# F365 Statorium Sync — context

Statorium Stats API → Futebol365 WordPress integration. Historical backfill, entity
matching against pre-existing (Django-migrated) data, delta sync, live ticker,
transfers, odds. PHP 8.1+, ~9.3k LOC across 13 files, no build step, no dependencies
beyond WordPress + ACF.

API key: `F365_STATORIUM_API_KEY` in `wp-config.php` (settings field is a fallback).
Logs: `wp-content/uploads/f365-logs/f365-statorium-YYYY-MM-DD.log` (also echoed to
WP-CLI). Settings screen: **Settings → Statorium Sync**. Audit screens: **Statorium
Data** (one page per module).

## The hard part

This site was migrated from Django/PostgreSQL. **The frontend templates read the
cloned Django mirror tables (`wp_competition_match`, `wp_team_team`,
`wp_person_person`, `wp_live_*`, …), not postmeta.** Postmeta/ACF only feeds the
admin editor. So every import writes twice: WP posts+meta *and* mirror rows. If a
frontend page renders blank, the mirror row is missing — not the meta.

Second constraint: legacy data already exists and must never be corrupted. Imports
resolve to an existing post where possible instead of creating one.

## File map

| File | Role |
|---|---|
| `f365-statorium.php` | Bootstrap, activation, load-order collision guard, Permalink Manager opt-out |
| `includes/class-api.php` | HTTP client over `wp_remote_get`: API-key auth, per-endpoint transient TTLs, 3-attempt retry |
| `includes/class-matcher.php` | Existing-record detection: normalized-name index + signal scoring → link/review/create |
| `includes/class-importer.php` | 4.1k lines. Everything: entity upserts, ownership-aware writes, merges, heals, repairs, rollback, country maps |
| `includes/class-tables.php` | Mirror-table writer (the layer the frontend actually reads) |
| `includes/class-backfill.php` | Day-cursor historical walk over `/todaymatches/`; state machine |
| `includes/class-updates.php` | `/updates/{ts}/` delta poller, table-driven dispatch |
| `includes/leagues.php` | League → federation map, per-league flags, auto-onboarding/rollover |
| `includes/ticker.php` | Header 4-tab match ticker (shortcode + `wp_footer` injection) |
| `includes/cron.php` | `f365_statorium_sync` (configurable) + `f365_statorium_tick` (2 min, DB-gated) |
| `includes/class-command.php` | `wp f365-statorium <subcommand>` — 35 subcommands |
| `includes/admin.php` | Settings screen, auto-detected setup checklist, manual actions, review queue |
| `includes/admin-data.php` | Per-module audit pages: what was imported/linked on which day |

## Identity & ID conventions

Three ID spaces coexist — mixing them up is the most common bug class here.

- **Statorium id** — the API's id. Stored in the indexed `wp_posts.api_id` column
  with `api_source='statorium'`. `map_resolve($type, $stat_id)` is the only lookup.
- **Public id** (`pub`) — what appears in URLs and mirror-table primary keys. For
  linked legacy posts it is the original Django id; for posts we created it is
  `ID_OFFSET + statorium_id` where `ID_OFFSET = 90000000`. So **`pub < 90000000`
  means legacy, `>= 90000000` means ours.** Also written to
  `wp_posts.legacy_id`/`legacy_table` (the URL resolvers read the *column*, not meta)
  and duplicated in `django_*_id` meta.
- **WP post ID** — internal only.

Stadium quirk: legacy stadium posts carry their public id **only** in
`django_stadium_id`, never `django_id`. `upsert_venue` resolves through
`django_stadium_id` first for exactly that reason.

`wp_posts` gains three columns via `ensure_schema()` (`DB_VERSION` currently `7`,
bump it to run a new migration step): `api_source`, `api_id`, and index
`api_source_id`. `legacy_id`/`legacy_table` come from the futebol365 plugin.

## Ownership policy — the invariant

`is_legacy($wp_id)`: true when `legacy_id` is set and below `ID_OFFSET`, or when
`_f365_source` is not `statorium`.

`write_fields($wp_id, $meta, $legacy)` enforces:
- posts **we created** → written freely
- `statorium_*` keys → always additive, written even on legacy posts
- legacy posts under the fixed `fill-gaps` policy → written **only when the field is
  currently empty**; never overwritten

`update_mode` is hard-pinned to `fill-gaps` (`Strict`/`Full` disabled by project
decision); `f365_statorium_update_mode` is the only escape hatch. `set_term_guarded`
and `set_thumbnail_guarded` apply the same rule to taxonomies and featured images.
Mirror-table entity rows are `INSERT IGNORE` + gap-fill `UPDATE`s with
`WHERE col IS NULL OR col = ''` — legacy rows are never modified.

