<?php
/**
 * ACF field groups for Newsstand module.
 *
 * Django models: Publisher, PublicationEdition
 *
 * Publisher → f365_publisher CPT
 *   name           → post_title
 *   slug           → post_name
 *   publisher_url  → ACF url
 *   is_visible     → ACF true_false
 *   external_id    → ACF text (original Django PK / external ID)
 *   order          → ACF number
 *
 * PublicationEdition → f365_newsstand CPT
 *   publisher      → ACF post_object → f365_publisher
 *   published_at   → post_date + ACF date_time_picker
 *   cover_file     → featured_image (WP thumbnail)
 *   is_last_edition→ ACF true_false
 *
 * SEO stored as standard Yoast postmeta keys.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'acf/init', 'f365_acf_newsstand' );

function f365_acf_newsstand(): void {

    // -----------------------------------------------------------------------
    // PUBLISHER  (Django: Publisher)
    // -----------------------------------------------------------------------
    acf_add_local_field_group( [
        'key'      => 'group_f365_publisher',
        'title'    => 'Publisher Details',
        'location' => [ [ [ 'param' => 'taxonomy', 'operator' => '==', 'value' => 'f365_publisher' ] ] ],
        'fields'   => [

            [
                'key'   => 'field_pub_publisher_url',
                'label' => 'Publisher URL',
                'name'  => 'publisher_url',
                'type'  => 'url',
            ],
            [
                'key'           => 'field_pub_is_visible',
                'label'         => 'Is Visible',
                'name'          => 'is_visible',
                'type'          => 'true_false',
                'default_value' => 0,
            ],
            [
                'key'          => 'field_pub_external_id',
                'label'        => 'External ID',
                'name'         => 'external_id',
                'type'         => 'text',
                'instructions' => 'Original external_id from Django Publisher model. Must be unique.',
            ],
            [
                'key'  => 'field_pub_order',
                'label'=> 'Display Order',
                'name' => 'order',
                'type' => 'number',
                'min'  => 0,
            ],

            // ── Migration ────────────────────────────────────────────────
            [
                'key'   => 'field_pub_django_id',
                'label' => 'Django Publisher ID',
                'name'  => 'django_publisher_id',
                'type'  => 'number',
            ],
        ],
    ] );

    // -----------------------------------------------------------------------
    // PUBLICATION EDITION  (Django: PublicationEdition)
    // -----------------------------------------------------------------------
    acf_add_local_field_group( [
        'key'      => 'group_f365_newsstand',
        'title'    => 'Newsstand Edition Details',
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'f365_newsstand' ] ] ],
        'fields'   => [


            [
                'key'           => 'field_ns_is_last_edition',
                'label'         => 'Is Latest Edition',
                'name'          => 'is_last_edition',
                'type'          => 'true_false',
                'default_value' => 0,
                'instructions'  => 'Mark as the most recent edition for this publisher.',
            ],


            // ── Migration ────────────────────────────────────────────────
            [
                'key'   => 'field_ns_django_id',
                'label' => 'Django PublicationEdition ID',
                'name'  => 'django_newsstand_id',
                'type'  => 'number',
            ],
            [
                'key'   => 'field_ns_django_publisher_id',
                'label' => 'Django Publisher ID (FK)',
                'name'  => 'django_publisher_id',
                'type'  => 'number',
            ],
        ],
    ] );
}
