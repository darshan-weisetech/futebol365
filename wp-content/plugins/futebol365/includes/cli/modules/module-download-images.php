<?php
/**
 * Migration module: Multi-module Image Downloader (PostgreSQL-Free)
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'WP_CLI' ) && WP_CLI ) :

// trait F365_Module_Download_Images {

//     protected function download_images(): void {
//         global $wpdb;

//         // Prevent memory leaks during long-running WP-CLI scripts
//         wp_suspend_cache_addition( true );

//         $module = $this->assoc_args['module'] ?? '';
//         if ( empty( $module ) ) {
//             WP_CLI::error( "Missing --module parameter. Usage: wp f365 migrate download_images --module=<video|news|player|coach|president>" );
//             wp_suspend_cache_addition( false );
//             return;
//         }

//         $supported = [ 'video', 'news', 'player', 'coach', 'president' ];
//         if ( ! in_array( $module, $supported, true ) ) {
//             WP_CLI::error( sprintf( "Invalid module '%s'. Supported modules: %s", $module, implode( ', ', $supported ) ) );
//             wp_suspend_cache_addition( false );
//             return;
//         }

//         WP_CLI::log( sprintf( "Starting image downloads for module: %s | Limit: %s | Batch: %d | Force: %s", 
//             $module, 
//             $this->limit === PHP_INT_MAX ? 'none' : $this->limit, 
//             $this->batch,
//             $this->force ? 'yes' : 'no' 
//         ) );

//         switch ( $module ) {
//             case 'video':
//                 $this->download_video_images();
//                 break;
//             case 'news':
//                 $this->download_news_images();
//                 break;
//             case 'player':
//                 $this->download_player_images();
//                 break;
//             case 'coach':
//                 $this->download_coach_images();
//                 break;
//             case 'president':
//                 $this->download_president_images();
//                 break;
//         }

//         wp_suspend_cache_addition( false );
//     }

//     private function download_video_images(): void {
//         global $wpdb;

//         $T_VIDEO = $wpdb->prefix . 'multimedia_video';
//         if ( ! $this->download_images_table_exists( $T_VIDEO ) ) {
//             WP_CLI::error( "Source table missing: ensure {$T_VIDEO} is imported." );
//             return;
//         }

//         $after = (int) ( $this->assoc_args['after-id'] ?? $this->offset );
//         $processed = 0;
//         $downloaded = 0;
//         $skipped = 0;
//         $failed = 0;

//         $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'f365_video' AND post_status != 'trash'" );
//         $cap = min( $total, $this->limit );
//         $progress = WP_CLI\Utils\make_progress_bar( 'Video Images', $cap );

//         while ( $processed < $cap ) {
//             $batch_limit = min( $this->batch, $cap - $processed );
//             if ( $batch_limit <= 0 ) {
//                 break;
//             }

//             $posts = $wpdb->get_results( $wpdb->prepare(
//                 "SELECT ID FROM {$wpdb->posts}
//                   WHERE post_type = 'f365_video' AND post_status != 'trash' AND ID > %d
//                   ORDER BY ID ASC
//                   LIMIT %d",
//                 $after,
//                 $batch_limit
//             ), ARRAY_A );

//             if ( empty( $posts ) ) {
//                 break;
//             }

//             foreach ( $posts as $p ) {
//                 $post_id = (int) $p['ID'];
//                 $after = $post_id;
//                 $processed++;
//                 $progress->tick();

//                 $has_thumb = has_post_thumbnail( $post_id );
//                 if ( $has_thumb && ! $this->force ) {
//                     $skipped++;
//                     continue;
//                 }

//                 $django_id = (int) $this->get_meta_sql( $post_id, 'django_video_id' );
//                 if ( ! $django_id ) {
//                     $django_id = (int) $wpdb->get_var( $wpdb->prepare(
//                         "SELECT legacy_id FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'f365_video' LIMIT 1",
//                         $post_id
//                     ) );
//                 }

//                 if ( ! $django_id ) {
//                     $failed++;
//                     $this->log( 'download_images_video', "FAIL: No legacy/django ID for post_id={$post_id}" );
//                     continue;
//                 }

//                 $image_path = $wpdb->get_var( $wpdb->prepare(
//                     "SELECT image FROM {$T_VIDEO} WHERE id = %d LIMIT 1",
//                     $django_id
//                 ) );

//                 if ( empty( $image_path ) || strpos( (string) $image_path, 'no-image' ) !== false ) {
//                     $skipped++;
//                     continue;
//                 }

//                 $url = $this->media_url( $image_path );
//                 $title = get_the_title( $post_id );

//                 $att_id = $this->sideload_image( $url, $post_id, $title, 'download_images_video' );
//                 if ( $att_id ) {
//                     set_post_thumbnail( $post_id, $att_id );
//                     $downloaded++;
//                 } else {
//                     $failed++;
//                 }
//             }

//             clean_post_cache( array_column( $posts, 'ID' ) );
//             unset( $posts );
//         }

//         $progress->finish();
//         WP_CLI::success( "Video Images: processed={$processed}, downloaded={$downloaded}, skipped={$skipped}, failed={$failed}" );
//         $this->log( 'download_images_video', "SUMMARY: processed={$processed}, downloaded={$downloaded}, skipped={$skipped}, failed={$failed}" );
//     }

//     private function download_news_images(): void {
//         global $wpdb;

//         $after = (int) ( $this->assoc_args['after-id'] ?? $this->offset );
//         $processed = 0;
//         $downloaded = 0;
//         $skipped = 0;
//         $failed = 0;

//         $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'django_news_id'" );
//         $cap = min( $total, $this->limit );
//         $progress = WP_CLI\Utils\make_progress_bar( 'News Images', $cap );

//         while ( $processed < $cap ) {
//             $batch_limit = min( $this->batch, $cap - $processed );
//             if ( $batch_limit <= 0 ) {
//                 break;
//             }

//             $posts = $wpdb->get_results( $wpdb->prepare(
//                 "SELECT post_id AS ID FROM {$wpdb->postmeta}
//                   WHERE meta_key = 'django_news_id' AND post_id > %d
//                   ORDER BY post_id ASC
//                   LIMIT %d",
//                 $after,
//                 $batch_limit
//             ), ARRAY_A );

//             if ( empty( $posts ) ) {
//                 break;
//             }

//             foreach ( $posts as $p ) {
//                 $post_id = (int) $p['ID'];
//                 $after = $post_id;
//                 $processed++;
//                 $progress->tick();

//                 $has_thumb = has_post_thumbnail( $post_id );
//                 if ( $has_thumb && ! $this->force ) {
//                     $skipped++;
//                     continue;
//                 }

//                 $django_news_id = (int) $this->get_meta_sql( $post_id, 'django_news_id' );
//                 if ( ! $django_news_id ) {
//                     $django_news_id = (int) $wpdb->get_var( $wpdb->prepare(
//                         "SELECT legacy_id FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'post' LIMIT 1",
//                         $post_id
//                     ) );
//                 }

//                 if ( ! $django_news_id ) {
//                     $failed++;
//                     $this->log( 'download_images_news', "FAIL: No legacy/django ID for post_id={$post_id}" );
//                     continue;
//                 }

//                 $image_path = '';
//                 $T_NEWS = $wpdb->prefix . 'publish_news';
//                 if ( $this->download_images_table_exists( $T_NEWS ) ) {
//                     $image_path = $wpdb->get_var( $wpdb->prepare(
//                         "SELECT image FROM {$T_NEWS} WHERE id = %d LIMIT 1",
//                         $django_news_id
//                     ) );
//                 }

//                 if ( empty( $image_path ) || strpos( $image_path, '365-no-photo.jpg' ) !== false || strpos( $image_path, 'no-image' ) !== false ) {
//                     $skipped++;
//                     continue;
//                 }

//                 $url = $this->media_url( $image_path );
//                 $title = get_the_title( $post_id );

//                 $att_id = $this->sideload_image( $url, $post_id, $title, 'download_images_news' );
//                 if ( $att_id ) {
//                     set_post_thumbnail( $post_id, $att_id );
//                     $downloaded++;
//                 } else {
//                     $failed++;
//                 }
//             }

//             clean_post_cache( array_column( $posts, 'ID' ) );
//             unset( $posts );
//         }

//         $progress->finish();
//         WP_CLI::success( "News Images: processed={$processed}, downloaded={$downloaded}, skipped={$skipped}, failed={$failed}" );
//         $this->log( 'download_images_news', "SUMMARY: processed={$processed}, downloaded={$downloaded}, skipped={$skipped}, failed={$failed}" );
//     }

//     private function download_player_images(): void {
//         global $wpdb;

//         $after = (int) ( $this->assoc_args['after-id'] ?? $this->offset );
//         $processed = 0;
//         $downloaded = 0;
//         $skipped = 0;
//         $failed = 0;

//         $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'f365_player' AND post_status != 'trash'" );
//         $cap = min( $total, $this->limit );
//         $progress = WP_CLI\Utils\make_progress_bar( 'Player Images', $cap );

//         while ( $processed < $cap ) {
//             $batch_limit = min( $this->batch, $cap - $processed );
//             if ( $batch_limit <= 0 ) {
//                 break;
//             }

//             $posts = $wpdb->get_results( $wpdb->prepare(
//                 "SELECT ID FROM {$wpdb->posts}
//                   WHERE post_type = 'f365_player' AND post_status != 'trash' AND ID > %d
//                   ORDER BY ID ASC
//                   LIMIT %d",
//                 $after,
//                 $batch_limit
//             ), ARRAY_A );

//             if ( empty( $posts ) ) {
//                 break;
//             }

//             foreach ( $posts as $p ) {
//                 $post_id = (int) $p['ID'];
//                 $after = $post_id;
//                 $processed++;
//                 $progress->tick();

//                 $has_thumb = has_post_thumbnail( $post_id );
//                 if ( $has_thumb && ! $this->force ) {
//                     $skipped++;
//                     continue;
//                 }

//                 $person_id = (int) $this->get_meta_sql( $post_id, 'django_person_id' );
//                 if ( ! $person_id ) {
//                     $failed++;
//                     $this->log( 'download_images_player', "FAIL: No django_person_id for post_id={$post_id}" );
//                     continue;
//                 }

//                 $base_url = rtrim( getenv( 'DJANGO_MEDIA_URL' ) ?: 'https://media.futebol365.pt', '/' );
//                 $url = $base_url . '/images/person/' . $person_id . '.jpg';
//                 $title = (string) $this->get_meta_sql( $post_id, 'shortname' );
//                 if ( empty( $title ) ) {
//                     $title = get_the_title( $post_id );
//                 }

//                 $att_id = $this->sideload_image( $url, $post_id, $title, 'download_images_player' );
//                 if ( $att_id ) {
//                     set_post_thumbnail( $post_id, $att_id );
//                     $downloaded++;
//                 } else {
//                     $failed++;
//                 }
//             }

//             clean_post_cache( array_column( $posts, 'ID' ) );
//             unset( $posts );
//         }

//         $progress->finish();
//         WP_CLI::success( "Player Images: processed={$processed}, downloaded={$downloaded}, skipped={$skipped}, failed={$failed}" );
//         $this->log( 'download_images_player', "SUMMARY: processed={$processed}, downloaded={$downloaded}, skipped={$skipped}, failed={$failed}" );
//     }

//     private function download_coach_images(): void {
//         global $wpdb;

//         $after = (int) ( $this->assoc_args['after-id'] ?? $this->offset );
//         $processed = 0;
//         $downloaded = 0;
//         $skipped = 0;
//         $failed = 0;

//         $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'f365_coach' AND post_status != 'trash'" );
//         $cap = min( $total, $this->limit );
//         $progress = WP_CLI\Utils\make_progress_bar( 'Coach Images', $cap );

//         while ( $processed < $cap ) {
//             $batch_limit = min( $this->batch, $cap - $processed );
//             if ( $batch_limit <= 0 ) {
//                 break;
//             }

//             $posts = $wpdb->get_results( $wpdb->prepare(
//                 "SELECT ID FROM {$wpdb->posts}
//                   WHERE post_type = 'f365_coach' AND post_status != 'trash' AND ID > %d
//                   ORDER BY ID ASC
//                   LIMIT %d",
//                 $after,
//                 $batch_limit
//             ), ARRAY_A );

//             if ( empty( $posts ) ) {
//                 break;
//             }

//             foreach ( $posts as $p ) {
//                 $post_id = (int) $p['ID'];
//                 $after = $post_id;
//                 $processed++;
//                 $progress->tick();

//                 $has_thumb = has_post_thumbnail( $post_id );
//                 if ( $has_thumb && ! $this->force ) {
//                     $skipped++;
//                     continue;
//                 }

//                 $person_id = (int) $this->get_meta_sql( $post_id, 'django_person_id' );
//                 if ( ! $person_id ) {
//                     $failed++;
//                     $this->log( 'download_images_coach', "FAIL: No django_person_id for post_id={$post_id}" );
//                     continue;
//                 }

//                 $base_url = rtrim( getenv( 'DJANGO_MEDIA_URL' ) ?: 'https://media.futebol365.pt', '/' );
//                 $url = $base_url . '/images/person/' . $person_id . '_coach.jpg';
//                 $title = (string) $this->get_meta_sql( $post_id, 'shortname' );
//                 if ( empty( $title ) ) {
//                     $title = get_the_title( $post_id );
//                 }

//                 $att_id = $this->sideload_image( $url, $post_id, $title, 'download_images_coach' );
//                 if ( $att_id ) {
//                     set_post_thumbnail( $post_id, $att_id );
//                     $downloaded++;
//                 } else {
//                     $failed++;
//                 }
//             }

//             clean_post_cache( array_column( $posts, 'ID' ) );
//             unset( $posts );
//         }

//         $progress->finish();
//         WP_CLI::success( "Coach Images: processed={$processed}, downloaded={$downloaded}, skipped={$skipped}, failed={$failed}" );
//         $this->log( 'download_images_coach', "SUMMARY: processed={$processed}, downloaded={$downloaded}, skipped={$skipped}, failed={$failed}" );
//     }

//     private function download_president_images(): void {
//         global $wpdb;

//         $after = (int) ( $this->assoc_args['after-id'] ?? $this->offset );
//         $processed = 0;
//         $downloaded = 0;
//         $skipped = 0;
//         $failed = 0;

//         $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'f365_president' AND post_status != 'trash'" );
//         $cap = min( $total, $this->limit );
//         $progress = WP_CLI\Utils\make_progress_bar( 'President Images', $cap );

//         while ( $processed < $cap ) {
//             $batch_limit = min( $this->batch, $cap - $processed );
//             if ( $batch_limit <= 0 ) {
//                 break;
//             }

//             $posts = $wpdb->get_results( $wpdb->prepare(
//                 "SELECT ID FROM {$wpdb->posts}
//                   WHERE post_type = 'f365_president' AND post_status != 'trash' AND ID > %d
//                   ORDER BY ID ASC
//                   LIMIT %d",
//                 $after,
//                 $batch_limit
//             ), ARRAY_A );

//             if ( empty( $posts ) ) {
//                 break;
//             }

//             foreach ( $posts as $p ) {
//                 $post_id = (int) $p['ID'];
//                 $after = $post_id;
//                 $processed++;
//                 $progress->tick();

//                 $has_thumb = has_post_thumbnail( $post_id );
//                 if ( $has_thumb && ! $this->force ) {
//                     $skipped++;
//                     continue;
//                 }

//                 $person_id = (int) $this->get_meta_sql( $post_id, 'django_person_id' );
//                 if ( ! $person_id ) {
//                     $failed++;
//                     $this->log( 'download_images_president', "FAIL: No django_person_id for post_id={$post_id}" );
//                     continue;
//                 }

//                 $base_url = rtrim( getenv( 'DJANGO_MEDIA_URL' ) ?: 'https://media.futebol365.pt', '/' );
//                 $url = $base_url . '/images/person/' . $person_id . '_president.jpg';
//                 $title = (string) $this->get_meta_sql( $post_id, 'shortname' );
//                 if ( empty( $title ) ) {
//                     $title = get_the_title( $post_id );
//                 }

//                 $att_id = $this->sideload_image( $url, $post_id, $title, 'download_images_president' );
//                 if ( $att_id ) {
//                     set_post_thumbnail( $post_id, $att_id );
//                     $downloaded++;
//                 } else {
//                     $failed++;
//                 }
//             }

//             clean_post_cache( array_column( $posts, 'ID' ) );
//             unset( $posts );
//         }

//         $progress->finish();
//         WP_CLI::success( "President Images: processed={$processed}, downloaded={$downloaded}, skipped={$skipped}, failed={$failed}" );
//         $this->log( 'download_images_president', "SUMMARY: processed={$processed}, downloaded={$downloaded}, skipped={$skipped}, failed={$failed}" );
//     }

//     private function download_images_table_exists( string $table ): bool {
//         global $wpdb;
//         return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
//     }

//     private function get_meta_sql( int $post_id, string $meta_key ): string {
//         global $wpdb;
//         return (string) $wpdb->get_var( $wpdb->prepare(
//             "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
//             $post_id,
//             $meta_key
//         ) );
//     }
// }


trait F365_Module_Download_Images {

    protected function download_news_images_standalone(): void {
        global $wpdb;

        // Prevent memory leaks during long-running WP-CLI scripts
        wp_suspend_cache_addition( true );

        $T_NEWS = $wpdb->prefix . 'publish_news';
        if ( ! $this->download_images_table_exists( $T_NEWS ) ) {
            WP_CLI::error( "Source table missing: ensure {$T_NEWS} is imported." );
            wp_suspend_cache_addition( false );
            return;
        }

        // Explicitly grab the base offset from arguments or fallback property
        $base_offset    = (int) ( $this->assoc_args['offset'] ?? $this->offset );
        $worker_limit   = $this->limit === PHP_INT_MAX ? 0 : (int) $this->limit;
        
        WP_CLI::log( sprintf( "Starting parallel-ready image sync (DESCENDING ORDER) | Worker Limit: %s | Base Offset: %d | Batch Size: %d", 
            $worker_limit === 0 ? 'none' : $worker_limit, 
            $base_offset,
            $this->batch
        ) );

        $processed  = 0;
        $downloaded = 0;
        $skipped    = 0;
        $failed     = 0;

        /**
         * Count all items matching the target metadata criteria for updates.
         * Filtered by post_type = 'post' and excluding placeholder files.
         */
        $total = (int) $wpdb->get_var( "
            SELECT COUNT(*) 
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            INNER JOIN {$T_NEWS} tn ON CAST(pm.meta_value AS UNSIGNED) = tn.id
            WHERE pm.meta_key = 'django_news_id' 
              AND p.post_type = 'post'
              AND tn.image NOT LIKE '%futebol365-no-photo.jpg'" 
        );
        
        // Compute maximum items this specific worker instance is allowed to process
        $remaining_in_db = $total - $base_offset;
        $cap = ($worker_limit > 0) ? min( $remaining_in_db, $worker_limit ) : $remaining_in_db;

        if ( $cap <= 0 ) {
            WP_CLI::warning( "No records found within the requested offset and limit." );
            wp_suspend_cache_addition( false );
            return;
        }

        $progress = WP_CLI\Utils\make_progress_bar( 'News Images', $cap );

        while ( $processed < $cap ) {
            // Determine size of the immediate chunk
            $batch_limit = min( $this->batch, $cap - $processed );
            if ( $batch_limit <= 0 ) {
                break;
            }
            
            /**
             * Pure Offset Pagination Required for Parallel Processing.
             * * Dynamically increments the global pointer: $base_offset + $processed.
             * This separates chunks statically, allowing Workers to process distinct segments safely.
             */
            $dynamic_offset = $base_offset + $processed;

            $posts = $wpdb->get_results( $wpdb->prepare(
                "SELECT pm.post_id AS ID, pm.meta_value AS django_id, tn.image AS raw_image
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 INNER JOIN {$T_NEWS} tn ON CAST(pm.meta_value AS UNSIGNED) = tn.id
                 WHERE pm.meta_key = 'django_news_id'
                   AND p.post_type = 'post'
                   AND tn.image NOT LIKE '%%futebol365-no-photo.jpg'
                 ORDER BY CAST(pm.meta_value AS UNSIGNED) DESC
                 LIMIT %d OFFSET %d",
                $batch_limit,
                $dynamic_offset
            ), ARRAY_A );

            if ( empty( $posts ) ) {
                break;
            }

           foreach ( $posts as $p ) {
            $post_id        = (int) $p['ID'];
            $django_news_id = (int) $p['django_id'];
            $image_url      = trim( (string) $p['raw_image'] );

            $this->log( 'download_images_news_isolated', '----------------------');
            // FIXED: Added 'download_images_news_isolated' as the first parameter
            $this->log( 'download_images_news_isolated', "Processing post_id={$post_id} | Django ID={$django_news_id}");

            $processed++;
            $progress->tick();

            // Fallback validation for any other local inline patterns
            if ( empty( $image_url ) 
                || strpos( $image_url, '365-no-photo.jpg' ) !== false 
                || strpos( $image_url, 'no-image' ) !== false 
                || strpos( $image_url, 'futebol365-no-photo.jpg' ) !== false 
            ) {
                // FIXED: Added 'download_images_news_isolated'
                $this->log( 'download_images_news_isolated', "   -> Skipped: Invalid or empty image identifier: {$image_url}" );
                $skipped++;
                continue;
            }

            // Skip if the post already has a featured image assigned
            if ( get_post_thumbnail_id( $post_id ) ) {
                // FIXED: Added 'download_images_news_isolated'
                $this->log( 'download_images_news_isolated', "   -> Skipped: Post ID {$post_id} already has a featured image assigned." );
                $skipped++;
                continue;
            }

            // Check if absolute URL is missing 'http' or 'https' and normalize it
            if ( strpos( $image_url, 'http://' ) !== 0 && strpos( $image_url, 'https://' ) !== 0 ) {
                $image_url = 'https://www.futebol365.pt/' . ltrim( $image_url, '/' );
            }

            $title = get_the_title( $post_id );

            /**
             * Fresh Sideload
             */
            $this->log( 'download_images_news_isolated', sprintf(
                "IMAGE SYNC (FRESH DOWNLOAD): Starting sideload for post_id=%d (Django ID: %d) | URL: %s",
                $post_id, $django_news_id, $image_url
            ) );

            $att_id = $this->sideload_image( $image_url, $post_id, $title, 'download_images_news_isolated' );
            
            if ( $att_id ) {
                set_post_thumbnail( $post_id, $att_id );
                $downloaded++;
            } else {
                $failed++;
                $this->log( 'download_images_news_isolated', sprintf(
                    "FAIL: Sideload failed for post_id=%d (Django ID: %d) | URL: %s",
                    $post_id, $django_news_id, $image_url
                ) );
            }
            $this->log( 'download_images_news_isolated', '----------------------');
        }

            clean_post_cache( array_column( $posts, 'ID' ) );
            unset( $posts );
        }

        $progress->finish();
        WP_CLI::success( "News Images Standalone: processed={$processed}, downloaded={$downloaded}, skipped={$skipped}, failed={$failed}" );
        $this->log( 'download_images_news_isolated', "SUMMARY: processed={$processed}, downloaded={$downloaded}, skipped={$skipped}, failed={$failed}" );

        wp_suspend_cache_addition( false );
    }

    private function download_images_table_exists( string $table ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }
}

