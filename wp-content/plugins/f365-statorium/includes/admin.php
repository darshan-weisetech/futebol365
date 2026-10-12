<?php
/**
 * Admin — Settings → Statorium Sync: settings, state machine, manual actions,
 * review queue (approve / create / ignore).
 *
 * @package F365_Statorium
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_options_page( 'Statorium Sync', 'Statorium Sync', 'manage_options', 'f365-statorium', 'f365_statorium_render_settings' );
} );

function f365_statorium_admin_url( array $args = [] ): string {
	return add_query_arg( $args, admin_url( 'options-general.php?page=f365-statorium' ) );
}

// f365_statorium_setup_checklist(), f365_statorium_run_checklist_step(), and
// f365_statorium_complete_pending_setup() now live in cron.php — that file is
// always loaded (admin.php is is_admin()-gated, so WP-Cron/WP-CLI contexts
// never see functions defined only here).

function f365_statorium_render_settings(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings = F365_Statorium_Importer::settings();
	$state    = F365_Statorium_Backfill::state();

	if ( isset( $_GET['f365stat_msg'] ) ) {
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sanitize_text_field( wp_unslash( $_GET['f365stat_msg'] ) ) )
		);
	}

	$key_from_config = defined( 'F365_STATORIUM_API_KEY' ) && F365_STATORIUM_API_KEY;
	?>
	<div class="wrap">
		<h1>Statorium Sync</h1>
		<p>
			<strong>Mode:</strong>
			<?php if ( 'backfill' === $state['mode'] ) : ?>
				Backfill — next day to process: <code><?php echo esc_html( $state['cursor'] ); ?></code>
			<?php else : ?>
				Delta — polling <code>/updates/</code> since <code><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $state['last_update_ts'] ) ); ?> GMT</code>
			<?php endif; ?>
		</p>

		<?php if ( 'backfill' === $state['mode'] ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 16px">
				<?php wp_nonce_field( 'f365stat_set_cursor' ); ?>
				<input type="hidden" name="action" value="f365stat_set_cursor">
				<label>Change backfill start date:
					<input type="date" name="cursor_date" value="<?php echo esc_attr( $state['cursor'] ); ?>">
				</label>
				<button type="submit" class="button">Set start date</button>
				<span class="description">Resumes backfill from this day forward — click "Run backfill batch" below (or wait for the next sync cycle) to actually process it.</span>
			</form>
		<?php endif; ?>

		<?php [ $scenario, $stat_posts, $checklist ] = f365_statorium_setup_checklist(); ?>
		<h2>Setup checklist <small style="font-weight:normal">— detected:
			<?php echo 'A' === $scenario
				? '<strong>Scenario A</strong> (database already contains Statorium data — ' . (int) $stat_posts . ' mapped posts)'
				: '<strong>Scenario B</strong> (no Statorium data yet — fresh setup on an old-production database)'; ?></small>
		</h2>
		<table class="widefat striped" style="max-width:1100px">
			<thead><tr><th style="width:36px"></th><th style="width:320px">Step</th><th>Status</th><th style="width:160px">Action</th></tr></thead>
			<tbody>
			<?php foreach ( $checklist as [ $label, $done, $detail, $what, $heavy, $cmd ] ) : ?>
				<tr>
					<td style="font-size:16px"><?php echo null === $done ? '❔' : ( $done ? '✅' : '⏳' ); ?></td>
					<td><strong><?php echo esc_html( $label ); ?></strong></td>
					<td>
						<?php echo esc_html( $detail ); ?>
						<?php if ( $cmd ) : ?>
							<br><code style="font-size:11px;user-select:all"><?php echo esc_html( $cmd ); ?></code>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $what ) : ?>
							<a class="button<?php echo $done ? '' : ' button-primary'; ?>"
								href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=f365stat_run&what=' . $what ), 'f365stat_run' ) ); ?>"
								<?php echo $heavy ? 'onclick="return confirm(\'This step can take several minutes in one request. For big imports the CLI is safer. Run here anyway?\');"' : ''; ?>>
								<?php echo $done ? 'Re-run' : 'Run now'; ?></a>
						<?php else : ?>
							<span class="description">manual</span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			✅ done · ⏳ pending · ❔ can't auto-detect. Buttons run the step immediately (same code as the CLI commands);
			heavy steps are batched or confirm first. The full manual reference remains in the
			<em>Staging / deployment checklist</em> below — this table is its live, self-detecting version.
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'f365stat_save' ); ?>
			<input type="hidden" name="action" value="f365stat_save">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">API key</th>
					<td>
						<?php if ( $key_from_config ) : ?>
							<code><?php echo esc_html( substr( F365_STATORIUM_API_KEY, 0, 6 ) ); ?>…</code>
							<p class="description">Defined as <code>F365_STATORIUM_API_KEY</code> in <code>wp-config.php</code>.</p>
						<?php else : ?>
							<input type="text" name="api_key" class="regular-text code" value="<?php echo esc_attr( (string) $settings['api_key'] ); ?>">
							<p class="description">Better: define <code>F365_STATORIUM_API_KEY</code> in wp-config.php.</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Legacy data policy</th>
					<td>
						<strong>Fill gaps</strong> — new records are created in full; existing (legacy) records only ever gain
						API ids and values for fields that are <em>empty</em> (missing photos, birthdates, …). Existing values are never overwritten.
						<p class="description">Fixed policy for this project (Strict/Full disabled). Developers can override via the <code>f365_statorium_update_mode</code> filter.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Header match ticker</th>
					<td>
						<label><input type="checkbox" name="ticker_ui" value="1" <?php checked( ! empty( $settings['ticker_ui'] ) ); ?>>
						Show the 4-tab match bar (Jogos em direto / terminados / próximos / na televisão) in the site header</label>
						<p class="description">Shortcode also available: <code>[f365_match_ticker tab="inlive|finished|tostart|ontv"]</code>. Data refreshes every minute; always live even on cached pages.</p>
						<p>
							<label><input type="checkbox" name="hide_empty_tabs" value="1" <?php checked( ! empty( $settings['hide_empty_tabs'] ) ); ?>>
							Hide empty tabs — don't show a tab at all when it has no matches, instead of the "Sem informação disponível" placeholder</label>
							<span class="description">If every tab would end up empty, all four stay visible rather than collapsing the bar entirely.</span>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save settings' ); ?>
		</form>

		<h2>Leagues → Federations</h2>
		<?php
		$league_map = f365_statorium_league_map();
		if ( ! $league_map ) {
			echo '<p><em>No leagues mapped yet.</em></p>';
		} else {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'f365stat_leagues' ); ?>
				<input type="hidden" name="action" value="f365stat_save_leagues">
				<table class="widefat striped" style="max-width:1100px">
					<thead><tr><th>League (Statorium id)</th><th>Federation post ID</th></tr></thead>
					<tbody>
					<?php foreach ( $league_map as $league_id => $entry ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $entry['name'] ); ?></strong> <code>#<?php echo (int) $league_id; ?></code>
								<?php if ( empty( $entry['federation_wp'] ) ) : ?><br><span style="color:#b32d2e">unmapped — set a federation ID</span><?php endif; ?>
							</td>
							<td>
								<input type="number" name="fed[<?php echo (int) $league_id; ?>]" value="<?php echo (int) $entry['federation_wp']; ?>" style="width:110px">
								<?php if ( ! empty( $entry['federation_wp'] ) ) : ?>
									<small><?php echo esc_html( get_the_title( (int) $entry['federation_wp'] ) ); ?></small>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<?php submit_button( 'Save league mapping', 'secondary', 'submit', false ); ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=f365stat_run&what=leagues' ), 'f365stat_run' ) ); ?>">Refresh from API</a>
				</p>
			</form>
			<?php
		}
		if ( ! $league_map ) {
			printf(
				'<p><a class="button" href="%s">Fetch leagues from API</a></p>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=f365stat_run&what=leagues' ), 'f365stat_run' ) )
			);
		}
		?>

		<h2>Data audit pages</h2>
		<p>
			The <a href="<?php echo esc_url( admin_url( 'admin.php?page=f365-statorium-data' ) ); ?>"><strong>Statorium Data</strong></a>
			admin menu has one page per module — Competitions, Teams, Players, Matches, Stadiums, Countries, Transfers —
			listing everything the importer <em>created</em> or <em>linked to legacy</em>: per-day import counts (click a day to filter),
			Statorium&nbsp;id / public&nbsp;id, origin (created vs linked + match method), and frontend links including every sub-page
			(jogos, plantel, classificação, …) plus edit links. Use it on staging to verify what each sync run produced.
			If items show "date not recorded" (linked by a build older than 2026-07-23), backfill the dates from the import logs:
			<code>wp f365-statorium repair-sync-dates</code> (re-run safe; reads uploads/f365-logs/, earliest link wins).
		</p>

		<h2>Link vs Create — decision rules per module</h2>
		<p>
			Every incoming API record is first resolved against existing data; only when resolution fails is a new record created
			(public id = <code>90&nbsp;000&nbsp;000&nbsp;+ Statorium id</code>). Resolution always starts with the stored identity —
			the indexed <code>api_source</code>/<code>api_id</code> columns written on every earlier link/create — so a record is matched at most once;
			the rules below describe the <em>first</em> encounter. Update policy is always <strong>fill-gaps</strong>:
			on legacy records a non-empty value is never overwritten, only empty fields are filled; records we created are refreshed fully.
		</p>
		<table class="widefat striped" style="max-width:1100px">
			<thead><tr>
				<th style="width:110px">Module</th>
				<th>Matched to existing (→ LINK + fill gaps) when…</th>
				<th>What gets written on update</th>
				<th>NEW record created when…</th>
			</tr></thead>
			<tbody>
				<tr>
					<td><strong>Competitions</strong><br><small>(seasons)</small></td>
					<td>League is in the league→federation map AND a legacy competition exists with the same <strong>federation + edition + name</strong> (name normalized, incl. the config's <code>legacy_name</code> — "Brasileirão", "Liga dos Campeões", "Mundial"… name alone is never trusted: 281 legacy comps are called "Premier League").</td>
					<td><code>season</code> field; fixtures pair into pre-loaded legacy skeletons (ids/URLs kept, empty scores filled); phases/groups rows added.</td>
					<td>League is subscribed (in the league map) but no legacy competition matches federation+edition+name. Unsubscribed leagues leaking through <code>/updates/</code> are SKIPped entirely.</td>
				</tr>
				<tr>
					<td><strong>Teams</strong></td>
					<td>Name matches the index (ALL name forms: title, shortname, longname from posts AND mirror tables, plus aliases like Irã→Irão) with no contradiction; signals: country (nationals), team_type. Single candidate → link.</td>
					<td>shortname/longname/team_type gaps, logo file (only if missing), mirror team row, teamsteps/participants as needed.</td>
					<td>No name-form candidate, or several candidates (ambiguous). Safety net: every sync runs the duplicate sweep in two passes — first an exact name-twin match; if none, a <strong>fuzzy fallback</strong> strips generic club words (FC/AC/SC/Real/Deportivo/Calcio/Club…) from both sides and requires one name to be the other's core plus only a clean, empty-or-numeric prefix/suffix (a founding year), corroborated by matching <code>federation_id</code> (or, when a created row's federation is unset, a stricter exact-core match across all federations) — multiple fuzzy candidates tie-break on shared <code>stadium_id</code>, else logged as ambiguous. Either pass auto-merges the created duplicate back into legacy (→ <code>merge-team</code> for anything still ambiguous). <strong>⚠ B/reserve teams are NOT duplicates of their parent club</strong> — same crest, different team ("SL Benfica B" plays at Benfica Campus, not Estádio da Luz; legacy has no B-team rows). Never <code>merge-team</code> a B side into the main club: shared logo is branding, not identity — compare home venue and competition instead.</td>
				</tr>
				<tr>
					<td><strong>Players</strong></td>
					<td>Name matches the index + supporting signals: country (+2), birthdate within <strong>±1 day</strong> (+2 — the two sources often disagree by one day on the same person), shirt number (+1). Single candidate, no contradiction → link. If NO exact name form matches, a <strong>fuzzy first+last-token fallback</strong> catches middle-name variants ("Juan Fernando Quintero" ↔ "Juan Quintero") — a fuzzy hit may only link when the <strong>birthdate confirms</strong> it.</td>
					<td>firstname/lastname/height/weight/number/current_team gaps, photo if missing, person mirror rows (incl. '0000-00-00' birthdate gap-fill).</td>
					<td>No candidate, ambiguous candidates, or a REAL birthdate contradiction (&gt;1 day — e.g. two different Nico Gonzálezes). Safety net: sync auto-merges a created player when exactly ONE legacy name-twin exists and both birthdates agree within 1 day (else logged for <code>merge-player</code>).</td>
				</tr>
				<tr>
					<td><strong>Matches</strong></td>
					<td>Competition is legacy-linked AND a legacy fixture exists with the same <strong>team pair</strong> (order-insensitive, alias-aware) — its id/URL is kept and scores/lineups/events/stats are filled in.</td>
					<td>Scores, penalties, lineups/subs/minutes, events, match stats, phase/group/jornada, venue link — details wiped &amp; rebuilt per match, so re-syncs are safe.</td>
					<td>No legacy fixture for that team pair (e.g. knockout rounds that legacy never pre-loaded) — created inside the linked competition.</td>
				</tr>
				<tr>
					<td><strong>Stadiums</strong><br><small>(venues)</small></td>
					<td>Name matches the index (title + mirror short/longnames).</td>
					<td>Photo/capacity/address/city/coordinates/opening — gaps only; mirror row columns; <code>/media/images/stadium/{id}.jpg</code> file if missing.</td>
					<td>No name match — created with offset id and enriched from <code>/venues/{id}/</code>. Safety net: every sync also runs a duplicate sweep on zero-name-match created stadiums — a GPS-proximity fallback (~1km, single-candidate-only) catches a venue that carries a genuinely different name per source (sponsorship rename, one-off event name), plus a curated known-pairs registry for cases where GPS alone is ambiguous (a second real venue also falls in range). A one-time-per-environment Nominatim geocode backfill fills coordinates on legacy stadiums that never had any, so the GPS signal is available broadly, not just where Statorium happened to enrich first.</td>
				</tr>
				<tr>
					<td><strong>Countries</strong></td>
					<td>The full EN→PT map (legacy spellings: Bahrein, Ilhas Fiji, Rep. Dominicana, Grã-Bretanha…) + canon aliases resolve to an existing f365_country term / mirror row. Expect <code>skipped=0</code>.</td>
					<td>Term metas (statorium id/ISO code, mirror row id) — additive only; duplicate terms/rows from older builds are self-healed (merged into legacy, flags kept).</td>
					<td>Country genuinely absent from legacy (micro-territories: Mónaco, Vaticano, Gronelândia…) — term + mirror row created with PT name.</td>
				</tr>
				<tr>
					<td><strong>Transfers</strong></td>
					<td>Never updated — a transfer is a fact. Natural key <strong>player + date + destination</strong> already present → skipped (and stamped as ours if unmarked).</td>
					<td>—</td>
					<td>Natural key absent → mirror row inserted (<code>api_source='statorium'</code> + import timestamp). Player/teams resolve through the player/team rules above first.</td>
				</tr>
			</tbody>
		</table>
		<p class="description">
			Where to see the outcome: the <strong>Statorium Data</strong> pages label every record <span style="color:#0a7d33">Created</span> or
			<span style="color:#996800">Linked legacy</span> with the match method and import day. Escape hatches: Statorium Data → Review Queue for
			queued decisions, Statorium Data → <strong>Merge Duplicates</strong> (2026-08-06) — search-by-name pickers on both sides (left:
			Statorium-created only, right: legacy only, so a wrong pick isn't possible), works purely on names so any admin can use it directly on
			whichever server they're looking at, no post IDs from another environment needed. This is the fallback for whatever a naming pattern
			no algorithm here has been taught yet — the acronym case ("RSC Anderlecht" vs "Royal Sporting Club Anderlecht", "FC Red Bull Salzburg"
			vs "Fußball Club Red Bull Salzburg") is exactly that; use this instead of waiting on a one-off code fix per pattern discovered. Every
			merge from this page is written to an audit log (admin, both titles, timestamp) shown right below the form — or the CLI directly:
			<code>resolve</code> / <code>merge-team</code> / <code>merge-player</code> / <code>merge-competition</code>, <code>rollback</code>
			(module/date-wise) to undo created data.
		</p>

		<h2>Modules &amp; API endpoints</h2>
		<p class="description">
			Base URL <code>https://api.statorium.com/api/v1</code>, auth via <code>?apikey=</code> (wp-config constant).
			Responses are transient-cached by the API client — except <code>/matches/live/</code> and <code>/updates/</code>, which are always fresh.
			Public ids everywhere = <code>90&nbsp;000&nbsp;000&nbsp;+ Statorium id</code> for created records, the original id for linked legacy records.
		</p>
		<table class="widefat striped" style="max-width:1100px">
			<thead><tr>
				<th style="width:150px">Module</th>
				<th style="width:260px">API endpoint(s)</th>
				<th style="width:220px">Called by / when</th>
				<th>What it writes</th>
			</tr></thead>
			<tbody>
				<tr>
					<td><strong>Leagues / onboarding</strong></td>
					<td><code>/leagues/</code> + <code>/leagues/{id}/</code></td>
					<td><code>leagues-map</code> command; start of every <code>sync</code>: auto-onboards newly activated leagues AND the <strong>annual season rollover</strong> of known leagues (current season not yet imported → imported automatically)</td>
					<td>League→federation map (settings table above); the <strong>subscription gate</strong> — seasons of leagues not in this list are never imported (<code>/updates/</code> leaks foreign leagues).</td>
				</tr>
				<tr>
					<td><strong>Competitions</strong><br><small>(seasons)</small></td>
					<td><code>/seasons/{id}/</code></td>
					<td><code>season</code> command; backfill; auto-onboarding</td>
					<td><code>f365_competition</code> post (auto-linked to legacy when federation+edition+name match) + mirror rows: <code>competition_competition</code>, <code>federation_federationcompetition</code>, phases (PT names), participants, season groups. When the Final finishes, the season is <strong>closed automatically</strong> (first/second_place_teamstep_id + is_closed — the Winners page; derived from the Final result by penalties → extra time → regulation, since the API has no winner field).</td>
				</tr>
				<tr>
					<td><strong>Matches</strong></td>
					<td><code>/matches/?season_id=</code> (bulk)<br><code>/todaymatches/?date=</code> (day cursor)</td>
					<td><code>matches</code> command; <code>backfill</code>/<code>today</code> walk day by day; <code>/updates/</code> dispatcher</td>
					<td><code>f365_match</code> posts (legacy fixtures paired by team-pair, ids/URLs kept) + <code>competition_match</code> rows (scores, phase, group, jornada) + the /jogos-em-direto/ header row and backing post. <strong>Fixture pairing runs in every <code>sync</code></strong> (and after backfill/today/matches): freshly-imported rows fold into the pre-loaded legacy skeleton of the same game — alias- and orientation-aware, so "Rep. Coreia"/"Coreia do Sul" and "Canada"/"Canadá" match. Without it the calendário showed both rows ("México 2-0 África do Sul" beside "México vs África do Sul"). Knockout stages get <strong>sequential match_round numbers</strong> continuing after the group rounds (groups 1–3 → Oitavos 4 → … → Final; legacy convention — the calendário sections by round; the API sends no round for knockouts, so it is derived from phase order after every matches import).
						<br><strong>competition_django_id bug fixed (2026-07-30):</strong> this column holds the FEDCOMP (federation_competition_id) public id — verified against legacy data, every Primeira Liga match carries 392 regardless of edition — and templates build <code>/competicao/{id}/</code> links straight off it (e.g. the stadium page's últimos/próximos jogos list). It was bound to the MATCH's own pub id instead on every match we ever created, producing a broken competition link everywhere that column is read. Fixed at the write site and re-derived for 2,658 existing rows (<code>repair</code> command); also added to the row's <code>ON DUPLICATE KEY UPDATE</code> clause so a future regression self-heals on next sync instead of sticking forever.</td>
				</tr>
				<tr>
					<td><strong>Match details</strong></td>
					<td><code>/matches/{id}/</code></td>
					<td><code>details</code> / <code>match-detail</code> commands; automatically when a tracked match finishes</td>
					<td>Lineups + minutes (<code>competition_matchplayer</code>), events (<code>…playerevent</code>), team stats (<code>…matchstats</code>), penalty shootouts, attendance + duration (mirror <code>assistance</code>/<code>duration</code>), squads/career rows — plus the live page´s event feed, Estatísticas and Ficha lineups. Referee crew fields exist in the API but arrive empty so far — captured as <code>statorium_referee*</code> meta the moment they populate (display wiring follows once real data exists).
						<br><small><strong>Penalty shootout score</strong> (<code>penalty_home_score</code>/<code>penalty_visitor_score</code>): the top-level <code>stages</code> array on <code>/matches/{id}/</code> (stageID&nbsp;4 = "Penalty Shootout", carries the final tally directly) is the PRIMARY source; per-player 'Penalty shootout scored/missed' events are the fallback (also still needed for the Ficha's per-kick detail — <code>stages</code> is aggregate-only). A disagreement between the two is logged ("PENALTY MISMATCH") rather than silently picked. <code>home_score_extra</code>/<code>visitor_score_extra</code> come from the match's own <code>extraTime</code> field (unrelated to <code>stages</code>, unchanged). The SEASON-level <code>periods</code> field (e.g. <code>[3,4]</code>) is a static schema declaration — "this competition's matches may include stage codes 3/4" — not per-match data; nothing to import from it.
						<br><strong>Event-name bug fixed (2026-07-30):</strong> the API sends <code>"Penalty goal"</code> for a scored penalty, never bare <code>"Penalty"</code> — the aggregator only recognized the latter, so every penalty-scored goal was silently dropped from <code>matchplayer.goals</code>/<code>goals_penalty</code> (undercounting the scoreboard by exactly one goal per such match — this is what blocked the "Melhor Marcador" ranking finalize's reconciliation gate). Also fixed: <code>"Second yellow"</code> (the dismissal marker fired alongside, not instead of, its own <code>"Yellow card"</code> event) was unhandled, so <code>double_yellow_card</code> under-reported second-booking send-offs, and <code>double_yellow_card_minute</code> was hardcoded <code>NULL</code> regardless of data — the player Jogos page's "AA" (Acumulação Amarelos) minute column was always blank. All three now correct; <code>wp f365-statorium details --force</code> re-syncs every previously-detailed match to correct the stored data (1,203 matches, run 2026-07-30).</small></td>
				</tr>
				<tr>
					<td><strong>Live scores</strong></td>
					<td><code>/matches/live/</code> <small>(never cached)</small></td>
					<td><code>tick</code> — every 2 min, only while a tracked match is inside its live window (cheap DB gate first, no API call otherwise)</td>
					<td>Live scores/status meta, <code>live_livematch</code> status (minute — prefers premium <code>matchTicker</code> — HT/RF) + goal flash; narrow cache purges.</td>
				</tr>
				<tr>
					<td><strong>Teams &amp; squads</strong></td>
					<td><code>/teams/{id}/?season_id=</code></td>
					<td><code>teams</code> command; season onboarding; <code>/updates/</code> team &amp; team_players events</td>
					<td><code>f365_team</code> posts + <code>team_team</code>/<code>team_teamstep</code> rows, squad/career tables, logo files (<code>/media/images/logotipo/100x100/{id}.gif</code>), team profile (city, founded, country, federation, stadium link, coach) — and every squad player via the player module. <strong>Teams created outside a season import</strong> (e.g. via the transfers feed) only get name/logo at creation; a per-sync heal (<code>heal-teams</code> command, 10/cycle) fetches the rest from <code>/teams/{id}/</code> (works without a season). City/founded/country/federation are written to BOTH the ACF postmeta (admin editor) and the <code>team_team</code> mirror row — the header and Info tab read the mirror directly, so both must be kept in sync.
						<br><small><strong>country_id</strong> (clubs only — nationals resolve instantly from the team name) prefers the stadium's own <code>country_id</code> mirror column (already correctly populated from legacy migration, or from Statorium's own venue enrichment) and only falls back to parsing the venue address (last segment, e.g. "…, Nice, <em>France</em>") when that's unset — best-effort, never guessed; unresolvable when the club has no venue or the address itself lacks a country (common on legacy street-only addresses). <strong>federation_id</strong> (all teams) = the domestic FA for clubs, the continental confederation (UEFA/CONMEBOL/CONCACAF/AFC/CAF/OFC — static FIFA membership map) for national teams; drives the <code>/federacao/{id}/</code> link on the team header.
						<br><strong>Club-league corrections (2026-07-30):</strong> (1) <code>team_type</code> now derives from the league scope — the old blanket <code>national</code> default typed every club-league team as a national side (98 repaired), broke the player pages' current-team join and minted bogus country terms from club names. (2) Domestic-league clubs get <code>country_id</code>/<code>federation_id</code> at import from their league federation (and a league-join gap-fill repair covers existing rows) — the venue fallback above remains for the transfers feed and international competitions. (3) The matcher now treats a KNOWN country mismatch as a contradiction for teams — Statorium "CS Marítimo" (Primeira Liga) had exact-name auto-linked the legacy <em>Venezuelan</em> CS Marítimo; relinked manually 2026-07-30, and this guard prevents the class. (4) <code>team_team.shortname/longname</code> were swapped for every created team (shortname held the full name "FC Porto" — correct, and what almost every template displays; longname held the API's bare club code "FCP" — wrong, and what the Info tab's "Nome:" field showed) — fixed, 131 existing rows corrected in place. (5) <code>wp_competition_participant.group_phase_one</code> — read by the classificação/equipas group filters to seed a group's roster — was never populated (34,899 existing rows backfilled from match data).
						<br><strong>Game Summary regression fix (2026-08-06):</strong> the team "Jogos" page's Game Summary box lists a team's competitions from <code>wp_competition_participant</code> (correctly resolved via the team's current teamstep — the template itself was never the bug). <code>merge_team()</code>'s teamstep-collision-safe fold (2026-08-06, above) repointed <code>competition_matchplayer</code>/<code>team_squad</code>/<code>competition_match</code>/<code>transfer_transfer</code> when folding a duplicate teamstep away, but missed <code>competition_participant</code> — so a merge that hit the fold branch (the legacy team already having its own teamstep for that edition, true for almost any actively-tracked club) silently dropped that competition from Game Summary the moment the duplicate teamstep was deleted, even though the competition's own page kept showing the team's fixtures fine (a different query, off <code>competition_match</code>, which <em>was</em> repointed). Also missed in the same fold: <code>competition_competition.first_place_teamstep_id</code>/<code>second_place_teamstep_id</code> — the same Palmarés-breaking gap fixed elsewhere today. Both are now repointed in <code>merge_team()</code>, <code>merge_competition()</code>, and <code>repair_group_pairing()</code> alike (all three fold teamsteps the same way); <code>repair_missing_participants()</code> (new, see the CLI table below) backfills any team already affected by a merge that ran before this fix.
						<br><strong>Equipa Técnica (2026-07-30):</strong> the Plantel/Histórico coaching-staff section reads <code>team_teamstepcoach</code>/<code>person_coach</code> — a different table from the <code>team_teamstep.tmp_coach</code> free-text column already filled from <code>additionalInfo.coach</code>. A minimal coach entity (name only — the payload carries no id/birthdate/nationality) is now created there too, reusing an existing coach by normalized name so the same manager isn't duplicated across clubs; never overwrites an existing (legacy or prior) staff row. <strong>Currently inert</strong> — every sampled team on this key returns an empty <code>coach</code> string; activates automatically once Statorium populates it (the live-minute <code>matchTicker</code> field went through this same "wired, no data yet" phase and is now confirmed live — verified 2026-08-06 against match 132647).
						<br><strong>Squad completeness (2026-08-06):</strong> <code>team_squad</code> was previously populated only by aggregating match appearances (<code>competition_matchplayer</code>) — a rostered player who hadn't featured in a detail-synced match yet had no squad row at all (Rangers' Plantel page showed 25 of 36 real players). <code>import_team_players()</code> now writes a squad row for every player in the <code>/teams/{id}/</code> roster directly, independent of match data. It also promotes/demotes the <code>order=0</code> "current club" flag on transfer/loan (a player could otherwise show as current at two clubs, or neither) and, after each roster sync, demotes — never deletes — any <code>order=0</code> row whose player is no longer in the current API roster, so departed players stop showing as active without losing their historical squad record.
						<br><strong>Teamstep-collision-safe merge (2026-08-06):</strong> <code>merge_team()</code> previously repointed a created team's <code>team_teamstep</code> rows straight to the legacy team id — if the legacy team already had its own row for that edition (true for almost any actively-tracked club), this minted an exact-duplicate teamstep on <em>every</em> merge, not just historically. It now folds per-teamstep: an existing legacy row for the same edition/step/modality/gender absorbs the created one's <code>matchplayer</code>/<code>squad</code>/match home-visitor/<code>transfer_transfer</code> references before the duplicate is deleted, otherwise it repoints as before. <code>merge_competition()</code> and <code>repair_group_pairing()</code> got the matching <code>transfer_transfer</code> repoint — without it, a folded teamstep orphaned its transfer rows and the same real transfer re-inserted as a "new" one on every subsequent sync.
						<br><strong>Fields NOT imported</strong> (confirmed absent from every Statorium payload): <code>url</code>, <code>email</code>, <code>phone</code>, <code>facebook</code>, <code>twitter</code>, <code>youtube</code>, <code>instagram</code>, <code>nickname</code>, <code>mascot</code> — editorial/directory data, not match-stats. The <code>team_team.logo</code> COLUMN (a legacy Django path string) is not written either — no template reads it; the real logo is the WP featured image. <code>edition</code> is a legacy "last-touched-by-importer" artifact, not a live field — not populated.</small></td>
				</tr>
				<tr>
					<td><strong>Players</strong></td>
					<td>squad payloads from <code>/teams/</code> and match lineups<br><code>/players/{id}/</code> <small>(diagnostics only: <code>why</code>, <code>audit-countries</code>)</small></td>
					<td>inside team/match imports — players are never fetched one-by-one for import (showstat data is redundant with what squads carry)</td>
					<td><code>f365_player</code> posts (numeric slugs) + <code>person_person</code>/<code>person_player</code> rows, position terms, country terms, photos. Matcher: name index + country/birthdate±1d/shirt signals + fuzzy fallback. <strong>Palmarés needs no import</strong> — the page DERIVES titles from squad membership × competition winners (first_place_teamstep_id), both of which the importer already writes; a title appears automatically when finalize closes the season.
						<br><small><strong>Mirror gap-fills (fixed 2026-07-30):</strong> the dados-pessoais page reads <code>person_player.height/weight/position</code> from the MIRROR, but a player first seen through a sparse payload (lineup, transfers feed) had his row inserted with NULLs and <code>INSERT IGNORE</code> ate every later value — enrichment fixed the postmeta only, so the page showed 0. <code>sync_person_rows</code> now gap-fills height/weight (any row) and refreshes position/shortname on OUR rows; the per-sync heal also selects players whose mirror height/weight is missing; a postmeta→mirror repair back-filled 378 existing rows without API calls. Not importable (absent from every payload): preferred foot, birth place, naturality, socials.</small></td>
				</tr>
				<tr>
					<td><strong>Stadiums</strong></td>
					<td><code>/venues/{id}/</code></td>
					<td><code>venues</code> command; automatically when a match/team references an unenriched venue</td>
					<td><code>f365_stadium</code> posts + <code>stadium_stadium</code> rows (capacity, address, city, coordinates, opening) + photo (featured image and <code>/media/images/stadium/{id}.jpg</code>).
						<br><small><strong>f365_country term fixed (2026-07-30):</strong> the stadium hero header's flag/country-name line reads the <code>f365_country</code> taxonomy term (<code>get_the_terms</code>) — no import path ever assigned it to a stadium post (only teams and players got it), so every created stadium's hero was silently missing its flag. <code>venueCity</code> is empty on most payloads and the API gives no separate country field either; the country is now derived from the last comma-segment of <code>venueAddress</code> (same helper already used for club country resolution) and set as the term + mirror <code>country_id</code> — 158 existing stadiums backfilled. City is deliberately NOT derived the same way: tested against real addresses, the "second-to-last segment" heuristic picks a PROVINCE instead of the city for Spanish addresses ("Gipuzkoa" instead of "Donostia") and a postcode-fused city for UK ones ("Manchester M11 3FF") — a wrong city is worse than a blank one, so it stays whatever <code>venueCity</code> sends. Also fixed in the same pass: <code>derive_country_from_address()</code> silently failed for any country whose PT spelling equals the English one (a `$pt !== $last` check couldn't tell "recognized, same spelling" from "not found") — <strong>including Portugal itself</strong>, the site's own country, so most Portuguese stadium/club addresses never resolved. Now uses <code>country_known()</code>, built for exactly this.</small></td>
				</tr>
				<tr>
					<td><strong>Countries</strong></td>
					<td><code>/countries/</code></td>
					<td><code>countries</code> command (one-time; re-run safe + self-healing)</td>
					<td><code>f365_country</code> terms (full PT map, legacy spellings) + <code>wp_country_country</code> mirror rows + Statorium id/ISO metas.</td>
				</tr>
				<tr>
					<td><strong>Transfers</strong></td>
					<td><code>/transfers/</code></td>
					<td>every delta-mode <code>sync</code>; <code>transfers</code> command</td>
					<td><code>wp_transfer_transfer</code> rows (type D/E, fee, teamsteps) — the table <code>/transferencias/</code> reads. Deduped by player+date+destination.</td>
				</tr>
				<tr>
					<td><strong>Odds</strong></td>
					<td><code>/matches/{id}/?include=odds</code></td>
					<td>every delta-mode <code>sync</code> for matches in the betting window (kickoff −3h…+48h); <code>odds</code> command</td>
					<td>Option map <code>f365_statorium_odds</code> (1x2 per public match id) → ticker cards' 1&nbsp;X&nbsp;2 row.</td>
				</tr>
				<tr>
					<td><strong>Delta engine</strong></td>
					<td><code>/updates/{unix_ts}/</code> <small>(never cached)</small></td>
					<td>every <code>sync</code> once backfill completes (state machine flips to delta mode automatically)</td>
					<td>Nothing directly — dispatches by element type: match → refresh (+detail on finish), player/team/team_players → team refresh, season/standings → matches re-import, season_teams → teams. Unknown types logged.</td>
				</tr>
				<tr>
					<td><strong>Standings</strong></td>
					<td><code>/standings/{id}</code></td>
					<td>not used in the sync cycle</td>
					<td>Nothing — classification is <strong>computed by the theme from match rows</strong> (phases + groups + jornadas we write); the endpoint stays available for future validation/point-deduction checks only.</td>
				</tr>
			</tbody>
		</table>

		<h2>Staging / deployment checklist</h2>
		<p>Everything below was executed on the development environment — replicate according to which database staging starts from.</p>
		<table class="widefat striped" style="max-width:1100px">
			<thead><tr><th style="width:60px">#</th><th>Step</th><th>Command / action</th></tr></thead>
			<tbody>
				<tr><td colspan="3"><strong>Always (any scenario)</strong></td></tr>
				<tr><td>1</td><td>Deploy code</td><td>Copy plugin folders <code>f365-statorium</code> + <code>f365-cache</code> AND the theme (contains the MySQL&nbsp;8 fix in <code>template-parts/competition/f365-competition-classificacao.php</code> — <code>groups</code> alias is reserved in MySQL&nbsp;8, without it group classification tables render empty; the live-page kickoff-time fix in <code>single-f365_livegames.php</code> — <code>wp_date()</code> shifted stored local time by the site timezone, so live and ficha pages disagreed on kickoff for ALL matches; AND the club-logo fix in <code>template-parts/team/f365-team-header.php</code> — the crest lookup was wrongly nested inside a country-name check that only ever passes for national teams, so every CLUB team's logo — correctly imported, just never displayed — was invisible).</td></tr>
				<tr><td>2</td><td>API key</td><td>Add to wp-config.php: <code>define( 'F365_STATORIUM_API_KEY', '…' );</code></td></tr>
				<tr><td>3</td><td>Activate</td><td>Activating the plugin creates the <code>api_source</code>/<code>api_id</code> columns, the review table, and persists the shipped settings (seasons 614, fill-gaps, auto review, ticker on). The schema migration also auto-prunes redundant <code>statorium_*_id</code> postmetas from older builds — identity lives in the indexed columns; only three small state metas remain (<code>statorium_status_id</code>, <code>statorium_detail_synced</code>, <code>statorium_venue_synced</code>). It also purges any Permalink&nbsp;Manager URIs from the entity CPTs and the plugin permanently disables PM for them (<code>permalink_manager_disabled_post_types</code>) — their URLs are numeric rewrites (<code>/jogador/{id}/</code>); if a created player/team ever shows a slug URL (jogador/salem-abdullah), the plugin build is outdated. It also merges duplicate PT position terms (Guarda-Redes/Defesa/Médio/Avançado) into the legacy english terms (goalkeeper/defender/midfielder/forward) that the theme and <code>/posicao/</code> pages use. <strong>Position convention:</strong> the API sends positions as strings (<code>/teams/</code>: "Attacker") or numeric codes (<code>/players/</code>: 1&nbsp;GK / 2&nbsp;DF / 3&nbsp;MF / 4&nbsp;FW); both are mapped to the legacy values — <code>forward</code> in the mirror tables is CORRECT (146k legacy rows use it; storing the API's "Attacker" literally would break the templates). Activation order vs futebol365 does <strong>not</strong> matter: if futebol365 is already active its legacy fallback module wins that one request and the schema/settings setup runs automatically on the next page load (collision guard in <code>f365-statorium.php</code> — no deactivate/reactivate dance). After any activate/deactivate cycle of futebol365 itself, re-save Permalinks (or <code>wp rewrite flush</code>) — its custom URLs (/jogo/, /equipa/, /competicao/…) 404 if the rules were flushed while it was inactive.</td></tr>
				<tr><td>4</td><td>Cron</td><td>System cron, flock-guarded: <code>sync</code> every 10 min + <code>tick</code> every 2 min — see the exact production crontab in the CLI table below. WP-Cron stays off (no settings-page toggle for it in this build).</td></tr>

				<tr><td colspan="3"><strong>Scenario A — staging DB is a clone of THIS environment (data already imported/merged)</strong></td></tr>
				<tr><td>A1</td><td>Media files</td><td>Automatic — the first <code>sync</code> detects missing <code>/media/images/{logotipo,stadium}/</code> files and backfills them from the API (re-runs the same idempotent <code>teams --no-players</code> + <code>venues</code> calls under the hood, skipping any file that already exists). No manual step needed; run <code>wp f365-statorium status</code> to confirm the checklist item cleared.</td></tr>
				<tr><td>A2</td><td>Verify</td><td><code>wp f365-statorium status</code> → mode delta, maps populated; check Statorium Data → Competition Duplicates shows none; purge caches.</td></tr>

				<tr><td colspan="3"><strong>Scenario B — staging DB is the OLD production DB (no Statorium data yet)</strong></td></tr>
				<tr><td>B1</td><td>One-time mappings</td><td><code>wp f365-statorium countries</code> (f365_country terms + wp_country_country mirror) then <code>wp f365-statorium index-names</code> (matcher name index) then <code>wp f365-statorium leagues-map</code>.<br>
					<small><strong>index-names</strong> indexes each entity under ALL its name forms — post title plus shortname/longname/fullname from post metas AND the mirror tables (legacy team titles are shortnames like "Ac. Viseu"; the API sends official names like "Académico de Viseu Futebol Clube" = the legacy longname). <strong>Re-run it once after every plugin update</strong> — it is idempotent and the next <code>sync</code> auto-merges any duplicate teams AND players the richer index reveals. Player auto-merge is strict (exactly one legacy name-twin + both birthdates known + within 1 day — Statorium and legacy data often disagree by one day on the same person's DOB, which older builds treated as different people and duplicated); ambiguous cases are logged for manual <code>merge-team</code> / <code>merge-player --from-wp=&lt;created&gt; --into-wp=&lt;legacy&gt;</code>.</small><br>
					<small>The country map covers the full /countries/ list with legacy spellings (Bahrein, Ilhas Fiji, Rep. Dominicana, Grã-Bretanha…), so everything <strong>pre-maps</strong> to existing terms/rows; only micro-territories with no legacy row get created (PT names) — expect <code>skipped=0</code>. Re-running is safe and also <strong>self-heals duplicates</strong> left by older builds (Barém/Fiji/Guiné/Trindade…): posts are re-tagged to the legacy term, flags kept, duplicate terms + mirror rows merged and deleted, team/person/stadium references repointed. If a DB was touched by an old build, just re-run <code>countries</code> once after updating the plugin.</small></td></tr>
				<tr><td>B2</td><td>Import the World Cup</td><td><code>wp f365-statorium season --season=614</code> → auto-links to legacy "Mundial"; then <code>teams --season=614</code> (squads + photos, heavy) and <code>matches --season=614</code> (fixtures auto-pair into the legacy skeletons) and <code>details</code> (lineups/events/stats/penalties).</td></tr>
				<tr><td>B3</td><td>Duplicates check</td><td>Only if the DB was ever touched by an OLD plugin build: Statorium Data → Competition Duplicates lists them with a one-click <em>Merge into legacy</em>.</td></tr>
				<tr><td>B4</td><td>Country flags</td><td>Automatic — the setup checklist attaches the theme's <code>assets/images/flags/{iso2}.svg</code> as the term's <code>country_flag</code> ACF field for any country term <code>countries</code> mapped that has none yet (almost always a brand-new term; legacy terms already carry theirs). No manual step needed.</td></tr>
				<tr><td>B4b</td><td>Live pages</td><td>Automatic — the setup checklist backfills /jogos-em-direto/ coverage (live rows + posts + goal/card event feeds + Estatísticas + Ficha lineups) for every already-imported match on the first <code>sync</code>, from mirror data (no API calls). New imports get it automatically too; during live matches the 2-min <code>tick</code> updates score/minute/goal-flash. <code>wp f365-statorium live-backfill</code> still works standalone if you want to force a re-check.</td></tr>
				<tr><td>B5</td><td>Transfers</td><td><code>wp f365-statorium transfers</code> — imports the global /transfers/ feed into <code>wp_transfer_transfer</code> (deduped, re-run safe; afterwards it runs automatically inside every delta-mode <code>sync</code>).</td></tr>
				<tr><td>B6</td><td>Verify pages</td><td><code>/competicao/2/?edition=2026</code> (Mundial: jogos + classificação groups), a match ficha (e.g. <code>/jogo/1178369/</code>), a player (<code>/jogador/90039646/</code>), a stadium (<code>/estadio/90001175/</code>), <code>/transferencias/</code> (today's transfers on page 1 — bypass WP&nbsp;Rocket with <code>?nowprocket=1</code>), and the header ticker tabs. Purge caches after imports.</td></tr>
			</tbody>
		</table>
		<p class="description">One-time repairs run on dev (<code>repair</code>, <code>repair-teams</code>, <code>review-rescore</code>, team/competition merges, PT-language fixes) are <strong>not needed</strong> on a fresh import — current code prevents those issues; the commands remain available if an old-build database is encountered.</p>

		<h2>Odds (1x2) — how it works</h2>
		<table class="widefat striped" style="max-width:1100px">
			<tbody>
				<tr><td style="width:180px"><strong>Source</strong></td>
					<td><code>/matches/{id}/?include=odds</code> — the premium add-on returns a <strong>top-level <code>betting.bookmakers</code> node</strong> (NOT <code>match.odds</code>): per bookmaker a 1x2 market with Home/Draw/Away decimal odds + a timestamp.</td></tr>
				<tr><td><strong>Which matches</strong></td>
					<td>The "betting window": kickoff within the <strong>next 48 hours</strong>, or started less than <strong>3 hours ago</strong> (covers live). One API call per match in the window, throttled.</td></tr>
				<tr><td><strong>When</strong></td>
					<td>Automatically inside every delta-mode <code>sync</code> (hourly system cron) — after the /updates/ poll and transfers. Manual: <code>odds</code> command or the settings-page button.</td></tr>
				<tr><td><strong>Bookmaker choice</strong></td>
					<td>Betclick → Bet365 → first available (filter-friendly: the full node is fetched, only the chosen trio is stored). Bookmaker name shows as tooltip on the ticker row.</td></tr>
				<tr><td><strong>Storage</strong></td>
					<td>One option, <code>f365_statorium_odds</code> (autoload off), keyed by <strong>public match id</strong>: <code>{ "1": 3.20, "x": 3.40, "2": 1.82, "bk": "Betclick", "ts": … }</code>. No postmeta, no extra queries — the ticker reads the whole map with a single <code>get_option</code>. Entries not refreshed for 4 days are pruned automatically.</td></tr>
				<tr><td><strong>Display</strong></td>
					<td>Cell priority: <strong>1st odds, 2nd score</strong> — on the <em>em direto</em> / <em>próximos</em> / <em>na televisão</em> tabs the score cell shows the 1x2 odds whenever the map has the match (stacked 1/X/2, bookmaker in the tooltip); otherwise the score/dash. <em>Terminados</em> always shows the final score — odds on a completed match would mislead. Legacy skeleton fixtures (not yet api-mapped, e.g. Brasileirão before activation) keep the dash until the league activates.</td></tr>
				<tr><td><strong>Verify on staging</strong></td>
					<td><code>wp f365-statorium odds --match=139563</code> (forces a WC match, any status) then check <code>option get f365_statorium_odds</code> has the entry; with real upcoming fixtures the row appears in the header ticker within one sync cycle.</td></tr>
			</tbody>
		</table>

		<h2>CLI / system cron commands</h2>
		<?php $wp_path = untrailingslashit( ABSPATH ); ?>
		<table class="widefat striped" style="max-width:1100px">
			<thead><tr><th style="width:220px">Purpose</th><th>Command</th><th style="width:180px">Suggested schedule</th></tr></thead>
			<tbody>
				<tr>
					<td><strong>Main sync</strong><br><small>backfill batch or /updates/ poll, per current mode</small></td>
					<td><code>wp f365-statorium sync --path="<?php echo esc_html( $wp_path ); ?>"</code></td>
					<td>every 10 min (production; flock-guarded, see crontab below) — every 1–2h is also fine on a lower-traffic environment</td>
				</tr>
				<tr>
					<td><strong>Sync from a specific date</strong><br><small>walks day by day from the date up to today, then returns to delta mode automatically; add <code>--days=N</code> to limit the batch. Optional <code>--into=&lt;legacy comp id&gt;</code> folds the season into the legacy competition afterwards — safe always: if it is already auto-linked it reports "nothing to merge"; if an older build created a duplicate it merges it. Use <code>--into=12911</code> for the Mundial. If a duplicate keeps appearing, the one-time mappings were skipped — run <code>countries</code> / <code>index-names</code> / <code>leagues-map</code> (B1) first: the auto-link needs the league→federation map to identify the legacy competition.</small></td>
					<td><code>wp f365-statorium backfill --from=2026-07-01 --details --into=12911 --path="<?php echo esc_html( $wp_path ); ?>"</code></td>
					<td>on demand</td>
				</tr>
				<tr>
					<td><strong>One specific day only</strong><br><small>cursor untouched; omit <code>--date</code> for today. Optional <code>--into=&lt;legacy comp id&gt;</code> works the same as on backfill (fold/verify season link afterwards — safe when already linked)</small></td>
					<td><code>wp f365-statorium today --date=2026-07-15 --details --into=12911 --path="<?php echo esc_html( $wp_path ); ?>"</code></td>
					<td>on demand / daily</td>
				</tr>
				<tr>
					<td><strong>Live-score ticker</strong><br><small>no API call outside live windows</small></td>
					<td><code>wp f365-statorium tick --path="<?php echo esc_html( $wp_path ); ?>"</code></td>
					<td>every 2 minutes</td>
				</tr>
				<tr>
					<td><strong>Map countries</strong><br><small>attaches Statorium country ids/ISO codes to f365_country terms (PT names)</small></td>
					<td><code>wp f365-statorium countries --path="<?php echo esc_html( $wp_path ); ?>"</code></td>
					<td>one time (re-run safe)</td>
				</tr>
				<tr>
					<td><strong>Transfers</strong><br><small>imports the /transfers/ rolling feed into <code>wp_transfer_transfer</code>; deduped, safe to re-run. Also runs automatically inside <strong>every</strong> <code>sync</code> (any mode)</small></td>
					<td><code>wp f365-statorium transfers --path="<?php echo esc_html( $wp_path ); ?>"</code></td>
					<td>on demand (auto via sync)</td>
				</tr>
				<tr>
					<td><strong>Live-pages backfill</strong><br><small>bulk one-time convenience (/jogos-em-direto/ rows + posts + event feeds + Estatísticas + Ficha lineups from mirror data, no API) — but plain <code>sync</code> now also <strong>self-heals</strong>: every cycle fills any live game whose Events/Ficha/Estatísticas are empty while its match detail exists in the mirror (batched 50/run, "Filled N live game(s)" in the output). New imports are covered automatically at creation.</small></td>
					<td><code>wp f365-statorium live-backfill --path="<?php echo esc_html( $wp_path ); ?>"</code></td>
					<td>one time (re-run safe)</td>
				</tr>
				<tr>
					<td><strong>Odds (1x2)</strong><br><small>fetches odds for matches in the betting window; also runs automatically inside every delta-mode <code>sync</code>. <code>--match=&lt;stat id&gt;</code> forces one match (any status — testing)</small></td>
					<td><code>wp f365-statorium odds --path="<?php echo esc_html( $wp_path ); ?>"</code></td>
					<td>auto via sync / on demand</td>
				</tr>
				<tr>
					<td><strong>Audit linked-player countries</strong><br><small>compares every linked player's legacy nationality vs the API. Spelling variants auto-ignored; birthdate-confirmed differences are dual-nationality/data-convention cases (legacy kept per fill-gaps); SUSPECT lines (no birthdate proof) may be wrong links — check each with <code>why</code>, unlink if wrong. ~1 API call per linked player.</small></td>
					<td><code>wp f365-statorium audit-countries --path="<?php echo esc_html( $wp_path ); ?>"</code> <small>(optional <code>--limit=N</code>)</small></td>
					<td>on demand</td>
				</tr>
				<tr>
					<td><strong>Diagnose a duplicate</strong><br><small>prints exactly why a player/team/match links or creates on THIS environment: build + db version, name-index health, stored identity, review state, API signals, and every candidate with score/contradiction. Attach this output to any duplicate report.</small></td>
					<td><code>wp f365-statorium why --type=player --id=13368 --path="<?php echo esc_html( $wp_path ); ?>"</code></td>
					<td>on demand</td>
				</tr>
				<tr>
					<td><strong>Drain sparse player profiles</strong><br><small>players created outside a squad payload (transfers feed) only have name/photo — <code>sync</code> enriches 20/cycle from <code>/players/{id}/</code>; use this to catch up immediately after a big import. Players the API has no birthdate for are auto-rechecked weekly, not hammered every cycle.</small></td>
					<td><code>wp f365-statorium heal-players --all --path="<?php echo esc_html( $wp_path ); ?>"</code></td>
					<td>on demand (auto: 20/sync)</td>
				</tr>
				<tr>
					<td><strong>Drain sparse team profiles</strong><br><small>same idea for teams (city/founded/stadium from <code>/teams/{id}/</code>, works without a season) — <code>sync</code> enriches 10/cycle.</small></td>
					<td><code>wp f365-statorium heal-teams --all --path="<?php echo esc_html( $wp_path ); ?>"</code></td>
					<td>on demand (auto: 10/sync)</td>
				</tr>
				<tr>
					<td><strong>Repair data-quality issues</strong><br><small>corrects <code>foundation</code> dates written by an older buggy parser (garbled strings like "07-06-1914  (112)" instead of a clean date — affects legacy AND created teams), pushes already-fetched city/foundation from postmeta into the <code>team_team</code> mirror row (what the team header/Info tab actually read), creates any missing <code>wp_stadium_stadium</code> mirror row (venues discovered only via a team's home venue never got one before), resolves team <code>country_id</code>/<code>federation_id</code> from already-stored data (no API calls), backfills any <code>competition_participant</code> row missing versus what <code>competition_match</code> proves actually happened (<code>repair_missing_participants()</code>, 2026-08-06 — the Game Summary regression fix's backlog cleaner; also runs automatically every <code>sync</code>, not just here), plus the pre-existing shortname/link-classification repairs. Re-run safe.</small></td>
					<td><code>wp f365-statorium repair --path="<?php echo esc_html( $wp_path ); ?>"</code></td>
					<td>after updating the plugin</td>
				</tr>
				<tr>
					<td><strong>Rollback (module / date wise)</strong><br><small>Deletes Statorium-<em>created</em> data only — posts, their mirror-table rows (ids in the created range only), sideloaded images, review rows, id-map entries. Legacy data is never deleted. <code>--unlink</code> additionally unlinks legacy posts in scope (metas + api columns removed; gap-filled values stay). <strong>Without <code>--yes</code> it is a DRY RUN preview.</strong></small></td>
					<td>
						<code>wp f365-statorium rollback --module=transfers --day=2026-07-23</code><br>
						<code>wp f365-statorium rollback --module=player --from=2026-07-20 --to=2026-07-23 --yes</code><br>
						<code>wp f365-statorium rollback --module=all --yes</code><br>
						<small>modules: all, match, player, team, stadium, competition, transfers, countries · dates filter on the <em>import</em> day (see the day pills on the Statorium Data pages); for <strong>transfers</strong> the transfer's own date also matches. A date that matches nothing prints a hint — run without dates to preview everything.</small>
					</td>
					<td>on demand</td>
				</tr>
			</tbody>
		</table>
		<p class="description">
			<strong>Production crontab (2026-08-06) — use this, not the plain example that used to be here:</strong><br>
			<code>*/10 * * * * cd <?php echo esc_html( $wp_path ); ?> &amp;&amp; flock -n /tmp/f365-sync.lock /usr/local/bin/wp f365-statorium sync --allow-root &gt;&gt; <?php echo esc_html( $wp_path ); ?>/wp-content/uploads/f365-logs/sync.log 2&gt;&amp;1</code><br>
			<code>*/2 * * * * cd <?php echo esc_html( $wp_path ); ?> &amp;&amp; flock -n /tmp/f365-tick.lock /usr/local/bin/wp f365-statorium tick --allow-root &gt;&gt; <?php echo esc_html( $wp_path ); ?>/wp-content/uploads/f365-logs/tick.log 2&gt;&amp;1</code><br>
			<strong>Why <code>flock -n</code>:</strong> a heavy <code>sync</code> (big backfill batch, a burst of detail syncs) can occasionally run past its own 10-minute slot; without a lock the next cron tick would start a second overlapping <code>sync</code> against the same DB. <code>flock -n /tmp/f365-sync.lock …</code> makes cron skip that tick entirely (silently, exit code non-zero, nothing logged) if the previous run is still holding the lock — safe by construction, no stacked runs, no manual monitoring needed. Give <code>sync</code> and <code>tick</code> separate lock files (as above) — they're independent and should never block each other.<br>
			Adjust <code>/usr/local/bin/wp</code> to wherever <code>wp</code> actually resolves on the server (<code>which wp</code>); use an absolute path — cron's <code>$PATH</code> is minimal and won't find it otherwise. <code>--allow-root</code> is only needed when the cron job itself runs as root (common on managed hosts); drop it if cron runs as the site's own user. The explicit <code>&gt;&gt; …/sync.log</code> / <code>tick.log</code> redirect (rather than <code>/dev/null</code>) keeps a persistent on-disk trail alongside the plugin's own dated <code>f365-statorium-YYYY-MM-DD.log</code> — useful for confirming cron itself is firing, separate from what each run actually did. Add <code>--skip-plugins=wp-rocket</code> to either command on environments where WP Rocket hangs WP-CLI. The plugin's internal WP-Cron scheduling (<code>f365_statorium_reschedule_cron()</code>, the <code>cron</code>/<code>cron_interval</code> settings) ships disabled by default and has no settings-page toggle in this build (deliberately — see the comment in <code>admin_post_f365stat_save</code>) — nothing to turn off before adding this crontab; it IS the scheduler.
		</p>

		<h2>Manual actions</h2>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=f365stat_run&what=backfill' ), 'f365stat_run' ) ); ?>">Run backfill batch (14 days)</a>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=f365stat_run&what=poll' ), 'f365stat_run' ) ); ?>">Poll /updates/ now</a>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=f365stat_run&what=index' ), 'f365stat_run' ) ); ?>">Rebuild name index</a>
		</p>

		<p class="description">Log: <code>wp-content/uploads/f365-logs/f365-statorium-YYYY-MM-DD.log</code></p>
	</div>
	<?php
}

