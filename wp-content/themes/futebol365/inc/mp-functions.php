<?php
defined( 'ABSPATH' ) || exit;



/* ── Config ─────────────────────────────────────────────────────────────────
 */
if ( ! defined( 'F365_MEDIA_DIR' ) ) {
	define( 'F365_MEDIA_DIR', 'media' );   // root-relative, e.g. /media/images/person/...
}
if ( ! defined( 'F365_STATIC_DIR' ) ) {
	define( 'F365_STATIC_DIR', 'static' ); // root-relative (kept for any static assets)
}
if ( ! defined( 'F365_SRC_PRESIDENT' ) ) {
	define( 'F365_SRC_PRESIDENT', 'wp_person_president' ); // legacy_table value on president posts
}
if ( ! defined( 'F365_SRC_TEAM' ) ) {
	define( 'F365_SRC_TEAM', 'wp_team_team' );          // legacy_table value on team posts
}
if ( ! defined( 'F365_SRC_COACH' ) ) {
	define( 'F365_SRC_COACH', 'wp_person_coach' );          // legacy_table value on coach posts
}

if ( ! defined( 'F365_SRC_REFEREE' ) ) {
	define( 'F365_SRC_REFEREE', 'wp_person_referee' );
}
 
if ( ! defined( 'F365_NATIONAL_SCOPES' ) ) {
	define( 'F365_NATIONAL_SCOPES', 'international_national_team,international_friendly_nationals' );
}
 
/**
 * Resolve a configured base (media/static) to an absolute URL base.
 * Accepts a full URL (used as-is) or a root-relative folder (vs home_url()).
 */
function f365_src_base_url( string $dir ): string {
	$dir = trim( $dir );
	if ( $dir === '' ) {
		return rtrim( home_url(), '/' );
	}
	if ( stripos( $dir, 'http://' ) === 0 || stripos( $dir, 'https://' ) === 0 ) {
		return rtrim( $dir, '/' );
	}
	return rtrim( home_url( '/' . ltrim( $dir, '/' ) ), '/' );
}
 
 
/* ===========================================================================
 * FEATURE IMAGES — SOURCE-SITE logic (UNCHANGED).
 * =========================================================================== */
 
/** Person profile photo by Django person id + role: images/person/{id}_{role}.jpg. */
function f365_src_person_photo( int $person_id, string $role = 'president' ): string {
 
	$base = f365_src_base_url( F365_MEDIA_DIR ) . '/images/person/';
 
	$role    = strtolower( trim( $role ) );
	$allowed = [ 'president', 'coach', 'player', 'referee' ];
	if ( ! in_array( $role, $allowed, true ) ) {
		$role = 'president';
	}
 
	if ( $person_id > 0 ) {
		return $base . $person_id . '_' . $role . '.jpg';
	}
	return $base . 'no-image.jpg';
}
 
/** Team logo by source logo filename: team/{logo}. */
function f365_src_team_logo( string $logo_filename ): string {
	$logo_filename = trim( $logo_filename );
	if ( $logo_filename === '' ) {
		return '';
	}
	$base = f365_src_base_url( F365_MEDIA_DIR );
	if ( strpos( $logo_filename, 'team/' ) === 0 ) {
		return $base . '/' . ltrim( $logo_filename, '/' );
	}
	return $base . '/team/' . ltrim( $logo_filename, '/' );
}
 
 
/* ===========================================================================
 * COUNTRY & FLAG — EXISTING taxonomy/term logic (reused).
 * One resolution path for the whole module.
 * =========================================================================== */
 
/**
 * f365_get_president_country()
 *
 * Resolves an f365_country term ID into a display-ready array.
 * Country flag uses the ACF image field (field_country_flag) on the term,
 * which stores a WP attachment ID. Returns the SVG URL via wp_get_attachment_image_url().
 *
 * @param  int   $term_id  f365_country term ID.
 * @return array
 */
function f365_get_president_country( int $term_id ): array {

	$empty = [
		'term_id'  => 0,
		'name'     => '',
		'slug'     => '',
		'flag_url' => '',
	];

	if ( $term_id <= 0 ) {
		return $empty;
	}

	$term = get_term( $term_id, 'f365_country' );
	if ( ! $term instanceof WP_Term ) {
		return $empty;
	}

	$flag_url = '';

	if ( function_exists( 'get_field' ) ) {
		$flag_img_id = get_field( 'field_country_flag', $term );
		if ( is_numeric( $flag_img_id ) && $flag_img_id > 0 ) {
			$flag_url = wp_get_attachment_image_url( (int) $flag_img_id, 'full' ) ?: '';
		}
	}

	return [
		'term_id'  => $term->term_id,
		'name'     => $term->name,
		'slug'     => $term->slug,
		'flag_url' => $flag_url,
	];
}


/**
 * Country sub-array: name/slug from the custom table, FLAG from the term
 * (via the existing f365_get_president_country() helper). Shared shape:
 *   [ term_id, id, name, slug, flag_url, flag_class ]
 */
function f365_src_country_arr( int $legacy_country_id, string $name, string $slug ): array {
 
	$out = [
		'term_id'    => 0,
		'id'         => $legacy_country_id,
		'name'       => $name,
		'slug'       => $slug,
		'flag_url'   => '',
		'flag_class' => $legacy_country_id > 0 ? 'flag-icon flag-icon-' . $legacy_country_id : '',
	];
 
	if ( $legacy_country_id <= 0 ) {
		return $out; // early return — nothing to resolve
	}
 
	$term_id = f365_country_term_from_legacy( $legacy_country_id );
	if ( $term_id <= 0 ) {
		return $out; // no term yet: keep custom-table text; flag '' (template guards)
	}
 
	if ( function_exists( 'f365_get_president_country' ) ) {
		$c = f365_get_president_country( $term_id );
		if ( is_array( $c ) ) {
			$out['term_id']  = isset( $c['term_id'] ) ? (int) $c['term_id'] : $term_id;
			$out['name']     = ( $name !== '' ) ? $name : (string) ( $c['name'] ?? '' );
			$out['slug']     = ( $slug !== '' ) ? $slug : (string) ( $c['slug'] ?? '' );
			$out['flag_url'] = (string) ( $c['flag_url'] ?? '' );
			return $out;
		}
	}
 
	$out['term_id'] = $term_id;
	return $out;
}
 
/** Empty country sub-array — SAME shape as f365_src_country_arr(). */
function f365_src_country_empty(): array {
	return [
		'term_id'    => 0,
		'id'         => 0,
		'name'       => '',
		'slug'       => '',
		'flag_url'   => '',
		'flag_class' => '',
	];
}
 
/**
 * Flag URL only, by legacy country id — taxonomy/term path (the existing logic).
 * Used by team rows. Cached per request so repeated team countries cost nothing.
 */
function f365_country_flag_from_legacy( int $legacy_country_id ): string {
	static $cache = [];
	if ( array_key_exists( $legacy_country_id, $cache ) ) {
		return $cache[ $legacy_country_id ];
	}
	if ( $legacy_country_id <= 0 ) {
		return $cache[ $legacy_country_id ] = '';
	}
 
	$term_id = f365_country_term_from_legacy( $legacy_country_id );
	if ( $term_id <= 0 ) {
		return $cache[ $legacy_country_id ] = '';
	}
	if ( function_exists( 'f365_get_president_country' ) ) {
		$c = f365_get_president_country( $term_id );
		if ( is_array( $c ) && isset( $c['flag_url'] ) ) {
			return $cache[ $legacy_country_id ] = (string) $c['flag_url'];
		}
	}
	return $cache[ $legacy_country_id ] = '';
}
 
/** legacy country id -> f365_country term id, via 'django_country_id' termmeta. Cached. */
function f365_country_term_from_legacy( int $legacy_country_id ): int {
	static $cache = [];
	if ( array_key_exists( $legacy_country_id, $cache ) ) {
		return $cache[ $legacy_country_id ];
	}
	if ( $legacy_country_id <= 0 ) {
		return $cache[ $legacy_country_id ] = 0;
	}
 
	global $wpdb;
	$term_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT term_id FROM {$wpdb->termmeta}
		  WHERE meta_key = 'django_country_id' AND meta_value = %d
		  LIMIT 1",
		$legacy_country_id
	) );
 
	return $cache[ $legacy_country_id ] = $term_id;
}
 
 
/* ===========================================================================
 * INFRA — table existence + legacy id resolution + logger
 * =========================================================================== */
 
/** True if a (cloned) table exists. Cached. */
function f365_src_table_exists( string $table ): bool {
	global $wpdb;
	static $cache = [];
	if ( array_key_exists( $table, $cache ) ) {
		return $cache[ $table ];
	}
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	return $cache[ $table ] = ( is_string( $found ) && $found === $table );
}
 
if ( ! function_exists( 'f365_president_legacy_id' ) ) {
	/** Resolve a WP post id → its legacy (Django) president id. 0 if unresolved. Cached. */
	function f365_president_legacy_id( int $post_id ): int {
		static $cache = [];
		if ( array_key_exists( $post_id, $cache ) ) {
			return $cache[ $post_id ];
		}
		if ( $post_id <= 0 ) {
			return $cache[ $post_id ] = 0;
		}
	 
		global $wpdb;
		$legacy_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT legacy_id FROM {$wpdb->posts}
			  WHERE ID = %d AND legacy_table = %s LIMIT 1",
			$post_id, F365_SRC_PRESIDENT
		) );
	 
		// President must not read post meta — legacy_id lives on wp_posts only.
	 
		return $cache[ $post_id ] = $legacy_id;
	}
}
 
/** Debug logger — only when WP_DEBUG is on. */
function f365_president_log( string $msg ): void {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[F365 President] ' . $msg );
	}
}
 
 
/* ===========================================================================
 * MAIN — f365_get_president_data()
 * Returns [] ONLY for an invalid post (the single legitimate 404). Otherwise
 * always returns a fully-populated array (every key defined).
 * =========================================================================== */
function f365_get_president_data( int $post_id ): array {
 
	static $cache = [];
	if ( array_key_exists( $post_id, $cache ) ) {
		return $cache[ $post_id ];
	}
 
	global $wpdb;
	$post_type = $wpdb->get_var( $wpdb->prepare(
		"SELECT post_type FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
		$post_id
	) );
	if ( $post_type !== 'f365_president' ) {
		return []; // ONLY 404 condition. Not cached.
	}
 
	$fallback_photo = defined( 'FB_THEME_URL' ) ? FB_THEME_URL . '/assets/images/no-image.jpg' : f365_src_person_photo( 0 );
 
	// Baseline — every key defined; page renders even with no source data.
	$base = [
		'post_id'              => $post_id,
		'shortname'            => get_the_title( $post_id ),
		'longname'             => '',
		'display_name'         => ( get_the_title( $post_id ) !== '' ) ? get_the_title( $post_id ) : ( 'Dirigente #' . $post_id ),
		'birth_date'           => '',
		'death_date'           => '',
		'birth_place'          => '',
		'age'                  => null,
		'is_deceased'          => false,
		'header_country'       => f365_src_country_empty(),
		'nationality'          => f365_src_country_empty(),
		'naturality'           => f365_src_country_empty(),
		'show_nationality_row' => false,
		'photo_url'            => $fallback_photo,
		'social_links'         => [],
		'website_url'          => '',
		'linked_coach_id'      => 0,
		'linked_coach_url'     => '',
		'linked_coach_name'    => '',
		'resume'               => [],
		'teams'                => [],
		'base_url'             => function_exists( 'f365_president_url' ) ? f365_president_url( $post_id ) : (string) get_permalink( $post_id ),
		'info_url'             => function_exists( 'f365_president_url' )
			? f365_president_url( $post_id, 'info' )
			: trailingslashit( (string) get_permalink( $post_id ) ) . 'info/',
	];
 
	$legacy_id = f365_president_legacy_id( $post_id );
	if ( $legacy_id <= 0 ) {
		f365_president_log( "no legacy_id for post {$post_id} — rendering baseline (run legacy backfill)." );
		return $cache[ $post_id ] = $base;
	}
 
	global $wpdb;
	$T_PRES = $wpdb->prefix . 'person_president';
	$T_PERS = $wpdb->prefix . 'person_person';
	$T_CTRY = $wpdb->prefix . 'country_country';
 
	if ( ! f365_src_table_exists( $T_PRES ) || ! f365_src_table_exists( $T_PERS ) ) {
		f365_president_log( "source tables missing ({$T_PRES}/{$T_PERS}) — rendering baseline." );
		return $cache[ $post_id ] = $base;
	}
 
	// Single query: president → person → country(name/slug ×2). Flags via term later.
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT  pr.person_id,
		         p.shortname, p.longname, p.slug,
		         p.birth_date, p.death_date, p.birth_place,
		         p.url, p.facebook, p.twitter, p.youtube, p.instagram, p.googleplus,
		         p.nationality_id, p.naturality_id,
		         nat.shortname AS nat_name, nat.slug AS nat_slug,
		         ntr.shortname AS ntr_name, ntr.slug AS ntr_slug
		   FROM  {$T_PRES} pr
		   JOIN  {$T_PERS} p   ON p.id  = pr.person_id
		   LEFT JOIN {$T_CTRY} nat ON nat.id = p.nationality_id
		   LEFT JOIN {$T_CTRY} ntr ON ntr.id = p.naturality_id
		  WHERE  pr.id = %d
		  LIMIT  1",
		$legacy_id
	), ARRAY_A );
 
	if ( $wpdb->last_error !== '' ) {
		f365_president_log( "SQL error (data) legacy_id={$legacy_id}: {$wpdb->last_error} — rendering baseline." );
		return $cache[ $post_id ] = $base;
	}
	if ( ! is_array( $row ) ) {
		f365_president_log( "no {$T_PRES} row for legacy_id={$legacy_id} — rendering baseline." );
		return $cache[ $post_id ] = $base;
	}
 
	// ── Scalars (all null-coalesced) ──────────────────────────────────────────
	$person_id   = isset( $row['person_id'] ) ? (int) $row['person_id'] : 0;
	$shortname   = isset( $row['shortname'] )   ? (string) $row['shortname']   : '';
	$longname    = isset( $row['longname'] )    ? (string) $row['longname']    : '';
	$birth_date  = isset( $row['birth_date'] )  ? (string) $row['birth_date']  : '';
	$death_date  = isset( $row['death_date'] )  ? (string) $row['death_date']  : '';
	$birth_place = isset( $row['birth_place'] ) ? (string) $row['birth_place'] : '';
 
	if ( $death_date === '0000-00-00' ) { $death_date = ''; }
	if ( $birth_date === '0000-00-00' ) { $birth_date = ''; }
 
	$display_name = ( $longname !== '' && $longname !== $shortname ) ? $longname : $shortname;
	if ( $display_name === '' ) {
		$display_name = $base['display_name'];
	}
 
	// ── Age ─────────────────────────────────────────────────────────────────
	$is_deceased = ( $death_date !== '' );
	$age         = null;
	if ( $birth_date !== '' ) {
		try {
			$born = new DateTime( $birth_date );
			$ref  = $is_deceased ? new DateTime( $death_date ) : new DateTime( 'today' );
			$age  = (int) $born->diff( $ref )->y;
		} catch ( Exception $e ) {
			$age = null;
		}
	}
 
	// ── Countries (name/slug from custom table; FLAG from the term) ───────────
	$nationality = ( isset( $row['nationality_id'] ) && (int) $row['nationality_id'] > 0 )
		? f365_src_country_arr( (int) $row['nationality_id'], (string) ( $row['nat_name'] ?? '' ), (string) ( $row['nat_slug'] ?? '' ) )
		: f365_src_country_empty();
	$naturality  = ( isset( $row['naturality_id'] ) && (int) $row['naturality_id'] > 0 )
		? f365_src_country_arr( (int) $row['naturality_id'], (string) ( $row['ntr_name'] ?? '' ), (string) ( $row['ntr_slug'] ?? '' ) )
		: f365_src_country_empty();
 
	$header_country = ( $naturality['name'] !== '' ) ? $naturality : $nationality;
 
	$show_nationality_row = (
		$nationality['id'] > 0 &&
		$naturality['id']  > 0 &&
		$nationality['id'] !== $naturality['id']
	);
 
	// ── Feature image (check WP featured image, fallback to source-site logic) ─────────────────────────
	$photo_url = get_the_post_thumbnail_url( $post_id, 'full' );
	if ( ! $photo_url ) {
		$photo_url = ( $person_id > 0 ) ? f365_src_person_photo( $person_id, 'president' ) : $fallback_photo;
	}
 
	// ── Socials (reuse existing helper) ───────────────────────────────────────
	$social_links = function_exists( 'f365_get_president_socials' )
		? f365_get_president_socials( [
			'facebook'  => isset( $row['facebook'] )  ? (string) $row['facebook']  : '',
			'twitter'   => isset( $row['twitter'] )   ? (string) $row['twitter']   : '',
			'instagram' => isset( $row['instagram'] ) ? (string) $row['instagram'] : '',
			'youtube'   => isset( $row['youtube'] )   ? (string) $row['youtube']   : '',
		] )
		: [];
 
	$website_url = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';
 
	// ── Cross-role linked coach ───────────────────────────────────────────────
	$linked = f365_president_linked_coach( $legacy_id );
	$linked_coach_id   = (int) ( $linked['id']   ?? 0 );
	$linked_coach_url  = (string) ( $linked['url']  ?? '' );
	$linked_coach_name = (string) ( $linked['name'] ?? '' );
 
	$data = array_merge( $base, [
		'shortname'            => $shortname !== '' ? $shortname : $base['shortname'],
		'longname'             => $longname,
		'display_name'         => $display_name,
		'birth_date'           => $birth_date,
		'death_date'           => $death_date,
		'birth_place'          => $birth_place,
		'age'                  => $age,
		'is_deceased'          => $is_deceased,
		'header_country'       => $header_country,
		'nationality'          => $nationality,
		'naturality'           => $naturality,
		'show_nationality_row' => $show_nationality_row,
		'photo_url'            => $photo_url,
		'social_links'         => is_array( $social_links ) ? $social_links : [],
		'website_url'          => $website_url,
		'linked_coach_id'      => $linked_coach_id,
		'linked_coach_url'     => $linked_coach_url,
		'linked_coach_name'    => $linked_coach_name,
	] );

	$data['resume'] = f365_get_president_resume( $post_id );
	$data['teams']  = f365_get_president_teams( $post_id );

	return $cache[ $post_id ] = $data;
}