trait F365_Module_Download_Video_Images {

    protected function download_video_images_standalone(): void {
        global $wpdb;

        // Prevent memory leaks during long-running WP-CLI scripts
        wp_suspend_cache_addition( true );

        $T_VIDEOS = $wpdb->prefix . 'multimedia_video';
        if ( ! $this->download_images_table_exists( $T_VIDEOS ) ) {
            WP_CLI::error( "Source table missing: ensure {$T_VIDEOS} is imported." );
            wp_suspend_cache_addition( false );
            return;
        }

        // Explicitly grab the base offset from arguments or fallback property
        $base_offset    = (int) ( $this->assoc_args['offset'] ?? $this->offset );
        $worker_limit   = $this->limit === PHP_INT_MAX ? 0 : (int) $this->limit;
        
        WP_CLI::log( sprintf( "Starting parallel-ready video image sync (DESCENDING ORDER) | Worker Limit: %s | Base Offset: %d | Batch Size: %d", 
            $worker_limit === 0 ? 'none' : $worker_limit, 
            $base_offset,
            $this->batch
        ) );

        $processed  = 0;
        $downloaded = 0;
        $skipped    = 0;
        $failed     = 0;

        /**
         * COUNT QUERY (Highly Optimized)
         * We split this into two lightning-fast indexed queries and combine them,
         * avoiding the massive structural table scan of the OR/EXISTS condition.
         */
        $total_legacy = (int) $wpdb->get_var( "
            SELECT COUNT(p.ID) 
            FROM {$wpdb->posts} p
            INNER JOIN {$T_VIDEOS} tv ON p.legacy_id = tv.id
            WHERE p.post_type = 'f365_video'
              AND tv.image NOT LIKE '%futebol365-no-photo.jpg'" 
        );

        $total_meta = (int) $wpdb->get_var( "
            SELECT COUNT(p.ID) 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'django_video_id'
            INNER JOIN {$T_VIDEOS} tv ON pm.meta_value = tv.id
            WHERE p.post_type = 'f365_video'
              AND p.legacy_id IS NULL
              AND tv.image NOT LIKE '%futebol365-no-photo.jpg'" 
        );

        $total = $total_legacy + $total_meta;
        
        // Compute maximum items this specific worker instance is allowed to process
        $remaining_in_db = $total - $base_offset;
        $cap = ($worker_limit > 0) ? min( $remaining_in_db, $worker_limit ) : $remaining_in_db;

        if ( $cap <= 0 ) {
            WP_CLI::warning( "No records found within the requested offset and limit." );
            wp_suspend_cache_addition( false );
            return;
        }

        $progress = WP_CLI\Utils\make_progress_bar( 'Video Images', $cap );

        while ( $processed < $cap ) {
            // Determine size of the immediate chunk
            $batch_limit = min( $this->batch, $cap - $processed );
            if ( $batch_limit <= 0 ) {
                break;
            }
            
            $dynamic_offset = $base_offset + $processed;

            /**
             * BATCH SELECTION QUERY (Flattened & Index Optimized)
             * Uses a clean UNION ALL approach to separate logic. MySQL handles this 
             * much faster than evaluated `OR` joins, executing deep offsets instantly.
             */
            $posts = $wpdb->get_results( $wpdb->prepare(
                "(SELECT 
                    p.ID, 
                    p.legacy_id AS django_id, 
                    tv.image AS raw_image
                 FROM {$wpdb->posts} p
                 INNER JOIN {$T_VIDEOS} tv ON p.legacy_id = tv.id
                 WHERE p.post_type = 'f365_video'
                   AND tv.image NOT LIKE '%%futebol365-no-photo.jpg')
                 
                 UNION ALL
                 
                 (SELECT 
                    p.ID, 
                    pm.meta_value AS django_id, 
                    tv.image AS raw_image
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'django_video_id'
                 INNER JOIN {$T_VIDEOS} tv ON pm.meta_value = tv.id
                 WHERE p.post_type = 'f365_video'
                   AND p.legacy_id IS NULL
                   AND tv.image NOT LIKE '%%futebol365-no-photo.jpg')
                 
                 ORDER BY ID DESC
                 LIMIT %d OFFSET %d",
                $batch_limit,
                $dynamic_offset
            ), ARRAY_A );

            if ( empty( $posts ) ) {
                break;
            }

            // Warm up object caches for the entire batch to optimize `get_the_title` and thumbnails
            $post_ids = array_map( 'intval', array_column( $posts, 'ID' ) );
            $this->_mood_warm_up_caches( $post_ids ); 

            foreach ( $posts as $p ) {
                $post_id         = (int) $p['ID'];
                $django_video_id = (int) $p['django_id'];
                $image_url       = trim( (string) $p['raw_image'] );

                $this->log( 'download_images_video_isolated', '----------------------');
                $this->log( 'download_images_video_isolated', "Processing post_id={$post_id} | Django Video ID/Legacy ID={$django_video_id}");

                $processed++;
                $progress->tick();

                // Fallback validation for any other local inline patterns
                if ( empty( $image_url ) 
                    || strpos( $image_url, '365-no-photo.jpg' ) !== false 
                    || strpos( $image_url, 'no-image' ) !== false 
                    || strpos( $image_url, 'futebol365-no-photo.jpg' ) !== false 
                ) {
                    $this->log( 'download_images_video_isolated', "   -> Skipped: Invalid or empty image identifier: {$image_url}" );
                    $skipped++;
                    continue;
                }

                // Skip if the post already has a featured image assigned
                if ( get_post_thumbnail_id( $post_id ) ) {
                    $this->log( 'download_images_video_isolated', "   -> Skipped: Post ID {$post_id} already has a featured image assigned." );
                    $skipped++;
                    continue;
                }

                // Check if absolute URL is missing 'http' or 'https' and normalize it
                if ( strpos( $image_url, 'http://' ) !== 0 && strpos( $image_url, 'https://' ) !== 0 ) {
                    $image_url = 'https://www.futebol365.pt/' . ltrim( $image_url, '/' );
                }

                $title = get_the_title( $post_id );

                /**
                 * Fresh Sideload
                 */
                $this->log( 'download_images_video_isolated', sprintf(
                    "IMAGE SYNC (FRESH DOWNLOAD): Starting sideload for post_id=%d (Django Video/Legacy ID: %d) | URL: %s",
                    $post_id, $django_video_id, $image_url
                ) );

                $att_id = $this->sideload_image( $image_url, $post_id, $title, 'download_images_video_isolated' );
                
                if ( $att_id ) {
                    set_post_thumbnail( $post_id, $att_id );
                    $downloaded++;
                } else {
                    $failed++;
                    $this->log( 'download_images_video_isolated', sprintf(
                        "FAIL: Sideload failed for post_id=%d (Django Video ID: %d) | URL: %s",
                        $post_id, $django_video_id, $image_url
                    ) );
                }
                $this->log( 'download_images_video_isolated', '----------------------');
            }

            clean_post_cache( $post_ids );
            unset( $posts, $post_ids );
        }