add_action( 'admin_post_f365stat_set_cursor', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'f365stat_set_cursor' );

	$date = sanitize_text_field( (string) ( $_POST['cursor_date'] ?? '' ) );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
		wp_safe_redirect( f365_statorium_admin_url( [ 'f365stat_msg' => rawurlencode( 'Invalid date — use YYYY-MM-DD.' ) ] ) );
		exit;
	}

	F365_Statorium_Backfill::set_cursor( $date );
	wp_safe_redirect( f365_statorium_admin_url( [ 'f365stat_msg' => rawurlencode( "Backfill cursor set to {$date}. Click \"Run backfill batch\" below (or wait for the next sync cycle) to process from there." ) ] ) );
	exit;
} );

add_action( 'admin_post_f365stat_save', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'f365stat_save' );

	$settings = F365_Statorium_Importer::settings();
	update_option( F365_Statorium_Importer::OPTION, [
		'api_key'       => isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['api_key'] ) ) : $settings['api_key'],
		// No longer form fields — the tracked-seasons list is auto-managed
		// (remember_season()/onboarding, view it on Statorium Data →
		// Seasons), and the WP-Cron toggle was misleading since production
		// drives sync via system cron calling `wp f365-statorium sync`
		// directly (see the manual-actions table below). Preserve whatever
		// is already there instead of wiping it on save.
		'seasons'       => (string) $settings['seasons'],
		'cron'          => (int) $settings['cron'],
		'cron_interval' => (string) $settings['cron_interval'],
		'update_mode'   => 'fill-gaps', // fixed policy — Strict/Full disabled
		'review_mode'   => $settings['review_mode'] ?? 'auto',
		'ticker_ui'       => empty( $_POST['ticker_ui'] ) ? 0 : 1,
		'hide_empty_tabs' => empty( $_POST['hide_empty_tabs'] ) ? 0 : 1,
	], false );

	f365_statorium_reschedule_cron();

	wp_safe_redirect( f365_statorium_admin_url( [ 'f365stat_msg' => rawurlencode( 'Settings saved.' ) ] ) );
	exit;
} );

