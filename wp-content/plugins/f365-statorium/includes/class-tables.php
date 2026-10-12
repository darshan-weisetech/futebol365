<?php
/**
 * Mirror-table writer — the frontend templates (single-f365_match.php, team /
 * competition sections) read the cloned Django tables (wp_competition_match,
 * wp_competition_matchplayer, wp_team_teamstep, …), NOT postmeta. This class
 * writes those rows for Statorium data so imported matches render exactly
 * like legacy ones (lineups, events, stats, venue, attendance).
 *
 * ID conventions (aligned with the postmeta layer):
 *  - match/competition/team/stadium/player row ids = the entity's public id
 *    (legacy django id for linked posts, OFFSET+statorium_id for created ones)
 *  - team_teamstep rows are looked up by (team_id, edition) and allocated via
 *    auto_increment when missing (their ids appear in no URL)
 *  - matchplayer/matchplayerevent/matchstats rows are wiped and rebuilt per
 *    match on every detail sync (idempotent)
 *
 * Legacy rows are never modified: entity rows are INSERT IGNORE — if the row
 * already exists (legacy data), it is left untouched.
 *
 * @package F365_Statorium
 */

defined( 'ABSPATH' ) || exit;

class F365_Statorium_Tables {

	private F365_Statorium_Importer $importer;

	public function __construct( F365_Statorium_Importer $importer ) {
		$this->importer = $importer;
	}

	private function t( string $table ): string {
		global $wpdb;
		return $wpdb->prefix . $table;
	}

	/**
	 * Statorium position → legacy matchplayer position value.
	 * Accepts both vocabularies the API uses: strings ("Attacker", /teams/)
	 * and numeric codes (4, /players/ showstat: 1 GK, 2 DF, 3 MF, 4 FW).
	 * Output is ALWAYS the legacy convention (goalkeeper/defender/midfielder/
	 * forward — 146k existing rows; templates translate to PT).
	 */
	private function position( string $raw ): string {
		$map = apply_filters( 'f365_statorium_table_position_map', [
			'goalkeeper' => 'goalkeeper',
			'defender'   => 'defender',
			'midfielder' => 'midfielder',
			'atacker'    => 'forward',
			'attacker'   => 'forward',
			'forward'    => 'forward',
			'1'          => 'goalkeeper',
			'2'          => 'defender',
			'3'          => 'midfielder',
			'4'          => 'forward',
		] );
		return $map[ strtolower( trim( $raw ) ) ] ?? 'midfielder';
	}

	/** Statorium event name → legacy matchplayerevent event_type. */
	private function event_type( string $name ): string {
		$map = apply_filters( 'f365_statorium_event_type_map', [
			'Goal'                    => 'goal',
			'Assist'                  => 'assist',
			'Own goal'                => 'own_goal',
			// The API sends "Penalty goal" for a scored penalty (never bare
			// "Penalty" — verified against live payloads); "Penalty" is kept
			// only in case an older/other feed shape ever uses it.
			'Penalty'                 => 'penalty',
			'Penalty goal'            => 'penalty',
			'Yellow card'             => 'yellow_card',
			// "Second yellow" is the API's dismissal-via-2nd-booking marker,
			// distinct from the "Yellow card" event for the card itself (both
			// fire at the same minute for the same player) — maps to the
			// legacy schema's own separate double_yellow_card category.
			'Second yellow'           => 'double_yellow_card',
			'Red card'                => 'red_card',
			'Substitution'            => 'substitution',
			'Penalty shootout scored' => 'penalty_shootout_scored',
			'Penalty shootout missed' => 'penalty_shootout_missed',
		] );
		return $map[ $name ] ?? sanitize_title_with_dashes( $name, '', 'save' );
	}

	// ════════════════════════════════════════════════════════════════════════
	// ENTITY ROWS (INSERT IGNORE — legacy rows untouched)
	// ════════════════════════════════════════════════════════════════════════

