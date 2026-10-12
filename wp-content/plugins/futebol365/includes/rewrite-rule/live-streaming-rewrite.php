<?php
/**
 * Futebol365 — Live Streaming Rewrite Rules & Routing.
 *
 * Mirrors the Django app's live URL structure exactly (live/urls.py):
 *   /live-streaming/                                  → homepage  (live.views.live_streaming_homepage      → name='live-streaming')
 *   /live-streaming/{inlive|tostart|finished}/        → by-status (live.views.live_streaming_by_status     → name='live-streaming-by-status')
 *   /live-streaming/{obj_id}/                         → detail    (common_views.read_cached_obj            → name='live-streaming-detail')
 *
 * {obj_id} = Django LiveStreaming.id, stored on each livestream post as
 * 'django_livestream_id'. Falls back to the raw WP post ID if none present —
 * the exact same scheme used by match-rewrite.php (django_match_id) and
 * livegames-rewrite.php (django_livematch_id).
 *
 * STATUS DERIVATION
 * -----------------
 * Django LiveStreaming has no stored status. Rather than the source's
 * time-window heuristic over the related Match.situation, the buckets are
 * derived from the live feed itself (cloned wp_live_livematch.status), which is
 * the authoritative, real-time signal. The status codes are taken verbatim from
 * live/models.py (LiveMatch.in_live / not_started) and live/signals.py:
 *   RF        = finished                       (signals maps RF -> situation 'finished')
 *   AD / SP   = delayed / suspended            (excluded from all three buckets)
 *   -1        = not started
 *   any other = in live  ("45"/"90"/"HT"/"PL"/"INT", ...)
 *
 *   finished → status = 'RF'                            ORDER BY datetime DESC
 *   inlive   → status NOT IN ('RF','AD','SP','-1')      ORDER BY datetime ASC   (== in_live())
 *   tostart  → status = '-1' AND datetime >= NOW()      ORDER BY datetime ASC
 *
 * Note: finished_at is NOT used as the finished marker — it is observed NULL on
 * rows already at status='RF' (e.g. id 531126), so `status` is authoritative.
 *
 * The post → live-match link is the django_match_id meta (= competition_match.id),
 * which equals wp_live_livematch.match_id (LiveMatch.match OneToOne). The resolved
 * post IDs feed a post__in main query with native pagination (mirroring
 * PaginatorHandler), built in pre_get_posts().
 *
 * Follows the same 8-part architecture as competition-rewrite.php /
 * match-rewrite.php / livegames-rewrite.php.
 *
 * @package Futebol365
 * @since   2.5.0
 */

defined( 'ABSPATH' ) || exit;

/* 1. REWRITE RULES ------------------------------------------------------- */
add_action( 'init', 'f365_live_streaming_rewrite_rules', 20 );
function f365_live_streaming_rewrite_rules(): void {

	// Homepage: /live-streaming/  (3 curated lists; served by archive template).
	add_rewrite_rule(
		'^live-streaming/?$',
		'index.php?post_type=f365_livestream&f365_livestream_home=1',
		'top'
	);

	// By-status pagination: /live-streaming/{status}/page/{n}/
	add_rewrite_rule(
		'^live-streaming/(inlive|tostart|finished)/page/([0-9]+)/?$',
		'index.php?post_type=f365_livestream&f365_livestream_status=$matches[1]&paged=$matches[2]',
		'top'
	);

	// By-status listing: /live-streaming/{inlive|tostart|finished}/
	add_rewrite_rule(
		'^live-streaming/(inlive|tostart|finished)/?$',
		'index.php?post_type=f365_livestream&f365_livestream_status=$matches[1]',
		'top'
	);

	// Single detail: /live-streaming/{obj_id}/  (numeric → details).
	add_rewrite_rule(
		'^live-streaming/([0-9]+)/?$',
		'index.php?post_type=f365_livestream&f365_livestream_id=$matches[1]',
		'top'
	);
}

