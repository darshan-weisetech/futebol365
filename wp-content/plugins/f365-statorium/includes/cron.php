<?php
/**
 * Cron wiring — configurable main-sync interval + fixed live ticker.
 *
 *  f365_statorium_sync : at the interval chosen in settings (30 min … daily).
 *      backfill mode → processes the next batch of days
 *      delta mode    → polls /updates/ and applies changes
 *  f365_statorium_tick : every 2 minutes, only does work while a synced match
 *      is inside its live window (cheap DB gate, no API call otherwise).
 *
 * @package F365_Statorium
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'cron_schedules', function ( array $schedules ): array {
	$schedules['f365stat_30min'] = [ 'interval' => 30 * MINUTE_IN_SECONDS, 'display' => 'Every 30 minutes (Statorium)' ];
	$schedules['f365stat_2h']    = [ 'interval' => 2 * HOUR_IN_SECONDS, 'display' => 'Every 2 hours (Statorium)' ];
	$schedules['f365stat_6h']    = [ 'interval' => 6 * HOUR_IN_SECONDS, 'display' => 'Every 6 hours (Statorium)' ];
	$schedules['f365stat_2min']  = [ 'interval' => 2 * MINUTE_IN_SECONDS, 'display' => 'Every 2 minutes (Statorium live ticker)' ];
	return $schedules;
} );

/**
 * The matcher is blind without the `_f365_norm_name` index — every import
 * then CREATES instead of linking (mass duplicates). If the one-time
 * `index-names` step was skipped on this environment, build it now.
 * Cheap existence probe; the build itself runs once.
 */
function f365_statorium_ensure_name_index(): void {
	global $wpdb;
	// The probe must find an index row on a LEGACY post — created posts
	// self-index on insert, so "any row exists" hides the real failure mode:
	// a partial index (433 created rows, zero legacy) that leaves the
	// matcher blind to all legacy content and duplicates every import.
	$has_legacy_row = $wpdb->get_var(
		"SELECT m.post_id FROM {$wpdb->postmeta} m
		   JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'f365_player'
		  WHERE m.meta_key = '" . F365_Statorium_Matcher::NORM_KEY . "'
		    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} s
		                      WHERE s.post_id = p.ID AND s.meta_key = '_f365_source' )
		  LIMIT 1"
	);
	if ( ! $has_legacy_row ) {
		$n = F365_Statorium_Matcher::index_names();
		( new F365_Statorium_Importer() )->log( "INDEX auto-built: legacy posts had no name-index rows on this environment — {$n} rows added (matcher would have created duplicates for everything)." );
	}
}

/**
 * Auto-detected setup checklist: figures out Scenario A (DB already contains
 * Statorium data — clone of dev) vs B (old production DB, fresh setup) and
 * reports every deployment step as done/pending with a Run button where the
 * step can be executed from here.
 *
 * Lives here (not admin.php) because it must be callable from cron/WP-CLI
 * context too — admin.php is only loaded when is_admin(), which WP-Cron and
 * WP-CLI requests are not.
 */
