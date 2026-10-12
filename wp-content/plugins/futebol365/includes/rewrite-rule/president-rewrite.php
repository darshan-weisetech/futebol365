<?php
/**
 * Futebol365 — President Rewrite Rules & Permalink Handling
 *
 * Handles custom routing for president pages and their sub-sections:
 * - /dirigente/{id}/
 * - /dirigente/{id}/info/
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

/**
 * -------------------------------------------------------
 * 1. REWRITE RULES
 * -------------------------------------------------------
 */
function f365_president_rewrite_rules(): void {

    $sections = [
        'info',
    ];

    foreach ( $sections as $section ) {
        add_rewrite_rule(
            '^dirigente/([0-9]+)/' . $section . '/?$',
            'index.php?post_type=f365_president&f365_president_id=$matches[1]&f365_section=' . $section,
            'top'
        );
    }

    add_rewrite_rule(
        '^dirigente/([0-9]+)/?$',
        'index.php?post_type=f365_president&f365_president_id=$matches[1]',
        'top'
    );
}
add_action( 'init', 'f365_president_rewrite_rules', 20 );


/**
 * -------------------------------------------------------
 * 2. QUERY VARS
 * -------------------------------------------------------
 */
add_filter( 'query_vars', 'f365_president_query_vars' );

function f365_president_query_vars( array $vars ): array {
    $new = [
        'f365_president_id',
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
add_action( 'pre_get_posts', 'f365_president_pre_get_posts' );

function f365_president_pre_get_posts( WP_Query $query ): void {

    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }

    $president_id = (int) $query->get( 'f365_president_id' );

    if ( ! $president_id ) {
        return;
    }

    $wp_post_id = f365_president_resolve_id( $president_id ) ?: $president_id;

    $query->set( 'post_type', 'f365_president' );
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
function f365_president_resolve_id( int $president_id ): ?int {
    static $cache = [];

    if ( isset( $cache[ $president_id ] ) ) {
        return $cache[ $president_id ];
    }

    global $wpdb;

    $wp_id = $wpdb->get_var(
        $wpdb->prepare(
            "
            SELECT ID
            FROM {$wpdb->posts}
            WHERE legacy_id = %d
            AND post_type = 'f365_president'
            LIMIT 1
            ",
            $president_id
        )
    );

    // Fallback to django_president_id in postmeta if not found in columns
    if (!$wp_id) {
        $wp_id = $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT pm.post_id
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key = 'django_president_id'
                AND pm.meta_value = %s
                AND p.post_type = 'f365_president'
                LIMIT 1
                ",
                $president_id
            )
        );
    }

    $cache[ $president_id ] = $wp_id ? (int) $wp_id : null;

    return $cache[ $president_id ];
}


/**
 * -------------------------------------------------------
 * 5. PERMALINKS
 * -------------------------------------------------------
 */
add_filter( 'post_type_link', 'f365_president_permalink', 10, 2 );

function f365_president_permalink( string $post_link, WP_Post $post ): string {

    if ( 'f365_president' !== $post->post_type ) {
        return $post_link;
    }

    $legacy_id = f365_president_legacy_id( $post->ID );
    $final_id = $legacy_id ?: $post->ID;

    return user_trailingslashit(
        home_url( "dirigente/{$final_id}" )
    );
}

if ( ! function_exists( 'f365_president_legacy_id' ) ) {
    function f365_president_legacy_id(int $post_id): int
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
            $post_id, 'wp_person_president'
        ));

        if ($legacy_id <= 0) {
            $meta = get_post_meta($post_id, 'django_president_id', true);
            $legacy_id = is_numeric($meta) ? (int) $meta : 0;
        }

        return $cache[$post_id] = $legacy_id;
    }
}

/**
 * President URL Helper
 */
