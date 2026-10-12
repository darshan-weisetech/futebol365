<?php
/**
 * WP-CLI: wp f365-statorium <subcommand>
 *
 *   status                                  state machine + map counts
 *   leagues                                 list leagues + seasons on the key
 *   index-names                             build the normalized-name index
 *   match-report --season=X [--players]     dry-run: link/create/review counts
 *   season|teams|matches|all --season=X     season-scoped imports
 *   backfill [--from=Y-m-d] [--days=N] [--details]
 *   today [--date=Y-m-d] [--details]        fetch one day's matches (default today)
 *   sync                                    system-cron entry: backfill batch / delta poll
 *   tick                                    system-cron entry: live scores (gated, cheap)
 *   poll                                    one /updates/ delta cycle
 *   live                                    one live-score tick (forced, no gate)
 *   review                                  list pending review rows
 *   resolve --id=N (--wp-id=M | --create | --ignore)
 *   touched                                 legacy posts linked by the sync
 *   teamsteps [--dry-run]                   fold duplicate teamstep rows
 *   rankings [--dry-run]                    derive Melhor Marcador rows for completed seasons
 *   rollback --yes                          delete created posts, unlink legacy
 *
 * @package F365_Statorium
 */

defined( 'ABSPATH' ) || exit;

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

class F365_Statorium_Command {