function f365_statorium_setup_checklist(): array {
	global $wpdb;

	$seasons    = array_filter( array_map( 'intval', explode( ',', (string) F365_Statorium_Importer::settings()['seasons'] ) ) );
	$stat_posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE api_source = 'statorium'" );
	$scenario   = $stat_posts > 50 ? 'A' : 'B';

	$theme_file = get_template_directory() . '/template-parts/competition/f365-competition-classificacao.php';
	$theme_fix  = file_exists( $theme_file ) && false !== strpos( (string) file_get_contents( $theme_file ), 'grp_names' );

	$countries = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = 'statorium_country_id'" );

	$norm_key   = F365_Statorium_Matcher::NORM_KEY;
	$idx_total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", $norm_key ) );
	$idx_posts  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s", $norm_key ) );
	$idx_legacy = (bool) $wpdb->get_var( $wpdb->prepare(
		"SELECT m.post_id FROM {$wpdb->postmeta} m
		   JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'f365_player'
		  WHERE m.meta_key = %s AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id = p.ID AND s.meta_key = '_f365_source' ) LIMIT 1",
		$norm_key
	) );
	$index_ok   = $idx_legacy && $idx_total > $idx_posts;

	$league_map  = (array) get_option( 'f365_statorium_league_map', [] );
	$leagues_ok  = (bool) array_filter( $league_map, fn( $e ) => ! empty( $e['federation_wp'] ) );

	// A season can be unresolvable forever on this API key (its league isn't
	// in the subscription and never will be, e.g. a World Cup edition on a
	// club-leagues-only key) — those must not count against "pending", or
	// the checklist nags with a "Run now" that will only repeat the same
	// skip. season_unsubscribed_league() re-checks live against the CURRENT
	// league map, so a season stops being excluded the moment its league
	// actually gets subscribed.
	$seasons_linked  = true;
	$seasons_blocked = [];
	foreach ( $seasons as $sid ) {
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE api_source = 'statorium' AND api_id = %d AND post_type = 'f365_competition'", $sid ) ) ) {
			continue;
		}
		$blocked = F365_Statorium_Importer::season_unsubscribed_league( $sid );
		if ( $blocked ) {
			$seasons_blocked[ $sid ] = $blocked;
		} else {
			$seasons_linked = false;
		}
	}

	$teams   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE api_source = 'statorium' AND post_type = 'f365_team'" );
	$matches = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE api_source = 'statorium' AND post_type = 'f365_match'" );

	$details_left = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} p
		   JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = 'statorium_status_id' AND st.meta_value = '1'
		  WHERE p.api_source = 'statorium' AND p.api_id IS NOT NULL AND p.post_type = 'f365_match'
		    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} d WHERE d.post_id = p.ID AND d.meta_key = 'statorium_detail_synced' )"
	);

	// Same selection criteria as heal_team_profiles()/heal_player_profiles()
	// themselves (including the _statorium_profile_checked throttle) — a
	// team/player already checked within the window and still sparse (the
	// API genuinely had nothing, e.g. a youth-squad venue) must NOT count as
	// "pending" here, or this repeats the exact misleading-forever-pending
	// problem already fixed for unsubscribed seasons.
	$profile_cutoff    = time() - 7 * DAY_IN_SECONDS;
	$team_profiles_left = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts} p
		   JOIN {$wpdb->prefix}team_team t ON t.id = p.legacy_id
		  WHERE p.post_type = 'f365_team' AND p.post_status = 'publish'
		    AND p.api_source = 'statorium' AND p.api_id IS NOT NULL
		    AND ( t.city IS NULL OR t.city = ''
		          OR ( t.team_type = 'club' AND ( t.stadium_id IS NULL OR t.stadium_id = 0
		                                       OR t.country_id IS NULL OR t.country_id = 0
		                                       OR t.federation_id IS NULL OR t.federation_id = 0 ) ) )
		    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} ck
		                      WHERE ck.post_id = p.ID AND ck.meta_key = '_statorium_profile_checked'
		                        AND CAST( ck.meta_value AS UNSIGNED ) > %d )",
		$profile_cutoff
	) );
	$player_profiles_left = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts} p
		   LEFT JOIN {$wpdb->prefix}person_player pl ON pl.id = p.legacy_id
		   LEFT JOIN {$wpdb->prefix}person_person pr ON pr.id = pl.person_id
		  WHERE p.post_type = 'f365_player' AND p.post_status = 'publish'
		    AND p.api_source = 'statorium' AND p.api_id IS NOT NULL
		    AND ( pr.id IS NULL OR pr.birth_date IS NULL OR CAST( pr.birth_date AS CHAR ) = '0000-00-00'
		          OR pl.height IS NULL OR pl.height = 0 OR pl.weight IS NULL OR pl.weight = 0
		          OR pr.nationality_id IS NULL OR pr.nationality_id = 0 )
		    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} ck
		                      WHERE ck.post_id = p.ID AND ck.meta_key = '_statorium_profile_checked'
		                        AND CAST( ck.meta_value AS UNSIGNED ) > %d )",
		$profile_cutoff
	) );

	$ttable        = $wpdb->prefix . 'transfer_transfer';
	$has_tcol      = in_array( 'api_source', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$ttable}", 0 ), true );
	$transfer_rows = $has_tcol ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ttable} WHERE api_source = 'statorium'" ) : 0;

	$state       = F365_Statorium_Backfill::state();
	$last_log    = glob( trailingslashit( wp_upload_dir()['basedir'] ) . 'f365-logs/f365-statorium-*.log' ) ?: [];
	$last_mtime  = $last_log ? max( array_map( 'filemtime', $last_log ) ) : 0;
	$cron_active = $last_mtime > time() - 3 * HOUR_IN_SECONDS;

	// /jogos-em-direto/ coverage (live_livematch row + f365_livegames post +
	// event feed) — built from mirror data already imported, no API calls.
	// New imports get it automatically via heal_live_details() every sync;
	// this only counts matches that predate that (first-time historical
	// backfill, previously the CLI-only `live-backfill` subcommand).
	$live_left = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} p
		  WHERE p.post_type = 'f365_match' AND p.post_status = 'publish'
		    AND p.api_source = 'statorium' AND p.legacy_id IS NOT NULL AND p.legacy_id > 0
		    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->prefix}live_livematch lm WHERE lm.match_id = p.legacy_id )"
	);

	// Flag attachment for terms map_countries() tagged but that never got one
	// (almost always a brand-new term — legacy terms already carry a flag
	// from the original Django migration). Theme ships flags/{iso2}.svg for
	// every ISO-3166 code, so this is resolvable without a human picking a
	// file, unlike the general "no flag" case.
	$flags_left = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->termmeta} c
		  WHERE c.meta_key = 'statorium_country_code' AND c.meta_value != ''
		    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->termmeta} f
		                      WHERE f.term_id = c.term_id AND f.meta_key = 'country_flag' AND f.meta_value != '' )"
	);

	// Players discovered ONLY through a match lineup — sync_match_detail()
	// writes full mirror data (person_player/person_person) and
	// competition_matchplayer references for every squad entry regardless
	// of whether a WP post exists, falling back to a bare deterministic pub
	// when none does. Unlike coaches, a player has its own /jogador/{pub}/
	// page, so this leaves real data with nothing to render it.
	$orphan_players_left = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->prefix}person_player pl
		  WHERE pl.id >= 90000000
		    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->posts} p WHERE p.legacy_id = pl.id AND p.post_type = 'f365_player' )"
	);

	$media_ok = null;
	$pub      = (int) $wpdb->get_var( "SELECT legacy_id FROM {$wpdb->posts} WHERE api_source = 'statorium' AND post_type = 'f365_team' AND legacy_id >= 90000000 LIMIT 1" );
	if ( $pub ) {
		$media_ok = file_exists( trailingslashit( ABSPATH ) . 'media/images/logotipo/100x100/' . $pub . '.gif' );
	}

	// Same criteria as heal_stadiums() — a stadium already retried within
	// the window and still empty must not count as pending (API genuinely
	// has nothing for it), same reasoning as the player/team profile rows.
	$stadiums_left = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts} p
		   JOIN {$wpdb->prefix}stadium_stadium s ON s.id = p.legacy_id
		  WHERE p.post_type = 'f365_stadium' AND p.post_status = 'publish'
		    AND p.api_source = 'statorium' AND p.api_id IS NOT NULL
		    AND ( s.capacity IS NULL OR s.capacity = 0 OR s.address IS NULL OR s.address = '' )
		    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} ck
		                      WHERE ck.post_id = p.ID AND ck.meta_key = 'statorium_venue_synced'
		                        AND ck.meta_value > %s )",
		gmdate( 'c', $profile_cutoff )
	) );

	// Curated GPS-ambiguous venue pairs (see resolve_known_stadium_merges()
	// docblock) — "left" means the created post still exists on THIS
	// environment (api_source='statorium' under that pair's stat_id), i.e.
	// resolve_known_stadium_merges() hasn't run here yet or hasn't reached
	// it. Keeps the checklist honest across a fresh deploy without
	// duplicating the pair list.
	$known_stadium_ids  = array_keys( F365_Statorium_Importer::known_stadium_merge_pairs() );
	// api_source/api_id alone isn't enough — map_set() (inside merge_stadium())
	// repoints BOTH onto the LEGACY post once a pair is resolved, so that
	// column pair matches a resolved pair too. `_f365_source = 'statorium'`
	// is the actual "we created this post" marker (same one auto_merge_
	// duplicate_stadiums() itself uses) — only a still-unmerged created post
	// carries it.
	$known_venues_left = $known_stadium_ids ? (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts} p
		   JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_f365_source' AND s.meta_value = 'statorium'
		  WHERE p.post_type = 'f365_stadium' AND p.api_source = 'statorium'
		    AND p.api_id IN (" . implode( ',', array_fill( 0, count( $known_stadium_ids ), '%d' ) ) . ')',
		$known_stadium_ids
	) ) : 0;

	// Same 7-day throttle geocode_missing_stadium_coordinates() itself uses —
	// without it, a stadium OSM genuinely has no data for would show as
	// perpetually "not done" and make f365_statorium_complete_pending_setup()'s
	// stabilization loop re-attempt this step on every single pass forever.
	// `s.id < ID_OFFSET` mirrors that method's own explicit legacy-only
	// guard — Statorium-created rows are never this step's concern.
	$geocode_cutoff       = gmdate( 'c', time() - 7 * DAY_IN_SECONDS );
	$stadium_coords_left  = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}stadium_stadium s
		   LEFT JOIN {$wpdb->postmeta} dsi ON dsi.meta_key = 'django_stadium_id' AND dsi.meta_value = s.id
		   LEFT JOIN {$wpdb->postmeta} dji ON dji.meta_key = 'django_id' AND dji.meta_value = s.id
		   LEFT JOIN {$wpdb->posts} p ON p.ID = COALESCE( dsi.post_id, dji.post_id ) AND p.post_type = 'f365_stadium'
		  WHERE s.id < " . F365_Statorium_Importer::ID_OFFSET . "
		    AND ( s.latitude IS NULL OR s.latitude = '' )
		    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} ck
		                      WHERE ck.post_id = p.ID AND ck.meta_key = '_statorium_geocode_checked'
		                        AND ck.meta_value > %s )",
		$geocode_cutoff
	) );

	$path = untrailingslashit( ABSPATH );
	$cli  = fn( string $c ): string => "wp f365-statorium {$c} --path=\"{$path}\"";

	// Suggesting a season known-blocked on this API key (e.g. a World Cup
	// edition on a club-leagues-only key) as the example --season= value
	// just sends whoever copy-pastes it straight into the same dead end for
	// every step that follows (teams, matches, all). Pick the first
	// configured season that isn't already known-unsubscribed instead.
	$example_season = null;
	foreach ( $seasons as $sid ) {
		if ( ! F365_Statorium_Importer::season_unsubscribed_league( $sid ) ) {
			$example_season = $sid;
			break;
		}
	}
	$season_arg = $example_season ? ' --season=' . $example_season : ( $seasons ? ' --season=' . $seasons[0] : '' );

	$items = [
		[ 'Code deployed (plugin + theme MySQL 8 fix)', $theme_fix, $theme_fix ? 'grp_names fix found in the active theme' : 'Theme is missing the MySQL 8 fix in f365-competition-classificacao.php — group tables will render empty', '', false, 'copy plugin folders + theme (no command)' ],
		[ 'API key in wp-config.php', '' !== F365_Statorium_Importer::api_key(), defined( 'F365_STATORIUM_API_KEY' ) ? 'F365_STATORIUM_API_KEY defined' : 'Define F365_STATORIUM_API_KEY (or set in settings below)', '', false, "define( 'F365_STATORIUM_API_KEY', '…' ); in wp-config.php" ],
		[ 'Schema migrations (v' . F365_Statorium_Importer::DB_VERSION . ')', get_option( 'f365_statorium_db_version' ) === F365_Statorium_Importer::DB_VERSION, 'Current: v' . get_option( 'f365_statorium_db_version', '—' ), 'migrations', false, $cli( 'status' ) . '  (migrations auto-run on any command)' ],
		[ 'Countries mapped', $countries >= 240, "{$countries} terms carry a Statorium country id (expect ~250, skipped=0)", 'countries', false, $cli( 'countries' ) ],
		[ 'Matcher name index (with alt names)', $index_ok, number_format( $idx_total ) . ' rows — without this every import CREATES duplicates', 'index', false, $cli( 'index-names' ) ],
		[ 'Leagues → federations map', $leagues_ok, $leagues_ok ? count( $league_map ) . ' league(s) in map' : 'Fetch leagues, then assign federations in the table below', 'leagues', false, $cli( 'leagues-map' ) ],
		[
			'Season(s) linked/imported (' . count( $seasons ) . ' configured)',
			$seasons_linked,
			( $seasons_linked
				? ( $seasons_blocked
					? count( $seasons_blocked ) . " season(s) not available on this API key's subscription (won't block sync)"
					: 'All configured seasons resolve to a competition' )
				: 'Import creates or auto-links to the legacy competition' ) . ' — see Statorium Data → Seasons for the per-id breakdown',
			'season', false, $cli( 'season' . $season_arg ),
		],
		[ 'Teams + squads', $teams > 0, "{$teams} teams mapped (squads + photos — takes minutes)", 'teams', true, $cli( 'teams' . $season_arg ) ],
		[ 'Matches', $matches > 0, "{$matches} matches mapped", 'matches', true, $cli( 'matches' . $season_arg ) ],
		[
			'Match details (lineups/events/stats)',
			$matches > 0 && 0 === $details_left,
			0 === $matches
				? 'No matches imported yet — run the Matches step first'
				: ( $details_left ? "{$details_left} finished match(es) missing details" : 'All finished matches have details' ),
			'details', true, $cli( 'details' ),
		],
		[ 'Transfers', $transfer_rows > 0, "{$transfer_rows} transfer rows imported (auto via sync afterwards)", 'transfers', false, $cli( 'transfers' ) ],
		[
			'Orphaned player mirrors recovered',
			0 === $orphan_players_left,
			$orphan_players_left
				? "{$orphan_players_left} player(s) have match data but no page"
				: 'Every player mirror row has a matching page',
			'orphan-players', true, $cli( 'repair-players --all' ),
		],
		[
			'Team profiles enriched (city/stadium)',
			0 === $team_profiles_left,
			$team_profiles_left
				? "{$team_profiles_left} team(s) still need enrichment"
				: 'All team profiles enriched (or the API genuinely has nothing more to give — e.g. youth/reserve squads)',
			'heal-teams', true, $cli( 'heal-teams --all' ),
		],
		[
			'Player profiles enriched (birthdate/height/weight)',
			0 === $player_profiles_left,
			$player_profiles_left
				? "{$player_profiles_left} player(s) still need enrichment"
				: 'All player profiles enriched (or the API genuinely has nothing more to give)',
			'heal-players', true, $cli( 'heal-players --all' ),
		],
		[
			'Stadium profiles enriched (capacity/address)',
			0 === $stadiums_left,
			$stadiums_left
				? "{$stadiums_left} stadium(s) still missing capacity/address"
				: 'All stadium profiles enriched (or the API genuinely has nothing more to give)',
			'heal-stadiums', true, $cli( 'heal-stadiums --all' ),
		],
		[
			'Known venue duplicates resolved',
			0 === $known_venues_left,
			$known_venues_left
				? "{$known_venues_left} of " . count( $known_stadium_ids ) . ' curated GPS-ambiguous pair(s) still unresolved on this environment (rival-club or demolished/rebuilt stadiums the coordinate sweep alone correctly won\'t auto-pick)'
				: 'All curated GPS-ambiguous venue pairs resolved (or none apply — none of those stadiums touched yet)',
			'known-venues', false, $cli( 'sync' ) . '  (runs automatically every sync — this row is just visibility)',
		],
		[
			'Legacy stadium coordinates geocoded',
			0 === $stadium_coords_left,
			$stadium_coords_left
				? "{$stadium_coords_left} legacy stadium(s) still missing coordinates — one-time Nominatim backfill, takes a minute or two the first time it runs"
				: 'Every legacy stadium has coordinates (or genuinely can\'t be geocoded — e.g. an obscure venue with no OpenStreetMap data)',
			'geocode-venues', true, $cli( 'sync' ) . '  (runs automatically the first time, then never again — this row is just visibility)',
		],
		[
			'Country flags attached',
			0 === $flags_left,
			$flags_left ? "{$flags_left} country term(s) missing a flag — attached from the theme's ISO-2 SVG set" : 'Every mapped country term has a flag',
			'country-flags', false, $cli( 'countries' ) . '  (flags backfill runs as part of the countries step)',
		],
		[
			'Live coverage backfilled (/jogos-em-direto/)',
			$matches > 0 && 0 === $live_left,
			0 === $matches
				? 'No matches imported yet — run the Matches step first'
				: ( $live_left ? "{$live_left} match(es) missing live coverage rows — backfilled from mirror data, no API calls" : 'All matches have live coverage' ),
			'live-backfill', true, $cli( 'live-backfill' ),
		],
		[ 'System cron running', $cron_active, $cron_active ? 'Import log written in the last 3 h' : 'No recent log activity — schedule the sync/tick commands (table below)', '', false, $cli( 'sync' ) . '  (hourly)  ·  ' . $cli( 'tick' ) . '  (every 2 min)' ],
	];

	if ( 'A' === $scenario ) {
		$items[] = [
			'Media files copied (Scenario A)',
			$media_ok,
			true === $media_ok
				? 'Sample created-team logo found in /media/'
				: 'Missing on disk — auto-backfilled from the API on the next sync (re-fetches team logos + venue photos; skips files that already exist)',
			'media', true,
			$cli( 'teams' . $season_arg . ' --no-players' ) . ' + ' . $cli( 'venues' ) . '  (or copy /media/images/{logotipo,stadium}/ from dev)',
		];
	}

	return [ $scenario, $stat_posts, $items ];
}

