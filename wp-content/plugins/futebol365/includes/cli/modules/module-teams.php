<?php
/**
 * Migration modules: Teams + Team Module (TeamSteps, Squad, Team Presidents).
 * * DIRECT POPULATION ARCHITECTURE:
 * • All data, taxonomies, and cross-module FK relationships are resolved 
 * and written to the database during the WP-CLI migration phase.
 * • Supports high-performance parallel execution using --offset and --limit windows.
 *
 * @package Futebol365
 * @since   2.4.0
 */

defined( 'ABSPATH' ) || exit;

/* ═══════════════════════════════════════════════════════════════════════════
 * 1. UTILITY CLASS (Shared Lookup Helpers)
 * ═══════════════════════════════════════════════════════════════════════════ */

if ( ! class_exists( 'F365_Team_Migration_Helper' ) ) {
    final class F365_Team_Migration_Helper {
        /** Resolve a [django_id => wp_id] map via postmeta in a batch. */
        public static function resolve_ids( string $post_type, string $meta_key, array $ids ): array {
            global $wpdb;
            $out = [];
            $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
            if ( ! $ids ) { return $out; }
            $in = implode( ',', $ids );
            foreach ( (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT pm.meta_value AS dj, pm.post_id AS wp FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s AND pm.meta_value IN ($in) AND p.post_type = %s",
                $meta_key, $post_type
            ), ARRAY_A ) as $r ) {
                $out[ (int) $r['dj'] ] = (int) $r['wp'];
            }
            return $out;
        }

        public static function term_set( int $wp_id, $name, string $taxonomy ): void {
            $name = trim( (string) $name );
            if ( $name === '' ) { return; }
            wp_set_object_terms( $wp_id, [ $name ], $taxonomy, true );
        }
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 * 2. TEAMS MIGRATION TRAIT
 * ═══════════════════════════════════════════════════════════════════════════ */

trait F365_Module_Teams {

    protected function import_teams(): void {
        global $wpdb;

        wp_suspend_cache_addition( true );
        wp_defer_term_counting( true );
        if ( function_exists( 'wp_defer_comment_counting' ) ) { wp_defer_comment_counting( true ); }

        $total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}team_team" );
        $win_off = ( ! empty( $this->offset ) ) ? (int) $this->offset : 0;
        $win_lim = ( ! empty( $this->limit ) && $this->limit !== PHP_INT_MAX ) ? (int) $this->limit : max( 0, $total - $win_off );
        $batch   = $this->batch > 0 ? (int) $this->batch : 200;

        $this->log( 'teams', "RUN_START post_type=f365_team window_offset={$win_off} window_limit={$win_lim} source_total={$total} sub_batch={$batch}" );
        WP_CLI::log( "Teams: window offset={$win_off} limit={$win_lim} (source={$total}) batch={$batch}" );

        $fed_map = $this->map_get( 'f365_federation' );
        $sta_map = $this->map_get( 'f365_stadium' );

        $ins = $upd = $fail = 0;
        $new_pairs = [];
        $processed = 0;

        while ( $processed < $win_lim ) {
            $chunk   = (int) min( $batch, $win_lim - $processed );
            $abs_off = $win_off + $processed;
            $t0      = microtime( true );

            $rows = $wpdb->get_results(
                "SELECT t.*, c.shortname AS country_name
                   FROM {$wpdb->prefix}team_team t
                   LEFT JOIN {$wpdb->prefix}country_country c ON c.id = t.country_id
                  ORDER BY t.id
                  LIMIT " . $chunk . " OFFSET " . $abs_off,
                ARRAY_A
            );
            if ( empty( $rows ) ) { break; }

            foreach ( $rows as $r ) {
                $django_id  = (int) $r['id'];
                $status     = ( $r['is_available'] ?? false ) ? 'publish' : 'draft';
                $updated_at = ! empty( $r['updated_at'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $r['updated_at'] ) ) : current_time( 'mysql', true );

                $wp_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_key = 'django_id' AND pm.meta_value = %d AND p.post_type = 'f365_team' LIMIT 1",
                    $django_id
                ) );

                if ( $wp_id ) {
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$wpdb->posts}
                            SET post_title=%s, post_status=%s, post_modified=%s, post_modified_gmt=%s,
                                legacy_table='team_team', legacy_id=%d
                          WHERE ID=%d",
                        $r['shortname'] ?? '', $status, $updated_at, get_gmt_from_date( $updated_at ), $django_id, $wp_id
                    ) );
                    $upd++;
                } else {
                    $now  = current_time( 'mysql' );
                    $slug = sanitize_title( $r['slug'] ?? ( 'team-' . $django_id ) );
                    $ok   = $wpdb->query( $wpdb->prepare(
                        "INSERT INTO {$wpdb->posts}
                            (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt,
                             post_status, comment_status, ping_status, post_password, post_name, to_ping,
                             pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent,
                             guid, menu_order, post_type, post_mime_type, comment_count, legacy_table, legacy_id)
                         VALUES (1,%s,%s,'',%s,'',%s,'closed','closed','',%s,'','',%s,%s,'',0,'',0,'f365_team','',0,'team_team',%d)",
                        $now, get_gmt_from_date( $now ), $r['shortname'] ?? '', $status,
                        $slug, $updated_at, get_gmt_from_date( $updated_at ), $django_id
                    ) );
                    if ( $ok === false || ! $wpdb->insert_id ) {
                        $fail++;
                        $this->log( 'teams', "FAILED insert team_id={$django_id}: " . $wpdb->last_error );
                        continue;
                    }
                    $wp_id = (int) $wpdb->insert_id;
                    $ins++;
                }
                $new_pairs[ $django_id ] = (int) $wp_id;

                $this->set_meta( $wp_id, [
                    'shortname'            => $r['shortname']        ?? '',
                    'longname'             => $r['longname']         ?? '',
                    'slug'                 => $r['slug']              ?? '',
                    'nickname'             => $r['nickname']          ?? '',
                    'namecode'             => $r['namecode']          ?? '',
                    'team_type'            => $r['team_type']         ?? '',
                    'city'                 => $r['city']              ?? '',
                    'address'              => $r['address']           ?? '',
                    'phone'                => $r['phone']             ?? '',
                    'fax'                  => $r['fax']               ?? '',
                    'foundation'           => $this->d( $r['foundation']  ?? '' ),
                    'extinction'           => $this->d( $r['extinction']  ?? '' ),
                    'official_members'     => (int) ( $r['official_members'] ?? 0 ),
                    'mascot'               => $r['mascot']            ?? '',
                    'equipment'            => $r['equipment']         ?? '',
                    'equipment_image'      => $r['equipment_image']   ?? '',
                    'url'                  => $r['url']               ?? '',
                    'email'                => $r['email']             ?? '',
                    'facebook'             => $r['facebook']          ?? '',
                    'twitter'              => $r['twitter']           ?? '',
                    'youtube'              => $r['youtube']           ?? '',
                    'instagram'            => $r['instagram']         ?? '',
                    'googleplus'           => $r['googleplus']        ?? '',
                    'is_available'         => $r['is_available']      ? 1 : 0,
                    'is_highlight'         => ( $r['is_highlight'] ?? 0 ) ? 1 : 0,
                    'updated_at'           => $r['updated_at']        ?? '',
                    'association_id'       => $r['association_id']    ?? '',
                    'logo'                 => $r['logo']              ?? '',
                    'edition'              => $r['edition']           ?? '',
                    'django_id'            => (int) $r['id'],
                    'django_federation_id' => (int) ( $r['federation_id'] ?? 0 ),
                    'django_stadium_id'    => (int) ( $r['stadium_id']    ?? 0 ),
                    'django_country_id'    => (int) ( $r['country_id']    ?? 0 ),
                    'django_tbl_name'      => 'team_team',
                ] );

                if ( $r['country_name'] ) {
                    $this->set_terms( $wp_id, [ $r['country_name'] ], 'f365_country' );
                }

                if ( isset( $this->assoc_args['with-logos'] ) && ! get_post_thumbnail_id( $wp_id ) && ! empty( $r['id'] ) ) {
                    $image_url = "https://futebol365.pt/media/images/logotipo/100x100/{$r['id']}.gif";
                    $att_id    = $this->sideload_image( $image_url, $wp_id, $r['shortname'] ?? '', 'teams' );
                    if ( $att_id ) {
                        set_post_thumbnail( $wp_id, $att_id );
                    }
                }

                if ( ! empty( $r['federation_id'] ) ) {
                    $fed_wp_id = $fed_map[ $r['federation_id'] ] ?? null;
                    if ( $fed_wp_id ) {
                        update_post_meta( $wp_id, 'federation', (int) $fed_wp_id );
                    } else {
                        $this->log( 'teams', "MISSING federation mapping for ID={$r['federation_id']}" );
                    }
                }
                if ( ! empty( $r['stadium_id'] ) ) {
                    $sta_wp_id = $sta_map[ $r['stadium_id'] ] ?? null;
                    if ( $sta_wp_id ) {
                        update_post_meta( $wp_id, 'stadium', (int) $sta_wp_id );
                    } else {
                        $this->log( 'teams', "MISSING stadium mapping for ID={$r['stadium_id']}" );
                    }
                }

                $this->log( 'teams', "OK team_id={$r['id']} → wp_id={$wp_id} ({$r['shortname']})" );
            }

            $processed += count( $rows );
            $this->log( 'teams', sprintf( 'BATCH_OK offset=%d rows=%d inserted=%d updated=%d failed=%d elapsed=%.2fs', $abs_off, count( $rows ), $ins, $upd, $fail, microtime( true ) - $t0 ) );

            if ( function_exists( 'wp_cache_flush_runtime' ) ) { wp_cache_flush_runtime(); }
            else { wp_cache_flush(); }

            if ( count( $rows ) < $chunk ) { break; }
        }

        $this->f365_map_merge_locked( 'f365_team', $new_pairs );

        wp_suspend_cache_addition( false );
        wp_defer_term_counting( false );
        if ( function_exists( 'wp_defer_comment_counting' ) ) { wp_defer_comment_counting( false ); }

        $this->log( 'teams', "SUMMARY window_offset={$win_off} window_limit={$win_lim} inserted={$ins} updated={$upd} failed={$fail}" );
        WP_CLI::success( "Teams [{$win_off}..+{$win_lim}]: inserted={$ins} updated={$upd} failed={$fail}." );
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 * 3. TEAM MODULE MIGRATION TRAIT (Fully Populate Relational Data on Import)
 * ═══════════════════════════════════════════════════════════════════════════ */

