<?php
/**
 * Competition — single fallback.
 *
 * Loaded only if a section-specific template cannot be located. Delegates to
 * the Resumo template so a competition URL always renders something sensible.
 *
 * @package Futebol365
 */
defined( 'ABSPATH' ) || exit;

get_header(); ?>

<h1>Competition Resumo Template</h1> 

<?php get_footer();