/**
 * Formats social media handles into full URL and SVG icon structures.
 */
function f365_get_president_socials( array $raw ): array {
	$socials = [];
	$templates = [
		'facebook'  => [
			'base_url' => 'https://www.facebook.com/',
			'label'    => 'Facebook',
			'icon'     => 'social-facebook',
		],
		'twitter'   => [
			'base_url' => 'https://x.com/',
			'label'    => 'Twitter',
			'icon'     => 'social-x',
		],
		'instagram' => [
			'base_url' => 'https://www.instagram.com/',
			'label'    => 'Instagram',
			'icon'     => 'social-instagram',
		],
		'youtube'   => [
			'base_url' => 'https://www.youtube.com/',
			'label'    => 'YouTube',
			'icon'     => 'social-youtube',
		],
	];

	foreach ( $templates as $network => $cfg ) {
		$handle = isset( $raw[ $network ] ) ? trim( (string) $raw[ $network ] ) : '';
		if ( $handle === '' ) {
			continue;
		}
		$url = ( str_starts_with( $handle, 'http://' ) || str_starts_with( $handle, 'https://' ) )
			? $handle
			: $cfg['base_url'] . ltrim( $handle, '/' );

		$socials[] = [
			'url'   => $url,
			'label' => $cfg['label'],
			'icon'  => $cfg['icon'],
		];
	}

	return $socials;
}

/* ===========================================================================
 * f365_get_president_teams() — single query over wp_team_teampresident.
 * =========================================================================== */
function f365_get_president_teams( int $president_post_id ): array {
 
	static $cache = [];
	if ( array_key_exists( $president_post_id, $cache ) ) {
		return $cache[ $president_post_id ];
	}
	if ( $president_post_id <= 0 ) {
		return $cache[ $president_post_id ] = [];
	}
 
	$legacy_id = f365_president_legacy_id( $president_post_id );
	if ( $legacy_id <= 0 ) {
		return $cache[ $president_post_id ] = [];
	}
 
	global $wpdb;
	$T_TP   = $wpdb->prefix . 'team_teampresident';
	$T_TEAM = $wpdb->prefix . 'team_team';
	$T_CTRY = $wpdb->prefix . 'country_country';
 
	if ( ! f365_src_table_exists( $T_TP ) || ! f365_src_table_exists( $T_TEAM ) ) {
		f365_president_log( "teams: source tables missing ({$T_TP}/{$T_TEAM}) — empty." );
		return $cache[ $president_post_id ] = [];
	}

	// Single query: assignment rows + team + country + WP team post id (for URL).
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT  tp.team_id        AS legacy_team_id,
		         COALESCE( tp.edition, '' )        AS edition,
		         COALESCE( tp.president_type, '' ) AS president_type,
		         CAST( COALESCE( tp.`order`, 0 ) AS UNSIGNED ) AS sort_order,
		         t.shortname       AS team_name,
		         t.logo            AS team_logo,
		         t.country_id      AS country_id,
		         c.shortname       AS country_name,
		         c.slug            AS country_slug
		   FROM  {$T_TP} tp
		   LEFT JOIN {$T_TEAM} t ON t.id = tp.team_id
		   LEFT JOIN {$T_CTRY} c ON c.id = t.country_id
		  WHERE  tp.president_id = %d AND tp.team_id IS NOT NULL
		  ORDER  BY sort_order ASC, tp.edition DESC",
		$legacy_id
	), ARRAY_A );

	if ( $wpdb->last_error !== '' || ! is_array( $rows ) || empty( $rows ) ) {
		if ( $wpdb->last_error !== '' ) {
			f365_president_log( "teams: SQL error legacy_id={$legacy_id}: {$wpdb->last_error}" );
		}
		return $cache[ $president_post_id ] = [];
	}
 
	$teams = [];
	$seen  = [];
	foreach ( $rows as $row ) {
		$ltid = isset( $row['legacy_team_id'] ) ? (int) $row['legacy_team_id'] : 0;
		if ( $ltid <= 0 ) {
			continue;
		}
		$edition = isset( $row['edition'] ) ? (string) $row['edition'] : '';
		$key     = $ltid . '_' . $edition;
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;
 
		$team_name  = isset( $row['team_name'] ) && $row['team_name'] !== null ? (string) $row['team_name'] : '';
		$country_id = isset( $row['country_id'] ) ? (int) $row['country_id'] : 0;
 
		// Source URL built from the legacy team id (no WP post lookup): /equipa/{id}/
		$permalink = user_trailingslashit( home_url( 'equipa/' . $ltid ) );
 
		$teams[] = [
			'id'             => $ltid,                          // legacy team id (URL built from it)
			'legacy_team_id' => $ltid,
			'title'          => $team_name,
			'permalink'      => (string) $permalink,
			'logo'           => f365_src_team_logo( isset( $row['team_logo'] ) ? (string) $row['team_logo'] : '' ),
			'country_name'   => isset( $row['country_name'] ) && $row['country_name'] !== null ? (string) $row['country_name'] : '',
			'country_flag'   => f365_country_flag_from_legacy( $country_id ),  // taxonomy/term flag
			'flag_class'     => $country_id > 0 ? 'flag-icon flag-icon-' . $country_id : '',
			'edition'        => $edition,
			'president_type' => isset( $row['president_type'] ) ? (string) $row['president_type'] : '',
			'order'          => isset( $row['sort_order'] ) ? (int) $row['sort_order'] : 0,
		];
	}
 
	return $cache[ $president_post_id ] = $teams;
}
 
 
/* ===========================================================================
 * f365_get_president_resume()  — interval grouping over editions.
 * =========================================================================== */
function f365_get_president_resume( int $president_post_id ): array {
 
	static $cache = [];
	if ( array_key_exists( $president_post_id, $cache ) ) {
		return $cache[ $president_post_id ];
	}
	if ( $president_post_id <= 0 ) {
		return $cache[ $president_post_id ] = [];
	}
 
	$raw_rows = f365_president_get_assignment_rows( $president_post_id );
	if ( empty( $raw_rows ) ) {
		return $cache[ $president_post_id ] = [];
	}
 
	$intervals = function_exists( 'f365_president_build_intervals' )
		? f365_president_build_intervals( $raw_rows )
		: [];
	if ( empty( $intervals ) ) {
		return $cache[ $president_post_id ] = [];
	}
 
	$legacy_team_ids = array_values( array_unique( array_filter(
		array_map( static function ( $r ) {
			return isset( $r['team_wp_id'] ) ? (int) $r['team_wp_id'] : 0;
		}, $raw_rows ),
		static function ( $id ) { return $id > 0; }
	) ) );
	if ( empty( $legacy_team_ids ) ) {
		return $cache[ $president_post_id ] = [];
	}
 
	$team_data = f365_president_batch_load_team_data( $legacy_team_ids ); // keyed by legacy team id
 
	$resume = [];
	foreach ( $intervals as $interval ) {
		$ltid = isset( $interval['team_wp_id'] ) ? (int) $interval['team_wp_id'] : 0;
		if ( $ltid <= 0 || ! isset( $team_data[ $ltid ] ) ) {
			continue;
		}
		$td  = $team_data[ $ltid ];
		$fed = isset( $td['federation'] ) ? $td['federation'] : null;
 
		$from = isset( $interval['from_edition'] ) ? (string) $interval['from_edition'] : '';
		$to   = isset( $interval['to_edition'] )   ? (string) $interval['to_edition']   : '';
 
		$resume[] = [
			'from'         => function_exists( 'f365_president_edition_to_season' ) ? f365_president_edition_to_season( $from, $fed ) : $from,
			'to'           => function_exists( 'f365_president_edition_to_season' ) ? f365_president_edition_to_season( $to, $fed )   : $to,
			'team_id'      => isset( $td['wp_id'] ) ? (int) $td['wp_id'] : 0,
			'team_name'    => isset( $td['name'] ) ? (string) $td['name'] : '',
			'team_url'     => isset( $td['url'] ) ? (string) $td['url'] : '',
			'country_name' => isset( $td['country_name'] ) ? (string) $td['country_name'] : '',
			'country_flag' => isset( $td['country_flag'] ) ? (string) $td['country_flag'] : '',
			'flag_class'   => isset( $td['flag_class'] ) ? (string) $td['flag_class'] : '',
		];
	}
 
	return $cache[ $president_post_id ] = $resume;
}
 
 
/**
 * Groups consecutive editions per team for the president resume.
 *
 * @param  array $rows  Raw assignment rows (sorted by edition ASC).
 * @return array
 */
function f365_president_build_intervals( array $rows ): array {
	$intervals = [];
	if ( empty( $rows ) ) {
		return $intervals;
	}
 
	$current = null;
 
	foreach ( $rows as $row ) {
		$team_id = isset( $row['team_wp_id'] ) ? (int) $row['team_wp_id'] : 0;
		$edition = isset( $row['edition'] ) ? (string) $row['edition'] : '';
 
		if ( $team_id <= 0 ) {
			continue;
		}
 
		if ( $current === null ) {
			$current = [
				'team_wp_id'   => $team_id,
				'from_edition' => $edition,
				'to_edition'   => $edition,
			];
		} elseif ( $current['team_wp_id'] === $team_id ) {
			$current['to_edition'] = $edition;
		} else {
			$intervals[] = $current;
			$current = [
				'team_wp_id'   => $team_id,
				'from_edition' => $edition,
				'to_edition'   => $edition,
			];
		}
	}
 
	if ( $current !== null ) {
		$intervals[] = $current;
	}
 
	return $intervals;
}
 
 
/**
 * Converts a single-year edition string to a standard season label (e.g., 2023 -> 2023/2024).
 *
 * @param  string      $edition  Year string.
 * @param  array|null  $fed      Federation config details.
 * @return string
 */
function f365_president_edition_to_season( string $edition, ?array $fed ): string {
	$edition = trim( $edition );
	if ( $edition === '' ) {
		return '';
	}
 
	$year = (int) $edition;
	if ( $year <= 0 ) {
		return $edition;
	}
 
	$season_type = isset( $fed['season_type'] ) ? (string) $fed['season_type'] : 'winter';
	if ( $season_type === 'winter' ) {
		$next_year = $year + 1;
		return $year . '/' . $next_year;
	}
 
	return (string) $year;
}
 
 
/* ===========================================================================
 * f365_president_get_assignment_rows()  — single query, sorted ASC.
 * Returns [ ['team_wp_id'=>legacy_team_id, 'edition'=>string], ... ].
 * ('team_wp_id' carries the LEGACY team id so build_intervals() is unchanged.)
 * =========================================================================== */