	public function __invoke( array $args, array $assoc ): void {
		$sub      = $args[0] ?? 'status';
		$season   = (int) ( $assoc['season'] ?? 0 );
		$importer = new F365_Statorium_Importer( $assoc['api-key'] ?? null );

		// Bulk imports set taxonomy terms per match/player; with live term
		// counting each assignment runs a COUNT(*) join over wp_posts ×
		// wp_term_relationships (millions of rows here — observed minutes
		// per match). Defer during the command and recount once at the end.
		wp_defer_term_counting( true );
		register_shutdown_function( static function () {
			wp_defer_term_counting( false );
		} );

		switch ( $sub ) {
			case 'status':
				$settings = F365_Statorium_Importer::settings();
				$state    = F365_Statorium_Backfill::state();
				WP_CLI::log( 'API key:   ' . ( F365_Statorium_Importer::api_key() ? substr( F365_Statorium_Importer::api_key(), 0, 6 ) . '… (' . ( defined( 'F365_STATORIUM_API_KEY' ) ? 'wp-config' : 'settings' ) . ')' : '(not set)' ) );
				WP_CLI::log( 'Mode:      ' . $state['mode'] . ( 'backfill' === $state['mode'] ? " (cursor {$state['cursor']})" : ' (last_update_ts ' . $state['last_update_ts'] . ')' ) );
				WP_CLI::log( 'Seasons:   ' . ( $settings['seasons'] ?: '(none)' ) );
				WP_CLI::log( 'Cron:      ' . ( $settings['cron'] ? 'enabled, ' . $settings['cron_interval'] : 'disabled' ) );
				WP_CLI::log( 'Policy:    ' . $settings['update_mode'] );
				global $wpdb;
				foreach ( [ 'f365_competition' => 'seasons', 'f365_team' => 'teams', 'f365_player' => 'players', 'f365_match' => 'matches', 'f365_stadium' => 'venues' ] as $pt => $label ) {
					$n = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE api_source='statorium' AND post_type=%s", $pt ) );
					WP_CLI::log( sprintf( '%-10s mapped: %d', $label, (int) $n ) );
				}

				// URL-health guards — these being non-zero caused the numeric-URL
				// bugs twice; surface loudly so a regression is caught same-day.
				$bad_slugs = (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$wpdb->posts} p
					   JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_f365_source' AND s.meta_value = 'statorium'
					  WHERE p.post_type = 'f365_player' AND p.post_status = 'publish' AND p.post_name NOT REGEXP '^[0-9]+$'"
				);
				$pm_uris = 0;
				$uris    = get_option( 'permalink-manager-uris', [] );
				if ( is_array( $uris ) && $uris ) {
					$pm_uris = (int) $wpdb->get_var(
						"SELECT COUNT(*) FROM {$wpdb->posts}
						  WHERE post_type IN ('f365_competition','f365_team','f365_player','f365_match','f365_stadium')
						    AND ID IN (" . implode( ',', array_map( 'intval', array_keys( $uris ) ) ) . ')'
					);
				}
				if ( $bad_slugs || $pm_uris ) {
					WP_CLI::warning( "URL HEALTH: {$bad_slugs} created player(s) with non-numeric slug, {$pm_uris} Permalink-Manager URI(s) on entity CPTs — numeric URLs are at risk. Update the plugin / re-run activation (migration purges URIs) and check the guide." );
				} else {
					WP_CLI::log( 'URL health: OK (numeric slugs, 0 Permalink Manager URIs on entity CPTs)' );
				}

				// Matcher-health: without the name index EVERY import creates
				// duplicates instead of linking. Alt-name rows (mirror
				// longnames) mean the index is the current, richer build.
				$idx_total = (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '" . F365_Statorium_Matcher::NORM_KEY . "'"
				);
				$idx_posts = (int) $wpdb->get_var(
					"SELECT COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m WHERE m.meta_key = '" . F365_Statorium_Matcher::NORM_KEY . "'"
				);
				if ( ! $idx_total ) {
					WP_CLI::warning( 'MATCHER HEALTH: name index is EMPTY — every import will CREATE duplicates. Run: wp f365-statorium index-names (sync also auto-builds it now).' );
				} elseif ( $idx_total <= $idx_posts ) {
					WP_CLI::warning( "MATCHER HEALTH: name index has no alternative-name rows ({$idx_total} rows) — built by an old plugin version; official API names will not match legacy shortnames. Re-run: wp f365-statorium index-names." );
				} else {
					WP_CLI::log( "Matcher health: OK (name index {$idx_total} rows incl. alt names)" );
				}
				$pending = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . F365_Statorium_Matcher::review_table() . " WHERE status='pending'" );
				WP_CLI::log( 'Review queue pending: ' . (int) $pending );
				break;

			case 'leagues':
				$data = $importer->api()->leagues();
				if ( is_wp_error( $data ) ) {
					WP_CLI::error( $data->get_error_message() );
				}
				foreach ( (array) ( $data['leagues'] ?? [] ) as $l ) {
					WP_CLI::log( sprintf( '%5d  %s', $l['id'], $l['name'] ) );
					$detail = $importer->api()->get( '/leagues/' . (int) $l['id'] . '/' );
					foreach ( (array) ( $detail['league']['seasons'] ?? [] ) as $s ) {
						WP_CLI::log( sprintf( '       season %-6d %s', $s['seasonID'], $s['seasonName'] ) );
					}
				}
				break;

			case 'index-names':
				$n = F365_Statorium_Matcher::index_names();
				WP_CLI::success( "{$n} post(s) indexed with normalized names." );
				break;

			case 'leagues-map':
				$map = f365_statorium_map_leagues( $importer );
				foreach ( $map as $league_id => $entry ) {
					WP_CLI::log( sprintf(
						'%6d  %-40s → %s%s',
						$league_id,
						$entry['name'],
						$entry['federation_wp'] ? get_the_title( (int) $entry['federation_wp'] ) . ' (#' . $entry['federation_wp'] . ')' : 'UNMAPPED',
						! empty( $entry['manual'] ) ? ' [manual]' : ''
					) );
				}
				WP_CLI::success( count( $map ) . ' league(s) in map. Unmapped ones can be assigned on the settings screen.' );
				break;

			case 'match-report':
				$this->need_season( $season );
				$importer->dry_run = true;
				$importer->import_teams( $season, isset( $assoc['players'] ) );
				WP_CLI::success( 'DRY RUN — nothing written. ' . wp_json_encode( $importer->stats ) . ' (would_create = new records; linked/review need no creation)' );
				break;

			case 'season':
				$this->need_season( $season );
				$wp_id = $importer->import_season( $season );
				$wp_id ? WP_CLI::success( "Season {$season} → competition wp_id={$wp_id}" ) : WP_CLI::error( 'Import failed — see log.' );
				break;

			case 'teams':
				$this->need_season( $season );
				$map = $importer->import_teams( $season, (bool) ( $assoc['players'] ?? true ) );
				WP_CLI::success( count( $map ) . ' teams processed. ' . wp_json_encode( $importer->stats ) );
				break;

			case 'matches':
				$this->need_season( $season );
				$n = $importer->import_matches( $season );
				WP_CLI::success( "{$n} matches upserted. " . wp_json_encode( $importer->stats ) );
				break;

			case 'all':
				$this->need_season( $season );
				$importer->import_season( $season );
				$importer->import_teams( $season, (bool) ( $assoc['players'] ?? true ) );
				$importer->import_matches( $season );
				WP_CLI::success( 'Full sync done. ' . wp_json_encode( $importer->stats ) );
				break;

			case 'backfill':
				if ( ! empty( $assoc['from'] ) ) {
					F365_Statorium_Backfill::set_cursor( (string) $assoc['from'] );
				}
				$days    = (int) ( $assoc['days'] ?? 7 );
				$summary = ( new F365_Statorium_Backfill( $importer ) )->run_days( $days, isset( $assoc['details'] ) );

				$this->maybe_merge_into( $importer, $assoc, $season );

				WP_CLI::success( 'Backfill: ' . wp_json_encode( $summary ) . ' ' . wp_json_encode( $importer->stats ) );
				break;

			case 'today':
				// Fetch today's matches only (or --date=Y-m-d). Cursor untouched.
				$n = ( new F365_Statorium_Backfill( $importer ) )->run_date( (string) ( $assoc['date'] ?? '' ), isset( $assoc['details'] ) );
				$this->maybe_merge_into( $importer, $assoc, $season );
				WP_CLI::success( "{$n} match(es) processed for " . ( $assoc['date'] ?? gmdate( 'Y-m-d' ) ) . '. ' . wp_json_encode( $importer->stats ) );
				break;

			case 'sync':
				// System-cron entry point: backfill batch OR delta poll,
				// depending on the state machine. Schedule hourly (or as needed).
				$setup_ran = f365_statorium_complete_pending_setup( $importer );
				if ( $setup_ran ) {
					WP_CLI::log( "Completed {$setup_ran} pending setup-checklist step(s) before this sync cycle." );
				}
				f365_statorium_ensure_name_index();
				$onboarded = f365_statorium_onboard_new_leagues( $importer );
				if ( $onboarded ) {
					WP_CLI::log( 'Onboarded new league(s): ' . implode( ', ', $onboarded ) );
				}
				[ $auto_merged ] = $importer->auto_merge_duplicate_teams();
				if ( $auto_merged ) {
					WP_CLI::log( 'Auto-merged ' . count( $auto_merged ) . ' duplicate team(s).' );
				}
				[ $pmerged, $pamb ] = $importer->auto_merge_duplicate_players();
				if ( $pmerged || $pamb ) {
					WP_CLI::log( 'Auto-merged ' . count( $pmerged ) . " duplicate player(s); {$pamb} ambiguous left for merge-player." );
				}
				[ $smerged, $samb ] = $importer->auto_merge_duplicate_stadiums();
				if ( $smerged || $samb ) {
					WP_CLI::log( 'Auto-merged ' . count( $smerged ) . " duplicate stadium(s); {$samb} ambiguous." );
				}
				$known_venues = $importer->resolve_known_stadium_merges();
				if ( $known_venues ) {
					WP_CLI::log( "Resolved {$known_venues} known GPS-ambiguous venue pair(s)." );
				}
				// Same repair sweeps as the WP-Cron f365_statorium_sync action
				// (cron.php) — this CLI path is the one production system-cron
				// actually calls (CLAUDE.md: cron setting ships off), so these
				// must run from here too or they never execute at all.
				$dupsteps = $importer->repair_duplicate_teamsteps();
				if ( $dupsteps ) {
					WP_CLI::log( "Folded {$dupsteps} duplicate teamstep(s) (edition-truncation)." );
				}
				$types = $importer->repair_team_types();
				if ( $types ) {
					WP_CLI::log( "Corrected {$types} club team(s) mislabeled 'national'." );
				}
				$cfed = $importer->repair_team_country_federation();
				if ( $cfed ) {
					WP_CLI::log( "Resolved {$cfed} club team country/federation gap(s)." );
				}
				$pmirror = $importer->repair_player_mirror_profiles();
				if ( $pmirror ) {
					WP_CLI::log( "Gap-filled {$pmirror} player mirror profile(s) (height/weight)." );
				}
				$groups = $importer->repair_participant_groups();
				if ( $groups ) {
					WP_CLI::log( "Backfilled {$groups} participant group_phase_one value(s)." );
				}
				$coaches = $importer->repair_teamstep_coaches();
				if ( $coaches ) {
					WP_CLI::log( "Linked {$coaches} teamstep coach(es) from existing tmp_coach data." );
				}
				$stad_country = $importer->repair_stadium_country();
				if ( $stad_country ) {
					WP_CLI::log( "Resolved {$stad_country} stadium country term(s) from address data." );
				}
				$fedcomp = $importer->repair_match_competition_django_id();
				if ( $fedcomp ) {
					WP_CLI::log( "Corrected {$fedcomp} match competition_django_id value(s)." );
				}
				$fedcomps_folded = $importer->repair_orphaned_fedcomps();
				if ( $fedcomps_folded ) {
					WP_CLI::log( "Folded {$fedcomps_folded} orphaned fedcomp(s) into their real legacy league entity." );
				}
				[ $rescored ] = $importer->rescore_reviews();
				if ( $rescored ) {
					WP_CLI::log( "Auto-resolved {$rescored} queued review item(s)." );
				}
				$finalized = $importer->finalize_competitions();
				if ( $finalized ) {
					WP_CLI::log( "Closed {$finalized} finished knockout season(s) — Winners page populated." );
				}
				$finalized_leagues = $importer->finalize_league_competitions();
				if ( $finalized_leagues ) {
					WP_CLI::log( "Closed {$finalized_leagues} finished league season(s) — Winners page populated." );
				}
				$rankings = $importer->finalize_topscorer_rankings();
				if ( $rankings ) {
					WP_CLI::log( "Derived {$rankings} topscorer ranking row(s)." );
				}
				$healed = $importer->heal_live_details();
				if ( $healed ) {
					WP_CLI::log( "Filled {$healed} live game(s) with Events/Ficha/Estatísticas from mirror data." );
				}
				$enriched = $importer->heal_player_profiles();
				if ( $enriched ) {
					WP_CLI::log( "Enriched {$enriched} sparse player profile(s) (country/birthdate from /players/)." );
				}
				$t_enriched = $importer->heal_team_profiles();
				if ( $t_enriched ) {
					WP_CLI::log( "Enriched {$t_enriched} sparse team profile(s) (city/venue from /teams/)." );
				}
				$s_enriched = $importer->heal_stadiums();
				if ( $s_enriched ) {
					WP_CLI::log( "Enriched {$s_enriched} stadium(s) previously stuck with empty capacity/address." );
				}
				// Transfers + odds run EVERY cycle regardless of mode — the
				// transfers feed is global/deduped; gating them on delta mode
				// left backfill-mode environments without transfer imports.
				$state = F365_Statorium_Backfill::state();
				if ( 'backfill' === $state['mode'] ) {
					$summary = ( new F365_Statorium_Backfill( $importer ) )->run_days( 14, true );
					$folded  = $importer->pair_legacy_fixtures();
					if ( $folded ) {
						WP_CLI::log( "Folded {$folded} imported fixture(s) into pre-loaded legacy rows (no duplicates left)." );
					}
					// Must run AFTER backfill/delta and pair_legacy_fixtures(), not
					// before — the fixture-dedup fold is what CREATES these orphans;
					// cleaning up earlier in the cycle only ever catches the PREVIOUS
					// run's mess, leaving whatever THIS run just orphaned to sit
					// until the next one.
					$orphans = $importer->repair_orphaned_match_duplicates();
					if ( $orphans ) {
						WP_CLI::log( "Deleted {$orphans} orphaned match duplicate(s) (fixture-dedup leftovers with no mirror row)." );
					}
					$orphan_teams = $importer->repair_orphaned_created_teams();
					if ( $orphan_teams ) {
						WP_CLI::log( "Deleted {$orphan_teams} orphaned created team(s) (fixture-dedup leftovers with no match/participant/squad reference)." );
					}
					$orphan_players = $importer->repair_orphaned_player_mirrors();
					if ( $orphan_players ) {
						WP_CLI::log( "Recovered {$orphan_players} orphaned player mirror row(s) (match-lineup-only players with no WP post)." );
					}
					[ $t_in, $t_skip ] = $importer->import_transfers();
					[ $o_up ]          = $importer->sync_odds();
					WP_CLI::success( 'Sync (backfill): ' . wp_json_encode( $summary ) . ' ' . wp_json_encode( $importer->stats ) . " Transfers: {$t_in} imported, {$t_skip} skipped. Odds: {$o_up} match(es)." );
				} else {
					$summary = ( new F365_Statorium_Updates( $importer ) )->poll();
					$folded  = $importer->pair_legacy_fixtures();
					if ( $folded ) {
						WP_CLI::log( "Folded {$folded} imported fixture(s) into pre-loaded legacy rows (no duplicates left)." );
					}
					// Must run AFTER backfill/delta and pair_legacy_fixtures(), not
					// before — the fixture-dedup fold is what CREATES these orphans;
					// cleaning up earlier in the cycle only ever catches the PREVIOUS
					// run's mess, leaving whatever THIS run just orphaned to sit
					// until the next one.
					$orphans = $importer->repair_orphaned_match_duplicates();
					if ( $orphans ) {
						WP_CLI::log( "Deleted {$orphans} orphaned match duplicate(s) (fixture-dedup leftovers with no mirror row)." );
					}
					$orphan_teams = $importer->repair_orphaned_created_teams();
					if ( $orphan_teams ) {
						WP_CLI::log( "Deleted {$orphan_teams} orphaned created team(s) (fixture-dedup leftovers with no match/participant/squad reference)." );
					}
					$orphan_players = $importer->repair_orphaned_player_mirrors();
					if ( $orphan_players ) {
						WP_CLI::log( "Recovered {$orphan_players} orphaned player mirror row(s) (match-lineup-only players with no WP post)." );
					}
					[ $t_in, $t_skip ] = $importer->import_transfers();
					[ $o_up ]          = $importer->sync_odds();
					WP_CLI::success( 'Sync (delta): ' . wp_json_encode( $summary ) . " Transfers: {$t_in} imported, {$t_skip} skipped. Odds: {$o_up} match(es)." );
				}
				break;

			case 'tick':
				// System-cron entry point for live scores: cheap DB gate first,
				// only calls the API while a tracked match is in its live window.
				global $wpdb;
				$due = $wpdb->get_var(
					"SELECT COUNT(*)
					   FROM {$wpdb->posts} p
					   JOIN {$wpdb->postmeta} dt ON dt.post_id = p.ID AND dt.meta_key = 'date_time'
					  WHERE p.api_source = 'statorium' AND p.post_type = 'f365_match'
					    AND dt.meta_value BETWEEN DATE_SUB( UTC_TIMESTAMP(), INTERVAL 4 HOUR ) AND DATE_ADD( UTC_TIMESTAMP(), INTERVAL 15 MINUTE )"
				);
				if ( ! $due ) {
					WP_CLI::success( 'No match in live window — skipped (no API call).' );
					break;
				}
				$n = $importer->sync_live();
				WP_CLI::success( "{$n} live match(es) updated." );
				break;

			case 'poll':
				$summary = ( new F365_Statorium_Updates( $importer ) )->poll();
				WP_CLI::success( 'Updates poll: ' . wp_json_encode( $summary ) );
				break;

			case 'live':
				$n = $importer->sync_live();
				WP_CLI::success( "{$n} live matches updated." );
				break;

			case 'heal-players':
				// No batch cap — drains the entire sparse-player backlog in
				// this one call (the API has no rate limit worth throttling
				// for). --all is accepted for backwards compatibility but is
				// now a no-op: every pending player is always processed.
				$total = $importer->heal_player_profiles();
				WP_CLI::success( "Enriched {$total} player profile(s). Players the API has no birthdate for are rechecked weekly." );
				break;

			case 'heal-teams':
				// No batch cap — drains the entire sparse-team backlog
				// (logo/city/founded/stadium) in this one call.
				$total = $importer->heal_team_profiles();
				WP_CLI::success( "Enriched {$total} team profile(s) (city/founded/stadium from /teams/)." );
				break;

			case 'repair-players':
				// No batch cap — drains the entire orphaned player mirror
				// backlog (match-lineup-only players with mirror data but no
				// WP post) in this one call.
				$total = $importer->repair_orphaned_player_mirrors();
				WP_CLI::success( "Recovered {$total} orphaned player mirror row(s) (linked to a legacy twin or created fresh from /players/)." );
				break;

			case 'heal-stadiums':
				// No batch cap — drains every stadium stuck with empty
				// capacity/address in this one call.
				$total = $importer->heal_stadiums();
				WP_CLI::success( "Enriched {$total} stadium profile(s) (capacity/address from /venues/)." );
				break;

			case 'repair-sync-dates':
				// Backfill `_statorium_synced_at` (the "imported on" day shown on
				// the Statorium Data pages) for posts linked before the stamp
				// existed, by replaying LINK lines from the uploads/f365-logs
				// files. Earliest occurrence wins; existing stamps untouched.
				$dir     = trailingslashit( wp_upload_dir()['basedir'] ) . 'f365-logs/';
				$files   = glob( $dir . 'f365-statorium-*.log' ) ?: [];
				sort( $files ); // chronological — first link wins
				$stamped = 0;
				foreach ( $files as $file ) {
					if ( ! preg_match( '/(\d{4}-\d{2}-\d{2})\.log$/', $file, $fm ) ) {
						continue;
					}
					foreach ( file( $file, FILE_IGNORE_NEW_LINES ) ?: [] as $line ) {
						if ( preg_match( '/^\[(\d{2}:\d{2}:\d{2})\] (?:AUTO)?LINK \w+ stat_id=\d+ → (?:existing )?wp_id=(\d+)/', $line, $m ) ) {
							if ( add_post_meta( (int) $m[2], '_statorium_synced_at', "{$fm[1]} {$m[1]}", true ) ) {
								$stamped++;
							}
						}
					}
				}
				WP_CLI::success( "{$stamped} post(s) stamped with their link date from " . count( $files ) . ' log file(s).' );
				break;

			case 'live-backfill':
				// Create /jogos-em-direto/ coverage (live_livematch row +
				// f365_livegames post + event feed) for every imported match,
				// from data already in the mirror tables — no API calls.
				global $wpdb;
				$match_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE api_source = 'statorium' AND post_type = 'f365_match' AND post_status = 'publish'" );
				$made      = 0;
				foreach ( $match_ids as $mid ) {
					$pub = $importer->ensure_livegame( (int) $mid );
					if ( ! $pub ) {
						continue;
					}
					$live_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}live_livematch WHERE match_id = %d", $pub ) );
					if ( $live_id ) {
						$importer->fill_livegame_from_mirror( $live_id, $pub );
					}
					$made++;
				}
				WP_CLI::success( "Live coverage ensured for {$made} match(es) (rows + posts + event feeds, from mirror data)." );
				break;

