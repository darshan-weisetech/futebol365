<?php
/**
 * Stadium Summary
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

$post_id = $args['post_id'];
?>
<section class="stadium-summary">
	<?php
	get_template_part(
		'template-parts/stadium/f365-stadium-ultimos-jogos',
		null,
		[
			'post_id'         => $post_id,
			'posts_per_page'  => 10,
			'hide_pagination' => true,
		]
	);
	?>

	<?php
	get_template_part(
		'template-parts/stadium/f365-stadium-proximos-jogos',
		null,
		[
			'post_id'         => $post_id,
			'posts_per_page'  => 10,
			'hide_pagination' => true,
		]
	);
	?>
</section>