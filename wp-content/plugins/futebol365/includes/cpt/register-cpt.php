<?php
/**
 * Register all Custom Post Types — v2.0.0
 *
 * Django model → WordPress CPT mapping (original + new modules):
 *   Team                → f365_team
 *   Player              → f365_player
 *   Coach               → f365_coach
 *   President           → f365_president
 *   Referee             → f365_referee
 *   Federation          → f365_federation
 *   FederationComp.     → f365_fed_comp
 *   Competition         → f365_competition
 *   Match               → f365_match
 *   Stadium             → f365_stadium
 *   Transfer            → f365_transfer
 *   Video               → f365_video
 *   PhotoGallery        → f365_gallery
 *   News                → post (native WP)
 *   PublicationEdition  → f365_newsstand    ← NEW
 *   Publisher           → f365_publisher taxonomy ← NEW
 *   LiveStreaming        → f365_livestream   ← NEW
 *   ClubRanking         → f365_ranking      ← NEW
 *   PlayerRanking       → f365_ranking      (same CPT, typed by meta)
 *   Author              → WP native user    ← uses native WP users (no CPT)
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'f365_register_cpt' );

function f365_register_cpt(): void {

    // -----------------------------------------------------------------------
    // TEAM  (Django: Team + TeamStep)
    // -----------------------------------------------------------------------
    register_post_type( 'f365_team', [
        'labels'        => f365_labels( 'Team', 'Teams' ),
        'public'        => true,
        'has_archive'   => true,
        'menu_icon'     => 'dashicons-groups',
        'supports'      => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'author' ],
        'rewrite'       => [ 'slug' => 'equipa' ],
        'show_in_rest'  => true,
    ] );

    // // -----------------------------------------------------------------------
    // // TEAM STEP  (Django: TeamStep — pivot between Team and every other entity)
    // // -----------------------------------------------------------------------
    // register_post_type( 'f365_teamstep', [
    //     'labels'       => f365_labels( 'Team Step', 'Team Steps' ),
    //     'public'       => false,            // not browsable on frontend directly
    //     'show_ui'      => true,             // visible in admin
    //     'show_in_menu' => 'edit.php?post_type=f365_team', // nested under Teams menu
    //     'menu_icon'    => 'dashicons-chart-bar',
    //     'supports'     => [ 'title', 'custom-fields' ],
    //     'show_in_rest' => true,
    //     'rewrite'      => false,
    //     /*
    //     * post_title convention: "{shortname} {step_suffix} {edition}"
    //     * e.g. "Sporting CP 2024", "Sporting CP B 2023", "Sporting CP S21 2024"
    //     */
    // ] );

    // // -----------------------------------------------------------------------
    // // SQUAD  (Django: Squad + SquadCompetition)
    // // One post = one player in one teamstep for one edition.
    // // -----------------------------------------------------------------------
    // register_post_type( 'f365_squad', [
    //     'labels'       => f365_labels( 'Squad Member', 'Squad Members' ),
    //     'public'       => false,
    //     'show_ui'      => true,
    //     'show_in_menu' => 'edit.php?post_type=f365_team',
    //     'menu_icon'    => 'dashicons-admin-users',
    //     'supports'     => [ 'title', 'custom-fields' ],
    //     'show_in_rest' => true,
    //     'rewrite'      => false,
    //     /*
    //     * post_title convention: "{player_shortname} — {team_shortname} {edition}"
    //     * e.g. "Cristiano Ronaldo — Sporting CP 2002"
    //     */
    // ] );

    // -----------------------------------------------------------------------
    // TEAM PRESIDENT  (Django: TeamPresident)
    // One post = one president assignment for one team edition.
    // -----------------------------------------------------------------------
    // register_post_type( 'f365_team_president', [
    //     'labels'       => f365_labels( 'Team President', 'Team Presidents' ),
    //     'public'       => false,
    //     'show_ui'      => true,
    //     'show_in_menu' => 'edit.php?post_type=f365_team',
    //     'menu_icon'    => 'dashicons-id-alt',
    //     'supports'     => [ 'title', 'custom-fields' ],
    //     'show_in_rest' => true,
    //     'rewrite'      => false,
    // ] );


    // -----------------------------------------------------------------------
    // PLAYER  (Django: Player + Person)
    // -----------------------------------------------------------------------
    register_post_type( 'f365_player', [
        'labels'        => f365_labels( 'Player', 'Players' ),
        'public'        => true,
        'has_archive'   => true,
        'menu_icon'     => 'dashicons-admin-users',
        'supports'      => [ 'title', 'thumbnail', 'custom-fields', 'author' ],
        'rewrite'       => [ 'slug' => 'jogador' ],
        'show_in_rest'  => true,
    ] );

    // -----------------------------------------------------------------------
    // COACH  (Django: Coach + Person)
    // -----------------------------------------------------------------------
    register_post_type( 'f365_coach', [
        'labels'        => f365_labels( 'Coach', 'Coaches' ),
        'public'        => true,
        'has_archive'   => true,
        'menu_icon'     => 'dashicons-businessman',
        'supports'      => [ 'title', 'editor', 'thumbnail', 'custom-fields', 'author' ],
        'rewrite'       => [ 'slug' => 'treinador' ],
        'show_in_rest'  => true,
    ] );

    // -----------------------------------------------------------------------
    // PRESIDENT  (Django: President + Person)
    // -----------------------------------------------------------------------
    register_post_type( 'f365_president', [
        'labels'        => f365_labels( 'President', 'Presidents' ),
        'public'        => true,
        'has_archive'   => true,
        'menu_icon'     => 'dashicons-id-alt',
        'supports'      => [ 'title', 'editor', 'thumbnail', 'custom-fields', 'author' ],
        'rewrite'       => [ 'slug' => 'presidente' ],
        'show_in_rest'  => true,
    ] );

    // -----------------------------------------------------------------------
    // REFEREE  (Django: Referee + Person)
    // -----------------------------------------------------------------------
    register_post_type( 'f365_referee', [
        'labels'        => f365_labels( 'Referee', 'Referees' ),
        'public'        => true,
        'has_archive'   => true,
        'menu_icon'     => 'dashicons-flag',
        'supports'      => [ 'title', 'editor', 'thumbnail', 'custom-fields', 'author' ],
        'rewrite'       => [ 'slug' => 'arbitro' ],
        'show_in_rest'  => true,
    ] );

    // -----------------------------------------------------------------------
    // FEDERATION  (Django: Federation — self-referential hierarchy)
    // -----------------------------------------------------------------------
    register_post_type( 'f365_federation', [
        'labels'        => f365_labels( 'Federation', 'Federations' ),
        'public'        => true,
        'has_archive'   => true,
        'hierarchical'  => true,
        'menu_icon'     => 'dashicons-building',
        'supports'      => [ 'title', 'editor', 'thumbnail', 'page-attributes', 'custom-fields' ],
        'rewrite'       => [ 'slug' => 'federacao' ],
        'show_in_rest'  => true,
    ] );

    // -----------------------------------------------------------------------
    // FEDERATION COMPETITION  (Django: FederationCompetition)
    // -----------------------------------------------------------------------
    register_post_type( 'f365_fed_comp', [
        'labels'        => f365_labels( 'Competition Type', 'Competition Types' ),
        'public'        => true,
        'has_archive'   => false,
        'menu_icon'     => 'dashicons-awards',
        'supports'      => [ 'title', 'thumbnail', 'custom-fields' ],
        'rewrite'       => [ 'slug' => 'competicao-tipo' ],
        'show_in_rest'  => true,
    ] );

    // -----------------------------------------------------------------------
    // COMPETITION  (Django: Competition — one edition of a tournament)
    // -----------------------------------------------------------------------
    register_post_type( 'f365_competition', [
        'labels'        => f365_labels( 'Competition', 'Competitions' ),
        'public'        => true,
        'has_archive'   => true,
        'menu_icon'     => 'dashicons-chart-line',
        'supports'      => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
        'rewrite'       => [ 'slug' => 'competicao' ],
        'show_in_rest'  => true,
    ] );

    // -----------------------------------------------------------------------
    // MATCH  (Django: Match)
    // -----------------------------------------------------------------------
    register_post_type( 'f365_match', [
        'labels'        => f365_labels( 'Match', 'Matches' ),
        'public'        => true,
        'has_archive'   => true,
        'menu_icon'     => 'dashicons-calendar-alt',
        'supports'      => [ 'title', 'editor', 'custom-fields' ],
        'rewrite'       => [ 'slug' => 'jogo' ],
        'show_in_rest'  => true,
    ] );

    // -----------------------------------------------------------------------
	// MATCH PLAYER  (Django: MatchPlayer)
	// One post = one player's appearance in one match. This is the largest
	// dataset (≈22–30 rows per match) so it is a first-class CPT rather than a
	// repeater: it stays queryable ("every appearance by player X"), paginable
	// and aggregatable ("top scorers") through core posts/postmeta indexes.
	// -----------------------------------------------------------------------
	// register_post_type( 'f365_match_player', [
	// 	'labels'       => function_exists( 'f365_labels' )
	// 		? f365_labels( 'Match Player', 'Match Players' )
	// 		: [ 'name' => 'Match Players', 'singular_name' => 'Match Player' ],
	// 	'public'       => false,           // never browsed directly on the frontend
	// 	'show_ui'      => true,            // editable in admin
	// 	'show_in_menu' => 'edit.php?post_type=f365_match',
	// 	'menu_icon'    => 'dashicons-id',
	// 	'supports'     => [ 'title', 'custom-fields' ],
	// 	'show_in_rest' => true,
	// 	'rewrite'      => false,
	// 	/*
	// 	 * post_title convention: "{player} — {home} v {visitor} ({date})"
	// 	 * Lineups/events are read through F365_Competition_Repository.
	// 	 */
	// ] );

	// -----------------------------------------------------------------------
    // STADIUM  (Django: Stadium)
    // -----------------------------------------------------------------------
    register_post_type( 'f365_stadium', [
        'labels'        => f365_labels( 'Stadium', 'Stadiums' ),
        'public'        => true,
        'has_archive'   => true,
        'menu_icon'     => 'dashicons-location',
        'supports'      => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
        'rewrite'       => [ 'slug' => 'estadio' ],
        'show_in_rest'  => true,
    ] );

    // -----------------------------------------------------------------------
    // TRANSFER  (Django: Transfer)
    // -----------------------------------------------------------------------
    register_post_type('f365_transfer', [
        'labels'        => f365_labels('Transfer', 'Transfers'),
        'public'        => false,
        'publicly_queryable' => false,
        'exclude_from_search' => true,
        'has_archive'   => false,
        'rewrite'       => false,
        'query_var'     => false,
        'show_ui'       => true,
        'show_in_menu'  => true,
        'menu_icon'     => 'dashicons-randomize',
        'supports'      => ['title', 'custom-fields'],
        'show_in_rest'  => true,
    ]);

    // -----------------------------------------------------------------------
    // VIDEO  (Django: Video)
    // -----------------------------------------------------------------------
    register_post_type( 'f365_video', [
        'labels'        => f365_labels( 'Video', 'Videos' ),
        'public'        => true,
        'has_archive'   => true,
        'menu_icon'     => 'dashicons-video-alt3',
        'supports'      => [ 'title', 'thumbnail', 'custom-fields', 'author' ],
        'rewrite'       => [ 'slug' => 'multimedia/videos' ],
        'show_in_rest'  => true,
    ] );

    // -----------------------------------------------------------------------
    // PHOTO GALLERY  (Django: PhotoGallery)
    // -----------------------------------------------------------------------
    register_post_type( 'f365_gallery', [
        'labels'        => f365_labels( 'Photo Gallery', 'Photo Galleries' ),
        'public'        => true,
        'has_archive'   => 'multimedia/fotogalerias',
        'menu_icon'     => 'dashicons-format-gallery',
        'supports'      => [ 'title', 'thumbnail', 'custom-fields', 'author' ],
        'rewrite'       => [ 'slug' => 'multimedia/fotogalerias' ],
        'show_in_rest'  => true,
    ] );

    // -----------------------------------------------------------------------
    // NEWSSTAND  (Django: PublicationEdition — cover image per publisher)  ← NEW
    // -----------------------------------------------------------------------
    register_post_type( 'f365_newsstand', [
        'labels'        => f365_labels( 'Newsstand Edition', 'Newsstand Editions' ),
        'public'        => true,
        'has_archive'   => true,
        'menu_icon'     => 'dashicons-book-alt',
        'supports'      => [ 'title', 'thumbnail', 'custom-fields' ],
        'rewrite'       => [ 'slug' => 'banca-de-jornais' ],
        'show_in_rest'  => true,
    ] );

    // -----------------------------------------------------------------------
    // LIVE STREAMING  (Django: LiveStreaming)  ← NEW
    // Maps LiveStreaming.match → f365_match FK; stores streaming URL per match.
    // -----------------------------------------------------------------------
    register_post_type( 'f365_livestream', [
        'labels'        => f365_labels( 'Live Streaming', 'Live Streamings' ),
        'public'        => true,
        'has_archive'   => 'live-streaming',
        'menu_icon'     => 'dashicons-controls-play',
        'supports'      => [ 'title', 'editor', 'thumbnail', 'custom-fields', 'author' ],
        'rewrite'       => [ 'slug' => 'live-streaming' ],
        'show_in_rest'  => true,
    ] );

    register_post_type( 'f365_livegames', [
        'labels'        => f365_labels( 'Live Games', 'Live Games' ),
        'public'        => true,
        'has_archive'   => true,
        'menu_icon'     => 'dashicons-games',
        'supports'      => [ 'title', 'editor', 'thumbnail', 'custom-fields', 'author' ],
        'rewrite'       => [ 'slug' => 'jogos-em-direto' ],
        'show_in_rest'  => true,
    ] );

    // -----------------------------------------------------------------------
    // LIVE GAME PLAYER  (Django: LiveMatchPlayer)  ← NEW
    // One post = one player's appearance in one live game. Mirrors the
    // f365_match_player design: a queryable child CPT rather than a repeater,
    // nested under the Live Games menu and never browsed on the frontend.
    // -----------------------------------------------------------------------
    // register_post_type( 'f365_livegame_player', [
    //     'labels'       => f365_labels( 'Live Game Player', 'Live Game Players' ),
    //     'public'       => false,
    //     'show_ui'      => true,
    //     'show_in_menu' => 'edit.php?post_type=f365_livegames',
    //     'menu_icon'    => 'dashicons-id',
    //     'supports'     => [ 'title', 'custom-fields' ],
    //     'show_in_rest' => true,
    //     'rewrite'      => false,
    //     /*
    //      * post_title convention: "({number}) {player_name}".
    //      * Linked to its live game through the `live_game` relationship meta.
    //      */
    // ] );

    // -----------------------------------------------------------------------
    // RANKING  (Django: ClubRanking + NationalRanking + PlayerRanking)  ← NEW
    // A single CPT handles all ranking types; discriminated by the
    // `ranking_type` meta key ('club', 'national', 'player').
    // -----------------------------------------------------------------------
    register_post_type( 'f365_ranking', [
        'labels'        => f365_labels( 'Ranking', 'Rankings' ),
        'public'        => true,
        'has_archive'   => true,
        'menu_icon'     => 'dashicons-chart-area',
        'supports'      => [ 'title', 'custom-fields', 'author' ],
        'rewrite'       => [ 'slug' => 'ranking' ],
        'show_in_rest'  => true,
    ] );

    // -----------------------------------------------------------------------
    // SONDAGEM  (Poll — backed by AYS Poll Maker `ayspoll_polls`)
    // URLs are mapped manually in includes/rewrite-rule/sondagem-rewrite.php,
    // so 'rewrite' => false.
    // -----------------------------------------------------------------------
    register_post_type( 'sondagem', [
        'labels'             => f365_labels( 'Sondagem', 'Sondagens' ),
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'menu_icon'          => 'dashicons-chart-pie',
        'supports'           => [ 'title', 'editor', 'thumbnail' ],
        'rewrite'            => false,
        'show_in_rest'       => true,
    ] );
}

