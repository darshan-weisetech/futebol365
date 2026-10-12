<?php
/**
 * Multimedia Tabs
 *
 * @package Futebol365
 */

$is_gallery = is_singular( 'f365_gallery' ) || is_post_type_archive( 'f365_gallery' );

$archive_link = $is_gallery
	? home_url( '/multimedia/fotogalerias/' )
	: home_url( '/multimedia/videos/' );

$current_filter = get_query_var( 'f365_section' ) ?: 'mais-recentes';

$latest_label  = $is_gallery ? 'Últimas' : 'Mais Recentes';
$popular_slug  = $is_gallery ? 'mais-vistas' : 'mais-vistos';
$popular_label = $is_gallery ? 'Mais Vistas' : 'Mais Vistos';

$recent_url = $is_gallery
	? trailingslashit( $archive_link )
	: trailingslashit( $archive_link ) . 'mais-recentes/';

$featured_url = trailingslashit( $archive_link ) . 'destaques/';
$popular_url  = trailingslashit( $archive_link ) . $popular_slug . '/';

$is_latest   = 'mais-recentes' === $current_filter;
$is_featured = 'destaques' === $current_filter;
$is_popular  = ( $popular_slug === $current_filter );
?>

<div
	class="nav-trigger"
	id="nav-trigger"
>
	<span>Menu</span>
</div>

<nav
	class="nav-main"
	id="nav-main"
>
	<ul class="multimedia-tabs">
		<li>
			<a
				class="<?php echo $is_latest ? 'active' : ''; ?>"
				href="<?php echo esc_url( $recent_url ); ?>"
			>
				<?php echo esc_html( $latest_label ); ?>
			</a>

		</li>
		<li>
			<a
				class="<?php echo $is_featured ? 'active' : ''; ?>"
				href="<?php echo esc_url( $featured_url ); ?>"
			>
			<?php esc_html_e( 'Em Destaque', 'futebol365' ); ?>
			</a>
		</li>
		<li>

			<a
				class="<?php echo $is_popular ? 'active' : ''; ?>"
				href="<?php echo esc_url( $popular_url ); ?>"
			>
				<?php echo esc_html( $popular_label ); ?>
			</a>
		</li>
	</ul>
</nav>
<nav
	class="nav-mobile"
	id="nav-mobile"
></nav>