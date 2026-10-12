<?php
/**
 * Helper: Calculate player age from birth date.
 */
function f365_calculate_age( $birth_date ) {
    if ( empty( $birth_date ) ) {
        return null;
    }
    $birth = new DateTime( $birth_date );
    $now   = new DateTime();
    $diff  = $now->diff( $birth );
    return $diff->y;
}

/**
 * Helper: Translate playing positions to Portuguese.
 */
function f365_get_position_display( $position_code ) {
    $positions = array(
        'goalkeeper' => 'Guarda-redes',
        'defender'   => 'Defesa',
        'midfielder' => 'Médio',
        'forward'    => 'Avançado',
    );
    return $positions[ strtolower( $position_code ) ] ?? $position_code;
}

/**
 * Helper: Translate transfer types.
 */
function f365_get_transfer_type_display( $type_code ) {
    $types = array(
        'D' => 'Definitiva',
        'E' => 'Empréstimo',
    );
    return $types[ strtoupper( $type_code ) ] ?? $type_code;
}

/**
 * Retrieves the total count of transfers based on active filters (Capped at 600).
 */
function f365_get_transfers_count( $filters = array() ) {
    global $wpdb;

    $transfers_table = $wpdb->prefix . 'transfer_transfer';
    $player_table    = $wpdb->prefix . 'person_player';
    $teamstep_table  = $wpdb->prefix . 'team_teamstep';
    $team_table      = $wpdb->prefix . 'team_team';

    $where_clauses = array();
    $params        = array();

    // Filter by Country
    if ( ! empty( $filters['country'] ) ) {
        $country_id = intval( $filters['country'] );
        $where_clauses[] = "(t_orig.country_id = %d OR t_orig_tmp.country_id = %d OR t_dest.country_id = %d)";
        $params[] = $country_id;
        $params[] = $country_id;
        $params[] = $country_id;
    }

    // Filter by Team
    if ( ! empty( $filters['team'] ) ) {
        $team_id = intval( $filters['team'] );
        $where_clauses[] = "(t_orig.id = %d OR t_orig_tmp.id = %d OR t_dest.id = %d)";
        $params[] = $team_id;
        $params[] = $team_id;
        $params[] = $team_id;
    }

    // Filter by Transfer Type
    if ( ! empty( $filters['transfer_type'] ) ) {
        $where_clauses[] = "t.transfer_type = %s";
        $params[] = sanitize_text_field( $filters['transfer_type'] );
    }

    // Filter by Transfer Option (In/Out Flows)
    if ( ! empty( $filters['transfer_option'] ) && 'trans_op_all' !== $filters['transfer_option'] ) {
        if ( 'trans_op_in' === $filters['transfer_option'] ) {
            if ( ! empty( $filters['team'] ) ) {
                $where_clauses[] = "t_dest.id = %d";
                $params[] = intval( $filters['team'] );
            }
            if ( ! empty( $filters['country'] ) ) {
                $where_clauses[] = "t_dest.country_id = %d";
                $params[] = intval( $filters['country'] );
            }
        } elseif ( 'trans_op_out' === $filters['transfer_option'] ) {
            if ( ! empty( $filters['team'] ) ) {
                $where_clauses[] = "(t_orig.id = %d OR t_orig_tmp.id = %d)";
                $params[] = intval( $filters['team'] );
                $params[] = intval( $filters['team'] );
            }
            if ( ! empty( $filters['country'] ) ) {
                $where_clauses[] = "(t_orig.country_id = %d OR t_orig_tmp.country_id = %d)";
                $params[] = intval( $filters['country'] );
                $params[] = intval( $filters['country'] );
            }
        }
    }

    $where_sql = ! empty( $where_clauses ) ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';

    $query = "SELECT COUNT(t.id)
              FROM {$transfers_table} t
              JOIN {$player_table} p ON t.player_id = p.id
              LEFT JOIN {$teamstep_table} ts_orig ON t.team_origin_id = ts_orig.id
              LEFT JOIN {$team_table} t_orig ON ts_orig.team_id = t_orig.id
              LEFT JOIN {$team_table} t_orig_tmp ON t.tmp_origin_team_id = t_orig_tmp.id
              JOIN {$teamstep_table} ts_dest ON t.team_destination_id = ts_dest.id
              JOIN {$team_table} t_dest ON ts_dest.team_id = t_dest.id
              {$where_sql}";

    if ( ! empty( $params ) ) {
        $query = $wpdb->prepare( $query, $params );
    }

    $count = (int) $wpdb->get_var( $query );

    // Django view slices the queryset to [:600]
    return min( 600, $count );
}