Match *detail* rows (`matchplayer`, `matchplayerevent`, `matchstats`,
`live_livematchplayer*`) are the exception: wiped and rebuilt per match on every
detail sync, so they are idempotent.

`write_fields` also writes the ACF `_fieldname → field_key` companion meta, or
`get_fields()` in the editor would not see the value.

Mirror entity rows have their own gap-fill trap: `INSERT IGNORE` + `prepare('%s',
null)` (null becomes `''` → `0`/zero-date under non-strict MySQL) means a row first
created from a sparse payload keeps fake zeros forever unless a per-column gap-fill
UPDATE exists. `sync_person_rows` gap-fills birth_date/nationality/height/weight
(that last pair was missing — the dados-pessoais page showed 0 for every
lineup/transfers-created player). When adding a mirror column write, always pair
the INSERT value with a `WHERE col IS NULL OR col = 0/''` UPDATE.

`sync_team_row` had the inverse trap: its two name params were bound to the wrong
SQL columns (`shortname` got the full name, `longname` got Statorium's bare 2-4
letter club code) — silent because `shortname` is what nearly every template reads
for display, so the site looked fine ("FC Porto" everywhere) while `longname` quietly
held "FCP", visible only on the team Info tab's "Nome:" field. Statorium gives no
third, fuller name to justify a real long/short split, so both mirror columns now
get the one full name it does provide (`repair_team_names` fixed the backlog).
When a column pair looks like a short/long split, verify what the API actually sends
for *both* sides before trusting the binding order.

Entities with no Statorium id at all (coach names — `additionalInfo.coach` is a bare
string, no id/birthdate) still get a minimal mirror row (`ensure_teamstepcoach`):
reuse an existing `person_coach` by normalized name, else create one, but only ever
into an *empty* slot — never overwrite existing (legacy or prior) staff data. Same
create-from-name-alone tier of evidence the transfers feed already uses for players.

`class-tables.php`'s `aggregate_event()`/`event_type()` switch on the API's literal
`eventName` string — a name not in the map is silently dropped from the goal/card
tally with no error. This bit twice already: the live API sends `"Penalty goal"`
for a scored penalty (never bare `"Penalty"`), so every penalty-scored goal was
undercounting `matchplayer.goals` by exactly one — invisible until something cross-
checks event totals against the scoreboard (which `finalize_topscorer_rankings()`
does, as its correctness gate). `"Second yellow"` similarly fires *alongside* its
own `"Yellow card"` event (not a duplicate booking) and was unhandled, under-
reporting `double_yellow_card`. When Statorium's docs are silent on an event
vocabulary, verify against a real `/matches/{id}/` payload before trusting a name —
never assume the vocabulary from a filter's existing keys is complete.