/**
 * Executes one setup-checklist (or manual-action) step by its key and returns
 * a human-readable status message. Shared by the Settings page "Run now" /
 * "Run backfill batch" / "Poll /updates/" buttons (admin.php) and by
 * f365_statorium_complete_pending_setup() below.
 */
function f365_statorium_run_checklist_step( string $what ): string {
	if ( 'backfill' === $what ) {
		$summary = ( new F365_Statorium_Backfill() )->run_days( 14, true );
		return 'Backfill: ' . wp_json_encode( $summary );
	}
	if ( 'poll' === $what ) {
		$summary = ( new F365_Statorium_Updates() )->poll();
		return 'Updates: ' . wp_json_encode( $summary );
	}
	if ( 'index' === $what ) {
		$n = F365_Statorium_Matcher::index_names();
		return "{$n} post(s) indexed.";
	}
	if ( 'leagues' === $what ) {
		$map = f365_statorium_map_leagues( new F365_Statorium_Importer() );
		return count( $map ) . ' league(s) mapped from the API.';
	}
	if ( 'migrations' === $what ) {
		f365_statorium_activate();
		return 'Migrations run — schema at v' . get_option( 'f365_statorium_db_version' ) . ', settings persisted, cron rescheduled.';
	}
	if ( 'countries' === $what ) {
		[ $m, $c, $s ] = ( new F365_Statorium_Importer() )->map_countries();
		return "Countries: {$m} mapped, {$c} created, " . count( $s ) . ' skipped.';
	}
	if ( 'known-venues' === $what ) {
		$n = ( new F365_Statorium_Importer() )->resolve_known_stadium_merges();
		return "{$n} known GPS-ambiguous venue pair(s) resolved.";
	}
	if ( 'geocode-venues' === $what ) {
		$n = ( new F365_Statorium_Importer() )->geocode_missing_stadium_coordinates();
		return "{$n} legacy stadium(s) geocoded via Nominatim.";
	}
	if ( 'season' === $what ) {
		$imp = new F365_Statorium_Importer();
		$ok  = [];
		foreach ( array_filter( array_map( 'intval', explode( ',', (string) F365_Statorium_Importer::settings()['seasons'] ) ) ) as $sid ) {
			$wp_id = $imp->import_season( $sid );
			$ok[]  = "{$sid}→" . ( $wp_id ?: 'FAIL' );
		}
		return 'Season import: ' . implode( ', ', $ok );
	}
	if ( 'teams' === $what ) {
		$imp = new F365_Statorium_Importer();
		$n   = 0;
		foreach ( array_filter( array_map( 'intval', explode( ',', (string) F365_Statorium_Importer::settings()['seasons'] ) ) ) as $sid ) {
			$n += count( $imp->import_teams( $sid, true ) );
		}
		return "Teams (with squads/photos): {$n} processed. " . wp_json_encode( $imp->stats );
	}
	if ( 'matches' === $what ) {
		$imp = new F365_Statorium_Importer();
		$n   = 0;
		foreach ( array_filter( array_map( 'intval', explode( ',', (string) F365_Statorium_Importer::settings()['seasons'] ) ) ) as $sid ) {
			$n += (int) $imp->import_matches( $sid );
		}
		return "Matches: {$n} upserted. " . wp_json_encode( $imp->stats );
	}
	if ( 'details' === $what ) {
		// No batch cap — the API has no rate limit worth throttling for, so
		// every finished match missing details is synced in this one call.
		global $wpdb;
		$imp  = new F365_Statorium_Importer();
		$rows = $wpdb->get_col(
			"SELECT p.api_id FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = 'statorium_status_id' AND st.meta_value = '1'
			  WHERE p.api_source = 'statorium' AND p.api_id IS NOT NULL AND p.post_type = 'f365_match'
			    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} d WHERE d.post_id = p.ID AND d.meta_key = 'statorium_detail_synced' )"
		);
		$done = 0;
		foreach ( $rows as $sid ) {
			if ( $imp->sync_match_detail( (int) $sid ) ) {
				$done++;
			}
			usleep( 250000 );
		}
		return "Match details: {$done} synced, all done.";
	}
	if ( 'transfers' === $what ) {
		[ $t_in, $t_skip ] = ( new F365_Statorium_Importer() )->import_transfers();
		return "Transfers: {$t_in} imported, {$t_skip} skipped.";
	}
	if ( 'orphan-players' === $what ) {
		$n = ( new F365_Statorium_Importer() )->repair_orphaned_player_mirrors();
		return "Orphaned player mirrors: {$n} recovered this batch — click again if the row still shows pending.";
	}
	if ( 'heal-teams' === $what ) {
		$n = ( new F365_Statorium_Importer() )->heal_team_profiles();
		return "Team profiles: {$n} enriched this batch — click again if the row still shows pending.";
	}
	if ( 'heal-players' === $what ) {
		$n = ( new F365_Statorium_Importer() )->heal_player_profiles();
		return "Player profiles: {$n} enriched this batch — click again if the row still shows pending.";
	}
	if ( 'heal-stadiums' === $what ) {
		$n = ( new F365_Statorium_Importer() )->heal_stadiums();
		return "Stadium profiles: {$n} enriched this batch — click again if the row still shows pending.";
	}
	if ( 'country-flags' === $what ) {
		$n = ( new F365_Statorium_Importer() )->backfill_country_flags();
		return "Country flags: {$n} attached from the theme's ISO-2 SVG set.";
	}
	if ( 'odds' === $what ) {
		[ $o_up ] = ( new F365_Statorium_Importer() )->sync_odds();
		return "Odds updated for {$o_up} match(es).";
	}
	if ( 'media' === $what ) {
		// Scenario A: the DB was cloned from an environment that already ran
		// this import, but the /media/images/{logotipo,stadium}/ files
		// themselves (plain filesystem, not WP attachments) didn't travel
		// with it. Re-running the same team/venue import save_media_image()
		// already calls is the auto-fixable equivalent of the manual "copy
		// /media/ from dev" step — it's a no-op download for any file that
		// already exists (file_exists() guard) and only writes the ones
		// genuinely missing on this filesystem.
		global $wpdb;
		$imp = new F365_Statorium_Importer();
		$t_n = 0;
		foreach ( array_filter( array_map( 'intval', explode( ',', (string) F365_Statorium_Importer::settings()['seasons'] ) ) ) as $sid ) {
			$t_n += count( $imp->import_teams( $sid, false ) );
		}
		$ids = $wpdb->get_col( "SELECT api_id FROM {$wpdb->posts} WHERE api_source = 'statorium' AND post_type = 'f365_stadium'" );
		$v_n = 0;
		foreach ( $ids as $sid ) {
			delete_post_meta( (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE api_source = 'statorium' AND api_id = %d AND post_type = 'f365_stadium'", (int) $sid
			) ), 'statorium_venue_synced' );
			if ( $imp->enrich_venue( (int) $sid ) ) {
				$v_n++;
			}
			usleep( 250000 );
		}
		return "Media backfill: {$t_n} team(s) processed, {$v_n}/" . count( $ids ) . ' venue(s) re-enriched (missing logo/photo files written, existing ones left untouched).';
	}
	if ( 'live-backfill' === $what ) {
		// Same get-or-create logic as the CLI `live-backfill` subcommand —
		// ensure_livegame()/fill_livegame_from_mirror() read only mirror
		// tables already populated by prior imports, no API calls, so this
		// is cheap and safe to run unconditionally on every match.
		global $wpdb;
		$imp        = new F365_Statorium_Importer();
		$match_ids  = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE api_source = 'statorium' AND post_type = 'f365_match' AND post_status = 'publish'" );
		$made = 0;
		foreach ( $match_ids as $mid ) {
			$pub = $imp->ensure_livegame( (int) $mid );
			if ( ! $pub ) {
				continue;
			}
			$live_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}live_livematch WHERE match_id = %d", $pub ) );
			if ( $live_id ) {
				$imp->fill_livegame_from_mirror( $live_id, $pub );
			}
			$made++;
		}
		return "Live coverage ensured for {$made}/" . count( $match_ids ) . ' match(es) (rows + posts + event feeds, from mirror data).';
	}

	return 'Nothing to do.';
}

