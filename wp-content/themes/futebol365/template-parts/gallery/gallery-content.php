<?php

/**
 * Gallery Content
 *
 * @package Futebol365
 *
 * Entity relations (teams/players/coaches/presidents/referees):
 *   1) ACF relationship field (stores WP post ids) when its selection is a SANE
 *      size — a human picks a handful; the import bulk-filled these with the whole
 *      entity list (e.g. 715 teams), so oversized selections are ignored.
 *   2) Otherwise the migration's django_*_ids (mapped to WP posts via `django_id`).
 * Competition & Match are single ACF post_object fields, gated on the django_* id
 * flag (those post_object fields were bulk-filled with a default during import).
 */

$fields = get_fields() ?: [];

/* Carousel photos stay on the ACF gallery field (that selection is correct). */
$gallery_images = $fields['gallery_photos'] ?? [];
if (empty($gallery_images) || ! is_array($gallery_images)) {
	$gallery_images = [];
}

$views = function_exists('futebol365_get_post_views') ? futebol365_get_post_views() : 0;

?>
<div class="photogallery-panel">
	<div id="main_carousel" class="ink-carousel">
		<ul class="stage">
			<?php if (! empty($gallery_images)) : ?>

				<?php foreach ($gallery_images as $index => $image_id) : ?>

					<?php
					$image_large = wp_get_attachment_image_url(
						$image_id,
						'large'
					);

					$image_alt = get_post_meta(
						$image_id,
						'_wp_attachment_image_alt',
						true
					);

					$image_caption = get_the_title(
						$image_id
					);

					if (!$image_large) {
						$image_large = futebol365_fallback_image();
					}
					?>

					<li class="slide all-100">
						<figure class="ink-image">
							<img
								src="<?php echo esc_url($image_large); ?>"
								alt="<?php echo esc_attr($image_alt ?: get_the_title()); ?>"
								loading="<?php echo (0 === $index) ? 'eager' : 'lazy'; ?>">
							<?php if (! empty($image_caption)) : ?>

								<figcaption class="over-bottom dark fw-400 medium uppercase">
									<?php echo esc_html($image_caption); ?>
								</figcaption>
							<?php endif; ?>
						</figure>
					</li>
				<?php endforeach; ?>

			<?php else : ?>

				<li class="slide all-100">
					<figure class="ink-image">
						<img
							src="<?php echo esc_url(FB_THEME_URL . '/assets/images/no-image-list.jpg' ); ?>"
							alt="<?php echo esc_attr(get_the_title()); ?>"
							loading="eager" style="display:none;" >
					</figure>
				</li>
			<?php endif; ?>
		</ul>
		<nav
			id="pagination_main"
			class="ink-navigation top-space">

			<ul class="pagination chevron">

				<li class="previous">
					<a href="#">
						<span>Previous</span>
					</a>
				</li>

				<?php foreach ($gallery_images as $index => $image_id) : ?>
					<li class="<?php echo (0 === $index) ? 'active' : ''; ?>">
						<a
							href="#"
							data-index="<?php echo esc_attr($index); ?>">
							<?php echo esc_html($index + 1); ?>
						</a>
					</li>
				<?php endforeach; ?>

				<li class="next">
					<a href="#">
						<span>Next</span>
					</a>
				</li>
			</ul>
		</nav>
	</div>

	<?php if (! empty($gallery_images)) : ?>

		<div id="thumbs_carousel" class="ink-carousel">
			<ul class="stage">
				<?php foreach ($gallery_images as $index => $image_id) : ?>
					<?php
					$image_thumb = wp_get_attachment_image_url(
						$image_id,
						'thumbnail'
					);

					$image_alt = get_post_meta(
						$image_id,
						'_wp_attachment_image_alt',
						true
					);

					if (! $image_thumb) {
						$image_thumb = futebol365_fallback_image();
					}
					?>

					<li class="slide all-20 <?php echo (0 === $index) ? 'active' : ''; ?>">
						<a href="#">
							<img
								src="<?php echo esc_url($image_thumb); ?>"
								alt="<?php echo esc_attr($image_alt ?: get_the_title()); ?>"
								loading="lazy">
						</a>
					</li>
				<?php endforeach; ?>
			</ul>

			<nav id="pagination_thumbs" class="ink-navigation top-space">
				<ul class="pagination dotted">
					<?php
					$thumb_pages = max(
						1,
						(int) ceil(count($gallery_images) / 5)
					);

					for ($i = 0; $i < $thumb_pages; $i++) :
					?>
						<li class="<?php echo 0 === $i ? 'active' : ''; ?>">
							<a href="#">
								<?php echo esc_html($i + 1); ?>
							</a>
						</li>
					<?php endfor; ?>
				</ul>
			</nav>
		</div>

	<?php endif; ?>
