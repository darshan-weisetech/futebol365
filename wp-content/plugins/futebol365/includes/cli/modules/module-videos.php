<?php
/**
 * Migration module: Videos (optimized for large datasets)
 *
 * Django table : multimedia_video
 * WordPress CPT : f365_video
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'WP_CLI' ) && WP_CLI ) :

trait F365_Module_Videos {

    protected function import_videos(): void {
        global $wpdb;

        // Prevent memory leaks during long-running WP-CLI scripts
        wp_suspend_cache_addition( true );

        $T_VIDEO = $wpdb->prefix . 'multimedia_video';

        if ( ! $this->src_table_exists( $T_VIDEO ) ) {
            WP_CLI::error( "Source table missing: ensure {$T_VIDEO} is imported." );
            wp_suspend_cache_addition( false );
            return;
        }

        // Keyset cursor + optional range bound.
        $after  = (int) ( $this->assoc_args['after-id'] ?? $this->offset );
        $max_id = isset( $this->assoc_args['max-id'] ) ? (int) $this->assoc_args['max-id'] : 0;
        $is_relations = isset( $this->assoc_args['relations'] ) || isset( $this->assoc_args['update-relations-only'] );

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T_VIDEO}" );
        $cap   = min( $total, (int) $this->limit );

        $mode_label = $is_relations ? 'Relations-only' : 'Thin import';
        WP_CLI::log( "Videos (thin): total=$total cap=$cap batch={$this->batch} after-id={$after}" . ( $max_id ? " max-id={$max_id}" : '' ) . " | mode: $mode_label" );
        $progress = WP_CLI\Utils\make_progress_bar( 'Videos', $cap );

        $created = $updated = $fail = $skip = 0;
        $handled = 0;

        while ( $handled < (int) $this->limit ) {
            $where_max = $max_id > 0 ? ' AND v.id <= ' . $max_id : '';
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT v.id, v.title, v.url, v.datetime, v.updated_at, v.file_type
                       FROM {$T_VIDEO} v
                      WHERE v.id > %d {$where_max}
                      ORDER BY v.id ASC
                      LIMIT %d",
                    $after,
                    (int) $this->batch
                ),
                ARRAY_A
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $r ) {
                if ( $handled >= (int) $this->limit ) {
                    break;
                }
                $after = (int) $r['id']; 
                $handled++;
                $progress->tick();

                $django_id = (int) $r['id'];
                $title = (string) ( $r['title'] ?? '' );
                $pd = ! empty( $r['datetime'] ) ? date( 'Y-m-d H:i:s', strtotime( $r['datetime'] ) ) : '';
                $pd_mod = ! empty( $r['updated_at'] ) ? date( 'Y-m-d H:i:s', strtotime( $r['updated_at'] ) ) : '';

                $wp_id = $this->video_find_existing( $django_id );

                if ( $is_relations ) {
                    // Relations update skipped as lazy hydration has been removed.
                    $skip++;
                    continue;
                }

                if ( ! $wp_id ) {
                    if ( $this->dry_run ) { $created++; continue; }

                    $wpdb->query( $wpdb->prepare(
                        "INSERT INTO {$wpdb->posts} (
                            post_author, post_title, post_name, post_status, post_type,
                            post_date, post_date_gmt, post_modified, post_modified_gmt,
                            comment_status, ping_status, post_content, post_excerpt,
                            to_ping, pinged, post_content_filtered, post_mime_type,
                            legacy_table, legacy_id
                        ) VALUES (
                            %d, %s, %s, %s, %s, %s, %s, %s, %s,
                            %s, %s, %s, %s, %s, %s, %s, %s, %s, %d
                        )",
                        1, $title, (string) $django_id, 'publish', 'f365_video',
                        $pd ?: current_time( 'mysql' ),
                        $pd ? get_gmt_from_date( $pd ) : current_time( 'mysql', 1 ),
                        $pd_mod ?: ( $pd ?: current_time( 'mysql' ) ),
                        $pd_mod ? get_gmt_from_date( $pd_mod ) : ( $pd ? get_gmt_from_date( $pd ) : current_time( 'mysql', 1 ) ),
                        'closed', 'closed', '', '', '', '', '', '',
                        'multimedia_video', $django_id
                    ) );
                    $wp_id = (int) $wpdb->insert_id;

                    if ( ! $wp_id ) {
                        $fail++;
                        $this->log( 'videos', "Missing/insert-failed video_id={$django_id}" );
                        continue;
                    }

                    // Write base identifiers in meta via a single bulk insert
                    $wpdb->query( $wpdb->prepare(
                        "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES 
                            (%d, 'django_video_id', %s),
                            (%d, '_django_video_id', 'field_v_django_id'),
                            (%d, 'django_source_table', 'multimedia_video')",
                        $wp_id, (string) $django_id, $wp_id, $wp_id
                    ) );

                    $created++;
                    $this->log( 'videos', "Created Video #{$django_id} -> wp_id={$wp_id} ({$title})" );

                } else {
                    if ( $this->dry_run ) { $updated++; continue; }

                    $upd = [ 'post_title' => $title, 'post_name' => (string) $django_id ];
                    if ( $pd_mod ) {
                        $upd['post_modified']     = $pd_mod;
                        $upd['post_modified_gmt'] = get_gmt_from_date( $pd_mod );
                    }
                    $wpdb->update( $wpdb->posts, $upd, [ 'ID' => $wp_id ] );

                    // Self-healing backfill legacy columns if empty
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$wpdb->posts}
                            SET legacy_table = 'multimedia_video', legacy_id = %d
                          WHERE ID = %d AND ( legacy_table IS NULL OR legacy_id IS NULL )",
                        $django_id, $wp_id
                    ) );

                    if ( ! get_post_meta( $wp_id, 'django_video_id', true ) ) {
                        update_post_meta( $wp_id, 'django_video_id', $django_id );
                        update_post_meta( $wp_id, '_django_video_id', 'field_v_django_id' );
                    }
                    if ( ! get_post_meta( $wp_id, 'django_source_table', true ) ) {
                        update_post_meta( $wp_id, 'django_source_table', 'multimedia_video' );
                    }

                    $updated++;
                    $this->log( 'videos', "Updated Video #{$django_id} -> wp_id={$wp_id} ({$title})" );
                }
            }

            if ( count( $rows ) < (int) $this->batch ) {
                unset( $rows );
                break;
            }
            unset( $rows );
        }

        $progress->finish();

        $this->log( 'videos', "SUMMARY thin: created=$created updated=$updated skipped=$skip failed=$fail (handled=$handled last-id=$after)" );
        WP_CLI::success( "Videos (thin): $created created, $updated updated, $fail failed. Last ID=$after. Meta hydrates on demand." );

        wp_suspend_cache_addition( false );
    }

    private function video_find_existing( int $django_id ): int {
        global $wpdb;

        // 1) Legacy columns lookup
        $found = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
              WHERE post_type = 'f365_video' AND legacy_table = 'multimedia_video' AND legacy_id = %d
              LIMIT 1",
            $django_id
        ) );
        if ( $found ) {
            return $found;
        }

        // 2) django_video_id postmeta lookup
        $found = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT pm.post_id
               FROM {$wpdb->postmeta} pm
               JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key   = 'django_video_id'
                AND pm.meta_value = %d
                AND p.post_type   = 'f365_video'
                AND p.post_status != 'trash'
              LIMIT 1",
            $django_id
        ) );
        if ( $found ) {
            return $found;
        }

        return 0;
    }

    private function src_table_exists( string $table ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }
}

class F365_Video_CLI_Command {
    /**
     * Sync metadata, relationships, and images for videos.
     *
     * ## OPTIONS
     *
     * [--after-id=<n>]
     * : Keyset starting ID (exclusive). Default: 0
     *
     * [--max-id=<n>]
     * : Keyset ending ID (inclusive). Default: 0
     *
     * [--limit=<n>]
     * : Maximum records to process.
     *
     * [--batch=<n>]
     * : Batch size. Default: 500
     *
     * [--force]
     * : Force re-hydration of metadata.
     *
     * [--rebuild-images]
     * : Force re-downloading and rebuilding featured images.
     *
     * ## EXAMPLES
     *
     *   wp futebol365 video sync-meta --after-id=0 --limit=5000
     */
    public function sync_meta( array $args, array $assoc ): void {
        global $wpdb;

        $after = isset( $assoc['after-id'] ) ? (int) $assoc['after-id'] : 0;
        $max_id = isset( $assoc['max-id'] ) ? (int) $assoc['max-id'] : 0;
        $limit = isset( $assoc['limit'] ) ? (int) $assoc['limit'] : PHP_INT_MAX;
        $batch = isset( $assoc['batch'] ) ? (int) $assoc['batch'] : 500;
        $force = isset( $assoc['force'] );
        $rebuild_images = isset( $assoc['rebuild-images'] );

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'f365_video'" );
        $cap = min( $total, $limit );
        WP_CLI::log( "Syncing video metadata: total=$total cap=$cap batch=$batch after-id=$after" . ( $max_id ? " max-id=$max_id" : '' ) );
        $progress = WP_CLI\Utils\make_progress_bar( 'Syncing videos', $cap );

        $handled = 0;
        $success = $fail = 0;

        while ( $handled < $limit ) {
            $where_max = $max_id > 0 ? $wpdb->prepare( ' AND legacy_id <= %d', $max_id ) : '';
            $posts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, legacy_id FROM {$wpdb->posts}
                      WHERE post_type = 'f365_video' AND legacy_table = 'multimedia_video' AND legacy_id > %d {$where_max}
                      ORDER BY legacy_id ASC
                      LIMIT %d",
                    $after,
                    $batch
                ),
                ARRAY_A
            );

            if ( empty( $posts ) ) {
                break;
            }

            foreach ( $posts as $p ) {
                if ( $handled >= $limit ) {
                    break;
                }
                $after = (int) $p['legacy_id'];
                $handled++;
                $progress->tick();

                $wp_id = (int) $p['ID'];

                if ( $force ) {
                    F365_Video_Lazy::rehydrate( $wp_id );
                    $success++;
                } else {
                    if ( $rebuild_images ) {
                        delete_post_meta( $wp_id, F365_Video_Lazy::FLAG_IMAGE );
                    }
                    $res = F365_Video_Lazy::hydrate( $wp_id );
                    if ( $res ) {
                        $success++;
                    } else {
                        $fail++;
                    }
                }
            }
            clean_post_cache( array_column( $posts, 'ID' ) );
            unset( $posts );
        }

        $progress->finish();
        WP_CLI::success( "Sync completed: $success processed, $fail failed. Last legacy_id=$after." );
    }
}
WP_CLI::add_command( 'futebol365 video', 'F365_Video_CLI_Command' );

endif; // end CLI-only logic.