trait F365_Module_TeamModule {

    protected function import_team_module(): void {
        WP_CLI::log( '━━━ team_module: teamsteps ━━━' );
        $this->import_teamsteps();

        WP_CLI::log( '━━━ team_module: squad ━━━' );
        $this->import_squad();

        WP_CLI::log( '━━━ team_module: team_presidents ━━━' );
        $this->import_team_presidents();
    }

    protected function import_teamsteps(): void {
        global $wpdb;
        $this->f365_direct_run( [
            'module'     => 'teamsteps',
            'legacy_tag' => 'team_teamstep',
            'post_type'  => 'f365_teamstep',
            'id_key'     => 'django_teamstep_id',
            'map_type'   => 'f365_teamstep',
            'slug'       => 'f365-teamstep-',
            'count_sql'  => "SELECT COUNT(*) FROM {$wpdb->prefix}team_teamstep",
            'select_sql' => "SELECT ts.*, t.shortname AS team_shortname
                               FROM {$wpdb->prefix}team_teamstep ts JOIN {$wpdb->prefix}team_team t ON t.id = ts.team_id ORDER BY ts.id",
            'title'      => function ( $r ) {
                $sfx = $this->teamstep_suffix( $r['step'] ?? '', $r['modality'] ?? '', $r['gender'] ?? '' );
                return trim( "{$r['team_shortname']} {$sfx} {$r['edition']}" );
            },
            'hydrate_fn' => function( $wp_id, $r ) use ( $wpdb ) {
                $team_map    = F365_Team_Migration_Helper::resolve_ids( 'f365_team',    'django_id',         [ (int) ( $r['team_id'] ?? 0 ) ] );
                $stadium_map = F365_Team_Migration_Helper::resolve_ids( 'f365_stadium', 'django_stadium_id', [ (int) ( $r['stadium_id'] ?? 0 ) ] );

                $meta = [
                    'team'              => (int) ( $team_map[ (int) ( $r['team_id'] ?? 0 ) ] ?? 0 ),
                    'edition'           => $r['edition'] ?? '',
                    'step'              => $r['step'] ?? '',
                    'modality'          => $r['modality'] ?? '',
                    'gender'            => $r['gender'] ?? '',
                    'stadium'           => (int) ( $stadium_map[ (int) ( $r['stadium_id'] ?? 0 ) ] ?? 0 ),
                    'is_available'      => ! empty( $r['is_available'] ) ? 1 : 0,
                    'tmp_coach'         => $r['tmp_coach'] ?? '',
                    'tmp_president'     => $r['tmp_president'] ?? '',
                    'django_team_id'    => (int) ( $r['team_id'] ?? 0 ),
                    'django_stadium_id' => (int) ( $r['stadium_id'] ?? 0 ),
                    'django_tbl_name'   => 'team_teamstep',
                ];
                foreach ( $meta as $k => $v ) {
                    update_post_meta( $wp_id, $k, $v );
                }

                F365_Team_Migration_Helper::term_set( $wp_id, $r['edition']  ?? '', 'f365_season' );
                F365_Team_Migration_Helper::term_set( $wp_id, $r['step']     ?? '', 'f365_step' );
                F365_Team_Migration_Helper::term_set( $wp_id, $r['modality'] ?? '', 'f365_modality' );
                F365_Team_Migration_Helper::term_set( $wp_id, $r['gender']   ?? '', 'f365_gender' );

                // Inline Coaching Aggregation
                $tsc = $wpdb->prefix . 'team_teamstepcoach';
                $coach_rows = [];
                if ( reset( $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $tsc ) ) ) === $tsc ) {
                    $coach_rows = $wpdb->get_results( $wpdb->prepare(
                        "SELECT * FROM {$tsc} WHERE team_id = %d ORDER BY `order` ASC, coach_type ASC",
                        (int) $r['id']
                    ), ARRAY_A );
                }

                if ( ! empty( $coach_rows ) ) {
                    $tscc     = $wpdb->prefix . 'team_teamstepcoachcompetition';
                    $has_tscc = ( reset( $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $tscc ) ) ) === $tscc );
                    $coach_dj = array_map( static fn( $c ) => (int) $c['coach_id'], $coach_rows );
                    $coach_map= F365_Team_Migration_Helper::resolve_ids( 'f365_coach', 'django_coach_id', $coach_dj );

                    $acf = $tsc_raw = $tscc_raw = [];
                    foreach ( $coach_rows as $cr ) {
                        $tsc_id = (int) $cr['id'];
                        $cdj    = (int) $cr['coach_id'];

                        $tsc_raw[] = [
                            'id' => $tsc_id, 'team_id' => (int) ( $cr['team_id'] ?? 0 ), 'coach_id' => $cdj,
                            'coach_type' => $cr['coach_type'] ?? '', 'order' => (int) ( $cr['order'] ?? 0 ), 'edition' => $cr['edition'] ?? '',
                        ];

                        $rep = $raw = [];
                        if ( $has_tscc ) {
                            $crows = $wpdb->get_results( $wpdb->prepare(
                                "SELECT * FROM {$tscc} WHERE teamstep_coach_id = %d ORDER BY competition_id ASC",
                                $tsc_id
                            ), ARRAY_A );
                            $comp_dj  = array_map( static fn( $x ) => (int) $x['competition_id'], $crows );
                            $comp_map = F365_Team_Migration_Helper::resolve_ids( 'f365_competition', 'django_competition_id', $comp_dj );
                            foreach ( $crows as $x ) {
                                $cdj2 = (int) $x['competition_id'];
                                $cwp  = (int) ( $comp_map[ $cdj2 ] ?? 0 );
                                if ( $cwp ) { $rep[] = [ 'competition' => $cwp ]; }
                                $raw[] = [ 'id' => (int) ( $x['id'] ?? 0 ), 'teamstep_coach_id' => $tsc_id, 'competition_id' => $cdj2, 'competition_wp_id' => $cwp ?: '' ];
                            }
                            if ( $raw ) { $tscc_raw[] = [ 'teamstepcoach_id' => $tsc_id, 'rows' => $raw ]; }
                        }

                        $acf[] = [
                            'coach' => (int) ( $coach_map[ $cdj ] ?? 0 ) ?: '',
                            'coach_type' => $cr['coach_type'] ?? 'principal',
                            'order' => (int) ( $cr['order'] ?? 0 ),
                            'django_coach_id' => $cdj,
                            'competitions' => $rep,
                            'django_tsc_id' => $tsc_id,
                            'edition' => $cr['edition'] ?? '',
                        ];
                    }

                    update_post_meta( $wp_id, 'team_teamstepcoach_data', $tsc_raw );
                    update_post_meta( $wp_id, 'team_teamstepcoachcompetition_data', $tscc_raw );
                    if ( function_exists( 'update_field' ) ) {
                        update_field( 'field_ts2_coaches', $acf, $wp_id );
                    } else {
                        update_post_meta( $wp_id, 'coaches', $acf );
                    }
                }
            }
        ] );
    }

    protected function import_squad(): void {
        global $wpdb;

        // 1. Framework initialization safeguards (Suspends caches and sets up custom tracking columns)
        wp_suspend_cache_addition( true );
        wp_defer_term_counting( true );
        if ( function_exists( 'wp_defer_comment_counting' ) ) { wp_defer_comment_counting( true ); }
        
        // Ensure legacy columns exist on wp_posts
        static $columns_checked = false;
        if ( ! $columns_checked ) {
            $cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->posts}" );
            if ( ! in_array( 'legacy_table', $cols, true ) ) {
                $wpdb->query( "ALTER TABLE {$wpdb->posts} ADD COLUMN legacy_table VARCHAR(64) NULL DEFAULT NULL" );
            }
            if ( ! in_array( 'legacy_id', $cols, true ) ) {
                $wpdb->query( "ALTER TABLE {$wpdb->posts} ADD COLUMN legacy_id BIGINT NULL DEFAULT NULL" );
            }
            if ( empty( $wpdb->get_results( "SHOW INDEX FROM {$wpdb->posts} WHERE Key_name='f365_legacy'" ) ) ) {
                $wpdb->query( "ALTER TABLE {$wpdb->posts} ADD INDEX f365_legacy (legacy_table, legacy_id)" );
            }
            $columns_checked = true;
        }

        // 2. Derive limits and offsets natively from CLI windows arguments
        $off   = ( ! empty( $this->offset ) ) ? (int) $this->offset : 0;
        $lim   = ( ! empty( $this->limit ) && $this->limit !== PHP_INT_MAX ) ? (int) $this->limit : 5000;
        $t0    = microtime( true );

        $this->log( 'squad', "RUN_START(direct_bulk) window_offset={$off} window_limit={$lim}" );

        // 3. High-speed isolated primary key selection window using Primary Index Forcing
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT 
                sq.*, 
                pp.shortname AS player_shortname, 
                tt.shortname AS team_shortname, 
                ts.edition AS ts_edition,
                ts.step, 
                ts.modality, 
                ts.gender
            FROM (
                SELECT * FROM {$wpdb->prefix}team_squad FORCE INDEX (PRIMARY)
                ORDER BY id ASC LIMIT %d OFFSET %d
            ) sq
            JOIN {$wpdb->prefix}team_teamstep ts ON ts.id = sq.team_id
            JOIN {$wpdb->prefix}team_team tt     ON tt.id = ts.team_id
            JOIN {$wpdb->prefix}person_player pl ON pl.id = sq.player_id
            JOIN {$wpdb->prefix}person_person pp ON pp.id = pl.person_id",
            $lim, $off
        ), ARRAY_A );

        if ( empty( $rows ) ) {
            $this->log( 'squad', "SUMMARY window_offset={$off} rows=0 inserted=0 updated=0 failed=0" );
            return;
        }

        $dids = array_map( static fn( $r ) => (int) $r['id'], $rows );
        
        // 4. Resolve existing target WordPress post IDs instantly in a single query pass
        $exist_map = [];
        $in_clause = implode( ',', $dids );
        $posts_found = $wpdb->get_results( 
            "SELECT ID, legacy_id FROM {$wpdb->posts} WHERE legacy_table = 'team_squad' AND legacy_id IN ($in_clause)", 
            ARRAY_A 
        );
        foreach ( $posts_found as $p ) {
            $exist_map[ (int) $p['legacy_id'] ] = (int) $p['ID'];
        }

        // Hot caching tracking structures
        $batch_ts_map = [];
        $batch_pl_map = [];
        $batch_comp_map = [];
        $bulk_meta_rows = [];
        $posts_to_delete_meta = [];
        $bulk_term_relationships = []; 
        $ins_count = $upd_count = 0;

        // Pre-collect unique entity FK targets to run bulk mappings all at once
        $ts_djs = array_unique( array_map( static fn($r) => (int)$r['team_id'], $rows ) );
        $pl_djs = array_unique( array_map( static fn($r) => (int)$r['player_id'], $rows ) );
        
        if ( ! empty( $ts_djs ) ) { $batch_ts_map = F365_Team_Migration_Helper::resolve_ids( 'f365_teamstep', 'django_teamstep_id', $ts_djs ); }
        if ( ! empty( $pl_djs ) ) { $batch_pl_map = F365_Team_Migration_Helper::resolve_ids( 'f365_player',   'django_player_id',   $pl_djs ); }

        // --- OPTIMIZATION 1: PRE-FETCH SQUAD COMPETITIONS IN BULK FROM POSTGRESQL ---
        $bulk_sc_lookup = [];
        if ( ! empty( $dids ) ) {
            $squad_id_list = implode( ',', $dids );
            $sc_rows = $wpdb->get_results(
                "SELECT squad_id, competition_id, shirt_number, games, goals 
                 FROM {$wpdb->prefix}team_squadcompetition 
                 WHERE squad_id IN ($squad_id_list) 
                 ORDER BY competition_id ASC", 
                ARRAY_A
            );
            
            // Group child items into memory buckets mapped by their parent squad_id
            foreach ( $sc_rows as $sc ) {
                $bulk_sc_lookup[ (int)$sc['squad_id'] ][] = $sc;
            }
            
            // Pre-resolve missing competition links all at once
            $comp_djs = array_unique( array_map( static fn($x) => (int)$x['competition_id'], $sc_rows ) );
            if ( ! empty( $comp_djs ) ) {
                $batch_comp_map = F365_Team_Migration_Helper::resolve_ids( 'f365_competition', 'django_competition_id', $comp_djs );
            }
        }

        // --- OPTIMIZATION 2: PRE-FETCH TAXONOMIES IN BULK ---
        $needed_slugs = [];
        foreach ( $rows as $r ) {
            if ( ! empty( $r['ts_edition'] ) ) { $needed_slugs[] = sanitize_title( $r['ts_edition'] ); }
            if ( ! empty( $r['step'] ) )       { $needed_slugs[] = sanitize_title( $r['step'] ); }
            if ( ! empty( $r['modality'] ) )   { $needed_slugs[] = sanitize_title( $r['modality'] ); }
            if ( ! empty( $r['gender'] ) )     { $needed_slugs[] = sanitize_title( $r['gender'] ); }
        }
        
        $tt_id_lookup = [];
        if ( ! empty( $needed_slugs ) ) {
            $slug_list = "'" . implode( "','", array_map( 'esc_sql', array_unique( $needed_slugs ) ) ) . "'";
            $term_rows = $wpdb->get_results(
                "SELECT t.slug, tt.term_taxonomy_id, tt.taxonomy 
                 FROM {$wpdb->terms} t 
                 JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id 
                 WHERE t.slug IN ($slug_list)", 
                ARRAY_A
            );
            foreach ( $term_rows as $tr ) {
                $tt_id_lookup[ $tr['taxonomy'] ][ $tr['slug'] ] = (int) $tr['term_taxonomy_id'];
            }
        }

        $now = current_time( 'mysql' );
        $gmt = current_time( 'mysql', 1 );

        // 5. OPEN ONE ATOMIC TRANSACTION FOR THE ENTIRE CHUNK FLUSH
        $wpdb->query( 'START TRANSACTION' );

        try {
            foreach ( $rows as $r ) {
                $django_id = (int) $r['id'];
                $title     = trim( "{$r['player_shortname']} — {$r['team_shortname']} {$r['ts_edition']}" );
                if ( $title === '' || $title === '—' ) { $title = 'Squad #' . $django_id; }

                $is_update = isset( $exist_map[ $django_id ] );

                // Resolve or create WP Post entry
                if ( $is_update ) {
                    $wp_id = (int) $exist_map[ $django_id ];
                    
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$wpdb->posts} SET post_title = %s, post_modified = %s, post_modified_gmt = %s WHERE ID = %d",
                        $title, $now, $gmt, $wp_id
                    ) );
                    $posts_to_delete_meta[] = $wp_id;
                    $upd_count++;
                    $this->log( 'squad', "[UPDATE] Django ID: {$django_id} → WP ID: {$wp_id}" );
                } else {
                    $wpdb->query( $wpdb->prepare(
                        "INSERT INTO {$wpdb->posts} 
                            (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_name, post_modified, post_modified_gmt, post_type, legacy_table, legacy_id, post_content_filtered, to_ping, pinged)
                         VALUES (1, %s, %s, '', %s, '', 'publish', 'closed', 'closed', %s, %s, %s, 'f365_squad', 'team_squad', %d, '', '', '')",
                        $now, $gmt, $title, 'f365-squad-' . $django_id, $now, $gmt, $django_id
                    ) );
                    $wp_id = (int) $wpdb->insert_id;
                    $exist_map[ $django_id ] = $wp_id;
                    $bulk_meta_rows[] = [ $wp_id, 'django_squad_id', $django_id ];
                    $ins_count++;
                    $this->log( 'squad', "[INSERT] Django ID: {$django_id} → WP ID: {$wp_id}" );
                }

                // --- CACHED READ: FETCH FROM IN-MEMORY ARRAY INSTEAD OF POSTGRESQL ---
                $sc_data = [];
                $tot_shirt = $tot_games = $tot_goals = $primary_comp = 0;
                $sc_rows = $bulk_sc_lookup[ $django_id ] ?? [];

                foreach ( $sc_rows as $sc ) {
                    $cdj = (int) $sc['competition_id'];
                    $cwp = (int) ( $batch_comp_map[ $cdj ] ?? 0 );
                    if ( ! $tot_shirt && (int) $sc['shirt_number'] ) { $tot_shirt = (int) $sc['shirt_number']; }
                    if ( ! $primary_comp && $cwp ) { $primary_comp = $cwp; }
                    $tot_games += (int) $sc['games'];
                    $tot_goals += (int) $sc['goals'];

                    $sc_data[] = [
                        'competition'           => $cwp, 
                        'shirt_number'          => (int)$sc['shirt_number'],
                        'games'                 => (int)$sc['games'], 
                        'goals'                 => (int)$sc['goals'], 
                        'django_competition_id' => $cdj
                    ];
                }

                // Compile meta mapping matrices
                $meta = [
                    'team_step'          => (int)($batch_ts_map[(int)$r['team_id']] ?? 0),
                    'player'             => (int)($batch_pl_map[(int)$r['player_id']] ?? 0),
                    'competition'        => $primary_comp, 
                    'position'           => $r['position'] ?? '', 
                    'squad_order'        => (int)$r['order'],
                    'shirt_number'       => $tot_shirt, 
                    'games'              => $tot_games, 
                    'goals'              => $tot_goals,
                    'edition'            => $r['ts_edition'] ?? '', 
                    'step'               => $r['step'] ?? '', 
                    'modality'           => $r['modality'] ?? '', 
                    'gender'             => $r['gender'] ?? '',
                    'django_teamstep_id' => (int)$r['team_id'], 
                    'django_player_id'   => (int)$r['player_id'], 
                    'django_tbl_name'    => 'team_squad',
                    'squad_competitions' => maybe_serialize( $sc_data )
                ];

                foreach ( $meta as $k => $v ) {
                    $bulk_meta_rows[] = [ $wp_id, $k, $v ];
                }

                // Stage terms directly in memory
                $tax_mappings = [
                    'f365_season'   => !empty($r['ts_edition']) ? sanitize_title($r['ts_edition']) : '',
                    'f365_step'     => !empty($r['step']) ? sanitize_title($r['step']) : '',
                    'f365_modality' => !empty($r['modality']) ? sanitize_title($r['modality']) : '',
                    'f365_gender'   => !empty($r['gender']) ? sanitize_title($r['gender']) : '',
                ];

                foreach ( $tax_mappings as $taxonomy => $slug ) {
                    if ( ! empty( $slug ) && isset( $tt_id_lookup[ $taxonomy ][ $slug ] ) ) {
                        $tt_id = $tt_id_lookup[ $taxonomy ][ $slug ];
                        $bulk_term_relationships[] = "($wp_id, $tt_id, 0)";
                    }
                }
            }

            // 6. EXECUTE WRITE DELETIONS AND BULK INSERTIONS IN 1 TRANSACTION PASS
            if ( ! empty( $posts_to_delete_meta ) ) {
                $id_list = implode( ',', $posts_to_delete_meta );
                $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($id_list) AND meta_key != 'django_squad_id'" );
            }

            if ( ! empty( $bulk_meta_rows ) ) {
                $format_values = [];
                $query_placeholders = [];
                foreach ( $bulk_meta_rows as $row ) {
                    $query_placeholders[] = '(%d, %s, %s)';
                    $format_values[] = $row[0]; $format_values[] = $row[1]; $format_values[] = $row[2];
                }
                $master_sql = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ', ', $query_placeholders );
                $wpdb->query( $wpdb->prepare( $master_sql, $format_values ) );
            }

            // Execute raw bulk terms insertion
            if ( ! empty( $bulk_term_relationships ) ) {
                $relationships_chunked = array_chunk( $bulk_term_relationships, 1000 );
                foreach ( $relationships_chunked as $chunk ) {
                    $values_sql = implode( ', ', $chunk );
                    $wpdb->query( "INSERT IGNORE INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order) VALUES $values_sql" );
                }
            }

            // Commit everything to disk safely in a single execution block
            $wpdb->query( 'COMMIT' );

        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            $this->log( 'squad', 'FATAL BATCH ROLLBACK: ' . $e->getMessage() );
            if ( class_exists( 'WP_CLI' ) ) { WP_CLI::error( $e->getMessage() ); }
            return;
        }

        // 7. Cleanup memory allocation buckets
        wp_suspend_cache_addition( false );
        wp_defer_term_counting( false );
        if ( function_exists( 'wp_defer_comment_counting' ) ) { wp_defer_comment_counting( false ); }

        $elapsed = microtime( true ) - $t0;
        $this->log( 'squad', sprintf( 'BATCH_OK offset=%d rows=%d inserted=%d updated=%d failed=0 elapsed=%.2fs', $off, count( $rows ), $ins_count, $upd_count, $elapsed ) );
        if ( class_exists( 'WP_CLI' ) ) { WP_CLI::success( "Processed " . count($rows) . " records in {$elapsed}s" ); }
    }

    protected function import_team_presidents(): void {
        global $wpdb;
        $this->f365_direct_run( [
            'module'     => 'team_presidents',
            'legacy_tag' => 'team_teampresident',
            'post_type'  => 'f365_team_president',
            'id_key'     => 'django_id',
            'map_type'   => 'f365_team_president',
            'slug'       => 'f365-tp-',
            'count_sql'  => "SELECT COUNT(*) FROM {$wpdb->prefix}team_teampresident",
            'select_sql' => "SELECT tp.*, pp.shortname AS president_name, tt.shortname AS team_shortname
                               FROM {$wpdb->prefix}team_teampresident tp
                               JOIN {$wpdb->prefix}team_team tt        ON tt.id = tp.team_id
                               JOIN {$wpdb->prefix}person_president pr ON pr.id = tp.president_id
                               JOIN {$wpdb->prefix}person_person pp    ON pp.id = pr.person_id
                              ORDER BY tp.id",
            'title'      => function ( $r ) {
                $t = trim( "{$r['president_name']} — {$r['team_shortname']} {$r['edition']}" );
                return $t !== '' ? $t : 'President assignment #' . (int) $r['id'];
            },
            'hydrate_fn' => function( $wp_id, $r ) {
                $team_map = F365_Team_Migration_Helper::resolve_ids( 'f365_team',      'django_id',            [ (int) ( $r['team_id'] ?? 0 ) ] );
                $pres_map = F365_Team_Migration_Helper::resolve_ids( 'f365_president', 'django_president_id',   [ (int) ( $r['president_id'] ?? 0 ) ] );

                $meta = [
                    'team'                => (int) ( $team_map[ (int) ( $r['team_id'] ?? 0 ) ] ?? 0 ),
                    'president'           => (int) ( $pres_map[ (int) ( $r['president_id'] ?? 0 ) ] ?? 0 ),
                    'edition'             => $r['edition'] ?? '',
                    'order'               => (int) ( $r['order'] ?? 0 ),
                    'president_type'      => $r['president_type'] ?? 'president',
                    'django_team_id'      => (int) ( $r['team_id'] ?? 0 ),
                    'django_president_id' => (int) ( $r['president_id'] ?? 0 ),
                    'django_tbl_name'     => 'team_teampresident',
                ];
                foreach ( $meta as $k => $v ) {
                    update_post_meta( $wp_id, $k, $v );
                }
            }
        ] );
    }

    /**
     * Shared migration engine runner: full heavy data insertion during CLI processing.
     */
    private function f365_direct_run( array $c ): void {
        global $wpdb;

        wp_suspend_cache_addition( true );
        wp_defer_term_counting( true );
        if ( function_exists( 'wp_defer_comment_counting' ) ) { wp_defer_comment_counting( true ); }

        $this->f365_ensure_legacy_columns();

        $total = (int) $wpdb->get_var( $c['count_sql'] );
        $off   = ( ! empty( $this->offset ) ) ? (int) $this->offset : 0;
        $lim   = ( ! empty( $this->limit ) && $this->limit !== PHP_INT_MAX ) ? (int) $this->limit : max( 0, $total - $off );
        $batch = $this->batch > 0 ? (int) $this->batch : 1000;

        $this->log( $c['module'], "RUN_START(direct) post_type={$c['post_type']} window_offset={$off} window_limit={$lim} source_total={$total} sub_batch={$batch}" );
        WP_CLI::log( "{$c['module']}: window offset={$off} limit={$lim} source={$total} batch={$batch}" );

        $ins = $upd = $fail = 0;
        $new = [];
        $done = 0;

        while ( $done < $lim ) {
            $chunk = (int) min( $batch, $lim - $done );
            $abs   = $off + $done;
            $t0    = microtime( true );

            $rows = $wpdb->get_results( $c['select_sql'] . ' LIMIT ' . $chunk . ' OFFSET ' . $abs, ARRAY_A );
            if ( empty( $rows ) ) { break; }

            $dids  = array_map( static fn( $r ) => (int) $r['id'], $rows );
            $exist = $this->f365_resolve_existing( $c['legacy_tag'], $c['post_type'], $c['id_key'], $dids );

            $now = current_time( 'mysql' );
            $gmt = current_time( 'mysql', 1 );

            $wpdb->query( 'START TRANSACTION' );
            try {
                foreach ( $rows as $r ) {
                    $id    = (int) $r['id'];
                    $title = ( $c['title'] )( $r );

                    if ( isset( $exist[ $id ] ) ) {
                        $wp_id = (int) $exist[ $id ];
                        if ( $wpdb->query( $wpdb->prepare(
                            "UPDATE {$wpdb->posts} SET post_title=%s, post_modified=%s, post_modified_gmt=%s, legacy_table=%s, legacy_id=%d WHERE ID=%d",
                            $title, $now, $gmt, $c['legacy_tag'], $id, $wp_id
                        ) ) === false ) {
                            throw new \RuntimeException( 'wp_posts UPDATE failed: ' . $wpdb->last_error );
                        }
                        $upd++;
                    } else {
                        $ok = $wpdb->query( $wpdb->prepare(
                            "INSERT INTO {$wpdb->posts}
                                (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt,
                                 post_status, comment_status, ping_status, post_password, post_name, to_ping,
                                 pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent,
                                 guid, menu_order, post_type, post_mime_type, comment_count, legacy_table, legacy_id)
                             VALUES (1,%s,%s,'',%s,'','publish','closed','closed','',%s,'','',%s,%s,'',0,'',0,%s,'',0,%s,%d)",
                            $now, $gmt, $title, $c['slug'] . $id, $now, $gmt, $c['post_type'], $c['legacy_tag'], $id
                        ) );
                        if ( $ok === false || ! $wpdb->insert_id ) {
                            throw new \RuntimeException( 'wp_posts INSERT failed: ' . $wpdb->last_error );
                        }
                        $wp_id = (int) $wpdb->insert_id;
                        update_post_meta( $wp_id, $c['id_key'], $id );
                        $ins++;
                    }

                    $new[ $id ] = $wp_id;
                    
                    // Directly populate all heavy relational details
                    ( $c['hydrate_fn'] )( $wp_id, $r );
                }
                $wpdb->query( 'COMMIT' );
            } catch ( \Throwable $e ) {
                $wpdb->query( 'ROLLBACK' );
                $fail += count( $rows );
                $this->log( $c['module'], "FAIL offset={$abs} — " . $e->getMessage() );
                WP_CLI::warning( "Batch offset={$abs} rolled back: " . $e->getMessage() );
                break;
            }

            $done += count( $rows );
            $this->log( $c['module'], sprintf( 'BATCH_OK offset=%d rows=%d inserted=%d updated=%d failed=%d elapsed=%.2fs', $abs, count( $rows ), $ins, $upd, $fail, microtime( true ) - $t0 ) );

            if ( function_exists( 'wp_cache_flush_runtime' ) ) { wp_cache_flush_runtime(); }
            else { wp_cache_flush(); }

            if ( count( $rows ) < $chunk ) { break; }
        }

        $this->f365_map_merge_locked( $c['map_type'], $new );

        wp_suspend_cache_addition( false );
        wp_defer_term_counting( false );
        if ( function_exists( 'wp_defer_comment_counting' ) ) { wp_defer_comment_counting( false ); }

        $this->log( $c['module'], "SUMMARY window_offset={$off} window_limit={$lim} inserted={$ins} updated={$upd} failed={$fail}" );
        WP_CLI::success( "{$c['module']} [{$off}..+{$lim}]: inserted={$ins} updated={$upd} failed={$fail}. Data structured directly into Admin Tables." );
    }

    private function teamstep_suffix( string $step, string $modality, string $gender ): string {
        $suffix = '';
        if ( $modality === 'futsal' ) { $suffix .= 'FS'; }
        if ( $gender === 'female' )   { $suffix .= ' FM'; }

        $step_labels = [ 'b_team' => 'B', 'c_team' => 'C', 'veterans' => 'VET', 'reservations' => 'RES' ];
        if ( isset( $step_labels[ $step ] ) ) {
            $suffix .= ' ' . $step_labels[ $step ];
        } elseif ( $step !== 'principal' ) {
            $suffix .= ' ' . strtoupper( str_replace( '_', '-', $step ) );
        }
        return trim( $suffix );
    }

    private function f365_ensure_legacy_columns(): void {
        static $done = false;
        if ( $done ) { return; }
        $done = true;
        global $wpdb;
        $cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->posts}" );
        if ( ! in_array( 'legacy_table', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->posts} ADD COLUMN legacy_table VARCHAR(64) NULL DEFAULT NULL" );
        }
        if ( ! in_array( 'legacy_id', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->posts} ADD COLUMN legacy_id BIGINT NULL DEFAULT NULL" );
        }
        if ( empty( $wpdb->get_results( "SHOW INDEX FROM {$wpdb->posts} WHERE Key_name='f365_legacy'" ) ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->posts} ADD INDEX f365_legacy (legacy_table, legacy_id)" );
        }
    }

    private function f365_resolve_existing( string $legacy_tag, string $post_type, string $meta_key, array $django_ids ): array {
        global $wpdb;
        $found = [];
        $ids   = array_values( array_unique( array_map( 'intval', $django_ids ) ) );
        if ( ! $ids ) { return $found; }
        $in = implode( ',', $ids );
        foreach ( (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, legacy_id FROM {$wpdb->posts} WHERE legacy_table = %s AND legacy_id IN ($in)",
            $legacy_tag
        ), ARRAY_A ) as $r ) {
            $found[ (int) $r['legacy_id'] ] = (int) $r['ID'];
        }
        $pending = array_values( array_diff( $ids, array_keys( $found ) ) );
        if ( $pending ) {
            $in2 = implode( ',', $pending );
            foreach ( (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s AND pm.meta_value IN ($in2) AND p.post_type = %s",
                $meta_key, $post_type
            ), ARRAY_A ) as $r ) {
                $found[ (int) $r['meta_value'] ] = (int) $r['post_id'];
            }
        }
        return $found;
    }

    private function f365_map_merge_locked( string $type, array $pairs ): void {
        if ( ! $pairs ) { return; }
        global $wpdb;
        $opt  = "f365_mig_{$type}";
        $lock = 'f365map_' . $type;
        $got  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock, 30 ) );
        try {
            wp_cache_delete( $opt, 'options' );
            $cur = get_option( $opt, '{}' );
            if ( is_array( $cur ) ) {
                $map = $cur;
            } else {
                $decoded = json_decode( (string) $cur, true );
                $map     = is_array( $decoded ) ? $decoded : [];
            }
            foreach ( $pairs as $dj => $wp ) {
                $map[ (string) (int) $dj ] = (int) $wp;
            }
            update_option( $opt, wp_json_encode( $map ), false );
        } finally {
            if ( $got === 1 ) {
                $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
            }
        }
    }
}