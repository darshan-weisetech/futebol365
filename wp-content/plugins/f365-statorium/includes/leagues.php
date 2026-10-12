<?php
/**
 * League → Federation mapping.
 *
 * Each Statorium league must land under the right f365_federation.
 *
 * Flow:
 *  - f365_statorium_league_config(): the intended leagues, matched by
 *    normalized-name patterns, federation referenced by post TITLE (resolved
 *    at runtime — portable across environments).
 *  - f365_statorium_map_leagues(): fetches /leagues/ and auto-assigns each
 *    API league to a config row; result persisted in option
 *    `f365_statorium_league_map` as [league_id => {name, federation_wp}].
 *    Ambiguous/unknown leagues are stored unmapped for manual assignment on
 *    the settings screen.
 *  - The importer reads the map in import_season() and links the competition
 *    to its federation.
 *
 * @package F365_Statorium
 */

defined( 'ABSPATH' ) || exit;

const F365_STAT_LEAGUE_MAP_OPTION = 'f365_statorium_league_map';

/**
 * Intended league set (from the Statorium delivery sheet).
 * patterns   — normalized substrings; most-specific (longest) match wins.
 * federation — f365_federation post title.
 */
function f365_statorium_league_config(): array {
	return apply_filters( 'f365_statorium_league_config', [
		'brazil-serie-a' => [
			'legacy_name' => 'Brasileirão',
			'scope'      => 'national_championship',
			'label'      => 'BRAZIL: Série A',
			'patterns'   => [ 'brazil serie a', 'serie a brazil', 'brasileirao', 'campeonato brasileiro' ],
			'federation' => 'Brasil',
		],
		'premier-league' => [
			'legacy_name' => 'Premier League',
			'scope'      => 'national_championship',
			'label'      => 'ENGLAND: Premier League',
			'patterns'   => [ 'premier league' ],
			'federation' => 'Inglaterra',
		],
		'ligue-1' => [
			'legacy_name' => 'Ligue 1',
			'scope'      => 'national_championship',
			'label'      => 'FRANCE: Ligue 1',
			'patterns'   => [ 'ligue 1' ],
			'federation' => 'França',
		],
		'bundesliga' => [
			'legacy_name' => 'Bundesliga',
			'scope'      => 'national_championship',
			'label'      => 'GERMANY: Bundesliga',
			'patterns'   => [ 'bundesliga' ],
			'federation' => 'Alemanha',
		],
		'italy-serie-a' => [
			'legacy_name' => 'Serie A',
			'scope'      => 'national_championship',
			'label'      => 'ITALY: Serie A',
			'patterns'   => [ 'serie a' ],
			'federation' => 'Itália',
		],
		'primeira-liga' => [
			'legacy_name' => 'Liga Portugal Betclic',
			'scope'      => 'national_championship',
			'label'      => 'PORTUGAL: Primeira Liga',
			'patterns'   => [ 'primeira liga', 'liga portugal betclic', 'liga portugal' ],
			'federation' => 'Portugal',
		],
		'segunda-divisao' => [
			'legacy_name' => 'Segunda Liga',
			'scope'      => 'national_championship',
			'label'      => 'PORTUGAL: Segunda Divisão',
			'patterns'   => [ 'segunda divisao', 'segunda liga', 'liga portugal 2' ],
			'federation' => 'Portugal',
		],
		'la-liga' => [
			'legacy_name' => 'La Liga',
			'scope'      => 'national_championship',
			'label'      => 'SPAIN: La Liga',
			'patterns'   => [ 'la liga', 'laliga' ],
			'federation' => 'Espanha',
		],
		'champions-league' => [
			'legacy_name' => 'Liga dos Campeões',
			'scope'      => 'international_clubs',
			'label'      => 'UEFA Champions League',
			'patterns'   => [ 'champions league' ],
			'federation' => 'UEFA',
		],
		'europa-league' => [
			// Delivered instead of/alongside the Conference League originally
			// on the sheet — Statorium's actual league 7 is "UEFA Europa
			// League" (2nd tier), a DIFFERENT competition from the
			// Conference League (3rd tier) below. Legacy has both as
			// separate rows (Liga Europa fedcomp 27 vs Liga Conferência
			// Europa fedcomp 1390) — do not conflate them.
			'legacy_name' => 'Liga Europa',
			'scope'      => 'international_clubs',
			'label'      => 'UEFA Europa League',
			'patterns'   => [ 'europa league' ],
			'federation' => 'UEFA',
		],
		'conference-league' => [
			'legacy_name' => 'Liga Conferência Europa',
			'scope'      => 'international_clubs',
			'label'      => 'UEFA Europa Conference League',
			'patterns'   => [ 'conference league' ],
			'federation' => 'UEFA',
		],
		// Already-subscribed international leagues.
		'world-cup' => [
			'legacy_name' => 'Mundial',
			'scope'      => 'international_national_team',
			'label'      => 'FIFA World Cup',
			'patterns'   => [ 'fifa world cup' ],
			'federation' => 'FIFA',
		],
		'world-cup-qualification' => [
			'legacy_name' => 'Qualificação Mundial',
			'scope'      => 'international_national_team',
			'label'      => 'FIFA World Cup Qualification',
			'patterns'   => [ 'fifa world cup qualification', 'world cup qualification' ],
			'federation' => 'FIFA',
		],
		'friendlies' => [
			'legacy_name' => 'Amigáveis de seleções',
			'scope'      => 'international_national_team',
			'label'      => 'International Friendlies',
			'patterns'   => [ 'international friendlies' ],
			'federation' => 'FIFA',
		],
	] );
}