/* 2. QUERY VARS ---------------------------------------------------------- */
add_filter( 'query_vars', 'f365_live_streaming_query_vars' );
function f365_live_streaming_query_vars( array $vars ): array {
	foreach ( [ 'f365_livestream_id', 'f365_livestream_status', 'f365_livestream_home' ] as $v ) {
		if ( ! in_array( $v, $vars, true ) ) { $vars[] = $v; }
	}
	return $vars;
}

/* 3. REQUEST — resolve the detail page to a real single BEFORE parse_query.
 *
 * The detail rewrite target is `post_type=f365_livestream&f365_livestream_id=N`.
 * If we only set `p` later (pre_get_posts), WP_Query::parse_query() has already
 * seen `post_type` with no `p`/`name` and locked the conditional tags to a
 * POST-TYPE ARCHIVE (is_post_type_archive=true, is_singular=false). The result
 * set then shows one post, but Yoast/templates still treat it as the archive —
 * which is why the detail <title> rendered as "Live Streamings Archive".
 *
 * Resolving in the `request` filter (which runs during WP::parse_request(),
 * before parse_query) injects `p` up front, so parse_query sees a clean single
 * and sets is_single/is_singular correctly. Mirrors match-rewrite.php exactly.
 * f365_livestream_id is kept (harmless alongside `p`) for the canonical/SEO. */
add_filter( 'request', 'f365_live_streaming_request' );
function f365_live_streaming_request( array $vars ): array {
	if ( empty( $vars['f365_livestream_id'] ) ) { return $vars; }

	$ls_id = (int) $vars['f365_livestream_id'];
	$wp_id = f365_live_streaming_resolve_id( $ls_id ) ?: $ls_id; // fall back to raw WP id

	$vars['post_type'] = 'f365_livestream';
	$vars['p']         = $wp_id;
	unset( $vars['name'] ); // avoid any name/archive lookup

	return $vars;
}

/* 3b. PRE_GET_POSTS — by-status listing query --------------------------- */
add_action( 'pre_get_posts', 'f365_live_streaming_pre_get_posts' );
function f365_live_streaming_pre_get_posts( WP_Query $query ): void {
	if ( is_admin() || ! $query->is_main_query() ) { return; }

	// ── By-status listing ──────────────────────────────────────────────
	$status = (string) $query->get( 'f365_livestream_status' );
	if ( $status && in_array( $status, [ 'inlive', 'tostart', 'finished' ], true ) ) {
		$query->set( 'post_type', 'f365_livestream' );
		$query->set( 'posts_per_page', 30 ); // mirrors PaginatorHandler page size
		f365_live_streaming_apply_status_query( $query, $status );
	}
}

/**
 * Resolve the WP post IDs for a status bucket by reading the LIVE match state
 * from the cloned wp_live_livematch table. The authoritative signal is the
 * live-feed `status` code (NOT finished_at, which is observed to be NULL on some
 * rows that are already status='RF' — e.g. id 531126).
 *
 * Status-code semantics, taken verbatim from live/models.py + live/signals.py:
 *   RF        = finished      (Resultado Final; signals maps RF -> situation 'finished')
 *   AD / SP   = delayed / suspended (NOT finished, NOT live, NOT upcoming)
 *   -1        = not started
 *   any other = in live       (minutes "45"/"90", "HT", "PL", "INT", ...)
 *
 * LiveMatch.in_live()  => status NOT IN ('RF','AD','SP','-1')
 * LiveMatch.not_started() => status IN ('AD','SP','-1')
 *
 * Bucket mapping (mirrors LiveStreaming.get_livestreaming_by_type intent, but
 * driven by the real feed status rather than the source's 105-min time heuristic):
 *   - finished : status = 'RF'                                 -> datetime DESC
 *   - inlive   : status NOT IN ('RF','AD','SP','-1')           -> datetime ASC
 *   - tostart  : status = '-1' AND datetime >= NOW()           -> datetime ASC
 *
 * Join: the livestream post's django_match_id (= competition_match.id) equals
 * wp_live_livematch.match_id (the LiveMatch.match OneToOne).
 *
 * @param string $status inlive|tostart|finished
 * @param int    $limit  max rows (0 = no limit). by-status uses 300; homepage 10.
 * @return int[] ordered WP post IDs.
 */
