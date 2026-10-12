<?php
/**
 * Futebol365 — Live Games Rewrite Rules & Routing.
 *
 * Front-end request layer for /jogos-em-direto/{legacy_id}/. Kept in its own
 * file (separate from the data layer in
 * includes/cli/modules/module-live-games.php).
 *
 * Routing uses the proven `request`-filter pattern (match-rewrite.php):
 *   resolve legacy_id → WP post ID, then set `p` (primary-key lookup) BEFORE
 *   the main query is built, so WordPress loads a clean single post and never
 *   reaches the 404 branch.
 *
 * Self-contained: the post type, the legacy-table tag and the source tables
 * are referenced by literal value / $wpdb->prefix (no external config class).
 *
 * @package Futebol365
 * @since   2.5.0
 */

defined( 'ABSPATH' ) || exit;

/* ============================================================================
 * 4. ROUTING  (`request`-filter pattern used by match-rewrite.php).
 * ========================================================================== */

/* 4.1 Rewrite rules ------------------------------------------------------- */
if ( ! function_exists( 'f365_livegames_rewrite_rules' ) ) {
	add_action( 'init', 'f365_livegames_rewrite_rules', 20 );
	function f365_livegames_rewrite_rules(): void {
		add_rewrite_rule(
			'^jogos-em-direto/([0-9]+)/?$',
			'index.php?post_type=f365_livegames&f365_livematch_id=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^jogos-em-direto/page/([0-9]+)/?$',
			'index.php?post_type=f365_livegames&f365_section=lista&paged=$matches[1]',
			'top'
		);
	}
}

/* 4.2 Query vars ---------------------------------------------------------- */
if ( ! function_exists( 'f365_livegames_query_vars' ) ) {
	add_filter( 'query_vars', 'f365_livegames_query_vars' );
	function f365_livegames_query_vars( array $vars ): array {
		if ( ! in_array( 'f365_livematch_id', $vars, true ) ) {
			$vars[] = 'f365_livematch_id';
		}
		return $vars;
	}
}

/* 4.3 Resolve legacy_id -> WP id (static-cached, self-healing) ------------ */
if ( ! function_exists( 'f365_livegames_resolve_id' ) ) {
	function f365_livegames_resolve_id( int $legacy_id ): ?int {
		static $cache = [];
		if ( array_key_exists( $legacy_id, $cache ) ) {
			return $cache[ $legacy_id ];
		}
		global $wpdb;
		$tag       = 'wp_live_livematch'; // value stored in wp_posts.legacy_table
		$post_type = 'f365_livegames';

		// Pass A: indexed column lookup.
		$wp_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			  WHERE legacy_table = %s AND legacy_id = %d AND post_type = %s LIMIT 1",
			$tag, $legacy_id, $post_type
		) );

		// Pass B: fall back to the stable 'lm-{id}' slug and self-heal columns.
		if ( ! $wp_id ) {
			$wp_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				  WHERE post_name = %s AND post_type = %s LIMIT 1",
				'lm-' . $legacy_id, $post_type
			) );
			if ( $wp_id ) {
				$wpdb->update(
					$wpdb->posts,
					[ 'legacy_table' => $tag, 'legacy_id' => $legacy_id ],
					[ 'ID' => $wp_id ],
					[ '%s', '%d' ],
					[ '%d' ]
				);
			}
		}

		return $cache[ $legacy_id ] = $wp_id ?: null;
	}
}

/* 4.4 request filter: convert /jogos-em-direto/{id}/ into a clean single
 *     primary-key query so WordPress never hits the 404 branch. ----------- */
if ( ! function_exists( 'f365_livegames_request' ) ) {
	add_filter( 'request', 'f365_livegames_request' );
	function f365_livegames_request( array $vars ): array {
		if ( empty( $vars['f365_livematch_id'] ) ) {
			return $vars;
		}
		$legacy_id = (int) $vars['f365_livematch_id'];
		$wp_id     = f365_livegames_resolve_id( $legacy_id );

		if ( $wp_id ) {
			$vars['post_type'] = 'f365_livegames';
			$vars['p']         = $wp_id;            // primary-key lookup
			unset( $vars['name'], $vars['f365_livematch_id'] ); // no slug/archive re-trigger
		}
		return $vars;
	}
}

