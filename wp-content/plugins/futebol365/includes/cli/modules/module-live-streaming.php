<?php
/**
 * Migration module: Live Streaming.
 *
 * Django tables (app: live):
 * live_livestreaming  → f365_livestream  (CPT, 1 post per LiveStreaming)   ← primary module
 * live_matchontv      → f365_livestream  (channel_type='tv')               ← secondary
 * live_livestream     → f365_livestream  (channel_type='betting')          ← secondary
 *
 * @package Futebol365
 * @since   2.5.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    return;
}

trait F365_Module_LiveStreaming {

    protected function import_live_streaming(): void {
        $this->_import_livestreaming_table();
    }

    private function _import_livestreaming_table(): void {
        global $wpdb;

        if ( ! $this->pg_val( "SELECT to_regclass('public.live_livestreaming')" ) ) {
            WP_CLI::log( '  Table live_livestreaming not found — skipping.' );
            return;
        }

        wp_suspend_cache_addition( true );
        wp_defer_term_counting( true );

        $total     = (int) $this->pg_val( 'SELECT COUNT(*) FROM live_livestreaming' );
        $max       = $this->limit ?: $total;
        $effective = min( $total, $max );
        WP_CLI::log( "Live streaming total: {$total} | effective: {$effective} | batch: {$this->batch} | offset: {$this->offset}" );
        $progress = WP_CLI\Utils\make_progress_bar( 'Live streaming', $effective );

        $n = $skip = $fail = 0;
        $offset = $this->offset;

        while ( $offset < $total && $n < $this->limit ) {
            $rows = $this->pg_all( '
                SELECT ls.id, ls.match_id, ls.datetime, ls.url,
                       m.competition_id  AS match_competition_id,
                       m.date_time       AS match_datetime,
                       m.situation       AS match_situation,
                       htt.shortname     AS home_team_name,
                       vtt.shortname     AS visitor_team_name,
                       c.name            AS competition_name,
                       c.season          AS competition_season,
                       m.home_score      AS home_score,
                       m.visitor_score   AS visitor_score,
                       m.home_team_id    AS home_team_id,
                       m.visitor_team_id AS visitor_team_id
                FROM live_livestreaming ls
                LEFT JOIN competition_match       m   ON m.id   = ls.match_id
                LEFT JOIN team_teamstep           ht  ON ht.id  = m.home_team_id
                LEFT JOIN team_team               htt ON htt.id = ht.team_id
                LEFT JOIN team_teamstep           vt  ON vt.id  = m.visitor_team_id
                LEFT JOIN team_team               vtt ON vtt.id = vt.team_id
                LEFT JOIN competition_competition c   ON c.id   = m.competition_id
                ORDER BY ls.id ASC LIMIT $1 OFFSET $2
            ', [ $this->batch, $offset ] );

            if ( empty( $rows ) ) { break; }

            // =========================================================================
            // ULTRA-FAST PLUCK-AND-CACHE RELATIONSHIP LOOKUPS (STRICT CPT ENFORCED)
            // =========================================================================
            $batch_match_ids        = array_filter( array_unique( array_column( $rows, 'match_id' ) ) );
            $batch_comp_ids         = array_filter( array_unique( array_column( $rows, 'match_competition_id' ) ) );
            $batch_home_team_ids    = array_filter( array_unique( array_column( $rows, 'home_team_id' ) ) );
            $batch_visitor_team_ids = array_filter( array_unique( array_column( $rows, 'visitor_team_id' ) ) );
            $batch_team_step_ids    = array_filter( array_unique( array_merge( $batch_home_team_ids, $batch_visitor_team_ids ) ) );

            $batch_match_map = [];
            $batch_comp_map  = [];
            $batch_team_map  = [];

            // 1. Fetch Match IDs for this batch from 'f365_match'
            if ( ! empty( $batch_match_ids ) ) {
                $match_placeholders = implode( ',', array_map( 'intval', $batch_match_ids ) );
                $match_results = $wpdb->get_results( "
                    SELECT p.ID as post_id, pm.meta_value 
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                    WHERE pm.meta_key = 'django_match_id' 
                      AND pm.meta_value IN ($match_placeholders)
                      AND p.post_type = 'f365_match'
                " );
                foreach ( $match_results as $res ) {
                    $batch_match_map[ (int) $res->meta_value ] = (int) $res->post_id;
                }
            }

            // 2. Fetch Competition IDs for this batch from 'f365_competition'
            if ( ! empty( $batch_comp_ids ) ) {
                $comp_placeholders = implode( ',', array_map( 'intval', $batch_comp_ids ) );
                $comp_results = $wpdb->get_results( "
                    SELECT p.ID as post_id, pm.meta_value 
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                    WHERE pm.meta_key = 'django_competition_id' 
                      AND pm.meta_value IN ($comp_placeholders)
                      AND p.post_type = 'f365_competition'
                " );
                foreach ( $comp_results as $res ) {
                    $batch_comp_map[ (int) $res->meta_value ] = (int) $res->post_id;
                }
            }

            // 3. Fetch TeamStep IDs for this batch from 'f365_teamstep'
            if ( ! empty( $batch_team_step_ids ) ) {
                $team_placeholders = implode( ',', array_map( 'intval', $batch_team_step_ids ) );
                $team_results = $wpdb->get_results( "
                    SELECT p.ID as post_id, pm.meta_value 
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                    WHERE pm.meta_key = 'django_teamstep_id' 
                      AND pm.meta_value IN ($team_placeholders)
                      AND p.post_type = 'f365_teamstep'
                " );
                foreach ( $team_results as $res ) {
                    $batch_team_map[ (int) $res->meta_value ] = (int) $res->post_id;
                }
            }
            // =========================================================================

            foreach ( $rows as $r ) {
                if ( $n >= $this->limit ) { break; }
                $progress->tick();

                $django_id = (int) $r['id'];
                $map_key   = "livestreaming_{$django_id}";

                // Check if post already exists
                $is_update = false;
                $map = $this->map_get( 'f365_livestream' );
                $existing_wp_id = isset( $map[ $map_key ] ) ? (int) $map[ $map_key ] : 0;
                
                if ( $existing_wp_id && get_post( $existing_wp_id ) ) {
                    $is_update = true;
                }

                // Skip optimization checkpoint
                if ( $is_update && ! $this->force ) {
                    $skip++;
                    $n++;
                    continue;
                }

                $home          = $r['home_team_name']     ?? '';
                $away          = $r['visitor_team_name']  ?? '';
                $home_score    = $r['home_score']         ?? '';
                $visitor_score = $r['visitor_score']      ?? '';
                $comp          = $r['competition_name']   ?? '';
                $season        = $r['competition_season'] ?? '';
                
                $django_match_id        = !empty($r['match_id']) ? (int)$r['match_id'] : null;
                $django_competition_id  = !empty($r['match_competition_id']) ? (int)$r['match_competition_id'] : null;
                $django_home_team_id    = !empty($r['home_team_id']) ? (int)$r['home_team_id'] : null;
                $django_visitor_team_id = !empty($r['visitor_team_id']) ? (int)$r['visitor_team_id'] : null;

                $title = ( $comp && $home && $away )
                    ? trim( "{$comp}: {$home} - {$away}" . ( $season ? " ({$season})" : '' ) )
                    : ( trim( "{$home} - {$away}" ) ?: "Live Streaming #{$django_id}" );

                // 1. Core Post Processing Engine
                $wp_id = $this->_upsert_livestream(
                    $map_key,
                    $django_id,
                    'live_livestreaming',
                    [
                        'post_title'  => $title,
                        'post_status' => 'publish',
                        'post_date'   => $r['datetime'] ? date( 'Y-m-d H:i:s', strtotime( $r['datetime'] ) ) : '',
                    ]
                );

                if ( ! $wp_id ) {
                    $fail++;
                    $this->log( 'livestreaming', "FAIL livestreaming id={$django_id}" );
                    continue;
                }

                // 2. Structured Meta Engine Snapshot updates
                $situation      = $r['match_situation'] ?? null;
                $match_status   = ( $situation === null || $situation === '' ) ? 'scheduled' : $situation;
                $match_datetime = $r['match_datetime'] ?? '';
                $match_ts       = $match_datetime ? strtotime( $match_datetime ) : 0;

                $meta = [
                    'url'                         => $r['url']  ?? '',
                    'datetime'                    => $r['datetime'] ?? '',
                    'home_team_name'              => $home,
                    'visitor_team_name'           => $away,
                    'home_score'                  => $home_score,
                    'visitor_score'               => $visitor_score,
                    'competition_season'          => $season,
                    'match_datetime'              => $match_datetime,
                    'match_timestamp'             => $match_ts,
                    'django_livestream_id'        => $django_id,
                    'django_home_team_step_id'    => $django_home_team_id,
                    'django_visitor_team_step_id' => $django_visitor_team_id,
                    'django_source_table'         => 'live_livestreaming',
                    'django_match_id'             => $django_match_id,
                    'django_competition_id'       => $django_competition_id,
                ];

                $this->set_meta( $wp_id, $meta );
                update_post_meta( $wp_id, 'match_situation', $match_status );

                // 3. Fast In-Memory Structural Mapping Engine
                if ( $django_match_id && isset( $batch_match_map[ $django_match_id ] ) ) {
                    update_post_meta( $wp_id, 'match', $batch_match_map[ $django_match_id ] );
                }

                if ( $django_competition_id && isset( $batch_comp_map[ $django_competition_id ] ) ) {
                    update_post_meta( $wp_id, 'competition', $batch_comp_map[ $django_competition_id ] );
                }

                if ( $django_home_team_id && isset( $batch_team_map[ $django_home_team_id ] ) ) {
                    update_post_meta( $wp_id, 'home_teamstep', $batch_team_map[ $django_home_team_id ] );
                }

                if ( $django_visitor_team_id && isset( $batch_team_map[ $django_visitor_team_id ] ) ) {
                    update_post_meta( $wp_id, 'visitor_teamstep', $batch_team_map[ $django_visitor_team_id ] );
                }

                if ( $season ) {
                    $this->set_terms( $wp_id, [ $season ], 'f365_season' );
                }

                update_post_meta( $wp_id, '_yoast_wpseo_title', "LIVE STREAMING - {$title} - Futebol 365" );
                update_post_meta( $wp_id, '_yoast_wpseo_metadesc', "Assista em direto e ao vivo o jogo {$title}." );

                $this->map_set( 'f365_livestream', $map_key, $wp_id );
                $this->log( 'livestreaming', ($is_update ? "FORCE-UPDATE" : "OK") . " livestreaming id={$django_id} → wp_id={$wp_id}" );
                $n++;
            }

            $this->map_save( 'f365_livestream' );
            wp_cache_flush();
            $offset += $this->batch;
        }

        $progress->finish();
        wp_suspend_cache_addition( false );
        wp_defer_term_counting( false );
        $this->log( 'livestreaming', "SUMMARY livestreaming: imported={$n} skipped={$skip} failed={$fail}" );
        WP_CLI::success( "Live streaming: {$n} processed, {$skip} skipped, {$fail} failed." );
    }

    /**
     * Handles strict core updates or initial insertion securely.
     */
    private function _upsert_livestream( string $map_key, int $django_id, string $source_table, array $core ): int {
        global $wpdb;

        $map   = $this->map_get( 'f365_livestream' );
        $wp_id = isset( $map[ $map_key ] ) ? (int) $map[ $map_key ] : 0;
        if ( $wp_id && ! get_post( $wp_id ) ) { $wp_id = 0; }

        if ( ! $wp_id ) {
            $wp_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT lid.post_id FROM {$wpdb->postmeta} lid
                 INNER JOIN {$wpdb->postmeta} src ON src.post_id = lid.post_id AND src.meta_key='django_source_table' AND src.meta_value=%s
                 INNER JOIN {$wpdb->posts} p ON p.ID = lid.post_id
                 WHERE lid.meta_key='django_livestream_id' AND lid.meta_value=%d AND p.post_type='f365_livestream' LIMIT 1",
                $source_table, $django_id
            ) );
            if ( $wp_id ) { $this->map_set( 'f365_livestream', $map_key, $wp_id ); }
        }

        if ( $wp_id ) {
            if ( ! $this->dry_run ) {
                wp_update_post( array_merge( [ 'ID' => $wp_id ], array_intersect_key( $core, array_flip( [ 'post_title', 'post_status', 'post_date' ] ) ) ) );
            }
            return $wp_id;
        }

        $wp_id = $this->insert_post( array_merge( [ 'post_type' => 'f365_livestream' ], $core ) );
        if ( $wp_id ) { $this->map_set( 'f365_livestream', $map_key, $wp_id ); }
        return (int) $wp_id;
    }
}