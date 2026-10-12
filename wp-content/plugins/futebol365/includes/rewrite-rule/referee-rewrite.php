<?php
/**
 * Futebol365 — Referee Rewrite Rules & Permalink Handling
 *
 * Handles custom routing for referee pages and their sub-sections:
 * - /arbitro/{id}/
 * - /arbitro/{id}/jogos/
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

/**
 * -------------------------------------------------------
 * 1. REWRITE RULES
 * -------------------------------------------------------
 */
function f365_referee_rewrite_rules(): void {

    $sections = [
        'jogos',
    ];

    foreach ( $sections as $section ) {
        add_rewrite_rule(
            '^arbitro/([0-9]+)/' . $section . '/?$',
            'index.php?post_type=f365_referee&f365_referee_id=$matches[1]&f365_section=' . $section,
            'top'
        );
    }

    add_rewrite_rule(
        '^arbitro/([0-9]+)/?$',
        'index.php?post_type=f365_referee&f365_referee_id=$matches[1]',
        'top'
    );

    add_rewrite_rule(
        '^arbitro/pais/([^/]+)/?$',
        'index.php?post_type=f365_referee&f365_country=$matches[1]',
        'top'
    );
}
add_action( 'init', 'f365_referee_rewrite_rules', 20 );


/**
 * -------------------------------------------------------
 * 2. QUERY VARS
 * -------------------------------------------------------
 */
add_filter( 'query_vars', 'f365_referee_query_vars' );

function f365_referee_query_vars( array $vars ): array {
    $new = [
        'f365_referee_id',
        'f365_section',
        'f365_country',
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
add_action( 'pre_get_posts', 'f365_referee_pre_get_posts' );

function f365_referee_pre_get_posts( WP_Query $query ): void {

    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }

    $referee_id = (int) $query->get( 'f365_referee_id' );

    if ( ! $referee_id ) {
        return;
    }

    $wp_post_id = f365_referee_resolve_id( $referee_id ) ?: $referee_id;

    $query->set( 'post_type', 'f365_referee' );
    $query->set( 'p', $wp_post_id );
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
function f365_referee_resolve_id( int $referee_id ): ?int {
    static $cache = [];

    if ( isset( $cache[ $referee_id ] ) ) {
        return $cache[ $referee_id ];
    }

    global $wpdb;

    $wp_id = $wpdb->get_var(
        $wpdb->prepare(
            "
            SELECT ID
            FROM {$wpdb->posts}
            WHERE legacy_id = %d
            AND post_type = 'f365_referee'
            LIMIT 1
            ",
            $referee_id
        )
    );

    // Fallback to django_referee_id in postmeta if not found in columns
    if (!$wp_id) {
        $wp_id = $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT pm.post_id
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key = 'django_referee_id'
                AND pm.meta_value = %s
                AND p.post_type = 'f365_referee'
                LIMIT 1
                ",
                $referee_id
            )
        );
    }

    $cache[ $referee_id ] = $wp_id ? (int) $wp_id : null;

    return $cache[ $referee_id ];
}


/**
 * -------------------------------------------------------
 * 5. PERMALINKS
 * -------------------------------------------------------
 */
add_filter( 'post_type_link', 'f365_referee_permalink', 10, 2 );

function f365_referee_permalink( string $post_link, WP_Post $post ): string {

    if ( 'f365_referee' !== $post->post_type ) {
        return $post_link;
    }

    $legacy_id = f365_referee_legacy_id( $post->ID );
    $final_id = $legacy_id ?: $post->ID;

    return user_trailingslashit(
        home_url( "arbitro/{$final_id}" )
    );
}

if ( ! function_exists( 'f365_referee_legacy_id' ) ) {
    function f365_referee_legacy_id(int $post_id): int
    {
        static $cache = [];
        if (array_key_exists($post_id, $cache)) {
            return $cache[$post_id];
        }
        if ($post_id <= 0) {
            return $cache[$post_id] = 0;
        }

        $post = get_post($post_id);
        if ($post && isset($post->legacy_id) && (int) $post->legacy_id > 0) {
            return $cache[$post_id] = (int) $post->legacy_id;
        }

        global $wpdb;
        $legacy_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT legacy_id FROM {$wpdb->posts} WHERE ID = %d AND legacy_table = %s LIMIT 1",
            $post_id, 'wp_person_referee'
        ));

        if ($legacy_id <= 0) {
            $meta = get_post_meta($post_id, 'django_referee_id', true);
            $legacy_id = is_numeric($meta) ? (int) $meta : 0;
        }

        return $cache[$post_id] = $legacy_id;
    }
}

/**
 * Referee URL Helper
 */