add_action( 'admin_post_f365stat_run', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'f365stat_run' );

	@set_time_limit( 900 );
	$what = sanitize_key( (string) ( $_GET['what'] ?? '' ) );
	$msg  = f365_statorium_run_checklist_step( $what );

	wp_safe_redirect( f365_statorium_admin_url( [ 'f365stat_msg' => rawurlencode( $msg ) ] ) );
	exit;
} );

add_action( 'admin_post_f365stat_save_leagues', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'f365stat_leagues' );

	$map = f365_statorium_league_map();
	foreach ( $map as $league_id => $entry ) {
		$fed = isset( $_POST['fed'][ $league_id ] ) ? (int) $_POST['fed'][ $league_id ] : (int) $entry['federation_wp'];
		if ( $fed && 'f365_federation' !== get_post_type( $fed ) ) {
			$fed = 0; // reject non-federation IDs
		}
		$changed = $fed !== (int) $entry['federation_wp'];

		$map[ $league_id ]['federation_wp'] = $fed;
		if ( $changed ) {
			$map[ $league_id ]['manual'] = 1; // survives future auto-remaps
		}
	}
	update_option( F365_STAT_LEAGUE_MAP_OPTION, $map, false );

	wp_safe_redirect( f365_statorium_admin_url( [ 'f365stat_msg' => rawurlencode( 'League mapping saved.' ) ] ) );
	exit;
} );