function f365_president_get_assignment_rows( int $president_post_id ): array {
 
	$legacy_id = f365_president_legacy_id( $president_post_id );
	if ( $legacy_id <= 0 ) {
		return [];
	}
	global $wpdb;
	$T_TP = $wpdb->prefix . 'team_teampresident';
	if ( ! f365_src_table_exists( $T_TP ) ) {
		return [];
	}
 
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT  tp.team_id AS team_wp_id,
		         COALESCE( tp.edition, '' ) AS edition
		   FROM  {$T_TP} tp
		  WHERE  tp.president_id = %d AND tp.team_id IS NOT NULL
		  ORDER  BY tp.edition ASC",
		$legacy_id
	), ARRAY_A );
 
	return is_array( $rows ) ? $rows : [];
}
 
 
/* ===========================================================================
 * f365_president_batch_load_team_data()  — single query, keyed by LEGACY team
 * id. name/url + country (flag via term) + federation season config + wp_id.
 * =========================================================================== */
function f365_president_batch_load_team_data( array $legacy_team_ids ): array {
 
	$result = [];
	$legacy_team_ids = array_values( array_unique( array_filter(
		array_map( 'intval', $legacy_team_ids ),
		static function ( $i ) { return $i > 0; }
	) ) );
	if ( empty( $legacy_team_ids ) ) {
		return $result;
	}
 
	global $wpdb;
	$T_TEAM = $wpdb->prefix . 'team_team';
	$T_CTRY = $wpdb->prefix . 'country_country';
	$T_FED  = $wpdb->prefix . 'federation_federation';
 
	if ( ! f365_src_table_exists( $T_TEAM ) ) {
		return $result;
	}
 
	$ph = implode( ',', array_fill( 0, count( $legacy_team_ids ), '%d' ) );

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT  t.id            AS legacy_team_id,
		         t.shortname     AS team_name,
		         t.logo          AS team_logo,
		         t.country_id    AS country_id,
		         c.shortname     AS country_name,
		         c.slug          AS country_slug,
		         f.season_type   AS season_type,
		         f.season_begin_with_edition AS season_begins
		   FROM  {$T_TEAM} t
		   LEFT JOIN {$T_CTRY} c ON c.id = t.country_id
		   LEFT JOIN {$T_FED}  f ON f.id = t.federation_id
		  WHERE  t.id IN ($ph)",
		$legacy_team_ids
	), ARRAY_A );

	if ( $wpdb->last_error !== '' || ! is_array( $rows ) ) {
		f365_president_log( "team batch: SQL error: {$wpdb->last_error}" );
		return $result;
	}
 
	foreach ( $rows as $r ) {
		$ltid = isset( $r['legacy_team_id'] ) ? (int) $r['legacy_team_id'] : 0;
		if ( $ltid <= 0 ) {
			continue;
		}
		$country_id = isset( $r['country_id'] ) ? (int) $r['country_id'] : 0;
 
		// Source URL built from the legacy team id (no WP post lookup): /equipa/{id}/jogos/
		$url = user_trailingslashit( home_url( 'equipa/' . $ltid . '/jogos' ) );
 
		$federation = null;
		if ( isset( $r['season_type'] ) && $r['season_type'] !== null && $r['season_type'] !== '' ) {
			$federation = [
				'season_type'               => (string) $r['season_type'],
				'season_begin_with_edition' => isset( $r['season_begins'] ) ? (bool) ( (int) $r['season_begins'] ) : true,
			];
		}
 
		$result[ $ltid ] = [
			'wp_id'         => $ltid,
			'name'          => isset( $r['team_name'] ) && $r['team_name'] !== null ? (string) $r['team_name'] : '',
			'url'           => (string) $url,
			'logo'          => f365_src_team_logo( isset( $r['team_logo'] ) ? (string) $r['team_logo'] : '' ),
			'country_name'  => isset( $r['country_name'] ) && $r['country_name'] !== null ? (string) $r['country_name'] : '',
			'country_flag'  => f365_country_flag_from_legacy( $country_id ),  // taxonomy/term flag
			'flag_class'    => $country_id > 0 ? 'flag-icon flag-icon-' . $country_id : '',
			'federation'    => $federation,
		];
	}
 
	return $result;
}
 
 
/* ===========================================================================
 * f365_president_batch_load_federation_data()  — back-compat, single query.
 * =========================================================================== */
function f365_president_batch_load_federation_data( array $legacy_fed_ids ): array {
 
	$result = [];
	$legacy_fed_ids = array_values( array_unique( array_filter(
		array_map( 'intval', $legacy_fed_ids ),
		static function ( $i ) { return $i > 0; }
	) ) );
	if ( empty( $legacy_fed_ids ) ) {
		return $result;
	}
 
	global $wpdb;
	$T_FED = $wpdb->prefix . 'federation_federation';
	if ( ! f365_src_table_exists( $T_FED ) ) {
		return $result;
	}
	$ph = implode( ',', array_fill( 0, count( $legacy_fed_ids ), '%d' ) );
 
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, season_type, season_begin_with_edition
		   FROM {$T_FED} WHERE id IN ($ph)",
		$legacy_fed_ids
	), ARRAY_A );
 
	if ( ! is_array( $rows ) ) {
		return $result;
	}
 
	foreach ( $rows as $r ) {
		$fid = isset( $r['id'] ) ? (int) $r['id'] : 0;
		if ( $fid <= 0 ) {
			continue;
		}
		$result[ $fid ] = [
			'season_type'               => isset( $r['season_type'] ) ? (string) $r['season_type'] : '',
			'season_begin_with_edition' => isset( $r['season_begin_with_edition'] ) ? (bool) ( (int) $r['season_begin_with_edition'] ) : true,
		];
	}
	return $result;
}
 
 
/* ===========================================================================
 * Cross-role linked coach — person→coach via source tables, WP id for the URL.
 * Returns [ 'id'=>int, 'url'=>string, 'name'=>string ] (always all keys).
 * =========================================================================== */
function f365_president_linked_coach( int $legacy_president_id ): array {
 
	$none = [ 'id' => 0, 'url' => '', 'name' => '' ];
	if ( $legacy_president_id <= 0 ) {
		return $none;
	}
 
	global $wpdb;
	$T_PRES  = $wpdb->prefix . 'person_president';
	$T_COACH = $wpdb->prefix . 'person_coach';
	$T_PERS  = $wpdb->prefix . 'person_person';
 
	if ( ! f365_src_table_exists( $T_PRES ) || ! f365_src_table_exists( $T_COACH ) ) {
		return $none;
	}
 
	// Single query: resolve coach legacy id + name in one go (person shared).
	$coach = $wpdb->get_row( $wpdb->prepare(
		"SELECT co.id AS coach_id, pe.shortname AS coach_name
		   FROM {$T_PRES} pr
		   JOIN {$T_COACH} co ON co.person_id = pr.person_id
		   LEFT JOIN {$T_PERS} pe ON pe.id = co.person_id
		  WHERE pr.id = %d
		  LIMIT 1",
		$legacy_president_id
	), ARRAY_A );
 
	$legacy_coach_id = ( is_array( $coach ) && isset( $coach['coach_id'] ) ) ? (int) $coach['coach_id'] : 0;
	if ( $legacy_coach_id <= 0 ) {
		return $none;
	}
 
	// Map legacy coach id → WP coach post (routing layer): legacy cols then meta.
	$coach_post_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts}
		  WHERE post_type = 'f365_coach' AND legacy_table = %s AND legacy_id = %d
		    AND post_status = 'publish' LIMIT 1",
		F365_SRC_COACH, $legacy_coach_id
	) );
	// President must not read post meta — coach maps via legacy_table/legacy_id only.
	if ( $coach_post_id <= 0 ) {
		return $none;
	}
 
	$url  = function_exists( 'f365_coach_url' ) ? f365_coach_url( $coach_post_id ) : (string) get_permalink( $coach_post_id );
	$name = ( is_array( $coach ) && isset( $coach['coach_name'] ) ) ? (string) $coach['coach_name'] : '';
	if ( $name === '' ) {
		$name = get_the_title( $coach_post_id );
	}
 
	return [ 'id' => $coach_post_id, 'url' => (string) $url, 'name' => (string) $name ];
}

/** ============================================================================= */

 

/** Coach-type display labels (source choices.COACH_TYPE). */
function f365_coach_type_label( string $type ): string {
	$labels = [
		'principal'  => __( 'Treinador', 'futebol365' ),
		'assistant'  => __( 'Adjunto', 'futebol365' ),
		'goalkeeper' => __( 'Treinador de Guarda-Redes', 'futebol365' ),
		'observer'   => __( 'Observador', 'futebol365' ),
	];
	$type = (string) $type;
	return $labels[ $type ] ?? ( $type !== '' ? ucfirst( $type ) : __( 'Treinador', 'futebol365' ) );
}
 
 
/* ===========================================================================
 * SHARED LEGACY-ID RESOLVERS (coach / referee) — mirror f365_president_legacy_id.
 * =========================================================================== */
if ( ! function_exists( 'f365_coach_legacy_id' ) ) {
	function f365_coach_legacy_id( int $post_id ): int {
		static $cache = [];
		if ( array_key_exists( $post_id, $cache ) ) {
			return $cache[ $post_id ];
		}
		if ( $post_id <= 0 ) {
			return $cache[ $post_id ] = 0;
		}
		global $wpdb;
		$legacy_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT legacy_id FROM {$wpdb->posts} WHERE ID = %d AND legacy_table = %s LIMIT 1",
			$post_id, F365_SRC_COACH
		) );
		// Coach must not read post meta — legacy_id lives on wp_posts only.
		return $cache[ $post_id ] = $legacy_id;
	}
}
 
if ( ! function_exists( 'f365_referee_legacy_id' ) ) {
	function f365_referee_legacy_id( int $post_id ): int {
		static $cache = [];
		if ( array_key_exists( $post_id, $cache ) ) {
			return $cache[ $post_id ];
		}
		if ( $post_id <= 0 ) {
			return $cache[ $post_id ] = 0;
		}
		global $wpdb;
		$legacy_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT legacy_id FROM {$wpdb->posts} WHERE ID = %d AND legacy_table = %s LIMIT 1",
			$post_id, F365_SRC_REFEREE
		) );
		// Referee must not read post meta — legacy_id lives on wp_posts only.
		return $cache[ $post_id ] = $legacy_id;
	}
}
 
/** Map a legacy team id -> WP f365_team post id (legacy cols then django meta). Cached. */
function f365_team_wp_id_from_legacy( int $legacy_team_id ): int {
	static $cache = [];
	if ( array_key_exists( $legacy_team_id, $cache ) ) {
		return $cache[ $legacy_team_id ];
	}
	if ( $legacy_team_id <= 0 ) {
		return $cache[ $legacy_team_id ] = 0;
	}
	global $wpdb;
	$wp_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts}
		  WHERE post_type = 'f365_team' AND legacy_table = %s AND legacy_id = %d
		    AND post_status = 'publish' LIMIT 1",
		F365_SRC_TEAM, $legacy_team_id
	) );
	if ( $wp_id <= 0 ) {
		$meta_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			   JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'f365_team'
			  WHERE pm.meta_key = 'django_id' AND pm.meta_value = %d LIMIT 1",
			$legacy_team_id
		) );
		$wp_id = is_numeric( $meta_id ) ? (int) $meta_id : 0;
	}
	return $cache[ $legacy_team_id ] = $wp_id;
}
 
/** Map a legacy competition id -> WP f365_competition post id. Cached. */
function f365_competition_wp_id_from_legacy( int $legacy_comp_id ): int {
	static $cache = [];
	if ( array_key_exists( $legacy_comp_id, $cache ) ) {
		return $cache[ $legacy_comp_id ];
	}
	if ( $legacy_comp_id <= 0 ) {
		return $cache[ $legacy_comp_id ] = 0;
	}
	global $wpdb;
	$wp_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts}
		  WHERE post_type = 'f365_competition' AND legacy_table = 'wp_competition_competition' AND legacy_id = %d
		    AND post_status = 'publish' LIMIT 1",
		$legacy_comp_id
	) );
	if ( $wp_id <= 0 ) {
		$meta_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			   JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'f365_competition'
			  WHERE pm.meta_key = 'django_competition_id' AND pm.meta_value = %d LIMIT 1",
			$legacy_comp_id
		) );
		$wp_id = is_numeric( $meta_id ) ? (int) $meta_id : 0;
	}
	return $cache[ $legacy_comp_id ] = $wp_id;
}
 
/** Map a legacy federation id -> WP f365_federation post id. Cached. */
function f365_federation_wp_id_from_legacy( int $legacy_fed_id ): int {
	static $cache = [];
	if ( array_key_exists( $legacy_fed_id, $cache ) ) {
		return $cache[ $legacy_fed_id ];
	}
	if ( $legacy_fed_id <= 0 ) {
		return $cache[ $legacy_fed_id ] = 0;
	}
	global $wpdb;
	$wp_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts}
		  WHERE post_type = 'f365_federation' AND legacy_table = 'federation_federation' AND legacy_id = %d
		    AND post_status = 'publish' LIMIT 1",
		$legacy_fed_id
	) );
	if ( $wp_id <= 0 ) {
		$meta_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			   JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'f365_federation'
			  WHERE pm.meta_key = 'django_federation_id' AND pm.meta_value = %d LIMIT 1",
			$legacy_fed_id
		) );
		$wp_id = is_numeric( $meta_id ) ? (int) $meta_id : 0;
	}
	return $cache[ $legacy_fed_id ] = $wp_id;
}
 
 
/* ###########################################################################
 * ##  COACH MODULE
 * ######################################################################### */
 
/**
 * f365_get_coach_data() — profile from wp_person_coach → wp_person_person → country.
 * Same return shape as before. Country/flag = term logic; photo = source path (role 'coach').
 */