/** Persisted map: [statorium_league_id => name/federation_wp]. */
function f365_statorium_league_map(): array {
	$map = get_option( F365_STAT_LEAGUE_MAP_OPTION, [] );
	return is_array( $map ) ? $map : [];
}

/** Resolve a federation post ID by exact title. */
function f365_statorium_federation_by_title( string $title ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts}
		  WHERE post_type = 'f365_federation' AND post_status = 'publish' AND post_title = %s
		  ORDER BY ID ASC LIMIT 1",
		$title
	) );
}

/**
 * Fetch /leagues/ and (re)build the league map. Manual assignments
 * (federation_wp set by hand in admin) are preserved.
 * Returns the map.
 */
function f365_statorium_map_leagues( F365_Statorium_Importer $importer ): array {
	$data = $importer->api()->leagues();
	if ( is_wp_error( $data ) ) {
		$importer->log( 'FAIL leagues map — ' . $data->get_error_message() );
		return f365_statorium_league_map();
	}

	$config   = f365_statorium_league_config();
	$existing = f365_statorium_league_map();
	$map      = [];
	$claimed  = []; // config key → league id, to detect two leagues hitting one row

	foreach ( (array) ( $data['leagues'] ?? [] ) as $l ) {
		$league_id = (int) ( $l['id'] ?? 0 );
		$name      = (string) ( $l['name'] ?? '' );
		if ( ! $league_id || '' === $name ) {
			continue;
		}

		// Manual assignment survives re-mapping (scope/legacy_name are config-owned though).
		if ( ! empty( $existing[ $league_id ]['manual'] ) ) {
			$map[ $league_id ]         = $existing[ $league_id ];
			$map[ $league_id ]['name'] = $name;
			$ck = (string) ( $existing[ $league_id ]['config_key'] ?? '' );
			$map[ $league_id ]['scope']       = (string) ( $config[ $ck ]['scope'] ?? ( $existing[ $league_id ]['scope'] ?? 'national_championship' ) );
			$map[ $league_id ]['legacy_name'] = (string) ( $config[ $ck ]['legacy_name'] ?? ( $existing[ $league_id ]['legacy_name'] ?? '' ) );
			continue;
		}

		$norm = F365_Statorium_Matcher::normalize( $name );

		// Most-specific (longest) pattern wins.
		$best_key = '';
		$best_len = 0;
		foreach ( $config as $key => $row ) {
			foreach ( $row['patterns'] as $pattern ) {
				if ( false !== strpos( $norm, $pattern ) && strlen( $pattern ) > $best_len ) {
					$best_key = $key;
					$best_len = strlen( $pattern );
				}
			}
		}

		$entry = [
			'name'          => $name,
			'config_key'    => $best_key,
			'federation_wp' => 0,
			'scope'         => 'national_championship',
			'legacy_name'   => '',
			'manual'        => 0,
		];

		if ( $best_key ) {
			if ( isset( $claimed[ $best_key ] ) ) {
				// Two API leagues matched the same config row → ambiguous,
				// leave THIS one unmapped for manual assignment.
				$importer->log( "LEAGUE ambiguous: '{$name}' (id {$league_id}) also matches '{$best_key}' — assign manually" );
				$entry['config_key'] = '';
			} else {
				$claimed[ $best_key ]   = $league_id;
				$row                    = $config[ $best_key ];
				$entry['scope']         = (string) ( $row['scope'] ?? 'national_championship' );
				$entry['legacy_name']   = (string) ( $row['legacy_name'] ?? '' );
				$entry['federation_wp'] = f365_statorium_federation_by_title( (string) $row['federation'] );
				$importer->log( "LEAGUE mapped: '{$name}' (id {$league_id}) → {$row['federation']} (wp_id {$entry['federation_wp']})" );
			}
		} else {
			$importer->log( "LEAGUE unmapped: '{$name}' (id {$league_id}) — assign a federation on the settings screen" );
		}

		$map[ $league_id ] = $entry;
	}

	update_option( F365_STAT_LEAGUE_MAP_OPTION, $map, false );
	return $map;
}

/**
 * League entry for a given statorium league id ([] when unknown).
 */
