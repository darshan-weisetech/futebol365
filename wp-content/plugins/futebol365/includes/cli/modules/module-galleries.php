<?php
/**
 * Migration module: Galleries
 *
 * Django tables:
 *   multimedia_photo                    — Photo (id, title, image)
 *   multimedia_photo_tags               — Photo tags M2M  (photo_id → common_tag.id)
 *   multimedia_photogallery             — PhotoGallery (id, title, url, image, datetime,
 *                                          views, votes, highlight, is_published,
 *                                          competition_id, match_id)
 *   multimedia_photogallery_tags        — Gallery tags M2M  (photogallery_id → common_tag.id)
 *   multimedia_photogallery_teams       — Gallery teams M2M
 *   multimedia_photogallery_players     — Gallery players M2M
 *   multimedia_photogallery_coaches     — Gallery coaches M2M
 *   multimedia_photogallery_presidents  — Gallery presidents M2M
 *   multimedia_photogallery_referees    — Gallery referees M2M
 *   multimedia_photogalleryphotos       — Through-model (photogallery_id, photo_id, ordered by id)
 *   common_tag                          — Tag names (id, name) — used by BOTH photos AND galleries
 *
 * Run order:
 *   wp f365 migrate galleries  — imports linked photos first (inside the batch),
 *                                then creates the gallery post.
 *   There is NO separate `photos` step needed. However if a `photos` step is
 *   registered in get_steps(), it is safe to run it too — photo_find_existing()
 *   will skip already-imported photos.
 *
 * Resuming:
 *   wp option get f365_last_gallery_import   → {"source_id": 890, ...}
 *   wp f365 migrate galleries --offset=890
 *   Photos linked to already-processed galleries are already in WP —
 *   photo_find_existing() skips them via map_resolve() in O(1).
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;
if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    return;
}

trait F365_Module_Galleries {

    private array $gallery_batch_cache = [];
    private array $_gallery_term_cache = [];

    // ════════════════════════════════════════════════════════════════════════
    // GALLERIES  (entry point — imports linked photos inline)
    // ════════════════════════════════════════════════════════════════════════

    protected function import_galleries(): void {

        // Warm all maps needed for relation resolution.
        // f365_photo map is also warmed here — already-imported photos are
        // found via map_resolve() in O(1), no second DB lookup.
        $this->map_get( 'f365_gallery' );
        $this->map_get( 'f365_photo' );
        $this->map_get( 'f365_competition' );
        $this->map_get( 'f365_match' );
        $this->map_get( 'f365_team' );
        $this->map_get( 'f365_player' );
        $this->map_get( 'f365_coach' );
        $this->map_get( 'f365_president' );
        $this->map_get( 'f365_referee' );

        $total   = (int) $this->pg_val( 'SELECT COUNT(*) FROM multimedia_photogallery' );
        $cap     = min( $total, $this->limit );
        WP_CLI::log( "Galleries total: $total | cap: $cap | batch: {$this->batch} | start-after-id: {$this->offset}" );

        $progress = WP_CLI\Utils\make_progress_bar( 'Galleries', $cap );

        $n = $skip = $fail = 0;
        $handled = 0;
        $last_id = (int) $this->offset;

        while ( $handled < $this->limit ) {

            // ── ONE query per batch — fetches everything needed ──────────────
            // Returns one row per gallery. All pivot data, tags, and the
            // ordered photo list (with photo title + image path) come back
            // as JSON-aggregated columns so there is exactly ONE PostgreSQL
            // round-trip per batch of galleries.
            $rows = $this->pg_all(
                "SELECT
                    pg.id,
                    pg.title,
                    pg.url,
                    pg.image,
                    pg.datetime,
                    pg.views,
                    pg.votes,
                    pg.highlight,
                    pg.is_published,
                    pg.competition_id,
                    pg.match_id,

                    -- Tags (common_tag, same table for both photos and galleries)
                    COALESCE(
                        (SELECT json_agg(t.name ORDER BY t.name)
                         FROM multimedia_photogallery_tags gt
                         JOIN common_tag t ON t.id = gt.tag_id
                         WHERE gt.photogallery_id = pg.id),
                    '[]') AS tags_json,

                    -- M2M: teams
                    COALESCE(
                        (SELECT json_agg(team_id) FROM multimedia_photogallery_teams
                         WHERE photogallery_id = pg.id),
                    '[]') AS team_ids_json,

                    -- M2M: players
                    COALESCE(
                        (SELECT json_agg(player_id) FROM multimedia_photogallery_players
                         WHERE photogallery_id = pg.id),
                    '[]') AS player_ids_json,

                    -- M2M: coaches
                    COALESCE(
                        (SELECT json_agg(coach_id) FROM multimedia_photogallery_coaches
                         WHERE photogallery_id = pg.id),
                    '[]') AS coach_ids_json,

                    -- M2M: presidents
                    COALESCE(
                        (SELECT json_agg(president_id) FROM multimedia_photogallery_presidents
                         WHERE photogallery_id = pg.id),
                    '[]') AS president_ids_json,

                    -- M2M: referees
                    COALESCE(
                        (SELECT json_agg(referee_id) FROM multimedia_photogallery_referees
                         WHERE photogallery_id = pg.id),
                    '[]') AS referee_ids_json,

                    -- Ordered photo list with title and image path
                    -- INNER JOIN ensures we only get photos actually linked to this gallery.
                    -- Photos with no physical file will fail sideload_image() gracefully.
                    COALESCE(
                        (SELECT json_agg(
                            json_build_object(
                                'photo_id', p.id,
                                'title',    p.title,
                                'image',    p.image
                            )
                            ORDER BY pgp.id ASC
                         )
                         FROM multimedia_photogalleryphotos pgp
                         JOIN multimedia_photo p ON p.id = pgp.photo_id
                         WHERE pgp.photogallery_id = pg.id),
                    '[]') AS photos_json

                FROM multimedia_photogallery pg
                WHERE pg.id > \$1
                ORDER BY pg.id
                LIMIT \$2",
                [ $last_id, $this->batch ]
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $r ) {
                if ( $handled >= $this->limit ) {
                    break;
                }

                $last_id = (int) $r['id'];
                $handled++;
                $progress->tick();

                // Decode JSON-aggregated columns once per row
                $r['tags']        = json_decode( $r['tags_json']        ?? '[]', true ) ?: [];
                $r['team_ids']    = json_decode( $r['team_ids_json']    ?? '[]', true ) ?: [];
                $r['player_ids']  = json_decode( $r['player_ids_json']  ?? '[]', true ) ?: [];
                $r['coach_ids']   = json_decode( $r['coach_ids_json']   ?? '[]', true ) ?: [];
                $r['president_ids'] = json_decode( $r['president_ids_json'] ?? '[]', true ) ?: [];
                $r['referee_ids'] = json_decode( $r['referee_ids_json'] ?? '[]', true ) ?: [];
                $r['photos']      = json_decode( $r['photos_json']      ?? '[]', true ) ?: [];

                $wp_id = $this->gallery_find_existing( $r );

                if ( $wp_id && ! $this->force ) {
                    $skip++;
                    continue;
                }

                if ( ! $wp_id ) {
                    $wp_id = $this->gallery_insert( $r );
                    if ( ! $wp_id ) {
                        $fail++;
                        continue;
                    }
                } else {
                    $this->gallery_update( $wp_id, $r );
                }

                $this->log( 'galleries', ( $this->force ? 'UPDATE' : 'OK' ) . " gallery_id={$r['id']} → wp_id=$wp_id" );
                $n++;
            }

            // Persist maps once per batch — NOT inside find_existing fallbacks
            $this->map_save( 'f365_gallery' );
            $this->map_save( 'f365_photo' );

            if ( isset( $r ) ) {
                update_option( 'f365_last_gallery_import', [
                    'source_id' => (int) $r['id'],
                    'wp_id'     => $wp_id ?? 0,
                    'n'         => $n,
                ], false );
            }

            unset( $rows );
            wp_cache_flush();
        }

        $progress->finish();
        $this->map_save( 'f365_gallery' ); // final flush
        $this->map_save( 'f365_photo' );   // final flush (photos inserted inline)
        $this->log( 'galleries', "SUMMARY: imported=$n skipped=$skip failed=$fail" );
        WP_CLI::success( "Galleries: $n imported, $skip skipped, $fail failed." );
    }

    // ════════════════════════════════════════════════════════════════════════
    // GALLERY INSERT / UPDATE
    // ════════════════════════════════════════════════════════════════════════

    private function gallery_find_existing( array $r ): int {
        global $wpdb;

        // Step 1: RAM map — O(1), no DB
        $wp_id = $this->map_resolve( 'f365_gallery', $r['id'] );
        if ( $wp_id ) {
            return $wp_id;
        }

        // Step 2: django_gallery_id postmeta
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT pm.post_id
               FROM {$wpdb->postmeta} pm
               JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key   = 'django_gallery_id'
                AND pm.meta_value = %d
                AND p.post_type   = 'f365_gallery'
                AND p.post_status != 'trash'
              LIMIT 1",
            (int) $r['id']
        ) );
        if ( $found ) {
            $this->map_set( 'f365_gallery', $r['id'], (int) $found );
            // NO map_save() here — saved once per batch above
            return (int) $found;
        }

        // Step 3: post_name (stored as (string) django_id)
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
              WHERE post_name   = %s
                AND post_type   = 'f365_gallery'
                AND post_status != 'auto-draft'
              LIMIT 1",
            (string) (int) $r['id']
        ) );
        if ( $found ) {
            $this->map_set( 'f365_gallery', $r['id'], (int) $found );
            // NO map_save() here
            return (int) $found;
        }

        return 0;
    }

    private function gallery_insert( array $r ): int {
        $status = $this->parse_pg_bool( $r['is_published'] ?? false ) ? 'publish' : 'draft';
        $pd     = ! empty( $r['datetime'] ) ? date( 'Y-m-d H:i:s', strtotime( $r['datetime'] ) ) : '';

        $wp_id = $this->insert_post( [
            'post_type'     => 'f365_gallery',
            'post_title'    => $r['title'] ?? '',
            'post_name'     => (string) $r['id'],
            'post_status'   => $status,
            'post_date'     => $pd ?: current_time( 'mysql' ),
            'post_modified' => $pd ?: current_time( 'mysql' ),
        ] );

        if ( ! $wp_id ) {
            return 0;
        }

        $this->map_set( 'f365_gallery', $r['id'], $wp_id );

        $this->gallery_write_meta( $wp_id, $r );
        $this->gallery_set_relations( $wp_id, $r );
        $this->gallery_set_photos( $wp_id, $r );
        $this->gallery_set_tags( $wp_id, $r );
        $this->gallery_set_featured_image( $wp_id, $r, false );
        $this->gallery_set_yoast( $wp_id, $r );

        return $wp_id;
    }

    private function gallery_update( int $wp_id, array $r ): void {
        global $wpdb;

        if ( $this->dry_run ) {
            return;
        }

        $this->map_set( 'f365_gallery', $r['id'], $wp_id );

        // ── Competition ───────────────────────────────────────────────────────
        $comp_dj = (int) ( $r['competition_id'] ?? 0 );
        if ( $comp_dj > 0 ) {
            $comp_wp = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                  WHERE meta_key = 'django_competition_id' AND meta_value = %d
                  LIMIT 1",
                $comp_dj
            ) );
            if ( $comp_wp ) {
                update_post_meta( $wp_id, 'competition',  $comp_wp );
                update_post_meta( $wp_id, '_competition', 'field_g_competition' );
            }
        }

        // ── Match ─────────────────────────────────────────────────────────────
        $match_dj = (int) ( $r['match_id'] ?? 0 );
        if ( $match_dj > 0 ) {
            $match_wp = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                  WHERE meta_key = 'django_match_id' AND meta_value = %d
                  LIMIT 1",
                $match_dj
            ) );
            if ( $match_wp ) {
                update_post_meta( $wp_id, 'match',  $match_wp );
                update_post_meta( $wp_id, '_match', 'field_g_match' );
            }
        }

        // ── Teams (M2M) ───────────────────────────────────────────────────────
        $team_dj_ids = array_map( 'intval', $r['team_ids'] ?? [] );
        if ( ! empty( $team_dj_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $team_dj_ids ), '%d' ) );
            $team_rows    = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_id AS wp_id FROM {$wpdb->postmeta}
                  WHERE meta_key = 'django_team_id' AND meta_value IN ({$placeholders})",
                ...$team_dj_ids
            ), ARRAY_A );

            $team_wp_ids = array_values( array_unique(
                array_map( 'intval', array_column( $team_rows, 'wp_id' ) )
            ) );

            if ( ! empty( $team_wp_ids ) ) {
                update_post_meta( $wp_id, 'teams',  $team_wp_ids );
                update_post_meta( $wp_id, '_teams', 'field_g_teams' );
            }
        }
    }


    private function gallery_write_meta( int $wp_id, array $r ): void {
        $this->set_meta( $wp_id, [
            'external_url'          => $r['url']   ?? '',
            'views'                 => (int) ( $r['views'] ?? 0 ),
            'votes'                 => (int) ( $r['votes'] ?? 0 ),
            'highlight'             => $this->parse_pg_bool( $r['highlight']    ?? false ) ? 1 : 0,
            'is_published'          => $this->parse_pg_bool( $r['is_published'] ?? false ) ? 1 : 0,
            'django_gallery_id'     => (int) $r['id'],
            'django_competition_id' => (int) ( $r['competition_id'] ?? 0 ),
            'django_match_id'       => (int) ( $r['match_id'] ?? 0 ),
            'django_source_table'   => 'multimedia_photogallery',
            // Raw Django ids for audit/debugging
            'django_team_ids'       => array_map( 'intval', $r['team_ids']     ?? [] ),
            'django_player_ids'     => array_map( 'intval', $r['player_ids']   ?? [] ),
            'django_coach_ids'      => array_map( 'intval', $r['coach_ids']    ?? [] ),
            'django_president_ids'  => array_map( 'intval', $r['president_ids'] ?? [] ),
            'django_referee_ids'    => array_map( 'intval', $r['referee_ids']  ?? [] ),
        ] );
    }

    // ════════════════════════════════════════════════════════════════════════
    // PHOTOS  (imported inline during gallery processing)
    //
    // Only photos that are actually linked to a gallery are imported.
    // Photo data (title, image) comes from the gallery batch query's
    // photos_json column — no extra PostgreSQL connection needed.
    // ════════════════════════════════════════════════════════════════════════

    private function gallery_set_photos( int $wp_id, array $r ): void {
        if ( $this->dry_run ) {
            return;
        }

        $att_ids = [];

        foreach ( $r['photos'] as $photo ) {
            $django_photo_id = (int) ( $photo['photo_id'] ?? 0 );
            if ( ! $django_photo_id ) {
                continue;
            }

            // Step 1: already imported in this or a previous run → O(1) lookup
            $att_id = $this->map_resolve( 'f365_photo', $django_photo_id );

            if ( ! $att_id ) {
                // Step 2: postmeta fallback (map cold after server restart)
                global $wpdb;
                $att_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT pm.post_id
                       FROM {$wpdb->postmeta} pm
                       JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                      WHERE pm.meta_key   = 'django_photo_id'
                        AND pm.meta_value = %d
                        AND p.post_type   = 'attachment'
                      LIMIT 1",
                    $django_photo_id
                ) );
                if ( $att_id ) {
                    $this->map_set( 'f365_photo', $django_photo_id, $att_id );
                    // NO map_save() here — saved per batch above
                }
            }

            if ( ! $att_id ) {
                // Step 3: not in WP yet — sideload now using data from batch row
                $att_id = $this->photo_import_from_row( $photo );
            }

            if ( $att_id ) {
                $att_ids[] = (int) $att_id;
            } else {
                $this->log( 'galleries',
                    "PHOTO-MISS gallery_id={$r['id']} photo_id={$django_photo_id}" );
            }
        }

        $this->gallery_write_acf_field( $wp_id, 'gallery_photos', $att_ids );
    }

    /**
     * Sideload a single photo and register it in the f365_photo map.
     *
     * @param  array $photo  One element from photos_json:
     *                       ['photo_id'=>int, 'title'=>string|null, 'image'=>string]
     * @return int  WP attachment ID, or 0 on failure.
     */
    private function photo_import_from_row( array $photo ): int {
        $django_id = (int) ( $photo['photo_id'] ?? 0 );
        if ( ! $django_id || empty( $photo['image'] ) ) {
            return 0;
        }

        $title = $this->photo_resolve_title( $photo );
        $url   = $this->media_url( $photo['image'] );

        // sideload_image() returns the existing attachment id if _source_url
        // already exists — safe to call even if the file was partially imported.
        $att_id = $this->sideload_image( $url, 0, $title, 'photos' );
        if ( ! $att_id ) {
            return 0;
        }

        if ( ! $this->dry_run ) {
            global $wpdb;
            $wpdb->update(
                $wpdb->posts,
                [
                    'post_name'  => (string) $django_id,
                    'post_title' => $title,
                ],
                [ 'ID' => $att_id ]
            );
            update_post_meta( $att_id, 'django_photo_id',    $django_id );
            update_post_meta( $att_id, 'django_source_table', 'multimedia_photo' );
        }

        $this->map_set( 'f365_photo', $django_id, $att_id );
        return $att_id;
    }

    /**
     * Build a non-empty title for a photo row.
     * Priority: Django title → filename without extension → "photo-{id}"
     */
    private function photo_resolve_title( array $photo ): string {
        $title = trim( (string) ( $photo['title'] ?? '' ) );
        if ( $title !== '' ) {
            return $title;
        }
        if ( ! empty( $photo['image'] ) ) {
            $base = pathinfo( (string) $photo['image'], PATHINFO_FILENAME );
            if ( $base !== '' ) {
                return $base;
            }
        }
        return 'photo-' . (int) ( $photo['photo_id'] ?? 0 );
    }

    // ════════════════════════════════════════════════════════════════════════
    // GALLERY RELATIONS
    // ════════════════════════════════════════════════════════════════════════

    private function gallery_set_relations( int $wp_id, array $r ): void {
        $gallery_id = (int) $r['id'];

        // Single FK: competition
        $comp_wp = $this->gallery_resolve_one(
            'f365_competition', (int) ( $r['competition_id'] ?? 0 ), $gallery_id, 'competition'
        );
        $this->gallery_write_acf_field( $wp_id, 'competition', $comp_wp ?: '' );

        // Single FK: match
        $match_wp = $this->gallery_resolve_one(
            'f365_match', (int) ( $r['match_id'] ?? 0 ), $gallery_id, 'match'
        );
        $this->gallery_write_acf_field( $wp_id, 'match', $match_wp ?: '' );

        // M2M relations — ids already decoded from JSON in the main loop
        $m2m = [
            'teams'      => [ 'f365_team',      $r['team_ids']      ?? [] ],
            'players'    => [ 'f365_player',    $r['player_ids']    ?? [] ],
            'coaches'    => [ 'f365_coach',     $r['coach_ids']     ?? [] ],
            'presidents' => [ 'f365_president', $r['president_ids'] ?? [] ],
            'referees'   => [ 'f365_referee',   $r['referee_ids']   ?? [] ],
        ];

        foreach ( $m2m as $field => [ $map_type, $django_ids ] ) {
            $wp_ids = $this->gallery_resolve_many(
                $map_type,
                array_map( 'intval', (array) $django_ids ),
                $gallery_id,
                $field
            );
            $this->gallery_write_acf_field( $wp_id, $field, $wp_ids );
        }
    }

    private function gallery_set_tags( int $wp_id, array $r ): void {
        // Tags already decoded from tags_json in the main loop.
        // common_tag table (NOT taggit_tag) — same for both photos and galleries.
        $tags = $r['tags'] ?? [];

        if ( empty( $tags ) || $this->dry_run ) {
            return;
        }

        $term_ids = [];
        foreach ( $tags as $tag_name ) {
            $tag_name = trim( (string) $tag_name );
            if ( $tag_name === '' ) {
                continue;
            }
            $id = $this->_gallery_ensure_term_cached( $tag_name, 'post_tag' );
            if ( $id ) {
                $term_ids[] = $id;
            }
        }

        if ( $term_ids ) {
            wp_set_object_terms( $wp_id, $term_ids, 'post_tag', false );
        }
    }

    private function gallery_set_featured_image( int $wp_id, array $r, bool $force ): void {
        if ( $this->dry_run || empty( $r['image'] ) ) {
            return;
        }
        if ( strpos( (string) $r['image'], 'no-image' ) !== false ) {
            return;
        }
        if ( has_post_thumbnail( $wp_id ) && ! $force ) {
            return;
        }

        $att_id = $this->sideload_image(
            $this->media_url( $r['image'] ),
            $wp_id,
            trim( (string) ( $r['title'] ?? '' ) ),
            'galleries'
        );

        if ( $att_id ) {
            set_post_thumbnail( $wp_id, $att_id );
        } else {
            $this->log( 'galleries', "IMAGE_FAIL gallery_id={$r['id']}" );
        }
    }

    private function gallery_set_yoast( int $wp_id, array $r ): void {
        $title     = trim( (string) ( $r['title'] ?? '' ) );
        $seo_title = $title !== '' ? 'FOTOGALERIA - ' . $title . ' - Futebol 365' : '';
        $seo_desc  = $title !== '' ? 'Veja todas as imagens da fotogaleria ' . $title : '';

        if ( function_exists( 'f365_set_yoast_seo' ) ) {
            f365_set_yoast_seo( $wp_id, $seo_title, $seo_desc );
        } else {
            update_post_meta( $wp_id, '_yoast_wpseo_title',    $seo_title );
            update_post_meta( $wp_id, '_yoast_wpseo_metadesc', $seo_desc );
        }
    }

    // ── Relation helpers ─────────────────────────────────────────────────────

    private function gallery_resolve_one( string $type, int $django_id, int $gallery_id, string $field ): ?int {
        if ( $django_id <= 0 ) {
            return null;
        }
        $wp = $this->map_resolve( $type, $django_id );
        if ( ! $wp ) {
            $this->log( 'galleries',
                "REL-MISS {$field} ({$type}) django_id={$django_id} gallery_id={$gallery_id}" );
            return null;
        }
        return (int) $wp;
    }

    private function gallery_resolve_many( string $type, array $django_ids, int $gallery_id, string $field ): array {
        $out = [];
        foreach ( $django_ids as $d ) {
            $wp = $this->gallery_resolve_one( $type, (int) $d, $gallery_id, $field );
            if ( $wp ) {
                $out[] = $wp;
            }
        }
        return array_values( array_unique( $out ) );
    }

    private function gallery_write_acf_field( int $wp_id, string $field, $value ): void {
        if ( $this->dry_run ) {
            return;
        }
        static $field_keys = [
            'competition'    => 'field_g_competition',
            'match'          => 'field_g_match',
            'teams'          => 'field_g_teams',
            'players'        => 'field_g_players',
            'coaches'        => 'field_g_coaches',
            'presidents'     => 'field_g_presidents',
            'referees'       => 'field_g_referees',
            'gallery_photos' => 'field_g_photos',
        ];
        update_post_meta( $wp_id, $field, $value );
        if ( isset( $field_keys[ $field ] ) ) {
            update_post_meta( $wp_id, '_' . $field, $field_keys[ $field ] );
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // UPDATE GALLERY RELATIONS (teams, competition, match) — direct SQL only
    // wp f365 migrate update_gallery_relations --batch=500 --allow-root
    // ════════════════════════════════════════════════════════════════════════

    protected function update_gallery_relations(): void {
        global $wpdb;

        $after      = (int) ( $this->assoc_args['after-id'] ?? 0 );
        $processed  = 0;
        $updated    = 0;
        $skipped    = 0;

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
              WHERE post_type = 'f365_gallery' AND post_status != 'trash'"
        );
        $cap      = min( $total, $this->limit );
        $progress = WP_CLI\Utils\make_progress_bar( 'Gallery Relations', $cap );

        WP_CLI::log( "Total galleries: {$total} | cap: {$cap} | batch: {$this->batch}" );

        while ( $processed < $cap ) {
            $batch_limit = min( $this->batch, $cap - $processed );
            if ( $batch_limit <= 0 ) {
                break;
            }

            // Fetch next batch of gallery post IDs (keyset pagination)
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.ID,
                        MAX(CASE WHEN pm.meta_key = 'django_competition_id' THEN pm.meta_value END) AS comp_dj_id,
                        MAX(CASE WHEN pm.meta_key = 'django_match_id'       THEN pm.meta_value END) AS match_dj_id,
                        MAX(CASE WHEN pm.meta_key = 'django_team_ids'       THEN pm.meta_value END) AS team_dj_ids
                   FROM {$wpdb->posts} p
                   LEFT JOIN {$wpdb->postmeta} pm
                          ON pm.post_id = p.ID
                         AND pm.meta_key IN ('django_competition_id', 'django_match_id', 'django_team_ids')
                  WHERE p.post_type   = 'f365_gallery'
                    AND p.post_status != 'trash'
                    AND p.ID > %d
                  GROUP BY p.ID
                  ORDER BY p.ID ASC
                  LIMIT %d",
                $after,
                $batch_limit
            ), ARRAY_A );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $wp_id    = (int) $row['ID'];
                $after    = $wp_id;
                $processed++;
                $progress->tick();

                $changed = false;

                // ── Competition ───────────────────────────────────────────
                $comp_dj = (int) ( $row['comp_dj_id'] ?? 0 );
                if ( $comp_dj > 0 ) {
                    $comp_wp = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta}
                          WHERE meta_key = 'django_competition_id' AND meta_value = %d
                          LIMIT 1",
                        $comp_dj
                    ) );
                    if ( $comp_wp ) {
                        update_post_meta( $wp_id, 'competition',  $comp_wp );
                        update_post_meta( $wp_id, '_competition', 'field_g_competition' );
                        $changed = true;
                    }
                }

                // ── Match ─────────────────────────────────────────────────
                $match_dj = (int) ( $row['match_dj_id'] ?? 0 );
                if ( $match_dj > 0 ) {
                    $match_wp = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta}
                          WHERE meta_key = 'django_match_id' AND meta_value = %d
                          LIMIT 1",
                        $match_dj
                    ) );
                    if ( $match_wp ) {
                        update_post_meta( $wp_id, 'match',  $match_wp );
                        update_post_meta( $wp_id, '_match', 'field_g_match' );
                        $changed = true;
                    }
                }

                // ── Teams (M2M — serialized array stored in postmeta) ─────
                $team_dj_raw = $row['team_dj_ids'] ?? '';
                $team_dj_ids = [];

                // stored as PHP serialized array or JSON
                if ( ! empty( $team_dj_raw ) ) {
                    $unserialized = @unserialize( $team_dj_raw );
                    if ( is_array( $unserialized ) ) {
                        $team_dj_ids = array_map( 'intval', $unserialized );
                    } else {
                        $decoded = json_decode( $team_dj_raw, true );
                        if ( is_array( $decoded ) ) {
                            $team_dj_ids = array_map( 'intval', $decoded );
                        }
                    }
                }

                if ( ! empty( $team_dj_ids ) ) {
                    $placeholders = implode( ',', array_fill( 0, count( $team_dj_ids ), '%d' ) );
                    $team_rows    = $wpdb->get_results( $wpdb->prepare(
                        "SELECT meta_value AS dj_id, post_id AS wp_id
                           FROM {$wpdb->postmeta}
                          WHERE meta_key   = 'django_team_id'
                            AND meta_value IN ({$placeholders})",
                        ...$team_dj_ids
                    ), ARRAY_A );

                    $team_wp_ids = array_values( array_unique(
                        array_map( 'intval', array_column( $team_rows, 'wp_id' ) )
                    ) );

                    if ( ! empty( $team_wp_ids ) ) {
                        update_post_meta( $wp_id, 'teams',  $team_wp_ids );
                        update_post_meta( $wp_id, '_teams', 'field_g_teams' );
                        $changed = true;
                    }
                }

                if ( $changed ) {
                    $updated++;
                    $this->log( 'gallery_relations', "OK wp_id={$wp_id}" );
                } else {
                    $skipped++;
                }
            }

            unset( $rows );
            wp_cache_flush();
        }

        $progress->finish();
        WP_CLI::success( "Gallery Relations: processed={$processed}, updated={$updated}, skipped={$skipped}" );
        $this->log( 'gallery_relations', "SUMMARY: processed={$processed}, updated={$updated}, skipped={$skipped}" );
    }

    // ── Term cache ───────────────────────────────────────────────────────────

    private function _gallery_ensure_term_cached( string $name, string $taxonomy ): int {
        $name = trim( $name );
        if ( $name === '' ) {
            return 0;
        }
        $key = $taxonomy . '||' . strtolower( $name );
        if ( ! isset( $this->_gallery_term_cache[ $key ] ) ) {
            $this->_gallery_term_cache[ $key ] = $this->ensure_term( $name, $taxonomy );
        }
        return $this->_gallery_term_cache[ $key ];
    }

}