        $progress->finish();
        WP_CLI::success( "Video Images Standalone: processed={$processed}, downloaded={$downloaded}, skipped={$skipped}, failed={$failed}" );
        $this->log( 'download_images_video_isolated', "SUMMARY: processed={$processed}, downloaded={$downloaded}, skipped={$skipped}, failed={$failed}" );

        wp_suspend_cache_addition( false );
    }

    /**
     * Helper function to prime cache for bulk entities
     */
    private function _mood_warm_up_caches( array $post_ids ): void {
        _prime_post_caches( $post_ids, true, true );
    }
}

trait F365_Module_Download_Player_Images {
    private function download_player_images(): void {
        global $wpdb;

        // 1. Capture the initial offset and limit passed to the command
        $offset = (int) ( $this->assoc_args['offset'] ?? $this->offset ?? 0 );
        $limit  = (int) ( $this->assoc_args['limit'] ?? $this->limit ?? 100 );

        if ( $limit <= 0 ) {
            WP_CLI::line( "Limit must be greater than 0." );
            return;
        }

        // 2. Consolidated query fetching ID, django_person_id, and shortname
        // CHANGED: Ordered by CAST(m1.meta_value AS UNSIGNED) DESC to sort numerically by django_person_id
        $players = $wpdb->get_results( $wpdb->prepare(
            "SELECT 
                p.ID,
                m1.meta_value AS django_person_id,
                m2.meta_value AS shortname,
                p.post_title
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m1 ON (p.ID = m1.post_id AND m1.meta_key = 'django_person_id')
             LEFT JOIN {$wpdb->postmeta} m2 ON (p.ID = m2.post_id AND m2.meta_key = 'shortname')
             WHERE p.post_type = 'f365_player' 
               AND p.post_status != 'trash'
             ORDER BY CAST(m1.meta_value AS UNSIGNED) DESC
             LIMIT %d, %d",
            $offset,
            $limit
        ), ARRAY_A );

        $cap = count( $players );

        if ( $cap === 0 ) {
            WP_CLI::line( "No players found within the specified offset ({$offset}) and limit ({$limit})." );
            return;
        }

        $processed  = 0;
        $downloaded = 0;
        $skipped    = 0;
        $failed     = 0;

        $progress = WP_CLI\Utils\make_progress_bar( 'Player Images', $cap );
        $base_url = rtrim( getenv( 'DJANGO_MEDIA_URL' ) ?: 'https://media.futebol365.pt', '/' );

        // 3. Process the pre-fetched dataset in memory-efficient chunks
        $chunks = array_chunk( $players, (int) ( $this->batch ?? 50 ) );
        unset( $players ); // Free memory immediately

        foreach ( $chunks as $batch ) {
            $batch_post_ids = [];

            foreach ( $batch as $player ) {
                $post_id = (int) $player['ID'];
                $batch_post_ids[] = $post_id;
                
                $processed++;
                $progress->tick();

                // 4. Skip if thumbnail exists (and force is not set)
                // CHANGED: Added success log indicating the featured image already exists
                if ( ! $this->force && has_post_thumbnail( $post_id ) ) {
                    $skipped++;
                    $this->log( 'download_images_player', "SKIP: Featured image already exists for post_id={$post_id}" );
                    continue;
                }

                // 5. Read data pulled directly from the JOIN query
                $person_id = (int) $player['django_person_id'];
                if ( ! $person_id ) {
                    $failed++;
                    $this->log( 'download_images_player', "FAIL: No django_person_id for post_id={$post_id}" );
                    continue;
                }

                $url   = $base_url . '/images/person/' . $person_id . '.jpg';
                $title = ! empty( $player['shortname'] ) ? (string) $player['shortname'] : (string) $player['post_title'];

                $att_id = $this->sideload_image( $url, $post_id, $title, 'download_images_player' );
                if ( $att_id ) {
                    set_post_thumbnail( $post_id, $att_id );
                    $downloaded++;
                    // CHANGED: Added success log for successful download and attachment attachment
                    $this->log( 'download_images_player', "SUCCESS: Downloaded image and set thumbnail for post_id={$post_id} (Attachment ID: {$att_id})" );
                } else {
                    $failed++;
                    // Note: sideload_image likely logs its own failure, but added here for tracking
                    $this->log( 'download_images_player', "FAIL: Sideload failed for post_id={$post_id} URL={$url}" );
                }
            }

            // 6. Hard memory cleanup for WP CLI processes
            clean_post_cache( $batch_post_ids );
            wp_cache_flush(); 
        }

        $progress->finish();
        
        $summary = "processed={$processed}, downloaded={$downloaded}, skipped={$skipped}, failed={$failed}";
        WP_CLI::success( "Player Images: " . $summary );
        $this->log( 'download_images_player', "SUMMARY: " . $summary );
    }
}