/**
 * Retrieves transfer entries from custom tables with high performance SQL.
 */
function f365_get_transfers( $filters = array(), $limit = 30, $offset = 0 ) {
    global $wpdb;

    // Mimic the Django [:600] cap on results
    if ( $offset >= 600 ) {
        return array();
    }
    if ( $offset + $limit > 600 ) {
        $limit = 600 - $offset;
    }

    $transfers_table = $wpdb->prefix . 'transfer_transfer';
    $player_table    = $wpdb->prefix . 'person_player';
    $person_table    = $wpdb->prefix . 'person_person';
    $teamstep_table  = $wpdb->prefix . 'team_teamstep';
    $team_table      = $wpdb->prefix . 'team_team';

    $where_clauses = array();
    $params        = array();

    // Filter by Country
    if ( ! empty( $filters['country'] ) ) {
        $country_id = intval( $filters['country'] );
        $where_clauses[] = "(t_orig.country_id = %d OR t_orig_tmp.country_id = %d OR t_dest.country_id = %d)";
        $params[] = $country_id;
        $params[] = $country_id;
        $params[] = $country_id;
    }

    // Filter by Team
    if ( ! empty( $filters['team'] ) ) {
        $team_id = intval( $filters['team'] );
        $where_clauses[] = "(t_orig.id = %d OR t_orig_tmp.id = %d OR t_dest.id = %d)";
        $params[] = $team_id;
        $params[] = $team_id;
        $params[] = $team_id;
    }

    // Filter by Transfer Type
    if ( ! empty( $filters['transfer_type'] ) ) {
        $where_clauses[] = "t.transfer_type = %s";
        $params[] = sanitize_text_field( $filters['transfer_type'] );
    }

    // Filter by Transfer Option (In/Out Flows)
    if ( ! empty( $filters['transfer_option'] ) && 'trans_op_all' !== $filters['transfer_option'] ) {
        if ( 'trans_op_in' === $filters['transfer_option'] ) {
            if ( ! empty( $filters['team'] ) ) {
                $where_clauses[] = "t_dest.id = %d";
                $params[] = intval( $filters['team'] );
            }
            if ( ! empty( $filters['country'] ) ) {
                $where_clauses[] = "t_dest.country_id = %d";
                $params[] = intval( $filters['country'] );
            }
        } elseif ( 'trans_op_out' === $filters['transfer_option'] ) {
            if ( ! empty( $filters['team'] ) ) {
                $where_clauses[] = "(t_orig.id = %d OR t_orig_tmp.id = %d)";
                $params[] = intval( $filters['team'] );
                $params[] = intval( $filters['team'] );
            }
            if ( ! empty( $filters['country'] ) ) {
                $where_clauses[] = "(t_orig.country_id = %d OR t_orig_tmp.country_id = %d)";
                $params[] = intval( $filters['country'] );
                $params[] = intval( $filters['country'] );
            }
        }
    }

    $where_sql = ! empty( $where_clauses ) ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';

    $query = "SELECT 
                t.id AS transfer_id,
                t.transfer_date,
                t.transfer_type,
                t.value,
                
                -- Player Info
                p.id AS player_id,
                pr.shortname AS player_name,
                p.position AS player_position,
                pr.birth_date AS player_birth_date,
                pr.nationality_id AS player_nationality_id,
                
                -- Origin Team Info
                t_orig.id AS origin_team_id,
                COALESCE(t_orig.shortname, t_orig_tmp.shortname) AS origin_team_name,
                
                -- Origin Team Country
                COALESCE(
                    (SELECT c.shortname FROM {$wpdb->prefix}country_country c WHERE c.id = t_orig.country_id),
                    (SELECT c.shortname FROM {$wpdb->prefix}country_country c WHERE c.id = t_orig_tmp.country_id)
                ) AS origin_team_country_name,
                
                -- Destination Team Info
                t_dest.id AS destination_team_id,
                t_dest.shortname AS destination_team_name,
                
                -- Destination Team Country
                (SELECT c.shortname FROM {$wpdb->prefix}country_country c WHERE c.id = t_dest.country_id) AS destination_team_country_name
              FROM {$transfers_table} t
              JOIN {$player_table} p ON t.player_id = p.id
              JOIN {$person_table} pr ON p.person_id = pr.id
              LEFT JOIN {$teamstep_table} ts_orig ON t.team_origin_id = ts_orig.id
              LEFT JOIN {$team_table} t_orig ON ts_orig.team_id = t_orig.id
              LEFT JOIN {$team_table} t_orig_tmp ON t.tmp_origin_team_id = t_orig_tmp.id
              JOIN {$teamstep_table} ts_dest ON t.team_destination_id = ts_dest.id
              JOIN {$team_table} t_dest ON ts_dest.team_id = t_dest.id
              {$where_sql}
              ORDER BY t.transfer_date DESC, t.id DESC
              LIMIT %d OFFSET %d";

    $params[] = $limit;
    $params[] = $offset;

    $query = $wpdb->prepare( $query, $params );

    $results = $wpdb->get_results( $query );

    $formatted_transfers = array();
    foreach ( $results as $row ) {
        $formatted_transfers[] = array(
            'date'                  => date_i18n( 'd-m-y', strtotime( $row->transfer_date ) ),
            'player_id'             => $row->player_id,
            'player_name'           => $row->player_name,
            'player_nationality_id' => $row->player_nationality_id,
            'player_position'       => f365_get_position_display( $row->player_position ),
            'player_age'            => f365_calculate_age( $row->player_birth_date ),
            'player_url'            => home_url( '/jogador/' . $row->player_id . '/' ),
            
            'origin_team_id'        => $row->origin_team_id,
            'origin_team_name'      => $row->origin_team_name,
            'origin_team_country'   => $row->origin_team_country_name,
            'origin_team_url'       => $row->origin_team_id ? home_url( '/equipa/' . $row->origin_team_id . '/' ) : '',
            
            'destination_team_id'   => $row->destination_team_id,
            'destination_team_name' => $row->destination_team_name,
            'destination_team_country'=> $row->destination_team_country_name,
            'destination_team_url'  => home_url( '/equipa/' . $row->destination_team_id . '/' ),
            
            'transfer_type'         => f365_get_transfer_type_display( $row->transfer_type ),
            'value'                 => $row->value,
        );
    }

    return $formatted_transfers;
}


