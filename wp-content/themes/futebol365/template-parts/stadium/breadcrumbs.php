<?php
/**
 * Stadium Breadcrumbs
 *
 * @package Futebol365
 */

$post_id = $args['post_id'];

?>

<div class="breadcrumbs-f365">
    <div class="ink-navigation breadcrumbs">
        <?php yoast_breadcrumb(); ?>
    </div>
</div>
<div class="breadcrumbs-f365" style="display: none;">
	<nav class="ink-navigation">
		<ul class="breadcrumbs">
			<li>
				<a href="<?php echo esc_url(
					home_url( '/' )
				); ?>">
					Home
				</a>
			</li>
			<?php
			echo wp_kses_post(
				f365_get_stadium_breadcrumbs(
					$post_id
				)
			);
			?>
			<li class="active">
				<?php echo esc_html(
					get_the_title(
						$post_id
					)
				); ?>

			</li>
		</ul>
	</nav>
</div>