function f365_president_url( int $post_id, string $section = '' ): string {

    $legacy_id = f365_president_legacy_id( $post_id );
    $final_id = $legacy_id ?: $post_id;

    $path = "dirigente/{$final_id}";

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
add_filter( 'template_include', 'f365_president_template_include' );

function f365_president_template_include( string $template ): string {

    $president_id = get_query_var( 'f365_president_id' );
    $section      = get_query_var( 'f365_section' );

    if ( ! $president_id ) {
        return $template;
    }

    switch ( $section ) {

        case 'info':
            $tpl = f365_president_locate_template( 'f365-president-info' );
            break;

        default:
            $tpl = f365_president_locate_template( 'single-f365_president' );
            break;
    }

    return $tpl ?: $template;
}


/**
 * -------------------------------------------------------
 * 7. TEMPLATE LOCATOR
 * -------------------------------------------------------
 */
function f365_president_locate_template( string $name ): ?string {

    /**
     * Theme override
     */
    $theme_file = locate_template(
        [ "template-parts/president/{$name}.php", "{$name}.php" ],
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

        $plugin_file = trailingslashit( F365_PLUGIN_DIR ) . "templates/president-template/{$name}.php";

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
 * 8. GET CURRENT PRESIDENT POST
 * -------------------------------------------------------
 */
function f365_get_current_president_post(): ?WP_Post {

    global $post;

    if ( $post instanceof WP_Post && $post->post_type === 'f365_president' ) {
        return $post;
    }

    $president_id = (int) get_query_var( 'f365_president_id' );

    if ( ! $president_id ) {
        return null;
    }

    $wp_post_id = f365_president_resolve_id( $president_id );

    if ( ! $wp_post_id ) {
        $wp_post_id = $president_id;
    }

    return get_post( $wp_post_id );
}


if ( ! function_exists( 'f365_president_current_post_id' ) ) {
	function f365_president_current_post_id(): int {
		if ( ! is_singular( 'f365_president' ) ) {
			return 0;
		}
		$id = get_queried_object_id();
		return $id > 0 ? (int) $id : 0;
	}
}
 
 
/* ===========================================================================
 * Core SEO data — shortname + longname from custom tables. Cached.
 * Returns [] when not resolvable (filters then leave Yoast untouched).
 * =========================================================================== */
function f365_president_seo_data( int $post_id ): array {
	static $memo = [];
	if ( array_key_exists( $post_id, $memo ) ) {
		return $memo[ $post_id ];
	}
	if ( $post_id <= 0 ) {
		return $memo[ $post_id ] = [];
	}
 
	$cache_key = 'f365_president_seo_' . $post_id;
	$cached    = wp_cache_get( $cache_key, 'f365' );
	if ( is_array( $cached ) ) {
		return $memo[ $post_id ] = $cached;
	}
 
	$legacy_id = function_exists( 'f365_president_legacy_id' ) ? f365_president_legacy_id( $post_id ) : 0;
	if ( $legacy_id <= 0 ) {
		return $memo[ $post_id ] = [];
	}
 
	global $wpdb;
	$T_PRESIDENT = $wpdb->prefix . 'person_president';
	$T_PERS      = $wpdb->prefix . 'person_person';
	if ( ! f365_src_table_exists( $T_PRESIDENT ) || ! f365_src_table_exists( $T_PERS ) ) {
		return $memo[ $post_id ] = [];
	}
 
	// Single, minimal query — only the two name fields the source SEO uses.
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT p.shortname, p.longname
		   FROM {$T_PRESIDENT} pr
		   JOIN {$T_PERS} p ON p.id = pr.person_id
		  WHERE pr.id = %d
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
 * Section labels (title only). Source: president-resume.html (base) adds
 * "Resumo" and president-info.html adds "Dados Pessoais" AFTER the names.
 *   base (no section) → 'Resumo'        (president-resume.html)
 *   info              → 'Dados Pessoais' (president-info.html)
 * =========================================================================== */
function f365_president_section_title_label( string $section ): string {
	$labels = [
		'info' => 'Dados Pessoais',
	];
	return $labels[ $section ] ?? 'Resumo';
}
 
 
/* ===========================================================================
 * TITLE — "{shortname}[ - {longname}] - {Section} - Futebol 365"
 * Section is always present (base = "Resumo", info = "Dados Pessoais").
 * =========================================================================== */
function f365_president_wpseo_title( string $title ): string {
	$post_id = f365_president_current_post_id();
	if ( $post_id <= 0 ) {
		return $title; // not a president page — leave other modules untouched
	}
 
	$data      = f365_president_seo_data( $post_id );
	$shortname = ( ! empty( $data['shortname'] ) ) ? $data['shortname'] : get_the_title( $post_id );
	$longname  = $data['longname'] ?? '';
 
	$parts = [ $shortname ];
	if ( $longname !== '' && $longname !== $shortname ) {
		$parts[] = $longname;
	}
 
	// Section label AFTER the names (source: {{ block.super }} - {Section}).
	$section = (string) get_query_var( 'f365_section' );
	$parts[] = f365_president_section_title_label( $section );
 
	$parts[] = 'Futebol 365';
 
	return implode( ' - ', $parts );
}
add_filter( 'wpseo_title', 'f365_president_wpseo_title', 20 );
 
 
/* ===========================================================================
 * DESCRIPTION — "Toda a informação sobre o dirigente {longname}."
 * Source (president-detail.html, inherited by the info subpage) uses LONGNAME
 * only — no shortname fallback. Same on the base page and the info subpage.
 * =========================================================================== */
function f365_president_wpseo_metadesc( string $desc ): string {
	$post_id = f365_president_current_post_id();
	if ( $post_id <= 0 ) {
		return $desc;
	}
 
	$data = f365_president_seo_data( $post_id );
	if ( empty( $data ) ) {
		return $desc; // unresolvable → leave Yoast untouched
	}
 
	$longname = $data['longname'] ?? '';
	return sprintf( 'Toda a informação sobre o dirigente %s.', $longname );
}
add_filter( 'wpseo_metadesc', 'f365_president_wpseo_metadesc', 20 );
 
 
/* ===========================================================================
 * OPEN GRAPH / TWITTER — match the source meta_tags block exactly:
 *   og:title       = shortname (NOT shortname-longname)
 *   og:description = "Toda a informação sobre o dirigente {longname}" (no period)
 * =========================================================================== */
function f365_president_og_title( string $title ): string {
	$post_id = f365_president_current_post_id();
	if ( $post_id <= 0 ) {
		return $title;
	}
	$data = f365_president_seo_data( $post_id );
	$shortname = ( ! empty( $data['shortname'] ) ) ? $data['shortname'] : get_the_title( $post_id );
	return $shortname;
}
add_filter( 'wpseo_opengraph_title', 'f365_president_og_title', 20 );
add_filter( 'wpseo_twitter_title', 'f365_president_og_title', 20 );
 
function f365_president_og_desc( string $desc ): string {
	$post_id = f365_president_current_post_id();
	if ( $post_id <= 0 ) {
		return $desc;
	}
	$data = f365_president_seo_data( $post_id );
	if ( empty( $data ) ) {
		return $desc;
	}
	$longname = $data['longname'] ?? '';
	// Source og:description has NO trailing period.
	return sprintf( 'Toda a informação sobre o dirigente %s', $longname );
}
add_filter( 'wpseo_opengraph_desc', 'f365_president_og_desc', 20 );
add_filter( 'wpseo_twitter_desc', 'f365_president_og_desc', 20 );
 
 
/* ===========================================================================
 * CANONICAL + OG:URL.
 *
 * canonical: self-referencing current URL — /dirigente/{id}/[section]/.
 * og:url:    matches the source meta_tags block, which uses
 *            president.get_absolute_url = reverse('president_resume') =
 *            /dirigente/{id}/ (the base URL) on BOTH the base and info pages.
 *
 * {id} is the f365_president_id URL segment (the Django id). Priority 40 runs
 * after the generic override (20) and the pagination canonical (30).
 * =========================================================================== */
function f365_president_wpseo_canonical( $canonical ) {
	$president_id = (int) get_query_var( 'f365_president_id' );
	if ( ! $president_id ) {
		return $canonical;
	}

	$section = (string) get_query_var( 'f365_section' );

	$path = "dirigente/{$president_id}";
	if ( $section !== '' ) {
		$path .= '/' . $section;
	}

	return user_trailingslashit( home_url( $path ) );
}
add_filter( 'wpseo_canonical', 'f365_president_wpseo_canonical', 40 );

function f365_president_wpseo_ogurl( $url ) {
	$president_id = (int) get_query_var( 'f365_president_id' );
	if ( ! $president_id ) {
		return $url;
	}
	// Source og:url = president.get_absolute_url (base), even on /info/.
	return user_trailingslashit( home_url( "dirigente/{$president_id}" ) );
}
add_filter( 'wpseo_opengraph_url', 'f365_president_wpseo_ogurl', 40 );
