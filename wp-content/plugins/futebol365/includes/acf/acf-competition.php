<?php
/**
 * ACF field groups for Competition & Match — v2.3.0 (WordPress-native model).
 *
 * DATA MODEL (no custom tables):
 *   - f365_competition / f365_match : CPTs (permalinks, SEO, admin).
 *   - Relationships (competition, teams, stadium, players, referees, coaches)
 *     are ACF `post_object` fields returning the related post ID — i.e. real
 *     WordPress post relationships, joinable through core postmeta.
 *   - The one genuinely high-volume child — a player's appearance in a match —
 *     is its OWN CPT (f365_match_player), NOT a repeater. See the field group
 *     at the bottom of this file and register-competition-match-cpt.php.
 *   - Only small, bounded per-post lists use ACF Repeaters:
 *       Competition: phases (≈2-6), participants (≈4-28), standings (≈4-40),
 *                    places/colours (≈4-28)
 *       Match:       referees (1-4), coaches (1-3)
 *       Match Player: events (0-3 goals/cards/sub markers)
 *     None of these grow with the size of the whole dataset, so they never hit
 *     the repeater scaling problem.
 *
 * Covers Django models: Competition, CompetitionPhase, Participant,
 * CompetitionPlace, Match, MatchStats, MatchReferee, MatchCoach, MatchPlayer,
 * MatchPlayerEvent.
 *
 * @package Futebol365
 * @since   2.3.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'acf/init', 'f365_acf_competition' );

function f365_acf_competition() {

	// =======================================================================
	// COMPETITION  (Django: Competition + Phase + Participant + Standings)
	// =======================================================================
	acf_add_local_field_group( [
		'key'      => 'group_f365_competition',
		'title'    => 'Competition Details',
		'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'f365_competition' ] ] ],
		'fields'   => [

			// --- Identity / relationships ---
			[
				'key' => 'field_comp_tab_general',
				'label' => 'General',
				'type' => 'tab',
				'placement' => 'top',
			],
			[ 'key' => 'field_comp_fed_comp', 'label' => 'Federation Competition', 'name' => 'federation_competition', 'type' => 'post_object', 'post_type' => [ 'f365_fed_comp' ], 'return_format' => 'id' ],
			[ 'key' => 'field_comp_edition',  'label' => 'Edition (year)', 'name' => 'edition', 'type' => 'text', 'instructions' => 'Used together with the Federation Competition for the /competicao/{fed_comp_id}/?edition= permalink.' ],
			[ 'key' => 'field_comp_season',   'label' => 'Season label',   'name' => 'season',  'type' => 'text' ],
			[ 'key' => 'field_comp_begin',    'label' => 'Start Date',     'name' => 'begin',   'type' => 'date_picker', 'display_format' => 'd/m/Y', 'return_format' => 'Y-m-d' ],
			[ 'key' => 'field_comp_end',      'label' => 'End Date',       'name' => 'end',     'type' => 'date_picker', 'display_format' => 'd/m/Y', 'return_format' => 'Y-m-d' ],
			[ 'key' => 'field_comp_local',    'label' => 'Local / Host',   'name' => 'local',   'type' => 'text' ],
			[ 'key' => 'field_comp_notes',    'label' => 'Notes',          'name' => 'notes',   'type' => 'textarea' ],

			// --- Standings rules (scalar) ---
			[
				'key' => 'field_comp_tab_rules',
				'label' => 'Rules & Settings',
				'type' => 'tab',
				'placement' => 'top',
			],
			[ 'key' => 'field_comp_victory_pts',     'label' => 'Victory Points',    'name' => 'victory_points',    'type' => 'number', 'default_value' => 3 ],
			[ 'key' => 'field_comp_draw_pts',        'label' => 'Draw Points',       'name' => 'draw_points',       'type' => 'number', 'default_value' => 1 ],
			[ 'key' => 'field_comp_defeat_pts',      'label' => 'Defeat Points',     'name' => 'defeat_points',     'type' => 'number', 'default_value' => 0 ],
			[ 'key' => 'field_comp_rise_places',     'label' => 'Rise Places',       'name' => 'rise_places',       'type' => 'number', 'default_value' => 0 ],
			[ 'key' => 'field_comp_relegate_places', 'label' => 'Relegation Places', 'name' => 'relegation_places', 'type' => 'number', 'default_value' => 0 ],
			[ 'key' => 'field_comp_stats_start',     'label' => 'Statistics Start Round', 'name' => 'statistics_start', 'type' => 'number' ],

			// --- Winner teams (post relationships → team post IDs) ---
			[
				'key' => 'field_comp_tab_teams',
				'label' => 'Teams',
				'type' => 'tab',
				'placement' => 'top', 
			],
			[ 'key' => 'field_comp_first_place',  'label' => '1st Place TeamStep', 'name' => 'first_place_team',  'type' => 'post_object', 'post_type' => [ 'f365_teamstep' ], 'return_format' => 'id', 'required' => 0 ],
			[ 'key' => 'field_comp_second_place', 'label' => '2nd Place TeamStep', 'name' => 'second_place_team', 'type' => 'post_object', 'post_type' => [ 'f365_teamstep' ], 'return_format' => 'id', 'required' => 0 ],

			// --- Flags ---
			[ 'key' => 'field_comp_is_home_visitor', 'label' => 'Home/Visitor format', 'name' => 'is_home_visitor', 'type' => 'true_false', 'default_value' => 0 ],
			[ 'key' => 'field_comp_is_available',    'label' => 'Available',           'name' => 'is_available',    'type' => 'true_false', 'default_value' => 0 ],
			[ 'key' => 'field_comp_is_highlight',    'label' => 'Highlight',           'name' => 'is_highlight',    'type' => 'true_false', 'default_value' => 0 ],
			[ 'key' => 'field_comp_is_closed',       'label' => 'Is Closed',           'name' => 'is_closed',       'type' => 'true_false', 'default_value' => 0 ],

			// --- PHASES (bounded: a few per competition) ---
			[
				'key' => 'field_comp_tab_phases',
				'label' => 'Phases',
				'type' => 'tab',
				'placement' => 'top',
			],
			[
				'key' => 'field_comp_phases', 'label' => 'Phases', 'name' => 'phases',
				'type' => 'repeater', 'layout' => 'table', 'button_label' => 'Add Phase',
				'instructions' => 'Few rows per competition (group stage, knockout, …). Matches reference a phase by its Django ID.',
				'sub_fields' => [
					//[ 'key' => 'field_ph_dj_id', 'label' => 'Django ID',  'name' => 'django_id',  'type' => 'number', 'readonly' => 1 ],
					[ 'key' => 'field_ph_order', 'label' => 'Order',      'name' => 'phase_order','type' => 'number' ],
					[ 'key' => 'field_ph_name',  'label' => 'Name',       'name' => 'name',       'type' => 'text' ],
					[ 'key' => 'field_ph_type',  'label' => 'Type',       'name' => 'phase_type', 'type' => 'text' ],
				],
			],

			// --- PARTICIPANTS (bounded: ≤ ~28; real team post relationships) ---
			[
				'key' => 'field_comp_tab_participants',
				'label' => 'Participants',
				'type' => 'tab',
				'placement' => 'top',
			],
			[
				'key' => 'field_comp_participants', 'label' => 'Participants', 'name' => 'participants',
				'type' => 'repeater', 'layout' => 'table', 'button_label' => 'Add Participant',
				'instructions' => 'One row per team (a post relationship to f365_team) plus its group per phase. Bounded by the number of entrants.',
				'sub_fields' => [
					[ 'key' => 'field_pt_team',  'label' => 'Team',  'name' => 'team',  'type' => 'post_object', 'post_type' => [ 'f365_team' ], 'return_format' => 'id' ],
					[ 'key' => 'field_pt_g1',    'label' => 'Group (Phase 1)', 'name' => 'group_phase_one',   'type' => 'text' ],
					[ 'key' => 'field_pt_g2',    'label' => 'Group (Phase 2)', 'name' => 'group_phase_two',   'type' => 'text' ],
					[ 'key' => 'field_pt_g3',    'label' => 'Group (Phase 3)', 'name' => 'group_phase_three', 'type' => 'text' ],
					//[ 'key' => 'field_pt_dj_ts', 'label' => 'Django TeamStep ID', 'name' => 'django_teamstep_id', 'type' => 'number', 'readonly' => 1 ],
				],
			],

			// --- STANDINGS (materialised by the importer; bounded ≈ teams × groups) ---
			[
				'key' => 'field_comp_tab_standings',
				'label' => 'Standings',
				'type' => 'tab',
				'placement' => 'top',
			],
			[
				'key' => 'field_comp_standings', 'label' => 'Standings (computed)', 'name' => 'standings',
				'type' => 'repeater', 'layout' => 'table', 'button_label' => 'Add Row',
				'instructions' => 'Computed from finished matches by the importer / refresh. One row per team (post relationship) per group — bounded, never the whole match dataset.',
				'sub_fields' => [
					[ 'key' => 'field_st_team',  'label' => 'Team',     'name' => 'team',     'type' => 'post_object', 'post_type' => [ 'f365_team' ], 'return_format' => 'id' ],
					[ 'key' => 'field_st_phase', 'label' => 'Phase ID', 'name' => 'phase_id', 'type' => 'number' ],
					[ 'key' => 'field_st_group', 'label' => 'Group',    'name' => 'group',    'type' => 'text' ],
					[ 'key' => 'field_st_pos',   'label' => 'Pos',      'name' => 'position', 'type' => 'number' ],
					[ 'key' => 'field_st_pl',    'label' => 'P',        'name' => 'played',   'type' => 'number' ],
					[ 'key' => 'field_st_w',     'label' => 'W',        'name' => 'won',      'type' => 'number' ],
					[ 'key' => 'field_st_d',     'label' => 'D',        'name' => 'drawn',    'type' => 'number' ],
					[ 'key' => 'field_st_l',     'label' => 'L',        'name' => 'lost',     'type' => 'number' ],
					[ 'key' => 'field_st_gf',    'label' => 'GF',       'name' => 'goals_for','type' => 'number' ],
					[ 'key' => 'field_st_ga',    'label' => 'GA',       'name' => 'goals_against', 'type' => 'number' ],
					[ 'key' => 'field_st_gd',    'label' => 'GD',       'name' => 'goal_diff','type' => 'number' ],
					[ 'key' => 'field_st_pts',   'label' => 'Pts',      'name' => 'points',   'type' => 'number' ],
					[ 'key' => 'field_st_color', 'label' => 'Colour',   'name' => 'color',    'type' => 'text' ],
				],
			],

			// --- PHASE WINNERS (honours per phase/group; bounded) ---
			[
				'key' => 'field_comp_tab_winners',
				'label' => 'Phase Winners',
				'type' => 'tab',
				'placement' => 'top',
			],
			[
				'key' => 'field_comp_phase_winners', 'label' => 'Phase Winners (honours)', 'name' => 'phase_winners',
				'type' => 'repeater', 'layout' => 'table', 'button_label' => 'Add Winner',
				'instructions' => 'One row per phase/group winner (a handful per competition). Team links are post relationships.',
				'sub_fields' => [
					[ 'key' => 'field_pw_phase',  'label' => 'Phase', 'name' => 'phase_name', 'type' => 'text' ],
					[ 'key' => 'field_pw_group',  'label' => 'Group', 'name' => 'group',      'type' => 'text' ],
					[ 'key' => 'field_pw_first',  'label' => '1st',   'name' => 'first_place_team',  'type' => 'post_object', 'post_type' => [ 'f365_team' ], 'return_format' => 'id' ],
					[ 'key' => 'field_pw_second', 'label' => '2nd',   'name' => 'second_place_team', 'type' => 'post_object', 'post_type' => [ 'f365_team' ], 'return_format' => 'id' ],
					[ 'key' => 'field_pw_order',  'label' => 'Order', 'name' => 'order',       'type' => 'number' ],
					//[ 'key' => 'field_pw_dj1',    'label' => 'Django 1st TS', 'name' => 'django_first_ts_id',  'type' => 'number', 'readonly' => 1 ],
					//[ 'key' => 'field_pw_dj2',    'label' => 'Django 2nd TS', 'name' => 'django_second_ts_id', 'type' => 'number', 'readonly' => 1 ],
				],
			],

			// --- CLASSIFICATION ORDER (tiebreakers; bounded ≤ ~6) ---
			[
				'key' => 'field_comp_tab_classification',
				'label' => 'Classification',
				'type' => 'tab',
				'placement' => 'top',
			],
			[
				'key' => 'field_comp_class_order', 'label' => 'Classification Order (tiebreakers)', 'name' => 'classification_order',
				'type' => 'repeater', 'layout' => 'table', 'button_label' => 'Add Rule',
				'instructions' => 'Ordered tiebreakers applied after points. Drives how standings are sorted.',
				'sub_fields' => [
					[ 'key' => 'field_co_order',  'label' => 'Order', 'name' => 'order', 'type' => 'number' ],
					[
						'key' => 'field_co_method', 'label' => 'Method', 'name' => 'order_method', 'type' => 'select',
						'choices' => [
							'points_between'          => 'Points (head-to-head)',
							'goal_difference_between' => 'Goal difference (head-to-head)',
							'visitor_goals_between'   => 'Away goals (head-to-head)',
							'goal_difference'         => 'Goal difference (total)',
							'victories'               => 'Victories (total)',
							'scored_goals'            => 'Goals scored (total)',
						],
					],
					[ 'key' => 'field_co_last', 'label' => 'Last round only', 'name' => 'last_round_only', 'type' => 'true_false', 'default_value' => 0 ],
				],
			],

			// --- CLASSIFICATION OFFSETS (per-team point adjustments; usually empty) ---
			[
				'key' => 'field_comp_class_offsets', 'label' => 'Classification Offsets (point adjustments)', 'name' => 'classification_offsets',
				'type' => 'repeater', 'layout' => 'table', 'button_label' => 'Add Offset',
				'instructions' => 'Per-team points bonus/deduction (e.g. -3). Team is a post relationship. Added to points before sorting.',
				'sub_fields' => [
					[ 'key' => 'field_cof_team',   'label' => 'Team',   'name' => 'team',   'type' => 'post_object', 'post_type' => [ 'f365_team' ], 'return_format' => 'id' ],
					[ 'key' => 'field_cof_phase',  'label' => 'Phase',  'name' => 'phase',  'type' => 'text' ],
					[ 'key' => 'field_cof_group',  'label' => 'Group',  'name' => 'group',  'type' => 'text' ],
					[ 'key' => 'field_cof_offset', 'label' => 'Offset', 'name' => 'offset', 'type' => 'number', 'default_value' => 0 ],
					//[ 'key' => 'field_cof_djts',   'label' => 'Django TeamStep ID', 'name' => 'django_teamstep_id', 'type' => 'number', 'readonly' => 1 ],
				],
			],

			// --- LOW-VOLUME editorial config: standings position colours ---
			[
				'key' => 'field_comp_tab_places',
				'label' => 'Position Colours',
				'type' => 'tab',
				'placement' => 'top',
			],
			[
				'key' => 'field_comp_places', 'label' => 'Standings Position Colours', 'name' => 'competition_places',
				'type' => 'repeater', 'layout' => 'table', 'button_label' => 'Add Colour',
				'instructions' => 'Optional editorial colour overrides applied to standings positions.',
				'sub_fields' => [
					[ 'key' => 'field_cpl_position',    'label' => 'Position',    'name' => 'position',    'type' => 'number' ],
					[ 'key' => 'field_cpl_phase',       'label' => 'Phase',       'name' => 'phase',       'type' => 'text' ],
					[ 'key' => 'field_cpl_group',       'label' => 'Group',       'name' => 'group',       'type' => 'text' ],
					[ 'key' => 'field_cpl_color',       'label' => 'Color',       'name' => 'color',       'type' => 'color_picker' ],
					[ 'key' => 'field_cpl_description', 'label' => 'Description', 'name' => 'description', 'type' => 'text' ],
				],
			],
		],
	] );

	// =======================================================================
	// MATCH  (Django: Match + MatchStats[1:1] + MatchReferee + MatchCoach)
	// =======================================================================
	acf_add_local_field_group( [
		'key'      => 'group_f365_match',
		'title'    => 'Match Details',
		'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'f365_match' ] ] ],
		'fields'   => [
			[
				'key'   => 'tab_match_relationships',
				'label' => 'Relationships',
				'type'  => 'tab',
				'placement' => 'top',
			],
			// --- Core relationships (post relationships → post IDs) ---
			[ 'key' => 'field_m_competition',  'label' => 'Competition',  'name' => 'competition',  'type' => 'post_object', 'post_type' => [ 'f365_competition' ], 'return_format' => 'id' ],
			[ 'key' => 'field_m_home_team',    'label' => 'Home TeamStep',    'name' => 'home_teamstep',    'type' => 'post_object', 'post_type' => [ 'f365_teamstep' ],        'return_format' => 'id' ],
			[ 'key' => 'field_m_visitor_team', 'label' => 'Visitor TeamStep', 'name' => 'visitor_teamstep', 'type' => 'post_object', 'post_type' => [ 'f365_teamstep' ],        'return_format' => 'id' ],
			[ 'key' => 'field_m_stadium',      'label' => 'Stadium',      'name' => 'stadium',      'type' => 'post_object', 'post_type' => [ 'f365_stadium' ],     'return_format' => 'id', 'required' => 0 ],

			// --- Schedule / phase ---
			[
				'key'   => 'tab_match_info',
				'label' => 'Match Info',
				'type'  => 'tab',
			],
			[ 'key' => 'field_m_date_time',   'label' => 'Date & Time', 'name' => 'date_time',   'type' => 'date_time_picker', 'display_format' => 'd/m/Y H:i', 'return_format' => 'Y-m-d H:i:s' ],
			[ 'key' => 'field_m_match_round', 'label' => 'Round',       'name' => 'match_round', 'type' => 'number', 'default_value' => 1 ],
			[ 'key' => 'field_m_leg',         'label' => 'Leg (1/2)',   'name' => 'leg',         'type' => 'number' ],
			[ 'key' => 'field_m_phase',       'label' => 'Phase (legacy)', 'name' => 'phase',    'type' => 'text' ],
			[ 'key' => 'field_m_group_home',  'label' => 'Group (Home)',    'name' => 'group_home_team',    'type' => 'text' ],
			[ 'key' => 'field_m_group_vis',   'label' => 'Group (Visitor)', 'name' => 'group_visitor_team', 'type' => 'text' ],
			[ 'key' => 'field_m_phase_round', 'label' => 'Phase Round',     'name' => 'group_phase_round',  'type' => 'number' ],
			//[ 'key' => 'field_m_comp_phase_id','label' => 'Competition Phase ID', 'name' => 'competition_phase_id', 'type' => 'number', 'instructions' => 'Django ID of a row in the Competition Phases repeater.' ],
			[ 'key' => 'field_m_comp_phase',  'label' => 'Competition Phase (name)', 'name' => 'competition_phase_name', 'type' => 'text' ],

			// --- Score ---
			[
				'key'   => 'tab_match_score',
				'label' => 'Score',
				'type'  => 'tab',
			],
			[ 'key' => 'field_m_home_score',    'label' => 'Home Score',         'name' => 'home_score',          'type' => 'number' ],
			[ 'key' => 'field_m_visitor_score', 'label' => 'Visitor Score',      'name' => 'visitor_score',       'type' => 'number' ],
			[ 'key' => 'field_m_home_extra',    'label' => 'Home Extra Time',    'name' => 'home_score_extra',    'type' => 'number' ],
			[ 'key' => 'field_m_visitor_extra', 'label' => 'Visitor Extra Time', 'name' => 'visitor_score_extra', 'type' => 'number' ],
			[ 'key' => 'field_m_pen_home',      'label' => 'Penalty Home',       'name' => 'penalty_home_score',  'type' => 'number' ],
			[ 'key' => 'field_m_pen_visitor',   'label' => 'Penalty Visitor',    'name' => 'penalty_visitor_score','type' => 'number' ],

			// --- Status ---
			[
				'key'   => 'tab_match_status',
				'label' => 'Status',
				'type'  => 'tab',
			],
			[
				'key' => 'field_m_situation', 'label' => 'Situation', 'name' => 'situation', 'type' => 'select',
				'choices' => [ 'finished' => 'Finished', 'delayed' => 'Delayed', 'suspended' => 'Suspended', 'abrogated' => 'Abrogated' ],
			],
			[ 'key' => 'field_m_duration',     'label' => 'Duration (min)', 'name' => 'duration',     'type' => 'number' ],
			[ 'key' => 'field_m_assistance',   'label' => 'Attendance',     'name' => 'assistance',   'type' => 'text' ],
			[ 'key' => 'field_m_observations', 'label' => 'Observations',   'name' => 'observations', 'type' => 'textarea' ],

			// --- MatchStats (1:1 → flat meta) ---
			[
				'key'   => 'tab_match_stats',
				'label' => 'Statistics',
				'type'  => 'tab',
			],
			[ 'key' => 'field_ms_home_poss',   'label' => 'Home Possession %',    'name' => 'home_ball_possession',   'type' => 'number' ],
			[ 'key' => 'field_ms_home_shots',  'label' => 'Home Shots',           'name' => 'home_shots',             'type' => 'number' ],
			[ 'key' => 'field_ms_home_sog',    'label' => 'Home Shots on Goal',   'name' => 'home_shots_on_goal',     'type' => 'number' ],
			[ 'key' => 'field_ms_home_fouls',  'label' => 'Home Fouls',           'name' => 'home_fouls',             'type' => 'number' ],
			[ 'key' => 'field_ms_home_yellow', 'label' => 'Home Yellow Cards',    'name' => 'home_cautions',          'type' => 'number' ],
			[ 'key' => 'field_ms_home_red',    'label' => 'Home Red Cards',       'name' => 'home_expulsions',        'type' => 'number' ],
			[ 'key' => 'field_ms_home_corner', 'label' => 'Home Corner Kicks',    'name' => 'home_corner_kicks',      'type' => 'number' ],
			[ 'key' => 'field_ms_home_offside','label' => 'Home Offsides',        'name' => 'home_offsides',          'type' => 'number' ],
			[ 'key' => 'field_ms_home_saves',  'label' => 'Home Saves',           'name' => 'home_saves',             'type' => 'number' ],
			[ 'key' => 'field_ms_home_throw',  'label' => 'Home Throw-ins',       'name' => 'home_throw_ins',         'type' => 'number' ],
			[ 'key' => 'field_ms_home_free',   'label' => 'Home Free Kicks',      'name' => 'home_free_kicks',        'type' => 'number' ],
			[ 'key' => 'field_ms_vis_poss',    'label' => 'Visitor Possession %', 'name' => 'visitor_ball_possession','type' => 'number' ],
			[ 'key' => 'field_ms_vis_shots',   'label' => 'Visitor Shots',        'name' => 'visitor_shots',          'type' => 'number' ],
			[ 'key' => 'field_ms_vis_sog',     'label' => 'Visitor Shots on Goal','name' => 'visitor_shots_on_goal',  'type' => 'number' ],
			[ 'key' => 'field_ms_vis_fouls',   'label' => 'Visitor Fouls',        'name' => 'visitor_fouls',          'type' => 'number' ],
			[ 'key' => 'field_ms_vis_yellow',  'label' => 'Visitor Yellow Cards', 'name' => 'visitor_cautions',       'type' => 'number' ],
			[ 'key' => 'field_ms_vis_red',     'label' => 'Visitor Red Cards',    'name' => 'visitor_expulsions',     'type' => 'number' ],
			[ 'key' => 'field_ms_vis_corner',  'label' => 'Visitor Corner Kicks', 'name' => 'visitor_corner_kicks',   'type' => 'number' ],
			[ 'key' => 'field_ms_vis_offside', 'label' => 'Visitor Offsides',     'name' => 'visitor_offsides',       'type' => 'number' ],
			[ 'key' => 'field_ms_vis_saves',   'label' => 'Visitor Saves',        'name' => 'visitor_saves',          'type' => 'number' ],
			[ 'key' => 'field_ms_vis_throw',   'label' => 'Visitor Throw-ins',    'name' => 'visitor_throw_ins',      'type' => 'number' ],
			[ 'key' => 'field_ms_vis_free',    'label' => 'Visitor Free Kicks',   'name' => 'visitor_free_kicks',     'type' => 'number' ],

			// --- REFEREES (bounded 1-4; post relationships) ---
			[
				'key'   => 'tab_match_referees',
				'label' => 'Referees',
				'type'  => 'tab',
			],
			[
				'key' => 'field_m_referees', 'label' => 'Referees', 'name' => 'referees',
				'type' => 'repeater', 'layout' => 'table', 'button_label' => 'Add Referee',
				'sub_fields' => [
					[ 'key' => 'field_mr_ref',   'label' => 'Referee', 'name' => 'referee', 'type' => 'post_object', 'post_type' => [ 'f365_referee' ], 'return_format' => 'id' ],
					[ 'key' => 'field_mr_pos',   'label' => 'Position','name' => 'position','type' => 'text' ],
					[ 'key' => 'field_mr_class', 'label' => 'Classification', 'name' => 'classification', 'type' => 'text' ],
					[ 'key' => 'field_mr_order', 'label' => 'Order',   'name' => 'order',   'type' => 'number' ],
				],
			],

			// --- COACHES (bounded 1-3; post relationships) ---
			[
				'key'   => 'tab_match_coaches',
				'label' => 'Coaches',
				'type'  => 'tab',
			],
			[
				'key' => 'field_m_coaches', 'label' => 'Coaches', 'name' => 'coaches',
				'type' => 'repeater', 'layout' => 'table', 'button_label' => 'Add Coach',
				'sub_fields' => [
					[ 'key' => 'field_mc_coach', 'label' => 'Coach',   'name' => 'coach',   'type' => 'post_object', 'post_type' => [ 'f365_coach' ], 'return_format' => 'id' ],
					[ 'key' => 'field_mc_home',  'label' => 'Home Team?', 'name' => 'is_home', 'type' => 'true_false', 'default_value' => 0 ],
					[ 'key' => 'field_mc_type',  'label' => 'Type',    'name' => 'coach_type', 'type' => 'text' ],
					[ 'key' => 'field_mc_order', 'label' => 'Order',   'name' => 'order',   'type' => 'number' ],
				],
			],
			[
				'key'   => 'tab_match_matchformation',
				'label' => 'formation',
				'type'  => 'tab',
			],
			[
				'key' => 'field_m_matchformation', 'label' => 'Formation Detail', 'name' => 'formations',
				'type' => 'repeater', 'layout' => 'table', 'button_label' => 'Add formation',
				'sub_fields' => [
					[ 'key' => 'field_mf_formation', 'label' => 'Formation',   'name' => 'formation',   'type' => 'text' ],
					[ 'key' => 'field_mf_home',  'label' => 'Home Team?', 'name' => 'is_home_team', 'type' => 'true_false', 'default_value' => 0 ],
				],
			],

		],
	] );

	// =======================================================================
	// MATCH PLAYER  (Django: MatchPlayer + MatchPlayerEvent)
	// One post per appearance — the scalable home for the high-volume data.
	// =======================================================================
	acf_add_local_field_group( [
		'key'      => 'group_f365_match_player',
		'title'    => 'Match Player (Appearance)',
		'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'f365_match_player' ] ] ],
		'fields'   => [
			[
				'key' => 'tab_mp_relationships',
				'label' => 'Relationships',
				'type' => 'tab',
				'placement' => 'top',
			],

			// --- Relationships (post IDs) ---
			[ 'key' => 'field_mp_match',  'label' => 'Match',       'name' => 'match',       'type' => 'post_object', 'post_type' => [ 'f365_match' ],       'return_format' => 'id' ],
			[ 'key' => 'field_mp_comp',   'label' => 'Competition', 'name' => 'competition', 'type' => 'post_object', 'post_type' => [ 'f365_competition' ], 'return_format' => 'id', 'instructions' => 'Denormalised from the match so top-scorer aggregation is a single indexed query.' ],
			[ 'key' => 'field_mp_player', 'label' => 'Player',      'name' => 'player',      'type' => 'post_object', 'post_type' => [ 'f365_player' ],      'return_format' => 'id' ],
			[ 'key' => 'field_mp_team',   'label' => 'Team',        'name' => 'team',        'type' => 'post_object', 'post_type' => [ 'f365_team' ],        'return_format' => 'id' ],
			[ 'key' => 'field_mp_home',   'label' => 'Home Team?',  'name' => 'is_home',     'type' => 'true_false', 'default_value' => 0 ],

			// --- Appearance ---
			[
				'key' => 'tab_mp_appearance',
				'label' => 'Appearance',
				'type' => 'tab',
				'placement' => 'top',
			],
			[ 'key' => 'field_mp_shirt',  'label' => 'Shirt Number', 'name' => 'shirt_number', 'type' => 'number' ],
			[ 'key' => 'field_mp_pos',    'label' => 'Position',     'name' => 'position',     'type' => 'text' ],
			[ 'key' => 'field_mp_minutes','label' => 'Minutes',      'name' => 'minutes',      'type' => 'number' ],
			[ 'key' => 'field_mp_titular','label' => 'Starter?',     'name' => 'is_titular',   'type' => 'true_false', 'default_value' => 0 ],
			[ 'key' => 'field_mp_in',     'label' => 'Subbed In?',   'name' => 'is_in',        'type' => 'true_false', 'default_value' => 0 ],
			[ 'key' => 'field_mp_out',    'label' => 'Subbed Out?',  'name' => 'is_out',       'type' => 'true_false', 'default_value' => 0 ],
			[ 'key' => 'field_mp_in_min', 'label' => 'In Minute',    'name' => 'is_in_minute', 'type' => 'number' ],
			[ 'key' => 'field_mp_out_min','label' => 'Out Minute',   'name' => 'is_out_minute','type' => 'number' ],

			// --- Returns ---
			[
				'key' => 'tab_mp_stats',
				'label' => 'Stats',
				'type' => 'tab',
				'placement' => 'top',
			],

			[ 'key' => 'field_mp_goals',   'label' => 'Goals',          'name' => 'goals',         'type' => 'number', 'default_value' => 0 ],
			[ 'key' => 'field_mp_goals_p', 'label' => 'Penalty Goals',  'name' => 'goals_penalty', 'type' => 'number', 'default_value' => 0 ],
			[ 'key' => 'field_mp_owngoal', 'label' => 'Own Goals',      'name' => 'own_goals',     'type' => 'number', 'default_value' => 0 ],
			[ 'key' => 'field_mp_yellow',  'label' => 'Yellow Card',    'name' => 'yellow_card',   'type' => 'true_false', 'default_value' => 0 ],
			[ 'key' => 'field_mp_dyellow', 'label' => 'Double Yellow',  'name' => 'double_yellow_card', 'type' => 'true_false', 'default_value' => 0 ],
			[ 'key' => 'field_mp_red',     'label' => 'Red Card',       'name' => 'red_card',      'type' => 'true_false', 'default_value' => 0 ],
			[ 'key' => 'field_mp_yellow_card_minute',   'label' => 'Yellow Card minute',          'name' => 'yellow_card_minute',         'type' => 'number', 'default_value' => 0 ],
			[ 'key' => 'field_mp_double_yellow_card_minute',   'label' => 'Double Yellow Card minute',          'name' => 'double_yellow_card_minute',         'type' => 'number', 'default_value' => 0 ],
			[ 'key' => 'field_mp_red_card_minute',   'label' => 'Red Card minute',          'name' => 'red_card_minute',         'type' => 'number', 'default_value' => 0 ],
			
			

			// --- EVENTS (tiny per appearance: 0-3 markers) ---
			[
				'key' => 'tab_mp_events',
				'label' => 'Events',
				'type' => 'tab',
				'placement' => 'top',
			],

			[
				'key' => 'field_mp_events', 'label' => 'Events', 'name' => 'events',
				'type' => 'repeater', 'layout' => 'table', 'button_label' => 'Add Event',
				'instructions' => 'Goals / cards / substitution markers for this appearance (a handful of rows).',
				'sub_fields' => [
					[
						'key' => 'field_ev_type', 'label' => 'Type', 'name' => 'event_type', 'type' => 'select',
						'choices' => [
							'goal' => 'Goal', 'goal_penalty' => 'Goal (penalty)', 'own_goal' => 'Own goal',
							'yellow_card' => 'Yellow', 'double_yellow_card' => 'Double yellow', 'red_card' => 'Red',
							'substitution' => 'Substitution',
						],
					],
					[ 'key' => 'field_ev_min',  'label' => 'Minute',      'name' => 'minute',      'type' => 'number' ],
					[ 'key' => 'field_ev_desc', 'label' => 'Description', 'name' => 'description', 'type' => 'text' ],
				],
			],
		],
	] );
}