/**
 * Runs every still-pending setup-checklist step (in checklist order) before
 * the normal sync cycle, so first-time setup (Scenario B) and any regression
 * self-complete instead of requiring manual "Run now" clicks on the Settings
 * page. Steps with no runnable key (code deploy, API key in wp-config.php,
 * the system cron entry itself, Scenario A media copy) need a human and are
 * skipped. Each step is idempotent/re-run-safe — same code as the manual
 * button — so re-checking every cycle is cheap once setup is actually done
 * (every item reads $done = true and the loop is a no-op).
 *
 * No per-step batch cap: details/heal-teams/heal-players/heal-stadiums/
 * orphan-players each drain their ENTIRE backlog in one call (the API has no
 * rate limit worth throttling for). But a single pass over one checklist
 * snapshot still isn't enough on its own: running an early step can make a
 * LATER (or even earlier) item newly pending as a side effect — e.g. 'season'
 * creating a competition makes 'teams'/'matches' pending for it, and 'teams'
 * creating a club can make 'heal-teams' pending again for it. A snapshot
 * taken before any step ran can't see that, so it silently rolled over to
 * "complete next sync" instead of finishing in this one. Fixed by re-fetching
 * the checklist and re-scanning in full passes until a pass runs nothing —
 * i.e. the site has actually reached a stable, fully-caught-up state, not
 * just "every step got tried once."
 */