/**
 * Helper: build standard label array.
 */
function f365_labels( string $singular, string $plural ): array {
    return [
        'name'               => $plural,
        'singular_name'      => $singular,
        'add_new'            => "Add New $singular",
        'add_new_item'       => "Add New $singular",
        'edit_item'          => "Edit $singular",
        'new_item'           => "New $singular",
        'view_item'          => "View $singular",
        'search_items'       => "Search $plural",
        'not_found'          => "No $plural found",
        'not_found_in_trash' => "No $plural in trash",
    ];
}

/**
 * Custom Rewrite Rules and Permalinks for Newsstand.
 * Creates URLs like: /banca-de-jornais/a-bola/14-04-2026/
 */
add_action( 'init', 'f365_newsstand_rewrites' );
function f365_newsstand_rewrites(): void {
    // Single Newsstand Edition: /banca-de-jornais/{publisher}/{date}/
    add_rewrite_rule(
        '^banca-de-jornais/([^/]+)/([^/]+)/?$',
        'index.php?f365_newsstand=$matches[1]-$matches[2]',
        'top'
    );
    
    // Publisher Taxonomy Archive: /banca-de-jornais/{publisher}/
    add_rewrite_rule(
        '^banca-de-jornais/([^/]+)/?$',
        'index.php?f365_publisher=$matches[1]',
        'top'
    );
}

// Override Permalink Manager for Publisher Taxonomy
add_filter( 'term_link', 'f365_publisher_term_link', 9999, 3 );
function f365_publisher_term_link( string $termlink, $term, string $taxonomy ): string {
    if ( 'f365_publisher' === $taxonomy ) {
        return home_url( user_trailingslashit( "banca-de-jornais/{$term->slug}" ) );
    }
    return $termlink;
}

add_filter( 'post_type_link', 'f365_newsstand_permalink', 9999, 2 );
function f365_newsstand_permalink( string $post_link, WP_Post $post ): string {
    if ( 'f365_newsstand' === $post->post_type ) {
        $terms = wp_get_post_terms( $post->ID, 'f365_publisher' );
        if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
            $pub_slug = $terms[0]->slug;
            $prefix   = $pub_slug . '-';
            
            if ( str_starts_with( $post->post_name, $prefix ) ) {
                $date_slug = substr( $post->post_name, strlen( $prefix ) );
                return home_url( user_trailingslashit( "banca-de-jornais/{$pub_slug}/{$date_slug}" ) );
            }
        }
    }
    return $post_link;
}