function f365_statorium_league_entry( int $league_id ): array {
	$map = f365_statorium_league_map();
	return isset( $map[ $league_id ] ) && is_array( $map[ $league_id ] ) ? $map[ $league_id ] : [];
}

/**
 * Self-heals the league map the moment it's needed, rather than depending on
 * `leagues-map` having been run manually first (or on sync/onboarding having
 * had a first pass). f365_statorium_onboard_new_leagues() already builds the
 * map when it sees a league id it doesn't recognize — but that function only
 * runs as part of a full `sync` cycle. import_season() is also reachable
 * directly (`wp f365-statorium season --season=X`, or via import_teams()/
 * import_matches() on a season that was never routed through onboarding),
 * and on a fresh Scenario-B install (legacy data, no Statorium data yet) that
 * path used to hit an empty map, silently fail every legacy-competition
 * match, and create a duplicate for every league — not a one-off, since the
 * failure mode is identical for every league on the key. Called from
 * import_season() before it attempts to link.
 *
 * A league that never appears in /leagues/ at all (out of this key's
 * catalog entirely — e.g. a season whose leagueID belongs to a league this
 * key was never given access to) would otherwise trigger a full /leagues/
 * refetch on EVERY single call for as long as it keeps appearing in
 * /updates/ — observed live: one poll cycle re-triggered this for the same
 * unknown league id repeatedly. A short transient remembers "just checked,
 * still absent" so it isn't hammered every call, while still re-checking
 * periodically in case the league gets added to the key later.
 */
function f365_statorium_ensure_league_map( F365_Statorium_Importer $importer, int $league_id ): void {
	if ( ! $league_id ) {
		return;
	}
	$map = f365_statorium_league_map();
	if ( isset( $map[ $league_id ] ) ) {
		return; // already known — mapped or explicitly left unmapped by a prior run
	}
	$recently_checked_key = 'f365stat_league_map_miss_' . $league_id;
	if ( get_transient( $recently_checked_key ) ) {
		return; // confirmed absent within the last hour — don't hammer /leagues/
	}
	$importer->log( "LEAGUE MAP auto-build triggered — league {$league_id} not yet known (first use of the map on this install, or a newly-appeared league)" );
	f365_statorium_map_leagues( $importer );

	if ( ! isset( f365_statorium_league_map()[ $league_id ] ) ) {
		set_transient( $recently_checked_key, 1, HOUR_IN_SECONDS );
	}
}

/**
 * Auto-onboarding: detect leagues that appeared on the API key since the last
 * run, map them to federations, and import their CURRENT season (competition
 * + teams + fixtures). Called from every `sync` cycle, so newly activated
 * leagues flow in without manual steps. Historical seasons stay on demand
 * (`wp f365-statorium all --season=X`).
 *
 * Returns the newly onboarded league ids.
 */
function f365_statorium_onboard_new_leagues( F365_Statorium_Importer $importer ): array {
	$data = $importer->api()->leagues();
	if ( is_wp_error( $data ) ) {
		return [];
	}

	$known    = f365_statorium_league_map();
	$api_ids  = [];
	$new_ids  = [];
	foreach ( (array) ( $data['leagues'] ?? [] ) as $l ) {
		$id = (int) ( $l['id'] ?? 0 );
		if ( ! $id ) {
			continue;
		}
		$api_ids[] = $id;
		if ( ! isset( $known[ $id ] ) ) {
			$new_ids[] = $id;
		}
	}

	if ( $new_ids ) {
		// Refresh the map first so the new leagues get federation + flags.
		f365_statorium_map_leagues( $importer );
	}

	// Every subscribed league (new AND known): if its CURRENT season is not
	// imported yet, import it. Covers both league activation and the annual
	// season rollover (e.g. Premier League 2026/27 → 2027/28) — /updates/
	// only refreshes seasons that are already mapped, so without this the
	// rollover would need a manual `season` import.
	$onboarded = [];
	foreach ( $api_ids as $league_id ) {
		$detail  = $importer->api()->get( "/leagues/{$league_id}/" );
		$seasons = is_wp_error( $detail ) ? [] : (array) ( $detail['league']['seasons'] ?? [] );
		if ( ! $seasons ) {
			continue;
		}

		// Current season = the last entry in the league's season list.
		$current   = end( $seasons );
		$season_id = (int) ( $current['seasonID'] ?? 0 );
		if ( ! $season_id || $importer->map_resolve( 'season', $season_id ) ) {
			continue; // already imported/linked
		}

		$kind = in_array( $league_id, $new_ids, true ) ? 'ONBOARD new league' : 'ROLLOVER known league';
		$importer->log( "{$kind} {$league_id} — importing current season {$season_id} ({$current['seasonName']})" );
		$importer->import_season( $season_id );
		$importer->remember_season( $season_id );
		$importer->import_matches( $season_id ); // creates teams + fixtures; squads follow via /updates/
		$onboarded[] = "{$league_id}:{$season_id}";
	}

	return $onboarded;
}