`sync_match_row` had a second silent-wrong-column bug: `competition_match.
competition_django_id` holds the FEDCOMP (`federation_competition_id`) public id —
confirmed against legacy rows, every Primeira Liga match carries `392` regardless
of edition — and templates build `/competicao/{id}/` links straight off it. It was
bound to the match's *own* pub id instead for every match we ever created (2,658
rows), silently producing broken competition links wherever that column is read
(e.g. the stadium page's últimos/próximos jogos list). It also wasn't in the row's
`ON DUPLICATE KEY UPDATE` clause, so a wrong value could never self-correct on
resync — fixed both. When a column's legacy semantics aren't obvious from its name,
check a real legacy row before trusting a `// not sure what this is for` comment.

`derive_country_from_address()` had a related trap: it used `$pt !== $last` to mean
"country_pt() recognized this" — but `country_pt()` falls back to returning the
input unchanged when a name isn't a map key, which is indistinguishable from a
country whose PT spelling equals the English one. That silently broke resolution
for **Portugal** (PT "Portugal" = EN "Portugal") — the site's own country, so most
Portuguese stadium/club addresses never resolved a country. Fixed by using
`country_known()`, whose own docblock already names this exact trap (Chile/Jamaica/
Peru) — the call site just hadn't used it. A follow-on city-derivation attempt from
the same address string was tried and dropped: real addresses showed it picking a
province instead of the city (Spain) or a postcode-fused city (UK) — a wrong value
beats nothing only when you're certain it's right.

## Matching (class-matcher.php)

Resolution order: `api_source`/`api_id` columns → normalized-name candidates →
signal scoring → verdict.

The name index is `_f365_norm_name` postmeta, one row per **distinct** normalized
name form: post title *plus* shortname/longname/fullname, sourced from meta for
created posts and **from the mirror tables for legacy posts** (legacy team titles are
shortnames like "Ac. Viseu" while the API sends "Académico de Viseu Futebol Clube").
Stadiums additionally get a variant with "Estádio"/"Stadium" stripped.

**Without this index the matcher is blind and every import creates duplicates.**
`wp f365-statorium index-names` builds it; `f365_statorium_ensure_name_index()` runs
on every sync and rebuilds it if no *legacy* post has an index row (probing for "any
row" would hide a partial index, which was a real bug).

Scoring: country +2, birthdate +2 (±1 day tolerance — the two sources disagree by a
day on timezone-shifted DOBs), shirt +1, team_type +1, season +2. Thresholds:
player/team/coach/season 2, venue 0. A fuzzy first+last-token name fallback exists
but may only *link* when a birthdate confirms it.

Contradictions veto auto-linking: birthdates that differ beyond the tolerance
(both known), and — for **teams only** — a known-country mismatch. Club shortnames
repeat across countries ("CS Marítimo" Primeira Liga vs the Venezuelan namesake —
a real mislink, since repaired); the importer passes the league's country as the
club's country signal (`season_team_context()`), which also decides
`team_type` (league scope) and fills club `country_id`/`federation_id` at import.
Country contradiction is deliberately NOT applied to players — the two sources
genuinely disagree on some nationalities (see `audit-countries`).

`review_mode` is `auto` (unattended): a single non-contradicting candidate links; a
contradiction or several unconfirmed candidates **creates** a new record. Rationale —
a wrong link corrupts legacy data permanently, a duplicate is healed later by
auto-merge. The `f365_stat_review` table becomes an audit log
(`auto_linked`/`auto_created`) rather than a work queue.

## Data flow

State machine in option `f365_statorium_state`: `mode` = `backfill` | `delta`,
`cursor` (Y-m-d), `last_update_ts`.

**Backfill** — `/todaymatches/` day by day from the cursor (default: 1st of the
current month), cursor advanced per day so it resumes. On reaching today it flips to
`delta`.

**Delta** — `/updates/{since_ts}/` returns changed `{elementID, elementName}` rows,
grouped and dispatched by type (`match`, `player`, `team`, `team_players`, `season`,
`standings`, `season_teams`). Unknown types are logged and skipped so new Statorium
element types can't break the poller.

`f365_statorium_sync` (cron / `wp f365-statorium sync`) runs, in order: name-index
guard → new-league onboarding → auto-merge teams/players/stadiums → rescore reviews →
finalize competitions (knockout, then league) → heal live details → heal player/team
profiles → backfill batch **or** delta poll → pair legacy fixtures → transfers → odds.

`f365_statorium_tick` (2 min) is gated by a cheap `wp_posts`/`postmeta` count of
matches inside their live window — no API call otherwise. When it does fire,
`sync_live()` calls the light `/matches/live/` list (score/minute/status — the
provider's own guidance is that this endpoint is a thin summary, not meant to carry
full data for many concurrent matches) but now **also** calls `sync_match_detail()`
(the heavy `/matches/{id}` lineups/events/stats fetch) for every match still in that
list, every tick. Previously that heavy call only ran once a match's
`statorium_status_id` flipped to `'1'` (finished) — via the `details` backlog step
— so the live page's Events/Ficha/Estatísticas tabs stayed empty for the entire
match and only populated after full-time. `sync_match_detail()` wipes+rebuilds its
rows per call, so repeating it every tick is idempotent; the last tick before a
match leaves the live feed already has `situation === 'finished'`, so this also
covers the finish transition — the `details` step is now just a safety net for a
match that finished without ever passing through the tick (tick disabled, cron
down, etc.), not the primary path.

Production runs system-CLI cron, so the `cron` setting ships **off** (WP-Cron
disabled).

## Self-healing machinery

Because unattended mode deliberately prefers creating over risky linking, a set of
sweeps runs every sync to clean up:

- `auto_merge_duplicate_{teams,players,stadiums}` — fold a created duplicate into its
  legacy twin. Players use a deliberately strict rule (exactly one legacy name-twin,
  both birthdates known and within a day); anything ambiguous is left for manual
  `merge-player`. Stadiums have a second fallback when name-matching finds zero
  candidates: `merge_stadium_by_coordinates()` checks GPS proximity (~1km) against
  unlinked legacy stadiums. Needed because a real venue can carry a *completely*
  different name per source — sponsorship renames ("Ricoh Arena" → "Coventry
  Building Society Arena") or a one-off event name ("City of Coventry Stadium" for
  the 2012 Olympics) — that no name normalization will ever unify, but the GPS
  coordinates never move. Only acts when exactly one legacy stadium falls in range;
  multiple candidates are logged and left alone. Since this sweep re-scans *every*
  currently-created stadium post each sync (not just newly touched ones), it
  retroactively catches the whole existing backlog the first time it runs, not just
  new occurrences — there's no separate one-time backfill step, and none is needed
  (Statorium also has no bulk "list all venues" endpoint to pre-seed one anyway).
- `merge_competition` / `pair_legacy_fixtures` / `repair_group_pairing` — pre-loaded
  legacy fixture skeletons get their empty scores filled from our rows (keeping legacy
  ids/URLs), instead of showing both "México 2-0 África do Sul" and "México vs África
  do Sul".
- `heal_player_profiles` / `heal_team_profiles` — the transfers feed creates players
  and clubs from name+photo alone; these fetch full profiles later. Selection is
  **mirror-driven** (not postmeta) and throttled by `_statorium_profile_checked`.
- `heal_live_details` — fills live Events/Ficha/Estatísticas from mirror data, no API
  calls.
- `finalize_competitions` — the API has no winner/closed concept, so the Winners page
  is derived from the Final (penalties → extra time → regulation). Only covers
  knockout tournaments (requires a finished 'Final' phase match); a round-robin
  league never has one, so it was never closed even when fully played — the Winners
  page stayed empty for e.g. a fully-finished Serie A season. `finalize_league_
  competitions` is the sibling that covers this: once every match in a season is
  'finished' and the competition has no 'Final' phase at all, it computes the final
  table itself (points/goal-difference/victories/scored-goals, per-competition
  `competition_competitionclassificationorder` tiebreak sequence, head-to-head,
  `competition_classificationoffset` deductions) — deliberately replicating
  `f365_cls_calculate_table()` in the theme's Classificação template rather than a
  simpler ad-hoc scoring, so the derived winner always agrees with what the
  standings table itself shows. Watch the "all matches finished" gate: a pending
  match's `situation` column is SQL `NULL`, not `''` — `situation != 'finished'`
  silently ignores `NULL` rows (MySQL `!=` never matches NULL), so the check must be
  `situation IS NULL OR situation != 'finished'` or it will treat an in-progress
  season as complete and fabricate a winner. Both finalizers are wired into the
  `sync` CLI subcommand / `f365_statorium_sync` cron action — **not** `repair`,
  which only runs the `repair_*` sweeps.
- `finalize_topscorer_rankings` — derives "Portugal: Melhor Marcador"
  (`ranking_playerranking`, `/rank-jogadores/2/`) from matchplayer goals at season
  close. Gated hard: full calendar, nothing pending, and event goals must reconcile
  exactly with the scoreboard total (legacy pre-Statorium seasons undercount events
  and stay editorial). Only editions newer than the ranking's latest row are
  candidates. Filter `f365_statorium_topscorer_rankings` maps awards → league keys.
- `repair_duplicate_teamsteps` — folds exact-duplicate teamstep rows into their
  lowest id. Root cause (fixed): `team_teamstep.edition` is **varchar(4)**; club
  seasons named "2026-27" silently truncated on INSERT (WP strips strict mode) while
  the reuse lookup compared the full string, so every match spawned two fresh
  teamsteps. `F365_Statorium_Tables::edition_year()` now normalizes every edition to
  the 4-char start year — keep it that way for anything touching `edition` columns.
- `repair_*` methods and `rollback_scope` / `prune_season` for scoped undo. Rollback
  only deletes mirror rows with id `>= ID_OFFSET`.

## URL fragility (regressed twice — check `wp f365-statorium status`)

Entity URLs are numeric rewrite rules (`/jogador/{pub}/`, `/jogo/{pub}/`,
`/competicao/{fedcomp}/?edition=`). Two things break them:

1. **Permalink Manager** auto-generates slug URIs and canonical-redirects the numeric
   URLs away. It is disabled for the entity CPTs via
   `permalink_manager_disabled_post_types` in the bootstrap (registered *before* the
   collision guard so load order can't skip it), `strip_permalink_manager_uri()` runs
   after every insert/update, and the v6 migration purges URIs created earlier.
2. **Post slugs.** Created players must have `post_name` = the numeric public id
   (legacy persons-module convention); WP's auto-slug from the title breaks the
   canonical URL.

`status` reports both as "URL health" — non-zero means a regression.

## Load-order collision guard

The futebol365 plugin ships a legacy fallback copy of this integration
(`futebol365/includes/statorium/`) that self-loads when these classes are absent. On
the single request that *activates* this plugin while futebol365 is already loaded,
the fallback has already declared the class names — redeclaring would fatal. So the
bootstrap detects `F365_Statorium_Importer` already existing, sets
`f365_statorium_pending_activation`, and returns; real activation runs on the next
request via `init`. Don't "simplify" this away.

## Localization

The site is European Portuguese; Statorium sends English and Brazilian Portuguese.
- `country_pt()` / `country_map()` — full EN→PT country map, PT spellings matching the
  legacy DB rows. `country_known()` is a membership test (needed because some names are
  identical in both languages).
- `F365_Statorium_Matcher::name_aliases()` — pt-BR → pt-PT on normalized names
  ("Irã"→"Irão", "Tchéquia"→"Chequia"), plus corrections for spellings earlier plugin
  builds created.
- `f365_position` terms are the legacy **lowercase English** slugs
  (goalkeeper/defender/midfielder/forward) — the theme translates for display. The v7
  migration merged away PT duplicates an earlier build created.
- Match/event/stat vocabularies are mapped to the legacy conventions in
  `class-tables.php`, each behind a filter.

## Extension points

Filters: `f365_statorium_update_mode`, `f365_statorium_name_aliases`,
`f365_statorium_league_config`, `f365_statorium_table_position_map`,
`f365_statorium_event_type_map`, `f365_statorium_teamstat_map`,
`f365_statorium_phase_rows`, `f365_statorium_transfer_type_map`,
`f365_statorium_first_run_date`.

Options: `f365_statorium_settings`, `f365_statorium_state`,
`f365_statorium_league_map`, `f365_statorium_odds`, `f365_statorium_db_version`,
`f365_stat_map_{type}` (legacy id maps, superseded by the `api_id` column but still
written).

## CLI

`wp f365-statorium <subcommand>`. Start with `status` — it prints the state machine,
mapped counts, and the URL/matcher health guards.

Setup: `index-names`, `leagues`, `leagues-map`, `countries`, `audit-countries`
Imports: `season|teams|matches|all --season=X`, `backfill [--from --days --details]`,
`today [--date --details]`, `venues`, `details`, `match-detail`, `transfers`, `odds`
Cron entries: `sync`, `tick`, `poll`, `live`
Diagnostics: `match-report --season=X` (dry run), `why` (why a record matched or
didn't), `review`, `touched`
Fixes: `resolve`, `review-rescore`, `merge-player`, `merge-team`,
`merge-competition`, `repair-pairing`, `repair`, `heal-players`, `heal-teams`,
`live-backfill`, `repair-sync-dates`, `teamsteps [--dry-run]`, `rankings [--dry-run]`
Undo: `prune-season`, `rollback --yes`

`repair` and `sync` are NOT the same step list — easy to mix up when chasing a fix.
`repair` only runs the `repair_*` sweeps (shortname/mirror-profile/teamstep-dedup
type gap-fills). The `finalize_*`/`heal_*` steps (Winners page closers, live-detail
healing, transfers-feed profile enrichment) only run under `sync` (and the
`f365_statorium_sync` cron action) — running `repair` to trigger one of those does
nothing, silently.

## Theme boundary: the plugin can write perfect data and the page still shows nothing

`/jogos-em-direto/` (theme: `archive-f365_livegames.php`,
`themes/futebol365/inc/f365-livegame-function.php`) called
`f365_sql_get_current_livematches()` and `f365_livegame_result_text()` — neither
was defined anywhere in the theme. The only caller guarded with
`function_exists()` and returned `[]`, so the Todos/Direto/Próximos/Terminados
tabs silently rendered "Sem jogos disponíveis" **regardless of how much correct
data `sync_live()`/`ensure_livematch()` had written** — the mirror tables were
fine; the theme's read side for the *list* page (not the single match page,
which had its own working query) was simply never finished. Implemented both
following the sibling `f365_livegame_competitions_today()`'s already-established
±2h-around-today window convention. When a page shows "no data" despite the
mirror tables being populated (verify with `wp eval` before assuming an import
gap), check whether the theme's query function actually exists — this class of
bug produces the exact same symptom as a real import gap but lives entirely
outside the plugin.

## Conventions when editing

- WordPress style: tabs, `defined( 'ABSPATH' ) || exit;`, spaces inside parens,
  `$wpdb->prepare` for anything with a variable.
- The dense explanatory comments are load-bearing — several document bugs that were
  fixed the hard way (MySQL 8 strict-mode date comparison, partial name index,
  `stages` vs event-count penalty scores, mirror-vs-postmeta reads). Preserve them.
- `dry_run` is honored by every write path; keep it that way.
- Prefer a mirror-driven query over a postmeta one when deciding "is this already
  done" — the postmeta version of that check has been the wrong answer twice.
