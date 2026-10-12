<?php
/**
 * Template part for displaying a message that posts cannot be found
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package Futebol365
 */

?>

<section class="no-results not-found">
	<div class="page-content">
		<div class="column-group double-bottom-space search-form-block">
			<?php if ( is_search() ) : ?>
				No results found "<?php echo esc_html( get_search_query() ); ?>"
			<?php else : ?>
				No results found
			<?php endif; ?>
		</div>
	</div><!-- .page-content -->
</section><!-- .no-results -->
