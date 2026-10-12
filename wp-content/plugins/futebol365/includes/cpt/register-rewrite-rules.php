<?php
/**
 * Futebol365 — Custom Rewrite Rules
 *
 * Mirrors the Django app's URL structure using the Portuguese slugs
 * already defined in register-cpt.php and register-taxonomies.php.
 *
 * Include this file in futebol365.php:
 *   require_once F365_PLUGIN_DIR . 'includes/cpt/register-rewrite-rules.php';
 *
 * After activating, go to Settings → Permalinks → Save Changes.
 *
 * Slug reference (from register-cpt.php / register-taxonomies.php):
 *   f365_team          → /equipa/
 *   f365_player        → /jogador/
 *   f365_coach         → /treinador/
 *   f365_president     → /presidente/
 *   f365_referee       → /arbitro/
 *   f365_federation    → /federacao/
 *   f365_fed_comp      → /competicao-tipo/
 *   f365_competition   → /competicao/
 *   f365_match         → /jogo/
 *   f365_stadium       → /estadio/
 *   f365_transfer      → /transferencia/
 *   f365_video         → /video/
 *   f365_gallery       → /fotogaleria/
 *   f365_country       → /pais/
 *   f365_season        → /epoca/
 *   f365_modality      → /modalidade/
 *   f365_gender        → /genero/
 *   f365_step          → /escalao/
 *   f365_position      → /posicao/
 *   category           → /categoria-noticias/
 *   author             → /fonte/
 *   f365_comp_scope    → /ambito-competicao/
 */

defined( 'ABSPATH' ) || exit;
require_once F365_PLUGIN_DIR . 'includes/rewrite-rule/federation-rewrite.php';
require_once F365_PLUGIN_DIR . 'includes/rewrite-rule/player-rewrite.php';
require_once F365_PLUGIN_DIR . 'includes/rewrite-rule/team-rewrite.php';
require_once F365_PLUGIN_DIR . 'includes/rewrite-rule/transfer-rewrite.php';
require_once F365_PLUGIN_DIR . 'includes/rewrite-rule/coach-rewrite.php';
require_once F365_PLUGIN_DIR . 'includes/rewrite-rule/president-rewrite.php';
require_once F365_PLUGIN_DIR . 'includes/rewrite-rule/referee-rewrite.php';
require_once F365_PLUGIN_DIR . 'includes/rewrite-rule/competition-rewrite.php';
require_once F365_PLUGIN_DIR . 'includes/rewrite-rule/match-rewrite.php';
require_once F365_PLUGIN_DIR . 'includes/rewrite-rule/gallery-rewrite.php';
require_once F365_PLUGIN_DIR . 'includes/rewrite-rule/video-rewrite.php';
require_once F365_PLUGIN_DIR . 'includes/rewrite-rule/stadium-rewrite.php';
require_once F365_PLUGIN_DIR . 'includes/rewrite-rule/livegames-rewrite.php';
require_once F365_PLUGIN_DIR . 'includes/rewrite-rule/ranking-rewrite.php';
require_once F365_PLUGIN_DIR . 'includes/rewrite-rule/live-streaming-rewrite.php';
require_once F365_PLUGIN_DIR . 'includes/rewrite-rule/sondagem-rewrite.php';
require_once F365_PLUGIN_DIR . 'includes/rewrite-rule/news-rewrite.php';
require_once F365_PLUGIN_DIR . 'includes/rewrite-rule/f365-goto-redirect.php';

add_action( 'init', 'f365_add_rewrite_rules', 20 );

