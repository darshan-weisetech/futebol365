<?php
/**
 * Futebol365 — Coach Rewrite Rules & Permalink Handling
 *
 * Handles custom routing for coach pages and their sub-sections:
 * - /treinador/{id}/
 * - /treinador/{id}/noticias/
 * - /treinador/{id}/dados-pessoais/
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
function f365_coach_rewrite_rules(): void {

    $sections = [
        'noticias',
        'info',
    ];

    foreach ( $sections as $section ) {
        add_rewrite_rule(
            '^treinador/([0-9]+)/' . $section . '/?$',
            'index.php?post_type=f365_coach&f365_coach_id=$matches[1]&f365_section=' . $section,
            'top'
        );
    }

    add_rewrite_rule(
        '^treinador/([0-9]+)/?$',
        'index.php?post_type=f365_coach&f365_coach_id=$matches[1]',
        'top'
    );

    add_rewrite_rule(
        '^treinador/pais/([^/]+)/?$',
        'index.php?post_type=f365_coach&f365_country=$matches[1]',
        'top'
    );
}
add_action( 'init', 'f365_coach_rewrite_rules', 20 );


/**
 * -------------------------------------------------------
 * 2. QUERY VARS
 * -------------------------------------------------------
 */
add_filter( 'query_vars', 'f365_coach_query_vars' );

function f365_coach_query_vars( array $vars ): array {
    $new = [
        'f365_coach_id',
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
add_action( 'pre_get_posts', 'f365_coach_pre_get_posts' );

function f365_coach_pre_get_posts( WP_Query $query ): void {

    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }

    $coach_id = (int) $query->get( 'f365_coach_id' );

    if ( ! $coach_id ) {
        return;
    }

    $wp_post_id = f365_coach_resolve_id( $coach_id ) ?: $coach_id;

    $query->set( 'post_type', 'f365_coach' );
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
function f365_coach_resolve_id( int $coach_id ): ?int {
    static $cache = [];

    if ( isset( $cache[ $coach_id ] ) ) {
        return $cache[ $coach_id ];
    }

    global $wpdb;

    $wp_id = $wpdb->get_var(
        $wpdb->prepare(
            "
            SELECT ID
            FROM {$wpdb->posts}
            WHERE legacy_id = %d
            AND post_type = 'f365_coach'
            LIMIT 1
            ",
            $coach_id
        )
    );

    // Fallback to django_coach_id in postmeta if not found in columns
    if (!$wp_id) {
        $wp_id = $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT pm.post_id
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key = 'django_coach_id'
                AND pm.meta_value = %s
                AND p.post_type = 'f365_coach'
                LIMIT 1
                ",
                $coach_id
            )
        );
    }

    $cache[ $coach_id ] = $wp_id ? (int) $wp_id : null;

    return $cache[ $coach_id ];
}


/**
 * -------------------------------------------------------
 * 5. PERMALINKS
 * -------------------------------------------------------
 */
add_filter( 'post_type_link', 'f365_coach_permalink', 10, 2 );

function f365_coach_permalink( string $post_link, WP_Post $post ): string {

    if ( 'f365_coach' !== $post->post_type ) {
        return $post_link;
    }

    $legacy_id = f365_coach_legacy_id( $post->ID );
    $final_id = $legacy_id ?: $post->ID;

    return user_trailingslashit(
        home_url( "treinador/{$final_id}" )
    );
}

if ( ! function_exists( 'f365_coach_legacy_id' ) ) {
    function f365_coach_legacy_id(int $post_id): int
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
            $post_id, 'wp_person_coach'
        ));

        if ($legacy_id <= 0) {
            $meta = get_post_meta($post_id, 'django_coach_id', true);
            $legacy_id = is_numeric($meta) ? (int) $meta : 0;
        }

        return $cache[$post_id] = $legacy_id;
    }
}

/**
 * Coach URL Helper
 */