			case 'odds':
				// Fetch 1x2 odds for matches in the betting window (or one
				// specific match with --match=<statorium id>, any status).
				[ $o_up, $o_pr ] = $importer->sync_odds( (int) ( $assoc['match'] ?? 0 ) );
				WP_CLI::success( "Odds updated for {$o_up} match(es), pruned {$o_pr}. Map: " . count( (array) get_option( F365_Statorium_Importer::ODDS_OPTION, [] ) ) . ' entr(ies).' );
				break;

			case 'transfers':
				// Import the /transfers/ rolling feed (global, ~50 recent entries)
				// into wp_transfer_transfer — the table /transferencias/ reads.
				[ $t_in, $t_skip ] = $importer->import_transfers();
				WP_CLI::success( "Transfers: {$t_in} imported, {$t_skip} skipped (duplicates/unresolvable). " . wp_json_encode( $importer->stats ) );
				break;

			case 'review':
				global $wpdb;
				$rows = $wpdb->get_results( 'SELECT id, entity_type, stat_id, label, candidates FROM ' . F365_Statorium_Matcher::review_table() . " WHERE status='pending' ORDER BY id" );
				if ( ! $rows ) {
					WP_CLI::success( 'Review queue is empty.' );
					break;
				}
				foreach ( $rows as $r ) {
					WP_CLI::log( "#{$r->id} [{$r->entity_type}] stat_id={$r->stat_id} \"{$r->label}\"" );
					foreach ( (array) json_decode( (string) $r->candidates, true ) as $c ) {
						WP_CLI::log( "     candidate wp_id={$c['wp_id']} \"{$c['title']}\" score={$c['score']} ({$c['signals']})" );
					}
				}
				WP_CLI::log( 'Resolve with: wp f365-statorium resolve --id=N --wp-id=M | --create | --ignore' );
				break;

