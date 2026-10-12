<?php
/**
 * ACF field groups for f365_livegames (and the f365_livegame_player child CPT).
 *
 * Django models (app: live):
 *   LiveMatch          → f365_livegames
 *     competition      → post_object → f365_competition  (FK)
 *     match            → post_object → f365_match        (1:1, nullable)
 *     datetime         → date_time_picker
 *     external_*id     → number (source service ids)
 *     home_team_name   → text  (filled when no match FK / is_mapped=false)
 *     visitor_team_name→ text
 *     home_score       → text
 *     visitor_score    → text
 *     status           → text  (live status code: RF / AD / SP / -1 / minute)
 *     goal             → true_false
 *     is_mapped        → true_false
 *     finished_at      → date_time_picker
 *     *_score_extra    → text  (extra time)
 *     penalty_*_score  → text  (penalty shoot-out)
 *     external_audio_narration → true_false
 *   LiveMatchStats     → flat home_* / visitor_* meta (1:1, mirrors MatchStats)
 *   LiveMatchEvent     → `live_events` repeater (event_type, player_name, minute, is_home_team)
 *   LiveMatchCoach     → home_coach / visitor_coach text
 *   LiveMatchFormation → home_formation / visitor_formation text
 *   LiveMatchPlayer    → f365_livegame_player child posts (see second group)
 *
 * Mirrors the structure of acf-live-streaming.php and the Match stats meta.
 * SEO stored as standard Yoast postmeta keys.
 *
 * @package Futebol365
 * @since   2.4.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'acf/init', 'f365_acf_livegames' );

function f365_acf_livegames(): void {

    // ════════════════════════════════════════════════════════════════════════
    // LIVE GAME  (Django: LiveMatch + LiveMatchStats + events/coaches/formations)
    // ════════════════════════════════════════════════════════════════════════
    acf_add_local_field_group( [
        'key'      => 'group_f365_livegames',
        'title'    => 'Live Game Details',
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'f365_livegames' ] ] ],
        'fields'   => [
             [
                'key' => 'field_lg_tab_relationships',
                'label' => 'Relationships',
                'type' => 'tab',
                'placement' => 'top',
            ],

            // ── Relationships ─────────────────────────────────────────────
            [
                'key'           => 'field_lg_match',
                'label'         => 'Match',
                'name'          => 'match',
                'type'          => 'post_object',
                'post_type'     => [ 'f365_match' ],
                'return_format' => 'id',
                'required'      => 0,
                'allow_null'    => 1,
                'instructions'  => 'Linked f365_match post (LiveMatch.match 1:1). Resolved in second pass from django_match_id.',
            ],
            [
                'key'           => 'field_lg_competition',
                'label'         => 'Competition',
                'name'          => 'competition',
                'type'          => 'post_object',
                'post_type'     => [ 'f365_competition' ],
                'return_format' => 'id',
                'required'      => 0,
                'allow_null'    => 1,
                'instructions'  => 'Linked f365_competition post (LiveMatch.competition FK).',
            ],

            // ── Scheduling / status ───────────────────────────────────────
            [
                'key' => 'field_lg_tab_match_info',
                'label' => 'Match Info',
                'type' => 'tab',
            ],
            [
                'key'            => 'field_lg_datetime',
                'label'          => 'Datetime',
                'name'           => 'datetime',
                'type'           => 'date_time_picker',
                'display_format' => 'd/m/Y H:i',
                'return_format'  => 'Y-m-d H:i:s',
                'instructions'   => 'LiveMatch.datetime (kick-off, Europe/Lisbon reference).',
            ],
            [
                'key'          => 'field_lg_live_status',
                'label'        => 'Live Status',
                'name'         => 'live_status',
                'type'         => 'text',
                'instructions' => 'LiveMatch.status — RF=finished, AD=postponed, SP=suspended, -1=not started, or the current minute.',
            ],
            [
                'key'           => 'field_lg_is_mapped',
                'label'         => 'Is Mapped',
                'name'          => 'is_mapped',
                'type'          => 'true_false',
                'default_value' => 1,
                'instructions'  => 'True when mapped to internal Match/TeamSteps; false uses the denormalised team names.',
            ],
            [
                'key'           => 'field_lg_goal',
                'label'         => 'Goal Flag',
                'name'          => 'goal',
                'type'          => 'true_false',
                'default_value' => 0,
                'instructions'  => 'True if a goal was scored in the current status (live highlight).',
            ],
            [
                'key'           => 'field_lg_audio_narration',
                'label'         => 'External Audio Narration',
                'name'          => 'external_audio_narration',
                'type'          => 'true_false',
                'default_value' => 0,
                'instructions'  => 'True if the match has external audio narration (Golo FM).',
            ],
            [
                'key'            => 'field_lg_finished_at',
                'label'          => 'Finished At',
                'name'           => 'finished_at',
                'type'           => 'date_time_picker',
                'display_format' => 'd/m/Y H:i',
                'return_format'  => 'Y-m-d H:i:s',
            ],

            // ── Denormalised team names (LiveMatch.*_team_name) ───────────
            [
                'key' => 'field_lg_tab_teams',
                'label' => 'Teams',
                'type' => 'tab',
            ],
            [
                'key'          => 'field_lg_home_team_name',
                'label'        => 'Home Team Name',
                'name'         => 'home_team_name',
                'type'         => 'text',
                'instructions' => 'Stored when no match FK exists (LiveMatch.home_team_name).',
            ],
            [
                'key'          => 'field_lg_visitor_team_name',
                'label'        => 'Visitor Team Name',
                'name'         => 'visitor_team_name',
                'type'         => 'text',
                'instructions' => 'Stored when no match FK exists (LiveMatch.visitor_team_name).',
            ],

            // ── Score ─────────────────────────────────────────────────────
            [
                'key' => 'field_lg_tab_scores',
                'label' => 'Scores',
                'type' => 'tab',
            ],

            [ 'key' => 'field_lg_home_score',            'label' => 'Home Score',            'name' => 'home_score',            'type' => 'text' ],
            [ 'key' => 'field_lg_visitor_score',         'label' => 'Visitor Score',         'name' => 'visitor_score',         'type' => 'text' ],
            [ 'key' => 'field_lg_home_score_extra',      'label' => 'Home Score (extra)',    'name' => 'home_score_extra',      'type' => 'text' ],
            [ 'key' => 'field_lg_visitor_score_extra',   'label' => 'Visitor Score (extra)', 'name' => 'visitor_score_extra',   'type' => 'text' ],
            [ 'key' => 'field_lg_penalty_home_score',    'label' => 'Penalty Home Score',    'name' => 'penalty_home_score',    'type' => 'text' ],
            [ 'key' => 'field_lg_penalty_visitor_score', 'label' => 'Penalty Visitor Score', 'name' => 'penalty_visitor_score', 'type' => 'text' ],

            // ── Coaches / formations (LiveMatchCoach / LiveMatchFormation) ─
           [
                'key' => 'field_lg_tab_coach',
                'label' => 'Coaches',
                'type' => 'tab',
            ],
            [
                'key'          => 'field_lg_coaches',
                'label'        => 'Live Coaches',
                'name'         => 'live_coaches',
                'type'         => 'repeater',
                'layout'       => 'table',
                'button_label' => 'Add Coach',
                'sub_fields'   => [
                    [ 'key' => 'field_lg_co_name',    'label' => 'Coach Name', 'name' => 'coach_name',   'type' => 'text' ],
                    [ 'key' => 'field_lg_co_is_home', 'label' => 'Home Team',  'name' => 'is_home_team',  'type' => 'true_false', 'default_value' => 0 ],
                ]
            ],

            [
                'key' => 'field_lg_tab_formation',
                'label' => 'Formations',
                'type' => 'tab',
            ],
            [
                'key'          => 'field_lg_formations',
                'label'        => 'Live Formations',
                'name'         => 'live_formations',
                'type'         => 'repeater',
                'layout'       => 'table',
                'button_label' => 'Add Formation',
                'sub_fields'   => [
                    [ 'key' => 'field_lg_fo_layout',  'label' => 'Formation String', 'name' => 'formation',    'type' => 'text' ],
                    [ 'key' => 'field_lg_fo_is_home', 'label' => 'Home Team',        'name' => 'is_home_team',  'type' => 'true_false', 'default_value' => 0 ],
                ]
            ],

            // ── Events repeater (LiveMatchEvent) ──────────────────────────
            [
                'key' => 'field_lg_tab_events',
                'label' => 'Events',
                'type' => 'tab',
            ],
            [
                'key'          => 'field_lg_live_events',
                'label'        => 'Live Events',
                'name'         => 'live_events',
                'type'         => 'repeater',
                'layout'       => 'table',
                'button_label' => 'Add Event',
                'instructions' => 'Timeline of LiveMatchEvent rows (goal, goal_penalty, own_goal, yellow_card, double_yellow_card, red_card, substitution).',
                'sub_fields'   => [
                    [ 'key' => 'field_lg_ev_minute',     'label' => 'Minute',      'name' => 'minute',      'type' => 'number' ],
                    [ 'key' => 'field_lg_ev_type',       'label' => 'Event Type',  'name' => 'event_type',  'type' => 'text' ],
                    [ 'key' => 'field_lg_ev_player',     'label' => 'Player',      'name' => 'player_name', 'type' => 'text' ],
                    [ 'key' => 'field_lg_ev_is_home',    'label' => 'Home Team',   'name' => 'is_home_team', 'type' => 'true_false', 'default_value' => 0 ],
                ],
            ],

            // ── Match stats snapshot (LiveMatchStats — mirrors MatchStats) ─
            [
                'key' => 'field_lg_tab_stats',
                'label' => 'Statistics',
                'type' => 'tab',
            ],
           // ── Statistics (FULL SET) ─────────────────────────────────────────────

            [ 'key' => 'field_lg_home_ball_possession',     'label' => 'Home Possession',           'name' => 'home_ball_possession',     'type' => 'number' ],
            [ 'key' => 'field_lg_home_shots',               'label' => 'Home Shots',                'name' => 'home_shots',               'type' => 'number' ],
            [ 'key' => 'field_lg_home_shots_on_goal',       'label' => 'Home Shots on Goal',       'name' => 'home_shots_on_goal',       'type' => 'number' ],
            [ 'key' => 'field_lg_home_fouls',               'label' => 'Home Fouls',               'name' => 'home_fouls',               'type' => 'number' ],
            [ 'key' => 'field_lg_home_cautions',            'label' => 'Home Cautions',            'name' => 'home_cautions',            'type' => 'number' ],
            [ 'key' => 'field_lg_home_expulsions',          'label' => 'Home Expulsions',          'name' => 'home_expulsions',          'type' => 'number' ],
            [ 'key' => 'field_lg_home_corner_kicks',        'label' => 'Home Corners',             'name' => 'home_corner_kicks',        'type' => 'number' ],
            [ 'key' => 'field_lg_home_offsides',           'label' => 'Home Offsides',            'name' => 'home_offsides',            'type' => 'number' ],
            [ 'key' => 'field_lg_home_saves',              'label' => 'Home Saves',               'name' => 'home_saves',               'type' => 'number' ],
            [ 'key' => 'field_lg_home_goals',              'label' => 'Home Goals',               'name' => 'home_goals',               'type' => 'number' ],
            [ 'key' => 'field_lg_home_goals_penalty',      'label' => 'Home Goals (Penalty)',      'name' => 'home_goals_penalty',      'type' => 'number' ],
            [ 'key' => 'field_lg_home_own_goals',          'label' => 'Home Own Goals',            'name' => 'home_own_goals',          'type' => 'number' ],
            [ 'key' => 'field_lg_home_substitutions',      'label' => 'Home Substitutions',       'name' => 'home_substitutions',      'type' => 'number' ],
            [ 'key' => 'field_lg_home_yellow_cards',       'label' => 'Home Yellow Cards',        'name' => 'home_yellow_cards',       'type' => 'number' ],
            [ 'key' => 'field_lg_home_double_yellow_cards', 'label' => 'Home Double Yellow Cards', 'name' => 'home_double_yellow_cards','type' => 'number' ],
            [ 'key' => 'field_lg_home_red_cards',          'label' => 'Home Red Cards',           'name' => 'home_red_cards',          'type' => 'number' ],
            [ 'key' => 'field_lg_home_throw_ins',          'label' => 'Home Throw Ins',           'name' => 'home_throw_ins',          'type' => 'number' ],
            [ 'key' => 'field_lg_home_free_kicks',         'label' => 'Home Free Kicks',          'name' => 'home_free_kicks',         'type' => 'number' ],

            [ 'key' => 'field_lg_visitor_ball_possession', 'label' => 'Visitor Possession',       'name' => 'visitor_ball_possession', 'type' => 'number' ],
            [ 'key' => 'field_lg_visitor_shots',           'label' => 'Visitor Shots',            'name' => 'visitor_shots',           'type' => 'number' ],
            [ 'key' => 'field_lg_visitor_shots_on_goal',   'label' => 'Visitor Shots on Goal',    'name' => 'visitor_shots_on_goal',   'type' => 'number' ],
            [ 'key' => 'field_lg_visitor_fouls',           'label' => 'Visitor Fouls',            'name' => 'visitor_fouls',           'type' => 'number' ],
            [ 'key' => 'field_lg_visitor_cautions',        'label' => 'Visitor Cautions',         'name' => 'visitor_cautions',        'type' => 'number' ],
            [ 'key' => 'field_lg_visitor_expulsions',      'label' => 'Visitor Expulsions',       'name' => 'visitor_expulsions',      'type' => 'number' ],
            [ 'key' => 'field_lg_visitor_corner_kicks',    'label' => 'Visitor Corners',          'name' => 'visitor_corner_kicks',    'type' => 'number' ],
            [ 'key' => 'field_lg_visitor_offsides',        'label' => 'Visitor Offsides',         'name' => 'visitor_offsides',        'type' => 'number' ],
            [ 'key' => 'field_lg_visitor_saves',           'label' => 'Visitor Saves',            'name' => 'visitor_saves',           'type' => 'number' ],
            [ 'key' => 'field_lg_visitor_goals',           'label' => 'Visitor Goals',            'name' => 'visitor_goals',           'type' => 'number' ],
            [ 'key' => 'field_lg_visitor_goals_penalty',   'label' => 'Visitor Goals (Penalty)',  'name' => 'visitor_goals_penalty',   'type' => 'number' ],
            [ 'key' => 'field_lg_visitor_own_goals',       'label' => 'Visitor Own Goals',        'name' => 'visitor_own_goals',       'type' => 'number' ],
            [ 'key' => 'field_lg_visitor_substitutions',   'label' => 'Visitor Substitutions',    'name' => 'visitor_substitutions',   'type' => 'number' ],
            [ 'key' => 'field_lg_visitor_yellow_cards',     'label' => 'Visitor Yellow Cards',     'name' => 'visitor_yellow_cards',     'type' => 'number' ],
            [ 'key' => 'field_lg_visitor_double_yellow_cards', 'label' => 'Visitor Double Yellow Cards', 'name' => 'visitor_double_yellow_cards', 'type' => 'number' ],
            [ 'key' => 'field_lg_visitor_red_cards',        'label' => 'Visitor Red Cards',        'name' => 'visitor_red_cards',        'type' => 'number' ],
            [ 'key' => 'field_lg_visitor_throw_ins',        'label' => 'Visitor Throw Ins',        'name' => 'visitor_throw_ins',        'type' => 'number' ],
            [ 'key' => 'field_lg_visitor_free_kicks',       'label' => 'Visitor Free Kicks',       'name' => 'visitor_free_kicks',       'type' => 'number' ],

             [
                'key' => 'field_lg_tab_narrations',
                'label' => 'Narrations',
                'type' => 'tab',
            ],
            [
                'key'          => 'field_f365_live_narrations',
                'label'        => 'Live Narrations',
                'name'         => 'live_narrations',
                'type'         => 'repeater',
                'layout'       => 'table',
                'button_label' => 'Add Narration',
                'sub_fields'   => [
                    [
                        'key'   => 'field_f365_live_narration_minute',
                        'label' => 'Minute',
                        'name'  => 'minute',
                        'type'  => 'number',
                    ],
                    [
                        'key'   => 'field_f365_live_narration_minute_extra',
                        'label' => 'Minute Extra',
                        'name'  => 'minute_extra',
                        'type'  => 'number',
                    ],
                    [
                        'key'   => 'field_f365_live_narration_period',
                        'label' => 'Period',
                        'name'  => 'period',
                        'type'  => 'number',
                    ],
                    [
                        'key'   => 'field_f365_live_narration_event',
                        'label' => 'Event',
                        'name'  => 'event',
                        'type'  => 'text',
                    ],
                    [
                        'key'   => 'field_f365_live_narration_description',
                        'label' => 'Description',
                        'name'  => 'description',
                        'type'  => 'textarea',
                        'rows'          => 2,
                    ],
                    [
                        'key'   => 'field_f365_live_narration_source_description',
                        'label' => 'Source Description',
                        'name'  => 'source_description',
                        'type'  => 'textarea',
                        'rows'          => 2,
                    ],
                    [
                        'key'   => 'field_f365_live_narration_external_id',
                        'label' => 'External ID',
                        'name'  => 'external_id',
                        'type'  => 'number',
                    ],
                ],
            ],
            // ── Migration FKs (audit; resolved to post IDs in resolve_fk) ─
            // [ 'key' => 'field_lg_django_id',         'label' => 'Django LiveMatch ID',     'name' => 'django_livematch_id',  'type' => 'number' ],
            // [ 'key' => 'field_lg_django_match_id',   'label' => 'Django Match ID (FK)',    'name' => 'django_match_id',      'type' => 'number' ],
            // [ 'key' => 'field_lg_django_comp_id',    'label' => 'Django Competition ID (FK)', 'name' => 'django_competition_id', 'type' => 'number' ],
        ],
    ] );

    // ════════════════════════════════════════════════════════════════════════
    // LIVE GAME PLAYER  (Django: LiveMatchPlayer + LiveMatchPlayerEvent)
    // Mirrors the f365_match_player ACF/meta shape.
    // ════════════════════════════════════════════════════════════════════════
    acf_add_local_field_group( [
        'key'      => 'group_f365_livegame_player',
        'title'    => 'Live Game Player',
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'f365_livegame_player' ] ] ],
        'fields'   => [
            [
                'key' => 'field_lgp_tab_player',
                'label' => 'Player',
                'type' => 'tab',
                'placement' => 'top',
            ],

            [
                'key'           => 'field_lgp_live_game',
                'label'         => 'Live Game',
                'name'          => 'live_game',
                'type'          => 'post_object',
                'post_type'     => [ 'f365_livegames' ],
                'return_format' => 'id',
                'required'      => 1,
            ],
            [ 'key' => 'field_lgp_player_name',  'label' => 'Player Name',     'name' => 'player_name',     'type' => 'text' ],
            [ 'key' => 'field_lgp_player_number','label' => 'Player Number',    'name' => 'player_number',   'type' => 'number' ],
            [ 'key' => 'field_lgp_player_order', 'label' => 'Order',           'name' => 'player_order',    'type' => 'number' ],
            [
                'key'           => 'field_lgp_is_field',
                'label'         => 'Is Field Player',
                'name'          => 'is_field_player',
                'type'          => 'true_false',
                'default_value' => 1,
                'instructions'  => 'True for starters; false for substitutes.',
            ],
            [
                'key'           => 'field_lgp_is_home',
                'label'         => 'Home Team',
                'name'          => 'is_home_team',
                'type'          => 'true_false',
                'default_value' => 0,
            ],
            [
                'key' => 'field_lgp_tab_events',
                'label' => 'Events',
                'type' => 'tab',
            ],
            [
                'key'          => 'field_lgp_events',
                'label'        => 'Player Events',
                'name'         => 'player_events',
                'type'         => 'repeater',
                'layout'       => 'table',
                'button_label' => 'Add Event',
                'instructions' => 'LiveMatchPlayerEvent rows for this appearance.',
                'sub_fields'   => [
                    [ 'key' => 'field_lgp_ev_minute', 'label' => 'Minute',     'name' => 'minute',     'type' => 'number' ],
                    [ 'key' => 'field_lgp_ev_type',   'label' => 'Event Type', 'name' => 'event_type', 'type' => 'text' ],
                ],
            ],
            // [ 'key' => 'field_lgp_django_id',      'label' => 'Django LiveMatch ID', 'name' => 'django_livematch_id', 'type' => 'number' ],
            // [ 'key' => 'field_lgp_django_ext_pid', 'label' => 'External Player ID',  'name' => 'external_player_id',  'type' => 'number' ],
        ],
    ] );
}