function f365_get_coach_data( int $post_id ): array {
 
	$cache_key = 'f365_coach_data_' . $post_id;
	$cached    = wp_cache_get( $cache_key, 'f365' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
 
	global $wpdb;
	$post_type = $wpdb->get_var( $wpdb->prepare(
		"SELECT post_type FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $post_id
	) );
	if ( $post_type !== 'f365_coach' ) {
		return []; // do NOT cache failures
	}
 
	$fallback_photo = defined( 'FB_THEME_URL' ) ? FB_THEME_URL . '/assets/images/no-image.jpg' : f365_src_person_photo( 0 );
	$title          = get_the_title( $post_id );
 
	$base = [
		'post_id'              => $post_id,
		'shortname'            => $title,
		'longname'             => '',
		'display_name'         => $title !== '' ? $title : ( 'Treinador #' . $post_id ),
		'birth_date'           => '',
		'death_date'           => '',
		'birth_place'          => '',
		'age'                  => null,
		'is_deceased'          => false,
		'header_country'       => f365_src_country_empty(),
		'header_location'      => '',
		'nationality'          => f365_src_country_empty(),
		'naturality'           => f365_src_country_empty(),
		'show_nationality_row' => false,
		'photo_url'            => $fallback_photo,
		'social_links'         => [],
		'website_url'          => '',
		'linked_player_id'     => 0,
		'linked_player_url'    => '',
		'linked_player_name'   => '',
		'current_team'         => null,
		'base_url'             => function_exists( 'f365_coach_url' ) ? f365_coach_url( $post_id ) : (string) get_permalink( $post_id ),
		'info_url'             => function_exists( 'f365_coach_url' ) ? f365_coach_url( $post_id, 'info' ) : '',
		'news_url'             => function_exists( 'f365_coach_url' ) ? f365_coach_url( $post_id, 'noticias' ) : '',
	];
 
	$legacy_id = f365_coach_legacy_id( $post_id );
	if ( $legacy_id <= 0 ) {
		f365_president_log( "coach: no legacy_id for post {$post_id} — baseline." );
		return $base; // not cached (no source row resolved)
	}
 
	$T_COACH = $wpdb->prefix . 'person_coach';
	$T_PERS  = $wpdb->prefix . 'person_person';
	$T_CTRY  = $wpdb->prefix . 'country_country';
	if ( ! f365_src_table_exists( $T_COACH ) || ! f365_src_table_exists( $T_PERS ) ) {
		f365_president_log( "coach: source tables missing — baseline." );
		return $base;
	}
 
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT  co.person_id,
		         p.shortname, p.longname, p.slug,
		         p.birth_date, p.death_date, p.birth_place,
		         p.url, p.facebook, p.twitter, p.youtube, p.instagram, p.googleplus,
		         p.nationality_id, p.naturality_id,
		         nat.shortname AS nat_name, nat.slug AS nat_slug,
		         ntr.shortname AS ntr_name, ntr.slug AS ntr_slug
		   FROM  {$T_COACH} co
		   JOIN  {$T_PERS} p   ON p.id = co.person_id
		   LEFT JOIN {$T_CTRY} nat ON nat.id = p.nationality_id
		   LEFT JOIN {$T_CTRY} ntr ON ntr.id = p.naturality_id
		  WHERE  co.id = %d LIMIT 1",
		$legacy_id
	), ARRAY_A );
 
	if ( $wpdb->last_error !== '' || ! is_array( $row ) ) {
		f365_president_log( "coach: no row / SQL error legacy_id={$legacy_id}: {$wpdb->last_error}" );
		return $base;
	}
 
	$person_id   = isset( $row['person_id'] ) ? (int) $row['person_id'] : 0;
	$shortname   = isset( $row['shortname'] ) ? (string) $row['shortname'] : '';
	$longname    = isset( $row['longname'] ) ? (string) $row['longname'] : '';
	$birth_date  = isset( $row['birth_date'] ) ? (string) $row['birth_date'] : '';
	$death_date  = isset( $row['death_date'] ) ? (string) $row['death_date'] : '';
	$birth_place = isset( $row['birth_place'] ) ? (string) $row['birth_place'] : '';
	if ( $death_date === '0000-00-00' ) { $death_date = ''; }
	if ( $birth_date === '0000-00-00' ) { $birth_date = ''; }
 
	$display_name = ( $longname !== '' && $longname !== $shortname ) ? $longname : $shortname;
	if ( $display_name === '' ) { $display_name = $base['display_name']; }
 
	$is_deceased = ( $death_date !== '' );
	$age         = null;
	if ( $birth_date !== '' ) {
		try {
			$born = new DateTime( $birth_date );
			$ref  = $is_deceased ? new DateTime( $death_date ) : new DateTime( 'today' );
			$age  = (int) $born->diff( $ref )->y;
		} catch ( Exception $e ) {
			$age = null;
		}
	}
 
	$nationality = ( isset( $row['nationality_id'] ) && (int) $row['nationality_id'] > 0 )
		? f365_src_country_arr( (int) $row['nationality_id'], (string) ( $row['nat_name'] ?? '' ), (string) ( $row['nat_slug'] ?? '' ) )
		: f365_src_country_empty();
	$naturality  = ( isset( $row['naturality_id'] ) && (int) $row['naturality_id'] > 0 )
		? f365_src_country_arr( (int) $row['naturality_id'], (string) ( $row['ntr_name'] ?? '' ), (string) ( $row['ntr_slug'] ?? '' ) )
		: f365_src_country_empty();
 
	$header_country = ( $naturality['name'] !== '' ) ? $naturality : $nationality;
	$show_nationality_row = ( $nationality['id'] > 0 && $naturality['id'] > 0 && $nationality['id'] !== $naturality['id'] );
 
	$header_location = $header_country['name'];
	if ( $birth_place !== '' ) {
		$header_location = ( $header_location !== '' ) ? $header_location . ' - ' . $birth_place : $birth_place;
	}
 
	// Featured image first; only fall back to the source photo when none is set.
	$photo_url = get_the_post_thumbnail_url( $post_id, 'full' );
	if ( ! $photo_url ) {
		$photo_url = ( $person_id > 0 ) ? f365_src_person_photo( $person_id, 'coach' ) : $fallback_photo;
	}
	$social_links = function_exists( 'f365_get_president_socials' )
		? f365_get_president_socials( [
			'facebook'  => isset( $row['facebook'] )  ? (string) $row['facebook']  : '',
			'twitter'   => isset( $row['twitter'] )   ? (string) $row['twitter']   : '',
			'instagram' => isset( $row['instagram'] ) ? (string) $row['instagram'] : '',
			'youtube'   => isset( $row['youtube'] )   ? (string) $row['youtube']   : '',
		] ) : [];
 
	$linked = f365_coach_linked_player( $legacy_id );
 
	$data = array_merge( $base, [
		'shortname'            => $shortname !== '' ? $shortname : $base['shortname'],
		'longname'             => $longname,
		'display_name'         => $display_name,
		'birth_date'           => $birth_date,
		'death_date'           => $death_date,
		'birth_place'          => $birth_place,
		'age'                  => $age,
		'is_deceased'          => $is_deceased,
		'header_country'       => $header_country,
		'header_location'      => $header_location,
		'nationality'          => $nationality,
		'naturality'           => $naturality,
		'show_nationality_row' => $show_nationality_row,
		'photo_url'            => $photo_url,
		'social_links'         => is_array( $social_links ) ? $social_links : [],
		'website_url'          => isset( $row['url'] ) ? trim( (string) $row['url'] ) : '',
		'linked_player_id'     => (int) ( $linked['id'] ?? 0 ),
		'linked_player_url'    => (string) ( $linked['url'] ?? '' ),
		'linked_player_name'   => (string) ( $linked['name'] ?? '' ),
		'current_team'         => f365_get_coach_current_team( $post_id ),
	] );
 
	wp_cache_set( $cache_key, $data, 'f365' );
	return $data;
}
 
 
/**
 * f365_get_coach_resume() — club + national career from wp_team_teamstepcoach.
 * Returns [ 'club' => [...], 'national' => [...] ] with the existing row shape.
 * One query for all assignments (joining teamstep→team→country→federation),
 * national rows then get their competitions in one extra query.
 */
function f365_get_coach_resume( int $coach_post_id ): array {
 
	$cache_key = 'f365_coach_resume_' . $coach_post_id;
	$cached    = wp_cache_get( $cache_key, 'f365' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$empty = [ 'club' => [], 'national' => [] ];
	if ( $coach_post_id <= 0 ) {
		return $empty;
	}
 
	$legacy_id = f365_coach_legacy_id( $coach_post_id );
	if ( $legacy_id <= 0 ) {
		return $empty;
	}
 
	global $wpdb;
	$T_TSC  = $wpdb->prefix . 'team_teamstepcoach';
	$T_TS   = $wpdb->prefix . 'team_teamstep';
	$T_TEAM = $wpdb->prefix . 'team_team';
	$T_CTRY = $wpdb->prefix . 'country_country';
	$T_FED  = $wpdb->prefix . 'federation_federation';
	if ( ! f365_src_table_exists( $T_TSC ) || ! f365_src_table_exists( $T_TS ) || ! f365_src_table_exists( $T_TEAM ) ) {
		f365_president_log( "coach resume: source tables missing — empty." );
		wp_cache_set( $cache_key, $empty, 'f365' );
		return $empty;
	}
 
	// Single query: each coach assignment row joined to teamstep → team → country
	// → federation. team_type splits club vs national. Sorted edition desc, order.
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT  tsc.id           AS tsc_id,
		         tsc.coach_type    AS coach_type,
		         COALESCE( tsc.edition, '' ) AS edition,
		         CAST( COALESCE( tsc.`order`, 0 ) AS UNSIGNED ) AS sort_order,
		         ts.id             AS teamstep_id,
		         ts.step           AS step,
		         ts.modality       AS modality,
		         ts.gender         AS gender,
		         t.id              AS team_id,
		         t.shortname       AS team_name,
		         t.logo            AS team_logo,
		         t.team_type       AS team_type,
		         t.country_id      AS country_id,
		         t.federation_id   AS federation_id,
		         c.shortname       AS country_name,
		         f.season_type     AS season_type,
		         f.season_begin_with_edition AS season_begins
		   FROM  {$T_TSC} tsc
		   JOIN  {$T_TS}   ts ON ts.id = tsc.team_id
		   JOIN  {$T_TEAM} t  ON t.id  = ts.team_id
		   LEFT JOIN {$T_CTRY} c ON c.id = t.country_id
		   LEFT JOIN {$T_FED}  f ON f.id = t.federation_id
		  WHERE  tsc.coach_id = %d
		  ORDER  BY tsc.edition DESC, sort_order ASC",
		$legacy_id
	), ARRAY_A );
 
	if ( $wpdb->last_error !== '' || ! is_array( $rows ) || empty( $rows ) ) {
		if ( $wpdb->last_error !== '' ) {
			f365_president_log( "coach resume: SQL error legacy_id={$legacy_id}: {$wpdb->last_error}" );
		}
		wp_cache_set( $cache_key, $empty, 'f365' );
		return $empty;
	}
 
	// Competitions for the national rows: one query for all tsc ids in this set.
	$tsc_ids = array_values( array_unique( array_map( static function ( $r ) {
		return isset( $r['tsc_id'] ) ? (int) $r['tsc_id'] : 0;
	}, $rows ) ) );
	$comps_by_tsc = f365_coach_competitions_by_tsc( $tsc_ids );
 
	$club     = [];
	$national = [];
	$seen     = [];
 
	foreach ( $rows as $r ) {
		$tsc_id     = isset( $r['tsc_id'] ) ? (int) $r['tsc_id'] : 0;
		$teamstep   = isset( $r['teamstep_id'] ) ? (int) $r['teamstep_id'] : 0;
		$team_id    = isset( $r['team_id'] ) ? (int) $r['team_id'] : 0;
		$edition    = isset( $r['edition'] ) ? (string) $r['edition'] : '';
		$team_type  = isset( $r['team_type'] ) ? (string) $r['team_type'] : 'club';
		$country_id = isset( $r['country_id'] ) ? (int) $r['country_id'] : 0;
 
		// De-dup identical teamstep+edition (source uses unique teamsteps).
		$key = $teamstep . '_' . $edition;
		if ( $teamstep <= 0 || isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;
 
		// Source team URL from the legacy id (no WP post lookup): /equipa/{id}/
		$team_url = ( $team_id > 0 ) ? user_trailingslashit( home_url( 'equipa/' . $team_id ) ) : '';
 
		$fed = ( isset( $r['season_type'] ) && $r['season_type'] !== null && $r['season_type'] !== '' )
			? [
				'season_type'               => (string) $r['season_type'],
				'season_begin_with_edition' => isset( $r['season_begins'] ) ? (bool) ( (int) $r['season_begins'] ) : true,
			]
			: null;
 
		$coach_type = isset( $r['coach_type'] ) ? (string) $r['coach_type'] : 'principal';
 
		$fed_id = isset( $r['federation_id'] ) ? (int) $r['federation_id'] : 0;
		// Source federation URL from the legacy id (no WP post lookup): /federacao/{id}/
		$federation_url = $fed_id > 0 ? user_trailingslashit( home_url( 'federacao/' . $fed_id ) ) : '';
 
		$base_row = [
			'ts_wp_id'         => $teamstep,           // legacy teamstep id
			'team_wp_id'       => $team_id,            // legacy team id (URL built from it)
			'team_name'        => isset( $r['team_name'] ) ? (string) $r['team_name'] : '',
			'team_url'         => (string) $team_url,
			'country_name'     => isset( $r['country_name'] ) ? (string) $r['country_name'] : '',
			'country_flag'     => f365_country_flag_from_legacy( $country_id ),
			'flag_class'       => $country_id > 0 ? 'flag-icon flag-icon-' . $country_id : '',
			'federation_url'   => $federation_url,
			'edition'          => $edition,
			'season_label'     => function_exists( 'f365_president_edition_to_season' ) ? f365_president_edition_to_season( $edition, $fed ) : $edition,
			'coach_type'       => $coach_type,
			'coach_type_label' => f365_coach_type_label( $coach_type ),
			'modality'         => isset( $r['modality'] ) ? (string) $r['modality'] : 'football',
			'gender'           => isset( $r['gender'] ) ? (string) $r['gender'] : 'male',
			'step'             => isset( $r['step'] ) ? (string) $r['step'] : 'principal',
		];
 
		if ( $team_type === 'national' ) {
			$base_row['competitions'] = $comps_by_tsc[ $tsc_id ] ?? [];
			$national[] = $base_row;
		} else {
			$club[] = $base_row;
		}
	}
 
	$result = [ 'club' => $club, 'national' => $national ];
	wp_cache_set( $cache_key, $result, 'f365' );
	return $result;
}
 
 
/**
 * Competitions per teamstep-coach row — one query for a set of tsc ids.
 * Returns [ tsc_id => [ ['wp_id','name','url','flag'], ... ] ].
 */