			case 'review-rescore':
				[ $linked, $pending ] = $importer->rescore_reviews();
				WP_CLI::success( "{$linked} review item(s) auto-linked after rescoring; {$pending} still pending." );
				break;

			case 'resolve':
				$this->resolve( $importer, $assoc );
				break;

			case 'countries':
				// One-time: map /countries/ ids+ISO codes onto f365_country terms.
				[ $mapped, $created_terms, $skipped ] = $importer->map_countries();
				if ( $skipped ) {
					WP_CLI::log( 'Unmapped (no PT name known, left untouched): ' . implode( ', ', array_slice( $skipped, 0, 40 ) ) . ( count( $skipped ) > 40 ? ' …' : '' ) );
				}
				$flags = $importer->backfill_country_flags();
				WP_CLI::success( "{$mapped} countries mapped to f365_country terms, {$created_terms} PT terms created, " . count( $skipped ) . " skipped, {$flags} flag(s) attached." );
				break;

			case 'venues':
				// Enrich every mapped venue (photo, capacity, address, coords).
				global $wpdb;
				$ids = $wpdb->get_col( "SELECT api_id FROM {$wpdb->posts} WHERE api_source = 'statorium' AND post_type = 'f365_stadium'" );
				$n = 0;
				foreach ( $ids as $sid ) {
					delete_post_meta( (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE api_source='statorium' AND api_id=%d AND post_type='f365_stadium'", (int) $sid ) ), 'statorium_venue_synced' );
					if ( $importer->enrich_venue( (int) $sid ) ) {
						$n++;
					}
					usleep( 250000 );
				}
				WP_CLI::success( "{$n}/" . count( $ids ) . ' venues enriched.' );
				break;

			case 'match-detail':
				$mid = (int) ( $assoc['match'] ?? 0 );
				if ( ! $mid ) {
					WP_CLI::error( 'Missing --match=<statorium match id>.' );
				}
				$ok = $importer->sync_match_detail( $mid );
				$ok ? WP_CLI::success( "Detail synced for match {$mid}." ) : WP_CLI::error( 'Detail sync failed — is the match imported?' );
				break;

			case 'details':
				// Sync detail for every finished imported match missing it
				// (--force re-syncs matches that already have detail).
				global $wpdb;
				$force = isset( $assoc['force'] ) ? '' : "AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} d WHERE d.post_id = p.ID AND d.meta_key = 'statorium_detail_synced' )";
				$rows  = $wpdb->get_col(
					"SELECT p.api_id FROM {$wpdb->posts} p
					   JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = 'statorium_status_id' AND st.meta_value = '1'
					  WHERE p.api_source = 'statorium' AND p.api_id IS NOT NULL AND p.post_type = 'f365_match' {$force}"
				);
				$done = 0;
				foreach ( $rows as $stat_id ) {
					if ( $importer->sync_match_detail( (int) $stat_id ) ) {
						$done++;
					}
					usleep( 300000 );
				}
				WP_CLI::success( "{$done}/" . count( $rows ) . ' match details synced.' );
				break;

			case 'prune-season':
				$this->need_season( $season );
				if ( ! isset( $assoc['yes'] ) ) {
					WP_CLI::error( 'Deletes this season and everything created for it. Re-run with --yes.' );
				}
				[ $m, $t, $v, $c ] = $importer->prune_season( $season );
				WP_CLI::success( "Pruned season {$season}: {$m} matches, {$t} teams, {$v} venues, {$c} competition deleted." );
				break;

			case 'merge-competition':
				$this->need_season( $season );
				$into = (int) ( $assoc['into'] ?? 0 );
				if ( ! $into ) {
					WP_CLI::error( 'Usage: merge-competition --season=<statorium season id> --into=<legacy competition row id>' );
				}
				$sum = $importer->merge_competition( $season, $into );
				if ( isset( $sum['error'] ) ) {
					WP_CLI::error( 'Merge aborted: ' . $sum['error'] );
				}
				if ( ! empty( $sum['already_linked'] ) ) {
					WP_CLI::success( "Nothing to merge — season {$season} is already linked to legacy competition {$into} (current builds auto-link on import). Fixture-pairing pass ran: {$sum['paired']} fixture(s) touched." );
					break;
				}
				$sum['repaired'] = $importer->repair_group_pairing( $into );
				WP_CLI::success( 'Competition merged: ' . wp_json_encode( $sum ) );
				break;

			case 'repair-pairing':
				$into = (int) ( $assoc['into'] ?? 0 );
				if ( ! $into ) {
					WP_CLI::error( 'Usage: repair-pairing --into=<legacy competition row id>' );
				}
				$n = $importer->repair_group_pairing( $into );
				WP_CLI::success( "{$n} leftover fixture(s) paired." );
				break;

			case 'audit-countries':
				// Compare every LINKED player's legacy nationality against the
				// API country. Classifies: naming variant (canon-equal, fine),
				// data disagreement (birthdate confirms same person — legacy
				// kept per fill-gaps), or SUSPECT link (no birthdate proof).
				global $wpdb;
				$limit  = (int) ( $assoc['limit'] ?? 0 );
				$linked = $wpdb->get_results(
					"SELECT p.ID, p.post_title, p.api_id, p.legacy_id FROM {$wpdb->posts} p
					  WHERE p.api_source = 'statorium' AND p.api_id IS NOT NULL
					    AND p.post_type = 'f365_player' AND p.post_status = 'publish'
					    AND p.legacy_id BETWEEN 1 AND " . ( F365_Statorium_Importer::ID_OFFSET - 1 ) . '
					    AND NOT EXISTS ( SELECT 1 FROM ' . $wpdb->postmeta . " s
					                      WHERE s.post_id = p.ID AND s.meta_key = '_f365_source' )"
					. ( $limit ? " LIMIT {$limit}" : '' )
				);
				$api      = new F365_Statorium_API( F365_Statorium_Importer::api_key() );
				$checked  = 0;
				$variants = 0;
				$disagree = 0;
				$suspects = 0;
				foreach ( $linked as $lp ) {
					$d = $api->get( '/players/' . (int) $lp->api_id . '/', [] );
					if ( is_wp_error( $d ) ) {
						continue;
					}
					$pl = (array) ( $d['player'] ?? [] );
					$checked++;
					$api_en = (string) ( $pl['country']['name'] ?? '' );
					if ( '' === $api_en ) {
						continue;
					}
					$api_pt  = F365_Statorium_Importer::country_pt( $api_en );
					$leg_row = $wpdb->get_row( $wpdb->prepare(
						"SELECT c.shortname, pr.birth_date FROM {$wpdb->prefix}person_player pp
						   JOIN {$wpdb->prefix}person_person pr ON pr.id = pp.person_id
						   LEFT JOIN {$wpdb->prefix}country_country c ON c.id = pr.nationality_id
						  WHERE pp.id = %d",
						(int) $lp->legacy_id
					) );
					$leg_pt = (string) ( $leg_row->shortname ?? '' );
					if ( '' === $leg_pt ) {
						continue; // legacy has no nationality — nothing to compare
					}
					if ( F365_Statorium_Matcher::canon( $leg_pt ) === F365_Statorium_Matcher::canon( $api_pt ) ) {
						continue; // same country, possibly different spelling
					}
					$variants++; // any surviving difference below is a REAL country difference

					$api_bd = '';
					if ( preg_match( '/(\d{2})-(\d{2})-(\d{4})/', (string) ( $pl['additionalInfo']['birthdate'] ?? '' ), $bm ) ) {
						$api_bd = "{$bm[3]}-{$bm[2]}-{$bm[1]}";
					}
					$leg_bd   = (string) ( $leg_row->birth_date ?? '' );
					$bd_match = $api_bd && $leg_bd && '0000-00-00' !== $leg_bd && F365_Statorium_Matcher::dates_close( $leg_bd, $api_bd );

					if ( $bd_match ) {
						$disagree++;
						WP_CLI::log( sprintf( 'DATA-DIFF  wp_id=%d "%s" legacy=%s api=%s (birthdate CONFIRMS same person %s — legacy kept per fill-gaps)',
							(int) $lp->ID, $lp->post_title, $leg_pt, $api_pt, $leg_bd ) );
					} else {
						$suspects++;
						WP_CLI::warning( sprintf( 'SUSPECT    wp_id=%d "%s" stat=%d legacy=%s api=%s legacy_bd=%s api_bd=%s — verify with: wp f365-statorium why --type=player --id=%d',
							(int) $lp->ID, $lp->post_title, (int) $lp->api_id, $leg_pt, $api_pt, $leg_bd ?: '—', $api_bd ?: '—', (int) $lp->api_id ) );
					}
				}
				WP_CLI::success( "Audited {$checked} linked player(s): {$variants} real country difference(s) — {$disagree} same-person data disagreements (birthdate confirms; legacy kept per fill-gaps), {$suspects} SUSPECT link(s) needing review. Spelling variants were auto-ignored." );
				break;

			case 'why':
				// Diagnostic: why does entity X link or create on THIS box?
				// Usage: why --type=player|team --id=<statorium id>
				$w_type = (string) ( $assoc['type'] ?? 'player' );
				$w_id   = (int) ( $assoc['id'] ?? 0 );
				if ( ! $w_id || ! in_array( $w_type, [ 'player', 'team', 'match' ], true ) ) {
					WP_CLI::error( 'Usage: why --type=player|team|match --id=<statorium id>' );
				}

				if ( 'match' === $w_type ) {
					// Matches never use the name matcher: identity = the
					// api_id column; legacy fixtures attach via team-pair
					// pairing at import. Re-creation every sync = identity
					// lost (posts without api_id / stale option map).
					global $wpdb;
					WP_CLI::log( 'Build: ' . F365_STAT_VERSION . ' / db v' . get_option( 'f365_statorium_db_version' ) );
					$mapped = $importer->map_resolve( 'match', $w_id );
					WP_CLI::log( 'Stored identity: ' . ( $mapped ? "wp_id={$mapped} status=" . get_post_status( $mapped ) . ' pub=' . get_post_meta( $mapped, 'django_match_id', true ) : 'NONE — next import will CREATE (this is the re-creation cause if the match visibly exists)' ) );
					$dupes = $wpdb->get_results( $wpdb->prepare(
						"SELECT ID, post_status, legacy_id, api_id FROM {$wpdb->posts} WHERE post_type = 'f365_match' AND api_id = %d",
						$w_id
					) );
					foreach ( $dupes as $dp ) {
						WP_CLI::log( "Post with this api_id: #{$dp->ID} status={$dp->post_status} pub={$dp->legacy_id}" );
					}
					$md = ( new F365_Statorium_API( F365_Statorium_Importer::api_key() ) )->match( $w_id, true );
					$mm = is_wp_error( $md ) ? [] : (array) ( $md['match'] ?? [] );
					if ( $mm ) {
						$pair = ( $mm['homeParticipant']['participantName'] ?? '?' ) . ' vs ' . ( $mm['awayParticipant']['participantName'] ?? '?' );
						WP_CLI::log( 'API: ' . ( $mm['matchDate'] ?? '?' ) . ' ' . $pair . ' (league ' . ( $mm['leagueID'] ?? '?' ) . ')' );
						$twins = $wpdb->get_results( $wpdb->prepare(
							"SELECT m.id, m.competition_id, htm.shortname h, vtm.shortname v FROM {$wpdb->prefix}competition_match m
							   JOIN {$wpdb->prefix}team_teamstep ht ON ht.id = m.home_team_id JOIN {$wpdb->prefix}team_team htm ON htm.id = ht.team_id
							   JOIN {$wpdb->prefix}team_teamstep vt ON vt.id = m.visitor_team_id JOIN {$wpdb->prefix}team_team vtm ON vtm.id = vt.team_id
							  WHERE DATE( m.date_time ) = %s LIMIT 12",
							(string) ( $mm['matchDate'] ?? '' )
						) );
						foreach ( $twins as $tw ) {
							WP_CLI::log( "Mirror fixture same day: row {$tw->id} (comp {$tw->competition_id}) {$tw->h} vs {$tw->v}" );
						}
					}
					WP_CLI::success( 'Diagnosis printed.' );
					break;
				}
				global $wpdb;

				WP_CLI::log( 'Build: ' . F365_STAT_VERSION . ' / db v' . get_option( 'f365_statorium_db_version' ) );
				$idx = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '" . F365_Statorium_Matcher::NORM_KEY . "'" );
				WP_CLI::log( "Name index rows: {$idx}" . ( $idx ? '' : '  ← EMPTY: matcher is blind, everything creates!' ) );

				$mapped = $importer->map_resolve( $w_type, $w_id );
				WP_CLI::log( 'Stored identity (map/api columns): ' . ( $mapped
					? "wp_id={$mapped} \"" . get_the_title( $mapped ) . '" status=' . get_post_status( $mapped )
					: 'none — next import will run the matcher' ) );

				// Build the same signals the importer would.
				$post_type = 'player' === $w_type ? 'f365_player' : 'f365_team';
				if ( 'player' === $w_type ) {
					$d  = ( new F365_Statorium_API( F365_Statorium_Importer::api_key() ) )->get( "/players/{$w_id}/", [], true );
					$pl = $d['player'] ?? [];
					$bd = '';
					if ( preg_match( '/(\d{2})-(\d{2})-(\d{4})/', (string) ( $pl['additionalInfo']['birthdate'] ?? '' ), $bm ) ) {
						$bd = "{$bm[3]}-{$bm[2]}-{$bm[1]}";
					}
					$signals = [
						'names'     => array_filter( [ (string) ( $pl['fullName'] ?? '' ), (string) ( $pl['homeName'] ?? '' ) ] ),
						'country'   => F365_Statorium_Importer::country_pt( (string) ( $pl['country']['name'] ?? '' ) ),
						'birthdate' => $bd,
						'shirt'     => (string) ( $pl['playerNumber'] ?? '' ),
					];
				} else {
					$d       = ( new F365_Statorium_API( F365_Statorium_Importer::api_key() ) )->get( "/teams/{$w_id}/", [ 'season_id' => 0 ], true );
					$t       = $d['team'] ?? [];
					$name    = (string) ( $t['teamName'] ?? '' );
					$signals = [ 'names' => array_filter( [ $name, (string) ( $t['shortName'] ?? '' ), (string) ( $t['middleName'] ?? '' ) ] ) ];
				}
				WP_CLI::log( 'API signals: ' . wp_json_encode( $signals, JSON_UNESCAPED_UNICODE ) );

				$matcher = new F365_Statorium_Matcher();
				$ex      = $matcher->explain( $w_type, $post_type, $w_id, $signals );
				WP_CLI::log( 'Review status: ' . ( $ex['review_status'] ?: '(none)' ) );
				WP_CLI::log( 'Normalized names searched: ' . implode( ' | ', $ex['normalized_names'] ) );
				if ( ! empty( $ex['fuzzy_used'] ) ) {
					WP_CLI::log( 'Candidates found via FUZZY first+last-token match (no exact name form matched — links only when the birthdate confirms).' );
				}
				if ( ! $ex['candidates'] ) {
					WP_CLI::log( 'Candidates: NONE — no post carries these names in the index (not even fuzzily). If the legacy record exists, run index-names (its name forms are missing) → then sync auto-merges.' );
				}
				foreach ( $ex['candidates'] as $c ) {
					WP_CLI::log( sprintf(
						'Candidate wp_id=%d "%s" score=%d%s%s %s',
						$c['wp_id'], $c['title'], $c['score'],
						$c['contradicts'] ? ' CONTRADICTS(birthdate>1d)' : '',
						$c['already_api'] ? " already-api-id={$c['already_api']}" : '',
						wp_json_encode( $c['signals'], JSON_UNESCAPED_UNICODE )
					) );
				}
				WP_CLI::success( 'Diagnosis printed — share this output when reporting a duplicate.' );
				break;

			case 'merge-player':
				$from = (int) ( $assoc['from-wp'] ?? 0 );
				$into = (int) ( $assoc['into-wp'] ?? 0 );
				if ( ! $from || ! $into ) {
					WP_CLI::error( 'Usage: merge-player --from-wp=<created post id> --into-wp=<legacy post id>' );
				}
				$importer->merge_player( $from, $into )
					? WP_CLI::success( "Player merged: {$from} → {$into}." )
					: WP_CLI::error( 'Merge failed — see log.' );
				break;

			case 'merge-team':
				$from = (int) ( $assoc['from-wp'] ?? 0 );
				$into = (int) ( $assoc['into-wp'] ?? 0 );
				if ( ! $from || ! $into ) {
					WP_CLI::error( 'Usage: merge-team --from-wp=<created post id> --into-wp=<legacy post id>' );
				}
				$importer->merge_team( $from, $into )
					? WP_CLI::success( "Merged #{$from} into #{$into}." )
					: WP_CLI::error( 'Merge aborted — see log.' );
				break;

			case 'repair':
				// --dry-run only gates the destructive live-orphan cleanup
				// below; every other repair_* here is additive (fill/correct/
				// fold) and always runs live, as before.
				$importer->dry_run = isset( $assoc['dry-run'] );
				$live_orphans       = $importer->repair_orphaned_live_matches();
				$importer->dry_run  = false;

				$unstamped = $importer->repair_misclassified_links();
				$fixed     = $importer->repair_linked_team_shortnames();
				$dates     = $importer->repair_foundation_dates();
				$mirrors   = $importer->repair_team_mirror_profiles();
				$stadiums  = $importer->repair_missing_stadium_rows();
				$types     = $importer->repair_team_types();
				$confeds   = $importer->repair_team_country_federation();
				$steps     = $importer->repair_duplicate_teamsteps();
				$players   = $importer->repair_player_mirror_profiles();
				$venues    = $importer->repair_stadium_mirror_profiles();
				$groups    = $importer->repair_participant_groups();
				$missing_parts = $importer->repair_missing_participants();
				$names     = $importer->repair_team_names();
				$coaches   = $importer->repair_teamstep_coaches();
				$std_geo   = $importer->repair_stadium_country();
				$fedcomp   = $importer->repair_match_competition_django_id();
				$fedcomps_folded = $importer->repair_orphaned_fedcomps();
				$orphan_teams = $importer->repair_orphaned_created_teams();
				// Unlike every other repair_* above, this one makes real API
				// calls (one per orphan, up to 25) — re-run the command if
				// more than 25 orphaned player mirrors remain.
				$orphan_players = $importer->repair_orphaned_player_mirrors();
				$dry_note  = $assoc['dry-run'] ?? null;
				WP_CLI::success( ( null !== $dry_note ? "DRY RUN: {$live_orphans} orphaned live match row(s) would be removed. " : "{$live_orphans} orphaned live match row(s) removed. " ) . "{$unstamped} misclassified link(s) corrected, {$fixed} team shortname(s) restored, {$dates} foundation date(s) repaired, {$mirrors} team mirror row(s) synced, {$stadiums} missing stadium mirror row(s) created, {$types} team type(s) flipped to club, {$confeds} team country/federation resolved, {$steps} duplicate teamstep(s) folded, {$players} player mirror profile(s) filled, {$venues} legacy stadium mirror row(s) gap-filled, {$groups} participant group(s) backfilled, {$missing_parts} missing competition_participant row(s) backfilled (Game Summary fix), {$names} team longname(s) corrected, {$coaches} Equipa Técnica row(s) created, {$std_geo} stadium country resolved, {$fedcomp} match competition_django_id corrected, {$fedcomps_folded} orphaned fedcomp(s) folded into their real legacy league, {$orphan_teams} orphaned created team(s) removed, {$orphan_players} orphaned player mirror row(s) recovered (batch of up to 25)." );
				break;

			case 'teamsteps':
				// Fold duplicate teamsteps (varchar(4) edition truncation).
				// --dry-run counts without writing.
				$importer->dry_run = isset( $assoc['dry-run'] );
				$n = $importer->repair_duplicate_teamsteps();
				WP_CLI::success( ( $importer->dry_run ? "DRY RUN: {$n} duplicate teamstep(s) would be folded." : "{$n} duplicate teamstep(s) folded into their canonical rows." ) );
				break;

			case 'rankings':
				// Derive top-scorer award rows (Melhor Marcador) for completed
				// seasons. --dry-run counts without writing.
				$importer->dry_run = isset( $assoc['dry-run'] );
				$n = $importer->finalize_topscorer_rankings();
				WP_CLI::success( ( $importer->dry_run ? "DRY RUN: {$n} ranking row(s) would be inserted." : "{$n} ranking row(s) inserted." ) );
				break;

			case 'touched':
				$rows = F365_Statorium_Importer::touched_legacy_posts();
				foreach ( (array) $rows as $r ) {
					WP_CLI::log( sprintf( '%-18s wp_id=%-8d api_id=%-8d %s', $r->post_type, $r->ID, $r->api_id, $r->post_title ) );
				}
				WP_CLI::success( count( (array) $rows ) . ' linked legacy post(s).' );
				break;

			case 'rollback':
				// Scoped rollback: --module=all|match|player|team|stadium|competition|transfers|countries
				//                  --day=Y-m-d OR --from=Y-m-d --to=Y-m-d (import day)
				//                  --unlink  also unlink legacy posts in scope
				//                  --yes     actually delete (without it: DRY RUN preview)
				$mod   = (string) ( $assoc['module'] ?? 'all' );
				$valid = [ 'all', 'match', 'player', 'team', 'stadium', 'competition', 'transfers', 'countries' ];
				if ( ! in_array( $mod, $valid, true ) ) {
					WP_CLI::error( '--module must be one of: ' . implode( ', ', $valid ) );
				}
				$rb_from = (string) ( $assoc['day'] ?? $assoc['from'] ?? '' );
				$rb_to   = (string) ( $assoc['day'] ?? $assoc['to'] ?? '' );
				foreach ( [ $rb_from, $rb_to ] as $d ) {
					if ( '' !== $d && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
						WP_CLI::error( 'Dates must be Y-m-d.' );
					}
				}
				$rb_unlink = isset( $assoc['unlink'] );
				$dry       = ! isset( $assoc['yes'] );

				$summary = $importer->rollback_scope( $mod, $rb_from, $rb_to, $rb_unlink, $dry );
				$label   = $mod . ( $rb_from || $rb_to ? " [{$rb_from}..{$rb_to}]" : ' [all dates]' ) . ( $rb_unlink ? ' +unlink' : '' );
				if ( ( $rb_from || $rb_to ) && ! array_sum( array_map( 'intval', $summary ) ) ) {
					WP_CLI::log( 'Nothing matched that date. Dates filter on the IMPORT day (when the sync ran — see the day pills on the Statorium Data pages); for transfers the transfer date also matches. Re-run without --day/--from/--to to preview everything.' );
				}
				if ( $dry ) {
					WP_CLI::log( 'DRY RUN (nothing deleted) — scope ' . $label . ': ' . wp_json_encode( $summary ) );
					WP_CLI::success( 'Re-run with --yes to delete. Legacy data is never deleted; mirror rows only in the created id range.' );
				} else {
					WP_CLI::success( 'Rollback ' . $label . ': ' . wp_json_encode( $summary ) . ' Caches purged.' );
				}
				break;

			default:
				WP_CLI::error( "Unknown subcommand '{$sub}'. See the file header for usage." );
		}
	}

