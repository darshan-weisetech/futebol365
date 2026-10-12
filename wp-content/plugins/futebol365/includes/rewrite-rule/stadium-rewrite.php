<?php
/**
 * Futebol365 — Stadium Rewrite Rules & Permalink Handling
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

/**
 * -------------------------------------------------------
 * 1. REWRITE RULES
 * -------------------------------------------------------
 */
function f365_stadium_rewrite_rules(): void {
    $sections = [
        'ultimos-jogos',
        'proximos-jogos',
        'mapa',
        'info',
    ];

    foreach ( $sections as $section ) {
        add_rewrite_rule(
            '^estadio/([0-9]+)/' . $section . '/?$',
            'index.php?post_type=f365_stadium&f365_stadium_id=$matches[1]&f365_section=' . $section,
            'top'
        );
    }

    add_rewrite_rule(
        '^estadio/([0-9]+)/?$',
        'index.php?post_type=f365_stadium&f365_stadium_id=$matches[1]',
        'top'
    );
}
add_action( 'init', 'f365_stadium_rewrite_rules', 20 );

/**
 * -------------------------------------------------------
 * 2. QUERY VARS
 * -------------------------------------------------------
 */
add_filter( 'query_vars', 'f365_stadium_query_vars' );
function f365_stadium_query_vars( array $vars ): array {
    $new = [
        'f365_stadium_id',
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
add_action( 'pre_get_posts', 'f365_stadium_pre_get_posts' );
function f365_stadium_pre_get_posts( WP_Query $query ): void {
    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }

    $stadium_id = (int) $query->get( 'f365_stadium_id' );

    if ( ! $stadium_id ) {
        return;
    }

    $wp_post_id = f365_stadium_resolve_id( $stadium_id );

    if ( $wp_post_id ) {
        $query->set( 'p', $wp_post_id );
        $query->set( 'post_type', 'f365_stadium' );
    } else {
        $query->set( 'p', $stadium_id );
        $query->set( 'post_type', 'f365_stadium' );
    }

    $query->set( 'name', '' );
}

/**
 * -------------------------------------------------------
 * 4. RESOLVE DJANGO ID → WP ID
 * -------------------------------------------------------
 */
function f365_stadium_resolve_id( int $stadium_id ): ?int {
    static $cache = [];

    if ( isset( $cache[ $stadium_id ] ) ) {
        return $cache[ $stadium_id ];
    }

    global $wpdb;

    $wp_id = $wpdb->get_var(
        $wpdb->prepare(
            "
            SELECT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'f365_stadium'
            AND ( (pm.meta_key = 'django_stadium_id' AND pm.meta_value = %d) OR (pm.meta_key = 'django_id' AND pm.meta_value = %d) OR p.legacy_id = %d )
            LIMIT 1
            ",
            $stadium_id,
            $stadium_id,
            $stadium_id
        )
    );

    $cache[ $stadium_id ] = $wp_id ? (int) $wp_id : null;

    return $cache[ $stadium_id ];
}

/**
 * -------------------------------------------------------
 * 5. PERMALINKS
 * -------------------------------------------------------
 */
add_filter( 'post_type_link', 'f365_stadium_permalink', 9999, 3 );
function f365_stadium_permalink( string $post_link, WP_Post $post ): string {
    if ( 'f365_stadium' !== $post->post_type ) {
        return $post_link;
    }

    $django_id = (int) get_post_meta( $post->ID, 'django_stadium_id', true );
    $final_id = $django_id ?: $post->ID;

    return user_trailingslashit( home_url( "estadio/{$final_id}" ) );
}

function f365_stadium_url( int $post_id, string $section = '' ): string {
    $django_id = (int) get_post_meta( $post_id, 'django_stadium_id', true );
    $final_id = $django_id ?: $post_id;

    $path = "estadio/{$final_id}";

    if ( $section ) {
        $path .= '/' . trim( $section, '/' );
    }

    return user_trailingslashit( home_url( $path ) );
}

/**
 * -------------------------------------------------------
 * 6. TEMPLATE LOADER
 * -------------------------------------------------------
 */
add_filter( 'template_include', 'f365_stadium_template_include' );
function f365_stadium_template_include( string $template ): string {
    $stadium_id = get_query_var( 'f365_stadium_id' );
    $section    = get_query_var( 'f365_section' );

    if ( ! $stadium_id ) {
        return $template;
    }

    switch ( $section ) {
        case 'ultimos-jogos':
            $tpl = f365_stadium_locate_template( 'f365-stadium-ultimos-jogos' );
            break;
        case 'proximos-jogos':
            $tpl = f365_stadium_locate_template( 'f365-stadium-proximos-jogos' );
            break;
        case 'mapa':
            $tpl = f365_stadium_locate_template( 'f365-stadium-mapa' );
            break;
        case 'info':
            $tpl = f365_stadium_locate_template( 'f365-stadium-info' );
            break;
        default:
            $tpl = f365_stadium_locate_template( 'single-f365_stadium' );
            break;
    }

    return $tpl ?: $template;
}

/**
 * -------------------------------------------------------
 * TEMPLATE LOCATOR
 * -------------------------------------------------------
 */
function f365_stadium_locate_template( string $name ): ?string {

    $template_paths = [
        "template-parts/stadium/{$name}.php",
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

        $plugin_file = trailingslashit( F365_PLUGIN_DIR ) . "templates/stadium-template/{$name}.php";

        if ( file_exists( $plugin_file ) ) {
            return $plugin_file;
        }
    }

    return null;
}

/**
 * -------------------------------------------------------
 * GET STADIUM POST
 * -------------------------------------------------------
 */
function f365_get_current_stadium_post(): ?WP_Post {
    $stadium_id = (int) get_query_var( 'f365_stadium_id' );

    if ( ! $stadium_id ) {
        return null;
    }

    global $post;

    if ( $post instanceof WP_Post && $post->post_type === 'f365_stadium' ) {
        return $post;
    }

    $wp_post_id = f365_stadium_resolve_id( $stadium_id );

    if ( ! $wp_post_id ) {
        $wp_post_id = $stadium_id;
    }

    return get_post( $wp_post_id );
}


/**
 * -------------------------------------------------------
 * SEO NAME SOURCE — shortname + longname from the cloned table
 * {prefix}stadium_stadium (NOT wp_postmeta / post_title).
 * Keyed on the Django stadium id held in 'django_stadium_id'.
 *
 * Source (stadium_base.html) renders the display name as:
 *   {% if stadium.longname|length %}{{ longname }}{% else %}{{ shortname }}{% endif %}
 * i.e. longname when present, otherwise shortname. Both the title and the
 * description use this same display name.
 * -------------------------------------------------------
 */
if ( ! function_exists( 'f365_stadium_seo_data' ) ) {
    function f365_stadium_seo_data( int $post_id ): array {
        static $memo = [];

        if ( $post_id <= 0 ) {
            return [ 'shortname' => '', 'longname' => '' ];
        }
        if ( array_key_exists( $post_id, $memo ) ) {
            return $memo[ $post_id ];
        }

        $django_id = (int) get_post_meta( $post_id, 'django_stadium_id', true );
        if ( $django_id <= 0 ) {
            return $memo[ $post_id ] = [ 'shortname' => '', 'longname' => '' ];
        }

        $cache_key = 'f365_stadium_seo_' . $django_id;
        $cached    = wp_cache_get( $cache_key, 'f365' );
        if ( is_array( $cached ) ) {
            return $memo[ $post_id ] = $cached;
        }

        global $wpdb;
        $T = $wpdb->prefix . 'stadium_stadium';
        if ( function_exists( 'f365_src_table_exists' ) && ! f365_src_table_exists( $T ) ) {
            return $memo[ $post_id ] = [ 'shortname' => '', 'longname' => '' ];
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT shortname, longname FROM {$T} WHERE id = %d LIMIT 1",
            $django_id
        ), ARRAY_A );

        if ( $wpdb->last_error !== '' || ! is_array( $row ) ) {
            return $memo[ $post_id ] = [ 'shortname' => '', 'longname' => '' ]; // do not cache errors
        }

        $data = [
            'shortname' => isset( $row['shortname'] ) ? (string) $row['shortname'] : '',
            'longname'  => isset( $row['longname'] ) ? (string) $row['longname'] : '',
        ];

        if ( $data['shortname'] === '' && $data['longname'] === '' ) {
            return $memo[ $post_id ] = $data; // do not cache empties
        }

        wp_cache_set( $cache_key, $data, 'f365', 12 * HOUR_IN_SECONDS );
        return $memo[ $post_id ] = $data;
    }
}