function f365_coach_competitions_by_tsc( array $tsc_ids ): array {
 
	$out = [];
	$tsc_ids = array_values( array_unique( array_filter( array_map( 'intval', $tsc_ids ), static function ( $i ) { return $i > 0; } ) ) );
	if ( empty( $tsc_ids ) ) {
		return $out;
	}
 
	global $wpdb;
	$T_TSCC = $wpdb->prefix . 'team_teamstepcoachcompetition';
	$T_COMP = $wpdb->prefix . 'competition_competition';
	if ( ! f365_src_table_exists( $T_TSCC ) || ! f365_src_table_exists( $T_COMP ) ) {
		return $out;
	}
 
	$ph = implode( ',', array_fill( 0, count( $tsc_ids ), '%d' ) );
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT  cc.teamstep_coach_id AS tsc_id,
		         co.id                AS comp_id,
		         co.name              AS comp_name,
		         co.country_id        AS country_id,
		         co.federation_competition_id AS fed_comp_id,
		         co.edition           AS comp_edition
		   FROM  {$T_TSCC} cc
		   JOIN  {$T_COMP} co ON co.id = cc.competition_id
		  WHERE  cc.teamstep_coach_id IN ($ph)",
		$tsc_ids
	), ARRAY_A );
 
	if ( $wpdb->last_error !== '' || ! is_array( $rows ) ) {
		return $out;
	}
 
	foreach ( $rows as $r ) {
		$tsc_id  = isset( $r['tsc_id'] ) ? (int) $r['tsc_id'] : 0;
		$comp_id = isset( $r['comp_id'] ) ? (int) $r['comp_id'] : 0;
		if ( $tsc_id <= 0 || $comp_id <= 0 ) {
			continue;
		}
		// Source competition URL from fed_comp id + edition (no WP post lookup): /competicao/{fed_comp_id}/?edition={edition}
		$fed_comp = isset( $r['fed_comp_id'] ) ? (int) $r['fed_comp_id'] : 0;
		$c_edit   = isset( $r['comp_edition'] ) ? (string) $r['comp_edition'] : '';
		$url = '';
		if ( $fed_comp > 0 ) {
			$url = home_url( '/competicao/' . $fed_comp . '/' );
			if ( $c_edit !== '' ) {
				$url = add_query_arg( 'edition', $c_edit, $url );
			}
		}
		$out[ $tsc_id ][] = [
			'wp_id' => $comp_id,
			'name'  => isset( $r['comp_name'] ) ? (string) $r['comp_name'] : '',
			'url'   => (string) $url,
			'flag'  => f365_country_flag_from_legacy( isset( $r['country_id'] ) ? (int) $r['country_id'] : 0 ),
		];
	}
	return $out;
}
 
 
/**
 * f365_get_coach_current_team() — most-recent active teamstep (source:
 * get_teamstep_as_coach_on_edition with order=0, newest edition). Single query.
 */
function f365_get_coach_current_team( int $coach_post_id ): ?array {
 
	if ( $coach_post_id <= 0 ) {
		return null;
	}
	$legacy_id = f365_coach_legacy_id( $coach_post_id );
	if ( $legacy_id <= 0 ) {
		return null;
	}
 
	global $wpdb;
	$T_TSC  = $wpdb->prefix . 'team_teamstepcoach';
	$T_TS   = $wpdb->prefix . 'team_teamstep';
	$T_TEAM = $wpdb->prefix . 'team_team';
	$T_CTRY = $wpdb->prefix . 'country_country';
	if ( ! f365_src_table_exists( $T_TSC ) || ! f365_src_table_exists( $T_TS ) || ! f365_src_table_exists( $T_TEAM ) ) {
		return null;
	}
 
	// Most recent edition, order=0 (head coach). One query, LIMIT 1.
	$r = $wpdb->get_row( $wpdb->prepare(
		"SELECT  t.id        AS team_id,
		         t.shortname AS team_name,
		         t.country_id AS country_id,
		         c.shortname AS country_name
		   FROM  {$T_TSC} tsc
		   JOIN  {$T_TS}   ts ON ts.id = tsc.team_id
		   JOIN  {$T_TEAM} t  ON t.id  = ts.team_id
		   LEFT JOIN {$T_CTRY} c ON c.id = t.country_id
		  WHERE  tsc.coach_id = %d AND COALESCE( tsc.`order`, 0 ) = 0
		  ORDER  BY CAST( tsc.edition AS UNSIGNED ) DESC
		  LIMIT  1",
		$legacy_id
	), ARRAY_A );
 
	if ( ! is_array( $r ) || empty( $r['team_id'] ) ) {
		return null;
	}
 
	$team_id  = (int) $r['team_id'];
	// Source team URL from the legacy id (no WP post lookup): /equipa/{id}/
	$team_url = user_trailingslashit( home_url( 'equipa/' . $team_id ) );
 
	return [
		'team_wp_id'   => $team_id,
		'team_name'    => isset( $r['team_name'] ) ? (string) $r['team_name'] : '',
		'team_url'     => (string) $team_url,
		'country_name' => isset( $r['country_name'] ) ? (string) $r['country_name'] : '',
		'country_flag' => f365_country_flag_from_legacy( isset( $r['country_id'] ) ? (int) $r['country_id'] : 0 ),
	];
}
 
 
/** Cross-role linked player — coach.person_id → player; WP post id for the URL. */
function f365_coach_linked_player( int $legacy_coach_id ): array {
	$none = [ 'id' => 0, 'url' => '', 'name' => '' ];
	if ( $legacy_coach_id <= 0 ) {
		return $none;
	}
	global $wpdb;
	$T_COACH  = $wpdb->prefix . 'person_coach';
	$T_PLAYER = $wpdb->prefix . 'person_player';
	$T_PERS   = $wpdb->prefix . 'person_person';
	if ( ! f365_src_table_exists( $T_COACH ) || ! f365_src_table_exists( $T_PLAYER ) ) {
		return $none;
	}
 
	$rec = $wpdb->get_row( $wpdb->prepare(
		"SELECT pl.id AS player_id, pe.shortname AS player_name
		   FROM {$T_COACH} co
		   JOIN {$T_PLAYER} pl ON pl.person_id = co.person_id
		   LEFT JOIN {$T_PERS} pe ON pe.id = co.person_id
		  WHERE co.id = %d LIMIT 1",
		$legacy_coach_id
	), ARRAY_A );
 
	$legacy_player_id = ( is_array( $rec ) && isset( $rec['player_id'] ) ) ? (int) $rec['player_id'] : 0;
	if ( $legacy_player_id <= 0 ) {
		return $none;
	}
 
	// Source player URL from the legacy id we already have (no WP post lookup): /jogador/{id}/
	$url  = user_trailingslashit( home_url( 'jogador/' . $legacy_player_id ) );
	$name = ( is_array( $rec ) && isset( $rec['player_name'] ) ) ? (string) $rec['player_name'] : '';
	return [ 'id' => $legacy_player_id, 'url' => (string) $url, 'name' => (string) $name ];
}
 
 
/* ###########################################################################
 * ##  REFEREE MODULE
 * ######################################################################### */
 
/**
 * f365_get_referee_data() — profile from wp_person_referee → person → country.
 * Adds 'level'. Country/flag = term logic; photo = source path (role 'player'
 * — referees share the person photo namespace; source uses person.id).
 */
function f365_get_referee_data( int $post_id ): array {
 
	$cache_key = 'f365_referee_data_' . $post_id;
	$cached    = wp_cache_get( $cache_key, 'f365' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
 
	global $wpdb;
	$post_type = $wpdb->get_var( $wpdb->prepare(
		"SELECT post_type FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $post_id
	) );
	if ( $post_type !== 'f365_referee' ) {
		return [];
	}
 
	$fallback_photo = defined( 'FB_THEME_URL' ) ? FB_THEME_URL . '/assets/images/no-image.jpg' : f365_src_person_photo( 0 );
	$title          = get_the_title( $post_id );
 
	$base = [
		'post_id'        => $post_id,
		'shortname'      => $title,
		'longname'       => '',
		'display_name'   => $title !== '' ? $title : ( 'Árbitro #' . $post_id ),
		'level'          => '',
		'birth_date'     => '',
		'death_date'     => '',
		'birth_place'    => '',
		'age'            => null,
		'is_deceased'    => false,
		'header_country' => f365_src_country_empty(),
		'nationality'    => f365_src_country_empty(),
		'naturality'     => f365_src_country_empty(),
		'photo_url'      => $fallback_photo,
		'social_links'   => [],
		'website_url'    => '',
		'base_url'       => function_exists( 'f365_referee_url' ) ? f365_referee_url( $post_id ) : (string) get_permalink( $post_id ),
		'games_url'      => function_exists( 'f365_referee_url' ) ? f365_referee_url( $post_id, 'jogos' ) : trailingslashit( (string) get_permalink( $post_id ) ) . 'jogos/',
	];
 
	$legacy_id = f365_referee_legacy_id( $post_id );
	if ( $legacy_id <= 0 ) {
		f365_president_log( "referee: no legacy_id for post {$post_id} — baseline." );
		return $base;
	}
 
	$T_REF  = $wpdb->prefix . 'person_referee';
	$T_PERS = $wpdb->prefix . 'person_person';
	$T_CTRY = $wpdb->prefix . 'country_country';
	if ( ! f365_src_table_exists( $T_REF ) || ! f365_src_table_exists( $T_PERS ) ) {
		f365_president_log( "referee: source tables missing — baseline." );
		return $base;
	}
 
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT  rf.person_id, rf.level,
		         p.shortname, p.longname, p.slug,
		         p.birth_date, p.death_date, p.birth_place,
		         p.url, p.facebook, p.twitter, p.youtube, p.instagram, p.googleplus,
		         p.nationality_id, p.naturality_id,
		         nat.shortname AS nat_name, nat.slug AS nat_slug,
		         ntr.shortname AS ntr_name, ntr.slug AS ntr_slug
		   FROM  {$T_REF} rf
		   JOIN  {$T_PERS} p   ON p.id = rf.person_id
		   LEFT JOIN {$T_CTRY} nat ON nat.id = p.nationality_id
		   LEFT JOIN {$T_CTRY} ntr ON ntr.id = p.naturality_id
		  WHERE  rf.id = %d LIMIT 1",
		$legacy_id
	), ARRAY_A );
 
	if ( $wpdb->last_error !== '' || ! is_array( $row ) ) {
		f365_president_log( "referee: no row / SQL error legacy_id={$legacy_id}: {$wpdb->last_error}" );
		return $base;
	}
 
	$person_id   = isset( $row['person_id'] ) ? (int) $row['person_id'] : 0;
	$shortname   = isset( $row['shortname'] ) ? (string) $row['shortname'] : '';
	$longname    = isset( $row['longname'] ) ? (string) $row['longname'] : '';
	$birth_date  = isset( $row['birth_date'] ) ? (string) $row['birth_date'] : '';
	$death_date  = isset( $row['death_date'] ) ? (string) $row['death_date'] : '';
	$birth_place = isset( $row['birth_place'] ) ? (string) $row['birth_place'] : '';
	if ( $death_date === '0000-00-00' ) { $death_date = ''; }
	if ( $birth_date === '0000-00-00' ) { $birth_date = ''; }
 
	$display_name = ( $longname !== '' && $longname !== $shortname ) ? $longname : $shortname;
	if ( $display_name === '' ) { $display_name = $base['display_name']; }
 
	$is_deceased = ( $death_date !== '' );
	$age         = null;
	if ( $birth_date !== '' ) {
		try {
			$born = new DateTime( $birth_date );
			$ref  = $is_deceased ? new DateTime( $death_date ) : new DateTime( 'today' );
			$age  = (int) $born->diff( $ref )->y;
		} catch ( Exception $e ) {
			$age = null;
		}
	}
 
	$nationality = ( isset( $row['nationality_id'] ) && (int) $row['nationality_id'] > 0 )
		? f365_src_country_arr( (int) $row['nationality_id'], (string) ( $row['nat_name'] ?? '' ), (string) ( $row['nat_slug'] ?? '' ) )
		: f365_src_country_empty();
	$naturality  = ( isset( $row['naturality_id'] ) && (int) $row['naturality_id'] > 0 )
		? f365_src_country_arr( (int) $row['naturality_id'], (string) ( $row['ntr_name'] ?? '' ), (string) ( $row['ntr_slug'] ?? '' ) )
		: f365_src_country_empty();
	$header_country = ( $naturality['name'] !== '' ) ? $naturality : $nationality;
 
	$social_links = function_exists( 'f365_get_president_socials' )
		? f365_get_president_socials( [
			'facebook'  => isset( $row['facebook'] )  ? (string) $row['facebook']  : '',
			'twitter'   => isset( $row['twitter'] )   ? (string) $row['twitter']   : '',
			'instagram' => isset( $row['instagram'] ) ? (string) $row['instagram'] : '',
			'youtube'   => isset( $row['youtube'] )   ? (string) $row['youtube']   : '',
		] ) : [];
 
	$data = array_merge( $base, [
		'shortname'      => $shortname !== '' ? $shortname : $base['shortname'],
		'longname'       => $longname,
		'display_name'   => $display_name,
		'level'          => isset( $row['level'] ) ? (string) $row['level'] : '',
		'birth_date'     => $birth_date,
		'death_date'     => $death_date,
		'birth_place'    => $birth_place,
		'age'            => $age,
		'is_deceased'    => $is_deceased,
		'header_country' => $header_country,
		'nationality'    => $nationality,
		'naturality'     => $naturality,
		'photo_url'      => get_the_post_thumbnail_url( $post_id, 'full' ) ?: ( ( $person_id > 0 ) ? f365_src_person_photo( $person_id, 'referee' ) : $fallback_photo ),
		'social_links'   => is_array( $social_links ) ? $social_links : [],
		'website_url'    => isset( $row['url'] ) ? trim( (string) $row['url'] ) : '',
	] );
 
	wp_cache_set( $cache_key, $data, 'f365' );
	return $data;
}
 
 
/**
 * Legacy match ids officiated by a referee (position='main_referee',
 * match situation='finished'). Single query against the custom tables.
 */
function f365_referee_officiated_match_ids( int $referee_post_id ): array {
	$legacy_id = f365_referee_legacy_id( $referee_post_id );
	if ( $legacy_id <= 0 ) {
		return [];
	}
	global $wpdb;
	$T_MR    = $wpdb->prefix . 'competition_matchreferee';
	$T_MATCH = $wpdb->prefix . 'competition_match';
	if ( ! f365_src_table_exists( $T_MR ) || ! f365_src_table_exists( $T_MATCH ) ) {
		return [];
	}
 
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT mr.match_id
		   FROM {$T_MR} mr
		   JOIN {$T_MATCH} m ON m.id = mr.match_id
		  WHERE mr.referee_id = %d
		    AND mr.position    = 'main_referee'
		    AND m.situation    = 'finished'",
		$legacy_id
	) );
 
	return is_array( $ids ) ? array_values( array_unique( array_map( 'intval', $ids ) ) ) : [];
}
 
 
/**
 * Card totals per legacy match id (one query over matchplayer).
 * Source: a double-yellow counts as +1 yellow AND +1 double.
 */
