<?php
defined('ABSPATH') || exit;

$static_total_pages  = 5;
$static_current_page = 2;
$static_start        = 1;
$static_end          = 4;

?>
<div class="paginacao-new">
	<nav class="ink-navigation">
		<ul class="pagination">
			<?php if ($static_current_page > 1) : ?>
				<li class="hide-tiny hide-small">
					<a href="#">&laquo; <?php esc_html_e('Anterior', 'f365'); ?></a>
				</li>
			<?php endif; ?>

			<?php for ($i = $static_start; $i <= $static_end; $i++) : ?>
				<li>
					<?php if ($i === $static_current_page) : ?>
						<span class="active"><?php echo esc_html($i); ?></span>
					<?php else : ?>
						<a href="#"><?php echo esc_html($i); ?></a>
					<?php endif; ?>
				</li>
			<?php endfor; ?>

			<?php if ($static_end < $static_total_pages) : ?>
				<li><span>...</span></li>
				<li><a href="#"><?php echo esc_html(number_format_i18n($static_total_pages)); ?></a></li>
			<?php endif; ?>

			<?php if ($static_current_page < $static_total_pages) : ?>
				<li class="hide-tiny hide-small">
					<a href="#"><?php esc_html_e('Seguinte', 'f365'); ?> &raquo;</a>
				</li>
			<?php endif; ?>
		</ul>
	</nav>
</div>

<?php
/*
 * Dynamic code temporarily disabled while static stadium template data is used.
 * Original implementation is preserved below as commented source.
 */
// <?php
// defined('ABSPATH') || exit;
// 
// 
// if (! empty($args['hide'])) {
// 	return;
// }
// if (empty($args['query'])) {
// 	return;
// }
// 
// $query = $args['query'];
// 
// $total_pages = (int) $query->max_num_pages;
// 
// if ($total_pages <= 1) {
// 	return;
// }
// 
// $current_page = max(
// 	1,
// 	(int) ($_GET['pagina'] ?? 1)
// );
// 
// $start = max(
// 	1,
// 	$current_page - 2
// );
// 
// $end = min(
// 	$total_pages,
// 	$current_page + 2
// );
// get_template_part(
// 	'template-parts/stadium/pagination',
// 	null,
// 	[
// 		'query' => $query,
// 		'hide'  => true,
// 	]
// );
// ? >
// 
// 
// <div class="paginacao-new">
// 	<nav class="ink-navigation">
// 		<ul class="pagination">
// 			<?php if ($current_page > 1) : ? >
// 				<li class="hide-tiny hide-small">
// 					<a href="<?php echo esc_url(add_query_arg('pagina', $current_page - 1)); ? >">
// 						&laquo; <?php esc_html_e('Anterior', 'f365'); ? >
// 					</a>
// 				</li>
// 			<?php endif; ? >
// 
// 			<?php if ($start > 1) : ? >
// 				<li>
// 					<a href="<?php echo esc_url(add_query_arg('pagina', 1)); ? >">
// 
// 						1
// 
// 					</a>
// 
// 				</li>
// 				<?php if ($start > 2) : ? >
// 					<li>
// 						<span>...</span>
// 					</li>
// 				<?php endif; ? >
// 			<?php endif; ? >
// 
// 			<?php for ($i = $start; $i <= $end; $i++) : ? >
// 				<li>
// 					<?php if ($i === $current_page) : ? >
// 						<span class="active">
// 							<?php echo esc_html($i); ? >
// 						</span>
// 
// 					<?php else : ? >
// 						<a href="<?php echo esc_url(add_query_arg('pagina', $i)); ? >">
// 							<?php echo esc_html($i); ? >
// 						</a>
// 					<?php endif; ? >
// 				</li>
// 
// 			<?php endfor; ? >
// 
// 			<?php if ($end < $total_pages) : ? >
// 				<?php if ($end < ($total_pages - 1)) : ? >
// 					<li>
// 						<span>...</span>
// 					</li>
// 
// 				<?php endif; ? >
// 
// 				<li>
// 					<a href="<?php echo esc_url(add_query_arg('pagina', $total_pages)); ? >">
// 						<?php echo esc_html(number_format_i18n($total_pages)); ? >
// 					</a>
// 				</li>
// 
// 			<?php endif; ? >
// 
// 			<?php if ($current_page < $total_pages) : ? >
// 
// 				<li class="hide-tiny hide-small">
// 					<a href="<?php echo esc_url(add_query_arg('pagina', $current_page + 1)); ? >">
// 						<?php esc_html_e('Seguinte', 'f365'); ? > &raquo;
// 					</a>
// 				</li>
// 
// 			<?php endif; ? >
// 		</ul>
// 	</nav>
// </div>
