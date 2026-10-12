<?php
/**
 * Migration modules: Stadiums, Federations, Federation Competitions.
 *
 * @package Futebol365
 * @since   2.1.0
 */

defined( 'ABSPATH' ) || exit;
if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) { return; }

trait F365_Module_Stadiums {

    protected function import_stadiums(): void {
        $rows = $this->pg_all( '
            SELECT s.*, c.shortname AS country_name
            FROM stadium_stadium s
            LEFT JOIN country_country c ON c.id = s.country_id
            ORDER BY s.id
        ' );
        $n = $skip = $fail = 0;

        foreach ( $rows as $r ) {
            if ( $n >= $this->limit ) break;
            if ( ! $this->force && $this->already_imported( 'f365_stadium', $r['id'] ) ) {
                $skip++;
                $this->log( 'stadiums', "SKIP stadium_id={$r['id']}" );
                continue;
            }
            $wp_id = $this->insert_post( [
                'post_type'   => 'f365_stadium',
                'post_title'  => $r['shortname'] ?? '',
                'post_status' => 'publish',
            ] );
            if ( ! $wp_id ) {
                $fail++;
                $this->log( 'stadiums', "FAIL stadium_id={$r['id']}" );
                continue;
            }

            // Using ACF field keys to ensure correct data structures
            $acf_fields = [
                'field_st_shortname'  => sanitize_text_field( $r['shortname']  ?? '' ),
                'field_st_longname'   => sanitize_text_field( $r['longname']   ?? '' ),
                'field_st_nickname'   => sanitize_text_field( $r['nickname']   ?? '' ),
                'field_st_url'        => esc_url_raw( $r['url']         ?? '' ),
                'field_st_email'      => sanitize_email( $r['email']       ?? '' ),
                'field_st_facebook'   => esc_url_raw( $r['facebook']    ?? '' ),
                'field_st_twitter'    => esc_url_raw( $r['twitter']     ?? '' ),
                'field_st_youtube'    => esc_url_raw( $r['youtube']     ?? '' ),
                'field_st_instagram'  => esc_url_raw( $r['instagram']   ?? '' ),
                'field_st_googleplus' => esc_url_raw( $r['googleplus']  ?? '' ),
                'field_st_city'       => sanitize_text_field( $r['city']        ?? '' ),
                'field_st_address'    => sanitize_textarea_field( $r['address'] ?? '' ),
                'field_st_latitude'   => sanitize_text_field( $r['latitude']   ?? '' ),
                'field_st_longitude'  => sanitize_text_field( $r['longitude']  ?? '' ),
                'field_st_capacity'   => (int) ( $r['capacity']   ?? 0 ),
                'field_st_dimensions' => sanitize_text_field( $r['dimensions'] ?? '' ),
                'field_st_architect'  => sanitize_text_field( $r['architect']  ?? '' ),
                'field_st_opening'    => $this->d( $r['opening']    ?? '' ),
                'field_st_closing'    => $this->d( $r['closing']    ?? '' ),
                'field_st_first_game' => sanitize_text_field( $this->d( $r['first_game'] ?? '' ) ),
                'field_st_edition'    => sanitize_text_field( $r['edition']    ?? '' ),
                'django_stadium_id'  => (int) $r['id'],
            ];

            foreach ( $acf_fields as $field_key => $value ) {
                update_field( $field_key, $value, $wp_id );
            }

            if ( $r['country_name'] ) {
                $this->set_terms( $wp_id, [ $r['country_name'] ], 'f365_country' );
            }

            // Yoast SEO
            update_post_meta( $wp_id, '_yoast_wpseo_title', sanitize_text_field( $r['shortname']  ?? '' ) );
            update_post_meta( $wp_id, '_yoast_wpseo_metadesc', "Toda a informação sobre " . sanitize_text_field( $r['shortname']  ?? '' ) . "." );

            $this->map_set( 'f365_stadium', $r['id'], $wp_id );
            $this->log( 'stadiums', "OK stadium_id={$r['id']} → wp_id=$wp_id ({$r['shortname']})" );
            $n++;
        }

        $this->map_save( 'f365_stadium' );
        $this->log( 'stadiums', "SUMMARY: imported=$n skipped=$skip failed=$fail" );
        WP_CLI::success( "Stadiums: $n imported, $skip skipped, $fail failed." );
    }
}

trait F365_Module_Federations {

