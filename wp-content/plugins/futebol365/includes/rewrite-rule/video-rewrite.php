<?php
/**
 * Futebol365 — Video Rewrite Rules & Permalink Handling
 *
 * Handles custom routing for videos:
 * - /multimedia/videos/
 * - /multimedia/videos/mais-recentes/
 * - /multimedia/videos/destaques/
 * - /multimedia/videos/mais-vistos/
 * - /multimedia/videos/{id}/ (Single video)
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;


/**
 * 1. REWRITE RULES
 */
function f365_video_rewrite_rules(): void {
    // Section archives (mais-recentes, destaques, mais-vistos) with pagination
    add_rewrite_rule(
        '^multimedia/videos/(mais-recentes|destaques|mais-vistos)/page/([0-9]+)/?$',
        'index.php?post_type=f365_video&f365_section=$matches[1]&paged=$matches[2]',
        'top'
    );

    add_rewrite_rule(
        '^multimedia/videos/(mais-recentes|destaques|mais-vistos)/?$',
        'index.php?post_type=f365_video&f365_section=$matches[1]',
        'top'
    );

    // Singular video rewrite rule using Django video ID
    add_rewrite_rule(
        '^multimedia/videos/([0-9]+)/?$',
        'index.php?post_type=f365_video&f365_video_id=$matches[1]',
        'top'
    );
}
add_action( 'init', 'f365_video_rewrite_rules', 20 );

/**
 * Filter the rewrite rules array to remove the theme's legacy video rewrite rules,
 * ensuring they do not override the new custom post type archive and section pages.
 */
add_filter( 'rewrite_rules_array', 'f365_video_filter_rewrite_rules', 999 );

function f365_video_filter_rewrite_rules( array $rules ): array {
    foreach ( $rules as $regex => $redirect ) {
        if ( strpos( $redirect, 'multimedia_type=videos' ) !== false ) {
            unset( $rules[ $regex ] );
        }
    }
    return $rules;
}

/**
 * 2. QUERY VARS
 */
add_filter( 'query_vars', 'f365_video_query_vars' );

function f365_video_query_vars( array $vars ): array {
    foreach ( [ 'f365_video_id', 'f365_section' ] as $v ) {
        if ( ! in_array( $v, $vars, true ) ) {
            $vars[] = $v;
        }
    }
    return $vars;
}


/**
 * 4. RESOLVE DJANGO ID → WP ID (Read-only lookup)
 */
function f365_video_resolve_id( int $video_id ): ?int {
    static $cache = [];

    if ( isset( $cache[ $video_id ] ) ) {
        return $cache[ $video_id ];
    }

    global $wpdb;

    // Primary: Fast indexed lookup via legacy_table column
    $wp_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE legacy_table = 'multimedia_video' AND legacy_id = %d LIMIT 1",
            $video_id
        )
    );

    if ( ! $wp_id ) {
        // Fallback: postmeta lookup (read-only — no auto-write on page visit)
        $wp_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'django_video_id' AND meta_value = %d LIMIT 1",
                $video_id
            )
        );
        // NOTE: legacy_table self-heal removed. Run 'wp f365 migrate videos' to backfill.
    }

    $cache[ $video_id ] = $wp_id ? (int) $wp_id : null;

    return $cache[ $video_id ];
}


/**
 * 5. PRE GET POSTS — ID resolution + section ordering
 */
function f365_video_pre_get_posts( WP_Query $query ): void {

    // Singular: Django video id → WP post id.
    $video_id = (int) $query->get( 'f365_video_id' );
    if ( $video_id ) {
        $wp_post_id = f365_video_resolve_id( $video_id ) ?: $video_id;
        $query->set( 'post_type', 'f365_video' );
        $query->set( 'p', $wp_post_id );
        $query->set( 'name', '' );
        return;
    }

    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }

    // Archive/section: the template renders from f365_video_page(); make the
    $is_video_archive = ( $query->get( 'post_type' ) === 'f365_video' )
        || $query->is_post_type_archive( 'f365_video' );
    if ( $is_video_archive ) {
        $query->set( 'posts_per_page', 1 );
        $query->set( 'no_found_rows', true );
        $query->set( 'ignore_sticky_posts', true );
    }
}
add_action( 'pre_get_posts', 'f365_video_pre_get_posts' );



if ( ! function_exists( 'f365_video_legacy_id' ) ) {
	function f365_video_legacy_id( int $post_id ): int {
		static $cache = [];
		if ( array_key_exists( $post_id, $cache ) ) {
			return $cache[ $post_id ];
		}
		if ( $post_id <= 0 ) {
			return $cache[ $post_id ] = 0;
		}
		global $wpdb;
		$legacy_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT legacy_id FROM {$wpdb->posts} WHERE ID = %d AND legacy_table = 'multimedia_video' LIMIT 1",
			$post_id
		) );
		if ( $legacy_id <= 0 ) {
			$meta = get_post_meta( $post_id, 'django_video_id', true );
			$legacy_id = is_numeric( $meta ) ? (int) $meta : 0;
		}
		return $cache[ $post_id ] = $legacy_id;
	}
}

