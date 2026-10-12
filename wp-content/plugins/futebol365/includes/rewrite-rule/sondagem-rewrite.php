<?php
/**
 * Futebol365 — Sondagem (Poll) Rewrite Rules & Shortcode
 *
 * Handles custom routing for poll detail pages and the [custom_poll] shortcode:
 *   - /sondagem/detalhes/{poll_id}/   (poll_id = AYS poll id; falls back to the
 *     WP sondagem post ID when no poll mapping exists)
 *
 * The `sondagem` CPT is registered in includes/cpt/register-cpt.php with
 * 'rewrite' => false; the status field is provided by includes/acf/acf-sondagem.php.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

/**
 * Meta key written by the ACF "Estado da Sondagem" toggle.
 */
const F365_SONDAGEM_ACTIVE_META = '_sondagem_is_active';

/**
 * -------------------------------------------------------
 * 1. REWRITE RULES
 * -------------------------------------------------------
 */
function f365_sondagem_rewrite_rules(): void {
    add_rewrite_rule(
        '^sondagem/detalhes/([0-9]+)/?$',
        'index.php?poll_id=$matches[1]',
        'top'
    );
}
add_action( 'init', 'f365_sondagem_rewrite_rules', 20 );


/**
 * -------------------------------------------------------
 * 2. QUERY VARS
 * -------------------------------------------------------
 */
add_filter( 'query_vars', 'f365_sondagem_query_vars' );

function f365_sondagem_query_vars( array $vars ): array {
    if ( ! in_array( 'poll_id', $vars, true ) ) {
        $vars[] = 'poll_id';
    }
    return $vars;
}


/**
 * -------------------------------------------------------
 * 3. PRE GET POSTS
 * -------------------------------------------------------
 */
add_action( 'pre_get_posts', 'f365_sondagem_pre_get_posts' );

function f365_sondagem_pre_get_posts( WP_Query $query ): void {

    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }

    $poll_id = (int) $query->get( 'poll_id' );

    if ( ! $poll_id ) {
        return;
    }

    // Resolve the poll ID to its sondagem post. If no poll mapping exists,
    // fall back to treating the value as a raw WordPress post ID.
    $wp_id = f365_sondagem_resolve_id( $poll_id ) ?? $poll_id;

    // Force the main query to resolve to this single sondagem post.
    $query->set( 'p', $wp_id );
    $query->set( 'post_type', 'sondagem' );
    $query->set( 'name', '' );

    // Flag adjustments to stop WordPress treating this as the home query and to
    // prevent canonical redirect loops on the virtual URL.
    $query->is_single   = true;
    $query->is_singular = true;
    $query->is_home     = false;
    $query->is_archive  = false;
    $query->is_page     = false;
}


/**
 * -------------------------------------------------------
 * 4. RESOLVE POLL ID → WP POST ID
 * -------------------------------------------------------
 * The importer stores the AYS poll id on each sondagem post as `_ays_poll_id`.
 */
function f365_sondagem_resolve_id( int $poll_id ): ?int {
    static $cache = [];

    if ( isset( $cache[ $poll_id ] ) ) {
        return $cache[ $poll_id ];
    }

    global $wpdb;

    $wp_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT post_id
         FROM {$wpdb->postmeta}
         WHERE meta_key = '_ays_poll_id'
           AND meta_value = %d
         LIMIT 1",
        $poll_id
    ) );

    $cache[ $poll_id ] = $wp_id ? (int) $wp_id : null;

    return $cache[ $poll_id ];
}


/**
 * -------------------------------------------------------
 * 5. PERMALINKS
 * -------------------------------------------------------
 * URLs are emitted with the poll ID, falling back to the post ID.
 */
add_filter( 'post_type_link', 'f365_sondagem_permalink', 10, 2 );

function f365_sondagem_permalink( string $post_link, WP_Post $post ): string {
    if ( 'sondagem' !== $post->post_type ) {
        return $post_link;
    }
    $poll_id = (int) get_post_meta( $post->ID, '_ays_poll_id', true );
    $final   = $poll_id ?: $post->ID;
    return user_trailingslashit( home_url( "sondagem/detalhes/{$final}" ) );
}

/**
 * Sondagem URL Helper. Emits the poll ID, falling back to the post ID.
 */
function f365_sondagem_url( int $post_id ): string {
    $poll_id = (int) get_post_meta( $post_id, '_ays_poll_id', true );
    $final   = $poll_id ?: $post_id;
    return user_trailingslashit( home_url( "sondagem/detalhes/{$final}" ) );
}


/**
 * -------------------------------------------------------
 * 6. [custom_poll] SHORTCODE
 * -------------------------------------------------------
 * Resolves the sondagem, then injects the appropriate AYS Poll Maker shortcode:
 *   - Active  ('1' or unset) → [ays_poll id='{row->id}']
 *   - Expired ('0')          → [ayspoll_results id='{row->id}' recent='false']
 *
 * Attributes:
 *   id   optional explicit sondagem post ID
 */
add_shortcode( 'custom_poll', 'f365_sondagem_custom_poll_shortcode' );

function f365_sondagem_custom_poll_shortcode( $atts ): string {

    $atts = shortcode_atts( [ 'id' => '' ], $atts, 'custom_poll' );

    // --- Resolve the post ID: id attr → poll_id query var → current post. ---
    if ( '' !== $atts['id'] && (int) $atts['id'] > 0 ) {
        $post_id = (int) $atts['id'];
    } else {
        $query_poll_id = (int) get_query_var( 'poll_id' );
        if ( $query_poll_id > 0 ) {
            $post_id = f365_sondagem_resolve_id( $query_poll_id ) ?? $query_poll_id;
        } else {
            $post_id = (int) get_the_ID();
        }
    }

    if ( $post_id <= 0 ) {
        return '';
    }

    // Confirm the resolved ID is actually a sondagem.
    if ( 'sondagem' !== get_post_type( $post_id ) ) {
        return '';
    }

    // --- Legacy AYS Poll Maker lookup. ---
    global $wpdb;
    $polls_table = $wpdb->prefix . 'ayspoll_polls';

    $ays_ref = (int) get_post_meta( $post_id, '_ays_poll_id', true );

    if ( $ays_ref <= 0 ) {
        $ays_ref = $post_id;
    }

    // Query specifically targets the custom field reference key
    $poll = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id FROM {$polls_table} WHERE post_id = %d LIMIT 1", 
            $ays_ref
        )
    );

    // Fallback: Check by primary key ID entry inside the AYS table
    if ( ! $poll ) {
        $poll = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$polls_table} WHERE id = %d LIMIT 1", 
                $ays_ref
            )
        );
    }

    if ( ! $poll ) {
        return '';
    }

    // Return ONLY the raw AYS Poll ID string
    return (string) $poll->id;
}