    protected function import_federations(): void {
        $n = $skip = $fail = 0;

        foreach ( [ 'confederation', 'federation', 'association' ] as $ftype ) {
            $rows = $this->pg_all( '
                SELECT f.*, c.shortname AS country_name
                FROM federation_federation f
                LEFT JOIN country_country c ON c.id = f.country_id
                WHERE f.federation_type = $1 ORDER BY f.shortname
            ', [ $ftype ] );

            foreach ( $rows as $r ) {
                if ( $n >= $this->limit ) break;
                $map = $this->map_get('f365_federation');
                $existing_wp_id = $map[$r['id']] ?? null;

                if ($existing_wp_id) {

                    $wp_id = (int) $existing_wp_id;

                    // safety check
                    if ($wp_id > 0 && get_post($wp_id)) {
                        $parent_wp = $this->map_resolve('f365_federation', $r['federation_id'] ?? null);
                        wp_update_post([
                            'ID'          => $wp_id,
                            'post_title'  => $r['shortname'] ?? '',
                            'post_status' => 'publish',
                            'post_parent' => $parent_wp ?? 0,
                        ]);
                    } else {
                        // map is corrupted → recreate
                        unset($map[$r['id']]);
                        $existing_wp_id = null;
                    }
                }

                if (!$existing_wp_id) {

                    $parent_wp = $this->map_resolve('f365_federation', $r['federation_id'] ?? null);

                    $wp_id = $this->insert_post([
                        'post_type'   => 'f365_federation',
                        'post_title'  => $r['shortname'] ?? '',
                        'post_status' => 'publish',
                        'post_parent' => $parent_wp ?? 0,
                    ]);

                    if (!$wp_id) {
                        $fail++;
                        $this->log('federations', "FAIL federation_id={$r['id']}");
                        continue;
                    }

                    $this->map_set('f365_federation', $r['id'], $wp_id);
                }

                $this->set_meta( $wp_id, [
                    'shortname'                   => $r['shortname']       ?? '',
                    'longname'                    => $r['longname']        ?? '',
                    'federation_type'             => $r['federation_type'] ?? '',
                    'season_type'                 => $r['season_type']     ?? '',
                    'season_begin_with_edition'   => $r['season_begin_with_edition'] ? 1 : 0,
                    'city'                        => $r['city']            ?? '',
                    'address'                     => $r['address']         ?? '',
                    'url'                         => $r['url']              ?? '',
                    'email'                       => $r['email']            ?? '',
                    'facebook'                    => $r['facebook']         ?? '',
                    'twitter'                     => $r['twitter']          ?? '',
                    'youtube'                     => $r['youtube']          ?? '',
                    'instagram'                   => $r['instagram']        ?? '',
                    'googleplus'                   => $r['googleplus']        ?? '',
                    'foundation'                  => $this->d( $r['foundation']   ?? '' ),
                    'season_begin'                => $this->d( $r['season_begin'] ?? '' ),
                    'season_end'                  => $this->d( $r['season_end']   ?? '' ),
                    'edition'                     => $r['edition']          ?? '',
                    'django_federation_id'        => (int) $r['id'],
                    'django_president_id'        => (int) $r['president_id'],
                    'django_country_id'        => (int) $r['country_id'],
                    'django_parent_federation_id' => (int) ( $r['federation_id'] ?? 0 ),
                    'django_tbl_name' => 'federation_federation',
                ] );
                if ( $r['country_name'] ) $this->set_terms( $wp_id, [ $r['country_name'] ], 'f365_country' );
                if ( $ftype )             $this->set_terms( $wp_id, [ $ftype ],              'f365_fed_type' );
                $img_error = $this->handle_featured_image( $wp_id,$r, $r['id']);
                if ( $img_error ) {
                    $this->log(
                        'federations',
                        "IMAGE federation_id={$r['id']} ERROR: {$img_error}"
                    );
                }
                $this->log( 'federations', "OK federation_id={$r['id']} → wp_id=$wp_id ({$r['shortname']})" );
                $n++;
            }
        }

        $this->map_save( 'f365_federation' );
        $this->log( 'federations', "SUMMARY: imported=$n skipped=$skip failed=$fail" );
        WP_CLI::success( "Federations: $n imported, $skip skipped, $fail failed." );
    }

    /**
     * DYNAMIC IMAGE DOWNLOADER ENGINE
     */
    protected function handle_featured_image(int $wp_post_id, array $row, int $source_id): ?string {
    
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        if (get_post_thumbnail_id($wp_post_id)) {
            return null;
        }

        $image_path = $this->get_image_url_by_source_id($source_id);

        if (!$image_path) {
            return "No image path for ID {$source_id}";
        }

        $existing_attachment_id = $this->get_attachment_id_by_url($image_path);
        if ($existing_attachment_id) {
            set_post_thumbnail($wp_post_id, $existing_attachment_id);
            return null;
        }

        $is_url = filter_var($image_path, FILTER_VALIDATE_URL);

        if ($is_url) {

            $tmp = download_url($image_path);

            if (is_wp_error($tmp)) {
                return $tmp->get_error_message();
            }

            $file_array = [
                'name'     => basename($image_path),
                'tmp_name' => $tmp,
            ];

        } else {

            if (!file_exists($image_path)) {
                return "File not found: {$image_path}";
            }

            $tmp_copy = wp_tempnam($image_path);
            copy($image_path, $tmp_copy);

            $file_array = [
                'name'     => basename($image_path),
                'tmp_name' => $tmp_copy,
            ];
        }

        $attachment_id = media_handle_sideload($file_array, $wp_post_id, $row['shortname'] ?? '');

        if (is_wp_error($attachment_id)) {
            @unlink($file_array['tmp_name']);
            return $attachment_id->get_error_message();
        }

        update_post_meta($attachment_id, '_f365_original_source_url', $image_path);
        set_post_thumbnail($wp_post_id, $attachment_id);

        return null;
    }

    /**
     * Image url
     */
    protected function get_image_url_by_source_id(int $source_id): ?string {
        // 1. Define the Mapping
        $map = [
            // Source IDs 1-7 use local icons
            '1' => 'assets/images/icon/1.png',
            '2' => 'assets/images/icon/2.png',
            '3' => 'assets/images/icon/3.png',
            '4' => 'assets/images/icon/4.png',
            '5' => 'assets/images/icon/5.png',
            '6' => 'assets/images/icon/6.png',
            '7' => 'assets/images/icon/7.png',
        ];

        // 3. Return the mapped value or empty string
        $path = $map[(string)$source_id] ?? '';

        // If it's a local path (doesn't start with http), prepend the plugin directory
        if (!empty($path) && strpos($path, 'http') === false) {
            return trailingslashit(F365_PLUGIN_DIR) . ltrim($path, '/');
        }

        return $path;
    }

    /**
     * Find an existing attachment ID by its original source URL.
     */
    protected function get_attachment_id_by_url( string $url ): ?int {
        global $wpdb;

        $attachment_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta 
            WHERE meta_key = '_f365_original_source_url' 
            AND meta_value = %s 
            LIMIT 1",
            $url
        ) );