function f365_live_streaming_status_post_ids( string $status, int $limit = 0 ): array {
	global $wpdb;

	$LM = $wpdb->prefix . 'live_livematch';
	if ( function_exists( 'f365_src_table_exists' ) && ! f365_src_table_exists( $LM ) ) {
		return [];
	}

	switch ( $status ) {
		case 'tostart':
			// not_started '-1', restricted to the future (Brevemente).
			$where = "lm.status = '-1' AND lm.datetime >= NOW()";
			$order = 'ASC';
			break;

		case 'inlive':
			// LiveMatch.in_live(): anything that is not finished/postponed/
			// suspended/not-started is actively in play. Feed status is
			// authoritative, so no datetime window is applied.
			$where = "lm.status NOT IN ( 'RF', 'AD', 'SP', '-1' )";
			$order = 'ASC';
			break;

		case 'finished':
			$where = "lm.status = 'RF'";
			$order = 'DESC';
			break;

		default:
			return [];
	}

	$limit_sql = ( $limit > 0 ) ? $wpdb->prepare( ' LIMIT %d', $limit ) : '';

	// No user-supplied values are interpolated raw (the limit is prepared;
	// everything else is a literal or the table prefix).
	$sql = "SELECT pm.post_id
	          FROM {$wpdb->postmeta} pm
	          JOIN {$LM} lm          ON lm.match_id = pm.meta_value
	          JOIN {$wpdb->posts} p  ON p.ID = pm.post_id
	         WHERE pm.meta_key = 'django_match_id'
	           AND p.post_type = 'f365_livestream'
	           AND p.post_status = 'publish'
	           AND {$where}
	         ORDER BY lm.datetime {$order}, p.ID {$order}{$limit_sql}";

	return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
}

/**
 * WP_Query args for a status bucket — a post__in constrained to the live result
 * set, preserving the SQL date_time ordering. Applied to the main query
 * (pre_get_posts) OR merged into a standalone WP_Query (homepage lists).
 */
function f365_live_streaming_status_args( string $status, int $limit = 0 ): array {
	$ids = f365_live_streaming_status_post_ids( $status, $limit );
	return [
		'post_type' => 'f365_livestream',
		'post__in'  => $ids ?: [ 0 ], // [0] forces an empty result set
		'orderby'   => 'post__in',    // keep date_time ASC/DESC from the SQL
	];
}

/**
 * Apply the status set to the MAIN query (pre_get_posts for /live-streaming/{status}/).
 * Caps at 300 rows, mirroring live_streaming_by_status(max_value=300) before the
 * 30-per-page paginator.
 */
function f365_live_streaming_apply_status_query( WP_Query $query, string $status ): void {
	foreach ( f365_live_streaming_status_args( $status, 300 ) as $k => $v ) {
		$query->set( $k, $v );
	}
}

/**
 * Standalone WP_Query for a status bucket (used by the homepage lists).
 *
 * @param string $status inlive|tostart|finished
 * @param int    $limit  max rows (Django homepage uses 10).
 */
function f365_live_streaming_get_by_status( string $status, int $limit = 10 ): WP_Query {
	return new WP_Query( array_merge(
		[
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'no_found_rows'  => true,
		],
		f365_live_streaming_status_args( $status, $limit )
	) );
}

