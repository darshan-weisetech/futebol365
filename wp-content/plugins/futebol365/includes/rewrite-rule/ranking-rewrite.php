<?php
/**
 * Futebol365 — Rankings Rewrite Rules, Routing & SEO.
 *
 * Routing + template loading + SEO only. ALL ranking data is fetched from the
 * custom tables by the data layer (inc/mp-functions.php): player rankings via
 * f365_get_player_ranking_entries(); club/national via their own entry
 * functions. This file no longer runs pre_get_posts/meta_query loops or reads
 * postmeta — the archives don't rely on the WP main query for their rows.
 *
 * URLs (match Django ranking/urls.py):
 *   /rank-seleccoes/        national (FIFA)
 *   /rank-clubes/           clubs (IFFHS)
 *   /rank-jogadores/{id}/   player rankings; {id} = legacy PlayerRankingType id
 *                           (= f365_ranking_subtype term slug)
 *
 * SEO (source templates, all + " - Futebol 365" from base):
 *   <title>  national → "Ranking de Seleções FIFA - Futebol 365"
 *            club     → "Ranking de Clubes IFFHS - Futebol 365"
 *            player   → "{shortname} - Ranking de Jogadores - Futebol 365"
 *
 *   The ranking templates (base_ranks.html) override ONLY the title block, so
 *   the description + og:* tags inherit website/base.html → templates/base.html
 *   defaults. Those defaults are reproduced verbatim below so the rendered
 *   <meta name="description">, og:title and og:description match the live site
 *   (Yoast would otherwise emit its own auto-generated values).
 *
 *   {shortname} is read from the CUSTOM TABLE (wp_ranking_playerrankingtype) via
 *   the data-layer helper — no term meta, no postmeta.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;


/* ===========================================================================
 * 1. REWRITE RULES
 * =========================================================================== */
add_action( 'init', 'f365_ranking_rewrite_rules', 20 );

function f365_ranking_rewrite_rules(): void {

	add_rewrite_rule(
		'^rank-seleccoes/?$',
		'index.php?f365_ranking_section=national',
		'top'
	);

	add_rewrite_rule(
		'^rank-clubes/?$',
		'index.php?f365_ranking_section=club',
		'top'
	);

	// Term slug on f365_ranking_subtype = legacy PlayerRankingType id, so WP
	// resolves the term natively; we also carry the section for the loader.
	add_rewrite_rule(
		'^rank-jogadores/([0-9]+)/?$',
		'index.php?f365_ranking_subtype=$matches[1]&f365_ranking_section=player',
		'top'
	);
}


/* ===========================================================================
 * 2. QUERY VARS
 * =========================================================================== */
add_filter( 'query_vars', 'f365_ranking_query_vars' );

function f365_ranking_query_vars( array $vars ): array {
	if ( ! in_array( 'f365_ranking_section', $vars, true ) ) {
		$vars[] = 'f365_ranking_section';
	}
	return $vars;
}


/* ===========================================================================
 * 3. SECTION RESOLUTION (single source of truth, cached per request)
 * Used by the template loader and SEO. Returns 'national'|'club'|'player'|''.
 * =========================================================================== */
function f365_ranking_current_section(): string {
	static $section = null;
	if ( $section !== null ) {
		return $section;
	}

	$section = (string) get_query_var( 'f365_ranking_section' );
	if ( $section !== '' ) {
		return $section;
	}

	if ( is_tax( 'f365_ranking_subtype' ) ) {
		return $section = 'player';
	}
	if ( is_tax( 'f365_ranking_type' ) ) {
		$term = get_queried_object();
		if ( $term instanceof WP_Term ) {
			$map = [
				'clubes'    => 'club',    'club'     => 'club',
				'seleccoes' => 'national','national' => 'national',
				'jogadores' => 'player',  'player'   => 'player',
			];
			$section = $map[ $term->slug ] ?? '';
		}
	}
	return $section = (string) $section;
}


/* ===========================================================================
 * 4. LIGHTWEIGHT MAIN-QUERY SHORT-CIRCUIT
 * The archives render entirely from the data layer, so we don't want WP running
 * an expensive default query (and the player route sets a tax_query that would
 * 404 with no posts). Tell the main query to fetch nothing and not 404.
 * =========================================================================== */
add_action( 'pre_get_posts', 'f365_ranking_pre_get_posts' );

function f365_ranking_pre_get_posts( WP_Query $query ): void {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( f365_ranking_current_section() === '' ) {
		return;
	}

	// No main-loop posts needed; the template calls the data layer directly.
	$query->set( 'post_type', 'f365_ranking' );
	$query->set( 'posts_per_page', 1 );
	$query->set( 'no_found_rows', true );
	$query->set( 'ignore_sticky_posts', true );
	$query->set( 'cache_results', false );
}