function f365_coach_url( int $post_id, string $section = '' ): string {

    $legacy_id = f365_coach_legacy_id( $post_id );
    $final_id = $legacy_id ?: $post_id;

    $path = "treinador/{$final_id}";

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
add_filter( 'template_include', 'f365_coach_template_include' );

function f365_coach_template_include( string $template ): string {

    $coach_id = get_query_var( 'f365_coach_id' );
    $section  = get_query_var( 'f365_section' );

    if ( ! $coach_id ) {
        return $template;
    }

    // All sections handled by single-f365_coach.php (unified controller).
    // $section is read via get_query_var('f365_section') inside that template.
    // Content parts are loaded via get_template_part() so $args passes correctly.
    $tpl = f365_coach_locate_template( 'single-f365_coach' );

    return $tpl ?: $template;
}


/**
 * -------------------------------------------------------
 * 7. TEMPLATE LOCATOR
 * -------------------------------------------------------
 */
function f365_coach_locate_template( string $name ): ?string {

    /**
     * Theme override
     */
    $theme_file = locate_template(
        [ "template-parts/coach/{$name}.php", "{$name}.php" ],
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

        $plugin_file = trailingslashit( F365_PLUGIN_DIR ) . "templates/coach-template/{$name}.php";

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
 * 8. GET CURRENT COACH POST
 * -------------------------------------------------------
 */
function f365_get_current_coach_post(): ?WP_Post {

    global $post;

    if ( $post instanceof WP_Post && $post->post_type === 'f365_coach' ) {
        return $post;
    }

    $coach_id = (int) get_query_var( 'f365_coach_id' );

    if ( ! $coach_id ) {
        return null;
    }

    $wp_post_id = f365_coach_resolve_id( $coach_id );

    if ( ! $wp_post_id ) {
        $wp_post_id = $coach_id;
    }

    return get_post( $wp_post_id );
}


/**
 * -------------------------------------------------------
 * 9. YOAST SEO TITLE FILTER
 * -------------------------------------------------------
 */
if ( ! function_exists( 'f365_coach_current_post_id' ) ) {
	function f365_coach_current_post_id(): int {
		if ( ! is_singular( 'f365_coach' ) ) {
			return 0;
		}
		$id = get_queried_object_id();
		return $id > 0 ? (int) $id : 0;
	}
}

/* ===========================================================================
 * Core SEO data for a coach — shortname + longname from custom tables. Cached.
 * Returns [] when not resolvable.
 * =========================================================================== */
function f365_coach_seo_data( int $post_id ): array {
	static $memo = [];
	if ( array_key_exists( $post_id, $memo ) ) {
		return $memo[ $post_id ];
	}
	if ( $post_id <= 0 ) {
		return $memo[ $post_id ] = [];
	}

	$cache_key = 'f365_coach_seo_' . $post_id;
	$cached    = wp_cache_get( $cache_key, 'f365' );
	if ( is_array( $cached ) ) {
		return $memo[ $post_id ] = $cached;
	}

	$legacy_id = function_exists( 'f365_coach_legacy_id' ) ? f365_coach_legacy_id( $post_id ) : 0;
	if ( $legacy_id <= 0 ) {
		return $memo[ $post_id ] = [];
	}

	global $wpdb;
	$T_COACH = $wpdb->prefix . 'person_coach';
	$T_PERS  = $wpdb->prefix . 'person_person';
	if ( ! f365_src_table_exists( $T_COACH ) || ! f365_src_table_exists( $T_PERS ) ) {
		return $memo[ $post_id ] = [];
	}

	// Single query: coach + person names
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT p.shortname, p.longname
		   FROM {$T_COACH} c
		   JOIN {$T_PERS} p ON p.id = c.person_id
		  WHERE c.id = %d
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
function f365_coach_wpseo_title( string $title ): string {
	$post_id = f365_coach_current_post_id();
	if ( $post_id <= 0 ) {
		return $title;
	}

	$data = f365_coach_seo_data( $post_id );
	$shortname = ( ! empty( $data['shortname'] ) ) ? $data['shortname'] : get_the_title( $post_id );
	$longname  = $data['longname'] ?? '';

	$parts   = [ $shortname ];
	if ( $longname !== '' && $longname !== $shortname ) {
		$parts[] = $longname;
	}

	$section = (string) get_query_var( 'f365_section' );
	$labels  = [
		'noticias' => 'Notícias',
		'info'     => 'Dados Pessoais',
	];
	$parts[] = ( $section !== '' && isset( $labels[ $section ] ) ) ? $labels[ $section ] : 'Resumo';

	$parts[] = 'Futebol 365';

	return implode( ' - ', $parts );
}
add_filter( 'wpseo_title', 'f365_coach_wpseo_title', 20 );


/* ---------------------------------------------------------------------------
 * Shared description text (source parity).
 *
 * coach-detail.html defines a SINGLE description block, inherited by every
 * section (resume/info/noticias/jogos/palmares override only the title):
 *   "Toda a informação sobre o treinador {shortname}. Jogos, estatísticas,
 *    palmarés, notícias, vídeos e muito mais!"
 * og:description is identical ({{ coach }} = person.shortname), so both the
 * meta description and og/twitter description use this exact string.
 * --------------------------------------------------------------------------- */
function f365_coach_description_text( int $post_id ): string {
	$data      = f365_coach_seo_data( $post_id );
	$shortname = ( ! empty( $data['shortname'] ) ) ? $data['shortname'] : get_the_title( $post_id );

	return sprintf(
		'Toda a informação sobre o treinador %s. Jogos, estatísticas, palmarés, notícias, vídeos e muito mais!',
		$shortname
	);
}

function f365_dynamic_coach_description( string $desc ): string {
	$post_id = f365_coach_current_post_id();
	if ( $post_id <= 0 ) {
		return $desc;
	}

	return f365_coach_description_text( $post_id );
}
add_filter( 'wpseo_metadesc', 'f365_dynamic_coach_description', 20 );


/* ===========================================================================
 * OPEN GRAPH / TWITTER — match the source meta_tags block exactly:
 *   og:title       = {{ coach }} = shortname
 *   og:description = same string as the meta description (shortname + suffix)
 * =========================================================================== */
function f365_coach_og_title( string $title ): string {
	$post_id = f365_coach_current_post_id();
	if ( $post_id <= 0 ) {
		return $title;
	}
	$data = f365_coach_seo_data( $post_id );
	$shortname = ( ! empty( $data['shortname'] ) ) ? $data['shortname'] : get_the_title( $post_id );
	return $shortname;
}
add_filter( 'wpseo_opengraph_title', 'f365_coach_og_title', 20 );
add_filter( 'wpseo_twitter_title', 'f365_coach_og_title', 20 );

function f365_coach_og_desc( string $desc ): string {
	$post_id = f365_coach_current_post_id();
	if ( $post_id <= 0 ) {
		return $desc;
	}
	return f365_coach_description_text( $post_id );
}
add_filter( 'wpseo_opengraph_desc', 'f365_coach_og_desc', 20 );
add_filter( 'wpseo_twitter_desc', 'f365_coach_og_desc', 20 );


/* ===========================================================================
 * CANONICAL + OG:URL (self-referencing).
 *
 * Source og:url uses request.get_full_path (the current URL), so canonical and
 * og:url are both the self-referencing per-section URL:
 *   base:    /treinador/{id}/
 *   section: /treinador/{id}/{section}/
 *
 * {id} is the f365_coach_id URL segment (the Django id). The country list route
 * (/treinador/pais/..) sets no f365_coach_id and is left untouched. Priority 40
 * runs after the generic override (20) and the pagination canonical (30).
 * =========================================================================== */
function f365_coach_wpseo_canonical( $canonical ) {
	$coach_id = (int) get_query_var( 'f365_coach_id' );
	if ( ! $coach_id ) {
		return $canonical;
	}

	$section = (string) get_query_var( 'f365_section' );

	$path = "treinador/{$coach_id}";
	if ( $section !== '' ) {
		$path .= '/' . $section;
	}

	return user_trailingslashit( home_url( $path ) );
}
add_filter( 'wpseo_canonical', 'f365_coach_wpseo_canonical', 40 );
add_filter( 'wpseo_opengraph_url', 'f365_coach_wpseo_canonical', 40 );