/* 4.6 Permalinks: output /jogos-em-direto/{legacy_id}/ -------------------- */
if ( ! function_exists( 'f365_livegames_permalink' ) ) {
	add_filter( 'post_type_link', 'f365_livegames_permalink', 10, 2 );
	function f365_livegames_permalink( string $post_link, WP_Post $post ): string {
		if ( 'f365_livegames' !== $post->post_type ) {
			return $post_link;
		}
		return f365_livegames_url( (int) $post->ID );
	}
}

if ( ! function_exists( 'f365_livegames_url' ) ) {
	function f365_livegames_url( int $post_id ): string {
		global $wpdb;
		$legacy = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT legacy_id FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
			$post_id
		) );
		$final = $legacy ?: $post_id;
		return user_trailingslashit( home_url( "jogos-em-direto/{$final}" ) );
	}
}

/* 4.7 Template routing ---------------------------------------------------- */
if ( ! function_exists( 'f365_livegames_template_include' ) ) {
	add_filter( 'template_include', 'f365_livegames_template_include' );
	function f365_livegames_template_include( string $template ): string {
		if ( is_singular( 'f365_livegames' ) ) {
			$found = f365_locate_livegames_template( 'single-f365_livegames' );
			return $found ?: $template;
		}
		if ( is_post_type_archive( 'f365_livegames' ) ) {
			$found = f365_locate_livegames_template( 'archive-f365_livegames' );
			if ( $found ) {
				return $found;
			}
		}
		return $template;
	}
}

if ( ! function_exists( 'f365_locate_livegames_template' ) ) {
	function f365_locate_livegames_template( string $name ): ?string {
		$theme = locate_template( [ "template-parts/livegames/{$name}.php", "{$name}.php" ], false, false );
		if ( $theme ) {
			return $theme;
		}
		if ( defined( 'F365_PLUGIN_DIR' ) ) {
			$file = trailingslashit( F365_PLUGIN_DIR ) . "templates/livegames-template/{$name}.php";
			if ( file_exists( $file ) ) {
				return $file;
			}
		}
		return null;
	}
}

/* ============================================================================
 * 4.8 Yoast SEO — titles / descriptions for the routed single + archive.
 *
 * Names are read DIRECTLY from the cloned source tables (never postmeta):
 *   legacy_id -> {prefix}live_livematch (is_mapped, match_id, competition_id,
 *   home_team_name, visitor_team_name); competition name from
 *   {prefix}competition_competition; and, for mapped games, the teamstep display
 *   (TeamStep.__unicode__, e.g. "Alverca S17") from {prefix}competition_match ->
 *   {prefix}team_teamstep -> {prefix}team_team.
 *
 * Mirrors live/templates/live/live_matches.html + live_match_details.html:
 *
 *   Archive  <title>      "Jogos em direto - Futebol 365"
 *            description   "Acompanhe os jogos de futebol em direto. Resultados de futebol ao vivo e jogos em directo para mais de 100 ligas diferentes"
 *            og:title      "Jogos em direto"
 *            og:desc       <description> + "."   (the source adds the period only on og)
 *
 *   Detail   <title>      "Jogo em direto - {home} {sep} {visitor} - {competition} - Futebol 365"
 *            description   "O jogo em direto, com as principais ocorrências e estatísticas da partida entre {home} e {visitor} - {competition}"
 *            og:title      "Jogo em direto - {home} vs {visitor} - {competition}"
 *            og:desc       = <description>
 *   {sep} = "vs" when is_mapped, "X" otherwise — but og:title always uses "vs"
 *   (exactly as the source template does). Mapped games use the teamstep names;
 *   unmapped games use the live_livematch feed names.
 * ========================================================================== */

