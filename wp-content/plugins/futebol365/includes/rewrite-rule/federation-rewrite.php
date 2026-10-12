<?php
/**
 * Futebol365 — Federation Rewrite Rules & Permalink Handling
 *
 * @package Futebol365
 * @since   2.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * -------------------------------------------------------
 * 1. REWRITE RULES
 * -------------------------------------------------------
 */

function f365_federation_rewrite_rules(): void {

    /**
     * Federation sections
     */

    $sections = [
        'competicoes',
        'equipas',
        'jogadores',
        'treinadores',
        'dirigentes',
        'estadios',
        'competicoes-regionais'
    ];

    foreach ( $sections as $section ) {

        add_rewrite_rule(
            '^federacao/([0-9]+)/' . $section . '/?$',
            'index.php?post_type=f365_federation&f365_fed_id=$matches[1]&f365_section=' . $section,
            'top'
        );
    }

    /**
     * Single federation
     * /federacao/{id}/
     */
    add_rewrite_rule(
        '^federacao/([0-9]+)/?$',
        'index.php?post_type=f365_federation&f365_fed_id=$matches[1]',
        'top'
    );
}

add_action( 'init', 'f365_federation_rewrite_rules', 20 );


/**
 * -------------------------------------------------------
 * 2. QUERY VARS
 * -------------------------------------------------------
 */

add_filter( 'query_vars', 'f365_federation_query_vars' );

function f365_federation_query_vars( array $vars ): array {

    $new = [
        'f365_fed_id',
        'f365_section',
    ];

    foreach ( $new as $var ) {

        if ( ! in_array( $var, $vars, true ) ) {
            $vars[] = $var;
        }
    }

    return $vars;
}


/**
 * -------------------------------------------------------
 * 3. PRE GET POSTS
 * -------------------------------------------------------
 */

add_action( 'pre_get_posts', 'f365_federation_pre_get_posts' );

function f365_federation_pre_get_posts( WP_Query $query ): void {

    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }

    $fed_id = (int) $query->get( 'f365_fed_id' );

    if ( ! $fed_id ) {
        return;
    }

    $wp_post_id = f365_federation_resolve_id( $fed_id );

    if ( $wp_post_id ) {

        $query->set( 'p', $wp_post_id );
        $query->set( 'post_type', 'f365_federation' );

    } else {

        /**
         * fallback to raw wp id
         */
        $query->set( 'p', $fed_id );
        $query->set( 'post_type', 'f365_federation' );
    }

    $query->set( 'name', '' );
    // Correct query flags for custom rewrite matching so that Yoast SEO and WP recognize it as singular
    $query->is_single            = true;
    $query->is_singular          = true;
    $query->is_archive           = false;
    $query->is_post_type_archive = false;
}


/**
 * -------------------------------------------------------
 * 4. RESOLVE DJANGO ID → WP ID
 * -------------------------------------------------------
 */

function f365_federation_resolve_id( int $fed_id ): ?int {

    static $cache = [];

    if ( isset( $cache[ $fed_id ] ) ) {
        return $cache[ $fed_id ];
    }

    global $wpdb;

    // Queries the custom legacy_id column from the wp_posts table directly
    $wp_id = $wpdb->get_var(
        $wpdb->prepare(
            "
            SELECT ID
            FROM {$wpdb->posts}
            WHERE legacy_id = %d
            AND post_type = 'f365_federation'
            LIMIT 1
            ",
            $fed_id
        )
    );

    $cache[ $fed_id ] = $wp_id ? (int) $wp_id : null;

    return $cache[ $fed_id ];
}


/**
 * -------------------------------------------------------
 * 5. PERMALINKS
 * -------------------------------------------------------
 */

add_filter( 'post_type_link', 'f365_federation_permalink', 10, 2 );

function f365_federation_permalink( string $post_link, WP_Post $post ): string {

    if ( 'f365_federation' !== $post->post_type ) {
        return $post_link;
    }

    // Accessing legacy_id column directly from the WP_Post object properties
    $legacy_id = isset( $post->legacy_id ) ? (int) $post->legacy_id : 0;
    $final_id  = $legacy_id ?: $post->ID;

    return user_trailingslashit(
        home_url( "federacao/{$final_id}" )
    );
}



