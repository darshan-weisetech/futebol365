<?php
/**
 * ACF field groups for WordPress native Users (Authors).
 *
 * Django model mapping:
 *   publish.Source  → wp_users / wp_usermeta (role: author)
 *
 * In WordPress we map the Author module to native WordPress users.
 * Custom metadata (like profile photos or active/display flags) are
 * stored in user meta and managed via ACF User profile location rules.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'acf/init', 'f365_acf_author' );

function f365_acf_author() {

    // -----------------------------------------------------------------------
    // AUTHOR — profile fields (Django: Source / editorial profile)
    // -----------------------------------------------------------------------
    acf_add_local_field_group( [
        'key'      => 'group_f365_author',
        'title'    => 'Author Profile',
        'location' => [ [ [ 'param' => 'user_form', 'operator' => '==', 'value' => 'all' ] ] ],
        'fields'   => [

            // Display flags
            [ 
                'key'           => 'field_au_display_source',
                'label'         => 'Display as Source', 
                'name'          => 'display_source', 
                'type'          => 'true_false', 
                'default_value' => 0 
            ],
            [ 
                'key'           => 'field_au_is_active',    
                'label'         => 'Is Active',      
                'name'          => 'is_active',      
                'type'          => 'true_false', 
                'default_value' => 1 
            ],
        ],
    ] );
}