        return $attachment_id ? (int) $attachment_id : null;
    }
}

trait F365_Module_FedCompetitions {

    protected function import_fed_competitions(): void {
        $rows = $this->pg_all( 'SELECT * FROM federation_federationcompetition ORDER BY "order", id' );
        $n = $skip = $fail = 0;

        foreach ( $rows as $r ) {
            if ( $n >= $this->limit ) break;
            $map = $this->map_get('f365_fed_comp');
            $existing_wp_id = $map[$r['id']] ?? null;

            if ($existing_wp_id) {

                $wp_id = (int) $existing_wp_id;

                // safety check
                if ($wp_id > 0 && get_post($wp_id)) {
                    wp_update_post([
                        'ID'          => $wp_id,
                        'post_title'  => $r['shortname'] ?? '',
                        'post_status' => 'publish',
                    ]);
                } else {
                    // map is corrupted → recreate
                    unset($map[$r['id']]);
                    $existing_wp_id = null;
                }
            }

            if (!$existing_wp_id) {
                $wp_id = $this->insert_post( [
                    'post_type'   => 'f365_fed_comp',
                    'post_title'  => $r['shortname'] ?? '',
                    'post_status' => 'publish',
                ] );
                if ( ! $wp_id ) {
                    $fail++;
                    $this->log( 'fed_competitions', "FAIL fed_comp_id={$r['id']}" );
                    continue;
                }
                $this->map_set( 'f365_fed_comp', $r['id'], $wp_id );
            }
            $this->set_meta( $wp_id, [
                'shortname'                => $r['shortname']       ?? '',
                'longname'                 => $r['longname']        ?? '',
                'competition_type'         => $r['competition_type']?? '',
                'scope'                    => $r['scope']            ?? '',
                'step'                     => $r['step']             ?? '',
                'modality'                 => $r['modality']         ?? '',
                'gender'                   => $r['gender']           ?? '',
                'color'                    => $r['color']            ?? '',
                'url'                      => $r['url']              ?? '',
                'email'                    => $r['email']            ?? '',
                'facebook'                 => $r['facebook']         ?? '',
                'twitter'                  => $r['twitter']          ?? '',
                'youtube'                  => $r['youtube']          ?? '',
                'instagram'                => $r['instagram']        ?? '',
                'googleplus'               => $r['googleplus']        ?? '',
                'order'                    => (int) ( $r['order']    ?? 0 ),
                'is_principal'             => $r['is_principal']             ? 1 : 0,
                'is_active'                => $r['is_active']                ? 1 : 0,
                'is_highlight'             => $r['is_highlight']             ? 1 : 0,
                'auto_create_match_events' => $r['auto_create_match_events'] ? 1 : 0,
                'show_stadiums'            => $r['show_stadiums']            ? 1 : 0,
                'django_fed_comp_id'       => (int) $r['id'],
                'django_federation_id'     => (int) ( $r['federation_id'] ?? 0 ),
                'django_tbl_name'          => 'federation_federationcompetition',
            ] );
            if ( $r['scope'] ) {
                $term_name = ucwords(str_replace('_', ' ', $r['scope']));
                $this->set_terms( $wp_id,[ $term_name ],'f365_comp_scope');
            }
            if (!empty($r['federation_id'])) {
                $fed_map = $this->map_get('f365_federation');
                $fed_wp_id = $fed_map[$r['federation_id']] ?? null;

                if ($fed_wp_id) {
                    update_post_meta($wp_id, 'federation', (int) $fed_wp_id);
                    
                    // GET COUNTRY FROM FEDERATION
                    $fed_rows = $this->pg_all(
                        'SELECT country_id FROM federation_federation WHERE id = $1',
                        [$r['federation_id']]
                    );

                    $country_id = $fed_rows[0]['country_id'] ?? null;

                    if ($country_id) {

                        $country_rows = $this->pg_all(
                            'SELECT shortname FROM country_country WHERE id = $1',
                            [$country_id]
                        );

                        $country_name = $country_rows[0]['shortname'] ?? null;

                        if ($country_name) {
                            $this->set_terms($wp_id, [$country_name], 'f365_country');
                        }
                    }
                } else {
                    $this->log('fed_competitions', "MISSING federation mapping for ID={$r['federation_id']}");
                }
            }
            /**
             * Save Repeater Field
             * federation_federationcompetitionsettings
             */
            $settings_rows = $this->pg_all(
                'SELECT *
                FROM federation_federationcompetitionsettings
                WHERE federation_competition_id = ' . (int) $r['id']
            ); 
            $acf_settings = [];
            $raw_settings = [];
            foreach ( $settings_rows as $s ) {

                $acf_settings[] = [
                    'season_type' => $s['season_type'] ?? '',
                    'season_type_since_edition' => $s['season_type_since_edition'] ?? '',
                    'season_type_since_season' => $s['season_type_since_season'] ?? '',
                    'season_begin_with_edition' => ! empty( $s['season_begin_with_edition'] ),
                    'has_apertura_clausura' => ! empty( $s['has_apertura_clausura'] ),
                ];
                $raw_settings[] = $s;
            }

            if ( ! empty( $acf_settings ) ) {
                update_field('field_fc_settings',$acf_settings, $wp_id);
                update_post_meta($wp_id,'_fc_settings_raw',$raw_settings);
            }
            
            $this->log( 'fed_competitions', "OK fed_comp_id={$r['id']} → wp_id=$wp_id ({$r['shortname']})" );
            $n++;
        }

        $this->map_save( 'f365_fed_comp' );
        $this->log( 'fed_competitions', "SUMMARY: imported=$n skipped=$skip failed=$fail" );
        WP_CLI::success( "Fed competitions: $n imported, $skip skipped, $fail failed." );
    }
}