	/**
	 * Shared `--into=<legacy comp id>` step for backfill/today: fold the
	 * season into the legacy competition after the import. Harmless when it
	 * is already auto-linked ("nothing to merge"); merges the duplicate when
	 * an older build created one.
	 */
	private function maybe_merge_into( F365_Statorium_Importer $importer, array $assoc, int $season ): void {
		// Always fold freshly-imported day fixtures into pre-loaded legacy
		// skeletons — required with or without --into, since the day paths
		// create rows without pairing.
		$folded = $importer->pair_legacy_fixtures();
		if ( $folded ) {
			WP_CLI::log( "Folded {$folded} imported fixture(s) into pre-loaded legacy rows." );
		}

		if ( empty( $assoc['into'] ) ) {
			return;
		}
		$into_season = $season ?: (int) ( explode( ',', (string) F365_Statorium_Importer::settings()['seasons'] )[0] );
		$msum        = $importer->merge_competition( $into_season, (int) $assoc['into'] );
		if ( ! empty( $msum['already_linked'] ) ) {
			WP_CLI::log( "Season {$into_season} already linked to legacy competition " . (int) $assoc['into'] . " — nothing to merge (pairing pass: {$msum['paired']})." );
		} elseif ( isset( $msum['error'] ) ) {
			WP_CLI::warning( 'merge-competition step: ' . $msum['error'] );
		} else {
			$msum['repaired'] = $importer->repair_group_pairing( (int) $assoc['into'] );
			WP_CLI::log( 'Merged duplicate season into legacy: ' . wp_json_encode( $msum ) );
		}
	}

