<?php
/**
 * Referee — "Jogos" route entry point.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The plugin's template router (referee-rewrite.php → f365_referee_template_include)
 * loads a DIFFERENT top-level template per section:
 *
 *     /arbitro/{id}/        → single-f365_referee.php   (full shell + Resumo)
 *     /arbitro/{id}/jogos/  → f365-referee-jogos.php    (THIS FILE)
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

get_template_part( 'single-f365_referee' );