</div>
<div class="column-group gutters top-space">
	<div class="all-100">
		<h2 class="video-title">
			<?php the_title(); ?>
		</h2>
		<h5 class="fw-500 video-date-and-views">
			<?php echo esc_html(get_the_date('d-m-Y')); ?>
			-
			<?php
			echo esc_html(
				number_format_i18n($views)
			);
			?>
			visualizações
		</h5>
		<?php
		if (!function_exists('f365_pg_id_from_url')) {
			function f365_pg_id_from_url()
			{
				$uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';

				if (preg_match('#/multimedia/fotogalerias/(\d+)/?#', $uri, $m)) {
					return absint($m[1]);
				}

				if (isset($_GET['gallery_id'])) {
					return absint($_GET['gallery_id']);
				}

				return absint(get_the_ID());
			}
		}

		if (!function_exists('f365_pg_gallery_url')) {
			function f365_pg_gallery_url($id)
			{
				return home_url('/multimedia/fotogalerias/' . absint($id) . '/');
			}
		}
		if (!function_exists('f365_pg_get_related_links')) {
			function f365_pg_get_related_links($gallery)
			{
				global $wpdb;

				$links = array();

				if (!empty($gallery->competition_id)) {
					$competition = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT id, name, edition, federation_competition_id
                                    FROM {$wpdb->prefix}competition_competition
                                    WHERE id = %d",
							$gallery->competition_id
						)
					);

					if ($competition) {
						$links[] = array(
							'title' => $competition->name,
							'url'   => add_query_arg(
								'edition',
								$competition->edition,
								home_url('/competicao/' . absint($competition->federation_competition_id) . '/')
							),
						);
					}
				}

				if (!empty($gallery->match_id)) {
					$has_players = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*)
                                    FROM {$wpdb->prefix}competition_matchplayer
                                    WHERE match_id = %d",
							$gallery->match_id
						)
					);

					if ($has_players > 0) {
						$links[] = array(
							'title' => 'Ficha do jogo',
							'url'   => home_url('/jogo/' . absint($gallery->match_id) . '/'),
						);
					}
				}

				$team_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT t.id, t.shortname, t.longname
                                FROM {$wpdb->prefix}multimedia_photogallery_teams rel
                                INNER JOIN {$wpdb->prefix}team_team t ON t.id = rel.team_id
                                WHERE rel.photogallery_id = %d
                                ORDER BY t.shortname",
						$gallery->id
					)
				);

				foreach ($team_rows as $team) {
					$links[] = array(
						'title' => $team->shortname ?: $team->longname,
						'url'   => home_url('/equipa/' . absint($team->id) . '/jogos/'),
					);
				}

				$player_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT pl.id, pe.shortname, pe.longname
                                FROM {$wpdb->prefix}multimedia_photogallery_players rel
                                INNER JOIN {$wpdb->prefix}person_player pl ON pl.id = rel.player_id
                                INNER JOIN {$wpdb->prefix}person_person pe ON pe.id = pl.person_id
                                WHERE rel.photogallery_id = %d
                                ORDER BY pe.shortname",
						$gallery->id
					)
				);

				foreach ($player_rows as $player) {
					$links[] = array(
						'title' => $player->shortname ?: $player->longname,
						'url'   => home_url('/jogador/' . absint($player->id) . '/'),
					);
				}

				$coach_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT c.id, c.coach_name, pe.shortname, pe.longname
                                FROM {$wpdb->prefix}multimedia_photogallery_coaches rel
                                INNER JOIN {$wpdb->prefix}person_coach c ON c.id = rel.coach_id
                                LEFT JOIN {$wpdb->prefix}person_person pe ON pe.id = c.person_id
                                WHERE rel.photogallery_id = %d
                                ORDER BY c.coach_name",
						$gallery->id
					)
				);

				foreach ($coach_rows as $coach) {
					$links[] = array(
						'title' => $coach->coach_name ?: ($coach->shortname ?: $coach->longname),
						'url'   => home_url('/treinador/' . absint($coach->id) . '/'),
					);
				}

				$president_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT pr.id, pe.shortname, pe.longname
                                FROM {$wpdb->prefix}multimedia_photogallery_presidents rel
                                INNER JOIN {$wpdb->prefix}person_president pr ON pr.id = rel.president_id
                                INNER JOIN {$wpdb->prefix}person_person pe ON pe.id = pr.person_id
                                WHERE rel.photogallery_id = %d
                                ORDER BY pe.shortname",
						$gallery->id
					)
				);

				foreach ($president_rows as $president) {
					$links[] = array(
						'title' => $president->shortname ?: $president->longname,
						'url'   => home_url('/dirigente/' . absint($president->id) . '/'),
					);
				}

				$referee_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT r.id, pe.shortname, pe.longname
                                FROM {$wpdb->prefix}multimedia_photogallery_referees rel
                                INNER JOIN {$wpdb->prefix}person_referee r ON r.id = rel.referee_id
                                INNER JOIN {$wpdb->prefix}person_person pe ON pe.id = r.person_id
                                WHERE rel.photogallery_id = %d
                                ORDER BY pe.shortname",
						$gallery->id
					)
				);

				foreach ($referee_rows as $referee) {
					$links[] = array(
						'title' => $referee->shortname ?: $referee->longname,
						'url'   => home_url('/arbitro/' . absint($referee->id) . '/'),
					);
				}

				return $links;
			}
		}

		$gallery_id = f365_pg_id_from_url();

		$gallery = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
                        FROM {$wpdb->prefix}multimedia_photogallery
                        WHERE id = %d
                        AND is_published = 1",
				$gallery_id
			)
		);

		if (!$gallery) {
			echo '<p class="f365-no-results">Fotogaleria nao encontrada.</p>';
			return;
		}

		$gallery_images = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
                            p.id,
                            p.title,
                            p.image
                        FROM {$wpdb->prefix}multimedia_photogalleryphotos pgp
                        INNER JOIN {$wpdb->prefix}multimedia_photo p ON p.id = pgp.photo_id
                        WHERE pgp.photogallery_id = %d
                        ORDER BY pgp.id ASC",
				$gallery_id
			)
		);

		$related_links = f365_pg_get_related_links($gallery);

		$latest_galleries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
                            pg.id,
                            pg.title,
                            COALESCE(NULLIF(pg.image, ''), first_photo.image, 'images/photos/no-image.jpg') AS front_image,
                            pg.datetime
                        FROM {$wpdb->prefix}multimedia_photogallery pg
                        LEFT JOIN (
                            SELECT pgp.photogallery_id, MIN(p.image) AS image
                            FROM {$wpdb->prefix}multimedia_photogalleryphotos pgp
                            INNER JOIN {$wpdb->prefix}multimedia_photo p ON p.id = pgp.photo_id
                            GROUP BY pgp.photogallery_id
                        ) AS first_photo ON first_photo.photogallery_id = pg.id
                        WHERE pg.is_published = 1
                        AND pg.id <> %d
                        ORDER BY pg.datetime DESC
                        LIMIT 8",
				$gallery_id
			)
		);
		?>
		<?php if (!empty($related_links)) : ?>
			<div class="button-toolbar vertical-padding">
				<?php foreach ($related_links as $related_link) : ?>
					<div class="button-group">
						<a class="ink-button" href="<?php echo esc_url($related_link['url']); ?>">
							<?php echo esc_html($related_link['title']); ?>
						</a>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</div>
<div class="single-content top-space">
	<?php the_content(); ?>
</div>