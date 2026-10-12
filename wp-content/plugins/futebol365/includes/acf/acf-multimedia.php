<?php
/**
 * ACF field groups for multimedia CPTs and News (WP posts).
 * Covers Django models: Video, PhotoGallery, Photo, News, Source, SocialMediaSchedule.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'acf/init', 'f365_acf_multimedia' );

function f365_acf_multimedia() {

    // -----------------------------------------------------------------------
    // VIDEO  (Django: Video)
    // -----------------------------------------------------------------------
    acf_add_local_field_group( [
        'key'      => 'group_f365_video',
        'title'    => 'Video Details',
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'f365_video' ] ] ],
        'fields'   => [
            [ 'key' => 'field_v_url',    'label' => 'Video URL',  'name' => 'url',       'type' => 'url' ],
            [ 'key' => 'field_v_length', 'label' => 'Length',     'name' => 'length',    'type' => 'text' ],
            [ 'key' => 'field_v_views',  'label' => 'Views',      'name' => 'views',     'type' => 'number', 'default_value' => 0 ],
            [
                'key'     => 'field_v_file_type',
                'label'   => 'File Type',
                'name'    => 'file_type',
                'type'    => 'select',
                'choices' => [
                    'embed'    => 'Embed',
                    'mp4'      => 'MP4',
                    'youtube'  => 'YouTube',
                ],
            ],
            [ 'key' => 'field_v_highlight',  'label' => 'Highlight',   'name' => 'highlight',   'type' => 'true_false', 'default_value' => 0 ],
        ],
    ] );

    // -----------------------------------------------------------------------
    // PHOTO GALLERY  (Django: PhotoGallery + PhotoGalleryPhotos)
    // -----------------------------------------------------------------------
    acf_add_local_field_group( [
        'key'      => 'group_f365_gallery',
        'title'    => 'Gallery Details',
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'f365_gallery' ] ] ],
        'fields'   => [
            [ 'key' => 'field_g_external_url', 'label' => 'External Gallery URL', 'name' => 'external_url', 'type' => 'url' ],
            [ 'key' => 'field_g_views',        'label' => 'Views',                'name' => 'views',        'type' => 'number', 'default_value' => 0 ],
            [ 'key' => 'field_g_votes',        'label' => 'Votes',                'name' => 'votes',        'type' => 'number', 'default_value' => 0 ],
            [ 'key' => 'field_g_competition',  'label' => 'Competition',          'name' => 'competition',  'type' => 'post_object', 'post_type' => [ 'f365_competition' ], 'return_format' => 'id', 'required' => 0 ],
            [ 'key' => 'field_g_match',        'label' => 'Match',                'name' => 'match',        'type' => 'post_object', 'post_type' => [ 'f365_match' ],       'return_format' => 'id', 'required' => 0 ],
            [ 'key' => 'field_g_teams',        'label' => 'Teams',                'name' => 'teams',        'type' => 'relationship', 'post_type' => [ 'f365_team' ],       'return_format' => 'id' ],
            [ 'key' => 'field_g_players',      'label' => 'Players',              'name' => 'players',      'type' => 'relationship', 'post_type' => [ 'f365_player' ],     'return_format' => 'id' ],
            [ 'key' => 'field_g_coaches',      'label' => 'Coaches',              'name' => 'coaches',      'type' => 'relationship', 'post_type' => [ 'f365_coach' ],      'return_format' => 'id' ],
            [ 'key' => 'field_g_presidents',   'label' => 'Presidents',           'name' => 'presidents',   'type' => 'relationship', 'post_type' => [ 'f365_president' ],  'return_format' => 'id' ],
            [ 'key' => 'field_g_referees',     'label' => 'Referees',             'name' => 'referees',     'type' => 'relationship', 'post_type' => [ 'f365_referee' ],    'return_format' => 'id' ],
            // Photo list (Gallery → Photo through-model)
            [
                'key'        => 'field_g_photos',
                'label'      => 'Photos',
                'name'       => 'gallery_photos',
                'type'       => 'gallery',
                'return_format' => 'id',
            ],
            [ 'key' => 'field_g_highlight',   'label' => 'Highlight',    'name' => 'highlight',   'type' => 'true_false', 'default_value' => 0 ],
            [ 'key' => 'field_g_is_published','label' => 'Is Published', 'name' => 'is_published','type' => 'true_false', 'default_value' => 1 ],
        ],
    ] );}
