<?php
/**
 * Live Games — listing / archive  /jogos-em-direto/
 *
 * Mirrors Django live.views.live_matches → 'live/live_matches.html':
 * today's (and near) live games, grouped by competition, with status tabs
 * (Todos / Direto / Próximos / Terminados). Each row links to the details page
 * /jogos-em-direto/{livematch_id}/.
 *
 * Data: f365_livegames posts whose `datetime` falls in the live window, read
 * through standard WP_Query + postmeta (no ACF repeaters needed for the list).
 *
 * @package Futebol365
 */
defined( 'ABSPATH' ) || exit;

get_header(); ?>

<h1><?php esc_html_e('Live Games — listing', 'futebol365'); ?></h1>

<?php get_footer(); ?>