if ( ! function_exists( 'f365_stadium_seo_name' ) ) {
    function f365_stadium_seo_name( int $post_id ): string {
        return f365_stadium_seo_data( $post_id )['shortname'];
    }
}

if ( ! function_exists( 'f365_stadium_seo_display_name' ) ) {
    function f365_stadium_seo_display_name( int $post_id ): string {
        $d = f365_stadium_seo_data( $post_id );
        return ( $d['longname'] !== '' ) ? $d['longname'] : $d['shortname'];
    }
}

/**
 * Section label map (source parity). Keys are f365_section query var values;
 * the base page (no section) falls back to 'Resumo' (stadium_detail.html).
 *   ultimos-jogos  → stadium_last_matches.html  → 'Últimos Jogos'
 *   proximos-jogos → stadium_next_matches.html  → 'Próximos jogos'
 *   mapa           → stadium_map.html           → 'Mapa'
 *   info           → stadium_info.html          → 'Dados Gerais'
 */
function f365_stadium_section_title_labels(): array {
    return [
        'ultimos-jogos'  => 'Últimos Jogos',
        'proximos-jogos' => 'Próximos jogos',
        'mapa'           => 'Mapa',
        'info'           => 'Dados Gerais',
    ];
}

/**
 * -------------------------------------------------------
 * SEO TITLE
 *
 * Source: {display} - {section label} - Futebol 365
 *   stadium_base.html  → block title = display name (longname|shortname)
 *   section templates  → {{block.super}} - {label}
 *   website/base.html  → "<title>{% block title %} - Futebol 365</title>"
 * -------------------------------------------------------
 */
