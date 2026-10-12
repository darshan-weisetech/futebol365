<?php
/**
 * Archive template for f365_ranking posts.
 *
 * Displays the clubes rankings archive.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

$rankings_helper = trailingslashit( get_stylesheet_directory() ) . 'inc/f365-rankings-clubes-functions.php';

if ( ! function_exists( 'f365_rankings_render_clubes' ) && file_exists( $rankings_helper ) ) {
	require_once $rankings_helper;
}

get_header();

if ( function_exists( 'f365_rankings_render_clubes' ) ) {
	echo f365_rankings_render_clubes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
} else {
	?>
	<div class="f365-ranking-page f365-ranking-fifa">
		<p class="f365-ranking-empty"><?php esc_html_e( 'Ranking indisponivel.', 'futebol365' ); ?></p>
	</div>
	<?php
}

get_footer();
