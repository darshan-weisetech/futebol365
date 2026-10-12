<?php
/**
 * Referee — Single Template (Unified Controller)
 *
 * Routes between the Resumo (default) and Jogos sections, loads only the data
 * the active section needs, and renders shared header + breadcrumbs + sidebar.
 *
 * Routing:    plugin referee-rewrite.php (query var f365_section = '' | 'jogos').
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

get_header();

$referee_post = function_exists( 'f365_get_current_referee_post' )
	? f365_get_current_referee_post()
	: ( ( $q = (int) get_query_var( 'f365_referee_id' ) ) ? get_post( $q ) : null );

// Empty-state, not 404: keep the page shell rendering (per brief).
$referee = ( $referee_post instanceof WP_Post && function_exists( 'f365_get_referee_data' ) )
	? f365_get_referee_data( $referee_post->ID )
	: [];

if ( $referee_post instanceof WP_Post ) {
	global $post;
	$post = $referee_post;
	setup_postdata( $post );
}

// ── Section routing ──────────────────────────────────────────────────────────
$sections        = function_exists( 'get_referee_section_functions' ) ? get_referee_section_functions() : [];
$current_section = (string) get_query_var( 'f365_section' );
if ( $current_section === '' || ! isset( $sections[ $current_section ] ) ) {
	$current_section = 'resumo';
}

// ── Load ONLY the active section's data ───────────────────────────────────────
$referee_id = ( $referee_post instanceof WP_Post ) ? (int) $referee_post->ID : 0;

$resume_data = [];
$games_data  = [];

if ( $referee_id > 0 ) {
	if ( $current_section === 'jogos' ) {
		$edition    = isset( $_GET['edition'] ) ? sanitize_text_field( wp_unslash( $_GET['edition'] ) ) : '';
		$games_data = f365_get_referee_games( $referee_id, $edition );
	} else {
		$resume_data = f365_get_referee_resume( $referee_id );
	}
}
?>

<main id="primary" class="site-main single-article-page referee-page">

	<?php get_template_part( 'template-parts/template-offer-widget' ); ?>


	<div class="ink-grid futebol365-container">

		<section class="left-column-block">
			

			<div class="breadcrumbs-f365">
				<?php if ( function_exists( 'yoast_breadcrumb' ) ) : ?>
					<?php yoast_breadcrumb(
						'<nav class="ink-navigation breadcrumbs " aria-label="' . esc_attr__( 'Breadcrumbs', 'futebol365' ) . '">',
						'</nav>'
					); ?>
				<?php endif; ?>
			</div>

			<?php get_template_part(
				'template-parts/referee/f365-referee-header',
				null,
				[
					'referee'         => $referee,
					'current_section' => $current_section,
					'sections'        => $sections,
				]
			); ?>

			<?php if ( $current_section === 'jogos' ) : ?>
				<?php get_template_part(
					'template-parts/referee/f365-referee-games',
					null,
					[ 'referee' => $referee, 'games' => $games_data ]
				); ?>
			<?php else : ?>
				<?php get_template_part(
					'template-parts/referee/f365-referee-resume',
					null,
					[ 'referee' => $referee, 'resume' => $resume_data ]
				); ?>
			<?php endif; ?>

			<?php get_template_part('template-parts/template-front-page-posts'); ?>


		</section>

		<section class="right-column-block">
			<?php if ( is_active_sidebar( 'post-sidebar' ) ) : ?>
				<?php dynamic_sidebar( 'post-sidebar' ); ?>
			<?php else : ?>
				<?php get_sidebar(); ?>
			<?php endif; ?>
		</section>

	</div>
</main>

<?php
if ( $referee_post instanceof WP_Post ) {
	wp_reset_postdata();
}
get_footer();