/* 4. RESOLVE HELPER (static-cached) -------------------------------------- */
function f365_live_streaming_resolve_id( int $ls_id ): ?int {
	static $cache = [];
	if ( isset( $cache[ $ls_id ] ) ) { return $cache[ $ls_id ]; }

	global $wpdb;
	// Match by django_livestream_id, scoped to the livestream post type (which
	// already disambiguates the non-unique legacy id). The previous query joined
	// on a 'livestreaming' marker that is never written — the importer stores
	// django_source_table = 'live_livestreaming' — so it always returned null,
	// which (via the request-filter fallback to p = django_id) caused 404s.
	$wp_id = $wpdb->get_var( $wpdb->prepare(
		"SELECT pm.post_id
		   FROM {$wpdb->postmeta} pm
		   JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		  WHERE pm.meta_key   = 'django_livestream_id'
		    AND pm.meta_value = %d
		    AND p.post_type   = 'f365_livestream'
		    AND p.post_status != 'trash'
		  LIMIT 1",
		$ls_id
	) );
	return $cache[ $ls_id ] = $wp_id ? (int) $wp_id : null;
}

/* 5. PERMALINK ----------------------------------------------------------- */
add_filter( 'post_type_link', 'f365_live_streaming_permalink', 10, 2 );
function f365_live_streaming_permalink( string $post_link, WP_Post $post ): string {
	if ( 'f365_livestream' !== $post->post_type ) { return $post_link; }
	return f365_live_streaming_url( $post->ID );
}

/* 6. URL HELPERS --------------------------------------------------------- */
function f365_live_streaming_url( int $post_id ): string {
	$django_id = (int) get_post_meta( $post_id, 'django_livestream_id', true );
	$final_id  = $django_id ?: $post_id;
	return user_trailingslashit( home_url( "live-streaming/{$final_id}" ) );
}

/** URL of a status listing: /live-streaming/{status}/ */
function f365_live_streaming_status_url( string $status ): string {
	return user_trailingslashit( home_url( "live-streaming/{$status}" ) );
}

/** Homepage URL. */
function f365_live_streaming_home_url(): string {
	return user_trailingslashit( home_url( 'live-streaming' ) );
}

/** Current livestream post from the active query (single details). */
function f365_get_current_livestream_post(): ?WP_Post {
	// Primary: the django-id query var set by the /live-streaming/{id}/ rewrite.
	$ls_id = (int) get_query_var( 'f365_livestream_id' );
	if ( $ls_id ) {
		global $post;
		if ( $post instanceof WP_Post && $post->post_type === 'f365_livestream' ) {
			return $post;
		}
		$id = f365_live_streaming_resolve_id( $ls_id );
		if ( $id ) {
			return get_post( $id );
		}
	}

	// Fallback: any singular livestream view whose query var is absent (e.g. the
	// post served via its default permalink rather than the numeric rewrite).
	// Without this, the detail SEO filters fall through to Yoast's stored
	// _yoast_wpseo_* postmeta, which can diverge from the source strings.
	if ( is_singular( 'f365_livestream' ) ) {
		$obj = get_queried_object();
		if ( $obj instanceof WP_Post && $obj->post_type === 'f365_livestream' ) {
			return $obj;
		}
		global $post;
		if ( $post instanceof WP_Post && $post->post_type === 'f365_livestream' ) {
			return $post;
		}
	}

	return null;
}

/* 7. TEMPLATE LOADER ----------------------------------------------------- */
add_filter( 'template_include', 'f365_live_streaming_template_include' );
function f365_live_streaming_template_include( string $template ): string {

	// Single details — /live-streaming/{id}/
	if ( get_query_var( 'f365_livestream_id' ) || is_singular( 'f365_livestream' ) ) {
		return f365_locate_live_streaming_template( 'single-f365_livestream' ) ?: $template;
	}

	// By-status listing — /live-streaming/{status}/
	if ( get_query_var( 'f365_livestream_status' ) ) {
		return f365_locate_live_streaming_template( 'f365-live-streaming-by-status' ) ?: $template;
	}

	// Homepage — /live-streaming/
	if ( get_query_var( 'f365_livestream_home' ) || is_post_type_archive( 'f365_livestream' ) ) {
		return f365_locate_live_streaming_template( 'archive-f365_livestream' ) ?: $template;
	}

	return $template;
}

