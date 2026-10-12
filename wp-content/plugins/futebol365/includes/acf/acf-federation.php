<?php
/**
 * ACF field groups for federation CPTs.
 * Covers Django models: Federation, FederationCompetition, FederationCompetitionSettings.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'acf/init', 'f365_acf_federation' );

function f365_acf_federation() {

    // -----------------------------------------------------------------------
    // FEDERATION  (Django: Federation)
    // Hierarchy: confederation → federation → association
    // Implemented via WP parent post (hierarchical CPT).
    // -----------------------------------------------------------------------
    acf_add_local_field_group( [
        'key'      => 'group_f365_federation',
        'title'    => 'Federation Details',
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'f365_federation' ] ] ],
        'fields'   => [
            [
                'key'       => 'field_fed_tab_identity',
                'label'     => 'Identity',
                'type'      => 'tab',
                'placement' => 'top',
            ],
            [ 'key' => 'field_fed_shortname', 'label' => 'Short Name', 'name' => 'shortname', 'type' => 'text' ],
            [ 'key' => 'field_fed_longname',  'label' => 'Long Name',  'name' => 'longname',  'type' => 'text' ],
            [ 'key' => 'field_fed_email',     'label' => 'Email',      'name' => 'email',     'type' => 'email' ],

            [
                'key'     => 'field_fed_type',
                'label'   => 'Federation Type',
                'name'    => 'federation_type',
                'type'    => 'select',
                'choices' => [
                    'confederation' => 'Confederation (e.g. UEFA)',
                    'federation'    => 'Federation (e.g. FPF)',
                    'association'   => 'Association (district/regional)',
                ],
            ],
            [
                'key'     => 'field_fed_season_type',
                'label'   => 'Default Season Type',
                'name'    => 'season_type',
                'type'    => 'select',
                'choices' => [
                    'summer'  => 'Summer (single year)',
                    'winter'  => 'Winter (year-year)',
                ],
            ],
            [ 'key' => 'field_fed_season_begin_with', 'label' => 'Season Begins With Edition', 'name' => 'season_begin_with_edition', 'type' => 'true_false', 'default_value' => 1 ],

            [ 'key' => 'field_fed_city',      'label' => 'City',           'name' => 'city',      'type' => 'text' ],
            [ 'key' => 'field_fed_address',   'label' => 'Address',        'name' => 'address',   'type' => 'text' ],
            [ 'key' => 'field_fed_foundation','label' => 'Foundation Date','name' => 'foundation','type' => 'date_picker', 'display_format' => 'd/m/Y', 'return_format' => 'Y-m-d' ],
            [ 'key' => 'field_fed_president', 'label' => 'President',      'name' => 'president', 'type' => 'post_object', 'post_type' => [ 'f365_president' ], 'return_format' => 'id', 'required' => 0 ],

            [ 'key' => 'field_fed_season_begin', 'label' => 'Season Begin', 'name' => 'season_begin', 'type' => 'date_picker', 'display_format' => 'd/m/Y', 'return_format' => 'Y-m-d' ],
            [ 'key' => 'field_fed_season_end',   'label' => 'Season End',   'name' => 'season_end',   'type' => 'date_picker', 'display_format' => 'd/m/Y', 'return_format' => 'Y-m-d' ],
            [ 'key' => 'field_fed_edition',      'label' => 'Edition', 'name' => 'edition',  'type' => 'text' ],

            // Social
            [
                'key'       => 'field_fed_tab_social',
                'label'     => 'Social Networks',
                'type'      => 'tab',
                'placement' => 'top',
            ],
            [ 'key' => 'field_fed_url',       'label' => 'Website URL','name' => 'url',       'type' => 'url' ],
            [ 'key' => 'field_fed_facebook',   'label' => 'Facebook',   'name' => 'facebook',   'type' => 'text' ],
            [ 'key' => 'field_fed_twitter',    'label' => 'Twitter/X',  'name' => 'twitter',    'type' => 'text' ],
            [ 'key' => 'field_fed_youtube',    'label' => 'YouTube',    'name' => 'youtube',    'type' => 'text' ],
            [ 'key' => 'field_fed_instagram',  'label' => 'Instagram',  'name' => 'instagram',  'type' => 'text' ],
            [ 'key' => 'field_fed_googleplus',  'label' => 'Googleplus',  'name' => 'googleplus',  'type' => 'text' ],
        ],
    ] );

    // -----------------------------------------------------------------------
    // FEDERATION COMPETITION  (Django: FederationCompetition)
    // -----------------------------------------------------------------------
    acf_add_local_field_group( [
        'key'      => 'group_f365_fed_comp',
        'title'    => 'Competition Type Details',
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'f365_fed_comp' ] ] ],
        'fields'   => [
            [
                'key'       => 'field_fc_tab_identity',
                'label'     => 'Identity',
                'type'      => 'tab',
                'placement' => 'top',
            ],

            [ 'key' => 'field_fc_shortname',  'label' => 'Short Name', 'name' => 'shortname', 'type' => 'text' ],
            [ 'key' => 'field_fc_longname',   'label' => 'Long Name',  'name' => 'longname',  'type' => 'text' ],
            [ 'key' => 'field_fed_email',     'label' => 'Email',      'name' => 'email',     'type' => 'email' ],
            [ 'key' => 'field_fc_federation', 'label' => 'Federation', 'name' => 'federation','type' => 'post_object', 'post_type' => [ 'f365_federation' ], 'return_format' => 'id' ],
            [
                'key'     => 'field_fc_comp_type',
                'label'   => 'Competition Type',
                'name'    => 'competition_type',
                'type'    => 'select',
                'allow_null' => 1,
                'choices' => [
                    'championship' => 'Championship',
                    'competition'  => 'Competition',
                    'friendly'     => 'Friendly',
                ],
            ],
            [
                'key'     => 'field_fc_scope',
                'label'   => 'Scope',
                'name'    => 'scope',
                'type'    => 'select',
                'allow_null' => 1,
                'choices' => [
                    'national_championship'          => 'National Championship',
                    'national_cup'                   => 'National Cup',
                    'national_supercup'              => 'National Supercup',
                    'national_league_cup'            => 'National League Cup',
                    'national_friendly'               => 'National Friendly',
                    'regional_championship'          => 'Regional Championship',
                    'regional_cup'                   => 'Regional Cup',
                    'international_clubs'            => 'International (Clubs)',
                    'international_national_team'    => 'International (National Teams)',
                    'international_friendly_clubs'   => 'International Friendly (Clubs)',
                    'international_friendly_nationals' => 'International Friendly (National Teams)',
                ],
            ],
            [
                'key'     => 'field_fc_step',
                'label'   => 'Step',
                'name'    => 'step',
                'type'    => 'select',
                'allow_null' => 1,
                'choices' => [
                    'principal'    => 'Principal',
                    'veterans'     => 'Veterans',
                    'sub_10'       => 'Sub 10',
                    'sub_13'       => 'Sub 13',
                    'sub_15'       => 'Sub 15',
                    'sub_17'       => 'Sub 17',
                    'sub_19'       => 'Sub 19',
                    'sub_20'       => 'Sub 20',
                    'sub_21'       => 'Sub 21',
                    'sub_23'       => 'Sub 23',            
                ],
            ],
            [
                'key'     => 'field_fc_modality',
                'label'   => 'Modality',
                'name'    => 'modality',
                'type'    => 'select',
                'allow_null' => 1,
                'choices' => [ 'football' => 'Football'],
            ],
            [
                'key'     => 'field_fc_gender',
                'label'   => 'Gender',
                'name'    => 'gender',
                'type'    => 'select',
                'allow_null' => 1,
                'choices' => [ 'male' => 'Male', 'female' => 'Female' ],
            ],

            [ 'key' => 'field_fc_color',        'label' => 'Color (hex)',          'name' => 'color',                   'type' => 'color_picker' ],
            [ 'key' => 'field_fc_order',        'label' => 'Display Order',        'name' => 'order',                   'type' => 'number', 'default_value' => 1 ],
            [ 'key' => 'field_fc_is_principal', 'label' => 'Is Principal',         'name' => 'is_principal',            'type' => 'true_false', 'default_value' => 0 ],
            [ 'key' => 'field_fc_is_active',    'label' => 'Active',               'name' => 'is_active',               'type' => 'true_false', 'default_value' => 0 ],
            [ 'key' => 'field_fc_is_highlight', 'label' => 'Highlight',            'name' => 'is_highlight',            'type' => 'true_false', 'default_value' => 0 ],
            [ 'key' => 'field_fc_auto_events',  'label' => 'Auto-create Events',   'name' => 'auto_create_match_events','type' => 'true_false', 'default_value' => 0 ],
            [ 'key' => 'field_fc_show_stadiums','label' => 'Show Stadiums',        'name' => 'show_stadiums',           'type' => 'true_false', 'default_value' => 1 ],

            // Per-edition settings — repeater (Django: FederationCompetitionSettings)
            [
                'key'       => 'field_fc_tab_settings',
                'label'     => 'Edition Settings',
                'type'      => 'tab',
                'placement' => 'top',
            ],
            [
                'key'        => 'field_fc_settings',
                'label'      => 'Per-Edition Season Settings',
                'name'       => 'edition_settings',
                'type'       => 'repeater',
                'layout'     => 'table',
                'button_label' => 'Add Setting',
                'sub_fields' => [
                    [ 'key' => 'field_fcs_season_type',         'label' => 'Season Type',   'name' => 'season_type',               'type' => 'select','allow_null' => 1, 'choices' => [ 'summer' => 'Summer', 'winter' => 'Winter' ] ],
                    [ 'key' => 'field_fcs_since_edition',       'label' => 'Since Edition', 'name' => 'season_type_since_edition', 'type' => 'text' ],
                    [ 'key' => 'field_fcs_season_type_since_season',       'label' => 'Since Season', 'name' => 'season_type_since_season', 'type' => 'text' ],
                    [ 'key' => 'field_fcs_begin_with_edition',  'label' => 'Begins With Edition', 'name' => 'season_begin_with_edition', 'type' => 'true_false', 'default_value' => 1 ],
                    [ 'key' => 'field_fcs_has_apertura',        'label' => 'Has Apertura/Clausura', 'name' => 'has_apertura_clausura', 'type' => 'true_false', 'default_value' => 0 ],
                ],
            ],
            [
                'key'       => 'field_fc_tab_social',
                'label'     => 'Social Networks',
                'type'      => 'tab',
                'placement' => 'top',
            ],
            [ 'key' => 'field_fc_url',        'label' => 'URL',        'name' => 'url',       'type' => 'url' ],
            ['key' => 'field_fed_facebook',   'label' => 'Facebook',   'name' => 'facebook',   'type' => 'text' ],
            [ 'key' => 'field_fed_twitter',    'label' => 'Twitter/X',  'name' => 'twitter',    'type' => 'text' ],
            [ 'key' => 'field_fed_youtube',    'label' => 'YouTube',    'name' => 'youtube',    'type' => 'text' ],
            [ 'key' => 'field_fed_instagram',  'label' => 'Instagram',  'name' => 'instagram',  'type' => 'text' ],
            [ 'key' => 'field_fed_googleplus',  'label' => 'Googleplus',  'name' => 'googleplus',  'type' => 'text' ],

        ],
    ] );
}
