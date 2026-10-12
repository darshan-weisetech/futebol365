<?php
/**
 * Competition — Estádios   /competicao/{fed_comp_id}/estadios/
 *
 * Fully RELATIONAL port of competition/views._get_stadiums_for_competition().
 * Reads ONLY the Django mirror tables (no ACF, no postmeta for football data):
 *
 *   competition_competition(id, federation_competition_id)
 *   federation_federationcompetition(id, federation_id, scope, show_stadiums)
 *   competition_participant(competition_id, team_id -> team_teamstep.id)
 *   team_teamstep(id, team_id -> team_team.id, stadium_id)
 *   team_team(id, stadium_id)
 *   competition_match(competition_id, stadium_id, home_team_id -> teamstep.id)
 *   stadium_stadium(id, shortname, city, capacity, country_id)
 *
 * Stadium id resolution (Django):
 *   championship scope (national_championship/regional_championship):
 *     per participant -> teamstep.stadium_id, else teamstep.team.stadium_id
 *   else (cup/league by matches):
 *     per match -> match.stadium_id, else home_teamstep.stadium_id,
 *     else home_teamstep.team.stadium_id
 *   Then Stadium.filter(pk in ids).order_by('shortname').
 *   404 when federation_competition.show_stadiums is false.
 *
 * The only WP postmeta used is django_competition_id (to map this WP post to the
 * Django competition row) — everything else is relational.
 *
 * @package Futebol365
 */

defined('ABSPATH') || exit;

global $wpdb;

get_header();

$post_id = $args['post_id'] ?? get_the_ID();

$sections        = futebol365_get_competition_sections();
$current_section = 'estadios';
if (!isset($sections[$current_section])) {
	$current_section = 'resumo';
}
$level_1_title = 'Estádios';

//$competition_id = (int) (get_post_meta($post_id, 'django_competition_id', true) ?: 0);
$competition_id = get_legacy_competition_id( $post_id );
$prefix         = $wpdb->prefix;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

if (!function_exists('f365_stadium_url')) {
	function f365_stadium_url(int $stadium_id): string
	{
		return $stadium_id ? user_trailingslashit(home_url('estadio/' . $stadium_id)) : '';
	}
}

if (!function_exists('f365_stadium_image_url')) {
    /**
     * Stadium photo pointing to the local site directory instead of the external host.
     */
    function f365_stadium_image_url(int $stadium_id): string
    {
        if (!$stadium_id) {
            return '';
        }

        // Generates: https://weisetech.dev/futebol365/media/images/stadium/{id}.jpg
        $url = home_url('/media/images/stadium/' . $stadium_id . '.jpg');

        return (string) apply_filters('f365_stadium_image_url', $url, $stadium_id);
    }
}

/**
 * Relational stadium list for a competition — cached per competition.
 * Returns stadium rows ordered by shortname, or [] when show_stadiums is off.
 */

