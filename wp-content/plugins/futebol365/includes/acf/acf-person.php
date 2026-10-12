<?php
/**
 * ACF field groups for person CPTs.
 * Covers Django models: Person (abstract), Player, Coach, President, Referee.
 *
 * Formatted with a clean, tabbed layout for easy backend maintenance.
 */

defined( 'ABSPATH' ) || exit;

// add_action( 'acf/init', 'f365_acf_person' );

function f365_acf_person() {

    // Helper to generate base fields for each person CPT
    $get_base_fields = function( string $prefix ) {
        return [
            [ 'key' => "field_{$prefix}_shortname",    'label' => 'Short Name',         'name' => 'shortname',    'type' => 'text' ],
            [ 'key' => "field_{$prefix}_longname",     'label' => 'Full Name',          'name' => 'longname',     'type' => 'text' ],
            [ 'key' => "field_{$prefix}_slug",         'label' => 'Slug',               'name' => 'slug',         'type' => 'text' ],
            [ 'key' => "field_{$prefix}_birth_date",   'label' => 'Birth Date',         'name' => 'birth_date',   'type' => 'date_picker', 'display_format' => 'd/m/Y', 'return_format' => 'Y-m-d' ],
            [ 'key' => "field_{$prefix}_death_date",   'label' => 'Death Date',         'name' => 'death_date',   'type' => 'date_picker', 'display_format' => 'd/m/Y', 'return_format' => 'Y-m-d' ],
            [ 'key' => "field_{$prefix}_birth_place",  'label' => 'Birth Place',        'name' => 'birth_place',  'type' => 'text' ],
            [ 'key' => "field_{$prefix}_nationality",  'label' => 'Nationality',        'name' => 'nationality',  'type' => 'taxonomy', 'taxonomy' => 'f365_country', 'field_type' => 'select', 'return_format' => 'id' ],
            [ 'key' => "field_{$prefix}_naturality",   'label' => 'Country of Origin',  'name' => 'naturality',   'type' => 'taxonomy', 'taxonomy' => 'f365_country', 'field_type' => 'select', 'return_format' => 'id' ],
        ];
    };

    // Helper to generate social fields for each person CPT
    $get_social_fields = function( string $prefix ) {
        return [
            [ 'key' => "field_{$prefix}_url",          'label' => 'Website',            'name' => 'url',          'type' => 'text' ],
            [ 'key' => "field_{$prefix}_email",        'label' => 'Email',              'name' => 'email',        'type' => 'email' ],
            [ 'key' => "field_{$prefix}_facebook",     'label' => 'Facebook',           'name' => 'facebook',     'type' => 'text' ],
            [ 'key' => "field_{$prefix}_twitter",      'label' => 'Twitter/X',          'name' => 'twitter',      'type' => 'text' ],
            [ 'key' => "field_{$prefix}_youtube",      'label' => 'YouTube',            'name' => 'youtube',      'type' => 'text' ],
            [ 'key' => "field_{$prefix}_instagram",    'label' => 'Instagram',          'name' => 'instagram',    'type' => 'text' ],
            [ 'key' => "field_{$prefix}_googleplus",   'label' => 'Google+',            'name' => 'googleplus',   'type' => 'text' ],
        ];
    };

    // -----------------------------------------------------------------------
    // PLAYER  (Django: Player)
    // -----------------------------------------------------------------------
    acf_add_local_field_group( [
        'key'      => 'group_f365_player',
        'title'    => 'Player Details',
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'f365_player' ] ] ],
        'fields'   => array_merge(
            [
                [
                    'key'   => 'field_pl_tab_personal',
                    'label' => 'Personal Info',
                    'type'  => 'tab',
                ],
            ],
            $get_base_fields( 'pl' ),
            [
                [
                    'key'   => 'field_pl_tab_profile',
                    'label' => 'Player Profile',
                    'type'  => 'tab',
                ],
                [
                    'key'     => 'field_pl_position',
                    'label'   => 'Position',
                    'name'    => 'position',
                    'type'    => 'select',
                    'choices' => [
                        'goalkeeper'  => 'Goalkeeper',
                        'defender'    => 'Defender',
                        'midfielder'  => 'Midfielder',
                        'forward'     => 'Forward',
                    ],
                ],
                [ 'key' => 'field_pl_weight',         'label' => 'Weight (kg)',      'name' => 'weight',           'type' => 'number' ],
                [ 'key' => 'field_pl_height',         'label' => 'Height (cm)',      'name' => 'height',           'type' => 'number' ],
                [ 'key' => 'field_pl_right_foot',     'label' => 'Right Foot',       'name' => 'right_foot',       'type' => 'true_false', 'default_value' => 0 ],
                [ 'key' => 'field_pl_left_foot',      'label' => 'Left Foot',        'name' => 'left_foot',        'type' => 'true_false', 'default_value' => 0 ],
                [
                    'key'   => 'field_pl_tab_social',
                    'label' => 'Social & Contact',
                    'type'  => 'tab',
                ],
            ],
            $get_social_fields( 'pl' )
        ),
    ] );

    // -----------------------------------------------------------------------
    // COACH  (Django: Coach)
    // -----------------------------------------------------------------------
    acf_add_local_field_group( [
        'key'      => 'group_f365_coach',
        'title'    => 'Coach Details',
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'f365_coach' ] ] ],
        'fields'   => array_merge(
            [
                [
                    'key'   => 'field_co_tab_personal',
                    'label' => 'Personal Info',
                    'type'  => 'tab',
                ],
            ],
            $get_base_fields( 'co' ),
            [
                [
                    'key'   => 'field_co_tab_profile',
                    'label' => 'Coach Profile',
                    'type'  => 'tab',
                ],
                [ 'key' => 'field_co_coach_name',     'label' => 'Coach Name (display)', 'name' => 'coach_name',       'type' => 'text' ],
                [ 'key' => 'field_co_level',          'label' => 'Level',                'name' => 'level',            'type' => 'text' ],
                [
                    'key'           => 'field_co_competitions',
                    'label'         => 'Competitions Managed',
                    'name'          => 'competitions',
                    'type'          => 'relationship',
                    'post_type'     => [ 'f365_competition' ],
                    'filters'       => [ 'search', 'taxonomy' ],
                    'elements'      => [ 'featured_image' ],
                    'min'           => '',
                    'max'           => '',
                    'return_format' => 'id',
                ],
                [
                    'key'   => 'field_co_tab_social',
                    'label' => 'Social & Contact',
                    'type'  => 'tab',
                ],
            ],
            $get_social_fields( 'co' )
        ),
    ] );

    // -----------------------------------------------------------------------
    // PRESIDENT  (Django: President)
    // -----------------------------------------------------------------------
    acf_add_local_field_group( [
        'key'      => 'group_f365_president',
        'title'    => 'President Details',
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'f365_president' ] ] ],
        'fields'   => array_merge(
            [
                [
                    'key'   => 'field_pr_tab_personal',
                    'label' => 'Personal Info',
                    'type'  => 'tab',
                ],
            ],
            $get_base_fields( 'pr' ),
            [
                [
                    'key'   => 'field_pr_tab_profile',
                    'label' => 'President Profile',
                    'type'  => 'tab',
                ],
                [
                    'key'   => 'field_pr_tab_social',
                    'label' => 'Social & Contact',
                    'type'  => 'tab',
                ],
            ],
            $get_social_fields( 'pr' )
        ),
    ] );

    // -----------------------------------------------------------------------
    // REFEREE  (Django: Referee)
    // -----------------------------------------------------------------------
    acf_add_local_field_group( [
        'key'      => 'group_f365_referee',
        'title'    => 'Referee Details',
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'f365_referee' ] ] ],
        'fields'   => array_merge(
            [
                [
                    'key'   => 'field_re_tab_personal',
                    'label' => 'Personal Info',
                    'type'  => 'tab',
                ],
            ],
            $get_base_fields( 're' ),
            [
                [
                    'key'   => 'field_re_tab_profile',
                    'label' => 'Referee Profile',
                    'type'  => 'tab',
                ],
                [ 'key' => 'field_re_level',            'label' => 'Level',             'name' => 'level',             'type' => 'text' ],
                [ 'key' => 'field_re_association',      'label' => 'Association',       'name' => 'association',       'type' => 'post_object', 'post_type' => [ 'f365_federation' ], 'return_format' => 'id', 'required' => 0 ],
                [
                    'key'   => 'field_re_tab_social',
                    'label' => 'Social & Contact',
                    'type'  => 'tab',
                ],
            ],
            $get_social_fields( 're' )
        ),
    ] );

    $po = static function ( string $key, string $label, string $name, string $cpt ): array {
        return [
            'key'           => $key,
            'label'         => $label,
            'name'          => $name,
            'type'          => 'post_object',
            'post_type'     => [ $cpt ],
            'return_format' => 'id',
            'allow_null'    => 1,
            'ui'            => 1,
        ];
    };
 
}
