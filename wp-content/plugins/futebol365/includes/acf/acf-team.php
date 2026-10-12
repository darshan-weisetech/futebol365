<?php
/**
 * ACF field groups for f365_team.
 *
 * Covers Django models: Team, TeamStep, Squad, SquadCompetition,
 * TeamPresident, TeamStepCoach, TeamStepCoachCompetition.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'acf/init', 'f365_acf_team' );

function f365_acf_team() {

    // -----------------------------------------------------------------------
    // TEAM — core fields (Django: Team)
    // -----------------------------------------------------------------------
    acf_add_local_field_group( [
        'key'      => 'group_f365_team',
        'title'    => 'Team Details',
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'f365_team' ] ] ],
        'fields'   => [
            [
                'key'       => 'field_team_tab_identity',
                'label'     => 'Identity',
                'type'      => 'tab',
                'placement' => 'top',
            ],

            // --- Identity ---
            [ 'key' => 'field_team_shortname',    'label' => 'Short Name',       'name' => 'shortname',       'type' => 'text' ],
            [ 'key' => 'field_team_longname',     'label' => 'Long Name',        'name' => 'longname',        'type' => 'text' ],
            [ 'key' => 'field_team_nickname',     'label' => 'Nickname',         'name' => 'nickname',        'type' => 'text' ],
            [ 'key' => 'field_team_namecode',     'label' => 'Name Code (3-5ch)','name' => 'namecode',        'type' => 'text',  'maxlength' => 5 ],
           
            // --- Location ---
            [ 'key' => 'field_team_city',    'label' => 'City',    'name' => 'city',    'type' => 'text' ],
            [ 'key' => 'field_team_address', 'label' => 'Address', 'name' => 'address', 'type' => 'text' ],
            [ 'key' => 'field_team_phone',   'label' => 'Phone',   'name' => 'phone',   'type' => 'text' ],
            [ 'key' => 'field_team_fax',     'label' => 'Fax',     'name' => 'fax',     'type' => 'text' ],
            [ 'key' => 'field_team_email',     'label' => 'Email',     'name' => 'email',     'type' => 'text' ],

            // --- Dates ---
            [ 'key' => 'field_team_foundation', 'label' => 'Foundation Date', 'name' => 'foundation', 'type' => 'date_picker', 'display_format' => 'd/m/Y', 'return_format' => 'Y-m-d' ],
            [ 'key' => 'field_team_extinction', 'label' => 'Extinction Date', 'name' => 'extinction', 'type' => 'date_picker', 'display_format' => 'd/m/Y', 'return_format' => 'Y-m-d' ],

            // --- Club info ---
            [ 'key' => 'field_team_official_members', 'label' => 'Official Members', 'name' => 'official_members', 'type' => 'number' ],
            [ 'key' => 'field_team_mascot',           'label' => 'Mascot',           'name' => 'mascot',           'type' => 'text' ],
            [ 'key' => 'field_team_equipment',        'label' => 'Equipment (kit)',  'name' => 'equipment',        'type' => 'text' ],

            // --- Status ---
            [ 'key' => 'field_team_is_available', 'label' => 'Is Available', 'name' => 'is_available', 'type' => 'true_false', 'default_value' => 0 ],
            [ 'key' => 'field_team_is_highlight', 'label' => 'Highlight',    'name' => 'is_highlight', 'type' => 'true_false', 'default_value' => 0 ],
            [ 'key' => 'field_team_edition',      'label' => 'Current Edition (year)', 'name' => 'edition', 'type' => 'text' ],

            
            // --- Type ---
            [
                'key'       => 'field_team_tab_relations',
                'label'     => 'Type & Relations',
                'type'      => 'tab',
                'placement' => 'top',
            ],
            [
                'key'     => 'field_team_type',
                'label'   => 'Team Type',
                'name'    => 'team_type',
                'type'    => 'select',
                'choices' => [ 'club' => 'Club', 'national' => 'National' ],
            ],

            // --- Relations (stored as post IDs) ---
            [ 'key' => 'field_team_federation',  'label' => 'Federation',  'name' => 'federation',  'type' => 'post_object', 'post_type' => [ 'f365_federation' ], 'return_format' => 'id' ],
            [ 'key' => 'field_team_stadium',     'label' => 'Stadium',     'name' => 'stadium',     'type' => 'post_object', 'post_type' => [ 'f365_stadium' ],    'return_format' => 'id', 'required' => 0 ],

            // --- Social networks ---
            [
                'key'       => 'field_team_tab_social',
                'label'     => 'Social Networks',
                'type'      => 'tab',
                'placement' => 'top',
            ],
            [ 'key' => 'field_team_url',         'label' => 'Website URL',  'name' => 'url',         'type' => 'url' ],
            [ 'key' => 'field_team_facebook',    'label' => 'Facebook',     'name' => 'facebook',    'type' => 'text' ],
            [ 'key' => 'field_team_twitter',     'label' => 'Twitter/X',    'name' => 'twitter',     'type' => 'text' ],
            [ 'key' => 'field_team_youtube',     'label' => 'YouTube',      'name' => 'youtube',     'type' => 'text' ],
            [ 'key' => 'field_team_instagram',   'label' => 'Instagram',    'name' => 'instagram',   'type' => 'text' ],
            [ 'key' => 'field_team_googleplus',  'label' => 'Google+',      'name' => 'googleplus',  'type' => 'text' ],

            // --- Django legacy ID (used during migration) ---
            //[ 'key' => 'field_team_django_id', 'label' => 'Django ID (migration)', 'name' => 'django_id', 'type' => 'number' ],
        ],
    ] );

    // TEAM STEP  (Django: TeamStep + TeamStepCoach)
    acf_add_local_field_group( [
        'key'      => 'group_f365_teamstep',
        'title'    => 'Team Step Details',
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'f365_teamstep' ] ] ],
        'fields'   => [
            [
                'key'   => 'field_ts2_tab_main',
                'label' => 'Main',
                'type'  => 'tab',
            ],

            // ── Core relationship ──────────────────────────────────────────
            [
                'key'           => 'field_ts2_team',
                'label'         => 'Team',
                'name'          => 'team',
                'type'          => 'post_object',
                'post_type'     => [ 'f365_team' ],
                'return_format' => 'id',
                'required'      => 1,
                'instructions'  => 'Parent f365_team post.',
            ],

            // ── Step dimensions ───────────────────────────────────────────
            [
                'key'   => 'field_ts2_edition',
                'label' => 'Edition (Year)',
                'name'  => 'edition',
                'type'  => 'text',
                'instructions' => 'Year string e.g. "2024". Matches Django TeamStep.edition.',
            ],
            [
                'key'     => 'field_ts2_step',
                'label'   => 'Step',
                'name'    => 'step',
                'type'    => 'select',
                'choices' => [
                    'principal'    => 'Principal',
                    'b_team'       => 'B Team',
                    'c_team'       => 'C Team',
                    'sub_23'       => 'Sub-23',
                    'sub_22'       => 'Sub-22',
                    'sub_21'       => 'Sub-21',
                    'sub_20'       => 'Sub-20',
                    'sub_19'       => 'Sub-19',
                    'sub_19_b'     => 'Sub-19 B',
                    'sub_18'       => 'Sub-18',
                    'sub_17'       => 'Sub-17',
                    'sub_17_b'     => 'Sub-17 B',
                    'sub_16'       => 'Sub-16',
                    'sub_15'       => 'Sub-15',
                    'sub_14'       => 'Sub-14',
                    'sub_13'       => 'Sub-13',
                    'sub_12'       => 'Sub-12',
                    'sub_11'       => 'Sub-11',
                    'sub_10'       => 'Sub-10',
                    'sub_9'        => 'Sub-9',
                    'sub_8'        => 'Sub-8',
                    'sub_7'        => 'Sub-7',
                    'sub_6'        => 'Sub-6',
                    'veterans'     => 'Veterans',
                    'reservations' => 'Reservations',
                ],
            ],
            [
                'key'     => 'field_ts2_modality',
                'label'   => 'Modality',
                'name'    => 'modality',
                'type'    => 'select',
                'choices' => [ 'football' => 'Football', 'futsal' => 'Futsal' ],
            ],
            [
                'key'     => 'field_ts2_gender',
                'label'   => 'Gender',
                'name'    => 'gender',
                'type'    => 'select',
                'choices' => [ 'male' => 'Male', 'female' => 'Female' ],
            ],

            // ── Optional stadium override ──────────────────────────────────
            [
                'key'           => 'field_ts2_stadium',
                'label'         => 'Stadium (override)',
                'name'          => 'stadium',
                'type'          => 'post_object',
                'post_type'     => [ 'f365_stadium' ],
                'return_format' => 'id',
                'required'      => 0,
                'allow_null'    => 1,
                'instructions'  => 'Overrides team-level stadium for this step/edition.',
            ],

            // ── Status ────────────────────────────────────────────────────
            [
                'key'           => 'field_ts2_is_available',
                'label'         => 'Is Available',
                'name'          => 'is_available',
                'type'          => 'true_false',
                'default_value' => 1,
            ],

            // ── Fallback text coaches/presidents ─────────────────────────
            [
                'key'          => 'field_ts2_tmp_coach',
                'label'        => 'Coach (text fallback)',
                'name'         => 'tmp_coach',
                'type'         => 'text',
                'instructions' => 'Used when no proper coach FK exists. Django: TeamStep.tmp_coach.',
            ],
            [
                'key'          => 'field_ts2_tmp_president',
                'label'        => 'President (text fallback)',
                'name'         => 'tmp_president',
                'type'         => 'text',
                'instructions' => 'Used when no proper president FK exists.',
            ],

            [
                'key'   => 'field_ts2_tab_coaches',
                'label' => 'Coaches',
                'type'  => 'tab',
            ],

            // ── Coaches (stored as JSON — Django: TeamStepCoach) ──────────
            [
                'key'        => 'field_ts2_coaches',
                'label'      => 'Coaches',
                'name'       => 'coaches',
                'type'       => 'repeater',
                'layout'     => 'table',
                'instructions' => 'Maps to team_teamstepcoach table. coach_type: principal, assistant, goalkeeper, observer.',
                'sub_fields' => [
                    [
                        'key'           => 'field_ts2_coach_coach',
                        'label'         => 'Coach',
                        'name'          => 'coach',
                        'type'          => 'post_object',
                        'post_type'     => [ 'f365_coach' ],
                        'return_format' => 'id',
                    ],
                    [
                        'key'           => 'field_ts2_coach_edition',
                        'label'         => 'Edition',
                        'name'          => 'edition',
                        'type'          => 'text',
                    ],
                    [
                        'key'     => 'field_ts2_coach_type',
                        'label'   => 'Coach Type',
                        'name'    => 'coach_type',
                        'type'    => 'select',
                        'choices' => [
                            'principal'  => 'Principal',
                            'assistant'  => 'Assistant',
                            'goalkeeper' => 'Goalkeeper',
                            'observer'   => 'Observer',
                        ],
                        'default_value' => 'principal',
                    ],
                    [
                        'key'           => 'field_ts2_coach_order',
                        'label'         => 'Order',
                        'name'          => 'order',
                        'type'          => 'number',
                        'default_value' => 0,
                    ],
                    [
                        'key'        => 'field_ts2_coach_competitions',
                        'label'      => 'Competitions',
                        'name'       => 'competitions',
                        'type'       => 'repeater',
                        'layout'     => 'table',

                        'sub_fields' => [
                            [
                                'key'           => 'field_ts2_coach_competition',
                                'label'         => 'Competition',
                                'name'          => 'competition',
                                'type'          => 'post_object',
                                'post_type'     => ['f365_competition'],
                                'return_format' => 'id',
                            ],
                        ],
                    ],
                    // [
                    //     'key'   => 'field_ts2_coach_django_id',
                    //     'label' => 'Django TeamStepCoach ID',
                    //     'name'  => 'django_coach_id',
                    //     'type'  => 'number',
                    // ],
                ],
            ],

            // ── Migration ─────────────────────────────────────────────────
            // [
            //     'key'   => 'field_ts2_django_id',
            //     'label' => 'Django TeamStep ID',
            //     'name'  => 'django_teamstep_id',
            //     'type'  => 'number',
            // ],
            // [
            //     'key'   => 'field_ts2_django_team_id',
            //     'label' => 'Django Team ID (FK)',
            //     'name'  => 'django_team_id',
            //     'type'  => 'number',
            // ],
            // [
            //     'key'   => 'field_ts2_django_stadium_id',
            //     'label' => 'Django Stadium ID (FK)',
            //     'name'  => 'django_stadium_id',
            //     'type'  => 'number',
            // ],
        ],
    ] );

    // SQUAD  (Django: Squad + SquadCompetition)
    acf_add_local_field_group( [
        'key'      => 'group_f365_squad',
        'title'    => 'Squad Member Details',
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'f365_squad' ] ] ],
        'fields'   => [

            [
                'key'       => 'field_sq2_tab_relationships',
                'label'     => 'Relationships',
                'type'      => 'tab',
                'placement' => 'top',
            ],
            // Relationships 
            [
                'key'           => 'field_sq2_team_step',
                'label'         => 'Team Step',
                'name'          => 'team_step',
                'type'          => 'post_object',
                'post_type'     => [ 'f365_teamstep' ],
                'return_format' => 'id',
                'required'      => 1,
            ],
            [
                'key'           => 'field_sq2_player',
                'label'         => 'Player',
                'name'          => 'player',
                'type'          => 'post_object',
                'post_type'     => [ 'f365_player' ],
                'return_format' => 'id',
                'required'      => 1,
            ],
            [
                'key'           => 'field_sq2_competition',
                'label'         => 'Competition (SquadCompetition)',
                'name'          => 'competition',
                'type'          => 'post_object',
                'post_type'     => [ 'f365_competition' ],
                'return_format' => 'id',
                'required'      => 0,
                'allow_null'    => 1,
                'instructions'  => 'Nullable. Maps to SquadCompetition.competition.',
            ],

            // Denormalised dimensions (copied from teamstep for fast queries)
            [
                'key'       => 'field_sq2_tab_dimensions',
                'label'     => 'Dimensions',
                'type'      => 'tab',
                'placement' => 'top',
            ],
            [
                'key'          => 'field_sq2_edition',
                'label'        => 'Edition',
                'name'         => 'edition',
                'type'         => 'text',
                'instructions' => 'Copied from parent TeamStep. Enables direct edition filtering without join.',
            ],
            [
                'key'   => 'field_sq2_step',
                'label' => 'Step (denormalised)',
                'name'  => 'step',
                'type'  => 'text',
            ],
            [
                'key'   => 'field_sq2_modality',
                'label' => 'Modality (denormalised)',
                'name'  => 'modality',
                'type'  => 'text',
            ],
            [
                'key'   => 'field_sq2_gender',
                'label' => 'Gender (denormalised)',
                'name'  => 'gender',
                'type'  => 'text',
            ],

            // Player data 
            [
                'key'       => 'field_sq2_tab_player',
                'label'     => 'Player Data',
                'type'      => 'tab',
                'placement' => 'top',
            ],
            [
                'key'     => 'field_sq2_position',
                'label'   => 'Position',
                'name'    => 'position',
                'type'    => 'select',
                'choices' => [
                    'goalkeeper' => 'Goalkeeper',
                    'defender'   => 'Defender',
                    'midfielder' => 'Midfielder',
                    'forward'    => 'Forward',
                ],
                'allow_null' => 1,
            ],
            [
                'key'           => 'field_sq2_shirt_number',
                'label'         => 'Shirt Number',
                'name'          => 'shirt_number',
                'type'          => 'number',
                'default_value' => 0,
                'min'           => 0,
            ],
            [
                'key'           => 'field_sq2_order',
                'label'         => 'Order (0=primary squad)',
                'name'          => 'order',
                'type'          => 'number',
                'default_value' => 0,
                'instructions'  => '0 = player primary squad. >0 = secondary squad (loan destination, etc).',
            ],
            [
                'key'           => 'field_sq2_games',
                'label'         => 'Games',
                'name'          => 'games',
                'type'          => 'number',
                'default_value' => 0,
            ],
            [
                'key'           => 'field_sq2_goals',
                'label'         => 'Goals',
                'name'          => 'goals',
                'type'          => 'number',
                'default_value' => 0,
            ],

            // Migration 
            // [
            //     'key'   => 'field_sq2_django_id',
            //     'label' => 'Django Squad ID',
            //     'name'  => 'django_squad_id',
            //     'type'  => 'number',
            // ],
            // [
            //     'key'   => 'field_sq2_django_teamstep_id',
            //     'label' => 'Django TeamStep ID (FK)',
            //     'name'  => 'django_teamstep_id',
            //     'type'  => 'number',
            // ],
            // [
            //     'key'   => 'field_sq2_django_player_id',
            //     'label' => 'Django Player ID (FK)',
            //     'name'  => 'django_player_id',
            //     'type'  => 'number',
            // ],
        ],
    ] );

    //
    // TEAM PRESIDENT  (Django: TeamPresident)
    //
    acf_add_local_field_group( [
        'key'      => 'group_f365_team_president',
        'title'    => 'Team President Assignment',
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'f365_team_president' ] ] ],
        'fields'   => [
            [
                'key'       => 'field_tp_tab_relationships',
                'label'     => 'Relationships',
                'type'      => 'tab',
                'placement' => 'top',
            ],

            [
                'key'           => 'field_tp_team',
                'label'         => 'Team',
                'name'          => 'team',
                'type'          => 'post_object',
                'post_type'     => [ 'f365_team' ],
                'return_format' => 'id',
                'required'      => 1,
            ],
            [
                'key'           => 'field_tp_president',
                'label'         => 'President',
                'name'          => 'president',
                'type'          => 'post_object',
                'post_type'     => [ 'f365_president' ],
                'return_format' => 'id',
                'required'      => 1,
            ],
            [
                'key'       => 'field_tp_tab_details',
                'label'     => 'Details',
                'type'      => 'tab',
                'placement' => 'top',
            ],
            [
                'key'   => 'field_tp_edition',
                'label' => 'Edition',
                'name'  => 'edition',
                'type'  => 'text',
            ],
            [
                'key'     => 'field_tp_president_type',
                'label'   => 'President Type',
                'name'    => 'president_type',
                'type'    => 'select',
                'choices' => [
                    'president'       => 'President',
                ],
                'default_value' => 'president',
            ],
            [
                'key'           => 'field_tp_order',
                'label'         => 'Order',
                'name'          => 'order',
                'type'          => 'number',
                'default_value' => 0,
            ],
            // [
            //     'key'   => 'field_tp_django_id',
            //     'label' => 'Django TeamPresident ID',
            //     'name'  => 'django_id',
            //     'type'  => 'number',
            // ],
            // [
            //     'key'   => 'field_tp_django_team_id',
            //     'label' => 'Django Team ID (FK)',
            //     'name'  => 'django_team_id',
            //     'type'  => 'number',
            // ],
            // [
            //     'key'   => 'field_tp_django_president_id',
            //     'label' => 'Django President ID (FK)',
            //     'name'  => 'django_president_id',
            //     'type'  => 'number',
            // ],
        ],
    ] );
}
