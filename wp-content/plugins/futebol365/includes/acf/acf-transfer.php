<?php
/**
 * ACF field groups for f365_transfer.
 * Covers Django models: Transfer, TransferRumor.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'acf/init', 'f365_acf_transfer' );

function f365_acf_transfer() {

    acf_add_local_field_group( [
        'key'      => 'group_f365_transfer',
        'title'    => 'Transfer Details',
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'f365_transfer' ] ] ],
        'fields'   => [
            // Core
            [
                'key'   => 'field_tr_tab_core',
                'label' => 'Relation',
                'type'  => 'tab',
            ],
            [ 'key' => 'field_tr_player',      'label' => 'Player',          'name' => 'player',      'type' => 'post_object', 'post_type' => [ 'f365_player' ], 'return_format' => 'id' ],
            [ 'key' => 'field_tr_team_origin', 'label' => 'Origin Team',     'name' => 'team_origin', 'type' => 'post_object', 'post_type' => [ 'f365_team' ],   'return_format' => 'id' ],
            [ 'key' => 'field_tr_team_dest',   'label' => 'Destination Team','name' => 'team_dest', 'type' => 'post_object', 'post_type' => [ 'f365_team' ], 'return_format' => 'id' ],
            [ 'key' => 'field_tr_teamstep_origin', 'label' => 'Origin Teamstep',     'name' => 'teamstep_origin', 'type' => 'post_object', 'post_type' => [ 'f365_teamstep' ],   'return_format' => 'id' ],
            [ 'key' => 'field_tr_teamstep_dest',   'label' => 'Destination Teamstep','name' => 'teamstep_dest', 'type' => 'post_object', 'post_type' => [ 'f365_teamstep' ], 'return_format' => 'id' ],
            // Details
            // Origin Teamstep Attributes
            // [
            //     'key'   => 'field_tr_tab_teamstep',
            //     'label' => 'Teamstep Details',
            //     'type'  => 'tab',
            // ],
            // [
            //     'key'   => 'field_tr_origin_step',
            //     'label' => 'Origin Step',
            //     'name'  => 'origin_step',
            //     'type'  => 'text',
            // ],
            // [
            //     'key'   => 'field_tr_origin_modality',
            //     'label' => 'Origin Modality',
            //     'name'  => 'origin_modality',
            //     'type'  => 'text',
            // ],
            // [
            //     'key'   => 'field_tr_origin_gender',
            //     'label' => 'Origin Gender',
            //     'name'  => 'origin_gender',
            //     'type'  => 'text',
            // ],

            // Destination Teamstep Attributes
            // [
            //     'key'   => 'field_tr_dest_step',
            //     'label' => 'Destination Step',
            //     'name'  => 'dest_step',
            //     'type'  => 'text',
            // ],
            // [
            //     'key'   => 'field_tr_dest_modality',
            //     'label' => 'Destination Modality',
            //     'name'  => 'dest_modality',
            //     'type'  => 'text',
            // ],
            // [
            //     'key'   => 'field_tr_dest_gender',
            //     'label' => 'Destination Gender',
            //     'name'  => 'dest_gender',
            //     'type'  => 'text',
            // ],
            [
                'key'   => 'field_tr_tab_transfer',
                'label' => 'Transfer Details',
                'type'  => 'tab',
            ],


            [ 'key' => 'field_tr_edition',      'label' => 'Edition',       'name' => 'edition',       'type' => 'text' ],
            [ 'key' => 'field_tr_shirt_number', 'label' => 'Shirt Number',  'name' => 'shirt_number',  'type' => 'number' ],
            [ 'key' => 'field_tr_value',        'label' => 'Transfer Value','name' => 'value',         'type' => 'text' ],
            [ 'key' => 'field_tr_date',         'label' => 'Transfer Date', 'name' => 'transfer_date', 'type' => 'date_picker', 'display_format' => 'd/m/Y', 'return_format' => 'Y-m-d' ],
            [
                'key'     => 'field_tr_type',
                'label'   => 'Transfer Type',
                'name'    => 'transfer_type',
                'type'    => 'select',
                'allow_null' => 1,
                'choices' => [ 'D' => 'Definitive', 'E' => 'Loan (Empréstimo)', '?' => 'AND' ],
            ],
            [ 'key' => 'field_tr_is_rumor', 'label' => 'Is Rumour',  'name' => 'is_rumor', 'type' => 'true_false', 'default_value' => 0 ],

            // Migration
            // [ 'key' => 'field_tr_django_id',     'label' => 'Django Transfer ID',     'name' => 'django_transfer_id',    'type' => 'number' ],
            // [ 'key' => 'field_tr_django_orig_ts','label' => 'Django Origin TS ID',    'name' => 'django_origin_ts_id',   'type' => 'number' ],
            // [ 'key' => 'field_tr_django_dest_ts','label' => 'Django Dest. TS ID',     'name' => 'django_dest_ts_id',     'type' => 'number' ],
        ],
    ] );
}