/* 8. TEMPLATE LOCATOR ---------------------------------------------------- */
function f365_locate_live_streaming_template( string $name ): ?string {
	$theme = locate_template( [ "template-parts/live-streaming/{$name}.php", "{$name}.php" ], false, false );
	if ( $theme ) { return $theme; }
	if ( defined( 'F365_PLUGIN_DIR' ) ) {
		$file = trailingslashit( F365_PLUGIN_DIR ) . "templates/live-streaming-template/{$name}.php";
		if ( file_exists( $file ) ) { return $file; }
	}
	return null;
}

/* =========================================================
 * YOAST SEO — LIVE STREAMING
 *
 * Source (live/templates/live/live_streaming_base.html + _detail.html):
 *
 *   Listing (homepage + by-status; by-status inherits the base blocks — the
 *   "Em direto/Brevemente/Mais recentes" label is only an on-page <h3>):
 *     <title>        Live Streaming - Futebol 365
 *     description    Aceda ao calendário detalhado dos jogos agendados em Live Streaming
 *     og:title       Live Streaming
 *     og:url         request.get_full_path   (the current URL)
 *
 *   Detail (live_streaming_detail.html overrides title/description/meta_tags;
 *   {{ obj.get_livestreaming_title }} = "{competition}: {home} - {visitor} ({season})",
 *   which the importer stored verbatim as the post_title):
 *     <title>        LIVE STREAMING - {title} - Futebol 365
 *     description    Assista em direto e ao vivo o jogo {title}
 *     og:title       LIVE STREAMING - {title}
 *     og:url         {% url live-streaming-detail obj.id %} = /live-streaming/{id}/
 * ========================================================= */

/** True on the /live-streaming/{id}/ detail page. */
function f365_live_streaming_is_detail(): bool {
	return (bool) get_query_var( 'f365_livestream_id' ) || is_singular( 'f365_livestream' );
}

/** True on the homepage, by-status, or post-type archive listing. */
function f365_live_streaming_is_listing(): bool {
	return is_post_type_archive( 'f365_livestream' )
		|| (bool) get_query_var( 'f365_livestream_home' )
		|| (bool) get_query_var( 'f365_livestream_status' );
}

/**
 * Reconstruct Django get_livestreaming_title for a livestream post:
 *   "{competition.name}: {home_team} - {visitor_team} ({competition.season})"
 * where each team is TeamStep.__unicode__ (shortname + step/modality/gender
 * suffix, e.g. "Alverca S17"). Read from the source clone via the post's
 * django_match_id (= competition_match.id) so the stepped names AND the literal
 * hyphen match the live site.
 *
 * Why not get_the_title(): the importer stored the step-less team shortnames in
 * post_title ("Alverca"), and get_the_title() also runs wptexturize, which turns
 * " - " into an en-dash " – ". Building the string here avoids both. Falls back
 * to the raw post_title if the match row can't be resolved.
 */
