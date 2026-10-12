<?php

/**
 * Single Stadium Template
 *
 * @package Futebol365
 */

get_header();

if (have_posts()) :
	while (have_posts()) :
		the_post();

		$post_id = get_the_ID();
		$current_section = get_query_var(
			'f365_section',
			'summary'
		);

		$section_map = [
			'ultimos-jogos'  => 'recent-games',
			'proximos-jogos' => 'upcoming-games',
			'mapa'           => 'mapa',
			'info'           => 'info',
		];

		if (
			isset(
				$section_map[$current_section]
			)
		) {

			$current_section =
				$section_map[$current_section];
		}

		$sections = [

			'summary' => [
				'label'    => 'Resumo',
				'url'      => '',
				'template' => 'summary',
			],

			'recent-games' => [
				'label'    => 'Últimos jogos',
				'url'      => 'ultimos-jogos',
				'template' => 'f365-stadium-ultimos-jogos',
			],

			'upcoming-games' => [
				'label'    => 'Próximos jogos',
				'url'      => 'proximos-jogos',
				'template' => 'f365-stadium-proximos-jogos',
			],

			'mapa' => [
				'label'    => 'Mapa',
				'url'      => 'mapa',
				'template' => 'f365-stadium-mapa',
			],

			'info' => [
				'label'    => 'Dados Gerais',
				'url'      => 'info',
				'template' => 'f365-stadium-info',
			],

		];

		if (
			! isset(
				$sections[$current_section]
			)
		) {

			$current_section = 'summary';
		}

		$current_config =
			$sections[$current_section];
?>

		<main id="primary" class="site-main single-article-page">
			<div class="ink-grid vertical-space">
				<?php
				get_template_part(
					'template-parts/template-offer-widget'
				);
				?>

				<?php
				get_template_part(
					'template-parts/stadium/hero',
					null,
					[
						'post_id' => $post_id,
					]
				);
				?>

				<?php
				get_template_part(
					'template-parts/stadium/tabs',
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
						'template-parts/stadium/breadcrumbs',
						null,
						[
							'post_id' => $post_id,
						]
					);
					?>

					<?php
					get_template_part(
						'template-parts/stadium/' .
							$current_config['template'],
						null,
						[
							'post_id' => $post_id,
						]
					);
					?>

					<?php
					get_template_part(
						'template-parts/template-front-page-posts'
					);
					?>

				</section>

				<section class="right-column-block">

					<?php
					if (
						is_active_sidebar(
							'post-sidebar'
						)
					) {

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
	endwhile;
endif;
get_footer();
