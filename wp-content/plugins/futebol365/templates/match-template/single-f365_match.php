<?php
/**
 * Match — single detail  /jogo/{django_match_id}/
 *
 * Full match sheet: scoreboard, timeline of events, both lineups (starters and
 * substitutes), match statistics, referees and coaches. Every relational block
 * is read from a dedicated indexed table through the repository — there are no
 * ACF repeaters involved, so a match with 30+ players and dozens of events is a
 * handful of indexed SELECTs rather than a giant serialised blob.
 *
 * @package Futebol365
 */
defined( 'ABSPATH' ) || exit;
get_header(); ?>

<h1>Match template</h1>

<?php get_footer();