function f365_referee_url( int $post_id, string $section = '' ): string {

    $legacy_id = f365_referee_legacy_id( $post_id );
    $final_id = $legacy_id ?: $post_id;

    $path = "arbitro/{$final_id}";

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
add_filter( 'template_include', 'f365_referee_template_include' );

function f365_referee_template_include( string $template ): string {

    $referee_id = get_query_var( 'f365_referee_id' );
    $section    = get_query_var( 'f365_section' );

    if ( ! $referee_id ) {
        return $template;
    }

    switch ( $section ) {

        case 'jogos':
            $tpl = f365_referee_locate_template( 'f365-referee-jogos' );
            break;

        default:
            $tpl = f365_referee_locate_template( 'single-f365_referee' );
            break;
    }

    return $tpl ?: $template;
}


/**
 * -------------------------------------------------------
 * 7. TEMPLATE LOCATOR
 * -------------------------------------------------------
 */
function f365_referee_locate_template( string $name ): ?string {

    /**
     * Theme override
     */
    $theme_file = locate_template(
        [ "template-parts/referee/{$name}.php", "{$name}.php" ],
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

        $plugin_file = trailingslashit( F365_PLUGIN_DIR ) . "templates/referee-template/{$name}.php";

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
 * 8. GET CURRENT REFEREE POST
 * -------------------------------------------------------
 */
function f365_get_current_referee_post(): ?WP_Post {

    global $post;

    if ( $post instanceof WP_Post && $post->post_type === 'f365_referee' ) {
        return $post;
    }

    $referee_id = (int) get_query_var( 'f365_referee_id' );

    if ( ! $referee_id ) {
        return null;
    }

    $wp_post_id = f365_referee_resolve_id( $referee_id );

    if ( ! $wp_post_id ) {
        $wp_post_id = $referee_id;
    }

    return get_post( $wp_post_id );
}


/**
 * -------------------------------------------------------
 * 9. YOAST SEO TITLE FILTER
 * -------------------------------------------------------
 */
if ( ! function_exists( 'f365_referee_current_post_id' ) ) {
	function f365_referee_current_post_id(): int {
		if ( ! is_singular( 'f365_referee' ) ) {
			return 0;
		}
		$id = get_queried_object_id();
		return $id > 0 ? (int) $id : 0;
	}
}

/* ===========================================================================
 * Core SEO data for a referee — shortname + longname from custom tables. Cached.
 * Returns [] when not resolvable.
 * =========================================================================== */
function f365_referee_seo_data( int $post_id ): array {
	static $memo = [];
	if ( array_key_exists( $post_id, $memo ) ) {
		return $memo[ $post_id ];
	}
	if ( $post_id <= 0 ) {
		return $memo[ $post_id ] = [];
	}

	$cache_key = 'f365_referee_seo_' . $post_id;
	$cached    = wp_cache_get( $cache_key, 'f365' );
	if ( is_array( $cached ) ) {
		return $memo[ $post_id ] = $cached;
	}

	$legacy_id = function_exists( 'f365_referee_legacy_id' ) ? f365_referee_legacy_id( $post_id ) : 0;
	if ( $legacy_id <= 0 ) {
		return $memo[ $post_id ] = [];
	}

	global $wpdb;
	$T_REFEREE = $wpdb->prefix . 'person_referee';
	$T_PERS    = $wpdb->prefix . 'person_person';
	if ( ! f365_src_table_exists( $T_REFEREE ) || ! f365_src_table_exists( $T_PERS ) ) {
		return $memo[ $post_id ] = [];
	}

	// Single query: referee + person names
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT p.shortname, p.longname
		   FROM {$T_REFEREE} r
		   JOIN {$T_PERS} p ON p.id = r.person_id
		  WHERE r.id = %d
		  LIMIT 1",
		$legacy_id
	), ARRAY_A );

	if ( $wpdb->last_error !== '' || ! is_array( $row ) ) {
		return $memo[ $post_id ] = [];
	}

	$data = [
		'shortname' => isset( $row['shortname'] ) ? (string) $row['shortname'] : '',
		'longname'  => isset( $row['longname'] ) && $row['longname'] !== null ? (string) $row['longname'] : '',
	];

	wp_cache_set( $cache_key, $data, 'f365', 12 * HOUR_IN_SECONDS );
	return $memo[ $post_id ] = $data;
}

/* ===========================================================================
 * FILTERS — title + description (+ OG/Twitter mirrors).
 * =========================================================================== */
function f365_referee_wpseo_title( string $title ): string {
	$post_id = f365_referee_current_post_id();
	if ( $post_id <= 0 ) {
		return $title;
	}

	$data = f365_referee_seo_data( $post_id );
	$shortname = ( ! empty( $data['shortname'] ) ) ? $data['shortname'] : get_the_title( $post_id );
	$longname  = $data['longname'] ?? '';

	$parts   = [ $shortname ];
	if ( $longname !== '' && $longname !== $shortname ) {
		$parts[] = $longname;
	}

	$section = (string) get_query_var( 'f365_section' );
	$labels  = [
		'jogos' => 'Jogos',
	];
	$parts[] = ( $section !== '' && isset( $labels[ $section ] ) ) ? $labels[ $section ] : 'Resumo';

	$parts[] = 'Futebol 365';

	return implode( ' - ', $parts );
}
add_filter( 'wpseo_title', 'f365_referee_wpseo_title', 20 );


/* ---------------------------------------------------------------------------
 * Shared description text (source parity).
 *
 * referee-detail.html defines a SINGLE description block, inherited by both
 * sections (resume/jogos override only the title):
 *   "Toda a informação sobre {shortname}. Jogos, golos, estatísticas,
 *    palmarés, notícias, vídeos e muito mais!"
 * Note: NO "o árbitro" word — it is "sobre {shortname}". og:description is
 * identical, so the meta description and og/twitter description share it.
 * --------------------------------------------------------------------------- */
function f365_referee_description_text( int $post_id ): string {
	$data      = f365_referee_seo_data( $post_id );
	$shortname = ( ! empty( $data['shortname'] ) ) ? $data['shortname'] : get_the_title( $post_id );

	return sprintf(
		'Toda a informação sobre %s. Jogos, golos, estatísticas, palmarés, notícias, vídeos e muito mais!',
		$shortname
	);
}

function f365_dynamic_referee_description( string $desc ): string {
	$post_id = f365_referee_current_post_id();
	if ( $post_id <= 0 ) {
		return $desc;
	}

	return f365_referee_description_text( $post_id );
}
add_filter( 'wpseo_metadesc', 'f365_dynamic_referee_description', 20 );


/* ===========================================================================
 * OPEN GRAPH / TWITTER — match the source meta_tags block exactly:
 *   og:title       = referee.person.shortname
 *   og:description = same string as the meta description (shortname + suffix)
 * =========================================================================== */
function f365_referee_og_title( string $title ): string {
	$post_id = f365_referee_current_post_id();
	if ( $post_id <= 0 ) {
		return $title;
	}
	$data = f365_referee_seo_data( $post_id );
	$shortname = ( ! empty( $data['shortname'] ) ) ? $data['shortname'] : get_the_title( $post_id );
	return $shortname;
}
add_filter( 'wpseo_opengraph_title', 'f365_referee_og_title', 20 );
add_filter( 'wpseo_twitter_title', 'f365_referee_og_title', 20 );

function f365_referee_og_desc( string $desc ): string {
	$post_id = f365_referee_current_post_id();
	if ( $post_id <= 0 ) {
		return $desc;
	}
	return f365_referee_description_text( $post_id );
}
add_filter( 'wpseo_opengraph_desc', 'f365_referee_og_desc', 20 );
add_filter( 'wpseo_twitter_desc', 'f365_referee_og_desc', 20 );


/* ===========================================================================
 * CANONICAL + OG:URL.
 *
 * canonical: self-referencing current URL — /arbitro/{id}/[section]/.
 * og:url:    matches the source meta_tags block, which uses
 *            referee.get_absolute_url = reverse('referee_resume') =
 *            /arbitro/{id}/ (the base URL) on BOTH the base and jogos pages.
 *
 * {id} is the f365_referee_id URL segment (the Django id). The country list
 * route (/arbitro/pais/..) sets no f365_referee_id and is left untouched.
 * Priority 40 runs after the generic override (20) and the pagination
 * canonical (30).
 * =========================================================================== */
function f365_referee_wpseo_canonical( $canonical ) {
	$referee_id = (int) get_query_var( 'f365_referee_id' );
	if ( ! $referee_id ) {
		return $canonical;
	}

	$section = (string) get_query_var( 'f365_section' );

	$path = "arbitro/{$referee_id}";
	if ( $section !== '' ) {
		$path .= '/' . $section;
	}

	return user_trailingslashit( home_url( $path ) );
}
add_filter( 'wpseo_canonical', 'f365_referee_wpseo_canonical', 40 );

function f365_referee_wpseo_ogurl( $url ) {
	$referee_id = (int) get_query_var( 'f365_referee_id' );
	if ( ! $referee_id ) {
		return $url;
	}
	// Source og:url = referee.get_absolute_url (base), even on /jogos/.
	return user_trailingslashit( home_url( "arbitro/{$referee_id}" ) );
}
add_filter( 'wpseo_opengraph_url', 'f365_referee_wpseo_ogurl', 40 );
