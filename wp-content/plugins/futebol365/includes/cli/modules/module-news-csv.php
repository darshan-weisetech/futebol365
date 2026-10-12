<?php
/**
 * Migration module: News CSV Direct SQL Importer
 *
 * Imports news articles from PostgreSQL source database driven by a CSV file.
 *
 * @package Futebol365
 * @since   2.5.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    return;
}

trait F365_Module_News_CSV {

    /**
     * Main entry point for the news_csv step.
     */
    protected function import_news_csv(): void {
        global $wpdb;

        $filename = $this->assoc_args['file'] ?? 'futebol365_artigo_urls';

        $filepath = '';
        $possible_names = [
            $filename,
            $filename . '.csv',
            ltrim( $filename, '/\\' ),
            ltrim( $filename, '/\\' ) . '.csv'
        ];

        foreach ( $possible_names as $name ) {
            $path = F365_PLUGIN_DIR . $name;
            if ( file_exists( $path ) ) {
                $filepath = $path;
                break;
            }
            if ( file_exists( $name ) ) {
                $filepath = $name;
                break;
            }
        }

        if ( empty( $filepath ) ) {
            WP_CLI::error( "CSV file not found: {$filename} (looked in plugin root and current directory)" );
            return;
        }

        WP_CLI::log( "Opening CSV file: {$filepath}" );

        $file_handle = fopen( $filepath, 'r' );
        if ( ! $file_handle ) {
            WP_CLI::error( "Failed to open CSV file: {$filepath}" );
            return;
        }

        // Initialize logging
        $this->log( 'news_csv', "RUN_START driven by CSV: {$filepath}" );

        // Defer term and comment counting, suspend cache addition to minimize WordPress overhead
        wp_suspend_cache_addition( true );
        wp_defer_term_counting( true );
        if ( function_exists( 'wp_defer_comment_counting' ) ) {
            wp_defer_comment_counting( true );
        }

        // Prepare Author mapping from usermeta
        $author_map = [];
        foreach ( $wpdb->get_results(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'django_source_id'"
        ) as $um ) {
            $author_map[ (int) $um->meta_value ] = (int) $um->user_id;
        }

        $upload_dir      = wp_upload_dir();
        $upload_basedir  = $upload_dir['basedir'];
        $upload_baseurl  = $upload_dir['baseurl'];

        // Read all URLs from CSV first
        $urls = [];
        while ( ( $row = fgetcsv( $file_handle ) ) !== false ) {
            if ( empty( $row ) || empty( $row[0] ) ) {
                continue;
            }
            $url = trim( $row[0] );
            if ( empty( $url ) || strpos( $url, 'http' ) !== 0 ) {
                continue;
            }
            $urls[] = $url;
        }
        fclose( $file_handle );

        $total_urls = count( $urls );
        WP_CLI::log( "Total URLs found in CSV: {$total_urls}" );

        $total_processed = 0;
        $success_count   = 0;
        $skipped_count   = 0;
        $missing_count   = 0;
        $errors_count    = 0;

        // Process in batches of 500 URLs
        $batch_size = (int) ( $this->batch ?? 500 );
        $chunks     = array_chunk( $urls, $batch_size );

        foreach ( $chunks as $chunk_index => $url_chunk ) {
            $batch_ids   = [];
            $batch_slugs = [];
            $parsed_urls = [];

            // 1. Parse URLs in this chunk
            foreach ( $url_chunk as $url ) {
                $parsed = $this->parse_article_url( $url );
                if ( $parsed ) {
                    $parsed_urls[ $url ] = $parsed;
                    if ( $parsed['id'] ) {
                        $batch_ids[] = $parsed['id'];
                    }
                    if ( $parsed['slug'] ) {
                        $batch_slugs[] = $parsed['slug'];
                    }
                } else {
                    $errors_count++;
                    $total_processed++;
                    $this->log( 'news_csv', "UNRESOLVED URL: '{$url}'" );
                    WP_CLI::warning( "Could not resolve article ID/slug from URL: {$url}" );
                }
            }

            if ( empty( $batch_ids ) && empty( $batch_slugs ) ) {
                continue;
            }

            // 2. Query PostgreSQL in a single bulk query for all items in the batch
            $where_clauses = [];
            $params        = [];
            $param_index   = 1;

            if ( ! empty( $batch_ids ) ) {
                $placeholders = [];
                foreach ( array_unique( $batch_ids ) as $id ) {
                    $placeholders[] = '$' . $param_index++;
                    $params[]       = $id;
                }
                $where_clauses[] = 'n.id IN (' . implode( ',', $placeholders ) . ')';
            }

            if ( ! empty( $batch_slugs ) ) {
                $placeholders = [];
                foreach ( array_unique( $batch_slugs ) as $slug ) {
                    $placeholders[] = '$' . $param_index++;
                    $params[]       = $slug;
                }
                $where_clauses[] = 'n.slug IN (' . implode( ',', $placeholders ) . ')';
            }

            $sql = 'SELECT n.*, s.name AS source_name, s.slug AS source_slug, s.id AS source_id
                      FROM publish_news n
                 LEFT JOIN publish_source s ON s.id = n.source_id';
            if ( ! empty( $where_clauses ) ) {
                $sql .= ' WHERE ' . implode( ' OR ', $where_clauses );
            }

            $pg_results = $this->pg_all( $sql, $params );

            // Index PostgreSQL results for quick lookup
            $news_by_id   = [];
            $news_by_slug = [];
            foreach ( $pg_results as $news_row ) {
                if ( isset( $news_row['id'] ) ) {
                    $news_by_id[ (int) $news_row['id'] ] = $news_row;
                }
                if ( ! empty( $news_row['slug'] ) ) {
                    $news_by_slug[ trim( $news_row['slug'] ) ] = $news_row;
                }
            }

            // 3. Pre-fetch existing WordPress posts matching this batch to avoid N+1 queries
            $existing_wp_ids = [];
            $pg_ids_found    = array_keys( $news_by_id );
            if ( ! empty( $pg_ids_found ) ) {
                $in_clause = implode( ',', $pg_ids_found );
                $existing_rows = $wpdb->get_results(
                    "SELECT ID, legacy_id FROM {$wpdb->posts} WHERE legacy_table = 'publish_news' AND legacy_id IN ($in_clause)",
                    ARRAY_A
                );
                foreach ( $existing_rows as $er ) {
                    $existing_wp_ids[ (int) $er['legacy_id'] ] = (int) $er['ID'];
                }
            }

            // 4. Process each URL in the chunk
            foreach ( $url_chunk as $url ) {
                if ( ! isset( $parsed_urls[ $url ] ) ) {
                    continue;
                }

                $total_processed++;
                $parsed = $parsed_urls[ $url ];
                
                $news_data = null;
                if ( $parsed['id'] && isset( $news_by_id[ $parsed['id'] ] ) ) {
                    $news_data = $news_by_id[ $parsed['id'] ];
                } elseif ( $parsed['slug'] && isset( $news_by_slug[ $parsed['slug'] ] ) ) {
                    $news_data = $news_by_slug[ $parsed['slug'] ];
                }

                if ( ! $news_data ) {
                    $missing_count++;
                    $this->log( 'news_csv', "MISSING URL in Postgres database (ID: " . var_export($parsed['id'], true) . ", Slug: " . var_export($parsed['slug'], true) . "): '{$url}'" );
                    WP_CLI::warning( "Article not found in source PostgreSQL database (URL: {$url})" );
                    continue;
                }

                $django_id = (int) $news_data['id'];

                // Check if already exists in WordPress
                if ( isset( $existing_wp_ids[ $django_id ] ) ) {
                    $skipped_count++;
                    $this->log( 'news_csv', "SKIP news_id={$django_id} — already exists in destination DB (no updates allowed). URL='{$url}'" );
                    continue;
                }

                try {
                    // Import the article
                    $result = $this->import_single_article( $news_data, $author_map, $upload_basedir, $upload_baseurl );
                    if ( $result === 'inserted' ) {
                        $success_count++;
                        $this->log( 'news_csv', "OK news_id={$django_id} result={$result} URL='{$url}'" );
                    } else {
                        $errors_count++;
                        $this->log( 'news_csv', "FAIL news_id={$django_id} result=unknown URL='{$url}'" );
                    }
                } catch ( \Throwable $e ) {
                    $errors_count++;
                    $this->log( 'news_csv', "FAIL news_id={$django_id} error='{$e->getMessage()}' URL='{$url}'" );
                    WP_CLI::warning( "Failed to import Article ID {$django_id}: " . $e->getMessage() );
                }
            }

            WP_CLI::log( sprintf( "Processed batch %d/%d (processed total: %d/%d)", $chunk_index + 1, count( $chunks ), $total_processed, $total_urls ) );
        }

        // Restore term counting and trigger counts calculation
        wp_defer_term_counting( false );
        if ( function_exists( 'wp_defer_comment_counting' ) ) {
            wp_defer_comment_counting( false );
        }
        wp_suspend_cache_addition( false );

        // Print final summary report
        WP_CLI::log( '' );
        WP_CLI::log( '━━━━━━━━━━━━ News CSV Import Summary ━━━━━━━━━━━━' );
        WP_CLI::log( "Total URLs processed:         {$total_processed}" );
        WP_CLI::log( "Successfully imported:         {$success_count}" );
        WP_CLI::log( "Skipped (already exists):      {$skipped_count}" );
        WP_CLI::log( "Missing from PostgreSQL DB:   {$missing_count}" );
        WP_CLI::log( "Errors / Unresolved URLs:      {$errors_count}" );
        WP_CLI::log( '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━' );

        $this->log( 'news_csv', "RUN_END total={$total_processed} success={$success_count} skipped={$skipped_count} missing={$missing_count} errors={$errors_count}" );
    }

    /**
     * Parse article URL to extract the legacy Django news ID and slug.
     */
    private function parse_article_url( string $url ): ?array {
        // Match /artigo/([^/\?#\s]+)
        if ( preg_match( '/\/artigo\/([^\/\?#\s]+)/i', $url, $matches ) ) {
            $path_part = $matches[1];
            $id = null;
            if ( preg_match( '/^(\d+)/', $path_part, $id_matches ) ) {
                $id = (int) $id_matches[1];
            }
            return [
                'id'   => $id,
                'slug' => $path_part
            ];
        }
        return null;
    }

    /**
     * Import a single news article from database content.
     */
    private function import_single_article( array $news, array $author_map, string $upload_basedir, string $upload_baseurl ): string {
        global $wpdb;

        $django_id = (int) $news['id'];
        $title     = $news['title'] ?? '';
        $content   = $news['description'] ?? '';
        $excerpt   = $news['summary'] ?? '';
        $status    = $this->parse_pg_bool( $news['is_published'] ?? false ) ? 'publish' : 'draft';
        
        $pd    = ! empty( $news['datetime'] )   ? date( 'Y-m-d H:i:s', strtotime( $news['datetime'] ) )   : current_time( 'mysql' );
        $mod_d = ! empty( $news['updated_at'] ) ? date( 'Y-m-d H:i:s', strtotime( $news['updated_at'] ) ) : $pd;
        
        $slug = sanitize_title( $news['slug'] ?? '' );
        if ( empty( $slug ) ) {
            $slug = (string) $django_id;
        }

        $author_id = 1;
        if ( ! empty( $news['source_id'] ) && isset( $author_map[ (int) $news['source_id'] ] ) ) {
            $author_id = $author_map[ (int) $news['source_id'] ];
        }

        // Direct SQL INSERT
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->posts}
                (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt,
                 post_status, comment_status, ping_status, post_name, post_modified, post_modified_gmt,
                 post_type, legacy_table, legacy_id, to_ping, pinged, post_content_filtered)
             VALUES (%d, %s, %s, %s, %s, %s, %s, 'closed', 'closed', %s, %s, %s, 'post', 'publish_news', %d, '', '', '')",
            $author_id, $pd, get_gmt_from_date( $pd ), $content, $title, $excerpt,
            $status, $slug, $mod_d, get_gmt_from_date( $mod_d ), $django_id
        ) );
        $wp_id = (int) $wpdb->insert_id;

        if ( ! $wp_id ) {
            throw new \RuntimeException( "Direct post insertion failed: " . $wpdb->last_error );
        }

        // Update guid column natively
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->posts} SET guid = %s WHERE ID = %d",
            get_home_url() . '/?p=' . $wp_id, $wp_id
        ) );

        // Synchronize category and tag relationships using Direct SQL
        $this->sync_article_taxonomies( $wp_id, $django_id );

        // Set featured image if image exists and is valid
        if ( ! empty( $news['image'] ) && strpos( $news['image'], '365-no-photo.jpg' ) === false ) {
            $this->assign_featured_image_local( $wp_id, $news['image'], $title, $pd, $mod_d, $upload_basedir, $upload_baseurl );
        }

        return 'inserted';
    }

    /**
     * Synchronize taxonomy terms (categories & tags) directly using SQL.
     */
    private function sync_article_taxonomies( int $wp_id, int $django_id ): void {
        global $wpdb;

        // Clear existing categories and tags term relationships for this post
        $wpdb->query( $wpdb->prepare(
            "DELETE tr FROM {$wpdb->term_relationships} tr
              INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
              WHERE tr.object_id = %d AND tt.taxonomy IN ('category', 'post_tag')",
            $wp_id
        ) );

        $term_taxonomy_ids = [];

        // 1. Fetch Categories from Postgres
        $pg_cats = $this->pg_all(
            'SELECT c.name, c.slug FROM publish_news_categories nc
               JOIN publish_newscategory c ON c.id = nc.newscategory_id
              WHERE nc.news_id = $1',
            [ $django_id ]
        );

        foreach ( $pg_cats as $c ) {
            // Enable cache temporarily to resolve or insert category terms
            wp_suspend_cache_addition( false );
            $term_id = $this->ensure_term( $c['name'], 'category', $c['slug'] );
            wp_suspend_cache_addition( true );

            if ( $term_id ) {
                $tt_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'category' LIMIT 1",
                    $term_id
                ) );
                if ( $tt_id ) {
                    $term_taxonomy_ids[] = $tt_id;
                }
            }
        }

        // 2. Fetch Tags from Postgres
        $pg_tags = $this->pg_all(
            'SELECT t.name FROM publish_news_tags nt
               JOIN common_tag t ON t.id = nt.tag_id
              WHERE nt.news_id = $1',
            [ $django_id ]
        );

        foreach ( $pg_tags as $t ) {
            wp_suspend_cache_addition( false );
            $term_id = $this->ensure_term( $t['name'], 'post_tag' );
            wp_suspend_cache_addition( true );

            if ( $term_id ) {
                $tt_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'post_tag' LIMIT 1",
                    $term_id
                ) );
                if ( $tt_id ) {
                    $term_taxonomy_ids[] = $tt_id;
                }
            }
        }

        // Insert new relationships in bulk
        if ( ! empty( $term_taxonomy_ids ) ) {
            $term_taxonomy_ids = array_values( array_unique( $term_taxonomy_ids ) );
            $values_sql = [];
            foreach ( $term_taxonomy_ids as $tt_id ) {
                $values_sql[] = $wpdb->prepare( "(%d, %d, 0)", $wp_id, $tt_id );
            }
            $wpdb->query( "INSERT IGNORE INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order) VALUES " . implode( ',', $values_sql ) );
        }
    }

    /**
     * Map the pre-existing file as an attachment and set it as the featured image.
     */
    private function assign_featured_image_local( int $wp_id, string $image_path, string $post_title, string $pd, string $mod_d, string $upload_basedir, string $upload_baseurl ): void {
        global $wpdb;

        $relative_file = '../../media/' . ltrim( $image_path, '/\\' );
        $guid_url      = get_home_url() . '/media/' . ltrim( $image_path, '/\\' );
        $absolute_image_path = ABSPATH . 'media/' . ltrim( $image_path, '/\\' );

        // Create attachment record directly in wp_posts using wp_insert_post
        $filename       = basename( $image_path );
        $post_mime_type = 'image/jpeg';
        $ext            = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
        switch ( $ext ) {
            case 'png':
                $post_mime_type = 'image/png';
                break;
            case 'gif':
                $post_mime_type = 'image/gif';
                break;
            case 'webp':
                $post_mime_type = 'image/webp';
                break;
        }

        $attachment_title = pathinfo( $filename, PATHINFO_FILENAME );
        $attachment_slug  = sanitize_title( $attachment_title );

        $attachment_data = [
            'post_title'        => $attachment_title,
            'post_name'         => $attachment_slug,
            'post_status'       => 'inherit',
            'post_type'         => 'attachment',
            'post_mime_type'    => $post_mime_type,
            'guid'              => $guid_url,
            'post_author'       => 1,
            'post_date'         => $pd,
            'post_date_gmt'     => get_gmt_from_date( $pd ),
            'post_modified'     => $mod_d,
            'post_modified_gmt' => get_gmt_from_date( $mod_d ),
            'post_content'      => '',
            'post_excerpt'      => '',
        ];

        // Use wp_insert_post (which is the approved standard wp_insert wrapper)
        $attachment_id = wp_insert_post( $attachment_data, true );

        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            $error_msg = is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'Unknown error';
            $this->log( 'news_csv', "IMAGE_FAIL wp_insert_post failed for image: {$image_path} post_id={$wp_id} error={$error_msg}" );
            return;
        }

        // Write direct postmeta for the attachment record
        update_post_meta( $attachment_id, '_wp_attached_file', $relative_file );

        // Gather image size dimensions if physical file exists, else default to 0
        $width  = 0;
        $height = 0;
        if ( file_exists( $absolute_image_path ) ) {
            $image_size = @getimagesize( $absolute_image_path );
            $width      = $image_size ? (int) $image_size[0] : 0;
            $height     = $image_size ? (int) $image_size[1] : 0;
        }

        $metadata = [
            'width'      => $width,
            'height'     => $height,
            'file'       => $relative_file,
            'sizes'      => [],
            'image_meta' => [
                'aperture'          => '0',
                'credit'            => '',
                'camera'            => '',
                'caption'           => '',
                'created_timestamp' => '0',
                'copyright'         => '',
                'focal_length'      => '0',
                'iso'               => '0',
                'shutter_speed'     => '0',
                'title'             => '',
                'orientation'       => '0',
            ],
        ];

        update_post_meta( $attachment_id, '_wp_attachment_metadata', $metadata );

        // Set attachment as featured image (clean previous and assign)
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_thumbnail_id'",
            $wp_id
        ) );

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES (%d, '_thumbnail_id', %d)",
            $wp_id, $attachment_id
        ) );
    }

}
