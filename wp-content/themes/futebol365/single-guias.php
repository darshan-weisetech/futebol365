<?php
/**
 * Single Guides Template (guias CPT)
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<main id="primary" class="site-main single-article-page">
	<?php get_template_part('template-parts/template-offer-widget'); ?>
	<div class="ink-grid vertical-space">
		<div class="all-100 top-space page-article">
			<h1 class="article-title"><?php the_title(); ?></h1>
		</div>
	</div>
	<div class="ink-grid futebol365-container">
		<section class="left-column-block">
			<div class="breadcrumbs-f365">
				<nav class="ink-navigation">
					<?php if (function_exists('yoast_breadcrumb')): ?>
						<div class="ink-navigation breadcrumbs">
							<?php yoast_breadcrumb(); ?>
						</div>
					<?php endif; ?>
				</nav>
			</div>

			<div class="column-group gutters page-article">
				<?php
				while (have_posts()) :
					the_post();

					$post_id    = get_the_ID();
					$permalink  = get_permalink($post_id);
					$title      = get_the_title($post_id);
					$title_attr = esc_attr($title);
					$thumb_id   = get_post_thumbnail_id($post_id);

					$post_categories = get_the_category($post_id);

					if ($thumb_id) {
						$image_url = get_the_post_thumbnail_url($post_id, 'full');
					} else {
						$image_url = futebol365_fallback_image();
					}
				?>

					<div class="tiny-100 small-100 medium-100 large-25 xlarge-25">
						<div class="article-metadata">
							<div><?php echo esc_html(get_the_modified_date('d-m-Y · H:i')); ?></div>
							<div><?php the_author_posts_link(); ?></div>
							<?php foreach ($post_categories as $category): ?>
								<div><a href="<?php echo esc_url(get_category_link($category->term_id)); ?>"><?php echo esc_html($category->name); ?></a></div>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="tiny-100 small-100 medium-100 large-75 xlarge-75">
						<?php if (has_excerpt()): ?>
							<div class="antetitulo bottom-space"><?php the_excerpt(); ?></div>
						<?php endif; ?>

						<figure class="ink-image article-image">
							<img
								class="feature-image-post"
								src="<?php echo esc_url($image_url); ?>"
								onerror="this.onerror=null;this.src='<?php echo esc_url(FB_THEME_URL . '/assets/images/image-fallback.jpg'); ?>';"
								alt="<?php echo esc_attr($title_attr); ?>"
								title="<?php echo esc_attr($title_attr); ?>"
								loading="eager"
								fetchpriority="high"
								decoding="async">

							<?php
							if ($thumb_id) {
								$text = get_the_title($thumb_id) ?: get_the_post_thumbnail_caption();
							} else {
								$text = $title;
							}

							if (!empty($text)):
							?>
								<figcaption class="over-bottom dark condensed-400 medium uppercase"><?php echo esc_html($text); ?></figcaption>
							<?php endif; ?>
						</figure>

						<div class="artigo top-space"><?php the_content(); ?></div>

						<?php
						$share_url   = rawurlencode($permalink);
						$share_title = rawurlencode($title);
						?>
						<div class="a2a_kit a2a_kit_size_32 a2a_default_style top-space" style="line-height: 32px;">
							<a class="a2a_button_facebook" target="_blank" href="https://www.facebook.com/sharer/sharer.php?u=<?php echo esc_attr($share_url); ?>" rel="nofollow noopener"></a>
							<a class="a2a_button_x" target="_blank" href="https://twitter.com/intent/tweet?url=<?php echo esc_attr($share_url); ?>&text=<?php echo esc_attr($share_title); ?>" rel="nofollow noopener"></a>
							<a class="a2a_button_whatsapp" target="_blank" href="https://api.whatsapp.com/send?text=<?php echo esc_attr($share_title . '%20' . $share_url); ?>" rel="nofollow noopener"></a>
						</div>
					</div>

				<?php endwhile; ?>

				<div class="all-100">
					<?php
					// Prefooter: Display 8 last modified Guias CPTs in Relacionadas section.
					$related_posts = get_posts([
						'post_type'              => 'guias',
						'post_status'            => 'publish',
						'orderby'                => 'modified',
						'order'                  => 'DESC',
						'posts_per_page'         => 8,
						'post__not_in'           => [ $post_id ],
						'no_found_rows'          => true,
						'update_post_meta_cache' => true,
						'update_post_term_cache' => false,
					]);

					if (!empty($related_posts)) :
						$fallback_image = function_exists('futebol365_fallback_image') ? futebol365_fallback_image() : '';
					?>
						<h2 class="condensed-400 extralarge modtitle relative-title">
							<?php esc_html_e( 'Relacionadas', 'futebol365' ); ?>
							<span class="modtitle-border"></span>
						</h2>
						<div class="column-group gutters related-posts">
							<?php
							foreach ($related_posts as $related_post) :
								$r_permalink  = get_permalink($related_post);
								$r_title      = get_the_title($related_post);
								$r_title_attr = esc_attr($r_title);
								$r_date       = get_the_modified_date('d-m-Y · H:i', $related_post);

								if (has_post_thumbnail($related_post->ID)) {
									$r_image = get_the_post_thumbnail_url($related_post->ID, 'medium_large');
								} else {
									$r_image = $fallback_image;
								}
							?>
								<div class="all-25 small-50 tiny-100">
									<article class="related-post-item">
										<a href="<?php echo esc_url($r_permalink); ?>">
											<figure class="ink-image ink-lazyload half-bottom-space my-thumb-3-2" data-delta="300" data-loaded-class="loaded">
												<img class="lazyload-item my-thumb-image"
													 src="<?php echo esc_url($r_image); ?>"
													 loading="lazy"
													 decoding="async"
													 onerror="this.onerror=null;this.src='<?php echo esc_url(FB_THEME_URL . '/assets/images/image-fallback.jpg'); ?>';"
													 title="<?php echo $r_title_attr; ?>"
													 alt="<?php echo $r_title_attr; ?>">
											</figure>
										</a>
										<div class="quarter-vertical-space medium condensed-400">
											<?php echo esc_html($r_date); ?>
										</div>
										<h3 class="large condensed-700">
											<a href="<?php echo esc_url($r_permalink); ?>">
												<?php echo esc_html($r_title); ?>
											</a>
										</h3>
									</article>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>

			</div>
		</section>

		<section class="right-column-block">
			<?php get_sidebar(); ?>
		</section>
	</div>
</main>
<script async src="https://static.addtoany.com/menu/page.js"></script>
<?php
get_footer();