function f365_add_rewrite_rules() {


    add_rewrite_rule(
        '^artigo/([^/]+)/?$',
        'index.php?post_type=post&name=$matches[1]',
        'top'
    );

    
    // ─────────────────────────────────────────────────────────────────────────
    // STADIUMS / ESTADIOS
    // /estadio/{slug}/                          → single (handled by WP)
    // /estadio/pais/{country}/                  → stadiums by country
    // ─────────────────────────────────────────────────────────────────────────

    add_rewrite_rule(
        '^estadio/pais/([^/]+)/?$',
        'index.php?post_type=f365_stadium&f365_country=$matches[1]',
        'top'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // VIDEOS
    // /video/                                   → archive (handled by WP)
    // /video/{slug}/                            → single (handled by WP)
    // /video/equipa/{team-slug}/                → videos for a team
    // /video/competicao/{comp-slug}/            → videos for a competition
    // ─────────────────────────────────────────────────────────────────────────

    add_rewrite_rule(
        '^video/equipa/([^/]+)/?$',
        'index.php?post_type=f365_video&f365_team_slug=$matches[1]',
        'top'
    );

    add_rewrite_rule(
        '^video/competicao/([^/]+)/?$',
        'index.php?post_type=f365_video&f365_competition_slug=$matches[1]',
        'top'
    );


    // ─────────────────────────────────────────────────────────────────────────
    // NEWS (standard WP posts with extra taxonomy filters)
    // /noticias/categoria/{cat}/                → news by category
    // /noticias/fonte/{source}/                 → news by source
    // /noticias/equipa/{team-slug}/             → news for a team
    // /noticias/competicao/{comp-slug}/         → news for a competition
    // ─────────────────────────────────────────────────────────────────────────

        // /noticias/fonte/{id}/
    add_rewrite_rule(
        '^noticias/fonte/([0-9]+)/?$',
        'index.php?f365_fonte_id=$matches[1]',
        'top'
    );
    // /noticias/source/{id}/
    add_rewrite_rule(
        '^noticias/source/([0-9]+)/?$',
        'index.php?f365_fonte_id=$matches[1]',
        'top'
    );

    add_rewrite_rule(
        '^noticias/categoria/([^/]+)/?$',
        'index.php?post_type=post&category_name=$matches[1]',
        'top'
    );

    add_rewrite_rule(
        '^noticias/equipa/([^/]+)/?$',
        'index.php?post_type=post&f365_team_slug=$matches[1]',
        'top'
    );

    add_rewrite_rule(
        '^noticias/competicao/([^/]+)/?$',
        'index.php?post_type=post&f365_competition_slug=$matches[1]',
        'top'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // POLLS / SONDAGENS
    // /sondagem/{poll_id}/                      → virtual single poll page
    // ─────────────────────────────────────────────────────────────────────────

    add_rewrite_rule(
        '^sondagem/([0-9]+)/?$',
        'index.php?f365_poll_id=$matches[1]',
        'top'
    );

}

// ─────────────────────────────────────────────────────────────────────────────
// REGISTER CUSTOM QUERY VARS
// All custom vars used in add_rewrite_rule() must be whitelisted here.
// ─────────────────────────────────────────────────────────────────────────────
add_filter( 'query_vars', 'f365_register_query_vars' );

function f365_register_query_vars( array $vars ): array {

    $central_vars = [
        'f365_competition_slug', // competition identifier in sub-routes
        'f365_team_slug',        // team identifier in sub-routes (video / news filters)
        'f365_round',            // match round / jornada number
        'f365_section',          // sub-page discriminator (shared by all modules)
        'f365_date',             // date filter: YYYY-MM-DD
        'f365_season',           // season/epoch filter
        'f365_country',          // country filter
        'f365_modality',         // modality filter
        'f365_gender',           // gender filter
        'f365_position',         // player position filter
        'f365_transfer_type',    // transfer type filter
        'f365_match_status',     // match status filter
        'f365_poll_id',          // poll virtual page ID
        'f365_fonte_id',          //author sourceID
    ];

    foreach ( $central_vars as $var ) {
        if ( ! in_array( $var, $vars, true ) ) {
            $vars[] = $var;
        }
    }

    return $vars;
}

/**
 * 1. Permalink: output /artigo/{post_name}/ for native posts.
 */
add_filter( 'post_link', 'f365_news_permalink', 10, 2 );
 
function f365_news_permalink( string $permalink, WP_Post $post ): string {
    if ( 'post' !== $post->post_type ) {
        return $permalink;
    }
    return user_trailingslashit( home_url( 'artigo/' . $post->post_name ) );
}
 


add_action( 'template_redirect', 'f365_author_redirect' );

function f365_author_redirect(): void {
    $django_source_id = get_query_var( 'f365_fonte_id' );
    if ( ! $django_source_id ) {
        return;
    }

    $django_source_id = (int) $django_source_id;

    // Find the WP user that has this Django source ID stored as usermeta
    $users = get_users( [
        'meta_key'   => 'django_source_id',
        'meta_value' => $django_source_id,
        'number'     => 1,
        'fields'     => [ 'ID', 'user_nicename' ],
    ] );

    if ( ! empty( $users ) ) {
        $slug = $users[0]->user_nicename; 
        wp_redirect( home_url( '/author/' . $slug . '/' ), 301 );
        exit;
    }
    wp_redirect( home_url( '/noticias/' ), 301 );
    exit;
}

add_action( 'pre_get_posts', 'f365_author_posts_per_page' );

function f365_author_posts_per_page( $query ) {

    if (
        ! is_admin() &&
        $query->is_main_query() &&
        is_author()
    ) {
        $query->set( 'posts_per_page', 18 );
    }
}