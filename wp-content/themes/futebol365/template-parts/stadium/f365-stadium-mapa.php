<?php
/**
 * Stadium Map
 *
 * @package Futebol365
 */

$post_id = $args['post_id'];
$fields = get_fields(
	$post_id
);
?>
<section class="stadium-map">
	<h2 class="condensed-400 headerTitle">
		<?php esc_html_e(
			'Mapa',
			'f365'
		); ?>
		<span class="headerTitle-border"></span>
	</h2>

	<div class="content-box">
		<?php if (
			! empty( $fields['latitude'] ) &&
			! empty( $fields['longitude'] )
		) : ?>

			<iframe
				width="100%"
				height="450"
				frameborder="0"
				style="border:0"
				loading="lazy"
				src="https://maps.google.com/maps?q=<?php echo esc_attr( $fields['latitude'] ); ?>,<?php echo esc_attr( $fields['longitude'] ); ?>&z=15&output=embed">
			</iframe>

		<?php else : ?>

			<h5 class="no-info">
				<?php esc_html_e(
					'Sem informação disponível',
					'f365'
				); ?>
			</h5>
		<?php endif; ?>
	</div>
</section>