	private function resolve( F365_Statorium_Importer $importer, array $assoc ): void {
		global $wpdb;
		$row_id = (int) ( $assoc['id'] ?? 0 );
		$row    = $row_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . F365_Statorium_Matcher::review_table() . ' WHERE id = %d', $row_id ) ) : null;
		if ( ! $row ) {
			WP_CLI::error( 'Review row not found — pass --id=N (see `review`).' );
		}

		if ( ! empty( $assoc['wp-id'] ) ) {
			$wp_id = (int) $assoc['wp-id'];
			$importer->map_set( $row->entity_type, (int) $row->stat_id, $wp_id );
			update_post_meta( $wp_id, '_f365_statorium_linked', 1 );
			update_post_meta( $wp_id, '_f365_match_method', 'manual-review' );
			F365_Statorium_Matcher::set_review_status( $row_id, 'linked' );
			WP_CLI::success( "Linked {$row->entity_type} stat_id={$row->stat_id} → wp_id={$wp_id}." );
			return;
		}
		if ( isset( $assoc['create'] ) ) {
			F365_Statorium_Matcher::set_review_status( $row_id, 'create' );
			WP_CLI::success( 'Marked for creation — the next sync will create it as a new record.' );
			return;
		}
		if ( isset( $assoc['ignore'] ) ) {
			F365_Statorium_Matcher::set_review_status( $row_id, 'ignored' );
			WP_CLI::success( 'Ignored — this record will be skipped.' );
			return;
		}
		WP_CLI::error( 'Pass one of --wp-id=M, --create, --ignore.' );
	}

	private function need_season( int $season ): void {
		if ( ! $season ) {
			WP_CLI::error( 'Missing --season=<statorium season id> (e.g. --season=614).' );
		}
	}
}

WP_CLI::add_command( 'f365-statorium', 'F365_Statorium_Command' );
