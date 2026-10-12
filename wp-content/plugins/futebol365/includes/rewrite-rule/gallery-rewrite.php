<?php
/**
 * Futebol365 — Photo Gallery Rewrite Rules & Permalink Handling
 *
 * Handles custom routing for photo galleries:
 * - /multimedia/fotogalerias/
 * - /multimedia/fotogalerias/mais-vistas/
 * - /multimedia/fotogalerias/destaques/
 * - /multimedia/fotogaleria/{id}/  (Native WP singular)
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

/**
 * -------------------------------------------------------
 * 1. REWRITE RULES
 * -------------------------------------------------------
 */
function f365_gallery_rewrite_rules(): void {
    // Section archives (mais-vistas and destaques) with pagination
    add_rewrite_rule(
        '^multimedia/fotogalerias/(mais-vistas|destaques)/page/([0-9]+)/?$',
        'index.php?post_type=f365_gallery&f365_section=$matches[1]&paged=$matches[2]',
        'top'
    );

    add_rewrite_rule(
        '^multimedia/fotogalerias/(mais-vistas|destaques)/?$',
        'index.php?post_type=f365_gallery&f365_section=$matches[1]',
        'top'
    );
}
add_action( 'init', 'f365_gallery_rewrite_rules', 20 );

/**
 * Filter the rewrite rules array to remove the theme's legacy gallery rewrite rules,
 * ensuring they do not override the new custom post type archive and section pages.
 */
add_filter( 'rewrite_rules_array', 'f365_gallery_filter_rewrite_rules', 999 );

function f365_gallery_filter_rewrite_rules( array $rules ): array {
    foreach ( $rules as $regex => $redirect ) {
        if ( strpos( $redirect, 'multimedia_type=fotogalerias' ) !== false ) {
            unset( $rules[ $regex ] );
        }
    }
    return $rules;
}


/**
 * -------------------------------------------------------
 * 2. QUERY VARS
 * -------------------------------------------------------
 */
add_filter( 'query_vars', 'f365_gallery_query_vars' );

function f365_gallery_query_vars( array $vars ): array {
    if ( ! in_array( 'f365_section', $vars, true ) ) {
        $vars[] = 'f365_section';
    }
    return $vars;
}


/**
 * -------------------------------------------------------
 * 3. PRE GET POSTS
 * -------------------------------------------------------
 */
add_action( 'pre_get_posts', 'f365_gallery_pre_get_posts' );

function f365_gallery_pre_get_posts( WP_Query $query ): void {
    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }

    // Handle section archives (mais-vistas and destaques) on f365_gallery post type
    if ( $query->get( 'post_type' ) === 'f365_gallery' || $query->is_post_type_archive( 'f365_gallery' ) ) {
        $section = $query->get( 'f365_section' );
        if ( $section === 'mais-vistas' ) {
            $query->set( 'meta_key', 'views' );
            $query->set( 'orderby', 'meta_value_num' );
            $query->set( 'order', 'DESC' );
        } elseif ( $section === 'destaques' ) {
            $query->set( 'meta_key', 'highlight' );
            $query->set( 'meta_value', '1' );
            $query->set( 'meta_compare', '=' );
        }
    }
}


/**
 * -------------------------------------------------------
 * 4. GET CURRENT GALLERY POST
 * -------------------------------------------------------
 */
function f365_get_current_gallery_post(): ?WP_Post {
    global $post;

    if ( $post instanceof WP_Post && $post->post_type === 'f365_gallery' ) {
        return $post;
    }

    return null;
}


/**
 * -------------------------------------------------------
 * 5. TEMPLATE LOADER
 * -------------------------------------------------------
 */


/**
 * -------------------------------------------------------
 * 6. TEMPLATE LOCATOR
 * -------------------------------------------------------
 */
function f365_gallery_locate_template( string $name ): ?string {
    /**
     * Theme override
     */
    $theme_file = locate_template(
        [ "template-parts/gallery/{$name}.php", "{$name}.php" ],
        false,
        false
    );

    if ( $theme_file ) {
        return $theme_file;
    }

    /**
     * Plugin template
     */
    if ( defined( 'F365_PLUGIN_DIR' ) ) {
        $plugin_file = trailingslashit( F365_PLUGIN_DIR ) . "templates/gallery-template/{$name}.php";
        if ( file_exists( $plugin_file ) ) {
            return $plugin_file;
        }

        $fallback_file = trailingslashit( F365_PLUGIN_DIR ) . "templates/{$name}.php";
        if ( file_exists( $fallback_file ) ) {
            return $fallback_file;
        }
    }

    return null;
}


/**
 * -------------------------------------------------------
 * 7. YOAST SEO TITLE FILTER
 * -------------------------------------------------------
 */
function f365_gallery_wpseo_title( string $title ): string {
    $section = get_query_var( 'f365_section' );
    $post_type = get_query_var( 'post_type' );
    if ( is_post_type_archive( 'f365_gallery' ) || ( 'f365_gallery' === $post_type && $section ) ) {
        if ( $section === 'mais-vistas' ) {
            return 'Fotogalerias: Mais vistas - Futebol 365';
        } elseif ( $section === 'destaques' ) {
            return 'Fotogalerias: Em Destaque - Futebol 365';
        }
        return 'Fotogalerias: Últimas - Futebol 365';
    }

    global $post;
    if ( is_singular( 'f365_gallery' ) && $post ) {
        return 'FOTOGALERIA - ' . get_the_title( $post ) . ' - Futebol 365';
    }

    return $title;
}
add_filter( 'wpseo_title', 'f365_gallery_wpseo_title', 20 );


/**
 * -------------------------------------------------------
 * 8. YOAST SEO METADESC FILTER
 * -------------------------------------------------------
 */
function f365_gallery_wpseo_metadesc( string $desc ): string {
    $section = get_query_var( 'f365_section' );
    $post_type = get_query_var( 'post_type' );
    if ( is_post_type_archive( 'f365_gallery' ) || ( 'f365_gallery' === $post_type && $section ) ) {
        return 'Veja as mais recentes fotografias de futebol captadas pelos melhores fotógrafos desportivos do mundo na galeria de imagens do Futebol 365.';
    }

    return $desc;
}
add_filter( 'wpseo_metadesc', 'f365_gallery_wpseo_metadesc', 20 );