/**
 * Federation URL helper
 */

function f365_federation_url( int $post_id, string $section = '' ): string {

    $post = get_post( $post_id );
    
    // Accessing legacy_id column directly from the fetched WP_Post object
    $legacy_id = ( $post && isset( $post->legacy_id ) ) ? (int) $post->legacy_id : 0;
    $final_id  = $legacy_id ?: $post_id;

    $path = "federacao/{$final_id}";

    if ( $section ) {
        $path .= '/' . trim( $section, '/' );
    }

    return user_trailingslashit(
        home_url( $path )
    );
}


/**
 * -------------------------------------------------------
 * 6. TEMPLATE LOADER
 * -------------------------------------------------------
 */

add_filter( 'template_include', 'f365_federation_template_include' );

function f365_federation_template_include( string $template ): string {

    $fed_id  = get_query_var( 'f365_fed_id' );
    $section = get_query_var( 'f365_section' );

    if ( ! $fed_id ) {
        return $template;
    }

    switch ( $section ) {

        case 'competicoes':
            $tpl = f365_locate_template( 'f365-federation-competicoes' );
            break;

        case 'equipas':
            $tpl = f365_locate_template( 'f365-federation-equipas' );
            break;

        case 'jogadores':
            $tpl = f365_locate_template( 'f365-federation-jogadores' );
            break;

        case 'treinadores':
            $tpl = f365_locate_template( 'f365-federation-treinadores' );
            break;

        case 'dirigentes':
            $tpl = f365_locate_template( 'f365-federation-dirigentes' );
            break;

        case 'estadios':
            $tpl = f365_locate_template( 'f365-federation-estadios' );
            break;

        case 'competicoes-regionais':
            $tpl = f365_locate_template( 'f365-federation-competicoes-regionais' );
            break;

        default:
            $tpl = f365_locate_template( 'single-f365_federation' );
            break;
    }

    return $tpl ?: $template;
}


/**
 * -------------------------------------------------------
 * 7. TEMPLATE LOCATOR
 * -------------------------------------------------------
 */

function f365_locate_template( string $name ): ?string {

    $template_paths = [
        "template-parts/federation/{$name}.php",
        "{$name}.php",
    ];

    /**
     * Theme override
     */
    $theme_file = locate_template(
        $template_paths,
        false,
        false
    );

    if ( $theme_file ) {
        return $theme_file;
    }

    /**
     * Plugin fallback
     */
    if ( defined( 'F365_PLUGIN_DIR' ) ) {

        $plugin_file = trailingslashit( F365_PLUGIN_DIR ) . "templates/federation-template/{$name}.php";

        if ( file_exists( $plugin_file ) ) {
            return $plugin_file;
        }
    }

    return null;
}

/**
 * -------------------------------------------------------
 * GET FEDERATION POST
 * -------------------------------------------------------
 */
function f365_get_current_federation_post(): ?WP_Post {

    $fed_id = (int) get_query_var('f365_fed_id');

    if (!$fed_id) {
        return null;
    }

    global $post;

    if ($post instanceof WP_Post && $post->post_type === 'f365_federation') {
        return $post;
    }

    $wp_post_id = f365_federation_resolve_id($fed_id);

    if (!$wp_post_id) {
        $wp_post_id = $fed_id;
    }

    return get_post($wp_post_id);
}

/**
 * Helper function to fetch and cache federation names from the custom table
 */
function f365_get_federation_names( int $legacy_id ): ?stdClass {
    static $runtime_cache = [];

    if ( ! $legacy_id ) {
        return null;
    }

    // If already fetched during this page load, return it from memory
    if ( isset( $runtime_cache[ $legacy_id ] ) ) {
        return $runtime_cache[ $legacy_id ];
    }

    global $wpdb;
    $fed_data = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT shortname, longname FROM {$wpdb->prefix}federation_federation WHERE id = %d LIMIT 1",
            $legacy_id
        )
    );

    // Cache the result (even if null/empty to prevent repeated failed lookups)
    $runtime_cache[ $legacy_id ] = $fed_data ?: null;

    return $runtime_cache[ $legacy_id ];
}

/**
 * Dynamic Yoast SEO title for federation pages
 */