trait F365_Module_Download_Coach_Images {
    private function download_coach_images(): void {
        global $wpdb;

        // 1. Capture the initial offset and limit passed to the command
        $offset = (int) ( $this->assoc_args['offset'] ?? $this->offset ?? 0 );
        $limit  = (int) ( $this->assoc_args['limit'] ?? $this->limit ?? 100 );

        if ( $limit <= 0 ) {
            WP_CLI::line( "Limit must be greater than 0." );
            return;
        }

        // 2. Consolidated query fetching ID, django_person_id, and shortname
        $coaches = $wpdb->get_results( $wpdb->prepare(
            "SELECT 
                p.ID,
                m1.meta_value AS django_person_id,
                m2.meta_value AS shortname,
                p.post_title
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m1 ON (p.ID = m1.post_id AND m1.meta_key = 'django_person_id')
             LEFT JOIN {$wpdb->postmeta} m2 ON (p.ID = m2.post_id AND m2.meta_key = 'shortname')
             WHERE p.post_type = 'f365_coach' 
               AND p.post_status != 'trash'
             ORDER BY CAST(m1.meta_value AS UNSIGNED) DESC
             LIMIT %d, %d",
            $offset,
            $limit
        ), ARRAY_A );

        $cap = count( $coaches );

        if ( $cap === 0 ) {
            WP_CLI::line( "No coaches found within the specified offset ({$offset}) and limit ({$limit})." );
            return;
        }

        $processed  = 0;
        $downloaded = 0;
        $skipped    = 0;
        $failed     = 0;

        // NOTE: If you are logging EVERY step via $this->log(), the progress bar will break visually.
        // Consider removing the progress bar if $this->log() outputs directly to the screen.
        $progress = WP_CLI\Utils\make_progress_bar( 'Coach Images', $cap );
        $base_url = rtrim( getenv( 'DJANGO_MEDIA_URL' ) ?: 'https://media.futebol365.pt', '/' );

        // 3. Process the pre-fetched dataset in memory-efficient chunks
        $chunks = array_chunk( $coaches, (int) ( $this->batch ?? 50 ) );
        unset( $coaches ); // Free memory immediately

        foreach ( $chunks as $batch ) {
            $batch_post_ids = [];

            foreach ( $batch as $coach ) {
                $post_id = (int) $coach['ID'];
                $batch_post_ids[] = $post_id;
                $processed++;

                // 4. Skip if thumbnail exists (and force is not set)
                if ( ! $this->force && has_post_thumbnail( $post_id ) ) {
                    $skipped++;
                    $this->log( 'download_images_coach', "SKIP: Featured image already exists for post_id={$post_id}" );
                    $progress->tick(); // Tick right before continuing to keep accuracy
                    continue;
                }

                // 5. Read data pulled directly from the JOIN query
                $person_id = (int) $coach['django_person_id'];
                if ( ! $person_id ) {
                    $failed++;
                    $this->log( 'download_images_coach', "FAIL: No django_person_id for post_id={$post_id}" );
                    $progress->tick(); 
                    continue;
                }

                $url   = $base_url . '/images/person/' . $person_id . '_coach.jpg';
                $title = ! empty( $coach['shortname'] ) ? (string) $coach['shortname'] : (string) $coach['post_title'];

                $att_id = $this->sideload_image( $url, $post_id, $title, 'download_images_coach' );
                if ( $att_id ) {
                    set_post_thumbnail( $post_id, $att_id );
                    $downloaded++;
                    $this->log( 'download_images_coach', "SUCCESS: Downloaded image and set thumbnail for post_id={$post_id} (Attachment ID: {$att_id})" );
                } else {
                    $failed++;
                    $this->log( 'download_images_coach', "FAIL: Sideload failed for post_id={$post_id} URL={$url}" );
                }

                $progress->tick();
            }

            // 6. Hard memory cleanup per batch
            clean_post_cache( $batch_post_ids );
            wp_cache_flush(); 
        }

        $progress->finish();
        
        $summary = "processed={$processed}, downloaded={$downloaded}, skipped={$skipped}, failed={$failed}";
        WP_CLI::success( "Coach Images: " . $summary );
        $this->log( 'download_images_coach', "SUMMARY: " . $summary );
    }
}