function f365_live_streaming_match_title( WP_Post $post ): string {
	static $memo = [];
	if ( isset( $memo[ $post->ID ] ) ) { return $memo[ $post->ID ]; }

	$fallback = (string) $post->post_title;

	$match_id = (int) get_post_meta( $post->ID, 'django_match_id', true );
	if ( ! $match_id ) { return $memo[ $post->ID ] = $fallback; }

	global $wpdb;
	$M  = $wpdb->prefix . 'competition_match';
	$TS = $wpdb->prefix . 'team_teamstep';
	$T  = $wpdb->prefix . 'team_team';
	$C  = $wpdb->prefix . 'competition_competition';

	if ( function_exists( 'f365_src_table_exists' )
		&& ( ! f365_src_table_exists( $M ) || ! f365_src_table_exists( $TS )
			|| ! f365_src_table_exists( $T ) || ! f365_src_table_exists( $C ) ) ) {
		return $memo[ $post->ID ] = $fallback;
	}

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT c.name AS comp_name, c.season AS comp_season,
		        hteam.shortname AS home_shortname, hts.step AS home_step,
		        hts.gender AS home_gender, hts.modality AS home_modality,
		        vteam.shortname AS visitor_shortname, vts.step AS visitor_step,
		        vts.gender AS visitor_gender, vts.modality AS visitor_modality
		   FROM {$M} m
		   JOIN {$TS} hts   ON hts.id    = m.home_team_id
		   JOIN {$T}  hteam ON hteam.id  = hts.team_id
		   JOIN {$TS} vts   ON vts.id    = m.visitor_team_id
		   JOIN {$T}  vteam ON vteam.id  = vts.team_id
		   JOIN {$C}  c     ON c.id      = m.competition_id
		  WHERE m.id = %d
		  LIMIT 1",
		$match_id
	) );

	if ( ! $row ) { return $memo[ $post->ID ] = $fallback; }

	$home    = f365_live_streaming_teamstep_name( (string) $row->home_shortname,    (string) $row->home_step,    (string) $row->home_gender,    (string) $row->home_modality );
	$visitor = f365_live_streaming_teamstep_name( (string) $row->visitor_shortname, (string) $row->visitor_step, (string) $row->visitor_gender, (string) $row->visitor_modality );

	// Django: "%s: %s - %s (%s)" — literal hyphen, no texturize.
	return $memo[ $post->ID ] = sprintf( '%s: %s - %s (%s)', $row->comp_name, $home, $visitor, $row->comp_season );
}

/** Mirrors Django TeamStep.__unicode__ (shortname + step/modality/gender suffix). */
function f365_live_streaming_teamstep_name( string $shortname, string $step, string $gender, string $modality ): string {
	if ( function_exists( 'f365_match_teamstep_name' ) ) {
		return f365_match_teamstep_name( $shortname, $step, $gender, $modality );
	}
	$rest = '';
	if ( 'futsal' === $modality ) { $rest .= ' FS'; }
	if ( 'female' === $gender )   { $rest .= ' FM'; }
	$excluded = [ 'principal', 'b_team', 'c_team', 'veterans', 'reservations' ];
	if ( ! in_array( $step, $excluded, true ) ) {
		$rest .= ' ' . f365_live_streaming_step_label( $step );
	}
	if ( 'b_team' === $step )           { $rest .= ' B'; }
	elseif ( 'c_team' === $step )       { $rest .= ' C'; }
	elseif ( 'veterans' === $step )     { $rest .= ' VET'; }
	elseif ( 'reservations' === $step ) { $rest .= ' RES'; }
	$rest = trim( $rest );
	return $rest !== '' ? $shortname . ' ' . $rest : $shortname;
}

/** Step code → label (Django get_step_display); reuses the match module map when loaded. */
function f365_live_streaming_step_label( string $step ): string {
	if ( function_exists( 'f365_match_step_label' ) ) {
		return f365_match_step_label( $step );
	}
	static $map = [
		'sub_23' => 'S23', 'sub_22' => 'S22', 'sub_21' => 'S21', 'sub_20' => 'S20',
		'sub_19' => 'S19', 'sub_19_b' => 'S19 B', 'sub_18' => 'S18', 'sub_17' => 'S17',
		'sub_17_b' => 'S17 B', 'sub_16' => 'S16', 'sub_15' => 'S15', 'sub_15_b' => 'S15 B',
		'sub_14' => 'S14', 'sub_13' => 'S13', 'sub_13_b' => 'S13 B', 'sub_12' => 'S12',
		'sub_12_b' => 'S12 B', 'sub_11' => 'S11', 'sub_11_b' => 'S11 B', 'sub_10' => 'S10',
		'sub_10_b' => 'S10 B', 'sub_9' => 'S9',
	];
	return $map[ $step ] ?? $step;
}

/** Title WITHOUT the " - Futebol 365" suffix (= the og:title value), or null. */
function f365_live_streaming_seo_title_core(): ?string {
	if ( f365_live_streaming_is_detail() ) {
		$post = f365_get_current_livestream_post();
		return ( $post instanceof WP_Post ) ? 'LIVE STREAMING - ' . f365_live_streaming_match_title( $post ) : null;
	}
	if ( f365_live_streaming_is_listing() ) {
		return 'Live Streaming';
	}
	return null;
}

