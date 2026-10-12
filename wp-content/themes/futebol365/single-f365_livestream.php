<?php
/**
 * Live Streaming single detail page.
 *
 * Reads the migrated Django tables directly with the WordPress database prefix:
 * wp_live_livestreaming -> wp_competition_match -> related competition/team/live tables.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;
get_header();

global $wpdb;

$livestream_id = (int) get_query_var( 'f365_livestream_id' );

if ( ! $livestream_id && preg_match( '#/live-streaming/(\d+)#', $_SERVER['REQUEST_URI'] ?? '', $matches ) ) {
	$livestream_id = (int) $matches[1];
}

$current_section = isset( $args['current_section'] ) ? $args['current_section'] : 'details';
$bg_field        = function_exists( 'get_field' ) ? get_field( 'federation_header_bg_image', 'option' ) : array();
$bg_image_url    = $bg_field['url'] ?? ( function_exists( 'futebol365_fallback_image' ) ? futebol365_fallback_image() : '' );
$theme_dir       = get_stylesheet_directory();
$tabs_file       = $theme_dir . '/template-parts/livestreaming/f365-live-streaming-tabs.php';

foreach ( array( '/inc/f365-livestreaming-function.php', '/f365-livestreaming-function.php' ) as $helper_file ) {
	if ( file_exists( $theme_dir . $helper_file ) ) {
		require_once $theme_dir . $helper_file;
		break;
	}
}

if ( file_exists( $tabs_file ) ) {
	require_once $tabs_file;
}

$stream = null;
if ( $livestream_id && function_exists( 'f365_sql_get_livestreaming_details' ) ) {
	$stream = f365_sql_get_livestreaming_details( $livestream_id );
}
?>

<div class="ink-grid vertical-space <?php echo esc_attr( $livestream_id ); ?>">
	<?php get_template_part( 'template-parts/template-offer-widget' ); ?>
	<div class="ink-header-f365 competicoes" style="background-image: linear-gradient(to left, rgba(250,250,250,0) 40%, rgba(0,0,0,1)), url(<?php echo esc_url( $bg_image_url ); ?>);">
		<div class="column-group">
			<div class="align-left all-60 medium-100 small-100 tiny-100 banner-left-content">
				<div class="headerdata">
					<h1 class="multimedia-title"><?php esc_html_e( 'Live Streaming', 'futebol365' ); ?></h1>
				</div>
			</div>
		</div>
	</div>

	<div class="nav-trigger" id="nav-trigger"><span><?php esc_html_e( 'Menu', 'futebol365' ); ?></span></div>
	<?php if ( function_exists( 'f365_live_streaming_tabs' ) ) : ?>
		<?php f365_live_streaming_tabs( 'homepage' ); ?>
	<?php endif; ?>
	<nav class="nav-mobile" id="nav-mobile"></nav>
</div>

<div class="ink-grid futebol365-container">
	<section class="left-column-block">

		<div class="breadcrumbs-f365">
			<?php if ( function_exists( 'yoast_breadcrumb' ) ) : ?>
				<nav class="ink-navigation breadcrumbs">
					<?php yoast_breadcrumb(); ?>
				</nav>
			<?php endif; ?>
		</div>

		<div class="f365-live-streaming f365-live-streaming--detail">
			<?php if ( $stream ) : ?>
			<?php
			$home_name = $stream->home_team_shortname ?: ( $stream->home_team_longname ?: __( 'Visitado', 'futebol365' ) );
			$away_name = $stream->visitor_team_shortname ?: ( $stream->visitor_team_longname ?: __( 'Visitante', 'futebol365' ) );

			// Team links resolve straight from the Django team id — no wp_posts
			// title lookup (f365_team_id_by_title) and no get_permalink().
			$home_team_url = home_url( '/equipa/' . (int) $stream->home_team_id . '/' );
			$away_team_url = home_url( '/equipa/' . (int) $stream->visitor_team_id . '/' );

			$competition_url = $stream->federation_competition_id
			? add_query_arg( 'edition', $stream->competition_edition, home_url( '/competicao/' . (int) $stream->federation_competition_id . '/' ) )
			: home_url( '/competicao/' . (int) $stream->competition_id . '/' );

			$when      = $stream->stream_datetime ?: $stream->match_datetime;
			$timestamp = $when ? strtotime( $when ) : false;
			$when_text = $timestamp ? wp_date( 'd-m-Y - H:i', $timestamp ) : '';

			$stream_title = sprintf(
			'%s: %s - %s%s',
			$stream->competition_name,
			$home_name,
			$away_name,
			$stream->competition_season ? ' (' . $stream->competition_season . ')' : ''
			);
			?>

			<?php if ( $stream->stream_url ) : ?>
				<div class="column-group gutters">
					<div class="all-100">
						<div class="videoDetail">
							<iframe class="iframeVideoDetail" src="<?php echo esc_url( $stream->stream_url ); ?>" allowfullscreen></iframe>
						</div>
					</div>
				</div>
			<?php else : ?>
				<p class="f365-no-stream"><?php esc_html_e( 'Transmissao indisponivel.', 'futebol365' ); ?></p>
			<?php endif; ?>

			<div class="subscribe bottom-space">
				<div class="g-ytsubscribe" data-channelid="UCdCCyF92C76R2ux_YQcOAFQ" data-layout="default" data-count="default"></div>
			</div>


			<h2 class="video-title"><?php echo esc_html( $stream_title ); ?></h2>

			<?php if ( $when_text ) : ?>
				<div class="fw-500 video-date-and-views"><?php echo esc_html( $when_text ); ?></div>
			<?php endif; ?>

			<div class="button-toolbar vertical-padding">
				<?php if ( $stream->competition_id && $stream->competition_name ) : ?>
					<div class="button-group">
						<a class="ink-button" href="<?php echo esc_url( $competition_url ); ?>">
							<?php echo esc_html( $stream->competition_name ); ?>
						</a>
					</div>
				<?php endif; ?>

				<div class="button-group">
					<a class="ink-button" href="<?php echo esc_url( $home_team_url ); ?>/jogos/"><?php echo esc_html( $home_name ); ?></a>
				</div>

				<div class="button-group">
					<a class="ink-button" href="<?php echo esc_url( $away_team_url ); ?>/jogos/"><?php echo esc_html( $away_name ); ?></a>
				</div>

			</div>

			<?php else : ?>
				<p class="f365-not-found"><?php esc_html_e( 'Transmissao nao encontrada.', 'futebol365' ); ?></p>
			<?php endif; ?>
		</div>

		<?php get_template_part( 'template-parts/template-front-page-posts' ); ?>
	</section>

	<section class="right-column-block">
		<?php
		if ( is_active_sidebar( 'post-sidebar' ) ) {
			dynamic_sidebar( 'post-sidebar' );
		} else {
			get_sidebar();
		}
		?>
	</section>
</div>

<script src="https://apis.google.com/js/platform.js"></script>

<?php get_footer(); ?>