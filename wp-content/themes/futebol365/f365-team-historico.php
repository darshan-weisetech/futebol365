<?php
/**
 * Team Histórico
 *
 * @package Futebol365
 */

get_header();

$team = f365_get_current_team_post();

if ( ! $team ) {

	echo '<h3>Equipa não encontrada.</h3>';

	get_footer();

	return;

}

$post_id = $team->ID;

$current_section = 'historico';

$sections = [
	'jogos' => [
		'label' => 'Games',
		'url'   => 'jogos',
	],
	'plantel' => [
		'label' => 'Squad',
		'url'   => 'plantel',
	],
	'palmares' => [
		'label' => 'Palmares',
		'url'   => 'palmares',
	],
	'noticias' => [
		'label' => 'News',
		'url'   => 'noticias',
	],
	'videos' => [
		'label' => 'Videos',
		'url'   => 'videos',
	],
	'historico' => [
		'label' => 'History',
		'url'   => 'historico',
	],
	'info' => [
		'label' => 'General Information',
		'url'   => 'info',
	],
	'transferencias' => [
		'label' => 'Transfers',
		'url'   => 'transferencias',
	],
];
?>

<main
	id="primary"
	class="site-main single-article-page"
>

	<div class="ink-grid vertical-space">

		<?php
		get_template_part(
			'template-parts/template-offer-widget'
		);
		?>

		<?php
		get_template_part(
			'template-parts/team/hero',
			null,
			[
				'post_id' => $post_id,
			]
		);
		?>

		<?php
		get_template_part(
			'template-parts/team/tabs',
			null,
			[
				'post_id'         => $post_id,
				'sections'        => $sections,
				'current_section' => $current_section,
			]
		);
		?>

	</div>

	<div class="ink-grid futebol365-container">

		<section class="left-column-block">

			<?php
			get_template_part(
				'template-parts/team/breadcrumbs',
				null,
				[
					'post_id' => $post_id,
				]
			);
			?>

			<?php
			get_template_part(
				'template-parts/team/f365-team-historico-content',
				null,
				[
					'post_id' => $post_id,
				]
			);
			?>

		</section>

		<section class="right-column-block">

			<?php

			if ( is_active_sidebar( 'post-sidebar' ) ) {

				dynamic_sidebar(
					'post-sidebar'
				);

			} else {

				get_sidebar();

			}

			?>

		</section>

	</div>

</main>

<?php
get_footer();