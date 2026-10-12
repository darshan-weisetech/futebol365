<?php
/**
 * Register all Taxonomies — v2.0.0
 *
 * Django model/field → WordPress taxonomy mapping (original + new modules):
 *   Country             → f365_country       (shared across most CPTs)
 *   Season/Edition      → f365_season        (e.g. "2024", "2023-2024")
 *   NewsCategory        → f365_news_cat      (maps to Django NewsCategory)
 *   Tag                 → post_tag           (reuse built-in WP tags)
 *   Federation type     → f365_fed_type      (confederation / federation / association)
 *   Competition scope   → f365_comp_scope    (national_championship, cup, etc.)
 *   Modality            → f365_modality      (football, futsal)
 *   Gender              → f365_gender        (male, female)
 *   Team step           → f365_step          (principal, b_team, c_team, veterans)
 *   Position            → f365_position      (goalkeeper, defender, midfielder, forward)
 *   Match situation     → f365_match_status  (finished, delayed, suspended, abrogated)
 *   Transfer type       → f365_transfer_type (definitive, loan)
 *   News source         → f365_news_source   (maps to Django Source)
 *   Ranking type        → f365_ranking_type  ← NEW (club, national, player)
 *   Ranking period      → f365_ranking_period← NEW (e.g. "2024-01", "2024")
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'f365_register_taxonomies' );

function f365_register_taxonomies(): void {

    $all_sports_cpts = [
        'f365_team', 'f365_player', 'f365_coach', 'f365_president',
        'f365_referee', 'f365_competition', 'f365_stadium',
        'f365_federation', 'f365_fed_comp', 'f365_ranking',
        'post',
    ];

    // -----------------------------------------------------------------------
    // COUNTRY  (Django: Country model — FK on almost every model)
    // -----------------------------------------------------------------------
    register_taxonomy( 'f365_country', $all_sports_cpts, [
        'label'             => 'Country',
        'hierarchical'      => true,
        'public'            => true,
        'show_admin_column' => true,
        'rewrite'           => [ 'slug' => 'pais' ],
        'show_in_rest'      => true,
    ] );

    // -----------------------------------------------------------------------
    // SEASON / EDITION  (Django: Competition.edition, TeamStep.edition, etc.)
    // -----------------------------------------------------------------------
    register_taxonomy( 'f365_season', ['f365_competition', 'f365_livegames','f365_match', 'f365_ranking', 'f365_livestream' ], [
        'label'             => 'Season',
        'hierarchical'      => true,
        'public'            => true,
        'show_admin_column' => true,
        'rewrite'           => [ 'slug' => 'epoca' ],
        'show_in_rest'      => true,
    ] );

    // -----------------------------------------------------------------------
    // FEDERATION TYPE  (Django: Federation.federation_type)
    // -----------------------------------------------------------------------
    register_taxonomy( 'f365_fed_type', [ 'f365_federation' ], [
        'label'             => 'Federation Type',
        'hierarchical'      => true,
        'public'            => true,
        'show_admin_column' => true,
        'rewrite'           => false,
        'show_in_rest'      => true,
    ] );

    // -----------------------------------------------------------------------
    // COMPETITION SCOPE  (Django: FederationCompetition.scope)
    // -----------------------------------------------------------------------
    register_taxonomy( 'f365_comp_scope', [ 'f365_fed_comp' ], [
        'label'             => 'Competition Scope',
        'hierarchical'      => true,
        'public'            => true,
        'show_admin_column' => true,
        'rewrite'           => [ 'slug' => 'ambito-competicao' ],
        'show_in_rest'      => true,
    ] );

    // -----------------------------------------------------------------------
    // MODALITY  (Django: TeamStep.modality — football / futsal)
    // -----------------------------------------------------------------------
    register_taxonomy( 'f365_modality', [ 'f365_teamstep' ], [
        'label'             => 'Modality',
        'hierarchical'      => true,
        'public'            => true,
        'show_admin_column' => true,
        'rewrite'           => [ 'slug' => 'modalidade' ],
        'show_in_rest'      => true,
    ] );

    // -----------------------------------------------------------------------
    // GENDER  (Django: TeamStep.gender — male / female)
    // -----------------------------------------------------------------------
    register_taxonomy( 'f365_gender', [ 'f365_teamstep' ], [
        'label'             => 'Gender',
        'hierarchical'      => true,
        'public'            => true,
        'show_admin_column' => false,
        'rewrite'           => [ 'slug' => 'genero' ],
        'show_in_rest'      => true,
    ] );

    // -----------------------------------------------------------------------
    // TEAM STEP  (Django: TeamStep.step)
    // -----------------------------------------------------------------------
    register_taxonomy( 'f365_step', [ 'f365_teamstep' ], [
        'label'             => 'Team Step',
        'hierarchical'      => true,
        'public'            => true,
        'show_admin_column' => true,
        'rewrite'           => [ 'slug' => 'escalao' ],
        'show_in_rest'      => true,
    ] );

    // -----------------------------------------------------------------------
    // PLAYER POSITION  (Django: Player.position, Squad.position)
    // -----------------------------------------------------------------------
    register_taxonomy( 'f365_position', [ 'f365_player' ], [
        'label'             => 'Position',
        'hierarchical'      => true,
        'public'            => true,
        'show_admin_column' => true,
        'rewrite'           => [ 'slug' => 'posicao' ],
        'show_in_rest'      => true,
    ] );

    // -----------------------------------------------------------------------
    // MATCH SITUATION  (Django: Match.situation)
    // -----------------------------------------------------------------------
    register_taxonomy( 'f365_match_status', [ 'f365_match' ], [
        'label'             => 'Match Status',
        'hierarchical'      => true,
        'public'            => false,
        'show_admin_column' => true,
        'rewrite'           => false,
        'show_in_rest'      => true,
    ] );

    // -----------------------------------------------------------------------
    // TRANSFER TYPE  (Django: Transfer.transfer_type — D=definitive, E=loan)
    // -----------------------------------------------------------------------
    register_taxonomy( 'f365_transfer_type', [ 'f365_transfer' ], [
        'label'             => 'Transfer Type',
        'hierarchical'      => true,
        'public'            => true,
        'show_admin_column' => true,
        'rewrite'           => false,
        'show_in_rest'      => true,
    ] );

    // -----------------------------------------------------------------------
    // RANKING TYPE  (Django: ClubRanking / NationalRanking / PlayerRanking)  ← NEW
    // -----------------------------------------------------------------------
    register_taxonomy( 'f365_ranking_type', [ 'f365_ranking' ], [
        'label'             => 'Ranking Type',
        'hierarchical'      => true,
        'public'            => true,
        'show_admin_column' => true,
        'rewrite'           => false,
        'show_in_rest'      => true,
    ] );

    // -----------------------------------------------------------------------
    // RANKING SUBTYPE  (Django: PlayerRankingType lookup)  ← NEW
    // -----------------------------------------------------------------------
    register_taxonomy( 'f365_ranking_subtype', [ 'f365_ranking' ], [
        'label'             => 'Ranking Subtype',
        'hierarchical'      => true,
        'public'            => true,
        'show_admin_column' => true,
        'rewrite'           => [ 'slug' => 'rank-jogadores', 'with_front' => false ],
        'show_in_rest'      => true,
    ] );


    // -----------------------------------------------------------------------
    // PUBLISHER  (Django: Publisher / newsstand)  ← NEW
    // -----------------------------------------------------------------------
    register_taxonomy( 'f365_publisher', [ 'f365_newsstand' ], [
        'label'             => 'Publisher',
        'hierarchical'      => true,
        'public'            => true,
        'show_admin_column' => true,
        'rewrite'           => [ 'slug' => 'banca-de-jornais', 'with_front' => false ],
        'show_in_rest'      => true,
    ] );

    // Register built-in post_tag taxonomy attachments (photos, videos, galleries)
    register_taxonomy_for_object_type( 'post_tag', 'attachment' );
    register_taxonomy_for_object_type( 'post_tag', 'f365_video' );
    register_taxonomy_for_object_type( 'post_tag', 'f365_gallery' );

    // Player position on a match_player appearance (goalkeeper/defender/…).
	register_taxonomy( 'f365_player_position', [ 'f365_match_player' ], [
		'labels'            => function_exists( 'f365_labels' )
			? f365_labels( 'Position', 'Positions' )
			: [ 'name' => 'Positions', 'singular_name' => 'Position' ],
		'public'            => false,
		'show_ui'           => true,
		'show_admin_column' => true,
		'hierarchical'      => true,
		'show_in_rest'      => true,
		'rewrite'           => false,
	] );

	// Event type for match events (goal / penalty / own_goal / yellow / red / sub).
	// Lets you ask "all goals in this competition" via a simple tax query when
	// you later promote events to their own browsing surface.
	register_taxonomy( 'f365_event_type', [ 'f365_match_player' ], [
		'labels'            => function_exists( 'f365_labels' )
			? f365_labels( 'Event Type', 'Event Types' )
			: [ 'name' => 'Event Types', 'singular_name' => 'Event Type' ],
		'public'            => false,
		'show_ui'           => true,
		'show_admin_column' => false,
		'hierarchical'      => true,
		'show_in_rest'      => true,
		'rewrite'           => false,
	] );

    register_taxonomy(
        'f365_video_type',
        [ 'f365_video', 'f365_gallery' ],
        [
            'labels' => function_exists( 'f365_labels' )
                ? f365_labels( 'Video Type', 'Video Types' )
                : [
                    'name'          => 'Video Types',
                    'singular_name' => 'Video Type',
                ],

            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'hierarchical'      => false,
            'show_in_rest'      => true,
            'rewrite'           => false,
        ]
    );


}