function f365_referee_card_totals( array $legacy_match_ids ): array {
	$totals = [];
	$legacy_match_ids = array_values( array_filter( array_map( 'intval', $legacy_match_ids ), static function ( $i ) { return $i > 0; } ) );
	if ( empty( $legacy_match_ids ) ) {
		return $totals;
	}
	global $wpdb;
	$T_MP = $wpdb->prefix . 'competition_matchplayer';
	if ( ! f365_src_table_exists( $T_MP ) ) {
		return $totals;
	}
	$ph = implode( ',', array_fill( 0, count( $legacy_match_ids ), '%d' ) );
 
	// Aggregate in SQL — source treats double-yellow as both a yellow and a double.
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT  match_id,
		         SUM( CASE WHEN yellow_card = 1 OR double_yellow_card = 1 THEN 1 ELSE 0 END ) AS yellow,
		         SUM( CASE WHEN double_yellow_card = 1 THEN 1 ELSE 0 END ) AS double_y,
		         SUM( CASE WHEN red_card = 1 THEN 1 ELSE 0 END ) AS red
		   FROM  {$T_MP}
		  WHERE  match_id IN ($ph)
		  GROUP  BY match_id",
		$legacy_match_ids
	), ARRAY_A );
 
	foreach ( (array) $rows as $r ) {
		$mid = isset( $r['match_id'] ) ? (int) $r['match_id'] : 0;
		if ( $mid <= 0 ) {
			continue;
		}
		$totals[ $mid ] = [
			'yellow' => isset( $r['yellow'] ) ? (int) $r['yellow'] : 0,
			'double' => isset( $r['double_y'] ) ? (int) $r['double_y'] : 0,
			'red'    => isset( $r['red'] ) ? (int) $r['red'] : 0,
		];
	}
	return $totals;
}
 
 
/**
 * Load referee matches grouped by competition. Single query for match+competition
 * +team names (teamstep→team), plus one card-totals query. Mirrors the original
 * return shape: competitions / by_comp / totals / grand.
 */
function f365_referee_load_matches( array $legacy_match_ids ): array {
	$out = [
		'competitions' => [],
		'by_comp'      => [],
		'totals'       => [],
		'grand'        => [ 'games' => 0, 'yellow' => 0, 'double' => 0, 'red' => 0 ],
	];
	$legacy_match_ids = array_values( array_filter( array_map( 'intval', $legacy_match_ids ), static function ( $i ) { return $i > 0; } ) );
	if ( empty( $legacy_match_ids ) ) {
		return $out;
	}
 
	global $wpdb;
	$T_MATCH = $wpdb->prefix . 'competition_match';
	$T_COMP  = $wpdb->prefix . 'competition_competition';
	$T_TS    = $wpdb->prefix . 'team_teamstep';
	$T_TEAM  = $wpdb->prefix . 'team_team';
	if ( ! f365_src_table_exists( $T_MATCH ) || ! f365_src_table_exists( $T_COMP ) ) {
		return $out;
	}
 
	$card_totals = f365_referee_card_totals( $legacy_match_ids );
	$ph = implode( ',', array_fill( 0, count( $legacy_match_ids ), '%d' ) );
 
	// Single query: match → competition, and home/visitor teamstep → team name.
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT  m.id            AS match_id,
		         m.date_time      AS date_time,
		         m.home_score     AS home_score,
		         m.visitor_score  AS visitor_score,
		         m.competition_id AS comp_id,
		         co.name          AS comp_name,
		         co.edition       AS comp_edition,
		         co.season        AS comp_season,
		         co.country_id    AS comp_country_id,
		         co.federation_competition_id AS fed_comp_id,
		         hteam.id         AS home_team_id,
		         hteam.shortname  AS home_name,
		         vteam.id         AS visitor_team_id,
		         vteam.shortname  AS visitor_name
		   FROM  {$T_MATCH} m
		   JOIN  {$T_COMP} co ON co.id = m.competition_id
		   LEFT JOIN {$T_TS}   ht    ON ht.id = m.home_team
		   LEFT JOIN {$T_TEAM} hteam ON hteam.id = ht.team_id
		   LEFT JOIN {$T_TS}   vt    ON vt.id = m.visitor_team
		   LEFT JOIN {$T_TEAM} vteam ON vteam.id = vt.team_id
		  WHERE  m.id IN ($ph)",
		$legacy_match_ids
	), ARRAY_A );
 
	if ( $wpdb->last_error !== '' || ! is_array( $rows ) ) {
		f365_president_log( "referee matches: SQL error: {$wpdb->last_error}" );
		return $out;
	}
 
	foreach ( $rows as $r ) {
		$match_id = isset( $r['match_id'] ) ? (int) $r['match_id'] : 0;
		$comp_id  = isset( $r['comp_id'] ) ? (int) $r['comp_id'] : 0;
		if ( $match_id <= 0 || $comp_id <= 0 ) {
			continue;
		}
 
		if ( ! isset( $out['competitions'][ $comp_id ] ) ) {
			$edition = isset( $r['comp_edition'] ) ? (string) $r['comp_edition'] : '';
			$fed_comp_id_url = isset( $r['fed_comp_id'] ) ? (int) $r['fed_comp_id'] : 0;
			// Source competition URL from fed_comp id + edition (no WP post lookup): /competicao/{fed_comp_id}/?edition={edition}
			$comp_url = '';
			if ( $fed_comp_id_url > 0 ) {
				$comp_url = home_url( '/competicao/' . $fed_comp_id_url . '/' );
				if ( $edition !== '' ) {
					$comp_url = add_query_arg( 'edition', $edition, $comp_url );
				}
			}
			$season  = isset( $r['comp_season'] ) && $r['comp_season'] !== '' ? (string) $r['comp_season'] : $edition;
			$out['competitions'][ $comp_id ] = [
				'id'          => $comp_id,
				'wp_id'       => $comp_id,
				'name'        => isset( $r['comp_name'] ) ? (string) $r['comp_name'] : '',
				'url'         => (string) $comp_url,
				'flag'        => f365_country_flag_from_legacy( isset( $r['comp_country_id'] ) ? (int) $r['comp_country_id'] : 0 ),
				'edition'     => $edition,
				'season'      => $season,
				'fed_comp_id' => isset( $r['fed_comp_id'] ) ? (int) $r['fed_comp_id'] : 0,
			];
			$out['by_comp'][ $comp_id ] = [];
			$out['totals'][ $comp_id ]  = [ 'games' => 0, 'yellow' => 0, 'double' => 0, 'red' => 0 ];
		}
 
		// Source team URLs from the legacy team ids (no WP post lookup): /equipa/{id}/
		$home_tid = isset( $r['home_team_id'] ) ? (int) $r['home_team_id'] : 0;
		$away_tid = isset( $r['visitor_team_id'] ) ? (int) $r['visitor_team_id'] : 0;
 
		$cards = $card_totals[ $match_id ] ?? [ 'yellow' => 0, 'double' => 0, 'red' => 0 ];
 
		$out['by_comp'][ $comp_id ][] = [
			'match_id'      => $match_id,
			'date_time'     => isset( $r['date_time'] ) ? (string) $r['date_time'] : '',
			'home_name'     => isset( $r['home_name'] ) ? (string) $r['home_name'] : '',
			'home_url'      => $home_tid > 0 ? user_trailingslashit( home_url( 'equipa/' . $home_tid ) ) : '',
			'away_name'     => isset( $r['visitor_name'] ) ? (string) $r['visitor_name'] : '',
			'away_url'      => $away_tid > 0 ? user_trailingslashit( home_url( 'equipa/' . $away_tid ) ) : '',
			'home_score'    => isset( $r['home_score'] ) ? $r['home_score'] : '',
			'visitor_score' => isset( $r['visitor_score'] ) ? $r['visitor_score'] : '',
			'yellow'        => (int) $cards['yellow'],
			'double'        => (int) $cards['double'],
			'red'           => (int) $cards['red'],
		];
 
		$out['totals'][ $comp_id ]['games']  += 1;
		$out['totals'][ $comp_id ]['yellow'] += (int) $cards['yellow'];
		$out['totals'][ $comp_id ]['double'] += (int) $cards['double'];
		$out['totals'][ $comp_id ]['red']    += (int) $cards['red'];
		$out['grand']['games']  += 1;
		$out['grand']['yellow'] += (int) $cards['yellow'];
		$out['grand']['double'] += (int) $cards['double'];
		$out['grand']['red']    += (int) $cards['red'];
	}
 
	// Sort matches within each competition by date desc.
	foreach ( $out['by_comp'] as $cid => $rws ) {
		usort( $rws, static function ( $a, $b ) { return strcmp( (string) $b['date_time'], (string) $a['date_time'] ); } );
		$out['by_comp'][ $cid ] = $rws;
	}
	// Sort competitions by edition desc.
	uasort( $out['competitions'], static function ( $a, $b ) { return strcmp( (string) $b['edition'], (string) $a['edition'] ); } );
 
	return $out;
}
 
 
/** Is a (legacy) competition national-team scope? Reads federation_competition.scope. Cached. */
function f365_referee_competition_is_national( int $fed_comp_id ): bool {
	static $cache = [];
	if ( array_key_exists( $fed_comp_id, $cache ) ) {
		return $cache[ $fed_comp_id ];
	}
	if ( $fed_comp_id <= 0 ) {
		return $cache[ $fed_comp_id ] = false;
	}
	global $wpdb;
	$T_FC = $wpdb->prefix . 'federation_federationcompetition';
	if ( ! f365_src_table_exists( $T_FC ) ) {
		return $cache[ $fed_comp_id ] = false;
	}
	$scope = (string) $wpdb->get_var( $wpdb->prepare(
		"SELECT scope FROM {$T_FC} WHERE id = %d LIMIT 1",
		$fed_comp_id
	) );
	$national_scopes = explode( ',', F365_NATIONAL_SCOPES );
	return $cache[ $fed_comp_id ] = ( $scope !== '' && in_array( $scope, $national_scopes, true ) );
}
 
 
/**
 * f365_get_referee_resume() — competitions split club/national, with game counts.
 * Same return shape as before.
 */
function f365_get_referee_resume( int $referee_post_id ): array {
	$cache_key = 'f365_referee_resume_' . $referee_post_id;
	$cached    = wp_cache_get( $cache_key, 'f365' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$empty = [ 'club' => [], 'national' => [] ];
	if ( $referee_post_id <= 0 ) {
		return $empty;
	}
 
	$match_ids = f365_referee_officiated_match_ids( $referee_post_id );
	if ( empty( $match_ids ) ) {
		wp_cache_set( $cache_key, $empty, 'f365' );
		return $empty;
	}
 
	$loaded = f365_referee_load_matches( $match_ids );
 
	$club     = [];
	$national = [];
	foreach ( $loaded['competitions'] as $comp_id => $comp ) {
		$games = (int) ( $loaded['totals'][ $comp_id ]['games'] ?? 0 );
		$row = [
			'comp_id' => $comp_id,
			'name'    => (string) ( $comp['name'] ?? '' ),
			'url'     => (string) ( $comp['url'] ?? '' ),
			'flag'    => (string) ( $comp['flag'] ?? '' ),
			'season'  => (string) ( $comp['season'] ?? '' ),
			'edition' => (string) ( $comp['edition'] ?? '' ),
			'games'   => $games,
		];
		if ( f365_referee_competition_is_national( (int) ( $comp['fed_comp_id'] ?? 0 ) ) ) {
			$national[] = $row;
		} else {
			$club[] = $row;
		}
	}
 
	$result = [ 'club' => $club, 'national' => $national ];
	wp_cache_set( $cache_key, $result, 'f365' );
	return $result;
}
 
 
/**
 * f365_get_referee_games() — full games table for a referee, optionally filtered
 * by edition. Same return shape (competitions/by_comp/totals/grand/editions/edition).
 */
function f365_get_referee_games( int $referee_post_id, string $edition = '' ): array {
	$empty = [
		'competitions' => [],
		'by_comp'      => [],
		'totals'       => [],
		'grand'        => [ 'games' => 0, 'yellow' => 0, 'double' => 0, 'red' => 0 ],
		'editions'     => [],
		'edition'      => '',
	];
	if ( $referee_post_id <= 0 ) {
		return $empty;
	}
 
	$cache_key = 'f365_referee_games_' . $referee_post_id . '_' . md5( $edition );
	$cached    = wp_cache_get( $cache_key, 'f365' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
 
	$match_ids = f365_referee_officiated_match_ids( $referee_post_id );
	if ( empty( $match_ids ) ) {
		wp_cache_set( $cache_key, $empty, 'f365' );
		return $empty;
	}
 
	$loaded = f365_referee_load_matches( $match_ids );
 
	// Distinct editions (most recent first).
	$editions = [];
	foreach ( $loaded['competitions'] as $comp ) {
		$ed = (string) ( $comp['edition'] ?? '' );
		if ( $ed !== '' && ! isset( $editions[ $ed ] ) ) {
			$editions[ $ed ] = ( ! empty( $comp['season'] ) ) ? (string) $comp['season'] : $ed;
		}
	}
	krsort( $editions );
 
	if ( $edition === '' && ! empty( $editions ) ) {
		$edition = (string) array_key_first( $editions );
	}
 
	// Filter to the selected edition.
	if ( $edition !== '' ) {
		foreach ( array_keys( $loaded['competitions'] ) as $cid ) {
			if ( (string) $loaded['competitions'][ $cid ]['edition'] !== $edition ) {
				unset( $loaded['competitions'][ $cid ], $loaded['by_comp'][ $cid ], $loaded['totals'][ $cid ] );
			}
		}
		$grand = [ 'games' => 0, 'yellow' => 0, 'double' => 0, 'red' => 0 ];
		foreach ( $loaded['totals'] as $t ) {
			$grand['games']  += (int) $t['games'];
			$grand['yellow'] += (int) $t['yellow'];
			$grand['double'] += (int) $t['double'];
			$grand['red']    += (int) $t['red'];
		}
		$loaded['grand'] = $grand;
	}
 
	$loaded['editions'] = $editions;
	$loaded['edition']  = $edition;
 
	wp_cache_set( $cache_key, $loaded, 'f365' );
	return $loaded;
}
 
 
/* ===========================================================================
 * SECTION CONFIGS (unchanged shapes; kept for the single-* templates).
 * =========================================================================== */
if ( ! function_exists( 'get_coach_section_functions' ) ) {
	function get_coach_section_functions(): array {
		return [
			'resumo'   => [ 'template' => 'resumo',   'label' => esc_html__( 'Resumo', 'futebol365' ) ],
			'noticias' => [ 'template' => 'noticias', 'label' => esc_html__( 'Notícias', 'futebol365' ) ],
			'info'     => [ 'template' => 'info',     'label' => esc_html__( 'Dados Pessoais', 'futebol365' ) ],
		];
	}
}
if ( ! function_exists( 'get_referee_section_functions' ) ) {
	function get_referee_section_functions(): array {
		return [
			'resumo' => [ 'template' => 'resume', 'label' => esc_html__( 'Resumo', 'futebol365' ) ],
			'jogos'  => [ 'template' => 'games',  'label' => esc_html__( 'Jogos', 'futebol365' ) ],
		];
	}
}


 

function f365_coach_news_total( int $coach_post_id ): int {
 
	if ( $coach_post_id <= 0 ) {
		return 0;
	}
 
	$cache_key = 'f365_coach_news_count_' . $coach_post_id;
	$cached    = wp_cache_get( $cache_key, 'f365' );
	if ( $cached !== false ) {
		return (int) $cached;
	}
 
	$legacy_id = f365_coach_legacy_id( $coach_post_id );
	if ( $legacy_id <= 0 ) {
		wp_cache_set( $cache_key, 0, 'f365', HOUR_IN_SECONDS );
		return 0;
	}
 
	global $wpdb;
	$T_PIVOT = $wpdb->prefix . 'publish_news_coaches';
	$T_NEWS  = $wpdb->prefix . 'publish_news';
	if ( ! f365_src_table_exists( $T_PIVOT ) || ! f365_src_table_exists( $T_NEWS ) ) {
		return 0;
	}
 
	// COUNT only the news that actually map to a published WP post, so the
	// total matches what the page query can render (no empty trailing pages).
	$total = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*)
		   FROM {$T_PIVOT} pc
		   JOIN {$T_NEWS}  n  ON n.id = pc.news_id AND n.datetime <= %s
		   JOIN {$wpdb->posts} p
		        ON p.legacy_table = 'publish_news' AND p.legacy_id = n.id
		       AND p.post_type = 'post' AND p.post_status = 'publish'
		  WHERE pc.coach_id = %d",
		current_time( 'mysql' ),
		$legacy_id
	) );
 
	wp_cache_set( $cache_key, $total, 'f365', HOUR_IN_SECONDS );
	return $total;
}
 
 
/**
 * One page of a coach's related news, fetched + cached. Returns only the fields
 * the template renders. Ordered by news datetime DESC; future-dated excluded.
 *
 * Each row:
 *   legacy_news_id, wp_post_id, title, excerpt (post_excerpt),
 *   legacy_summary, wp_cat_name (may be ''), legacy_cat_name (may be ''),
 *   legacy_image (relative path from publish_news.image)
 *
 * The template applies the WP-first fallback (featured image / WP category /
 * WP excerpt) and only falls back to the legacy_* fields when WP data is empty.
 *
 * @return array[] page rows (may be empty)
 */