function f365_statorium_complete_pending_setup( F365_Statorium_Importer $importer ): int {
	if ( '' === F365_Statorium_Importer::api_key() ) {
		return 0; // nothing below can call the API anyway
	}

	$ran = 0;
	for ( $pass = 1; $pass <= 10; $pass++ ) {
		[ , , $checklist ] = f365_statorium_setup_checklist();
		$pass_ran = 0;
		foreach ( $checklist as [ $label, $done, $detail, $what, $heavy, $cmd ] ) {
			if ( $done || ! $what ) {
				continue;
			}

			$msg = f365_statorium_run_checklist_step( $what );
			$importer->log( "SETUP pending step '{$label}' (pass {$pass}) → {$msg}" );
			$pass_ran++;
		}
		$ran += $pass_ran;
		if ( 0 === $pass_ran ) {
			break; // a full pass completed nothing — genuinely stable now
		}
	}
	return $ran;
}

function f365_statorium_reschedule_cron(): void {
	$settings = F365_Statorium_Importer::settings();
	$enabled  = ! empty( $settings['cron'] );
	$interval = (string) $settings['cron_interval'];

	wp_clear_scheduled_hook( 'f365_statorium_sync' );
	wp_clear_scheduled_hook( 'f365_statorium_tick' );

	if ( ! $enabled ) {
		return;
	}

	$valid = [ 'f365stat_30min', 'hourly', 'f365stat_2h', 'f365stat_6h', 'twicedaily', 'daily' ];
	if ( ! in_array( $interval, $valid, true ) ) {
		$interval = 'hourly';
	}

	wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, 'f365_statorium_sync' );
	wp_schedule_event( time() + 2 * MINUTE_IN_SECONDS, 'f365stat_2min', 'f365_statorium_tick' );
}