// Keep the page from being treated as a 404 when the loop is empty.
add_filter( 'template_redirect', 'f365_ranking_force_200', 0 );

function f365_ranking_force_200(): void {
	if ( f365_ranking_current_section() === '' ) {
		return;
	}
	global $wp_query;
	$wp_query->is_404 = false;
	status_header( 200 );
}


/* ===========================================================================
 * 5. HELPER: resolve f365_ranking_subtype term by slug (= legacy id). Cached.
 * (Kept — used by the player nav + template to map a legacy id to its term.)
 * =========================================================================== */
function f365_ranking_resolve_subtype_term( int $django_type_id ): ?WP_Term {
	static $cache = [];
	if ( array_key_exists( $django_type_id, $cache ) ) {
		return $cache[ $django_type_id ];
	}
	if ( $django_type_id <= 0 ) {
		return $cache[ $django_type_id ] = null;
	}

	$term = get_term_by( 'slug', (string) $django_type_id, 'f365_ranking_subtype' );
	$cache[ $django_type_id ] = ( $term instanceof WP_Term ) ? $term : null;
	return $cache[ $django_type_id ];
}

/** Legacy PlayerRankingType id for the current player archive (term slug). */
function f365_ranking_current_type_id(): int {
	$term = get_queried_object();
	return ( $term instanceof WP_Term ) ? (int) $term->slug : 0;
}


/* ===========================================================================
 * 6. TEMPLATE LOADER
 * =========================================================================== */
add_filter( 'template_include', 'f365_ranking_template_include' );

function f365_ranking_template_include( string $template ): string {
	$section = f365_ranking_current_section();
	if ( $section === '' ) {
		return $template;
	}

	$name_map = [
		'national' => 'f365-ranking-national',
		'club'     => 'f365-ranking-clubes',
		'player'   => 'f365-ranking-player',
	];
	$name = $name_map[ $section ] ?? '';
	if ( $name === '' ) {
		return $template;
	}

	$found = f365_ranking_locate_template( $name )
	      ?: f365_ranking_locate_template( 'archive-f365_ranking' );

	return $found ?: $template;
}

function f365_ranking_locate_template( string $name ): ?string {
	$theme_file = locate_template(
		[ "template-parts/ranking/{$name}.php", "{$name}.php" ],
		false,
		false
	);
	if ( $theme_file ) {
		return $theme_file;
	}
	if ( defined( 'F365_PLUGIN_DIR' ) ) {
		$plugin_file = trailingslashit( F365_PLUGIN_DIR ) . "templates/ranking-template/{$name}.php";
		if ( file_exists( $plugin_file ) ) {
			return $plugin_file;
		}
	}
	return null;
}


/* ===========================================================================
 * 7. DISABLE SINGLE RANKING POST PAGES + neutralize permalinks
 * =========================================================================== */
add_action( 'template_redirect', 'f365_ranking_disable_single_post' );

function f365_ranking_disable_single_post(): void {
	if ( is_singular( 'f365_ranking' ) ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		include get_query_template( '404' );
		exit;
	}
}

add_filter( 'post_type_link', 'f365_ranking_post_link', 10, 2 );

function f365_ranking_post_link( string $post_link, WP_Post $post ): string {
	return 'f365_ranking' === $post->post_type ? '#' : $post_link;
}


/* ===========================================================================
 * 8. SEO — titles + description/og inherited from the Django base defaults.
 *
 * <title> is section-specific (source title blocks). The ranking templates do
 * NOT override the description/meta_tags blocks, so on the live site the
 * description and og:* fall back to templates/base.html. Those exact defaults
 * are reproduced here. The player shortname is read from the CUSTOM TABLE via
 * the data-layer helper (no postmeta).
 * =========================================================================== */

/* Verbatim defaults from templates/base.html (note: meta description uses
 * "seleções"; the longer og:description uses the older "selecções"). */
if ( ! defined( 'F365_RANKING_METADESC' ) ) {
	define( 'F365_RANKING_METADESC', 'O Portal do Futebol Mundial. Tudo sobre os principais campeonatos e competições nacionais e internacionais de clubes e seleções.' );
}
if ( ! defined( 'F365_RANKING_OG_TITLE' ) ) {
	define( 'F365_RANKING_OG_TITLE', 'Portal do Futebol Mundial | Futebol 365' );
}
if ( ! defined( 'F365_RANKING_OG_DESC' ) ) {
	define( 'F365_RANKING_OG_DESC', 'O Portal do Futebol Mundial. Tudo sobre os principais campeonatos e competições nacionais e internacionais de clubes e selecções. Notícias, jogos, jogadores, clubes, treinadores, videos, estatísticas, transferências, ranking de clubes, ranking de selecções, agenda desportiva, foruns, jogos em directo e muito, muito mais... tudo no maior portal de futebol!' );
}