add_action( 'admin_post_f365stat_merge_comp', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'f365stat_merge_comp' );

	@set_time_limit( 900 );
	$season = (int) ( $_GET['season'] ?? 0 );
	$into   = (int) ( $_GET['into'] ?? 0 );

	$importer = new F365_Statorium_Importer();
	$sum      = $importer->merge_competition( $season, $into );
	if ( ! isset( $sum['error'] ) ) {
		$sum['repaired'] = $importer->repair_group_pairing( $into );
	}

	$msg = isset( $sum['error'] )
		? 'Merge aborted: ' . $sum['error']
		: 'Competition merged: ' . wp_json_encode( $sum );
	wp_safe_redirect( add_query_arg(
		[ 'page' => 'f365-statorium-data-duplicates', 'f365stat_msg' => rawurlencode( $msg ) ],
		admin_url( 'admin.php' )
	) );
	exit;
} );

/**
 * Manual merge (Statorium Data → Merge Duplicates) — the admin-driven
 * counterpart to the automatic dedup sweeps (auto_merge_duplicate_teams/
 * players/stadiums, which run every sync). Those only catch a duplicate when
 * the name comparison they know how to do (exact / generic-word-stripped
 * fuzzy) actually matches. A real-world pair can still slip past both — an
 * acronym vs its expansion ("RSC Anderlecht" vs "Royal Sporting Club
 * Anderlecht"), or any other name divergence no algorithm was written for —
 * with zero automatic signal (not even logged as "ambiguous", since the
 * matcher never found a candidate to begin with). This handler is the
 * general-purpose fallback: an admin who spots a duplicate by eye on the
 * frontend picks both sides by hand and merges them, no code change needed.
 * Reuses the exact same merge_team()/merge_player()/merge_stadium() methods
 * the CLI's merge-team/merge-player/merge-stadium commands call — same
 * data-safety guarantees (is_legacy() direction check, fold-not-overwrite),
 * just reachable without SSH access.
 */
