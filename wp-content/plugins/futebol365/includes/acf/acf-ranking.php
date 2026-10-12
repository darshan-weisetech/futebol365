<?php
/**
 * ACF field groups for f365_ranking.
 *
 * Django models: ClubRanking, NationalRanking, PlayerRanking (all inherit Ranking)
 *
 * Common Ranking fields:
 *   edition    → ACF text
 *   position   → ACF number
 *   points     → ACF number
 *
 * OrderedRanking (ClubRanking + NationalRanking) adds:
 *   team       → ACF post_object → f365_team
 *   variation  → ACF number
 *   month      → ACF number
 *   year       → ACF number
 *   coefficient→ ACF number (ClubRanking only)
 *
 * PlayerRanking adds:
 *   player         → ACF post_object → f365_player
 *   season         → ACF text
 *   ranking_type   → ACF post_object → (stored as meta, not CPT)
 *   ranking_type_shortname → ACF text
 *   ranking_type_longname  → ACF text
 *   points_description     → ACF text
 *
 * Discriminator: `ranking_type` meta ('club', 'national', 'player').
 * Taxonomy terms f365_ranking_type and f365_ranking_period are also set.
 *
 * SEO stored as standard Yoast postmeta keys.
 */

defined( 'ABSPATH' ) || exit;

// add_action( 'acf/init', 'f365_acf_ranking' );

function f365_acf_ranking(): void {

    acf_add_local_field_group( [
        'key'      => 'group_f365_ranking',
        'title'    => 'Ranking Details',
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'f365_ranking' ] ] ],
        'fields'   => [

            // ── Discriminator ─────────────────────────────────────────────
            [
                'key'     => 'field_rk_ranking_type',
                'label'   => 'Ranking Type',
                'name'    => 'ranking_type',
                'type'    => 'select',
                'choices' => [
                    'club'     => 'Club Ranking (IFFHS)',
                    'national' => 'National Team Ranking (FIFA)',
                    'player'   => 'Player Ranking',
                ],
                'required' => 1,
                'instructions' => 'Determines which Django model this row maps to.',
            ],

            // ── Common fields (all ranking types) ─────────────────────────
            [
                'key'  => 'field_rk_position',
                'label'=> 'Position',
                'name' => 'position',
                'type' => 'number',
                'min'  => 1,
            ],
            [
                'key'           => 'field_rk_points',
                'label'         => 'Points',
                'name'          => 'points',
                'type'          => 'number',
                'default_value' => 1,
                'min'           => 0,
            ],

            // ── Club / National (OrderedRanking) ──────────────────────────
            [
                'key'           => 'field_rk_team',
                'label'         => 'Team',
                'name'          => 'team',
                'type'          => 'post_object',
                'post_type'     => [ 'f365_team' ],
                'return_format' => 'id',
                'required'      => 0,
                'allow_null'    => 1,
                'instructions'  => 'Used for club and national team rankings.',
                'conditional_logic' => [
                    [
                        [ 'field' => 'field_rk_ranking_type', 'operator' => '!=', 'value' => 'player' ],
                    ],
                ],
            ],
            [
                'key'          => 'field_rk_variation',
                'label'        => 'Variation',
                'name'         => 'variation',
                'type'         => 'number',
                'instructions' => 'Position change from previous period (+/-).',
                'conditional_logic' => [
                    [
                        [ 'field' => 'field_rk_ranking_type', 'operator' => '!=', 'value' => 'player' ],
                    ],
                ],
            ],
            [
                'key'  => 'field_rk_month',
                'label'=> 'Month',
                'name' => 'month',
                'type' => 'number',
                'min'  => 1,
                'max'  => 12,
                'conditional_logic' => [
                    [
                        [ 'field' => 'field_rk_ranking_type', 'operator' => '!=', 'value' => 'player' ],
                    ],
                ],
            ],
            [
                'key'  => 'field_rk_year',
                'label'=> 'Year',
                'name' => 'year',
                'type' => 'number',
                'min'  => 1990,
                'conditional_logic' => [
                    [
                        [ 'field' => 'field_rk_ranking_type', 'operator' => '!=', 'value' => 'player' ],
                    ],
                ],
            ],

            // ── Club-specific ─────────────────────────────────────────────
            [
                'key'          => 'field_rk_coefficient',
                'label'        => 'Coefficient (IFFHS)',
                'name'         => 'coefficient',
                'type'         => 'number',
                'min'          => 0,
                'instructions' => 'ClubRanking.coefficient — IFFHS-specific field.',
                'conditional_logic' => [
                    [
                        [ 'field' => 'field_rk_ranking_type', 'operator' => '==', 'value' => 'club' ],
                    ],
                ],
            ],

            // ── Player ranking ────────────────────────────────────────────
            [
                'key'           => 'field_rk_player',
                'label'         => 'Player',
                'name'          => 'player',
                'type'          => 'post_object',
                'post_type'     => [ 'f365_player' ],
                'return_format' => 'id',
                'required'      => 0,
                'allow_null'    => 1,
                'conditional_logic' => [
                    [
                        [ 'field' => 'field_rk_ranking_type', 'operator' => '==', 'value' => 'player' ],
                    ],
                ],
            ],
            [
                'key'          => 'field_rk_season',
                'label'        => 'Season',
                'name'         => 'season',
                'type'         => 'text',
                'instructions' => 'Display season string, e.g. "2019-2020". Plain text — not a taxonomy term. Derived from edition + federation season type during migration.',
                'readonly'     => 1,
                'conditional_logic' => [
                    [
                        [ 'field' => 'field_rk_ranking_type', 'operator' => '==', 'value' => 'player' ],
                    ],
                ],
            ],

            [
                'key'   => 'field_rk_points_description',
                'label' => 'Points Description',
                'name'  => 'points_description',
                'type'  => 'text',
                'instructions' => 'PlayerRankingType.points_description.',
                'conditional_logic' => [
                    [
                        [ 'field' => 'field_rk_ranking_type', 'operator' => '==', 'value' => 'player' ],
                    ],
                ],
            ],
        ],
    ] );
}