/* ── <title> ─────────────────────────────────────────────────────────── */
add_filter( 'wpseo_title', 'f365_ranking_wpseo_title', 20 );

function f365_ranking_wpseo_title( string $title ): string {
	$section = f365_ranking_current_section();
	if ( $section === '' ) {
		return $title;
	}

	if ( $section === 'national' ) {
		return 'Ranking de Seleções FIFA - Futebol 365';
	}
	if ( $section === 'club' ) {
		return 'Ranking de Clubes IFFHS - Futebol 365';
	}
	if ( $section === 'player' ) {
		$type_id   = f365_ranking_current_type_id();
		$shortname = '';
		if ( $type_id > 0 && function_exists( 'f365_get_player_ranking_type' ) ) {
			$rt        = f365_get_player_ranking_type( $type_id );
			$shortname = (string) ( $rt['shortname'] ?? '' );
		}
		if ( $shortname !== '' ) {
			return sprintf( '%s - Ranking de Jogadores - Futebol 365', $shortname );
		}
		return 'Ranking de Jogadores - Futebol 365';
	}

	return $title;
}

/* ── meta description (inherited base default for all ranking sections) ── */
add_filter( 'wpseo_metadesc', 'f365_ranking_wpseo_metadesc', 20 );

function f365_ranking_wpseo_metadesc( string $desc ): string {
	return f365_ranking_current_section() !== '' ? F365_RANKING_METADESC : $desc;
}

/* ── og/twitter title (base default — matches the source meta_tags block) ─ */
function f365_ranking_og_title( string $title ): string {
	return f365_ranking_current_section() !== '' ? F365_RANKING_OG_TITLE : $title;
}
add_filter( 'wpseo_opengraph_title', 'f365_ranking_og_title', 20 );
add_filter( 'wpseo_twitter_title', 'f365_ranking_og_title', 20 );

/* ── og/twitter description (base default long string) ───────────────────── */
function f365_ranking_og_desc( string $desc ): string {
	return f365_ranking_current_section() !== '' ? F365_RANKING_OG_DESC : $desc;
}
add_filter( 'wpseo_opengraph_desc', 'f365_ranking_og_desc', 20 );
add_filter( 'wpseo_twitter_desc', 'f365_ranking_og_desc', 20 );

/* ===========================================================================
 * 9. CANONICAL + og:url  (self-referential ranking URL, incl. ?pagina=N)
 *
 * Ranking pages are custom-routed, so Yoast's computed canonical/og:url can be
 * wrong. Build them from the resolved section:
 *   national → /rank-seleccoes/
 *   club     → /rank-clubes/
 *   player   → /rank-jogadores/{id}/
 * The paginator uses the custom ?pagina=N var (mirrors the source og:url =
 * request.get_full_path, which carries the query string), so N>=2 is appended.
 * Priority 40 runs after the generic overrides; og:url is wired to the same
 * builder so it always matches the canonical.
 * =========================================================================== */
function f365_ranking_canonical_url(): ?string {
	$section = f365_ranking_current_section();
	if ( $section === '' ) {
		return null;
	}

	if ( $section === 'national' ) {
		$path = 'rank-seleccoes';
	} elseif ( $section === 'club' ) {
		$path = 'rank-clubes';
	} elseif ( $section === 'player' ) {
		$type_id = f365_ranking_current_type_id();
		if ( $type_id <= 0 ) {
			return null;
		}
		$path = 'rank-jogadores/' . $type_id;
	} else {
		return null;
	}

	$url = user_trailingslashit( home_url( $path ) );

	$pagina = isset( $_GET['pagina'] ) ? (int) $_GET['pagina'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
	if ( $pagina >= 2 ) {
		$url .= '?pagina=' . $pagina;
	}

	return $url;
}

function f365_ranking_wpseo_canonical( $canonical ) {
	$url = f365_ranking_canonical_url();
	return ( $url !== null ) ? $url : $canonical;
}
add_filter( 'wpseo_canonical', 'f365_ranking_wpseo_canonical', 40 );
add_filter( 'wpseo_opengraph_url', 'f365_ranking_wpseo_canonical', 40 );