function f365_compare_get_team_ids_from_url(): array {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    if (preg_match('#/equipa/([0-9]+)/comparar-com/([0-9]+)/estadios/?#', $path, $m)) {
        return [
            'first_team_id'  => (int) $m[1],
            'second_team_id' => (int) $m[2],
        ];
    }

    return [
        'first_team_id'  => isset($_GET['first_team_id']) ? absint($_GET['first_team_id']) : 0,
        'second_team_id' => isset($_GET['second_team_id']) ? absint($_GET['second_team_id']) : 0,
    ];
}

function f365_compare_get_param(string $key, string $default): string {
    if (!isset($_GET[$key]) || $_GET[$key] === '') {
        return $default;
    }

    return sanitize_text_field(wp_unslash($_GET[$key]));
}

function f365_compare_valid_edition($edition): bool {
    return (bool) preg_match('/^[0-9]{4}$/', (string) $edition);
}

function f365_compare_get_team($team_id) {
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}team_team WHERE id = %d LIMIT 1",
            $team_id
        )
    );
}

function f365_compare_get_teamstep($team_id, $edition, $step, $gender, $modality) {
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT *
             FROM {$wpdb->prefix}team_teamstep
             WHERE team_id = %d
               AND edition = %s
               AND step = %s
               AND gender = %s
               AND modality = %s
             LIMIT 1",
            $team_id,
            $edition,
            $step,
            $gender,
            $modality
        )
    );
}