function f365_coach_news_page( int $coach_post_id, int $paged, int $per_page ): array {
 
	$paged    = max( 1, $paged );
	$per_page = max( 1, $per_page );
 
	if ( $coach_post_id <= 0 ) {
		return [];
	}
 
	$cache_key = 'f365_coach_news_page_' . $coach_post_id . '_' . $per_page . '_' . $paged;
	$cached    = wp_cache_get( $cache_key, 'f365' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
 
	$legacy_id = f365_coach_legacy_id( $coach_post_id );
	if ( $legacy_id <= 0 ) {
		return [];
	}
 
	global $wpdb;
	$T_PIVOT = $wpdb->prefix . 'publish_news_coaches';
	$T_NEWS  = $wpdb->prefix . 'publish_news';
	$T_NCAT  = $wpdb->prefix . 'publish_news_categories';
	$T_CAT   = $wpdb->prefix . 'publish_newscategory';
	if ( ! f365_src_table_exists( $T_PIVOT ) || ! f365_src_table_exists( $T_NEWS ) ) {
		return [];
	}
 
	$offset = ( $paged - 1 ) * $per_page;
 
	// Single paginated query. One category per news via a correlated subquery
	// (MIN id) so the JOIN doesn't multiply rows; LEFT so news without a legacy
	// category still return. WP post resolved via legacy_table/legacy_id columns.
	$sql = $wpdb->prepare(
		"SELECT  n.id          AS legacy_news_id,
		         p.ID          AS wp_post_id,
		         p.post_title  AS title,
		         p.post_excerpt AS excerpt,
		         n.summary     AS legacy_summary,
		         n.image       AS legacy_image,
		         (
		             SELECT c.name
		               FROM {$T_NCAT} nc
		               JOIN {$T_CAT}  c ON c.id = nc.newscategory_id
		              WHERE nc.news_id = n.id
		              ORDER BY nc.newscategory_id ASC
		              LIMIT 1
		         )             AS legacy_cat_name,
		         n.datetime    AS news_datetime
		   FROM  {$T_PIVOT} pc
		   JOIN  {$T_NEWS}  n  ON n.id = pc.news_id AND n.datetime <= %s
		   JOIN  {$wpdb->posts} p
		         ON p.legacy_table = 'publish_news' AND p.legacy_id = n.id
		        AND p.post_type = 'post' AND p.post_status = 'publish'
		  WHERE  pc.coach_id = %d
		  ORDER  BY n.datetime DESC
		  LIMIT  %d OFFSET %d",
		current_time( 'mysql' ),
		$legacy_id,
		$per_page,
		$offset
	);
 
	$rows = $wpdb->get_results( $sql, ARRAY_A );
 
	if ( $wpdb->last_error !== '' || ! is_array( $rows ) ) {
		if ( $wpdb->last_error !== '' ) {
			f365_president_log( "coach news page: SQL error coach={$coach_post_id}: {$wpdb->last_error}" );
		}
		return [];
	}
 
	// Normalise types once here so the template stays presentation-only.
	$out = [];
	foreach ( $rows as $r ) {
		$out[] = [
			'legacy_news_id'  => isset( $r['legacy_news_id'] ) ? (int) $r['legacy_news_id'] : 0,
			'wp_post_id'      => isset( $r['wp_post_id'] ) ? (int) $r['wp_post_id'] : 0,
			'title'           => isset( $r['title'] ) ? (string) $r['title'] : '',
			'excerpt'         => isset( $r['excerpt'] ) ? (string) $r['excerpt'] : '',
			'legacy_summary'  => isset( $r['legacy_summary'] ) ? (string) $r['legacy_summary'] : '',
			'legacy_cat_name' => isset( $r['legacy_cat_name'] ) && $r['legacy_cat_name'] !== null ? (string) $r['legacy_cat_name'] : '',
			'legacy_image'    => isset( $r['legacy_image'] ) ? (string) $r['legacy_image'] : '',
		];
	}
 
	// Prime the page's WP posts in ONE batched load so the template's
	// get_permalink()/the_post_thumbnail()/get_the_category() are cache hits
	// (no N+1) and render byte-identical markup to the old WP_Query path.
	$page_post_ids = array_values( array_filter( array_map(
		static function ( $row ) { return (int) $row['wp_post_id']; }, $out
	), static function ( $id ) { return $id > 0; } ) );
	if ( ! empty( $page_post_ids ) ) {
		_prime_post_caches( $page_post_ids, true, true );
	}
 
	wp_cache_set( $cache_key, $out, 'f365', HOUR_IN_SECONDS );
	return $out;
}
 
 
/**
 * Build a source-site news image URL from the relative path stored in
 * publish_news.image. Images live under the WordPress-root /media/ folder
 * (F365_MEDIA_DIR). Mirrors the source MEDIA_URL + relative path.
 *
 * @return string '' when no path.
 */
function f365_news_legacy_image_url( string $relative_path ): string {
	$relative_path = trim( $relative_path );
	if ( $relative_path === '' ) {
		return '';
	}
	// f365_src_base_url() resolves F365_MEDIA_DIR ('media') against home_url().
	$base = function_exists( 'f365_src_base_url' )
		? f365_src_base_url( defined( 'F365_MEDIA_DIR' ) ? F365_MEDIA_DIR : 'media' )
		: rtrim( home_url( '/media' ), '/' );
	return $base . '/' . ltrim( $relative_path, '/' );
}


// ──────────────────────====================================────────────────────────────────────────────
 

 


// ========================================== End ==========================

function f365_get_player_header_data( int $post_id ): array {
 
	static $memo = [];
	if ( array_key_exists( $post_id, $memo ) ) {
		return $memo[ $post_id ];
	}
	if ( $post_id <= 0 ) {
		return $memo[ $post_id ] = [];
	}
 
	$cache_key = 'f365_player_header_' . $post_id;
	$cached    = wp_cache_get( $cache_key, 'f365' );
	if ( is_array( $cached ) ) {
		return $memo[ $post_id ] = $cached;
	}
 
	$legacy_id = function_exists( 'f365_player_legacy_id' ) ? f365_player_legacy_id( $post_id ) : 0;
	if ( $legacy_id <= 0 ) {
		return $memo[ $post_id ] = [];
	}
 
	global $wpdb;
	$T_PLAYER = $wpdb->prefix . 'person_player';
	$T_PERS   = $wpdb->prefix . 'person_person';
	$T_CTRY   = $wpdb->prefix . 'country_country';
	if ( ! f365_src_table_exists( $T_PLAYER ) || ! f365_src_table_exists( $T_PERS ) ) {
		return $memo[ $post_id ] = [];
	}
 
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT  pl.person_id, pl.position,
		         p.shortname, p.birth_date, p.death_date, p.birth_place,
		         p.facebook, p.twitter, p.youtube, p.instagram, p.url,
		         p.nationality_id, p.naturality_id,
		         nat.shortname AS nationality_name,
		         ntr.shortname AS naturality_name
		   FROM  {$T_PLAYER} pl
		   JOIN  {$T_PERS} p   ON p.id = pl.person_id
		   LEFT JOIN {$T_CTRY} nat ON nat.id = p.nationality_id
		   LEFT JOIN {$T_CTRY} ntr ON ntr.id = p.naturality_id
		  WHERE  pl.id = %d
		  LIMIT  1",
		$legacy_id
	), ARRAY_A );
 
	if ( $wpdb->last_error !== '' || ! is_array( $row ) ) {
		return $memo[ $post_id ] = [];
	}
 
	$person_id   = isset( $row['person_id'] ) ? (int) $row['person_id'] : 0;
	$birth_date  = isset( $row['birth_date'] ) ? (string) $row['birth_date'] : '';
	$death_date  = isset( $row['death_date'] ) ? (string) $row['death_date'] : '';
	if ( $birth_date === '0000-00-00' ) { $birth_date = ''; }
	if ( $death_date === '0000-00-00' ) { $death_date = ''; }
 
	$is_deceased = ( $death_date !== '' );
	$nat_id      = isset( $row['nationality_id'] ) ? (int) $row['nationality_id'] : 0;
	$ntr_id      = isset( $row['naturality_id'] ) ? (int) $row['naturality_id'] : 0;
 
	// Current club (source get_team_as_player_on_edition). Hidden when deceased.
	$club = ( ! $is_deceased ) ? f365_player_current_club( $legacy_id ) : null;
 
	$photo_url = ( $person_id > 0 ) ? f365_person_image_url( $person_id ) : '';

 
	$data = [
		'post_id'        => $post_id,
		'shortname'      => isset( $row['shortname'] ) ? (string) $row['shortname'] : '',
		'position_label' => function_exists( 'f365_player_position_display' )
			? f365_player_position_display( (string) ( $row['position'] ?? '' ) )
			: (string) ( $row['position'] ?? '' ),
		'is_deceased'    => $is_deceased,
		'age'            => ( ! $is_deceased && function_exists( 'f365_player_age' ) ) ? f365_player_age( $birth_date ) : null,
		'birth_place'    => isset( $row['birth_place'] ) ? (string) $row['birth_place'] : '',
		'photo_url'      => $photo_url,
 
		'naturality'     => [
			'id'         => $ntr_id,
			'name'       => isset( $row['naturality_name'] ) && $row['naturality_name'] !== null ? (string) $row['naturality_name'] : '',
			'flag_class' => $ntr_id > 0 ? 'flag-icon flag-icon-' . $ntr_id : '',
		],
		'nationality'    => [
			'id'         => $nat_id,
			'name'       => isset( $row['nationality_name'] ) && $row['nationality_name'] !== null ? (string) $row['nationality_name'] : '',
			'flag_class' => $nat_id > 0 ? 'flag-icon flag-icon-' . $nat_id : '',
		],
		// Show the nationality line separately only when it differs from naturality.
		'show_nationality_separately' => ( $ntr_id > 0 && $nat_id > 0 && $ntr_id !== $nat_id ),
 
		'club'           => $club, // ['name','url'] | null
 
		'socials'        => [
			'facebook'  => isset( $row['facebook'] )  ? trim( (string) $row['facebook'] )  : '',
			'twitter'   => isset( $row['twitter'] )   ? trim( (string) $row['twitter'] )   : '',
			'youtube'   => isset( $row['youtube'] )   ? trim( (string) $row['youtube'] )   : '',
			'instagram' => isset( $row['instagram'] ) ? trim( (string) $row['instagram'] ) : '',
		],
		'website'        => isset( $row['url'] ) ? trim( (string) $row['url'] ) : '',
	];
 
	wp_cache_set( $cache_key, $data, 'f365', 12 * HOUR_IN_SECONDS );
	return $memo[ $post_id ] = $data;
}
 
 
/**
 * Current club for the header (source: get_team_as_player_on_edition()).
 * Newest club squad by -edition, order; but if the same teamstep lineage has a
 * LATER edition the player is not in, the player has left → return null.
 * Returns [ 'name', 'url' ] | null. One/two small indexed queries. Cached.
 */
