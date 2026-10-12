<?php
/**
 * ACF field group for f365_country taxonomy.
 *
 * Registers all Country Details fields (faithfully mirroring group_country_fields.json)
 * and the new `country_flag` ACF Image field used by the migration to store imported SVG
 * attachment IDs.
 *
 * Also enables SVG uploads for the WordPress media library (required for flag import).
 *
 * @package Futebol365
 * @since   2.1.0
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// SVG MIME type — allow SVG uploads (required for country flag import).
// Scoped to this plugin only; additive filter, no global side-effects.
// ---------------------------------------------------------------------------
add_filter(
    'upload_mimes',
    static function ( array $mimes ): array {
        $mimes['svg'] = 'image/svg+xml';
        return $mimes;
    }
);

// ---------------------------------------------------------------------------
// ACF field group registration
// ---------------------------------------------------------------------------
add_action( 'acf/init', 'f365_acf_country' );

function f365_acf_country(): void {

    acf_add_local_field_group( [
        'key'                   => 'group_country_fields',
        'title'                 => 'Country Details',
        'menu_order'            => 0,
        'position'              => 'normal',
        'style'                 => 'default',
        'label_placement'       => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen'        => '',
        'active'                => true,
        'description'           => '',
        'location'              => [
            [
                [
                    'param'    => 'taxonomy',
                    'operator' => '==',
                    'value'    => 'f365_country',
                ],
            ],
        ],
        'fields' => [

            // ── Identity ──────────────────────────────────────────────────
            [
                'key'   => 'field_country_longname',
                'label' => 'Long Name',
                'name'  => 'longname',
                'type'  => 'text',
            ],
            [
                'key'   => 'field_country_capital',
                'label' => 'Capital',
                'name'  => 'capital',
                'type'  => 'text',
            ],
            [
                'key'   => 'field_country_city',
                'label' => 'City',
                'name'  => 'city',
                'type'  => 'text',
            ],
            [
                'key'   => 'field_country_language',
                'label' => 'Language',
                'name'  => 'language',
                'type'  => 'text',
            ],
            [
                'key'   => 'field_country_area',
                'label' => 'Area',
                'name'  => 'area',
                'type'  => 'text',
            ],
            [
                'key'   => 'field_country_population',
                'label' => 'Population',
                'name'  => 'population',
                'type'  => 'text',
            ],
            [
                'key'   => 'field_country_coin',
                'label' => 'Coin/Currency',
                'name'  => 'coin',
                'type'  => 'text',
            ],
            [
                'key'     => 'field_country_is_extinct',
                'label'   => 'Is Extinct?',
                'name'    => 'is_extinct',
                'type'    => 'true_false',
                'ui'      => 1,
            ],

            // ── Contact / Social ──────────────────────────────────────────
            [
                'key'   => 'field_country_url',
                'label' => 'Website URL',
                'name'  => 'url',
                'type'  => 'url',
            ],
            [
                'key'   => 'field_country_email',
                'label' => 'Email',
                'name'  => 'email',
                'type'  => 'email',
            ],
            [
                'key'   => 'field_country_facebook',
                'label' => 'Facebook URL',
                'name'  => 'facebook',
                'type'  => 'url',
            ],
            [
                'key'   => 'field_country_twitter',
                'label' => 'Twitter URL',
                'name'  => 'twitter',
                'type'  => 'url',
            ],
            [
                'key'   => 'field_country_youtube',
                'label' => 'YouTube URL',
                'name'  => 'youtube',
                'type'  => 'url',
            ],
            [
                'key'   => 'field_country_instagram',
                'label' => 'Instagram URL',
                'name'  => 'instagram',
                'type'  => 'url',
            ],
            [
                'key'   => 'field_country_googleplus',
                'label' => 'Google+ URL',
                'name'  => 'googleplus',
                'type'  => 'url',
            ],

            // ── Flag (NEW — populated by migration) ───────────────────────
            [
                'key'           => 'field_country_flag',
                'label'         => 'Country Flag',
                'name'          => 'country_flag',
                'type'          => 'image',
                'return_format' => 'id',
                'preview_size'  => 'thumbnail',
                'library'       => 'all',
            ],
        ],
    ] );
}