function f365_compare_get_stadium($stadium_id) {
    global $wpdb;

    if (!$stadium_id) {
        return null;
    }

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}stadium_stadium WHERE id = %d LIMIT 1",
            $stadium_id
        )
    );
}

function f365_compare_teamstep_name($team, $teamstep): string {
    if (!$team) {
        return '';
    }

    $name = (string) $team->shortname;
    $suffix = '';

    if ($teamstep) {
        if ($teamstep->modality === 'futsal') {
            $suffix .= ' FS';
        }

        if ($teamstep->gender === 'female') {
            $suffix .= ' FM';
        }

        if ($teamstep->step === 'b_team') {
            $suffix .= ' B';
        } elseif ($teamstep->step === 'c_team') {
            $suffix .= ' C';
        } elseif ($teamstep->step === 'veterans') {
            $suffix .= ' VET';
        } elseif ($teamstep->step === 'reservations') {
            $suffix .= ' RES';
        } elseif (
            $teamstep->step !== 'principal'
            && $teamstep->step !== ''
            && $teamstep->step !== null
        ) {
            $suffix .= ' ' . $teamstep->step;
        }
    }

    return trim($name . $suffix);
}

function f365_compare_stadium_for_team($team, $teamstep) {
    if ($teamstep && !empty($teamstep->stadium_id)) {
        return f365_compare_get_stadium((int) $teamstep->stadium_id);
    }

    if ($team && !empty($team->stadium_id)) {
        return f365_compare_get_stadium((int) $team->stadium_id);
    }

    return null;
}

function f365_compare_value($value): string {
    if ($value === null || $value === '') {
        return '-';
    }

    return esc_html($value);
}

function f365_compare_year($date): string {
    if (!$date || $date === '0000-00-00') {
        return '-';
    }

    $time = strtotime($date);

    return $time ? esc_html(date('Y', $time)) : '-';
}

function f365_compare_stadium_name($stadium): string {
    if (!$stadium) {
        return '-';
    }

    if (!empty($stadium->shortname)) {
        return esc_html($stadium->shortname);
    }

    if (!empty($stadium->longname)) {
        return esc_html($stadium->longname);
    }

    return '-';
}

function f365_compare_stadium_url($stadium): string {
    if (!$stadium || empty($stadium->id)) {
        return '#';
    }

    return home_url('/estadio/' . (int) $stadium->id . '/');
}

add_filter( 'wpseo_breadcrumb_links', 'f365_stadium_breadcrumb' );
function f365_stadium_breadcrumb( $links ) {

    if ( is_singular( 'f365_stadium' ) ) {

        global $post;

        $links = array(
            array(
                'url'  => home_url( '/' ),
                'text' => 'Home',
            ),
            array(
                'text' => get_the_title( $post->ID ),
            ),
        );
    }

    return $links;
}

/**
 * Optimize Poll Maker Gutenberg block queries on frontend.
 * Removes the plugin's original registration hook on the frontend to avoid the redundant
 * database query on every page load, and registers a lightweight block representation instead.
 */
add_action( 'init', 'f365_optimize_poll_maker_gutenberg_block', 5 );
function f365_optimize_poll_maker_gutenberg_block() {
    if ( ! is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        remove_action( 'init', 'ays_poll_gutenberg_block_register' );
        add_action( 'init', 'f365_register_lightweight_poll_maker_block', 12 );
    }
}