function f365_player_current_club( int $legacy_player_id ): ?array {
	static $cache = [];
	if ( array_key_exists( $legacy_player_id, $cache ) ) {
		return $cache[ $legacy_player_id ];
	}
	if ( $legacy_player_id <= 0 ) {
		return $cache[ $legacy_player_id ] = null;
	}
 
	global $wpdb;
	$T_SQUAD = $wpdb->prefix . 'team_squad';
	$T_TS    = $wpdb->prefix . 'team_teamstep';
	$T_TEAM  = $wpdb->prefix . 'team_team';
	if ( ! f365_src_table_exists( $T_SQUAD ) || ! f365_src_table_exists( $T_TS ) || ! f365_src_table_exists( $T_TEAM ) ) {
		return $cache[ $legacy_player_id ] = null;
	}
 
	// Newest club squad row for the player (+ its teamstep lineage keys).
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT  ts.id    AS teamstep_id,
		         ts.team_id AS team_id,
		         ts.step    AS step,
		         ts.gender  AS gender,
		         ts.modality AS modality,
		         sq.edition AS edition,
		         t.id       AS club_id,
		         t.shortname AS club_name
		   FROM  {$T_SQUAD} sq
		   JOIN  {$T_TS}   ts ON ts.id = sq.team_id
		   JOIN  {$T_TEAM} t  ON t.id  = ts.team_id AND t.team_type = 'club'
		  WHERE  sq.player_id = %d
		  ORDER  BY CAST( sq.edition AS UNSIGNED ) DESC, CAST( COALESCE( sq.`order`, 99999 ) AS UNSIGNED ) ASC
		  LIMIT  1",
		$legacy_player_id
	), ARRAY_A );
 
	if ( $wpdb->last_error !== '' || ! is_array( $row ) || empty( $row['club_id'] ) ) {
		return $cache[ $legacy_player_id ] = null;
	}
 
	// If the club's lineage has a LATER edition than this squad's, the player
	// has moved on (source returns None) → no current club.
	$later = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*)
		   FROM {$T_TS} ts
		  WHERE ts.team_id  = %d
		    AND ts.step     = %s
		    AND ts.gender   = %s
		    AND ts.modality = %s
		    AND CAST( ts.edition AS UNSIGNED ) > %d",
		(int) $row['team_id'],
		(string) ( $row['step'] ?? '' ),
		(string) ( $row['gender'] ?? '' ),
		(string) ( $row['modality'] ?? '' ),
		(int) $row['edition']
	) );
	if ( $later > 0 ) {
		return $cache[ $legacy_player_id ] = null;
	}
 
	$club_id    = (int) $row['club_id'];
	$club_wp_id = function_exists( 'f365_team_wp_id_from_legacy' ) ? f365_team_wp_id_from_legacy( $club_id ) : 0;
	$url        = ( $club_wp_id > 0 )
		? ( function_exists( 'f365_team_url' ) ? f365_team_url( $club_wp_id ) : (string) get_permalink( $club_wp_id ) )
		: '';
 
	return $cache[ $legacy_player_id ] = [
		'name' => isset( $row['club_name'] ) ? (string) $row['club_name'] : '',
		'url'  => (string) $url,
	];
}

if ( ! function_exists( 'f365_person_image_url' ) ) {
	function f365_person_image_url( int $person_id ): string {
		if ( $person_id <= 0 ) {
			return '';
		}
		$dir  = defined( 'F365_MEDIA_DIR' ) ? F365_MEDIA_DIR : 'media';
		$base = function_exists( 'f365_src_base_url' )
			? f365_src_base_url( $dir )
			: rtrim( home_url( '/' . ltrim( $dir, '/' ) ), '/' );
		return $base . '/images/person/' . $person_id . '.jpg';
	}
}



/**
 * Source media URL for a path/filename stored in a source table (image columns),
 * skipping the source no-image / no-photo placeholders. Single source of truth
 * for every listing's image building.
 *
 * @param string $path  Either a full relative path ("images/photos/x.jpg") or a
 *                      bare filename to be placed under $subdir.
 * @param string $subdir Folder when $path is a bare filename (e.g. 'images/videos').
 */
function f365_listing_media_url( string $path, string $subdir = '' ): string {
	$path = trim( $path );
	if ( $path === '' || stripos( $path, 'no-image' ) !== false || stripos( $path, 'no-photo' ) !== false ) {
		return '';
	}
 
	$dir  = defined( 'F365_MEDIA_DIR' ) ? F365_MEDIA_DIR : 'media';
	$base = function_exists( 'f365_src_base_url' )
		? f365_src_base_url( $dir )
		: rtrim( home_url( '/' . ltrim( $dir, '/' ) ), '/' );
 
	// If the stored value already looks like a path, use it as-is; else nest it.
	$rel = ( $subdir !== '' && strpos( $path, '/' ) === false )
		? trailingslashit( $subdir ) . $path
		: $path;
 
	return $base . '/' . ltrim( $rel, '/' );
}

if ( ! defined( 'F365_VIDEO_RELATED_LIMIT' ) ) {
	define( 'F365_VIDEO_RELATED_LIMIT', 8 );
}
 
 

/** Resolve the current video's LEGACY id from the query var / URL (no WP lookup). */
function f365_video_single_legacy_id(): int {
	$id = (int) get_query_var( 'f365_video_id' );
	if ( $id > 0 ) {
		return $id;
	}
	if ( preg_match( '#/multimedia/videos/(\d+)#', (string) ( $_SERVER['REQUEST_URI'] ?? '' ), $m ) ) {
		return (int) $m[1];
	}
	// Last resort: map the queried WP post back to its legacy id.
	if ( function_exists( 'f365_video_legacy_id' ) ) {
		return f365_video_legacy_id( (int) get_queried_object_id() );
	}
	return 0;
}
 

 
 
/** Player iframe src: embed → obj.url; else the internal player route. */
function f365_video_player_src( array $video ): string {
	if ( ( $video['file_type'] ?? '' ) === 'embed' && ! empty( $video['url'] ) ) {
		return (string) $video['url'];
	}
	return home_url( '/multimedia/video-player/' . (int) $video['id'] . '/' );
}
 
 
/**
 * Related-link buttons (source get_related_links). One small query per relation
 * pivot, batched. URLs built to match source patterns from legacy ids. Cached.
 *
 * @return array[] [ [ 'title' => string, 'class' => string, 'url' => string ], … ]
 */
function f365_video_related_links( int $legacy_id, array $video = [] ): array {
	if ( $legacy_id <= 0 ) {
		return [];
	}
 
	$cache_key = 'f365_video_links_' . $legacy_id;
	$cached    = wp_cache_get( $cache_key, 'f365' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
 
	global $wpdb;
	$P = $wpdb->prefix;
	$out = [];
 
	// competition_id / match_id live only in the custom table — fetch them when
	// the caller passes just the legacy id (related_links is the one allowed
	if ( ! isset( $video['competition_id'] ) || ! isset( $video['match_id'] ) ) {
		$T_V = $P . 'multimedia_video';
		if ( function_exists( 'f365_src_table_exists' ) && f365_src_table_exists( $T_V ) ) {
			$vrow = $wpdb->get_row( $wpdb->prepare(
				"SELECT competition_id, match_id FROM {$T_V} WHERE id = %d LIMIT 1",
				$legacy_id
			), ARRAY_A );
			$video['competition_id'] = isset( $vrow['competition_id'] ) ? (int) $vrow['competition_id'] : 0;
			$video['match_id']       = isset( $vrow['match_id'] ) ? (int) $vrow['match_id'] : 0;
		}
	}
 
	// Competition → /competicao/{federation_competition_id}/  (source uses fed_comp id).
	if ( ! empty( $video['competition_id'] ) ) {
		$T_COMP = $P . 'competition_competition';
		if ( f365_src_table_exists( $T_COMP ) ) {
			$comp = $wpdb->get_row( $wpdb->prepare(
				"SELECT name, federation_competition_id, edition FROM {$T_COMP} WHERE id = %d LIMIT 1",
				(int) $video['competition_id']
			), ARRAY_A );
			if ( is_array( $comp ) ) {
				$fed     = (int) ( $comp['federation_competition_id'] ?? 0 );
				$edition = (string) ( $comp['edition'] ?? '' );
				// Source: /competicao/{fed_comp_id}/?edition={edition}
				$comp_url = '';
				if ( $fed > 0 ) {
					$comp_url = home_url( '/competicao/' . $fed . '/' );
					if ( $edition !== '' ) {
						$comp_url = add_query_arg( 'edition', $edition, $comp_url );
					}
				}
				$out[] = [
					'title' => (string) ( $comp['name'] ?? '' ),
					'class' => 'competition-icon',
					'url'   => $comp_url,
				];
			}
		}
	}
 
	// Match → "Ficha do jogo" → /jogo/{match_id}/  (only if it has matchplayers).
	if ( ! empty( $video['match_id'] ) ) {
		$T_MP = $P . 'competition_matchplayer';
		$has  = f365_src_table_exists( $T_MP ) ? (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT EXISTS( SELECT 1 FROM {$T_MP} WHERE match_id = %d )",
			(int) $video['match_id']
		) ) : 0;
		if ( $has ) {
			$out[] = [
				'title' => __( 'Ficha do jogo', 'futebol365' ),
				'class' => 'match-icon',
				'url'   => home_url( '/jogo/' . (int) $video['match_id'] . '/' ),
			];
		}
	}
 
	// Teams / players / coaches / presidents / referees via pivots.
	$relations = [
		[ 'multimedia_video_teams',      'team_id',      'team_team',       'shortname', 'team-icon',      '/equipa/%d/jogos/' ],
		[ 'multimedia_video_players',    'player_id',    'person_player',   '',          'player-icon',    '/jogador/%d/' ],
		[ 'multimedia_video_coaches',    'coach_id',     'person_coach',    '',          'coach-icon',     '/treinador/%d/' ],
		[ 'multimedia_video_presidents', 'president_id', 'person_president','',          'president-icon', '/presidente/%d/' ],
		[ 'multimedia_video_referees',   'referee_id',   'person_referee',  '',          'referee-icon',   '/arbitro/%d/' ],
	];
 
	foreach ( $relations as $rel ) {
		list( $pivot, $fk, $entity_tbl, $name_col, $css, $url_fmt ) = $rel;
		$T_PIVOT = $P . $pivot;
		if ( ! f365_src_table_exists( $T_PIVOT ) ) {
			continue;
		}
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT {$fk} FROM {$T_PIVOT} WHERE video_id = %d",
			$legacy_id
		) );
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		if ( empty( $ids ) ) {
			continue;
		}
 
		// Resolve display names in one batched query (person entities → person_person).
		$names = f365_video_entity_names( $ids, $entity_tbl, $name_col );
 
		foreach ( $ids as $eid ) {
			$out[] = [
				'title' => $names[ $eid ] ?? '',
				'class' => $css,
				'url'   => home_url( sprintf( $url_fmt, $eid ) ),
			];
		}
	}
 
	wp_cache_set( $cache_key, $out, 'f365', 6 * HOUR_IN_SECONDS );
	return $out;
}
 
 
/**
 * Batched display-name resolver for a set of entity ids.
 * Team uses team_team.shortname; person entities (player/coach/president/referee)
 * resolve through their table → person_person.shortname.
 *
 * @return array<int,string>  entity_id => name
 */
function f365_video_entity_names( array $ids, string $entity_table, string $name_col ): array {
	$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
	if ( empty( $ids ) ) {
		return [];
	}
 
	global $wpdb;
	$T = $wpdb->prefix . $entity_table;
	if ( ! f365_src_table_exists( $T ) ) {
		return [];
	}
	$in_ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
 
	if ( $name_col !== '' ) {
		// Direct name column on the entity table (e.g. team_team.shortname).
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, {$name_col} AS nm FROM {$T} WHERE id IN ($in_ph)",
			$ids
		), ARRAY_A );
	} else {
		// Person-backed entity: entity.person_id → person_person.shortname.
		$T_PERS = $wpdb->prefix . 'person_person';
		if ( ! f365_src_table_exists( $T_PERS ) ) {
			return [];
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT e.id AS id, p.shortname AS nm
			   FROM {$T} e
			   JOIN {$T_PERS} p ON p.id = e.person_id
			  WHERE e.id IN ($in_ph)",
			$ids
		), ARRAY_A );
	}
 
	$map = [];
	if ( is_array( $rows ) ) {
		foreach ( $rows as $r ) {
			$map[ (int) $r['id'] ] = (string) ( $r['nm'] ?? '' );
		}
	}
	return $map;
}
 
 

if ( ! function_exists( 'f365_module_image_url' ) ) {
 
	/**
	 * @param string $module     'news' | 'video' (aliases below also accepted).
	 * @param int    $legacy_id  Source-table id.
	 * @return string Image URL, or '' to signal "use your fallback".
	 */
	function f365_module_image_url( string $module, int $legacy_id ): string {
		if ( $legacy_id <= 0 ) {
			return '';
		}
 
		// module → [ source table (no prefix), media subdir for bare filenames ]
		static $map = [
			'news'             => [ 'publish_news',     '' ],
			'publish_news'     => [ 'publish_news',     '' ],
			'video'            => [ 'multimedia_video', 'images/videos' ],
			'videos'           => [ 'multimedia_video', 'images/videos' ],
			'multimedia_video' => [ 'multimedia_video', 'images/videos' ],
		];
 
		$key = strtolower( trim( $module ) );
		if ( ! isset( $map[ $key ] ) ) {
			return '';
		}
		list( $table, $subdir ) = $map[ $key ];
 
		// Per-request memo so repeated calls for the same row don't re-query.
		static $memo = [];
		$memo_key = $key . ':' . $legacy_id;
		if ( array_key_exists( $memo_key, $memo ) ) {
			return $memo[ $memo_key ];
		}
 
		global $wpdb;
		$T = $wpdb->prefix . $table;
		if ( ! function_exists( 'f365_src_table_exists' ) || ! f365_src_table_exists( $T ) ) {
			return $memo[ $memo_key ] = '';
		}
 
		$image = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT image FROM {$T} WHERE id = %d LIMIT 1",
			$legacy_id
		) );
 
		$url = ( $image !== '' && function_exists( 'f365_listing_media_url' ) )
			? f365_listing_media_url( $image, $subdir )
			: '';
 
		return $memo[ $memo_key ] = $url;
	}
}


if ( ! function_exists( 'f365_news_categories' ) ) {
	function f365_news_categories( int $legacy_news_id ): array {
		static $memo = [];
		if ( array_key_exists( $legacy_news_id, $memo ) ) {
			return $memo[ $legacy_news_id ];
		}
		if ( $legacy_news_id <= 0 ) {
			return $memo[ $legacy_news_id ] = [];
		}
 
		global $wpdb;
		$T_NCAT = $wpdb->prefix . 'publish_news_categories';
		$T_CAT  = $wpdb->prefix . 'publish_newscategory';
		if ( ! function_exists( 'f365_src_table_exists' )
			|| ! f365_src_table_exists( $T_NCAT )
			|| ! f365_src_table_exists( $T_CAT ) ) {
			return $memo[ $legacy_news_id ] = [];
		}
 
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.name, c.slug
			   FROM {$T_NCAT} nc
			   JOIN {$T_CAT} c ON c.id = nc.newscategory_id
			  WHERE nc.news_id = %d
			  ORDER BY nc.newscategory_id ASC",
			$legacy_news_id
		), ARRAY_A );
 
		$out = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$out[] = [
					'id'   => (int) $r['id'],
					'name' => (string) ( $r['name'] ?? '' ),
					'slug' => (string) ( $r['slug'] ?? '' ),
				];
			}
		}
 
		return $memo[ $legacy_news_id ] = $out;
	}
}
 
 

if ( ! function_exists( 'f365_news_category_name' ) ) {
	function f365_news_category_name( int $legacy_news_id ): string {
		$cats = f365_news_categories( $legacy_news_id );
		return ! empty( $cats ) ? $cats[0]['name'] : '';
	}
}