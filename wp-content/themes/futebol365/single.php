<?php

/**
 * Single Post Template (news article)
 *
 * Match/competition relations come from the legacy publish_news row and the mirror
 * tables, resolved ONCE and cached in a transient keyed by post id + modified time.
 * The cached payload includes everything the "Programa da jornada" block needs
 * (news_match_id, news_competition_id, display_matchday) so it renders on cache hits
 * too. Related entities are batch-primed so per-item title/permalink calls hit cache.
 *
 * @package Futebol365
 */

$f365_current_post_id = (int) get_queried_object_id();
if ($f365_current_post_id && function_exists('_prime_post_caches')) {
	_prime_post_caches([$f365_current_post_id], true, true);
}

get_header();
?>

<main id="primary" class="site-main single-article-page">
	<div class="ink-grid vertical-space">
		<?php get_template_part('template-parts/template-offer-widget'); ?>
		<section class="news-most-view-in-category">
			<div class="hide-tiny hide-small hide-medium large-100 xlarge-100">
				<div class="column-group horizontal-gutters">

					<?php
					$top_news_ids = f365_get_cached_related_posts($f365_current_post_id, 4);

					if (!empty($top_news_ids)):

						$top_news = new WP_Query(
							[
								'post_type' => 'post',
								'post_status' => 'publish',
								'post__in' => $top_news_ids,
								'orderby' => 'post__in',
								'posts_per_page' => 4,
								'no_found_rows' => true,
								'update_post_meta_cache' => false,
								'update_post_term_cache' => false,
							]
						);

						while ($top_news->have_posts()):
							$top_news->the_post();
							$t_title = get_the_title();
							$t_permalink = get_permalink();
							global $post;
							$legacy_news_id = (int) ($post->legacy_id ?? 0);

							$image_url = '';

							if (has_post_thumbnail()) {
								$image_url = get_the_post_thumbnail_url(get_the_ID(), 'medium_large');
							} elseif ($legacy_news_id > 0 && function_exists('f365_module_image_url')) {
								$image_url = f365_module_image_url('news', $legacy_news_id);
							}

							$image_url = $image_url ?: futebol365_fallback_image();

							?>

							<div class="all-25">
								<figure class="ink-image ink-lazyload half-bottom-space my-thumb-4-2" data-delta="300"
									data-loaded-class="loaded">
									<a class="top-news-card" href="<?php echo esc_url($t_permalink); ?>">
										<img class="lazyload-item my-thumb-image loaded"
											src="<?php echo esc_url($image_url); ?>"
											onerror="this.onerror=null;this.src='<?php echo esc_url(FB_THEME_URL . '/assets/images/image-fallback.jpg'); ?>';"
											alt="<?php echo esc_attr($t_title); ?>" title="<?php echo esc_attr($t_title); ?>"
											loading="lazy" decoding="async">
									</a>
									<figcaption class="over-bottom smooth figcaption-padding-news">
										<div class="title medium condensed-700">
											<a href="<?php echo esc_url($t_permalink); ?>"><?php echo esc_html($t_title); ?></a>
										</div>
									</figcaption>
								</figure>
							</div>

							<?php
						endwhile;
						wp_reset_postdata();
					endif;
					?>

				</div>
			</div>
		</section>

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
				while (have_posts()):
					the_post();

					global $wpdb, $post;

					$post_id = $f365_current_post_id ?: get_the_ID();
					$permalink = get_permalink($post_id);
					$title = get_the_title($post_id);
					$title_attr = esc_attr($title);

					$thumb_id = get_post_thumbnail_id($post_id);

					$post_terms = wp_get_object_terms(
						$post_id,
						['category', 'post_tag']
					);

					$legacy_news_id = (int) ($post->legacy_id ?? 0);

					$post_categories = [];
					$has_mercado_tag = false;

					if (!is_wp_error($post_terms)) {
						foreach ($post_terms as $term) {
							if ('category' === $term->taxonomy) {
								$post_categories[] = $term;
							} elseif (
								'post_tag' === $term->taxonomy &&
								'mercado' === $term->slug
							) {
								$has_mercado_tag = true;
							}
						}
					}

					$image_url = '';

					if ($thumb_id) {
						$image_url = get_the_post_thumbnail_url(
							$post_id,
							'full'
						);
					} elseif (
						$legacy_news_id > 0 &&
						function_exists('f365_module_image_url')
					) {
						$image_url = f365_module_image_url(
							'news',
							$legacy_news_id
						);
					}

					$image_url = $image_url ?: futebol365_fallback_image();

					$topics = [];
					$competition_url = '';
					$news_match_postid = 0;
					$news_match_id = 0;
					$news_competition_id = 0;
					$display_matchday = '';

					$cache_key = 'f365_news_topics_' .
						$post_id .
						'_' .
						(int) get_post_modified_time(
							'U',
							true,
							$post_id
						);

					// Changed from get_transient to wp_cache_get using a custom group 'f365'
					$cached = wp_cache_get($cache_key, 'f365_single');

					if (false !== $cached) {

						$topics = $cached['topics'] ?? [];
						$competition_url = $cached['competition_url'] ?? '';
						$news_match_postid = (int) ($cached['match_postid'] ?? 0);
						$news_match_id = (int) ($cached['news_match_id'] ?? 0);
						$news_competition_id = (int) ($cached['news_competition_id'] ?? 0);
						$display_matchday = (string) ($cached['display_matchday'] ?? '');
					} else {
						$news_competition = null;
						$news_match_postid = 0;
						$news_generic_link = null;

						$generic_link_title = '';
						$generic_link_url = '';

						$legacy_news = $wpdb->get_row(
							$wpdb->prepare(
								"
                                SELECT
                                    n.competition_id,
                                    n.match_id,
                                    n.display_matchday,
                                    n.generic_link_title,
                                    n.generic_link_url
                                FROM {$wpdb->posts} p
                                INNER JOIN {$wpdb->prefix}publish_news n
                                    ON n.id = p.legacy_id
                                WHERE p.ID = %d
                                LIMIT 1
                                ",
								$post_id
							),
							ARRAY_A
						);

						if ($legacy_news) {
							$display_matchday = (string) ($legacy_news['display_matchday'] ?? '');
							$news_competition_id = (int) ($legacy_news['competition_id'] ?? 0);
							$news_match_id = (int) ($legacy_news['match_id'] ?? 0);
							$generic_link_title = (string) ($legacy_news['generic_link_title'] ?? '');
							$generic_link_url = (string) ($legacy_news['generic_link_url'] ?? '');
						}

						if ($news_competition_id || $news_match_id) {
							$news_context = $wpdb->get_row(
								$wpdb->prepare(
									"SELECT
                                    c.id,
                                    c.name,
                                    c.edition,
                                    c.federation_competition_id,
                                    mp.ID AS match_postid
                                FROM {$wpdb->prefix}competition_competition c
                                LEFT JOIN {$wpdb->posts} mp
                                    ON mp.legacy_table = 'wp_competition_match'
                                    AND mp.legacy_id = %d
                                    AND mp.post_type = 'f365_match'
                                    AND mp.post_status = 'publish'
                                WHERE c.id = %d
                                LIMIT 1",
									$news_match_id,
									$news_competition_id
								),
								ARRAY_A
							);

							if ($news_context) {
								if (!empty($news_context['id'])) {
									$news_competition = $news_context;
								}
								$news_match_postid = (int) ($news_context['match_postid'] ?? 0);
							}
						}

						if ('' !== $generic_link_title && '' !== $generic_link_url) {
							$news_generic_link = ['title' => $generic_link_title, 'url' => home_url($generic_link_url)];
						}

						/* Competition page URL — /competicao/{federation_competition_id}/?edition= */
						$competition_url = '';
						if ($news_competition && !empty($news_competition['federation_competition_id'])) {
							$competition_url = home_url(
								sprintf('/competicao/%d/?edition=%s', (int) $news_competition['federation_competition_id'], rawurlencode((string) $news_competition['edition']))
							);
						}

						/* Relations (WP posts) — prime once so title/permalink hit cache. */
						$relations = futebol365_get_news_relations($post_id);
						$rel_ids = array_map(
							'intval',
							array_merge($relations['teams'] ?? [], $relations['players'] ?? [], $relations['coaches'] ?? [], $relations['presidents'] ?? [], $relations['videos'] ?? [])
						);
						if ($rel_ids && function_exists('_prime_post_caches')) {
							_prime_post_caches(array_values(array_unique($rel_ids)), true, true);
						}

						$topics = [];
						if ($competition_url) {
							$topics[] = ['title' => $news_competition['name'], 'url' => $competition_url, 'target' => '_top'];
						}
						if ($news_match_postid) {
							$topics[] = ['title' => __('Aposte neste jogo', 'futebol365'), 'url' => home_url('/goto/'), 'target' => '_blank'];
							$topics[] = ['title' => __('Frente-a-frente', 'futebol365'), 'url' => get_permalink($news_match_postid), 'target' => '_top'];
						}
						if ($news_generic_link) {
							$topics[] = ['title' => $news_generic_link['title'], 'url' => $news_generic_link['url'], 'target' => '_top'];
						}
						foreach (array_merge($relations['teams'] ?? [], $relations['players'] ?? [], $relations['coaches'] ?? [], $relations['presidents'] ?? []) as $id) {
							$topics[] = ['title' => get_the_title($id), 'url' => get_permalink($id), 'target' => '_top'];
						}
						if (!empty($relations['videos'])) {
							$topics[] = ['title' => __('Ver vídeo', 'futebol365'), 'url' => get_permalink(reset($relations['videos'])), 'target' => '_top'];
						}

						/* De-duplicate (preserve order). */
						$unique = [];
						foreach ($topics as $topic) {
							$unique[md5($topic['title'] . $topic['url'])] = $topic;
						}
						$topics = array_values($unique);

						// Changed from set_transient to wp_cache_set with DAY_IN_SECONDS expiration (24 hours)
						wp_cache_set(
							$cache_key,
							array(
								'topics' => $topics,
								'competition_url' => $competition_url,
								'match_postid' => $news_match_postid,
								'news_match_id' => $news_match_id,
								'news_competition_id' => $news_competition_id,
								'display_matchday' => $display_matchday,
							),
							'f365_single',
							DAY_IN_SECONDS
						);
					}
					?>

					<div class="tiny-100 small-100 medium-100 large-25 xlarge-25">
						<div class="article-metadata">
							<div><?php echo esc_html(get_the_date('d-m-Y · H:i')); ?></div>
							<div><?php the_author_posts_link(); ?></div>
							<?php foreach ($post_categories as $category): ?>
								<div><a
										href="<?php echo esc_url(get_category_link($category->term_id)); ?>"><?php echo esc_html($category->name); ?></a>
								</div>
							<?php endforeach; ?>
						</div>

						<?php if (!empty($topics)): ?>
							<div class="vertical-padding article-metadata hide-tiny hide-small hide-medium">
								<h2
									class="condensed-400 extralarge modtitle quarter-bottom-space hide-tiny hide-small hide-medium">
									<?php esc_html_e('Tópicos', 'futebol365'); ?>
									<span class="modtitle-border"></span>
								</h2>
								<div class="hide-xlarge hide-large"><b><?php esc_html_e('TÓPICOS', 'futebol365'); ?></b></div>
								<?php foreach ($topics as $topic): ?>
									<div>
										<a href="<?php echo esc_url($topic['url']); ?>"
											target="<?php echo esc_attr($topic['target']); ?>"><?php echo esc_html($topic['title']); ?></a>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>

					<div class="tiny-100 small-100 medium-100 large-75 xlarge-75">
						<?php if (has_excerpt()): ?>
							<div class="antetitulo bottom-space"><?php the_excerpt(); ?></div>
						<?php endif; ?>

						<figure class="ink-image article-image">

							<img class="feature-image-post" src="<?php echo esc_url($image_url); ?>"
								onerror="this.onerror=null;this.src='<?php echo esc_url(FB_THEME_URL . '/assets/images/image-fallback.jpg'); ?>';"
								alt="<?php echo esc_attr($title_attr); ?>" title="<?php echo esc_attr($title_attr); ?>"
								loading="eager" fetchpriority="high" decoding="async">


							<?php
							if ($thumb_id) {
								$text = get_the_title($thumb_id) ?: get_the_post_thumbnail_caption();
							} else {
								$text = $title;
							}

							if (!empty($text)):
								?>
								<figcaption class="over-bottom dark condensed-400 medium uppercase">
									<?php echo esc_html($text); ?>
								</figcaption>
								<?php
							endif;
							?>
						</figure>

						<div class="artigo top-space"><?php the_content(); ?></div>

						<?php
						$round_matches = [];

						if (
							't' === $display_matchday &&
							$news_competition_id > 0 &&
							$news_match_id > 0
						) {
							$match_info = $wpdb->get_row(
								$wpdb->prepare(
									"
                                    SELECT competition_id, match_round
                                    FROM {$wpdb->prefix}competition_match
                                    WHERE id = %d
                                    LIMIT 1
                                    ",
									$news_match_id
								)
							);

							if ($match_info) {

								$round_cache_key =
									'f365_round_matches_' .
									(int) $match_info->competition_id .
									'_' .
									(int) $match_info->match_round;

								$cached_round = wp_cache_get(
									$round_cache_key,
									'f365'
								);

								if (false !== $cached_round) {

									$round_matches = $cached_round;

								} else {

									$round_matches = $wpdb->get_results(
										$wpdb->prepare(
											"
                                            SELECT
                                                id,
                                                date_time,
                                                home_team_display_name,
                                                visitor_team_display_name,
                                                home_score,
                                                visitor_score,
                                                situation
                                            FROM {$wpdb->prefix}competition_match
                                            WHERE competition_id = %d
                                                AND match_round = %d
                                            ORDER BY date_time ASC
                                            ",
											$match_info->competition_id,
											$match_info->match_round
										)
									);
									wp_cache_set(
										$round_cache_key,
										$round_matches,
										'f365',
										12 * HOUR_IN_SECONDS
									);
								}
							}
						}

						$match_posts = [];

						if (!empty($round_matches)) {

							$match_ids = wp_list_pluck(
								$round_matches,
								'id'
							);

							$match_ids = array_map(
								'intval',
								$match_ids
							);

							if (!empty($match_ids)) {

								$rows = $wpdb->get_results(
									"
                                    SELECT ID, legacy_id
                                    FROM {$wpdb->posts}
                                    WHERE post_type = 'f365_match'
                                        AND post_status = 'publish'
                                        AND legacy_table = 'wp_competition_match'
                                        AND legacy_id IN (" . implode(',', $match_ids) . ")
                                    "
								);

								foreach ($rows as $row) {
									$match_posts[
										(int) $row->legacy_id
									] = (int) $row->ID;
								}

								if (
									$match_posts &&
									function_exists('_prime_post_caches')
								) {
									_prime_post_caches(
										array_values($match_posts),
										true,
										false
									);
								}
							}
						}
						?>

						<div class="conteudo">
							<?php if (
								't' === $display_matchday &&
								$news_competition_id > 0 &&
								$news_match_id > 0 &&
								!empty($round_matches)
							): ?>
								<p></p>
								<b class="large">
									<?php esc_html_e('Programa da jornada', 'futebol365'); ?>
								</b>

								<?php
								$last_date = '';

								foreach ($round_matches as $match):

									$current_date = wp_date(
										'l, j \d\e F \d\e Y',
										strtotime($match->date_time)
									);

									if ($last_date !== $current_date):
										$last_date = $current_date;
										?>
										<div>
											<br>
											<b><?php echo esc_html($current_date); ?></b>
										</div>
									<?php endif; ?>

									<div>

										<?php
										echo esc_html(
											$match->home_team_display_name .
											' - ' .
											$match->visitor_team_display_name
										);
										?>

										<?php
										if (
											!empty($match->home_score) ||
											!empty($match->visitor_score) ||
											'finished' === $match->situation
										):

											$match_post_id = $match_posts[(int) $match->id] ?? 0;

											if ($match_post_id):
												?>

												, <a href="<?php echo esc_url(get_permalink($match_post_id)); ?>">
													<?php
													echo esc_html(
														(int) $match->home_score .
														' - ' .
														(int) $match->visitor_score
													);
													?>
												</a>

												<?php
											else:
												?>

												, <?php echo (int) $match->home_score; ?> - <?php echo (int) $match->visitor_score; ?>

												<?php
											endif;

										else:
											?>

											, <?php echo esc_html(wp_date('H:i', strtotime($match->date_time))); ?>

										<?php endif; ?>

									</div>

								<?php endforeach; ?>

								<br>

							<?php endif; ?>

							<?php if ($competition_url): ?>
								<p>
									<?php esc_html_e('Confira', 'f365'); ?>
									<a
										href="<?php echo esc_url($competition_url); ?>"><b><?php esc_html_e('aqui', 'f365'); ?></b></a>
									<?php esc_html_e('tudo sobre a competição.', 'f365'); ?>
								</p>
							<?php endif; ?>

							<?php if ($has_mercado_tag): ?>
								<p>
									<?php esc_html_e('Acompanhe todas as movimentações do', 'f365'); ?>
									<a
										href="<?php echo esc_url(home_url('/transferencias/')); ?>"><b><?php esc_html_e('Mercado de transferências', 'f365'); ?></b></a>.
								</p>
							<?php endif; ?>

							<p class="top-space">
								<?php esc_html_e('Siga-nos no', 'f365'); ?>
								<a href="https://www.facebook.com/futebol365" target="_blank"
									rel="nofollow noopener"><b><?php esc_html_e('Facebook', 'f365'); ?></b></a>,
								<?php esc_html_e('no', 'f365'); ?>
								<a href="https://x.com/futebol365" target="_blank"
									rel="nofollow noopener"><b><?php esc_html_e('X', 'f365'); ?></b></a>,
								<?php esc_html_e('no', 'f365'); ?>
								<a href="https://www.instagram.com/futebol365.pt/" target="_blank"
									rel="nofollow noopener"><b><?php esc_html_e('Instagram', 'f365'); ?></b></a>,
								<?php esc_html_e('no', 'f365'); ?>
								<a href="https://whatsapp.com/channel/0029VaT9WymA89MYl3wUT71t" target="_blank"
									rel="nofollow noopener"><b><?php esc_html_e('Whatsapp', 'f365'); ?></b></a>
								<?php esc_html_e('e no', 'f365'); ?>
								<a href="https://www.youtube.com/c/futebol365-oficial?sub_confirmation=1" target="_blank"
									rel="nofollow noopener"><b><?php esc_html_e('Youtube', 'f365'); ?></b></a>.
							</p>
						</div>

						<?php if (!empty($topics)): ?>
							<div class="button-toolbar vertical-space">
								<?php foreach ($topics as $topic): ?>
									<div class="button-group">
										<a class="ink-button"
											href="<?php echo esc_url($topic['url']); ?>"><?php echo esc_html($topic['title']); ?></a>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

						<?php $share_url = rawurlencode($permalink);
						$share_title = rawurlencode($title); ?>
						<div class="a2a_kit a2a_kit_size_32 a2a_default_style top-space" style="line-height: 32px;">
							<a class="a2a_button_facebook" target="_blank"
								href="https://www.facebook.com/sharer/sharer.php?u=<?php echo esc_attr($share_url); ?>"
								rel="nofollow noopener"></a>
							<a class="a2a_button_x" target="_blank"
								href="https://twitter.com/intent/tweet?url=<?php echo esc_attr($share_url); ?>&text=<?php echo esc_attr($share_title); ?>"
								rel="nofollow noopener"></a>
							<a class="a2a_button_whatsapp" target="_blank"
								href="https://api.whatsapp.com/send?text=<?php echo esc_attr($share_title . '%20' . $share_url); ?>"
								rel="nofollow noopener"></a>
						</div>

					</div>
				<?php endwhile; ?>

				<?php get_template_part('template-parts/template-related-posts'); ?>
				<?php get_template_part('template-parts/template-front-page-posts'); ?>
		</section>

		<section class="right-column-block">
			<?php get_sidebar(); ?>
		</section>
	</div>
</main>
<script async src="https://static.addtoany.com/menu/page.js"></script>
<?php
get_footer();