if (!function_exists('f365_competition_stadiums_rel')) {
    function f365_competition_stadiums_rel(int $competition_id): array
    {
        global $wpdb;
        if (!$competition_id) {
            return [];
        }

        $cache_key   = 'f365_comp_stadiums_' . $competition_id;
        $cache_group = 'futebol365_stadiums';
        $cached      = wp_cache_get($cache_key, $cache_group);
        if (false !== $cached) {
            return $cached;
        }

        $p = $wpdb->prefix;

        // Resolve scope + show_stadiums from federationcompetition.
        $fc = $wpdb->get_row($wpdb->prepare(
            "SELECT fc.scope AS scope, fc.show_stadiums AS show_stadiums
             FROM {$p}competition_competition c
             INNER JOIN {$p}federation_federationcompetition fc ON fc.id = c.federation_competition_id
             WHERE c.id = %d",
            $competition_id
        )); // phpcs:ignore WordPress.DB

        // show_stadiums false (Postgres 'f'/0) -> Django raises 404; return [].
        if ($fc) {
            $show = (string) $fc->show_stadiums;
            if ('0' === $show || 'f' === $show || 'false' === $show || '' === $show) {
                wp_cache_set($cache_key, [], $cache_group, DAY_IN_SECONDS);
                return [];
            }
        }

        $scope           = $fc ? (string) $fc->scope : '';
        $is_championship = in_array($scope, ['national_championship', 'regional_championship'], true);
        $stadium_ids     = [];

        if ($is_championship) {
            /*
             * Championship: per participant teamstep, prefer teamstep.stadium_id,
             * else the base team's stadium_id. One query with COALESCE does the
             * fallback; DISTINCT collapses duplicates.
             */
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT COALESCE(NULLIF(ts.stadium_id, 0), tm.stadium_id) AS sid
                 FROM {$p}competition_participant pa
                 INNER JOIN {$p}team_teamstep ts ON ts.id = pa.team_id
                 LEFT  JOIN {$p}team_team tm     ON tm.id = ts.team_id
                 WHERE pa.competition_id = %d",
                $competition_id
            )); // phpcs:ignore WordPress.DB
        } else {
            /*
             * Else (cup/league): per match, prefer match.stadium_id, else the home
             * teamstep's stadium_id, else the home teamstep's base team stadium_id.
             */
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT COALESCE(NULLIF(m.stadium_id, 0), NULLIF(ht.stadium_id, 0), tm.stadium_id) AS sid
                 FROM {$p}competition_match m
                 LEFT JOIN {$p}team_teamstep ht ON ht.id = m.home_team_id
                 LEFT JOIN {$p}team_team tm     ON tm.id = ht.team_id
                 WHERE m.competition_id = %d",
                $competition_id
            )); // phpcs:ignore WordPress.DB
        }

        foreach ((array) $rows as $sid) {
            $sid = (int) $sid;
            if ($sid) {
                $stadium_ids[$sid] = true;
            }
        }

        if (empty($stadium_ids)) {
            wp_cache_set($cache_key, [], $cache_group, DAY_IN_SECONDS);
            return [];
        }

        $in       = implode(',', array_map('absint', array_keys($stadium_ids)));
        $stadiums = $wpdb->get_results(
            "SELECT s.id, s.shortname, s.longname, s.city, s.capacity, s.country_id
             FROM {$p}stadium_stadium s
             WHERE s.id IN ($in)
             ORDER BY s.shortname ASC"
        ); // phpcs:ignore WordPress.DB

        $stadiums = (array) $stadiums;
        wp_cache_set($cache_key, $stadiums, $cache_group, DAY_IN_SECONDS);
        return $stadiums;
    }
}
/* -------------------------------------------------------------------------
 * Load + paginate
 * ---------------------------------------------------------------------- */

$all_stadiums = f365_competition_stadiums_rel($competition_id);

$per_page = 20;
$paged    = isset($_GET['pagina']) ? absint($_GET['pagina']) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ($paged < 1) {
	$paged = 1;
}
$total         = count($all_stadiums);
$max_num_pages = (int) ceil($total / $per_page);
if ($max_num_pages > 0 && $paged > $max_num_pages) {
	$paged = $max_num_pages;
}
$offset        = ($paged - 1) * $per_page;
$page_stadiums = array_slice($all_stadiums, $offset, $per_page);

// Breadcrumb federation chain: resolve relationally from the competition row.
$federation_id = 0;
$fed_edition   = '';
if ($competition_id) {
	$crumb = $wpdb->get_row($wpdb->prepare(
		"SELECT c.edition AS edition, fc.federation_id AS federation_id
		 FROM {$prefix}competition_competition c
		 LEFT JOIN {$prefix}federation_federationcompetition fc ON fc.id = c.federation_competition_id
		 WHERE c.id = %d",
		$competition_id
	)); // phpcs:ignore WordPress.DB
	if ($crumb) {
		$fed_edition = (string) $crumb->edition;
		// Map the Django federation id to its WP post for the breadcrumb helper.
		$wp_fed = $wpdb->get_var($wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key = 'django_federation_id' AND meta_value = %d LIMIT 1",
			(int) $crumb->federation_id
		)); // phpcs:ignore WordPress.DB
		$federation_id = (int) $wp_fed;
	}
}
?>