function f365_federation_wpseo_title( $title ) {

    $fed_id = get_query_var( 'f365_fed_id' );

    if ( ! $fed_id ) {
        return $title;
    }

    global $post;

    if ( ! $post || $post->post_type !== 'f365_federation' ) {
        return $title;
    }

    $section = get_query_var( 'f365_section' );

    // Fetch names via cached helper function
    $fed_data  = f365_get_federation_names( (int) $fed_id );
    $shortname = ! empty( $fed_data->shortname ) ? $fed_data->shortname : '';
    $longname  = ! empty( $fed_data->longname ) ? $fed_data->longname : '';

    if ( ! $shortname ) {
        $shortname = get_the_title( $post->ID );
    }

    $parts = [];
    $parts[] = $shortname;

    if ( ! empty( $longname ) ) {
        $parts[] = $longname;
    }

    $section_labels = [
        'competicoes' => 'Competições',
        'equipas'     => 'Equipas',
        'jogadores'   => 'Jogadores',
        'treinadores' => 'Treinadores',
        'dirigentes'  => 'Dirigentes',
        'estadios'    => 'Estádios',
        'competicoes-regionais' => 'Competições',
    ];

    if ( ! empty( $section ) && isset( $section_labels[ $section ] ) ) {
        $parts[] = $section_labels[ $section ];
    }

    $parts[] = 'Futebol 365';

    return implode( ' - ', $parts );
}
add_filter( 'wpseo_title', 'f365_federation_wpseo_title', 20 );
add_filter( 'wpseo_opengraph_title', 'f365_federation_wpseo_title', 20 );
/**
 * -------------------------------------------------------
 * SEO DESCRIPTION
 * -------------------------------------------------------
 */
function f365_dynamic_federation_description( string $desc = '' ): string {

    $post = f365_get_current_federation_post();

    if ( ! $post ) {
        return $desc; // not a federation page — leave other modules / Yoast untouched
    }

    $legacy_id = isset( $post->legacy_id ) ? (int) $post->legacy_id : (int) get_query_var( 'f365_fed_id' );

    $shortname = '';
    $longname  = '';

    if ( $legacy_id ) {
        // Fetch names via cached helper function (will instantly load from memory)
        $fed_data  = f365_get_federation_names( $legacy_id );
        $shortname = ! empty( $fed_data->shortname ) ? $fed_data->shortname : '';
        $longname  = ! empty( $fed_data->longname ) ? $fed_data->longname : '';
    }

    if ( ! $shortname ) {
        $shortname = get_the_title( $post );
    }

    return sprintf(
        'Toda a informação sobre %s - %s. Competições, equipas, jogadores, treinadores, dirigentes, estádios e muito mais!',
        $shortname,
        $longname
    );
}
add_filter( 'wpseo_metadesc', 'f365_dynamic_federation_description', 20);
add_filter( 'wpseo_opengraph_desc', 'f365_dynamic_federation_description', 20 );

/**
 * -------------------------------------------------------
 * YOAST SEO — wpseo_canonical (self-referencing)
 *
 * Without this, Yoast emits a canonical with the federation id dropped
 * (e.g. /federacao/ instead of /federacao/5/) on the base page, and the
 * generic f365_yoast_canonical_override() cannot fix the base because no
 * section is present. Rebuild the canonical from the URL query vars so it
 * always matches the real URL. Priority 40 — after the generic override (20)
 * and the pagination canonical (30) — fully replacing their output here.
 *
 *   Base:    /federacao/{id}/
 *   Section: /federacao/{id}/{section}/
 * -------------------------------------------------------
 */
add_filter( 'wpseo_canonical', 'f365_federation_wpseo_canonical', 40 );
add_filter( 'wpseo_opengraph_url', 'f365_federation_wpseo_canonical', 40 );

function f365_federation_wpseo_canonical( $canonical ) {

    $fed_id = (int) get_query_var( 'f365_fed_id' );
    if ( ! $fed_id ) {
        return $canonical;
    }

    $section = (string) get_query_var( 'f365_section' );

    $path = "federacao/{$fed_id}";
    if ( $section !== '' ) {
        $path .= '/' . $section;
    }

    return user_trailingslashit( home_url( $path ) );
}
