<?php
/**
 * Live Streaming — by status  /live-streaming/{inlive|tostart|finished}/
 *
 * Mirrors Django live.views.live_streaming_by_status → 'live/live_streaming_by_status.html':
 * a single paginated table for the requested status. The status window + ordering
 * are applied to the MAIN query in live-streaming-rewrite.php::pre_get_posts(),
 * so pagination works natively (the Django view used PaginatorHandler, 30/page).
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;
$post_id         = $args['post_id'] ?? get_the_ID();
$current_section = $args['current_section'] ?? 'details';
$bg_field      = get_field( 'federation_header_bg_image', 'option' );
$bg_image_url  = $bg_field['url'] ?? futebol365_fallback_image();

get_header();

//require_once trailingslashit( F365_PLUGIN_DIR ) . 'templates/live-streaming-template/f365-live-streaming-tabs.php';
$file = get_stylesheet_directory() . '/template-parts/livestreaming/f365-live-streaming-tabs.php';

if ( file_exists( $file ) ) {
    require_once $file;
}

$status = (string) get_query_var( 'f365_livestream_status' );
$titles = [ 'inlive' => 'Em direto', 'tostart' => 'Brevemente', 'finished' => 'Mais recentes' ];
$title  = $titles[ $status ] ?? 'Live Streaming';

global $wp_query;
?>
<div class="ink-grid vertical-space <?php echo esc_attr( $post_id ); ?>">
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

	<div class="nav-trigger" id="nav-trigger"><span><?php esc_html_e('Menu', 'futebol365'); ?></span></div>
		<?php f365_live_streaming_tabs( 'homepage' ); ?>
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

		<h3 class="condensed-400 headerTitle orange">
			<?php echo esc_html( $title ); ?>
		</h3>

		<?php if ( $wp_query->have_posts() ) : ?>

			<?php f365_live_streaming_table( $wp_query ); ?>

			<?php
			$big = 999999999;

			$links = paginate_links( [
				'base'      => str_replace(
					$big,
					'%#%',
					esc_url( get_pagenum_link( $big ) )
				),
				'format'    => 'page/%#%/',
				'current'   => max( 1, get_query_var( 'paged' ) ),
				'total'     => $wp_query->max_num_pages,
				'prev_text' => '« Anterior',
				'next_text' => 'Seguinte »',
			] );

			if ( $links ) :
			?>
				<div class="paginacao-new">
					<nav class="ink-navigation">
						<ul class="pagination">
							<li><?php echo wp_kses_post( $links ); ?></li>
						</ul>
					</nav>
				</div>
			<?php endif; ?>

		<?php else : ?>

			<h6 class="no-info"><?php esc_html_e('Sem informação disponível', 'futebol365'); ?></h6>

		<?php endif; ?>

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

<?php get_footer(); ?>