trait F365_Module_Download_President_Images {
    private function download_president_images(): void {
        global $wpdb;

        // 1. Capture the initial offset and limit passed to the command
        $offset = (int) ( $this->assoc_args['offset'] ?? $this->offset ?? 0 );
        $limit  = (int) ( $this->assoc_args['limit'] ?? $this->limit ?? 100 );

        if ( $limit <= 0 ) {
            WP_CLI::line( "Limit must be greater than 0." );
            return;
        }

        // 2. Consolidated query fetching ID, django_person_id, and shortname
        $presidents = $wpdb->get_results( $wpdb->prepare(
            "SELECT 
                p.ID,
                m1.meta_value AS django_person_id,
                m2.meta_value AS shortname,
                p.post_title
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m1 ON (p.ID = m1.post_id AND m1.meta_key = 'django_person_id')
             LEFT JOIN {$wpdb->postmeta} m2 ON (p.ID = m2.post_id AND m2.meta_key = 'shortname')
             WHERE p.post_type = 'f365_president' 
               AND p.post_status != 'trash'
             ORDER BY CAST(m1.meta_value AS UNSIGNED) DESC
             LIMIT %d, %d",
            $offset,
            $limit
        ), ARRAY_A );

        $cap = count( $presidents );

        if ( $cap === 0 ) {
            WP_CLI::line( "No presidents found within the specified offset ({$offset}) and limit ({$limit})." );
            return;
        }

        $processed  = 0;
        $downloaded = 0;
        $skipped    = 0;
        $failed     = 0;

        // NOTE: If you are logging EVERY step via $this->log(), the progress bar will break visually.
        // Consider removing the progress bar if $this->log() outputs directly to the screen.
        $progress = WP_CLI\Utils\make_progress_bar( 'President Images', $cap );
        $base_url = rtrim( getenv( 'DJANGO_MEDIA_URL' ) ?: 'https://media.futebol365.pt', '/' );

        // 3. Process the pre-fetched dataset in memory-efficient chunks
        $chunks = array_chunk( $presidents, (int) ( $this->batch ?? 50 ) );
        unset( $presidents ); // Free memory immediately

        foreach ( $chunks as $batch ) {
            $batch_post_ids = [];

            foreach ( $batch as $president ) {
                $post_id = (int) $president['ID'];
                $batch_post_ids[] = $post_id;
                $processed++;

                // 4. Skip if thumbnail exists (and force is not set)
                if ( ! $this->force && has_post_thumbnail( $post_id ) ) {
                    $skipped++;
                    $this->log( 'download_images_president', "SKIP: Featured image already exists for post_id={$post_id}" );
                    $progress->tick(); // Tick right before continuing to keep accuracy
                    continue;
                }

                // 5. Read data pulled directly from the JOIN query
                $person_id = (int) $president['django_person_id'];
                if ( ! $person_id ) {
                    $failed++;
                    $this->log( 'download_images_president', "FAIL: No django_person_id for post_id={$post_id}" );
                    $progress->tick(); 
                    continue;
                }

                $url   = $base_url . '/images/person/' . $person_id . '_president.jpg';
                $title = ! empty( $president['shortname'] ) ? (string) $president['shortname'] : (string) $president['post_title'];

                $att_id = $this->sideload_image( $url, $post_id, $title, 'download_images_president' );
                if ( $att_id ) {
                    set_post_thumbnail( $post_id, $att_id );
                    $downloaded++;
                    $this->log( 'download_images_president', "SUCCESS: Downloaded image and set thumbnail for post_id={$post_id} (Attachment ID: {$att_id})" );
                } else {
                    $failed++;
                    $this->log( 'download_images_president', "FAIL: Sideload failed for post_id={$post_id} URL={$url}" );
                }

                $progress->tick();
            }

            // 6. Hard memory cleanup per batch
            clean_post_cache( $batch_post_ids );
            wp_cache_flush(); 
        }

        $progress->finish();
        
        $summary = "processed={$processed}, downloaded={$downloaded}, skipped={$skipped}, failed={$failed}";
        WP_CLI::success( "President Images: " . $summary );
        $this->log( 'download_images_president', "SUMMARY: " . $summary );
    }
}


endif;
