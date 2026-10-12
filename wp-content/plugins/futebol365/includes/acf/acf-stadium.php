<?php
/**
 * ACF field groups for f365_stadium.
 * Covers Django model: Stadium (extends Entity).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'acf/init', 'f365_acf_stadium' );

function f365_acf_stadium() {

    acf_add_local_field_group( [
        'key'      => 'group_f365_stadium',
        'title'    => 'Stadium Details',
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'f365_stadium' ] ] ],
        'fields'   => [
            // Identity (from Entity base)
            [ 'key' => 'field_st_shortname', 'label' => 'Short Name', 'name' => 'shortname', 'type' => 'text' ],
            [ 'key' => 'field_st_longname',  'label' => 'Full Name',  'name' => 'longname',  'type' => 'text' ],
            [ 'key' => 'field_st_nickname',  'label' => 'Nickname',   'name' => 'nickname',  'type' => 'text' ],
            [ 'key' => 'field_st_url',       'label' => 'Website',    'name' => 'url',       'type' => 'url' ],
            [ 'key' => 'field_st_email',     'label' => 'Email',      'name' => 'email',     'type' => 'email' ],

            // Social
            [ 'key' => 'field_st_facebook',   'label' => 'Facebook',   'name' => 'facebook',   'type' => 'url' ],
            [ 'key' => 'field_st_twitter',    'label' => 'Twitter',    'name' => 'twitter',    'type' => 'url' ],
            [ 'key' => 'field_st_youtube',    'label' => 'YouTube',    'name' => 'youtube',    'type' => 'url' ],
            [ 'key' => 'field_st_instagram',  'label' => 'Instagram',  'name' => 'instagram',  'type' => 'url' ],
            [ 'key' => 'field_st_googleplus', 'label' => 'Google+',    'name' => 'googleplus', 'type' => 'url' ],

            // Location
            [ 'key' => 'field_st_city',      'label' => 'City',       'name' => 'city',      'type' => 'text' ],
            [ 'key' => 'field_st_address',   'label' => 'Address',    'name' => 'address',   'type' => 'textarea' ],
            [ 'key' => 'field_st_latitude',  'label' => 'Latitude',   'name' => 'latitude',  'type' => 'text' ],
            [ 'key' => 'field_st_longitude', 'label' => 'Longitude',  'name' => 'longitude', 'type' => 'text' ],

            // Details
            [ 'key' => 'field_st_capacity',   'label' => 'Capacity',           'name' => 'capacity',   'type' => 'number' ],
            [ 'key' => 'field_st_dimensions', 'label' => 'Pitch Dimensions',   'name' => 'dimensions', 'type' => 'text' ],
            [ 'key' => 'field_st_architect',  'label' => 'Architect',          'name' => 'architect',  'type' => 'text' ],
            [ 'key' => 'field_st_opening',    'label' => 'Opening Date',       'name' => 'opening',    'type' => 'date_picker', 'display_format' => 'd/m/Y', 'return_format' => 'Y-m-d' ],
            [ 'key' => 'field_st_closing',    'label' => 'Closing Date',       'name' => 'closing',    'type' => 'date_picker', 'display_format' => 'd/m/Y', 'return_format' => 'Y-m-d' ],
            [ 'key' => 'field_st_first_game', 'label' => 'First Game (text)',  'name' => 'first_game', 'type' => 'text' ],
            [ 'key' => 'field_st_edition',    'label' => 'Edition',            'name' => 'edition',    'type' => 'text' ],

            // Migration
            [ 'key' => 'field_st_django_id', 'label' => 'Django Stadium ID', 'name' => 'django_stadium_id', 'type' => 'number' ],
        ],
    ] );
}
