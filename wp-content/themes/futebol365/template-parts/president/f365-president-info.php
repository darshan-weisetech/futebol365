<?php
/**
 * President Info Template — Delegate
 *
 * URL: /dirigente/{id}/info/
 * Loaded by president-rewrite.php when f365_section = 'info'.
 *
 * This file is a thin delegate. All rendering logic — including the shared
 * header, breadcrumbs, and sidebar — lives in single-f365_president.php.
 * The $section query var is already set to 'info' by the rewrite rule,
 * so single-f365_president.php will render the correct content block.
 *
 * No duplication of header, breadcrumbs, sidebar, or data fetching.
 */

defined( 'ABSPATH' ) || exit;

$template = locate_template( 'single-f365_president.php', false, false );

if ( $template ) {
    require $template;
} else {
    // Fallback: should never happen if theme is correctly structured
    wp_die( esc_html__( 'President template not found.', 'futebol365' ) );
}