<?php
/**
 * Futebol365 — Player Rewrite Rules & Permalink Handling
 *
 * Handles custom routing for player pages and their sub-sections:
 * - /jogador/{id}/
 * - /jogador/{id}/jogos/
 * - /jogador/{id}/palmares/
 * - /jogador/{id}/premios/
 * - /jogador/{id}/transferencias/
 * - /jogador/{id}/noticias/
 * - /jogador/{id}/videos/
 * - /jogador/{id}/dados-pessoais/
 *
 * @package Futebol365
 * @since   2.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Set Yoast SEO title for Transfers archive.
 */
add_filter('wpseo_title', function ($title) {

    if (is_post_type_archive('f365_transfer')) {
        return __('Mercado de Transferências - Futebol 365', 'futebol365');
    }

    return $title;
});

/**
 * Set Yoast SEO meta description for Transfers archive.
 */
add_filter('wpseo_metadesc', function ($desc) {

    if (is_post_type_archive('f365_transfer')) {
        return __('Acompanhe as mais recentes transferências do futebol nacional e internacional.', 'futebol365');
    }

    return $desc;
},99);