function f365_stadium_wpseo_title( $title ) {
    $stadium_id = (int) get_query_var( 'f365_stadium_id' );
    if ( ! $stadium_id ) {
        return $title;
    }

    global $post;
    if ( ! ( $post instanceof WP_Post ) || $post->post_type !== 'f365_stadium' ) {
        return $title;
    }

    $display = f365_stadium_seo_display_name( $post->ID );
    if ( $display === '' ) {
        $display = get_the_title( $post->ID );
    }

    $section = (string) get_query_var( 'f365_section' );
    $labels  = f365_stadium_section_title_labels();
    $label   = $labels[ $section ] ?? 'Resumo';

    return $display . ' - ' . $label . ' - Futebol 365';
}
add_filter( 'wpseo_title', 'f365_stadium_wpseo_title', 20 );
add_filter( 'wpseo_opengraph_title', 'f365_stadium_wpseo_title', 20 );
add_filter( 'wpseo_twitter_title', 'f365_stadium_wpseo_title', 20 );

/**
 * -------------------------------------------------------
 * SEO DESCRIPTION
 *
 * Source (stadium_base.html, inherited by every section since none override
 * {% block description %}):
 *   Toda a informação sobre {display}.
 * -------------------------------------------------------
 */
function f365_dynamic_stadium_description( string $desc ): string {

    $post = f365_get_current_stadium_post();

    // Not a stadium page → leave the description as-is (do NOT substitute the
    // site/home description; a missing description must stay missing).
    if ( ! $post ) {
        return $desc;
    }

    $display = f365_stadium_seo_display_name( $post->ID );
    if ( $display === '' ) {
        $display = get_the_title( $post );
    }

    return sprintf(
        'Toda a informação sobre %s.',
        $display
    );
}
add_filter( 'wpseo_metadesc', 'f365_dynamic_stadium_description', 20 );
add_filter( 'wpseo_opengraph_desc', 'f365_dynamic_stadium_description', 20 );
add_filter( 'wpseo_twitter_desc', 'f365_dynamic_stadium_description', 20 );

/**
 * -------------------------------------------------------
 * SEO CANONICAL + OG:URL (self-referencing)
 *
 * URL shape mirrors the rewrite rules:
 *   base:    /estadio/{id}/
 *   section: /estadio/{id}/{section}/
 *
 * {id} is the f365_stadium_id URL segment (the Django id), so the canonical
 * always equals the real URL. Priority 40 runs after the generic override (20)
 * and the pagination canonical (30), fully replacing their output here.
 * -------------------------------------------------------
 */
function f365_stadium_wpseo_canonical( $canonical ) {
    $stadium_id = (int) get_query_var( 'f365_stadium_id' );
    if ( ! $stadium_id ) {
        return $canonical;
    }

    $section = (string) get_query_var( 'f365_section' );

    $path = "estadio/{$stadium_id}";
    if ( $section !== '' ) {
        $path .= '/' . $section;
    }

    return user_trailingslashit( home_url( $path ) );
}
add_filter( 'wpseo_canonical', 'f365_stadium_wpseo_canonical', 40 );
add_filter( 'wpseo_opengraph_url', 'f365_stadium_wpseo_canonical', 40 );