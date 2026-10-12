<?php
/**
 * Live Streaming — homepage  /live-streaming/
 *
 * Mirrors Django live.views.live_streaming_homepage → 'live/live_streaming_homepage.html':
 * three curated lists (Em direto / Brevemente / Mais recentes), each capped at
 * 10 rows with a "ver mais" link to the corresponding /live-streaming/{status}/ page.
 *
 * Lists are built through f365_live_streaming_get_by_status(), which applies the
 * same status windows as LiveStreaming.get_livestreaming_by_type().
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

get_header();

require_once trailingslashit( F365_PLUGIN_DIR ) . 'templates/live-streaming-template/f365-live-streaming-tabs.php';

$blocks = [
	'inlive'   => 'Em direto',
	'tostart'  => 'Brevemente',
	'finished' => 'Mais recentes',
];
?>

<div class="f365-live-streaming f365-live-streaming--home">
	<h1 class="condensed-400 headerTitle">Futebol em Direto — Live Streaming</h1>

	<?php f365_live_streaming_tabs( 'homepage' ); ?>

	<?php foreach ( $blocks as $status => $label ) :
		$query = f365_live_streaming_get_by_status( $status, 10 );
		if ( ! $query->have_posts() ) { continue; }
		?>
		<section class="f365-live-streaming__block f365-live-streaming__block--<?php echo esc_attr( $status ); ?>">
			<h3 class="condensed-400 headerTitle orange">
				<a href="<?php echo esc_url( f365_live_streaming_status_url( $status ) ); ?>"><?php echo esc_html( $label ); ?></a>
			</h3>

			<?php f365_live_streaming_table( $query ); ?>

			<div class="column-group">
				<div class="bottom-space small-double-bottom-space tiny-double-bottom-space">
					<a class="ink-button" href="<?php echo esc_url( f365_live_streaming_status_url( $status ) ); ?>">ver mais</a>
				</div>
			</div>
		</section>
	<?php endforeach; ?>
</div>

<?php get_footer(); ?>