	public function sync_competition_row( int $pub_id, string $name, string $season, int $fed_comp_pub = 0, int $country_id = 0 ): void {
		global $wpdb;
		// edition is the 4-char starting year (legacy convention; the column
		// is varchar(4) — passing the full season name only "worked" through
		// silent truncation, which strict-mode MySQL rejects outright).
		$wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . $this->t( 'competition_competition' ) . '
				(id, federation_competition_id, name, edition, season, country_id, is_available, is_home_visitor, victory_points, draw_points, defeat_points, is_highlight, is_closed)
			 VALUES (%d, ' . ( $fed_comp_pub ?: 'NULL' ) . ', %s, %s, %s, ' . ( $country_id ?: 'NULL' ) . ', %s, %s, 3, 1, 0, %s, %s)',
			$pub_id, $name, self::edition_year( $season ), $season, 't', 'f', 'f', 'f'
		) );

		// Self-heal: our own rows may have been written before season
		// strings were normalized to the full "2026-2027" legacy format
		// (Statorium sends the short "2026-27" form) — INSERT IGNORE only
		// sets this on first creation, so an existing row never picks up a
		// later format fix by itself. Legacy rows are never touched
		// (id < ID_OFFSET); this only ever corrects OUR OWN rows, and only
		// when the value has actually drifted from the current one.
		if ( $pub_id >= F365_Statorium_Importer::ID_OFFSET ) {
			$wpdb->query( $wpdb->prepare(
				'UPDATE ' . $this->t( 'competition_competition' ) . '
				    SET season = %s
				  WHERE id = %d AND season <> %s',
				$season, $pub_id, $season
			) );

			// Additive gap-fill: country_id was never written for our own
			// rows at all until now — fill it in on already-existing rows
			// too, but only ever into an empty slot, never overwriting.
			if ( $country_id ) {
				$wpdb->query( $wpdb->prepare(
					'UPDATE ' . $this->t( 'competition_competition' ) . '
					    SET country_id = %d
					  WHERE id = %d AND ( country_id IS NULL OR country_id = 0 )',
					$country_id, $pub_id
				) );
			}
		}

		// Link OUR rows created before the fed_comp layer existed. The whole
		// /competicao/{id}/ URL scheme keys on federation_competition_id.
		if ( $fed_comp_pub && $pub_id >= F365_Statorium_Importer::ID_OFFSET ) {
			$wpdb->query( $wpdb->prepare(
				'UPDATE ' . $this->t( 'competition_competition' ) . '
				    SET federation_competition_id = %d
				  WHERE id = %d AND ( federation_competition_id IS NULL OR federation_competition_id = 0 )',
				$fed_comp_pub, $pub_id
			) );
		}
	}

	/**
	 * Competition-type row (Django FederationCompetition) — one per league.
	 * The public /competicao/{id}/ URLs key on THIS id (+ ?edition= for the
	 * season), so without it every competition link renders as /competicao/0/.
	 */
	public function sync_fedcomp_row( int $pub_id, string $name, int $federation_mirror_id, string $scope = 'national_championship' ): void {
		global $wpdb;
		$type = 'national_championship' === $scope || 'regional_championship' === $scope ? 'championship' : 'competition';
		$wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . $this->t( 'federation_federationcompetition' ) . '
				(id, shortname, longname, federation_id, competition_type, scope, is_principal, `order`, is_active,
				 step, gender, modality, is_highlight, show_stadiums, auto_create_match_events)
			 VALUES (%d, %s, %s, ' . ( $federation_mirror_id ?: 'NULL' ) . ', %s, %s, 0, 99, 1, %s, %s, %s, 0, 1, 0)',
			$pub_id, $name, $name, $type, $scope, 'principal', 'male', 'football'
		) );

		// Config owns scope/federation on OUR rows — keep them current.
		if ( $pub_id >= F365_Statorium_Importer::ID_OFFSET ) {
			$wpdb->query( $wpdb->prepare(
				'UPDATE ' . $this->t( 'federation_federationcompetition' ) . '
				    SET scope = %s, competition_type = %s' . ( $federation_mirror_id ? ', federation_id = ' . $federation_mirror_id : '' ) . '
				  WHERE id = %d',
				$scope, $type, $pub_id
			) );
		}
	}

	public function sync_team_row( int $pub_id, string $name, string $shortname = '', string $team_type = 'national', string $pt_country_hint = '' ): void {
		global $wpdb;
		$is_national = 'national' === $team_type;
		// A national team's name IS the country — resolvable immediately, no
		// venue/address lookup needed. A CLUB's country comes from the hint
		// (its domestic league's federation country, passed by the importer)
		// — far more reliable than the venue-address fallback in
		// enrich_team_profile, which stays for hint-less paths (transfers
		// feed, international competitions). federation_id: continental
		// confederation for nationals, domestic FA for hinted clubs.
		$pt_country    = $is_national ? $name : $pt_country_hint;
		$country_id    = $pt_country ? $this->country_id_by_name( $pt_country ) : 0;
		$federation_id = $pt_country ? $this->federation_id_for_country( $pt_country, $is_national ) : 0;

		// $name is the only reliably full/readable team name Statorium gives
		// (participantName/teamName — "FC Porto"). $shortname here is the
		// API's bare 2-4 letter CODE ("FCP"), not a legacy-style short name
		// like "SC Braga" — it is captured in postmeta (write_fields, for the
		// matcher/audits) but must NOT land in the mirror `shortname` column:
		// nearly every frontend display (match cards, team lists, headers)
		// reads that column, and it previously held $name here while
		// `longname` held the bare code — backwards, and the Dados Gerais
		// "Nome:" field (which reads longname) showed "FCP" instead of "FC
		// Porto". Both mirror columns get $name; there is no third, fuller
		// official name in any Statorium payload to put in longname instead.
		$wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . $this->t( 'team_team' ) . '
				(id, shortname, longname, team_type, country_id, federation_id, is_available, is_highlight, slug)
			 VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s)',
			$pub_id, $name, $name, $team_type, $country_id ?: null, $federation_id ?: null, 't', 'f', sanitize_title( $name )
		) );

		// Fill-gap on ANY existing row (created or linked-legacy) missing
		// either column — not restricted to created rows: a legacy national
		// team's empty federation_id benefits from this exactly the same way.
		$this->update_team_country_federation( $pub_id, $country_id, $federation_id );
	}

	public function sync_stadium_row( int $pub_id, string $name ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . $this->t( 'stadium_stadium' ) . '
				(id, shortname, longname)
			 VALUES (%d, %s, %s)',
			$pub_id, $name, $name
		) );
	}

	public function sync_person_rows( int $pub_id, string $fullname, string $shortname, string $position = '', string $birth_date = '', string $height = '', string $weight = '', int $nationality_id = 0 ): void {
		global $wpdb;
		// NULL literals, not %s-with-null: $wpdb->prepare turns null into ''
		// which a non-strict MySQL coerces to 0 / '0000-00-00' — those fake
		// values then block every later gap-fill.
		$wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . $this->t( 'person_person' ) . '
				(id, shortname, longname, birth_date, nationality_id, slug)
			 VALUES (%d, %s, %s, ' . ( $birth_date ? '%s' : 'NULL' ) . ', ' . ( $nationality_id ?: 'NULL' ) . ', %s)',
			...array_merge(
				[ $pub_id, $shortname ?: $fullname, $fullname ],
				$birth_date ? [ $birth_date ] : [],
				[ sanitize_title( $fullname ) ]
			)
		) );
		$wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . $this->t( 'person_player' ) . '
				(id, person_id, position, height, weight)
			 VALUES (%d, %d, %s, ' . self::sql_int_or_null( (int) $height ?: '' ) . ', ' . self::sql_int_or_null( (int) $weight ?: '' ) . ')',
			$pub_id, $pub_id, $this->position( $position )
		) );

		// Fill gaps on ANY row — the WHERE clauses only ever touch NULL /
		// zero-date / 0 values, so a legacy player's existing birthdate or
		// nationality is never overwritten (fill-gaps policy); an EMPTY
		// legacy value getting filled is exactly what the policy allows.
		// height/weight/position matter: a player first seen through a sparse
		// payload (lineup, transfers feed) gets his row inserted with NULLs,
		// and INSERT IGNORE means the later full-profile sync lands here —
		// without these UPDATEs the dados-pessoais page showed 0 forever.
		if ( $pub_id > 0 ) {
			if ( $birth_date ) {
				// NOTE: no `birth_date = ''` comparison — comparing a DATE
				// column to an empty string is an ERROR on MySQL 8 strict
				// mode and silently killed this whole UPDATE.
				$wpdb->query( $wpdb->prepare(
					'UPDATE ' . $this->t( 'person_person' ) . "
					    SET birth_date = %s
					  WHERE id = %d AND ( birth_date IS NULL OR CAST(birth_date AS CHAR) = '0000-00-00' )",
					$birth_date, $pub_id
				) );
			}
			if ( $nationality_id ) {
				$wpdb->query( $wpdb->prepare(
					'UPDATE ' . $this->t( 'person_person' ) . '
					    SET nationality_id = %d
					  WHERE id = %d AND ( nationality_id IS NULL OR nationality_id = 0 )',
					$nationality_id, $pub_id
				) );
			}
			if ( (int) $height ) {
				$wpdb->query( $wpdb->prepare(
					'UPDATE ' . $this->t( 'person_player' ) . '
					    SET height = %d WHERE id = %d AND ( height IS NULL OR height = 0 )',
					(int) $height, $pub_id
				) );
			}
			if ( (int) $weight ) {
				$wpdb->query( $wpdb->prepare(
					'UPDATE ' . $this->t( 'person_player' ) . '
					    SET weight = %d WHERE id = %d AND ( weight IS NULL OR weight = 0 )',
					(int) $weight, $pub_id
				) );
			}
			// Position: rows WE created default to 'midfielder' when the
			// first payload had none — refresh ours whenever the API actually
			// sends a position. Legacy rows keep theirs (always set).
			if ( '' !== trim( $position ) && $pub_id >= F365_Statorium_Importer::ID_OFFSET ) {
				$wpdb->query( $wpdb->prepare(
					'UPDATE ' . $this->t( 'person_player' ) . ' SET position = %s WHERE id = %d',
					$this->position( $position ), $pub_id
				) );
			}
			// Short name: sparse creations store the full name in both
			// columns; when a real short form arrives ("G. Varela") adopt it
			// on OUR rows. Detail syncs pass fullname twice — the inequality
			// guard keeps them from clobbering a good short form.
			if ( '' !== $shortname && $shortname !== $fullname && $pub_id >= F365_Statorium_Importer::ID_OFFSET ) {
				$wpdb->query( $wpdb->prepare(
					'UPDATE ' . $this->t( 'person_person' ) . ' SET shortname = %s WHERE id = %d AND shortname = longname',
					$shortname, $pub_id
				) );
			}
		}
	}

	/**
	 * Resolve a country_country row id by name (accent/case-insensitive,
	 * matches shortname or longname). Cached per request.
	 */
	public function country_id_by_name( string $name ): int {
		static $index = null;
		if ( null === $index ) {
			global $wpdb;
			$index = [];
			foreach ( (array) $wpdb->get_results( 'SELECT id, shortname, longname FROM ' . $this->t( 'country_country' ) ) as $c ) {
				foreach ( [ $c->shortname, $c->longname ] as $n ) {
					$key = F365_Statorium_Matcher::canon( (string) $n );
					if ( $key && ! isset( $index[ $key ] ) ) {
						$index[ $key ] = (int) $c->id;
					}
				}
			}
		}
		return $index[ F365_Statorium_Matcher::canon( $name ) ] ?? 0;
	}

	/**
	 * federation_federation row id for a team: the domestic FA for a club
	 * (federation_type='federation', same country_id), or the continental
	 * confederation for a national team (federation_type='confederation',
	 * via F365_Statorium_Importer::country_confederation()). Returns 0 when
	 * unresolvable — never guessed.
	 */
	public function federation_id_for_country( string $pt_country, bool $is_national ): int {
		global $wpdb;
		if ( '' === $pt_country ) {
			return 0;
		}
		if ( $is_national ) {
			$conf = F365_Statorium_Importer::country_confederation()[ $pt_country ] ?? '';
			if ( '' === $conf ) {
				return 0;
			}
			return (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT id FROM ' . $this->t( 'federation_federation' ) . " WHERE shortname = %s AND federation_type = 'confederation' LIMIT 1",
				$conf
			) );
		}
		$country_id = $this->country_id_by_name( $pt_country );
		if ( ! $country_id ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . $this->t( 'federation_federation' ) . " WHERE country_id = %d AND federation_type = 'federation' LIMIT 1",
			$country_id
		) );
	}

	/**
	 * Gap-fill team_team.country_id/federation_id. Never overwrites an
	 * existing legacy value — created AND linked-legacy teams both benefit.
	 */
	public function update_team_country_federation( int $pub_id, int $country_id, int $federation_id ): void {
		global $wpdb;
		if ( $country_id ) {
			$wpdb->query( $wpdb->prepare(
				'UPDATE ' . $this->t( 'team_team' ) . ' SET country_id = %d WHERE id = %d AND ( country_id IS NULL OR country_id = 0 )',
				$country_id, $pub_id
			) );
		}
		if ( $federation_id ) {
			$wpdb->query( $wpdb->prepare(
				'UPDATE ' . $this->t( 'team_team' ) . ' SET federation_id = %d WHERE id = %d AND ( federation_id IS NULL OR federation_id = 0 )',
				$federation_id, $pub_id
			) );
		}
	}

	/**
	 * Squad membership (player career table): team_squad row per
	 * (player, teamstep) + team_squadcompetition per competition with
	 * games/goals. Upsert — safe to re-run.
	 */
	public function ensure_squad( int $player_pub, int $teamstep_id, string $edition, string $position, int $comp_pub, string $shirt, int $games, int $goals ): void {
		global $wpdb;

		// Same start-year convention as teamsteps — the ranking template joins
		// team_squad.edition against ranking rows' 4-char edition, and legacy
		// squad rows all carry plain years.
		$edition = self::edition_year( $edition );

		$squad_id = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . $this->t( 'team_squad' ) . ' WHERE player_id = %d AND team_id = %d LIMIT 1',
			$player_pub, $teamstep_id
		) );
		if ( ! $squad_id ) {
			$wpdb->query( $wpdb->prepare(
				'INSERT INTO ' . $this->t( 'team_squad' ) . ' (`order`, edition, team_id, player_id, position)
				 VALUES (0, %s, %d, %d, %s)',
				$edition, $teamstep_id, $player_pub, $this->position( $position )
			) );
			$squad_id = (int) $wpdb->insert_id;
		}
		if ( ! $squad_id || ! $comp_pub ) {
			return;
		}

		$sc_id = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . $this->t( 'team_squadcompetition' ) . ' WHERE squad_id = %d AND competition_id = %d LIMIT 1',
			$squad_id, $comp_pub
		) );
		if ( $sc_id ) {
			$wpdb->query( $wpdb->prepare(
				'UPDATE ' . $this->t( 'team_squadcompetition' ) . ' SET games = %d, goals = %d, shirt_number = %s WHERE id = %d',
				$games, $goals, $shirt, $sc_id
			) );
		} else {
			$wpdb->query( $wpdb->prepare(
				'INSERT INTO ' . $this->t( 'team_squadcompetition' ) . ' (squad_id, competition_id, shirt_number, games, goals)
				 VALUES (%d, %d, %s, %d, %d)',
				$squad_id, $comp_pub, $shirt, $games, $goals
			) );
		}
	}

	/**
	 * Rebuild squads + per-competition games/goals for one competition from
	 * the matchplayer rows (idempotent aggregation).
	 */
	public function rebuild_squads( int $comp_pub_id ): int {
		global $wpdb;

		$edition = (string) $wpdb->get_var( $wpdb->prepare(
			'SELECT season FROM ' . $this->t( 'competition_competition' ) . ' WHERE id = %d', $comp_pub_id
		) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT mp.player_id, mp.team_id AS teamstep_id,
			        MAX(mp.position) AS position, MAX(mp.shirt_number) AS shirt,
			        COUNT(DISTINCT CASE WHEN mp.minutes > 0 OR mp.is_titular = \'t\' THEN mp.match_id END) AS games,
			        COALESCE(SUM(mp.goals),0) AS goals
			   FROM ' . $this->t( 'competition_matchplayer' ) . ' mp
			   JOIN ' . $this->t( 'competition_match' ) . ' m ON m.id = mp.match_id
			  WHERE m.competition_id = %d
			  GROUP BY mp.player_id, mp.team_id',
			$comp_pub_id
		) );

		foreach ( (array) $rows as $r ) {
			$this->ensure_squad(
				(int) $r->player_id, (int) $r->teamstep_id, $edition,
				(string) $r->position, $comp_pub_id, (string) $r->shirt,
				(int) $r->games, (int) $r->goals
			);
		}
		return count( (array) $rows );
	}

	/**
	 * Competition participation (teamstep ↔ competition). The team pages
	 * build their competition selector from this table — without it the
	 * jogos/calendário sections render "Sem informação disponível".
	 *
	 * $group_name lands in `group_phase_one` — the classification/equipas
	 * templates read THIS column (not the match table) to seed the 0-line
	 * roster for a group before any fixture creates a row for it, and to
	 * auto-discover group names. Column choice: group_phase_one/two/three
	 * map to phase order 1/2/3 (see f365-competition-classificacao.php);
	 * every group stage the importer creates is phase order 1 ("Fase de
	 * grupos" — see sync_match_row), so group_phase_one is always correct
	 * here. Gap-fill on repeat calls: a team's row is often created by its
	 * FIRST match (e.g. an early knockout leg with no group), and the group
	 * becomes known only once that team's group-stage fixtures sync.
	 */
	public function ensure_participant( int $teamstep_id, int $comp_pub_id, string $group_name = '' ): void {
		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . $this->t( 'competition_participant' ) . '
			  WHERE competition_id = %d AND team_id = %d LIMIT 1',
			$comp_pub_id, $teamstep_id
		) );
		if ( $exists ) {
			if ( '' !== $group_name ) {
				$wpdb->query( $wpdb->prepare(
					'UPDATE ' . $this->t( 'competition_participant' ) . "
					    SET group_phase_one = %s
					  WHERE id = %d AND ( group_phase_one IS NULL OR group_phase_one = '' )",
					$group_name, (int) $exists
				) );
			}
			return;
		}
		$wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . $this->t( 'competition_participant' ) . '
				(competition_id, team_id, group_phase_one, group_phase_two, group_phase_three)
			 VALUES (%d, %d, %s, %s, %s)',
			$comp_pub_id, $teamstep_id, $group_name, '', ''
		) );
	}

	/**
	 * Head-coach entity for a teamstep's "Equipa Técnica" section — read by
	 * the Plantel and Histórico templates from `team_teamstepcoach` (joined
	 * to `person_coach`/`person_person`), a DIFFERENT table from the
	 * `team_teamstep.tmp_coach` free-text column enrich_team_profile()
	 * already fills. Statorium's /teams/{id} payload gives only a bare name
	 * string — no coach id, birthdate, or nationality — so this creates the
	 * minimal person_person + person_coach pair a name-only signal can
	 * safely support (same tier of evidence the transfers feed already uses
	 * to create players), reusing an existing coach by exact normalized name
	 * match before creating a new one so the same manager isn't duplicated
	 * across every club he's tracked at.
	 *
	 * Pure fill-gap: a `team_teamstepcoach` row already existing for this
	 * teamstep (legacy data, or a previous run) is NEVER touched — a
	 * mid-season managerial change is real history, not a value to
	 * overwrite. Idempotent: only inserts when the (teamstep, 'principal')
	 * slot is empty.
	 */
	public function ensure_teamstepcoach( int $teamstep_id, string $edition, string $coach_name ): void {
		global $wpdb;
		$coach_name = trim( $coach_name );
		if ( '' === $coach_name ) {
			return;
		}

		$has_slot = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . $this->t( 'team_teamstepcoach' ) . "
			  WHERE team_id = %d AND coach_type = 'principal' LIMIT 1",
			$teamstep_id
		) );
		if ( $has_slot ) {
			return;
		}

		$norm      = F365_Statorium_Matcher::normalize( $coach_name );
		$coach_id  = 0;
		$person_id = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . $this->t( 'person_person' ) . '
			  WHERE shortname = %s OR longname = %s LIMIT 1',
			$coach_name, $coach_name
		) );
		if ( $person_id ) {
			$coach_id = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT id FROM ' . $this->t( 'person_coach' ) . ' WHERE person_id = %d LIMIT 1',
				(int) $person_id
			) );
		}
		// Exact-string lookup above misses accent/case variants — fall back
		// to a normalized scan (coaches are a small table; a LIKE-anchored
		// index scan is cheap and this path only runs once per new name).
		if ( ! $coach_id ) {
			$candidates = $wpdb->get_results(
				'SELECT ch.id AS coach_id, pe.shortname, pe.longname FROM ' . $this->t( 'person_coach' ) . ' ch
				   JOIN ' . $this->t( 'person_person' ) . ' pe ON pe.id = ch.person_id'
			);
			foreach ( $candidates as $c ) {
				if ( F365_Statorium_Matcher::normalize( (string) $c->shortname ) === $norm
					|| F365_Statorium_Matcher::normalize( (string) $c->longname ) === $norm ) {
					$coach_id = (int) $c->coach_id;
					break;
				}
			}
		}

		if ( ! $coach_id ) {
			$wpdb->query( $wpdb->prepare(
				'INSERT INTO ' . $this->t( 'person_person' ) . ' (shortname, longname, slug) VALUES (%s, %s, %s)',
				$coach_name, $coach_name, sanitize_title( $coach_name )
			) );
			$new_person_id = (int) $wpdb->insert_id;
			$wpdb->query( $wpdb->prepare(
				'INSERT INTO ' . $this->t( 'person_coach' ) . ' (person_id, coach_name) VALUES (%d, %s)',
				$new_person_id, $coach_name
			) );
			$coach_id = (int) $wpdb->insert_id;
		}

		$wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . $this->t( 'team_teamstepcoach' ) . "
				(edition, `order`, team_id, coach_id, coach_type)
			 VALUES (%s, 0, %d, %d, 'principal')",
			self::edition_year( $edition ), $teamstep_id, $coach_id
		) );
	}

	/**
	 * Competition phase row (Fase de grupos / Oitavos-de-final / …) — the
	 * classification and phase navigation are keyed on these, not on the
	 * text `phase` column. Reuse by (competition, name), else create.
	 */
	public function ensure_phase( int $comp_id, string $name, string $type, int $order ): int {
		global $wpdb;
		$existing = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . $this->t( 'competition_competitionphase' ) . '
			  WHERE competition_id = %d AND name = %s LIMIT 1',
			$comp_id, $name
		) );
		if ( $existing ) {
			return (int) $existing;
		}
		$wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . $this->t( 'competition_competitionphase' ) . '
				(competition_id, `order`, name, phase_type) VALUES (%d, %d, %s, %s)',
			$comp_id, $order, $name, $type
		) );
		return (int) $wpdb->insert_id;
	}

	/** Legacy phase slug → PT phase row definition [name, type, order]. */
	public static function phase_def( string $slug ): ?array {
		$map = apply_filters( 'f365_statorium_phase_rows', [
			// UEFA's 3 club competitions (Champions/Europa/Conference League)
			// switched to a single round-robin "League Phase" since 2024-25 —
			// same non-elimination, round-based shape as the traditional
			// named-group stage, so it reuses that type. Statorium calls the
			// rounds "MatchDay N", identical to any ordinary league — see
			// import_matches()'s $is_league_phase check, which is what
			// actually assigns this slug (map_phase() alone can't, since the
			// matchday NAME carries no signal of which format it belongs to).
			'league_phase'   => [ 'Fase de Liga', 'mixed_groups', 1 ],
			// The new format's play-off round (league-phase 9th-24th place)
			// to reach the Round of 16 — between the league phase and the
			// knockout bracket proper.
			'ko_playoffs'    => [ 'Play-Off - Oitavos-de-final', 'elimination', 2 ],
			'round_of_32'    => [ '16 avos-de-final', 'elimination', 2 ],
			'round_of_16'    => [ 'Oitavos-de-final', 'elimination', 3 ],
			'quarter_finals' => [ 'Quartos-de-final', 'elimination', 4 ],
			'semi_finals'    => [ 'Meias-finais', 'elimination', 5 ],
			'third_fourth'   => [ 'Terceiro lugar', 'elimination', 6 ],
			'final'          => [ 'Final', 'elimination', 7 ],
		] );
		return $map[ $slug ] ?? null;
	}

	/**
	 * Legacy edition convention: the season's STARTING YEAR, always 4 chars —
	 * `team_teamstep.edition` is varchar(4). Statorium club-league season
	 * names are longer ("2026-27"), and with WP's non-strict sql_mode MySQL
	 * silently truncated them on INSERT while the reuse lookup below compared
	 * the full string — it never matched, so EVERY match spawned two fresh
	 * teamsteps (an 18-team league ended up with 612 "participants" and a
	 * broken classification). Normalize BEFORE both the lookup and the
	 * insert; repair_duplicate_teamsteps() folds rows created before the fix.
	 */
	public static function edition_year( string $edition ): string {
		return preg_match( '/\d{4}/', $edition, $m ) ? $m[0] : substr( trim( $edition ), 0, 4 );
	}

	/**
	 * Teamstep for (team, edition): reuse an existing row, else create one.
	 */
	public function ensure_teamstep( int $team_pub_id, string $edition ): int {
		global $wpdb;
		$edition  = self::edition_year( $edition );
		// The lookup must mirror the INSERT's constants: a club can carry
		// several teamsteps per edition (principal, sub_23, B team, futsal,
		// female…) and an unfiltered MIN(id) sometimes returned the wrong one
		// — our match rows then referenced a different step than the legacy
		// fixtures of the same competition, splitting the classification.
		$existing = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . $this->t( 'team_teamstep' ) . "
			  WHERE team_id = %d AND edition = %s
			    AND step = 'principal' AND modality = 'football' AND gender = 'male'
			  ORDER BY id ASC LIMIT 1",
			$team_pub_id, $edition
		) );
		if ( $existing ) {
			return (int) $existing;
		}

		$wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . $this->t( 'team_teamstep' ) . '
				(team_id, step, modality, gender, edition, is_available)
			 VALUES (%d, %s, %s, %s, %s, %s)',
			$team_pub_id, 'principal', 'football', 'male', $edition, 't'
		) );
		return (int) $wpdb->insert_id;
	}

	// ════════════════════════════════════════════════════════════════════════
	// MATCH ROW
	// ════════════════════════════════════════════════════════════════════════

	/**
	 * Upsert the wp_competition_match row. $args:
	 *   pub_id, comp_pub_id, comp_name, season, round, phase, date_time,
	 *   home_team_pub, home_name, away_team_pub, away_name, home_score,
	 *   visitor_score, home_score_extra, visitor_score_extra, situation,
	 *   stadium_pub, attendance, duration
	 */
	public function sync_match_row( array $a ): void {
		global $wpdb;

		$home_step = $this->ensure_teamstep( (int) $a['home_team_pub'], (string) $a['season'] );
		$away_step = $this->ensure_teamstep( (int) $a['away_team_pub'], (string) $a['season'] );

		// Group names computed here (not just below) so participants register
		// with their group immediately — see ensure_participant()'s docblock.
		$group_home = (string) ( $a['group_home'] ?? '' );
		$group_away = (string) ( $a['group_visitor'] ?? '' );

		// Register both teams as participants of the competition.
		$this->ensure_participant( $home_step, (int) $a['comp_pub_id'], $group_home );
		$this->ensure_participant( $away_step, (int) $a['comp_pub_id'], $group_away );

		// Phase row linkage: group stage (mixed_groups) or knockout phase —
		// classification and phase navigation read competition_phase_id.
		$phase_id   = 0;
		if ( '' !== $group_home || '' !== $group_away ) {
			$phase_id = $this->ensure_phase( (int) $a['comp_pub_id'], 'Fase de grupos', 'mixed_groups', 1 );
		} elseif ( ! empty( $a['phase'] ) && ( $def = self::phase_def( (string) $a['phase'] ) ) ) {
			$phase_id = $this->ensure_phase( (int) $a['comp_pub_id'], $def[0], $def[1], $def[2] );
		}

		// Jornada column: round-based matches (group/league stage) carry the
		// round in group_phase_round too — the jornada tabs read it. Knockouts
		// stay NULL, matching the legacy convention. Keyed on $a['round']
		// alone, not "$a['phase'] is empty" — import_matches() sets round_no
		// AND phase='league_phase' together for UEFA's new League Phase
		// format (round-based but still phase-tagged, unlike an ordinary
		// league where phase stays empty); a knockout $a['round'] is always
		// 0 (import_matches() never sets both a knockout phase and a round
		// number on the same matchday), so this is equivalent to the old
		// condition for every case that already worked, and also correctly
		// covers the new one.
		$gpr = (int) $a['round'] > 0 ? (int) $a['round'] : null;

		// competition_django_id holds the FEDCOMP (federation_competition_id)
		// public id — verified against legacy rows, e.g. every Primeira Liga
		// match carries 392, matching its competition's own
		// federation_competition_id column, regardless of edition. Templates
		// build /competicao/{id}/ links straight off this column (e.g. the
		// stadium page's últimos/próximos jogos list) — it was previously
		// bound to the MATCH's own pub id instead, producing a broken
		// competition link on every match we ever created. The competition
		// row (sync_competition_row) always runs before this method in the
		// only call site, so its federation_competition_id is already
		// current — legacy value untouched, or just-set for a new row.
		$fedcomp_pub = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT federation_competition_id FROM ' . $this->t( 'competition_competition' ) . ' WHERE id = %d',
			(int) $a['comp_pub_id']
		) );

		$sql = 'INSERT INTO ' . $this->t( 'competition_match' ) . '
			(id, competition_id, match_round, group_phase_round, phase, competition_phase_id, group_home_team, group_visitor_team,
			 date_time, home_team_id, home_score, home_score_extra,
			 visitor_team_id, visitor_score, visitor_score_extra, leg, situation, stadium_id, assistance,
			 duration, home_team_display_name, visitor_team_display_name, competition_display_name, competition_django_id)
			VALUES (%d, %d, %d, ' . ( null !== $gpr ? $gpr : 'NULL' ) . ', %s, ' . ( $phase_id ?: 'NULL' ) . ', %s, %s,
			 %s, %d, ' . self::sql_int_or_null( $a['home_score'] ) . ', ' . self::sql_int_or_null( $a['home_score_extra'] ) . ',
			 %d, ' . self::sql_int_or_null( $a['visitor_score'] ) . ', ' . self::sql_int_or_null( $a['visitor_score_extra'] ) . ', %s, %s, ' . ( (int) $a['stadium_pub'] ?: 'NULL' ) . ', %s,
			 %s, %s, %s, %s, %d)
			ON DUPLICATE KEY UPDATE
			 competition_id = VALUES(competition_id), match_round = VALUES(match_round),
			 group_phase_round = COALESCE( VALUES(group_phase_round), group_phase_round ),
			 phase = VALUES(phase),
			 competition_phase_id = COALESCE( VALUES(competition_phase_id), competition_phase_id ),
			 group_home_team = IF( VALUES(group_home_team) != \'\', VALUES(group_home_team), group_home_team ),
			 group_visitor_team = IF( VALUES(group_visitor_team) != \'\', VALUES(group_visitor_team), group_visitor_team ),
			 date_time = VALUES(date_time), home_team_id = VALUES(home_team_id), home_score = VALUES(home_score),
			 home_score_extra = VALUES(home_score_extra), visitor_team_id = VALUES(visitor_team_id),
			 visitor_score = VALUES(visitor_score), visitor_score_extra = VALUES(visitor_score_extra),
			 situation = VALUES(situation), stadium_id = VALUES(stadium_id), assistance = VALUES(assistance),
			 duration = VALUES(duration), home_team_display_name = VALUES(home_team_display_name),
			 visitor_team_display_name = VALUES(visitor_team_display_name),
			 competition_display_name = VALUES(competition_display_name),
			 competition_django_id = IF( VALUES(competition_django_id) != 0, VALUES(competition_django_id), competition_django_id )';

		$wpdb->query( $wpdb->prepare(
			$sql,
			(int) $a['pub_id'], (int) $a['comp_pub_id'], (int) $a['round'], (string) $a['phase'],
			$group_home, $group_away, (string) $a['date_time'],
			$home_step, $away_step,
			(string) $a['leg'], (string) $a['situation'], (string) $a['attendance'],
			(string) $a['duration'], (string) $a['home_name'], (string) $a['away_name'], (string) $a['comp_name'], $fedcomp_pub
		) );

		if ( $wpdb->last_error ) {
			$this->importer->log( 'TABLE FAIL competition_match id=' . $a['pub_id'] . ' — ' . $wpdb->last_error );
		}
	}

	/** Literal for nullable int columns ('' → NULL). */
	private static function sql_int_or_null( $v ): string {
		return ( '' === $v || null === $v ) ? 'NULL' : (string) (int) $v;
	}

	// ════════════════════════════════════════════════════════════════════════
	// MATCH DETAIL — lineups, events, stats (wipe + rebuild per match)
	// ════════════════════════════════════════════════════════════════════════

	/**
	 * Write matchplayer / matchplayerevent / matchstats rows from a full
	 * /matches/{id} payload. $player_pub is a resolver:
	 * fn(int $statorium_player_id): int → public player id.
	 */
	public function sync_match_detail( int $match_pub_id, array $match, callable $player_pub ): void {
		global $wpdb;

		$season   = '';
		$row      = $wpdb->get_row( $wpdb->prepare(
			'SELECT m.competition_id, m.home_team_id, m.visitor_team_id, m.duration, c.season
			   FROM ' . $this->t( 'competition_match' ) . ' m
			   LEFT JOIN ' . $this->t( 'competition_competition' ) . ' c ON c.id = m.competition_id
			  WHERE m.id = %d',
			$match_pub_id
		) );
		if ( ! $row ) {
			$this->importer->log( "TABLE FAIL detail: match row {$match_pub_id} missing" );
			return;
		}
		$duration = (int) ( $match['matchDuration'] ?? 0 ) ?: 90;

		// Wipe existing detail rows for idempotency.
		$mp_ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT id FROM ' . $this->t( 'competition_matchplayer' ) . ' WHERE match_id = %d', $match_pub_id
		) );
		if ( $mp_ids ) {
			$in = implode( ',', array_map( 'intval', $mp_ids ) );
			$wpdb->query( 'DELETE FROM ' . $this->t( 'competition_matchplayerevent' ) . " WHERE matchplayer_id IN ({$in})" );
			$wpdb->query( 'DELETE FROM ' . $this->t( 'competition_matchplayer' ) . ' WHERE match_id = ' . (int) $match_pub_id );
		}

		$live_events = [];

		foreach ( [ 'homeParticipant' => (int) $row->home_team_id, 'awayParticipant' => (int) $row->visitor_team_id ] as $side => $teamstep_id ) {
			$p     = (array) ( $match[ $side ] ?? [] );
			$squad = (array) ( $p['squad'] ?? [] );

			// National squads: the players' nationality is the team's country.
			$nationality_id = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT t.country_id FROM ' . $this->t( 'team_teamstep' ) . ' ts
				   JOIN ' . $this->t( 'team_team' ) . ' t ON t.id = ts.team_id
				  WHERE ts.id = %d AND t.team_type = %s',
				$teamstep_id, 'national'
			) );

			// Per-player aggregates from the events list.
			$agg = [];
			foreach ( (array) ( $p['events'] ?? [] ) as $ev ) {
				$this->aggregate_event( $agg, $ev );
				foreach ( (array) ( $ev['assist'] ?? [] ) as $nested ) {
					$this->aggregate_event( $agg, $nested );
				}
			}

			// Substitutions: minute in/out per player.
			$subs_in  = [];
			$subs_out = [];
			foreach ( (array) ( $squad['subs'] ?? [] ) as $sub ) {
				if ( ! empty( $sub['playerIN'] ) ) {
					$subs_in[ (int) $sub['playerIN'] ] = (int) $sub['minute'];
				}
				if ( ! empty( $sub['playerOUT'] ) ) {
					$subs_out[ (int) $sub['playerOUT'] ] = (int) $sub['minute'];
				}
			}

			foreach ( [ 'lineup' => 't', 'bench' => 'f' ] as $group => $titular ) {
				foreach ( (array) ( $squad[ $group ] ?? [] ) as $pl ) {
					$stat_pid = (int) ( $pl['playerID'] ?? 0 );
					if ( ! $stat_pid ) {
						continue;
					}
					$pub_pid = (int) $player_pub( $stat_pid );
					$info    = (array) ( $pl['additionalInfo'] ?? [] );

					// Guarantee person rows so the INNER JOINs hit.
					$birth = '';
					if ( preg_match( '/(\d{2})-(\d{2})-(\d{4})/', (string) ( $info['birthdate'] ?? '' ), $bd ) ) {
						$birth = "{$bd[3]}-{$bd[2]}-{$bd[1]}";
					}
					$this->sync_person_rows(
						$pub_pid,
						(string) ( $pl['playerFullName'] ?? ( $pl['fullName'] ?? '' ) ),
						(string) ( $pl['playerFullName'] ?? ( $pl['fullName'] ?? '' ) ),
						(string) ( $info['position'] ?? '' ),
						$birth,
						(string) ( $info['height'] ?? '' ),
						(string) ( $info['weight'] ?? '' ),
						$nationality_id
					);

					$a       = $agg[ $stat_pid ] ?? [];
					$in_min  = $subs_in[ $stat_pid ] ?? null;
					$out_min = $subs_out[ $stat_pid ] ?? null;

					if ( 't' === $titular ) {
						$minutes = null !== $out_min ? $out_min : $duration;
					} else {
						$minutes = null !== $in_min ? max( 0, $duration - $in_min ) : 0;
					}

					$wpdb->query( $wpdb->prepare(
						'INSERT INTO ' . $this->t( 'competition_matchplayer' ) . '
							(match_id, team_id, player_id, position, minutes, is_titular, is_in, is_out,
							 is_in_minute, is_out_minute, goals, goals_penalty, own_goals,
							 yellow_card, double_yellow_card, red_card,
							 yellow_card_minute, double_yellow_card_minute, red_card_minute, shirt_number)
						 VALUES (%d, %d, %d, %s, %d, %s, %s, %s,
							 ' . ( null !== $in_min ? (int) $in_min : 'NULL' ) . ', ' . ( null !== $out_min ? (int) $out_min : 'NULL' ) . ', %d, %d, %d,
							 %s, %s, %s,
							 ' . self::sql_int_or_null( $a['yellow_minute'] ?? '' ) . ', ' . self::sql_int_or_null( $a['double_yellow_minute'] ?? '' ) . ', ' . self::sql_int_or_null( $a['red_minute'] ?? '' ) . ', %s)',
						$match_pub_id, $teamstep_id, $pub_pid, $this->position( (string) ( $info['position'] ?? '' ) ), (int) $minutes,
						$titular,
						null !== $in_min ? 't' : 'f',
						null !== $out_min ? 't' : 'f',
						(int) ( $a['goals'] ?? 0 ), (int) ( $a['penalties'] ?? 0 ), (int) ( $a['own_goals'] ?? 0 ),
						( $a['yellow'] ?? 0 ) >= 1 ? 't' : 'f',
						( $a['yellow'] ?? 0 ) >= 2 ? 't' : 'f',
						( $a['red'] ?? 0 ) >= 1 ? 't' : 'f',
						(string) ( $pl['playerNumber'] ?? '' )
					) );
					$matchplayer_id = (int) $wpdb->insert_id;

					// Per-event rows (goals/cards/etc. with minutes).
					foreach ( (array) ( $p['events'] ?? [] ) as $ev ) {
						$all = array_merge( [ $ev ], (array) ( $ev['assist'] ?? [] ) );
						foreach ( $all as $e ) {
							if ( (int) ( $e['playerID'] ?? 0 ) === $stat_pid ) {
								$etype = $this->event_type( (string) ( $e['eventName'] ?? '' ) );
								$wpdb->query( $wpdb->prepare(
									'INSERT INTO ' . $this->t( 'competition_matchplayerevent' ) . '
										(matchplayer_id, event_type, minute, description)
									 VALUES (%d, %s, %d, %s)',
									$matchplayer_id, $etype, (int) ( $e['minute'] ?? 0 ), ''
								) );
								$live_events[] = [
									$etype,
									(string) ( $pl['shortName'] ?? ( $pl['playerFullName'] ?? $pl['fullName'] ?? '' ) ),
									(int) ( $e['minute'] ?? 0 ),
									'homeParticipant' === $side,
								];
							}
						}
					}
				}
			}
		}

		$this->sync_match_stats( $match_pub_id, $match );

		// Live-page event feed + Estatísticas (only when the livematch row
		// exists; stats copy AFTER sync_match_stats so they are fresh).
		$live_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $this->t( 'live_livematch' ) . ' WHERE match_id = %d', $match_pub_id ) );
		if ( $live_id ) {
			$this->sync_livematch_events( $live_id, $live_events );
			$this->sync_livematch_stats( $live_id, $match_pub_id );
			$this->sync_livematch_players( $live_id, $match_pub_id );
		}

		// Penalty shootout result. PRIMARY source: the top-level `stages`
		// array (stageID 4 = "Penalty Shootout", carries the authoritative
		// final tally directly — e.g. {"stageID":4,"home_score":2,
		// "away_score":3}). FALLBACK: count individual 'Penalty shootout
		// scored/missed' events per player (also still needed regardless,
		// for the Ficha's per-kick detail — this only supplies the
		// aggregate score). A disagreement between the two is logged, never
		// silently picked.
		$pens = [ 'homeParticipant' => null, 'awayParticipant' => null ];
		foreach ( $pens as $side => $ignore ) {
			$scored = 0;
			$took   = 0;
			foreach ( (array) ( $match[ $side ]['events'] ?? [] ) as $ev ) {
				$name = (string) ( $ev['eventName'] ?? '' );
				if ( 'Penalty shootout scored' === $name ) {
					$scored++;
					$took++;
				} elseif ( 'Penalty shootout missed' === $name ) {
					$took++;
				}
			}
			$pens[ $side ] = $took > 0 ? $scored : null;
		}

		$shootout_stage = null;
		foreach ( (array) ( $match['stages'] ?? [] ) as $stage ) {
			if ( 4 === (int) ( $stage['stageID'] ?? 0 ) ) {
				$shootout_stage = $stage;
				break;
			}
		}
		if ( $shootout_stage && null !== $shootout_stage['home_score'] && null !== $shootout_stage['away_score'] ) {
			$stage_home = (int) $shootout_stage['home_score'];
			$stage_away = (int) $shootout_stage['away_score'];
			if ( null !== $pens['homeParticipant'] && ( $pens['homeParticipant'] !== $stage_home || $pens['awayParticipant'] !== $stage_away ) ) {
				$this->importer->log( "PENALTY MISMATCH match={$match_pub_id}: stages={$stage_home}-{$stage_away} vs event-count={$pens['homeParticipant']}-{$pens['awayParticipant']} — using stages (authoritative)" );
			}
			$pens['homeParticipant'] = $stage_home;
			$pens['awayParticipant'] = $stage_away;
		}

		// Attendance / duration / shootout onto the match row.
		$att = (string) ( $match['additionalInfo']['attendance'] ?? '' );
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . $this->t( 'competition_match' ) . '
			    SET assistance = %s, duration = %s,
			        penalty_home_score = ' . ( null !== $pens['homeParticipant'] ? (int) $pens['homeParticipant'] : 'penalty_home_score' ) . ',
			        penalty_visitor_score = ' . ( null !== $pens['awayParticipant'] ? (int) $pens['awayParticipant'] : 'penalty_visitor_score' ) . '
			  WHERE id = %d',
			$att, (string) $duration, $match_pub_id
		) );

		// Career table (player pages): refresh squads for this competition.
		$this->rebuild_squads( (int) $row->competition_id );

		$this->importer->log( "TABLE detail synced match={$match_pub_id}" );
	}

	private function aggregate_event( array &$agg, array $ev ): void {
		$pid = (int) ( $ev['playerID'] ?? 0 );
		if ( ! $pid ) {
			return;
		}
		$name   = (string) ( $ev['eventName'] ?? '' );
		$minute = (int) ( $ev['minute'] ?? 0 );
		$agg[ $pid ] = $agg[ $pid ] ?? [ 'goals' => 0, 'penalties' => 0, 'own_goals' => 0, 'yellow' => 0, 'red' => 0, 'yellow_minute' => '', 'double_yellow_minute' => '', 'red_minute' => '' ];

		switch ( $name ) {
			case 'Goal':
				$agg[ $pid ]['goals']++;
				break;
			// "Penalty goal" is what the live API actually sends for a scored
			// penalty (verified against real payloads — a match with one went
			// undercounted by exactly one goal, breaking scoreboard
			// reconciliation for the season-close ranking finalize). Bare
			// "Penalty" kept for safety, in case another feed shape uses it.
			case 'Penalty':
			case 'Penalty goal':
				$agg[ $pid ]['goals']++;
				$agg[ $pid ]['penalties']++;
				break;
			case 'Own goal':
				$agg[ $pid ]['own_goals']++;
				break;
			case 'Yellow card':
				$agg[ $pid ]['yellow']++;
				if ( '' === $agg[ $pid ]['yellow_minute'] ) {
					$agg[ $pid ]['yellow_minute'] = $minute;
				}
				break;
			// Fires alongside "Yellow card" at the same minute for the same
			// player (the API's dismissal flag, not an extra card) — force
			// the yellow count to 2 so double_yellow_card comes out 't' even
			// on the rare payload where the accompanying "Yellow card" entry
			// is absent.
			case 'Second yellow':
				$agg[ $pid ]['yellow'] = max( $agg[ $pid ]['yellow'], 2 );
				if ( '' === $agg[ $pid ]['yellow_minute'] ) {
					$agg[ $pid ]['yellow_minute'] = $minute;
				}
				$agg[ $pid ]['double_yellow_minute'] = $minute;
				break;
			case 'Red card':
				$agg[ $pid ]['red']++;
				$agg[ $pid ]['red_minute'] = $minute;
				break;
		}
	}

	/** Map Statorium teamStats → wp_competition_matchstats + match.stats_id. */
	private function sync_match_stats( int $match_pub_id, array $match ): void {
		global $wpdb;

		$stat_map = apply_filters( 'f365_statorium_teamstat_map', [
			'Possession'      => 'ball_possession',
			'Total shots'     => 'shots',
			'Shots on target' => 'shots_on_goal',
			'Corners'         => 'corner_kicks',
			'Offsides'        => 'offsides',
			'Fouls'           => 'fouls',
			'Saves'           => 'saves',
			'Free kicks'      => 'free_kicks',
			'Throw-ins'       => 'throw_ins',
			'Yellow cards'    => 'cautions',
			'Red cards'       => 'expulsions',
		] );

		$values = [];
		foreach ( [ 'homeParticipant' => 'home', 'awayParticipant' => 'visitor' ] as $side => $prefix ) {
			foreach ( (array) ( $match[ $side ]['teamStats'] ?? [] ) as $stat ) {
				$col = $stat_map[ (string) ( $stat['teamStatName'] ?? '' ) ] ?? null;
				if ( $col ) {
					$values[ "{$prefix}_{$col}" ] = (int) ( $stat['count'] ?? 0 );
				}
			}
		}
		if ( ! $values ) {
			return;
		}

		$stats_id = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT stats_id FROM ' . $this->t( 'competition_match' ) . ' WHERE id = %d', $match_pub_id
		) );

		if ( $stats_id ) {
			$sets = implode( ', ', array_map( fn( $c ) => "`{$c}` = " . (int) $values[ $c ], array_keys( $values ) ) );
			$wpdb->query( 'UPDATE ' . $this->t( 'competition_matchstats' ) . " SET {$sets} WHERE id = " . $stats_id );
		} else {
			$cols = '`' . implode( '`, `', array_keys( $values ) ) . '`';
			$vals = implode( ', ', array_map( 'intval', $values ) );
			$wpdb->query( 'INSERT INTO ' . $this->t( 'competition_matchstats' ) . " ({$cols}) VALUES ({$vals})" );
			$stats_id = (int) $wpdb->insert_id;
			$wpdb->query( $wpdb->prepare(
				'UPDATE ' . $this->t( 'competition_match' ) . ' SET stats_id = %d WHERE id = %d',
				$stats_id, $match_pub_id
			) );
		}
	}

	// ════════════════════════════════════════════════════════════════════════
	// LIVE TABLES — the /jogos-em-direto/ pages read wp_live_* rows, keyed on
	// wp_live_livematch.id (each backed by an f365_livegames post).
	// Status vocabulary (theme f365_livegame_status): '-1' scheduled, numeric
	// string = live minute, HT, ET, P (penalties), RF finished, AD, SP.
	// ════════════════════════════════════════════════════════════════════════

	/** Ensure the livematch header row exists; returns its id. */
	public function ensure_livematch( int $match_pub, int $comp_pub, string $datetime, string $home_name, string $visitor_name ): int {
		global $wpdb;
		$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $this->t( 'live_livematch' ) . ' WHERE match_id = %d', $match_pub ) );
		if ( $existing ) {
			return (int) $existing;
		}
		$wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . $this->t( 'live_livematch' ) . '
				(competition_id, match_id, datetime, external_competitionid, external_matchid,
				 home_team_name, visitor_team_name, home_score, visitor_score, status, goal, is_mapped)
			 VALUES (%d, %d, %s, 0, 0, %s, %s, %s, %s, %s, %s, %s)',
			$comp_pub, $match_pub, $datetime, $home_name, $visitor_name, '-', '-', '-1', 'f', 't'
		) );
		return (int) $wpdb->insert_id;
	}

	/** Update livematch scores/status; sets the goal flash when a score changed. */
	public function update_livematch( int $match_pub, string $status, $home_score, $visitor_score, string $home_extra = '', string $visitor_extra = '', string $pen_home = '', string $pen_visitor = '' ): void {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, home_score, visitor_score FROM ' . $this->t( 'live_livematch' ) . ' WHERE match_id = %d', $match_pub ) );
		if ( ! $row ) {
			return;
		}
		$h    = ( null === $home_score || '' === (string) $home_score ) ? '-' : (string) (int) $home_score;
		$v    = ( null === $visitor_score || '' === (string) $visitor_score ) ? '-' : (string) (int) $visitor_score;
		$goal = ( $h !== (string) $row->home_score || $v !== (string) $row->visitor_score )
			&& '-' !== $h && '-1' !== $status ? 't' : 'f';
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . $this->t( 'live_livematch' ) . '
			    SET status = %s, home_score = %s, visitor_score = %s, goal = %s,
			        home_score_extra = %s, visitor_score_extra = %s,
			        penalty_home_score = %s, penalty_visitor_score = %s,
			        finished_at = ' . ( 'RF' === $status ? 'COALESCE(finished_at, NOW())' : 'finished_at' ) . '
			  WHERE id = %d',
			$status, $h, $v, $goal, $home_extra, $visitor_extra, $pen_home, $pen_visitor, (int) $row->id
		) );
	}

	/**
	 * Fill the live Estatísticas table from the match stats already stored in
	 * competition_matchstats (same teamStats source). Creates or updates the
	 * live_livematchstats row and links it via live_livematch.stats_id.
	 */
	public function sync_livematch_stats( int $live_id, int $match_pub ): void {
		global $wpdb;
		$src = $wpdb->get_row( $wpdb->prepare(
			'SELECT s.* FROM ' . $this->t( 'competition_match' ) . ' m
			   JOIN ' . $this->t( 'competition_matchstats' ) . ' s ON s.id = m.stats_id
			  WHERE m.id = %d',
			$match_pub
		), ARRAY_A );
		if ( ! $src ) {
			return;
		}

		$cols = [];
		foreach ( [ 'ball_possession', 'shots', 'shots_on_goal', 'fouls', 'cautions', 'expulsions', 'corner_kicks', 'offsides', 'saves', 'free_kicks', 'throw_ins' ] as $stat ) {
			foreach ( [ 'home_', 'visitor_' ] as $side ) {
				if ( array_key_exists( $side . $stat, $src ) ) {
					$cols[ $side . $stat ] = null === $src[ $side . $stat ] ? null : (int) $src[ $side . $stat ];
				}
			}
		}
		if ( ! $cols ) {
			return;
		}

		$stats_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT stats_id FROM ' . $this->t( 'live_livematch' ) . ' WHERE id = %d', $live_id ) );
		if ( $stats_id ) {
			$wpdb->update( $this->t( 'live_livematchstats' ), $cols, [ 'id' => $stats_id ] );
		} else {
			$wpdb->insert( $this->t( 'live_livematchstats' ), $cols );
			$new_id = (int) $wpdb->insert_id;
			if ( $new_id ) {
				$wpdb->update( $this->t( 'live_livematch' ), [ 'stats_id' => $new_id ], [ 'id' => $live_id ] );
			}
		}
	}

	/**
	 * Gap-fill `city`/`foundation` on the team_team MIRROR row. The team
	 * header and Info tab read these columns DIRECTLY (not postmeta/ACF —
	 * enrich_team_profile's write_fields() only reaches the admin editor),
	 * so without this the fields stay blank on the frontend even once the
	 * postmeta is correct. Fill-gap: only touches NULL/empty values, so a
	 * legacy team's existing data is never overwritten.
	 */
	public function update_team_profile_row( int $pub_id, string $city, string $foundation ): void {
		global $wpdb;
		if ( '' !== $city ) {
			$wpdb->query( $wpdb->prepare(
				'UPDATE ' . $this->t( 'team_team' ) . " SET city = %s WHERE id = %d AND ( city IS NULL OR city = '' )",
				$city, $pub_id
			) );
		}
		if ( '' !== $foundation ) {
			$wpdb->query( $wpdb->prepare(
				'UPDATE ' . $this->t( 'team_team' ) . ' SET foundation = %s WHERE id = %d AND foundation IS NULL',
				$foundation, $pub_id
			) );
		}
	}

	/**
	 * Fill the live Ficha lineups (live_livematchplayer + per-player events,
	 * incl. substitutions) from the mirror matchplayer rows. Coach/formation
	 * are NOT written — the API provides neither. Wipe & rebuild.
	 */
	public function sync_livematch_players( int $live_id, int $match_pub ): void {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT mp.id, mp.player_id, mp.shirt_number, mp.is_titular, mp.is_in, mp.is_out,
			        mp.is_in_minute, mp.is_out_minute,
			        IF( mp.team_id = m.home_team_id, 1, 0 ) AS is_home,
			        pr.shortname AS player_name
			   FROM ' . $this->t( 'competition_matchplayer' ) . ' mp
			   JOIN ' . $this->t( 'competition_match' ) . ' m ON m.id = mp.match_id
			   LEFT JOIN ' . $this->t( 'person_player' ) . ' pl ON pl.id = mp.player_id
			   LEFT JOIN ' . $this->t( 'person_person' ) . ' pr ON pr.id = pl.person_id
			  WHERE mp.match_id = %d
			  ORDER BY is_home DESC, mp.is_titular DESC, CAST( mp.shirt_number AS UNSIGNED ) ASC',
			$match_pub
		) );
		if ( ! $rows ) {
			return;
		}

		$old = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . $this->t( 'live_livematchplayer' ) . ' WHERE live_match_id = %d', $live_id ) );
		if ( $old ) {
			$wpdb->query( 'DELETE FROM ' . $this->t( 'live_livematchplayerevent' ) . ' WHERE live_match_player_id IN (' . implode( ',', array_map( 'intval', $old ) ) . ')' );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->t( 'live_livematchplayer' ) . ' WHERE live_match_id = %d', $live_id ) );
		}

		$event_map = [ 'goal' => 'goal', 'penalty' => 'goal_penalty', 'own_goal' => 'own_goal', 'yellow_card' => 'yellow_card', 'red_card' => 'red_card' ];
		$order     = [ 1 => 0, 0 => 0 ];

		foreach ( $rows as $r ) {
			$wpdb->query( $wpdb->prepare(
				'INSERT INTO ' . $this->t( 'live_livematchplayer' ) . '
					(live_match_id, external_player_id, player_name, player_number, player_order, is_field_player, is_home_team)
				 VALUES (%d, %d, %s, %d, %d, %s, %s)',
				$live_id, (int) $r->player_id, mb_substr( (string) ( $r->player_name ?: 'Jogador' ), 0, 50 ),
				(int) $r->shirt_number, $order[ (int) $r->is_home ]++,
				't' === $r->is_titular ? 't' : 'f', $r->is_home ? 't' : 'f'
			) );
			$lmp_id = (int) $wpdb->insert_id;

			$events = $wpdb->get_results( $wpdb->prepare(
				'SELECT event_type, minute FROM ' . $this->t( 'competition_matchplayerevent' ) . ' WHERE matchplayer_id = %d ORDER BY minute',
				(int) $r->id
			) );
			foreach ( $events as $e ) {
				if ( isset( $event_map[ $e->event_type ] ) ) {
					$wpdb->query( $wpdb->prepare(
						'INSERT INTO ' . $this->t( 'live_livematchplayerevent' ) . ' (live_match_player_id, event_type, minute) VALUES (%d, %s, %d)',
						$lmp_id, $event_map[ $e->event_type ], (int) $e->minute
					) );
				}
			}
			if ( 't' === $r->is_in && null !== $r->is_in_minute ) {
				$wpdb->query( $wpdb->prepare(
					'INSERT INTO ' . $this->t( 'live_livematchplayerevent' ) . " (live_match_player_id, event_type, minute) VALUES (%d, 'substitution_in', %d)",
					$lmp_id, (int) $r->is_in_minute
				) );
			}
			if ( 't' === $r->is_out && null !== $r->is_out_minute ) {
				$wpdb->query( $wpdb->prepare(
					'INSERT INTO ' . $this->t( 'live_livematchplayerevent' ) . " (live_match_player_id, event_type, minute) VALUES (%d, 'substitution_out', %d)",
					$lmp_id, (int) $r->is_out_minute
				) );
			}
		}
	}

	/**
	 * Wipe & rebuild the live event feed for a livematch from importer event
	 * rows [ [type, player_name, minute, is_home(bool)] … ]. Types are OUR
	 * matchplayerevent vocabulary; mapped here to the live vocabulary.
	 */
	public function sync_livematch_events( int $live_id, array $events ): void {
		global $wpdb;
		$map = [
			'goal'        => 'goal',
			'penalty'     => 'goal_penalty',
			'own_goal'    => 'own_goal',
			'yellow_card' => 'yellow_card',
			'red_card'    => 'red_card',
		];
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->t( 'live_livematchevent' ) . ' WHERE live_match_id = %d', $live_id ) );
		foreach ( $events as [ $type, $player, $minute, $is_home ] ) {
			if ( ! isset( $map[ $type ] ) ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare(
				'INSERT INTO ' . $this->t( 'live_livematchevent' ) . ' (live_match_id, event_type, player_name, minute, is_home_team)
				 VALUES (%d, %s, %s, %d, %s)',
				$live_id, $map[ $type ], mb_substr( $player, 0, 50 ), (int) $minute, $is_home ? 't' : 'f'
			) );
		}
	}
}
