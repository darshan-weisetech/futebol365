<?php
/**
 * Migration module: Sondagens (poll detail pages).
 *
 * Source table: {$wpdb->prefix}poll_poll  (WordPress custom / legacy poll table)
 * WordPress CPT: sondagem
 *
 * Builds (creates or updates) a `sondagem` post per poll row via direct SQL, 
 * sideloads the row image from the production domain as the featured image (de-duplicated), 
 * and writes the active/expired status to the `_sondagem_is_active` meta key.
 *
 * @package Futebol365
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    return;
}

trait F365_Module_Sondagem {

    protected function sync_sondagens(): void {

        global $wpdb;

        $polls_table = $wpdb->prefix . 'poll_poll';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$polls_table}'" ) !== $polls_table ) {
            WP_CLI::error( "Table {$polls_table} not found. Please verify your database structure first." );
            return;
        }

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$polls_table}" );
        $limit = ( PHP_INT_MAX === $this->limit ) ? $total : (int) $this->limit;

        // Determine available order column dynamically
        $order_by = $wpdb->get_col( "DESCRIBE {$polls_table}", 0 );
        $order_column = in_array( 'post_id', $order_by, true ) ? 'post_id' : 'id';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$polls_table} ORDER BY {$order_column} ASC LIMIT %d OFFSET %d",
            max( 0, $limit ),
            max( 0, $this->offset )
        ) );

        $created = $updated = $skipped = $images = 0;

        foreach ( $rows as $row ) {

            // Status condition lives in styles.published; default to active when absent.
            $is_active = '0';
            if ( isset( $row->is_active ) && '' !== (string) $row->is_active ) {
                $is_active = $this->parse_pg_bool( $row->is_active ) ? '1' : '0';
            }

            // Fallback chain for title text selection
            $title_text = $row->title ?? ( $row->question ?? "Sondagem " . $row->id );
            $title = sanitize_text_field( $title_text ?: "Sondagem " . $row->id );

            // Extract the original date from the table row if available (common column variants)
            $raw_date = $row->date ?? ( $row->created_at ?? ( $row->date_created ?? current_time( 'mysql' ) ) );
            
            // Format to standard WordPress MySQL datetime layout: YYYY-MM-DD HH:MM:SS
            $post_date = date( 'Y-m-d H:i:s', strtotime( (string) $raw_date ) );
            $post_date_gmt = get_gmt_from_date( $post_date );
            
            // Generate clean slug manually since we bypass wp_insert_post
            $post_name = sanitize_title( $title );

            // Match existing posts using a legacy reference look-up instead of literal ID allocation
            $existing_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ays_poll_id' AND meta_value = %d LIMIT 1",
                (int) $row->id
            ) );

            $target_post_id = 0;

            if ( $existing_id ) {
                // Verify the matched post is actually a 'sondagem'
                $existing_type = $wpdb->get_var( $wpdb->prepare(
                    "SELECT post_type FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
                    $existing_id
                ) );

                if ( 'sondagem' === $existing_type ) {
                    if ( ! $this->dry_run ) {
                        // Direct SQL Update
                        $wpdb->update(
                            $wpdb->posts,
                            [
                                'post_title'        => $title,
                                'post_name'         => $post_name,
                                'post_date'         => $post_date,
                                'post_date_gmt'     => $post_date_gmt,
                                'post_modified'     => current_time( 'mysql' ),
                                'post_modified_gmt' => current_time( 'mysql', 1 ),
                            ],
                            [ 'ID' => $existing_id ],
                            [ '%s', '%s', '%s', '%s', '%s', '%s' ],
                            [ '%d' ]
                        );
                        clean_post_cache( $existing_id );
                    }
                    $target_post_id = $existing_id;
                    $updated++;
                    $this->log( 'sondagem', "UPDATED post_id={$target_post_id} row_id={$row->id}" );
                } else {
                    $skipped++;
                    $this->log( 'sondagem', "SKIP row_id={$row->id} — Post match mismatch or corrupted type" );
                    continue;
                }
            } else {
                if ( ! $this->dry_run ) {
                    // Direct SQL Insert
                    $inserted = $wpdb->insert(
                        $wpdb->posts,
                        [
                            'post_author'       => 1, // Defaulting to admin or your migration user ID
                            'post_date'         => $post_date,
                            'post_date_gmt'     => $post_date_gmt,
                            'post_content' => '<!-- wp:shortcode -->[custom_poll]<!-- /wp:shortcode -->',
                            'post_title'        => $title,
                            'post_excerpt'      => '',
                            'post_status'       => 'publish',
                            'comment_status'    => 'closed',
                            'ping_status'       => 'closed',
                            'post_name'         => $post_name,
                            'to_ping'           => '',
                            'pinged'            => '',
                            'post_modified'     => $post_date,
                            'post_modified_gmt' => $post_date_gmt,
                            'post_content_filtered' => '',
                            'post_parent'       => 0,
                            'guid'              => '', // WordPress updates this post-insert naturally if needed
                            'menu_order'        => 0,
                            'post_type'         => 'sondagem',
                            'post_mime_type'    => '',
                            'comment_count'     => 0
                        ],
                        [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d' ]
                    );

                    if ( false === $inserted ) {
                        $skipped++;
                        $this->log( 'sondagem', "FAIL row_id={$row->id} — Database write error." );
                        continue;
                    }

                    $target_post_id = $wpdb->insert_id;
                    
                    // Tag the created post with its old record ID reference
                    $this->direct_update_post_meta( $target_post_id, '_ays_poll_id', (int) $row->id );
                }
                $created++;
                $this->log( 'sondagem', "CREATED row_id={$row->id}" );
            }

            if ( $this->dry_run ) {
                continue;
            }

            // Status meta + ACF reference key via Direct SQL updates
            $this->direct_update_post_meta( $target_post_id, '_sondagem_is_active', $is_active );
            $this->direct_update_post_meta( $target_post_id, '__sondagem_is_active', 'field_sondagem_is_active' );
            
            $this->direct_update_post_meta( $target_post_id, '_yoast_wpseo_title', "Sondagem: {$title} - Futebol 365" );
            $this->direct_update_post_meta( $target_post_id, '_yoast_wpseo_metadesc', "Dê a sua opinião sobre a nossa sondagem: {$title}" );

            // Featured image resolution with fixed site domain mapping rules
            if ( ! empty( $row->image ) ) {
                $att_id = $this->resolve_poll_attachment( (string) $row->image );
                if ( $att_id ) {
                    $this->direct_update_post_meta( $target_post_id, '_thumbnail_id', $att_id );
                    $images++;
                } else {
                    $this->log( 'sondagem', "IMAGE_FAIL post_id={$target_post_id} — {$row->image}" );
                }
            }
        }

        $this->log( 'sondagem', "SUMMARY: created=$created updated=$updated skipped=$skipped images=$images" );
        WP_CLI::success( "Sondagens: created=$created updated=$updated skipped=$skipped images=$images" . ( $this->dry_run ? ' (dry-run)' : '' ) );
    }

    /**
     * Helper to perform low-overhead, upsert-style direct SQL operations for metadata.
     */
    private function direct_update_post_meta( int $post_id, string $meta_key, $meta_value ): void {
        global $wpdb;
        
        $serialized_value = is_scalar( $meta_value ) ? $meta_value : serialize( $meta_value );

        $meta_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
            $post_id,
            $meta_key
        ) );

        if ( $meta_id ) {
            $wpdb->update(
                $wpdb->postmeta,
                [ 'meta_value' => $serialized_value ],
                [ 'meta_id' => $meta_id ],
                [ '%s' ],
                [ '%d' ]
            );
        } else {
            $wpdb->insert(
                $wpdb->postmeta,
                [
                    'post_id'    => $post_id,
                    'meta_key'   => $meta_key,
                    'meta_value' => $serialized_value
                ],
                [ '%d', '%s', '%s' ]
            );
        }
    }

    /**
     * Resolve a poll image to an attachment ID, using a specified production domain
     * path structure to target missing remote image assets cleanly.
     */
    private function resolve_poll_attachment( string $image ): int {

        $image = trim( $image );
        if ( '' === $image ) {
            return 0;
        }

        // Standardize image path into a qualified production domain URL
        if ( preg_match( '/^https?:\/\//i', $image ) ) {
            $url = $image;
        } else {
            $url = 'https://futebol365.pt/' . ltrim( $image, '/' );
        }

        global $wpdb;

        // URL already points at an existing local attachment.
        $local = attachment_url_to_postid( $url );
        if ( $local ) {
            return (int) $local;
        }

        // Clean filename lookup fallback strategy to minimize disk copies
        $filename = basename( (string) ( parse_url( $url, PHP_URL_PATH ) ?: $url ) );
        if ( $filename ) {
            $by_name = $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1",
                '%/' . $wpdb->esc_like( $filename )
            ) );
            if ( ! $by_name ) {
                $by_name = $wpdb->get_var( $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
                    $filename
                ) );
            }
            if ( $by_name ) {
                return (int) $by_name;
            }
        }

        // Sideloads image stream from production server seamlessly
        return $this->sideload_image( $url, 0, '', 'sondagem' );
    }
}