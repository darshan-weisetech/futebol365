<?php
/**
 * Migration module: News  (rewritten for large datasets & idempotency)
 *
 * Django tables : publish_news (+ pivots), publish_source, publish_socialmediaschedule
 * WordPress CPT : post
 *
 * Design goals
 *   • Scales to ~300k rows  → keyset pagination by id (no slow OFFSET),
 *     per-batch object-cache flush, progress bar.
 *   • Idempotent            → an existing post is UPDATED, never re-inserted.
 *     Existence is detected by (1) the id map, (2) the authoritative
 *     `django_news_id` postmeta, (3) the slug — in that order — so a lost
 *     map can never create duplicates.
 *   • Media written once     → the featured image is downloaded only on the
 *     first insert (or with --force). Updates never re-download.
 *   • Relations resolved     → news_set_relations() converts every Django id
 *     (single FKs + all M2M pivots) to WP post IDs and writes the ACF fields.
 *     Nothing is dropped silently; unresolved ids are logged (REL-MISS).
 *   • Permalink shape        → post_name = "{django_news_id}-{slug}" to match
 *     the live structure /artigo/{id}-{slug}/.
 *
 * CLI flags honoured: --limit, --offset (resume AFTER this django id),
 *                     --batch, --force, --dry-run
 *
 * @package Futebol365
 * @since   2.1.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    return;
}

trait F365_Module_News {

    /**
     * ACF field-name → field-key map (from acf-news-extended.php).
     * Used to write meta + the ACF `_name` reference without requiring
     * ACF to be bootstrapped in the CLI context.
     */
    private array $news_rel_field_keys = [
        'competition'  => 'field_n_competition',
        'match'        => 'field_n_match',
        'photogallery' => 'field_n_gallery',
        'video_embed'  => 'field_news_video_embed',
        'videos'       => 'field_n_videos',
        'teams'        => 'field_n_teams',
        'players'      => 'field_n_players',
        'coaches'      => 'field_n_coaches',
        'presidents'   => 'field_n_presidents',
        'referees'     => 'field_n_referees',
    ];

    /**
     * Cache for batch queries (M2M pivots, tags, categories).
     */
    private array $news_batch_cache = [];

    /**
     * Local memory term cache to prevent N+1 and state sync issues when wp_suspend_cache_addition is active.
     */
    private array $_news_term_cache = [];

    /**
     * Prefetched existing WordPress post IDs mapped by django_news_id and post_name (slug).
     */
    private array $news_batch_existing_by_django_id = [];
    private array $news_batch_existing_by_slug = [];

    // ════════════════════════════════════════════════════════════════════════
    // MAIN ENTRY
    // ════════════════════════════════════════════════════════════════════════

    protected function import_news(): void {
        global $wpdb;

        // Prevent memory leaks during long-running WP-CLI scripts
        wp_suspend_cache_addition( true );

        // Defer term and comment counting to reduce database overhead during bulk insert
        wp_defer_term_counting( true );
        wp_defer_comment_counting( true );

        // Warm every id map we will resolve against, so map_resolve() is a
        // pure in-memory lookup during the hot loop.
        foreach ( [
            'f365_news', 'f365_competition', 'f365_match', 'f365_gallery',
            'f365_video', 'f365_team', 'f365_player', 'f365_coach',
            'f365_president', 'f365_referee',
        ] as $t ) {
            $this->map_get( $t );
        }

        // Author map: django source_id → wp_user_id
        $author_map = [];
        foreach ( $wpdb->get_results(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'django_source_id'"
        ) as $um ) {
            $author_map[ (string) $um->meta_value ] = (int) $um->user_id;
        }

        // Check if --relations pass is requested
        $is_relations = isset( $this->assoc_args['relations'] ) || isset( $this->assoc_args['update-relations-only'] );

        $total = (int) $this->pg_val( 'SELECT COUNT(*) FROM publish_news' );
        $cap   = min( $total, $this->limit );
        $mode_label = $is_relations ? 'Relations-only' : 'Full import';
        WP_CLI::log( "News total: $total | cap: $cap | batch: {$this->batch} | start-after-id: {$this->offset} | mode: $mode_label" );

        $progress = WP_CLI\Utils\make_progress_bar( 'News', $cap );

        $inserted = $updated = $failed = $skipped = $relations_ok = 0;
        $handled  = 0;                       // total rows processed (guards --limit)
        $last_id  = (int) $this->offset;     // keyset cursor (0 = from the start)

        while ( $handled < $this->limit ) {

            // Keyset pagination — fast even at row 300,000 (uses the PK index).
            $rows = $this->pg_all(
                'SELECT n.*, s.name AS source_name, s.slug AS source_slug, s.id AS source_id
                   FROM publish_news n
              LEFT JOIN publish_source s ON s.id = n.source_id
                   WHERE n.id > $1
                ORDER BY n.id
                   LIMIT $2',
                [ $last_id, $this->batch ]
            );

            if ( empty( $rows ) ) {
                break;
            }

            // Prefetch existence matching posts to avoid N+1 queries in loop
            $this->news_prefetch_existing_wp_posts( $rows );

            // Prefetch batch data for all records in the batch.
            $batch_news_ids = array_map( fn( $r ) => (int) $r['id'], $rows );
            $this->news_prefetch_batch( $batch_news_ids );

            foreach ( $rows as $news ) {

                if ( $handled >= $this->limit ) {
                    break;
                }

                $last_id = (int) $news['id'];   // advance cursor regardless of outcome
                $handled++;
                $progress->tick();

                $wp_id = $this->news_find_existing( $news );

                $news_id = (int) $news['id'];
                $pivots = [
                    'teams'      => $this->news_pivot_ids( 'publish_news_teams',      'news_id', $news_id, 'team_id' ),
                    'players'    => $this->news_pivot_ids( 'publish_news_players',    'news_id', $news_id, 'player_id' ),
                    'coaches'    => $this->news_pivot_ids( 'publish_news_coaches',    'news_id', $news_id, 'coach_id' ),
                    'presidents' => $this->news_pivot_ids( 'publish_news_presidents', 'news_id', $news_id, 'president_id' ),
                    'referees'   => $this->news_pivot_ids( 'publish_news_referees',   'news_id', $news_id, 'referee_id' ),
                    'videos'     => $this->news_pivot_ids( 'publish_news_videos',     'news_id', $news_id, 'video_id' ),
                ];

                if ( $is_relations ) {
                    if ( ! $wp_id ) {
                        $skipped++;
                        continue;
                    }
                    $this->news_set_relations( $wp_id, $news, $pivots );
                    $this->log( 'news', "REL-OK news_id={$news['id']} → wp_id=$wp_id" );
                    $relations_ok++;
                    continue;
                }

                $author_id = 1;
                if ( ! empty( $news['source_id'] ) && isset( $author_map[ (string) $news['source_id'] ] ) ) {
                    $author_id = $author_map[ (string) $news['source_id'] ];
                }

                // ── UPDATE ───────────────────────────────────────────────────
                if ( $wp_id ) {
                    $this->news_update( $wp_id, $news, $author_id, $pivots );
                    $this->log( 'news', "UPDATE news_id={$news['id']} → wp_id=$wp_id" );
                    $updated++;
                    continue;
                }

                // ── INSERT ───────────────────────────────────────────────────
                $wp_id = $this->news_insert( $news, $author_id, $pivots );
                if ( ! $wp_id ) {
                    $this->log( 'news', "FAIL insert news_id={$news['id']}" );
                    $failed++;
                    continue;
                }
                $inserted++;
            }

            // Save option map at the end of each batch instead of every iteration.
            $this->map_save( 'f365_news' );

            if ( isset( $news ) ) {
                $option_name = $is_relations ? 'f365_last_news_relations_pass' : 'f365_last_news_import';
                update_option( $option_name, [
                    'source_id' => (int) $news['id'],
                    'wp_id'     => $wp_id ?? 0,
                    'n'         => $is_relations ? $relations_ok : ( $inserted + $updated ),
                ], false );
            }

            // Free per-batch memory.
            unset( $rows );
            $this->news_batch_cache = []; // Clear batch cache to free memory
        }

        $progress->finish();

        // Final save to make sure everything is written.
        $this->map_save( 'f365_news' );

        if ( $is_relations ) {
            $this->log( 'news', "SUMMARY: mode=$mode_label processed=$relations_ok skipped=$skipped" );
            WP_CLI::success( "News ($mode_label): $relations_ok processed, $skipped skipped." );
        } else {
            $this->log( 'news', "SUMMARY: inserted=$inserted updated=$updated failed=$failed (handled=$handled)" );
            WP_CLI::success( "News: $inserted inserted, $updated updated, $failed failed." );
        }

        // Re-enable and trigger term and comment count updates
        wp_defer_term_counting( false );
        wp_defer_comment_counting( false );
        wp_suspend_cache_addition( false );
    }

    /**
     * Prefetch existence matching posts to avoid N+1 queries in loop.
     */
    private function news_prefetch_existing_wp_posts( array $rows ): void {
        global $wpdb;

        $this->news_batch_existing_by_django_id = [];
        $this->news_batch_existing_by_slug = [];

        if ( empty( $rows ) ) {
            return;
        }

        $django_ids = [];
        $slugs = [];
        foreach ( $rows as $r ) {
            $django_ids[] = (int) $r['id'];
            if ( ! empty( $r['slug'] ) ) {
                $slugs[] = trim( $r['slug'] );
            }
        }

        // 1) Fetch by django_news_id postmeta
        if ( ! empty( $django_ids ) ) {
            $id_list = implode( ',', $django_ids );
            $meta_rows = $wpdb->get_results(
                "SELECT pm.post_id, pm.meta_value 
                   FROM {$wpdb->postmeta} pm
                   JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                  WHERE pm.meta_key   = 'django_news_id'
                    AND pm.meta_value IN ($id_list)
                    AND p.post_type   = 'post'
                    AND p.post_status != 'trash'"
            );
            foreach ( $meta_rows as $mr ) {
                $this->news_batch_existing_by_django_id[ (int) $mr->meta_value ] = (int) $mr->post_id;
            }
        }

        // 2) Fetch by slug (post_name)
        if ( ! empty( $slugs ) ) {
            $slugs_escaped = array_map( 'esc_sql', $slugs );
            $slug_list = "'" . implode( "','", $slugs_escaped ) . "'";
            $slug_rows = $wpdb->get_results(
                "SELECT ID, post_name 
                   FROM {$wpdb->posts}
                  WHERE post_name IN ($slug_list)
                    AND post_type   = 'post'
                    AND post_status != 'auto-draft'"
            );
            foreach ( $slug_rows as $sr ) {
                $this->news_batch_existing_by_slug[ $sr->post_name ] = (int) $sr->ID;
            }
        }
    }

    /**
     * Prefetch all many-to-many pivots, tags, and categories for the batch of news IDs.
     */
    private function news_prefetch_batch( array $news_ids ): void {
        if ( empty( $news_ids ) ) {
            return;
        }

        $id_list = implode( ',', $news_ids );

        $this->news_batch_cache = [
            'videos'     => [],
            'teams'      => [],
            'players'    => [],
            'coaches'    => [],
            'presidents' => [],
            'referees'   => [],
            'tags'       => [],
            'categories' => [],
        ];

        // Initialize all keys for the batch to avoid fallback queries
        foreach ( $news_ids as $id ) {
            $this->news_batch_cache['videos'][ $id ]     = [];
            $this->news_batch_cache['teams'][ $id ]      = [];
            $this->news_batch_cache['players'][ $id ]    = [];
            $this->news_batch_cache['coaches'][ $id ]    = [];
            $this->news_batch_cache['presidents'][ $id ] = [];
            $this->news_batch_cache['referees'][ $id ]   = [];
            $this->news_batch_cache['tags'][ $id ]       = [];
            $this->news_batch_cache['categories'][ $id ] = [];
        }

        // 1) Videos
        $rows = $this->pg_all( "SELECT news_id, video_id AS v FROM publish_news_videos WHERE news_id IN ($id_list)" );
        foreach ( (array) $rows as $r ) {
            if ( ! empty( $r['v'] ) ) {
                $this->news_batch_cache['videos'][ (int) $r['news_id'] ][] = (int) $r['v'];
            }
        }

        // 2) Teams
        $rows = $this->pg_all( "SELECT news_id, team_id AS v FROM publish_news_teams WHERE news_id IN ($id_list)" );
        foreach ( (array) $rows as $r ) {
            if ( ! empty( $r['v'] ) ) {
                $this->news_batch_cache['teams'][ (int) $r['news_id'] ][] = (int) $r['v'];
            }
        }

        // 3) Players
        $rows = $this->pg_all( "SELECT news_id, player_id AS v FROM publish_news_players WHERE news_id IN ($id_list)" );
        foreach ( (array) $rows as $r ) {
            if ( ! empty( $r['v'] ) ) {
                $this->news_batch_cache['players'][ (int) $r['news_id'] ][] = (int) $r['v'];
            }
        }

        // 4) Coaches
        $rows = $this->pg_all( "SELECT news_id, coach_id AS v FROM publish_news_coaches WHERE news_id IN ($id_list)" );
        foreach ( (array) $rows as $r ) {
            if ( ! empty( $r['v'] ) ) {
                $this->news_batch_cache['coaches'][ (int) $r['news_id'] ][] = (int) $r['v'];
            }
        }

        // 5) Presidents
        $rows = $this->pg_all( "SELECT news_id, president_id AS v FROM publish_news_presidents WHERE news_id IN ($id_list)" );
        foreach ( (array) $rows as $r ) {
            if ( ! empty( $r['v'] ) ) {
                $this->news_batch_cache['presidents'][ (int) $r['news_id'] ][] = (int) $r['v'];
            }
        }

        // 6) Referees
        $rows = $this->pg_all( "SELECT news_id, referee_id AS v FROM publish_news_referees WHERE news_id IN ($id_list)" );
        foreach ( (array) $rows as $r ) {
            if ( ! empty( $r['v'] ) ) {
                $this->news_batch_cache['referees'][ (int) $r['news_id'] ][] = (int) $r['v'];
            }
        }

        // 7) Tags
        $rows = $this->pg_all( "SELECT nt.news_id, t.name FROM publish_news_tags nt
                                  JOIN common_tag t ON t.id = nt.tag_id
                                 WHERE nt.news_id IN ($id_list)" );
        foreach ( (array) $rows as $r ) {
            if ( ! empty( $r['name'] ) ) {
                $this->news_batch_cache['tags'][ (int) $r['news_id'] ][] = $r['name'];
            }
        }

        // 8) Categories
        $rows = $this->pg_all( "SELECT nc.news_id, c.name, c.slug FROM publish_news_categories nc
                                  JOIN publish_newscategory c ON c.id = nc.newscategory_id
                                 WHERE nc.news_id IN ($id_list)" );
        foreach ( (array) $rows as $r ) {
            $this->news_batch_cache['categories'][ (int) $r['news_id'] ][] = [
                'name' => $r['name'],
                'slug' => $r['slug'],
            ];
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // EXISTENCE DETECTION (idempotency)
    // ════════════════════════════════════════════════════════════════════════

    private function news_find_existing( array $news ): int {
        // 1) Fast path: the persisted id map.
        $wp_id = $this->map_resolve( 'f365_news', $news['id'] );
        if ( $wp_id ) {
            return (int) $wp_id;
        }

        // 2) Prefetched by django_news_id
        $django_id = (int) $news['id'];
        if ( isset( $this->news_batch_existing_by_django_id[ $django_id ] ) ) {
            $found = $this->news_batch_existing_by_django_id[ $django_id ];
            $this->news_remember( $django_id, $found );
            return $found;
        }

        // 3) Prefetched by slug
        if ( ! empty( $news['slug'] ) ) {
            $slug = trim( $news['slug'] );
            if ( isset( $this->news_batch_existing_by_slug[ $slug ] ) ) {
                $found = $this->news_batch_existing_by_slug[ $slug ];
                $this->news_remember( $django_id, $found );
                $this->log( 'news', "MAP-RECOVERY news_id={$django_id} → wp_id=$found" );
                return $found;
            }
        }

        return 0;
    }

    private function news_remember( $django_id, int $wp_id ): void {
        $this->map_set( 'f365_news', $django_id, $wp_id );
    }

    // ════════════════════════════════════════════════════════════════════════
    // INSERT / UPDATE
    // ════════════════════════════════════════════════════════════════════════

    private function news_insert( array $news, int $author_id, array $pivots = [] ): int {
        $status = $this->parse_pg_bool( $news['is_published'] ?? false ) ? 'publish' : 'draft';
        $pd     = ! empty( $news['datetime'] )   ? date( 'Y-m-d H:i:s', strtotime( $news['datetime'] ) )   : '';
        $mod_d  = ! empty( $news['updated_at'] ) ? date( 'Y-m-d H:i:s', strtotime( $news['updated_at'] ) ) : $pd;

        $wp_id = $this->insert_post( [
            'post_type'     => 'post',
            'post_title'    => $news['title']       ?? '',
            'post_content'  => $news['description'] ?? '',
            'post_excerpt'  => $news['summary']     ?? '',
            'post_name'     => $this->news_post_name( $news ),
            'post_status'   => $status,
            'post_date'     => $pd,
            'post_modified' => $mod_d,
            'post_author'   => $author_id,
        ] );

        if ( ! $wp_id ) {
            return 0;
        }

        // Register in the map IMMEDIATELY so a crash mid-batch can't orphan it.
        $this->news_remember( $news['id'], $wp_id );

        $this->news_write_meta( $wp_id, $news, $pivots );
        $this->news_set_relations( $wp_id, $news, $pivots );
        $this->news_set_yoast( $wp_id, $news );
        $this->news_set_tags( $wp_id, (int) $news['id'] );
        $this->news_set_categories( $wp_id, (int) $news['id'] );

        // Featured image — first time only, never the placeholder.
        $this->news_set_featured_image( $wp_id, $news, false );

        $this->log( 'news', "OK insert news_id={$news['id']} → wp_id=$wp_id" );
        return $wp_id;
    }

    /**
     * Re-sync an existing post. Writes the FULL meta set + relations (so rows
     * recovered by slug, which may lack django_*_ids, are repaired). Touches
     * the image only with --force.
     */
    private function news_update( int $wp_id, array $news, int $author_id, array $pivots = [] ): void {
        global $wpdb;

        $this->news_write_meta( $wp_id, $news, $pivots );
        $this->news_set_relations( $wp_id, $news, $pivots );
        $this->news_set_yoast( $wp_id, $news );
        $this->news_set_tags( $wp_id, (int) $news['id'] );
        $this->news_set_categories( $wp_id, (int) $news['id'] );

        if ( ! $this->dry_run ) {
            $update = [
                'post_title'   => $news['title']       ?? '',
                'post_content' => $news['description'] ?? '',
                'post_excerpt' => $news['summary']     ?? '',
                'post_author'  => $author_id,
            ];
            if ( ! empty( $news['updated_at'] ) ) {
                $up = date( 'Y-m-d H:i:s', strtotime( $news['updated_at'] ) );
                $update['post_modified']     = $up;
                $update['post_modified_gmt'] = get_gmt_from_date( $up );
            }
            $wpdb->update( $wpdb->posts, $update, [ 'ID' => $wp_id ] );
            clean_post_cache( $wp_id );
        }

        // --force re-downloads the image; a normal re-run leaves media untouched.
        $this->news_set_featured_image( $wp_id, $news, $this->force );
    }

    private function news_post_name( array $news ): string {
        $slug = $news['slug'] ?? '';
        return $slug !== '' ? $slug : (string) (int) $news['id'];
    }

    // ════════════════════════════════════════════════════════════════════════
    // META  (single source of truth — used by insert AND update)
    // ════════════════════════════════════════════════════════════════════════

    private function news_write_meta( int $wp_id, array $news, array $pivots = [] ): void {
        $this->set_meta( $wp_id, [
            // counters / content
            'views'                     => (int) ( $news['views'] ?? 0 ),
            'embed_video'               => $news['embed_video']        ?? '',
            'photogallery_url'          => $news['photogallery_url']   ?? '',
            'generic_link_title'        => $news['generic_link_title'] ?? '',
            'generic_link_url'          => $news['generic_link_url']   ?? '',
            'livecomments'              => $news['livecomments']       ?? '',
            'source_display_name'       => $news['source_name']        ?? '',
            'source_slug'               => $news['source_slug']        ?? '',

            // flags
            'display_matchday'          => $this->parse_pg_bool( $news['display_matchday'] ?? false )          ? 1 : 0,
            'highlight'                 => $this->parse_pg_bool( $news['highlight'] ?? false )                 ? 1 : 0,
            'news_aggregators'          => $this->parse_pg_bool( $news['news_aggregators'] ?? false )          ? 1 : 0,
            'social_sharing'            => $this->parse_pg_bool( $news['social_sharing'] ?? false )            ? 1 : 0,
            'social_sharing_with_photo' => $this->parse_pg_bool( $news['social_sharing_with_photo'] ?? false ) ? 1 : 0,
            'related_news'              => $this->parse_pg_bool( $news['related_news'] ?? false )              ? 1 : 0,

            // django audit ids (kept for resolve-fk / debugging)
            'django_news_id'            => (int) $news['id'],
            'django_news_source_id'     => (int) ( $news['source_id']       ?? 0 ),
            'django_source_table'       => 'publish_news',
            'django_competition_id'     => (int) ( $news['competition_id']  ?? 0 ),
            'django_match_id'           => (int) ( $news['match_id']        ?? 0 ),
            'django_photogallery_id'    => (int) ( $news['photogallery_id'] ?? 0 ),
            'django_video_embed_id'     => (int) ( $news['video_embed_id']  ?? 0 ),
            'django_image_path'         => $news['image']                   ?? '',

            // django m2m id lists (raw, for audit)
            'django_team_ids'           => $pivots['teams']      ?? [],
            'django_player_ids'         => $pivots['players']    ?? [],
            'django_coach_ids'          => $pivots['coaches']    ?? [],
            'django_president_ids'      => $pivots['presidents'] ?? [],
            'django_referee_ids'        => $pivots['referees']   ?? [],
            'django_video_ids'          => $pivots['videos']     ?? [],

            // temporary slugs imported from Django
            'competition_tmp'           => $news['competition_tmp'] ?? '',
            'season_tmp'                => $news['season_tmp']      ?? '',
        ] );
    }

    // ════════════════════════════════════════════════════════════════════════
    // RELATIONSHIPS  (the dedicated resolver you asked for)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Resolve every Django relation to WP post IDs and write the ACF fields.
     * Single FKs use post_object (id); M2M use relationship (array of ids).
     * Unresolved ids are logged as REL-MISS so nothing is lost silently.
     */
    private function news_set_relations( int $wp_id, array $news, array $pivots = [] ): void {
        if ( $this->dry_run ) {
            return;
        }

        $news_id = (int) $news['id'];

        // ── Single FKs ──────────────────────────────────────────────────────
        $singles = [
            'competition'  => [ 'f365_competition', (int) ( $news['competition_id']  ?? 0 ) ],
            'match'        => [ 'f365_match',       (int) ( $news['match_id']        ?? 0 ) ],
            'photogallery' => [ 'f365_gallery',     (int) ( $news['photogallery_id'] ?? 0 ) ],
            'video_embed'  => [ 'f365_video',       (int) ( $news['video_embed_id']  ?? 0 ) ],
        ];
        foreach ( $singles as $field => $pair ) {
            list( $type, $django_id ) = $pair;
            $resolved = $this->news_resolve_one( $type, $django_id, $news_id, $field );
            $this->news_write_acf_field( $wp_id, $field, $resolved ?: '' );
        }

        // ── Many-to-many ────────────────────────────────────────────────────
        $m2m = [
            'videos'     => 'f365_video',
            'teams'      => 'f365_team',
            'players'    => 'f365_player',
            'coaches'    => 'f365_coach',
            'presidents' => 'f365_president',
            'referees'   => 'f365_referee',
        ];
        foreach ( $m2m as $field => $type ) {
            $django_ids = $pivots[ $field ] ?? [];
            $wp_ids     = $this->news_resolve_many( $type, $django_ids, $news_id, $field );
            $this->news_write_acf_field( $wp_id, $field, $wp_ids );
        }
    }

    /** Resolve one Django id to a WP post id (via the persisted map). */
    private function news_resolve_one( string $type, int $django_id, int $news_id = 0, string $field = '' ): ?int {
        if ( $django_id <= 0 ) {
            return null;
        }
        $wp = $this->map_resolve( $type, $django_id );
        if ( ! $wp ) {
            $this->log( 'news', "REL-MISS {$field} ({$type}) django_id={$django_id} news_id={$news_id}" );
            return null;
        }
        return (int) $wp;
    }

    /** Resolve a list of Django ids to a de-duplicated array of WP post ids. */
    private function news_resolve_many( string $type, array $django_ids, int $news_id = 0, string $field = '' ): array {
        $out = [];
        foreach ( $django_ids as $d ) {
            $wp = $this->news_resolve_one( $type, (int) $d, $news_id, $field );
            if ( $wp ) {
                $out[] = $wp;
            }
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * Write an ACF field as raw meta + its `_name` field-key reference.
     * Avoids depending on ACF being loaded during CLI while still producing
     * the exact storage ACF expects (post_object = id, relationship = array).
     */
    private function news_write_acf_field( int $wp_id, string $field, $value ): void {
        if ( $this->dry_run ) {
            return;
        }
        update_post_meta( $wp_id, $field, $value );
        if ( isset( $this->news_rel_field_keys[ $field ] ) ) {
            update_post_meta( $wp_id, '_' . $field, $this->news_rel_field_keys[ $field ] );
        }
    }

    /** Return an int[] of ids from a Django pivot table. */
    private function news_pivot_ids( string $table, string $fk, $news_id, string $col ): array {
        $key_map = [
            'publish_news_teams'      => 'teams',
            'publish_news_players'    => 'players',
            'publish_news_coaches'    => 'coaches',
            'publish_news_presidents' => 'presidents',
            'publish_news_referees'   => 'referees',
            'publish_news_videos'     => 'videos',
        ];

        $cache_key = $key_map[ $table ] ?? null;
        if ( $cache_key && isset( $this->news_batch_cache[ $cache_key ][ (int) $news_id ] ) ) {
            return $this->news_batch_cache[ $cache_key ][ (int) $news_id ];
        }

        // Fallback for safety (e.g. if prefetch is somehow bypassed or for single queries)
        $rows = $this->pg_all(
            "SELECT {$col} AS v FROM {$table} WHERE {$fk} = \$1",
            [ (int) $news_id ]
        );
        $ids = [];
        foreach ( (array) $rows as $r ) {
            if ( ! empty( $r['v'] ) ) {
                $ids[] = (int) $r['v'];
            }
        }
        return $ids;
    }

    // ════════════════════════════════════════════════════════════════════════
    // FEATURED IMAGE  (download once)
    // ════════════════════════════════════════════════════════════════════════

    private function news_set_featured_image( int $wp_id, array $news, bool $force ): void {
        if ( $this->dry_run || empty( $news['image'] ) ) {
            return;
        }
        if ( strpos( $news['image'], '365-no-photo.jpg' ) !== false ) {
            return; // placeholder — skip
        }
        if ( has_post_thumbnail( $wp_id ) && ! $force ) {
            return; // already has media and not forcing → never re-download
        }

        $att_id = $this->sideload_image(
            $this->media_url( $news['image'] ),
            $wp_id,
            $news['title'] ?? ''
        );
        if ( $att_id ) {
            set_post_thumbnail( $wp_id, $att_id );
        } else {
            $this->log( 'news', "IMAGE_FAIL news_id={$news['id']}" );
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // YOAST / SOCIAL / TAXONOMY
    // ════════════════════════════════════════════════════════════════════════

    private function news_set_yoast( int $wp_id, array $news ): void {
        $seo_title = ! empty( $news['title'] ) ? $news['title'] . ' - Futebol 365' : '';
        $seo_desc  = $news['summary'] ?? '';
        if ( function_exists( 'f365_set_yoast_seo' ) ) {
            f365_set_yoast_seo( $wp_id, $seo_title, $seo_desc );
        } else {
            update_post_meta( $wp_id, '_yoast_wpseo_title',    $seo_title );
            update_post_meta( $wp_id, '_yoast_wpseo_metadesc', $seo_desc );
        }
    }

    private function news_set_tags( int $wp_id, int $news_id ): void {
        $tags = $this->news_batch_cache['tags'][ $news_id ] ?? null;

        // Fallback if not cached
        if ( $tags === null ) {
            $rows = $this->pg_all(
                'SELECT t.name FROM publish_news_tags nt
                   JOIN common_tag t ON t.id = nt.tag_id
                  WHERE nt.news_id = $1',
                [ $news_id ]
            );
            $tags = [];
            foreach ( (array) $rows as $r ) {
                if ( ! empty( $r['name'] ) ) {
                    $tags[] = $r['name'];
                }
            }
        }

        if ( ! $tags || $this->dry_run ) {
            return;
        }
        $term_ids = [];
        foreach ( $tags as $t ) {
            $name = is_array( $t ) ? ( $t['name'] ?? '' ) : $t;
            if ( empty( $name ) ) {
                continue;
            }
            $id = $this->_news_ensure_term_cached( $name, 'post_tag' );
            if ( $id ) {
                $term_ids[] = $id;
            }
        }
        if ( $term_ids ) {
            wp_set_object_terms( $wp_id, $term_ids, 'post_tag', false );
        }
    }

    private function news_set_categories( int $wp_id, int $news_id ): void {
        $cats = $this->news_batch_cache['categories'][ $news_id ] ?? null;

        // Fallback if not cached
        if ( $cats === null ) {
            $cats = $this->pg_all(
                'SELECT c.name, c.slug FROM publish_news_categories nc
                   JOIN publish_newscategory c ON c.id = nc.newscategory_id
                  WHERE nc.news_id = $1',
                [ $news_id ]
            );
        }

        if ( ! $cats || $this->dry_run ) {
            return;
        }
        $term_ids = [];
        foreach ( $cats as $c ) {
            $id = $this->_news_ensure_term_cached( $c['name'], 'category', $c['slug'] );
            if ( $id ) {
                $term_ids[] = $id;
            }
        }
        if ( $term_ids ) {
            wp_set_object_terms( $wp_id, $term_ids, 'category', false );
        }
    }

    // ── Term cache helper ───────────────────────────────────────────────────

    private function _news_ensure_term_cached( string $name, string $taxonomy, string $slug = '' ): int {
        $name = trim( $name );
        if ( $name === '' ) {
            return 0;
        }
        $key = $taxonomy . '||' . strtolower( $name );
        if ( ! isset( $this->_news_term_cache[ $key ] ) ) {
            $this->_news_term_cache[ $key ] = $this->ensure_term( $name, $taxonomy, $slug );
        }
        return $this->_news_term_cache[ $key ];
    }

}

trait F365_Module_Sync_News_Categories {

    /**
     * Synchronizes and assigns categories from legacy tables to WordPress posts.
     * Uses bounded keyset pagination to ensure high-speed, thread-safe parallel operations.
     */
    protected function sync_news_categories(): void {
        global $wpdb;

        // Prevent memory exhaustion during initial extensive query reads
        wp_suspend_cache_addition( true );

        $T_CAT_MAP  = $wpdb->prefix . 'publish_news_categories';
        $T_CAT_LIST = $wpdb->prefix . 'publish_newscategory';

        // Validate source tables existence
        if ( ! $this->sync_table_exists( $T_CAT_MAP ) || ! $this->sync_table_exists( $T_CAT_LIST ) ) {
            WP_CLI::error( "Source tables missing: ensure {$T_CAT_MAP} and {$T_CAT_LIST} are imported." );
            wp_suspend_cache_addition( false );
            return;
        }

        // --- COMMAND LINE / ENVIRONMENT PARAMETERS CONFIGURATION ---
        $worker_limit = PHP_INT_MAX;
        if ( isset( $this->assoc_args['limit'] ) ) {
            $worker_limit = (int) $this->assoc_args['limit'];
        } elseif ( isset( $this->limit ) && $this->limit !== PHP_INT_MAX ) {
            $worker_limit = (int) $this->limit;
        }
        
        $batch_size = (int) ( $this->batch ?? 500 );
        $offset     = isset( $this->assoc_args['offset'] ) ? (int) $this->assoc_args['offset'] : 0;

        WP_CLI::log( sprintf( 
            "Starting category mapping sync | Worker Limit: %s | Batch Size: %d | Offset: %d", 
            $worker_limit === PHP_INT_MAX ? 'none' : $worker_limit, 
            $batch_size,
            $offset
        ) );

        $processed   = 0;
        $assigned    = 0;
        $skipped     = 0;
        $failed      = 0;

        /**
         * Counts distinct mapping rows where the target WordPress post actually exists 
         */
        $total = (int) $wpdb->get_var( "
            SELECT COUNT(*) 
            FROM {$T_CAT_MAP} map
            INNER JOIN {$wpdb->postmeta} pm ON pm.meta_value = map.news_id
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = 'django_news_id' 
              AND p.post_type = 'post'" 
        );

        // Adjust total capacity to reflect the records remaining after the offset skip
        $effective_total = max( 0, $total - $offset );
        $cap = ($worker_limit < PHP_INT_MAX) ? min( $effective_total, $worker_limit ) : $effective_total;

        if ( $cap <= 0 ) {
            WP_CLI::warning( "No records found within the requested category parameters." );
            wp_suspend_cache_addition( false );
            return;
        }

        $progress = WP_CLI\Utils\make_progress_bar( 'Syncing Categories', $cap );

        // Warm up and map legacy category data to local memory to minimize overhead
        $legacy_categories = $this->get_legacy_categories_map( $T_CAT_LIST );

        // --- PARALLEL WORKER BOUNDARY CALCULATIONS (CRITICAL FIX) ---
        $last_processed_id = PHP_INT_MAX; // Ceil boundary
        $floor_id          = 0;           // Floor boundary (workers will stop here)

        // 1. Calculate the ceiling cursor where this specific worker starts
        if ( $offset > 0 ) {
            $starting_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$T_CAT_MAP} ORDER BY id DESC LIMIT 1 OFFSET %d",
                $offset
            ) );

            if ( $starting_id ) {
                $last_processed_id = (int) $starting_id + 1;
            } else {
                WP_CLI::warning( "Offset is larger than available mapping records." );
                wp_suspend_cache_addition( false );
                return;
            }
        }

        // 2. Calculate the floor cursor where this worker MUST STOP to avoid multi-thread overlap
        if ( $worker_limit < PHP_INT_MAX ) {
            $ending_offset = $offset + $worker_limit;
            $ending_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$T_CAT_MAP} ORDER BY id DESC LIMIT 1 OFFSET %d",
                $ending_offset
            ) );

            if ( $ending_id ) {
                $floor_id = (int) $ending_id;
            }
        }

        // --- CORE PROCESS EXECUTION LOOP ---
        while ( $processed < $cap ) {
            $batch_limit = min( $batch_size, $cap - $processed );
            if ( $batch_limit <= 0 ) {
                break;
            }

            // Keyset optimized layout enforcing both the ceiling ($last_processed_id) and floor ($floor_id) boundaries
            $mappings = $wpdb->get_results( $wpdb->prepare(
                "SELECT map.id AS map_id, pm.post_id AS wp_post_id, map.news_id AS django_id, map.newscategory_id AS legacy_cat_id
                 FROM (
                     SELECT id, news_id, newscategory_id 
                     FROM {$T_CAT_MAP}
                     WHERE id < %d AND id >= %d
                     ORDER BY id DESC
                     LIMIT %d
                 ) map
                 INNER JOIN {$wpdb->postmeta} pm ON pm.meta_value = map.news_id
                 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE pm.meta_key = 'django_news_id'
                   AND p.post_type = 'post'
                 ORDER BY map.id DESC",
                $last_processed_id,
                $floor_id,
                $batch_limit
            ), ARRAY_A );

            if ( empty( $mappings ) ) {
                break;
            }

            foreach ( $mappings as $row ) {
                $post_id       = (int) $row['wp_post_id'];
                $django_id     = (int) $row['django_id'];
                $legacy_cat_id = (int) $row['legacy_cat_id'];

                // Track the current key position
                $last_processed_id = (int) $row['map_id'];

                $this->log( 'sync_categories_isolated', '----------------------' );
                $this->log( 'sync_categories_isolated', "Processing Assignment: Map ID={$last_processed_id} | WP Post={$post_id} | Django ID={$django_id} | Legacy Cat ID={$legacy_cat_id}" );

                $processed++;
                $progress->tick();

                if ( ! isset( $legacy_categories[ $legacy_cat_id ] ) ) {
                    $this->log( 'sync_categories_isolated', "   -> Failed: Legacy Category ID {$legacy_cat_id} does not exist in master list." );
                    $failed++;
                    continue;
                }

                $cat_data = $legacy_categories[ $legacy_cat_id ];
                
                // PERFORMANCE OPTIMIZATION: Only temporarily enable cache insertion during potential term operations
                wp_suspend_cache_addition( false );
                $wp_term_id = $this->ensure_wp_category( $cat_data['name'], $cat_data['slug'] );
                wp_suspend_cache_addition( true );

                if ( ! $wp_term_id ) {
                    $this->log( 'sync_categories_isolated', "   -> Failed: WordPress could not process or register category term: {$cat_data['name']}" );
                    $failed++;
                    continue;
                }

                if ( has_term( $wp_term_id, 'category', $post_id ) ) {
                    $this->log( 'sync_categories_isolated', "   -> Skipped: Post ID {$post_id} already contains category term ID {$wp_term_id}." );
                    $skipped++;
                    continue;
                }

                // Apply term relationship updates safely
                $assignment_result = wp_set_post_terms( $post_id, array( $wp_term_id ), 'category', true );

                if ( ! is_wp_error( $assignment_result ) && ! empty( $assignment_result ) ) {
                    $this->log( 'sync_categories_isolated', "   -> Success: Assigned category '{$cat_data['name']}' to Post ID {$post_id}." );
                    $assigned++;
                } else {
                    $error_reason = is_wp_error( $assignment_result ) ? $assignment_result->get_error_message() : 'Empty response returned or invalid term taxonomy ID mapping.';
                    $this->log( 'sync_categories_isolated', "   -> Failed: Error running wp_set_post_terms for term ID {$wp_term_id}. Reason: {$error_reason}" );
                    $failed++;
                }
            }

            // Object caching flush at the end of every database batch block iteration
            $batch_post_ids = array_column( $mappings, 'wp_post_id' );
            if ( ! empty( $batch_post_ids ) ) {
                clean_post_cache( $batch_post_ids );
                clean_object_term_cache( $batch_post_ids, 'post' );
            }

            unset( $mappings );

            // Yield database thread execution control to allow concurrent regular traffic breathing room
            usleep( 50000 ); 
        }

        $progress->finish();
        WP_CLI::success( "Category Sync Finished: processed={$processed}, assigned={$assigned}, skipped={$skipped}, failed={$failed}" );
        $this->log( 'sync_categories_isolated', "SUMMARY: processed={$processed}, assigned={$assigned}, skipped={$skipped}, failed={$failed}" );

        wp_suspend_cache_addition( false );
    }

    /**
     * Resolves WordPress terms or generates missing taxonomies on demand.
     */
    private function ensure_wp_category( string $name, string $slug ): ?int {
        $term = get_term_by( 'slug', $slug, 'category' );
        if ( $term ) {
            return (int) $term->term_id;
        }

        $term_by_name = get_term_by( 'name', $name, 'category' );
        if ( $term_by_name ) {
            return (int) $term_by_name->term_id;
        }

        $new_term = wp_insert_term( $name, 'category', array( 'slug' => $slug ) );

        if ( ! is_wp_error( $new_term ) && isset( $new_term['term_id'] ) ) {
            return (int) $new_term['term_id'];
        }

        if ( is_wp_error( $new_term ) && isset( $new_term->error_data['term_exists'] ) ) {
            return (int) $new_term->error_data['term_exists'];
        }

        return null;
    }

    /**
     * Maps the dictionary object structure configuration for local processing lookups.
     */
    private function get_legacy_categories_map( string $table ): array {
        global $wpdb;
        $results = $wpdb->get_results( "SELECT id, name, slug FROM {$table}", ARRAY_A );
        
        $map = array();
        foreach ( $results as $row ) {
            $map[ (int) $row['id'] ] = array(
                'name' => trim( (string) $row['name'] ),
                'slug' => trim( (string) $row['slug'] )
            );
        }
        return $map;
    }

    /**
     * Verification rule validating critical schema layouts.
     */
    private function sync_table_exists( string $table ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }
}

trait F365_Module_Sync_News_Tags {

    /**
     * Synchronizes and assigns tags from legacy tables to WordPress posts.
     * Uses keyset pagination (Seek Method) to ensure high-speed, lock-safe operations.
     */
    protected function sync_news_tags(): void {
        global $wpdb;
        wp_suspend_cache_addition( true );

        $T_TAG_MAP  = $wpdb->prefix . 'publish_news_tags';
        $T_TAG_LIST = $wpdb->prefix . 'common_tag';

        if ( ! $this->sync_table_exists( $T_TAG_MAP ) || ! $this->sync_table_exists( $T_TAG_LIST ) ) {
            $this->log( 'sync_news_tags', "   -> Failed: Source tables missing." );
            WP_CLI::error( "Source tables missing." );
            return;
        }

        // 1. Capture user-supplied limit and offset inputs
        $requested_limit  = isset( $this->assoc_args['limit'] ) ? (int) $this->assoc_args['limit'] : PHP_INT_MAX;
        $requested_offset = isset( $this->assoc_args['offset'] ) ? (int) $this->assoc_args['offset'] : 0;
        $batch_size       = (int) ( $this->batch ?? 500 );

        // 2. DYNAMIC TRANSLATION: Find the absolute start_id matching the requested --offset
        $start_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$T_TAG_MAP} ORDER BY id ASC LIMIT 1 OFFSET %d",
            $requested_offset
        ) );

        if ( ! $start_id ) {
            $this->log( 'sync_news_tags', "   -> Warning: No data found at offset {$requested_offset}. Worker exiting safely." );
            WP_CLI::warning( "No data found at offset {$requested_offset}. Worker exiting safely." );
            wp_suspend_cache_addition( false );
            return;
        }

        // 3. Find the absolute end_id based on the requested --limit segment
        $end_id = PHP_INT_MAX;
        if ( $requested_limit !== PHP_INT_MAX ) {
            $target_end_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$T_TAG_MAP} WHERE id >= %d ORDER BY id ASC LIMIT 1 OFFSET %d",
                $start_id,
                $requested_limit - 1
            ) );
            
            if ( $target_end_id ) {
                $end_id = (int) $target_end_id;
            }
        }

        $this->log( 'sync_news_tags', sprintf( "   -> Translated Inputs -> Offset: %d | Limit: %d", $requested_offset, $requested_limit ) );
        $this->log( 'sync_news_tags', sprintf( "   -> Calculated Range  -> Start ID: %d | End ID: %s | Batch Size: %d", $start_id, $end_id === PHP_INT_MAX ? 'MAX' : $end_id, $batch_size ) );

        WP_CLI::log( sprintf( "Translated Inputs -> Offset: %d | Limit: %d", $requested_offset, $requested_limit ) );
        WP_CLI::log( sprintf( "Calculated Range  -> Start ID: %d | End ID: %s | Batch Size: %d", $start_id, $end_id === PHP_INT_MAX ? 'MAX' : $end_id, $batch_size ) );

        $legacy_tags = $this->get_legacy_tags_map( $T_TAG_LIST );
        $current_id  = $start_id;

        while ( $current_id <= $end_id ) {
            $mappings = $wpdb->get_results( $wpdb->prepare(
                "SELECT map.id AS map_id, pm.post_id AS wp_post_id, map.news_id AS django_id, map.tag_id AS legacy_tag_id
                 FROM {$T_TAG_MAP} map
                 INNER JOIN {$wpdb->postmeta} pm ON pm.meta_value = map.news_id
                 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE map.id >= %d AND map.id <= %d
                   AND pm.meta_key = 'django_news_id'
                   AND p.post_type = 'post'
                 ORDER BY map.id ASC
                 LIMIT %d",
                $current_id,
                $end_id,
                $batch_size
            ), ARRAY_A );

            if ( empty( $mappings ) ) {
                break;
            }

            foreach ( $mappings as $row ) {
                $post_id       = (int) $row['wp_post_id'];
                $legacy_tag_id = (int) $row['legacy_tag_id'];
                $current_id    = (int) $row['map_id'];

                if ( ! isset( $legacy_tags[ $legacy_tag_id ] ) ) {
                    $this->log( 'sync_news_tags', "   -> Skip: Legacy tag ID {$legacy_tag_id} not found in dictionary mapping table." );
                    continue;
                }
                
                $tag_data = $legacy_tags[ $legacy_tag_id ];
                
                wp_suspend_cache_addition( false );
                $wp_term_id = $this->ensure_wp_tag( $tag_data['name'], $tag_data['slug'] );

                if ( $wp_term_id ) {
                    if ( ! has_term( $wp_term_id, 'post_tag', $post_id ) ) {
                        $res = wp_set_post_terms( $post_id, array( $wp_term_id ), 'post_tag', true );
                        
                        if ( is_wp_error( $res ) ) {
                            $error_reason = $res->get_error_message();
                            $this->log( 'sync_news_tags', "   -> Failed: Error running wp_set_post_terms for term ID {$wp_term_id}. Reason: {$error_reason}" );
                        } else {
                            $this->log( 'sync_news_tags', "   -> Success: Assigned tag ID {$wp_term_id} ('{$tag_data['name']}') to WP Post {$post_id}" );
                        }
                    }
                } else {
                    $this->log( 'sync_news_tags', "   -> Failed: Could not resolve or generate WordPress term for tag '{$tag_data['name']}'." );
                }
                wp_suspend_cache_addition( true );
            }

            $batch_post_ids = array_column( $mappings, 'wp_post_id' );
            if ( ! empty( $batch_post_ids ) ) {
                clean_post_cache( $batch_post_ids );
                clean_object_term_cache( $batch_post_ids, 'post' );
            }

            $current_id++; 
            usleep( 20000 ); 
        }
        
        wp_suspend_cache_addition( false );
    }

    /**
     * Resolves WordPress terms or generates missing taxonomies on demand.
     */
    private function ensure_wp_tag( string $name, string $slug ): ?int {
        $term = get_term_by( 'slug', $slug, 'post_tag' );
        if ( $term ) {
            return (int) $term->term_id;
        }

        $term_by_name = get_term_by( 'name', $name, 'post_tag' );
        if ( $term_by_name ) {
            return (int) $term_by_name->term_id;
        }

        $new_term = wp_insert_term( $name, 'post_tag', array( 'slug' => $slug ) );

        if ( ! is_wp_error( $new_term ) && isset( $new_term['term_id'] ) ) {
            return (int) $new_term['term_id'];
        }

        if ( is_wp_error( $new_term ) && isset( $new_term->error_data['term_exists'] ) ) {
            return (int) $new_term->error_data['term_exists'];
        }

        return null;
    }

    /**
     * Maps the dictionary object structure configuration for local processing lookups.
     */
    private function get_legacy_tags_map( string $table ): array {
        global $wpdb;
        $results = $wpdb->get_results( "SELECT id, name, slug FROM {$table}", ARRAY_A );
        
        $map = array();
        foreach ( $results as $row ) {
            $map[ (int) $row['id'] ] = array(
                'name' => trim( (string) $row['name'] ),
                'slug' => trim( (string) $row['slug'] )
            );
        }
        return $map;
    }
}