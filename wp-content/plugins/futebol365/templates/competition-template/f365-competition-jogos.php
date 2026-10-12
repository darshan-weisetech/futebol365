<?php
/**
 * Competition — Jogos  /competicao/{fed_comp_id}/jogos/
 * Optional round filter: /competicao/{fed_comp_id}/jornada/{n}/  (?edition=)
 *
 * Lists every match of the edition, grouped by round (jornada). Reads scalar
 * match data straight off post meta through the repository — paginated at the
 * DB level so even competitions with thousands of matches stay fast.
 *
 * @package Futebol365
 */
defined( 'ABSPATH' ) || exit;
get_header(); ?>

<h1>Competition jogos Template</h1> 

<?php get_footer();