/* Source-table resolver: {home, visitor, competition, mapped} for a livegame. */
if ( ! function_exists( 'f365_livegames_seo_data' ) ) {
	function f365_livegames_seo_data( int $post_id ): ?array {
		static $memo = [];
		if ( array_key_exists( $post_id, $memo ) ) {
			return $memo[ $post_id ];
		}

		global $wpdb;
		$legacy_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT legacy_id FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
			$post_id
		) );
		if ( ! $legacy_id ) {
			return $memo[ $post_id ] = null;
		}

		$LM = $wpdb->prefix . 'live_livematch';
		$C  = $wpdb->prefix . 'competition_competition';

		$lm = $wpdb->get_row( $wpdb->prepare(
			"SELECT lm.is_mapped, lm.match_id, lm.home_team_name, lm.visitor_team_name,
			        c.name AS competition_name
			   FROM {$LM} lm
			   LEFT JOIN {$C} c ON c.id = lm.competition_id
			  WHERE lm.id = %d
			  LIMIT 1",
			$legacy_id
		) );
		if ( ! $lm ) {
			return $memo[ $post_id ] = null;
		}

		$mapped  = ( 't' === (string) $lm->is_mapped );
		$home    = '';
		$visitor = '';

		// Mapped → competition_match teamstep display names ("Alverca S17").
		if ( $mapped && (int) $lm->match_id ) {
			$CM = $wpdb->prefix . 'competition_match';
			$TS = $wpdb->prefix . 'team_teamstep';
			$T  = $wpdb->prefix . 'team_team';

			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT hteam.shortname AS home_shortname, hts.step AS home_step,
				        hts.gender AS home_gender, hts.modality AS home_modality,
				        vteam.shortname AS visitor_shortname, vts.step AS visitor_step,
				        vts.gender AS visitor_gender, vts.modality AS visitor_modality
				   FROM {$CM} m
				   JOIN {$TS} hts   ON hts.id   = m.home_team_id
				   JOIN {$T}  hteam ON hteam.id = hts.team_id
				   JOIN {$TS} vts   ON vts.id   = m.visitor_team_id
				   JOIN {$T}  vteam ON vteam.id = vts.team_id
				  WHERE m.id = %d
				  LIMIT 1",
				(int) $lm->match_id
			) );
			if ( $row ) {
				$home    = f365_livegames_teamstep_name( (string) $row->home_shortname, (string) $row->home_step, (string) $row->home_gender, (string) $row->home_modality );
				$visitor = f365_livegames_teamstep_name( (string) $row->visitor_shortname, (string) $row->visitor_step, (string) $row->visitor_gender, (string) $row->visitor_modality );
			}
		}

		// Unmapped (or mapped fallback) → the live_livematch feed names.
		if ( '' === $home ) {
			$home = trim( (string) $lm->home_team_name );
		}
		if ( '' === $visitor ) {
			$visitor = trim( (string) $lm->visitor_team_name );
		}

		return $memo[ $post_id ] = [
			'home'        => $home,
			'visitor'     => $visitor,
			'competition' => trim( (string) $lm->competition_name ),
			'mapped'      => $mapped,
		];
	}
}

/* Mirrors Django TeamStep.__unicode__ (shortname + step/modality/gender suffix). */
if ( ! function_exists( 'f365_livegames_teamstep_name' ) ) {
	function f365_livegames_teamstep_name( string $shortname, string $step, string $gender, string $modality ): string {
		if ( function_exists( 'f365_match_teamstep_name' ) ) {
			return f365_match_teamstep_name( $shortname, $step, $gender, $modality );
		}
		$rest = '';
		if ( 'futsal' === $modality ) { $rest .= ' FS'; }
		if ( 'female' === $gender )   { $rest .= ' FM'; }
		$excluded = [ 'principal', 'b_team', 'c_team', 'veterans', 'reservations' ];
		if ( ! in_array( $step, $excluded, true ) ) {
			$rest .= ' ' . f365_livegames_step_label( $step );
		}
		if ( 'b_team' === $step )           { $rest .= ' B'; }
		elseif ( 'c_team' === $step )       { $rest .= ' C'; }
		elseif ( 'veterans' === $step )     { $rest .= ' VET'; }
		elseif ( 'reservations' === $step ) { $rest .= ' RES'; }
		$rest = trim( $rest );
		return '' !== $rest ? $shortname . ' ' . $rest : $shortname;
	}
}