<main id="primary" class="site-main single-article-page">

	<?php
	get_template_part(
		'template-parts/competition/f365-competition-header',
		null,
		array(
			'post_id'         => $post_id,
			'current_section' => $current_section,
			'sections'        => $sections,
			'edition'         => $fed_edition,
			'competition_id'  => $competition_id,
		)
	);
	?>

	<div class="ink-grid futebol365-container">

		<section class="left-column-block">

			<div class="breadcrumbs-f365">
				<div class="ink-navigation breadcrumbs">
				<?php if (function_exists('yoast_breadcrumb')) {
					yoast_breadcrumb();
				} ?>
				</div>
			</div>

			<h3 class="condensed-400 headerTitle blue"><?php echo esc_html($level_1_title); ?><span class="headerTitle-border"></span></h3>

			<?php if (!empty($page_stadiums)) : ?>

				<div class="column-group half-gutters">
					<?php
					foreach ($page_stadiums as $stadium) :
						$stadium_id    = (int) $stadium->id;
						$stadium_title = (string) $stadium->shortname;
						$city          = (string) $stadium->city;
						$capacity      = (int) $stadium->capacity;
						$detail_url    = f365_stadium_url($stadium_id);

						// Wrapped in home_url() to turn the relative path into a full URL
						$image_url     = f365_stadium_image_url($stadium_id);
						$no_image      = home_url('/media/images/stadium/no-image.jpg');
					?>

					<div class="all-50 small-100 tiny-100">
						<div class="obj-right-div obj-flex">
							<div class="obj-image-stadium">
								<figure class="ink-image">
									<a href="<?php echo esc_url($detail_url); ?>">
										<img
											src="<?php echo esc_url($image_url); ?>"
											title="<?php echo esc_attr($stadium_title); ?>"
											alt="<?php echo esc_attr($stadium_title); ?>"
											onerror="this.onerror=null; this.src='<?php echo esc_url($no_image); ?>';">
									</a>
								</figure>
							</div>
							<div class="obj-detail">
								<div class="obj-name"><a href="<?php echo esc_url($detail_url); ?>"><?php echo esc_html($stadium_title); ?></a></div>
								<div class="obj-link"><?php echo esc_html($city); ?></div>
								<div class="obj-link">
									<?php if ($capacity > 0) : ?>
										<?php echo esc_html(number_format($capacity, 0, '', '')); ?> <?php esc_html_e('lugares', 'futebol365'); ?>
									<?php endif; ?>
								</div>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
				</div>

				<?php if ($max_num_pages > 1) : ?>
					<div class="pagination-f365">
						<?php
						$pagination_args = array();
						if (isset($_GET['edition']) && '' !== $_GET['edition']) {
							$pagination_args['edition'] = sanitize_text_field(wp_unslash($_GET['edition']));
						}
						echo paginate_links(
							array(
								'base'     => '%_%',
								'format'   => '?pagina=%#%',
								'total'    => $max_num_pages,
								'current'  => $paged,
								'add_args' => $pagination_args,
							)
						);
						?>
					</div>
				<?php endif; ?>

			<?php else : ?>

				<h5 class="no-info"><?php esc_html_e('Sem informação disponível', 'futebol365'); ?></h5>

			<?php endif; ?>
			<?php get_template_part('template-parts/template-front-page-posts'); ?>

		</section>

		<section class="right-column-block">
			<?php
			if (is_active_sidebar('post-sidebar')) {
				dynamic_sidebar('post-sidebar');
			} else {
				get_sidebar();
			}
			?>
		</section>

	</div>
</main>

<?php
get_footer();
