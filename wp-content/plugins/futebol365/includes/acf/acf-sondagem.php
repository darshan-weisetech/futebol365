<?php
/**
 * ACF field group for the `sondagem` CPT.
 *
 * Adds the "Estado da Sondagem" box on the side context with a single
 * true/false toggle bound to the `_sondagem_is_active` meta key.
 * Stores '1' when active, '0' when inactive; new posts default to active.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'acf/init', 'f365_acf_sondagem' );

function f365_acf_sondagem() {

    acf_add_local_field_group( [
        'key'      => 'group_f365_sondagem',
        'title'    => 'Estado da Sondagem',
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'sondagem' ] ] ],
        'position' => 'side',
        'fields'   => [
            [
                'key'           => 'field_sondagem_is_active',
                'label'         => 'Sondagem ativa',
                'name'          => '_sondagem_is_active',
                'type'          => 'true_false',
                'instructions'  => 'Quando desativada, a sondagem é apresentada como expirada.',
                'message'       => 'Sondagem ativa',
                'default_value' => 1,
                'ui'            => 1,
            ],
        ],
    ] );
}
