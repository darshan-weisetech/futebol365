<?php
/**
 * Compare sub-section registry for the Comparar-com (Frente-a-frente) module.
 * Load from functions.php BEFORE the compare header partial runs:
 *   require_once get_stylesheet_directory() . '/inc/f365-team-compare-sections.php';
 *
 * Mirrors the Django compare nav (team-compare* url names). The empty slug is
 * the Resumo (default /comparar-com/{id2}/).
 *
 * @package Futebol365
 */

defined('ABSPATH') || exit;

if (! function_exists('futebol365_get_team_compare_sections')) :
	function futebol365_get_team_compare_sections(): array {
		return [
			''           => ['label' => 'Resumo',     'slug' => ''],
			'confrontos' => ['label' => 'Confrontos', 'slug' => 'confrontos'],
			'forma'      => ['label' => 'Forma',      'slug' => 'forma'],
			'palmares'   => ['label' => 'Palmarés',   'slug' => 'palmares'],
			'estadios'   => ['label' => 'Estádios',   'slug' => 'estadios'],
		];
	}
endif;