function f365_video_locate_template( string $name ): ?string {
	$theme_file = locate_template(
		[ "template-parts/video/{$name}.php", "{$name}.php" ],
		false,
		false
	);
	if ( $theme_file ) {
		return $theme_file;
	}
	if ( defined( 'F365_PLUGIN_DIR' ) ) {
		$plugin_file = trailingslashit( F365_PLUGIN_DIR ) . "templates/video-template/{$name}.php";
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
 
 
/* ===========================================================================
 * 7. SEO — single video title/description from the CUSTOM TABLE. Cached.
 * =========================================================================== */
if ( ! function_exists( 'f365_video_current_post_id' ) ) {
	function f365_video_current_post_id(): int {
		if ( ! is_singular( 'f365_video' ) ) {
			return 0;
		}
		$id = get_queried_object_id();
		return $id > 0 ? (int) $id : 0;
	}
}
 
function f365_video_seo_data( int $post_id ): array {
	static $memo = [];
	if ( array_key_exists( $post_id, $memo ) ) {
		return $memo[ $post_id ];
	}
	if ( $post_id <= 0 ) {
		return $memo[ $post_id ] = [];
	}
 
	$cache_key = 'f365_video_seo_' . $post_id;
	$cached    = wp_cache_get( $cache_key, 'f365' );
	if ( is_array( $cached ) ) {
		return $memo[ $post_id ] = $cached;
	}
 
	$legacy_id = f365_video_legacy_id( $post_id );
	if ( $legacy_id <= 0 ) {
		return $memo[ $post_id ] = [];
	}
 
	global $wpdb;
	$T_VIDEO = $wpdb->prefix . 'multimedia_video';
	if ( ! function_exists( 'f365_src_table_exists' ) || ! f365_src_table_exists( $T_VIDEO ) ) {
		return $memo[ $post_id ] = [];
	}
 
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT title FROM {$T_VIDEO} WHERE id = %d LIMIT 1",
		$legacy_id
	), ARRAY_A );
 
	if ( $wpdb->last_error !== '' || ! is_array( $row ) ) {
		return $memo[ $post_id ] = [];
	}
 
	$data = [ 'title' => isset( $row['title'] ) ? (string) $row['title'] : '' ];
	wp_cache_set( $cache_key, $data, 'f365', 12 * HOUR_IN_SECONDS );
	return $memo[ $post_id ] = $data;
}
 
/** Is the current request a video archive (list or section)? */
function f365_video_is_archive(): bool {
	if ( is_post_type_archive( 'f365_video' ) ) {
		return true;
	}
	return ( (string) get_query_var( 'post_type' ) === 'f365_video' )
		&& ( get_query_var( 'f365_video_id' ) === '' || (int) get_query_var( 'f365_video_id' ) === 0 );
}
 
function f365_video_wpseo_title( string $title ): string {
    $post_id = f365_video_current_post_id();
    if ( $post_id > 0 ) {
        $data        = f365_video_seo_data( $post_id );
        $video_title = ( ! empty( $data['title'] ) ) ? $data['title'] : get_the_title( $post_id );
        return 'VIDEO - ' . $video_title . ' - Futebol 365';
    }
 
    // List + all section archives share one title (source video_list.html).
    if ( f365_video_is_archive() ) {
        // Get the active subsection via query vars
        $section = get_query_var( 'f365_section' );

        // Map section keys to their proper human-readable titles
        $section_titles = [
            'mais-recentes' => 'Vídeos: Mais Recentes',
            'destaques'     => 'Vídeos: Em Destaque',
            'mais-vistos'   => 'Vídeos: Mais Vistos',
        ];

        if ( ! empty( $section ) && isset( $section_titles[ $section ] ) ) {
            return $section_titles[ $section ] . ' - Futebol 365';
        }

        // Fallback for general video archive /multimedia/videos/
        return 'Vídeos - Futebol 365';
    }
 
    return $title;
}
add_filter( 'wpseo_title', 'f365_video_wpseo_title', 20 );
add_filter( 'wpseo_opengraph_title', 'f365_video_wpseo_title', 20 );

 
function f365_video_wpseo_metadesc( string $desc ): string {
	$post_id = f365_video_current_post_id();
	if ( $post_id > 0 ) {
		$data        = f365_video_seo_data( $post_id );
		$video_title = ( ! empty( $data['title'] ) ) ? $data['title'] : get_the_title( $post_id );
		// Source: "Assista ao vídeo {title}" — no trailing period, no suffix.
		return 'Assista ao vídeo ' . $video_title;
	}
 
	if ( f365_video_is_archive() ) {
		return 'Veja os mais recentes vídeos de futebol na galeria de vídeos do Futebol 365.';
	}
 
	return $desc;
}
add_filter( 'wpseo_metadesc', 'f365_video_wpseo_metadesc', 20 );
add_filter( 'wpseo_opengraph_desc', 'f365_video_wpseo_metadesc', 20 );