/** Meta description (= og:description value), or null to leave Yoast untouched. */
function f365_live_streaming_seo_description(): ?string {
	if ( f365_live_streaming_is_detail() ) {
		$post = f365_get_current_livestream_post();
		return ( $post instanceof WP_Post ) ? 'Assista em direto e ao vivo o jogo ' . f365_live_streaming_match_title( $post ) : null;
	}
	if ( f365_live_streaming_is_listing() ) {
		return 'Aceda ao calendário detalhado dos jogos agendados em Live Streaming';
	}
	return null;
}

/* ── <title> ─────────────────────────────────────────────────────────── */
add_filter( 'wpseo_title', 'f365_live_streaming_wpseo_title', 20 );
function f365_live_streaming_wpseo_title( string $title ): string {
	$core = f365_live_streaming_seo_title_core();
	return ( $core !== null ) ? $core . ' - Futebol 365' : $title;
}

/* ── meta description ────────────────────────────────────────────────── */
add_filter( 'wpseo_metadesc', 'f365_live_streaming_wpseo_metadesc', 20 );
function f365_live_streaming_wpseo_metadesc( string $desc ): string {
	$d = f365_live_streaming_seo_description();
	return ( $d !== null ) ? $d : $desc;
}

/* ── og/twitter title (no site-name suffix) ──────────────────────────── */
function f365_live_streaming_og_title( string $title ): string {
	$core = f365_live_streaming_seo_title_core();
	return ( $core !== null ) ? $core : $title;
}
add_filter( 'wpseo_opengraph_title', 'f365_live_streaming_og_title', 20 );
add_filter( 'wpseo_twitter_title', 'f365_live_streaming_og_title', 20 );

/* ── og/twitter description (same string as the meta description) ─────── */
function f365_live_streaming_og_desc( string $desc ): string {
	$d = f365_live_streaming_seo_description();
	return ( $d !== null ) ? $d : $desc;
}
add_filter( 'wpseo_opengraph_desc', 'f365_live_streaming_og_desc', 20 );
add_filter( 'wpseo_twitter_desc', 'f365_live_streaming_og_desc', 20 );

/* ── canonical + og:url ──────────────────────────────────────────────────
 * Detail  → /live-streaming/{id}/            (matches {% url live-streaming-detail %})
 * Status  → /live-streaming/{status}/[page/N/]   (self-ref; = request.get_full_path)
 * Home    → /live-streaming/
 * og:url is wired to the same builder since the source og:url equals these URLs.
 * Priority 40 runs after the generic override (20) and pagination canonical (30).
 * --------------------------------------------------------------------------- */
function f365_live_streaming_wpseo_canonical( $canonical ) {

	// Detail page.
	$ls_id = (int) get_query_var( 'f365_livestream_id' );
	if ( $ls_id ) {
		return user_trailingslashit( home_url( "live-streaming/{$ls_id}" ) );
	}

	// By-status listing (self-referencing, including native pagination).
	$status = (string) get_query_var( 'f365_livestream_status' );
	if ( $status && in_array( $status, [ 'inlive', 'tostart', 'finished' ], true ) ) {
		$path  = "live-streaming/{$status}";
		$paged = (int) get_query_var( 'paged' );
		if ( $paged >= 2 ) {
			$path .= '/page/' . $paged;
		}
		return user_trailingslashit( home_url( $path ) );
	}

	// Homepage.
	if ( get_query_var( 'f365_livestream_home' ) || is_post_type_archive( 'f365_livestream' ) ) {
		return user_trailingslashit( home_url( 'live-streaming' ) );
	}

	return $canonical;
}
add_filter( 'wpseo_canonical', 'f365_live_streaming_wpseo_canonical', 40 );
add_filter( 'wpseo_opengraph_url', 'f365_live_streaming_wpseo_canonical', 40 );