/**
 * Main sync: backfill batch OR delta poll, depending on the state machine.
 */
add_action( 'f365_statorium_sync', function () {
	if ( '' === F365_Statorium_Importer::api_key() ) {
		return;
	}
	// Unlimited, not 900s — the batched steps now drain their entire backlog
	// in one pass (no per-cycle cap), which can legitimately run past 15
	// minutes on a large backlog; this is the WP-Cron entry point (a real
	// web request under the hood), so PHP's execution-time limit still
	// applies here even though the CLI `sync` command is naturally exempt.
	@set_time_limit( 0 );

	$importer = new F365_Statorium_Importer();

	// Same term-counting deferral as the CLI: a delta cycle can touch dozens
	// of matches, and every term assignment otherwise pays a COUNT(*) join
	// over the full posts table.
	wp_defer_term_counting( true );
	register_shutdown_function( static function () {
		wp_defer_term_counting( false );
	} );

	$setup_ran = f365_statorium_complete_pending_setup( $importer );
	if ( $setup_ran ) {
		$importer->log( "SETUP completed {$setup_ran} pending checklist step(s) before this sync cycle." );
	}

	f365_statorium_ensure_name_index();
	f365_statorium_onboard_new_leagues( $importer );
	$importer->auto_merge_duplicate_teams();
	$importer->auto_merge_duplicate_players();
	$importer->auto_merge_duplicate_stadiums();
	$importer->resolve_known_stadium_merges(); // curated GPS-ambiguous venue pairs — see docblock
	$importer->repair_duplicate_teamsteps(); // fold varchar(4)-truncation teamstep dupes
	$importer->repair_team_types();          // created clubs mislabeled 'national' → club
	$importer->repair_team_country_federation(); // league-derived club country/federation (gap-fill)
	$importer->repair_player_mirror_profiles();  // postmeta height/weight → person mirror (gap-fill)
	$importer->repair_participant_groups();      // group_phase_one backfill from match data (gap-fill)
	$importer->repair_missing_participants();    // competition_participant backfill from match data — Game Summary fix (2026-08-06)
	$importer->repair_teamstep_coaches();        // Equipa Técnica entities from existing tmp_coach data
	$importer->repair_stadium_country();         // f365_country term + mirror country_id from address
	$importer->repair_match_competition_django_id(); // fedcomp id (was the match's own id) — self-heals via ON DUPLICATE too
	$importer->repair_orphaned_fedcomps(); // fold a created league entity into its real legacy fedcomp once matchable
	$importer->rescore_reviews(); // drain any queued items unattended
	$importer->finalize_competitions(); // close finished knockout seasons (Winners page)
	$importer->finalize_league_competitions(); // close finished LEAGUE seasons (Winners page)
	$importer->finalize_topscorer_rankings(); // derive Melhor Marcador rows at season close
	$importer->heal_live_details();     // fill live Events/Ficha/Estatísticas from mirror
	$importer->heal_player_profiles();  // enrich sparse transfers-feed players (country/DOB)
	$importer->heal_team_profiles();    // enrich sparse transfers-feed clubs (city/venue)
	$importer->heal_stadiums();         // retry stadiums stuck with empty capacity/address

	$state = F365_Statorium_Backfill::state();
	if ( 'backfill' === $state['mode'] ) {
		( new F365_Statorium_Backfill( $importer ) )->run_days( 14, true );
	} else {
		( new F365_Statorium_Updates( $importer ) )->poll();
	}

	// Fold freshly-imported fixtures into pre-loaded legacy skeletons — the
	// day-cursor paths (backfill/today) create rows without pairing.
	$importer->pair_legacy_fixtures();

	// Must run AFTER backfill/delta and pair_legacy_fixtures(), not before —
	// the fixture-dedup fold (repair_duplicate_fixtures(), called from both
	// import_matches() during backfill/delta AND from pair_legacy_fixtures()
	// itself) is what CREATES these orphans in the first place. Running the
	// cleanup earlier in the cycle only ever catches the PREVIOUS cycle's
	// mess, leaving whatever this run just orphaned to sit until the next
	// one — a single completed sync should clean up fully after itself.
	$importer->repair_orphaned_match_duplicates(); // fixture-dedup leftovers with no mirror row of their own
	$importer->repair_orphaned_created_teams(); // fixture-dedup leftovers: a team that lost its only match reference
	$importer->repair_orphaned_player_mirrors(); // match-lineup-only players: mirror data + matchplayer refs but no WP post

	// Every cycle regardless of mode — the transfers feed is global and
	// deduped, and odds only touch matches in the betting window. Gating
	// them on delta mode left backfill-mode environments without transfers.
	$importer->import_transfers();
	$importer->sync_odds();
} );

/**
 * Live ticker — only calls the API while a tracked match is in play.
 */
add_action( 'f365_statorium_tick', function () {
	if ( '' === F365_Statorium_Importer::api_key() ) {
		return;
	}
	if ( 'delta' !== F365_Statorium_Backfill::state()['mode'] ) {
		return;
	}

	global $wpdb;
	$due = $wpdb->get_var(
		"SELECT COUNT(*)
		   FROM {$wpdb->posts} p
		   JOIN {$wpdb->postmeta} dt ON dt.post_id = p.ID AND dt.meta_key = 'date_time'
		  WHERE p.api_source = 'statorium' AND p.post_type = 'f365_match'
		    AND dt.meta_value BETWEEN DATE_SUB( UTC_TIMESTAMP(), INTERVAL 4 HOUR ) AND DATE_ADD( UTC_TIMESTAMP(), INTERVAL 15 MINUTE )"
	);
	if ( ! $due ) {
		return;
	}

	( new F365_Statorium_Importer() )->sync_live();
} );
