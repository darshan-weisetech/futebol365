<?php
/**
 * Futebol365 — Match Rewrite Rules & Routing.
 *
 * Mirrors Django's match URL exactly:
 *   /jogo/{match_id}/   (Match.get_absolute_url → reverse('get_match', [id]))
 *
 * {match_id} = Django Match.id, stored on each match post as 'django_match_id'.
 * Falls back to the raw WP post ID if no Django id is present.
 *
 * Follows the same architecture as team-rewrite.php / competition-rewrite.php.
 *
 * @package Futebol365
 * @since   2.3.0
 */

defined( 'ABSPATH' ) || exit;

/*
 * Ensure the Match module is loaded on web/admin requests so the lazy hydrator
 * (F365_Match_Hydrator, defined in module-competitions-matches.php) can register
 * its front-end / admin lifecycle hooks. This mirrors how livegames-rewrite.php
 * lazy-loads module-live-games.php. It loads CLASSES/HOOKS only — it does NOT
 * change any rewrite rule, permalink, CPT, taxonomy, or use legacy_id for
 * routing. Under WP-CLI the migration command require_once's the same file
 * (deduped). function_exists / class_exists guards prevent any redeclaration.
 */
// if ( ! class_exists( 'F365_Match_Hydrator' ) ) {
// 	$f365_match_module = dirname( __DIR__ ) . '/cli/modules/module-competitions-matches.php';
// 	if ( is_readable( $f365_match_module ) ) {
// 		require_once $f365_match_module;
// 	}
// }

/* 1. REWRITE RULES ------------------------------------------------------- */
add_action( 'init', 'f365_match_rewrite_rules', 20 );
function f365_match_rewrite_rules(): void {
	add_rewrite_rule(
		'^jogo/([0-9]+)/?$',
		'index.php?post_type=f365_match&f365_match_id=$matches[1]',
		'top'
	);
}

/* 2. QUERY VARS ---------------------------------------------------------- */
add_filter( 'query_vars', 'f365_match_query_vars' );
function f365_match_query_vars( array $vars ): array {
	if ( ! in_array( 'f365_match_id', $vars, true ) ) { $vars[] = 'f365_match_id'; }
	return $vars;
}

/* 3. PRE_GET_POSTS — resolve Django match id → match post ----------------- */
add_filter( 'request', 'f365_match_request' );
function f365_match_request( array $vars ): array {
	if ( empty( $vars['f365_match_id'] ) ) { return $vars; }

	$match_id = (int) $vars['f365_match_id'];
	$wp_id    = f365_match_resolve_id( $match_id ) ?: $match_id; // fall back to raw WP id

	$vars['post_type'] = 'f365_match';
	$vars['p']         = $wp_id;

	// Drop anything that would re-trigger an archive / name lookup.
	unset( $vars['f365_match_id'], $vars['name'] );

	return $vars;
}

/* 4. RESOLVE HELPER (static-cached) -------------------------------------- */
function f365_match_resolve_id( int $match_id ): ?int {
	static $cache = [];
	if ( isset( $cache[ $match_id ] ) ) { return $cache[ $match_id ]; }

	/*
	 * Persist the (django id -> post id) mapping in the object cache. The
	 * mapping is immutable for the life of a post, so a long TTL is safe and
	 * this avoids re-hitting postmeta on every request (default caching, not a
	 * transient). A "not found" is cached as 0 so misses are negative-cached.
	 */
	$group     = 'f365';
	$cache_key = 'match_resolve_id_' . $match_id;
	$found     = false;
	$cached    = wp_cache_get( $cache_key, $group, false, $found );
	if ( $found ) {
		return $cache[ $match_id ] = ( $cached ? (int) $cached : null );
	}

	global $wpdb;

	/*
	 * Rewritten for index use:
	 *   - JOIN wp_posts instead of `post_id IN (SELECT ... )`, which previously
	 *     materialised every f365_match ID.
	 *   - meta_value bound as a string (%s) so the (meta_key, meta_value) index
	 *     prefix is usable; %d forced a numeric cast on the longtext column and
	 *     defeated it. Results are identical — django ids are stored as plain
	 *     integer strings.
	 */
	$wp_id = $wpdb->get_var( $wpdb->prepare(
		"SELECT pm.post_id
		 FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE pm.meta_key = 'django_match_id'
		   AND pm.meta_value = %s
		   AND p.post_type = 'f365_match'
		   AND p.post_status != 'trash'
		 LIMIT 1",
		(string) $match_id
	) );

	$resolved = $wp_id ? (int) $wp_id : null;
	wp_cache_set( $cache_key, $resolved ?? 0, $group, 12 * HOUR_IN_SECONDS );
	return $cache[ $match_id ] = $resolved;
}

/* 5. PERMALINK ----------------------------------------------------------- */
add_filter( 'post_type_link', 'f365_match_permalink', 10, 2 );
function f365_match_permalink( string $post_link, WP_Post $post ): string {
	if ( 'f365_match' !== $post->post_type ) { return $post_link; }
	return f365_match_url( $post->ID );
}

/* 6. URL HELPER ---------------------------------------------------------- */
function f365_match_url( int $post_id ): string {
	$django_id = (int) get_post_meta( $post_id, 'django_match_id', true );
	$final_id  = $django_id ?: $post_id;
	return user_trailingslashit( home_url( "jogo/{$final_id}" ) );
}