/* Step code → label (Django get_step_display); reuses the match map when loaded. */
if ( ! function_exists( 'f365_livegames_step_label' ) ) {
	function f365_livegames_step_label( string $step ): string {
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
}

/* Detail title segment: "Jogo em direto - {home} {sep} {visitor} - {competition}". */
if ( ! function_exists( 'f365_livegames_detail_core' ) ) {
	function f365_livegames_detail_core( array $d, string $sep ): string {
		return sprintf( 'Jogo em direto - %s %s %s - %s', $d['home'], $sep, $d['visitor'], $d['competition'] );
	}
}

if ( ! defined( 'F365_LIVEGAMES_ARCHIVE_DESC' ) ) {
	define( 'F365_LIVEGAMES_ARCHIVE_DESC', 'Acompanhe os jogos de futebol em direto. Resultados de futebol ao vivo e jogos em directo para mais de 100 ligas diferentes' );
}

/* ── <title> ─────────────────────────────────────────────────────────── */
if ( ! function_exists( 'f365_livegames_wpseo_title' ) ) {
	add_filter( 'wpseo_title', 'f365_livegames_wpseo_title', 20 );
	function f365_livegames_wpseo_title( string $title ): string {
		if ( is_post_type_archive( 'f365_livegames' ) ) {
			return 'Jogos em direto - Futebol 365';
		}
		if ( is_singular( 'f365_livegames' ) ) {
			$d = f365_livegames_seo_data( (int) get_queried_object_id() );
			if ( $d ) {
				$sep = $d['mapped'] ? 'vs' : 'X';
				return f365_livegames_detail_core( $d, $sep ) . ' - Futebol 365';
			}
		}
		return $title;
	}
}

/* ── meta description ────────────────────────────────────────────────── */
if ( ! function_exists( 'f365_livegames_wpseo_metadesc' ) ) {
	add_filter( 'wpseo_metadesc', 'f365_livegames_wpseo_metadesc', 20 );
	function f365_livegames_wpseo_metadesc( string $desc ): string {
		if ( is_post_type_archive( 'f365_livegames' ) ) {
			return F365_LIVEGAMES_ARCHIVE_DESC;
		}
		if ( is_singular( 'f365_livegames' ) ) {
			$d = f365_livegames_seo_data( (int) get_queried_object_id() );
			if ( $d ) {
				return sprintf(
					'O jogo em direto, com as principais ocorrências e estatísticas da partida entre %s e %s - %s',
					$d['home'], $d['visitor'], $d['competition']
				);
			}
		}
		return $desc;
	}
}

/* ── og/twitter title (no site-name suffix; og:title always uses "vs") ── */
if ( ! function_exists( 'f365_livegames_og_title' ) ) {
	function f365_livegames_og_title( string $title ): string {
		if ( is_post_type_archive( 'f365_livegames' ) ) {
			return 'Jogos em direto';
		}
		if ( is_singular( 'f365_livegames' ) ) {
			$d = f365_livegames_seo_data( (int) get_queried_object_id() );
			if ( $d ) {
				return f365_livegames_detail_core( $d, 'vs' );
			}
		}
		return $title;
	}
	add_filter( 'wpseo_opengraph_title', 'f365_livegames_og_title', 20 );
	add_filter( 'wpseo_twitter_title', 'f365_livegames_og_title', 20 );
}

/* ── og/twitter description (archive adds a trailing period) ───────────── */
if ( ! function_exists( 'f365_livegames_og_desc' ) ) {
	function f365_livegames_og_desc( string $desc ): string {
		if ( is_post_type_archive( 'f365_livegames' ) ) {
			return F365_LIVEGAMES_ARCHIVE_DESC . '.';
		}
		if ( is_singular( 'f365_livegames' ) ) {
			$d = f365_livegames_seo_data( (int) get_queried_object_id() );
			if ( $d ) {
				return sprintf(
					'O jogo em direto, com as principais ocorrências e estatísticas da partida entre %s e %s - %s',
					$d['home'], $d['visitor'], $d['competition']
				);
			}
		}
		return $desc;
	}
	add_filter( 'wpseo_opengraph_desc', 'f365_livegames_og_desc', 20 );
	add_filter( 'wpseo_twitter_desc', 'f365_livegames_og_desc', 20 );
}