add_action( 'admin_post_f365stat_manual_merge', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'f365stat_manual_merge' );

	@set_time_limit( 300 );
	$type       = sanitize_key( (string) ( $_POST['type'] ?? '' ) );
	$created_id = (int) ( $_POST['created_id'] ?? 0 );
	$legacy_id  = (int) ( $_POST['legacy_id'] ?? 0 );

	$methods = [ 'team' => 'merge_team', 'player' => 'merge_player', 'stadium' => 'merge_stadium' ];
	if ( ! isset( $methods[ $type ] ) || ! $created_id || ! $legacy_id ) {
		$msg = 'Merge aborted: missing or invalid parameters.';
	} else {
		// Titles must be read BEFORE merging — the source post is deleted by
		// merge_team()/merge_player()/merge_stadium() itself, so the audit
		// log row is the only place this pairing survives afterwards.
		$source_title = get_the_title( $created_id );
		$target_title = get_the_title( $legacy_id );

		$importer = new F365_Statorium_Importer();
		// Same abort-on-wrong-direction guard the CLI relies on — merge_*()
		// itself refuses (created_id must be non-legacy, legacy_id must be
		// legacy) and just logs+returns false, so give a clear reason here
		// rather than a silent "nothing happened".
		$ok = call_user_func( [ $importer, $methods[ $type ] ], $created_id, $legacy_id );
		if ( $ok ) {
			$user = wp_get_current_user();
			F365_Statorium_Importer::record_manual_merge(
				$type, $created_id, $source_title, $legacy_id, $target_title,
				(int) $user->ID, (string) $user->user_login
			);
			$msg = ucfirst( $type ) . " merged: \"{$source_title}\" → \"{$target_title}\".";
		} else {
			$msg = "Merge aborted: \"{$source_title}\" must be a Statorium-created record and \"{$target_title}\" must be a legacy record (see the log for detail).";
		}
	}

	wp_safe_redirect( add_query_arg(
		[ 'page' => 'f365-statorium-data-merge', 'type' => $type, 'f365stat_msg' => rawurlencode( $msg ) ],
		admin_url( 'admin.php' )
	) );
	exit;
} );