function f365_register_lightweight_poll_maker_block() {
    if ( function_exists( 'register_block_type' ) ) {
        register_block_type(
            'poll-maker/poll',
            array(
                'render_callback' => 'pollmaker_render_callback',
                'attributes'      => array(
                    'idner'          => array(),
                    'metaFieldValue' => array(
                        'type' => 'integer',
                    ),
                    'shortcode'      => array(
                        'type' => 'string',
                    ),
                    'className'      => array(
                        'type' => 'string',
                    ),
                    'openPopupId'    => array(
                        'type' => 'string',
                    ),
                ),
            )
        );
    }
}

/**
 * Optimize wp_posts query searching by GUID.
 * Since the GUID column in wp_posts is not indexed in WordPress, queries searching for
 * attachments by GUID trigger slow full table scans. This filter intercepts those queries,
 * finds the attachment ID using faster indexed postmeta queries, and caches the result.
 */
add_filter( 'query', 'futebol365_optimize_guid_queries' );
function futebol365_optimize_guid_queries( $query ) {
    // Check case-insensitively if the query selects ID from a table by guid
    if ( preg_match( '/SELECT\s+ID\s+FROM/i', $query ) && preg_match( '/WHERE\s+guid\s*=\s*/i', $query ) ) {
        // Extract the GUID from the query
        if ( preg_match( "/WHERE\s+guid\s*=\s*'([^']+)'/i", $query, $matches ) ) {
            $guid = $matches[1];
            
            // 1. Try resolving using cache
            $cache_key = 'f365_guid_id_' . md5( $guid );
            $cached_id = wp_cache_get( $cache_key, 'f365_guid_queries' );
            
            if ( false !== $cached_id ) {
                $id_to_select = (int) $cached_id;
                return "SELECT ID FROM {$GLOBALS['wpdb']->posts} WHERE ID = {$id_to_select}";
            }
            
            // 2. Use WordPress native attachment_url_to_postid which uses indexed fields
            $attachment_id = attachment_url_to_postid( $guid );
            
            // 3. Fallback: query postmeta _wp_attached_file directly which is indexed
            if ( ! $attachment_id ) {
                $uploads_dir = wp_upload_dir();
                $base_url    = $uploads_dir['baseurl'];
                if ( strpos( $guid, $base_url ) === 0 ) {
                    $relative_path = ltrim( str_replace( $base_url, '', $guid ), '/' );
                    $attachment_id = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare(
                        "SELECT post_id FROM {$GLOBALS['wpdb']->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
                        $relative_path
                    ) );
                }
            }
            
            // Cache the resolved ID (even 0 if not found) for 1 hour
            wp_cache_set( $cache_key, $attachment_id, 'f365_guid_queries', 3600 );
            
            $id_to_select = (int) $attachment_id;
            return "SELECT ID FROM {$GLOBALS['wpdb']->posts} WHERE ID = {$id_to_select}";
        }
    }
    return $query;
}

/**
 * Bypass main query on frontend search pages to prevent slow SQL search execution on wp_posts.
 */
add_filter( 'posts_pre_query', function ( $posts, $query ) {
    if ( ! is_admin() && $query->is_main_query() && $query->is_search() ) {
        return array();
    }
    return $posts;
}, 10, 2 );

/**
 * Cache the HTML output of the [ays_poll] shortcode to prevent heavy database queries on every page load.
 */
add_filter( 'pre_do_shortcode_tag', function( $output, $tag, $attr ) {
    if ( 'ays_poll' === $tag ) {
        $poll_id = isset( $attr['id'] ) ? (int) $attr['id'] : 0;
        if ( $poll_id > 0 ) {
            $cache_key = 'f365_poll_shortcode_cache_' . $poll_id;
            $cached    = get_transient( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }
    }
    return $output;
}, 10, 3 );

add_filter( 'do_shortcode_tag', function( $output, $tag, $attr ) {
    if ( 'ays_poll' === $tag ) {
        $poll_id = isset( $attr['id'] ) ? (int) $attr['id'] : 0;
        if ( $poll_id > 0 ) {
            $cache_key = 'f365_poll_shortcode_cache_' . $poll_id;
            // Cache the rendered HTML output for 1 hour
            set_transient( $cache_key, $output, HOUR_IN_SECONDS );
        }
    }
    return $output;
}, 10, 3 );