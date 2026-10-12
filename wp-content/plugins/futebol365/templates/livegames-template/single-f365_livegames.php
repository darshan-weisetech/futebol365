<?php
/**
 * Live Game — single detail  /jogos-em-direto/{django_livematch_id}/
 *
 * Mirrors Django live.views.live_match_details → 'live/live_match_details.html':
 * scoreboard, goal scorers, timeline of events, both lineups (starters and
 * substitutes), coaches/formations and live statistics. Each block is a small
 * partial in templates/livegames-template/, loaded below — the same partial
 * pattern used by the Competition module.
 *
 * @package Futebol365
 */
defined( 'ABSPATH' ) || exit;

get_header(); ?>

<h1><?php esc_html_e('Live Games — single detail', 'futebol365'); ?></h1>

<?php get_footer(); ?>