add_action( 'admin_post_f365stat_resolve', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'f365stat_resolve' );

	global $wpdb;
	$row_id = (int) ( $_GET['id'] ?? 0 );
	$row    = $row_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . F365_Statorium_Matcher::review_table() . ' WHERE id = %d', $row_id ) ) : null;
	if ( ! $row ) {
		wp_die( 'Review row not found.' );
	}

	$wp_id    = (int) ( $_GET['wp_id'] ?? 0 );
	$decision = sanitize_key( (string) ( $_GET['decision'] ?? '' ) );

	if ( $wp_id ) {
		$importer = new F365_Statorium_Importer();
		$importer->map_set( $row->entity_type, (int) $row->stat_id, $wp_id );
		update_post_meta( $wp_id, '_f365_statorium_linked', 1 );
		update_post_meta( $wp_id, '_f365_match_method', 'manual-review' );
		F365_Statorium_Matcher::set_review_status( $row_id, 'linked' );
		$msg = "Linked stat_id={$row->stat_id} → wp_id={$wp_id}.";
	} elseif ( 'create' === $decision ) {
		F365_Statorium_Matcher::set_review_status( $row_id, 'create' );
		$msg = 'Marked for creation on next sync.';
	} elseif ( 'ignore' === $decision ) {
		F365_Statorium_Matcher::set_review_status( $row_id, 'ignored' );
		$msg = 'Ignored.';
	} else {
		$msg = 'No action taken.';
	}

	wp_safe_redirect( add_query_arg(
		[ 'page' => 'f365-statorium-data-review', 'f365stat_msg' => rawurlencode( $msg ) ],
		admin_url( 'admin.php' )
	) );
	exit;
} );
