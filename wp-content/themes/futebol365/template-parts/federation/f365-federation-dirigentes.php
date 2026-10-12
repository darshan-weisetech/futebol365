<?php
/**
 * Federation — Dirigentes (Presidents)
 *
 * Port of federation/templates/federation/federation-president.html.
 *   View: President.objects.filter(person__nationality=federation.country)
 *               .order_by('person__shortname')  (paginated 30)
 *   Confederation mode: federation.get_descendents() + member.count_presidents.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

get_header();
global $wpdb;

$current_post_id      = get_the_ID();
//$django_federation_id = get_post_meta( $current_post_id, 'django_federation_id', true );
$django_federation_id = get_legacy_competition_id( $current_post_id );
$federation_id        = intval( $django_federation_id );
$sections             = futebol365_get_federation_sections();

$fed_table    = $wpdb->prefix . 'federation_federation';
$country_tbl  = $wpdb->prefix . 'country_country';
$pres_table   = $wpdb->prefix . 'person_president';
$person_table = $wpdb->prefix . 'person_person';

$fed = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT f.id, f.shortname, f.longname, f.country_id, f.federation_id, f.federation_type,
		        pf.federation_type AS parent_type, pf.country_id AS parent_country_id
		   FROM {$fed_table} f
		   LEFT JOIN {$fed_table} pf ON f.federation_id = pf.id
		  WHERE f.id = %d",
		$federation_id
	),
	OBJECT
);


if ( ! function_exists( 'f365_get_image_in_path' ) ) {
	function f365_get_image_in_path( $path, $filename, $ext ) {
		$media_url = defined( 'F365_MEDIA_URL' ) ? trailingslashit( F365_MEDIA_URL ) : trailingslashit( home_url( '/media/' ) );
		return $media_url . $path . $filename . '.' . $ext;
	}
}

if ( ! function_exists( 'f365_president_age' ) ) {
	/**
	 * Port of common.functions.get_years_in_between(birth_date, today).
	 */
	function f365_president_age( $birth_date ) {
		if ( empty( $birth_date ) || '0000-00-00' === $birth_date ) {
			return '';
		}
		$bts = strtotime( (string) $birth_date );
		if ( false === $bts ) {
			return '';
		}
		$by  = (int) gmdate( 'Y', $bts );
		$bm  = (int) gmdate( 'n', $bts );
		$bd  = (int) gmdate( 'j', $bts );
		$ty  = (int) current_time( 'Y' );
		$tm  = (int) current_time( 'n' );
		$td  = (int) current_time( 'j' );
		$age = $ty - $by;
		if ( $bm < $tm ) {
			return $age;
		}
		if ( $bm > $tm ) {
			return $age - 1;
		}
		return ( $bd > $td ) ? ( $age - 1 ) : $age;
	}
}

if ( ! function_exists( 'f365_federation_member_flag_id' ) ) {
	function f365_federation_member_flag_id( $type, $id, $country_id, $parent_country_id ) {
		if ( 'association' === $type ) {
			return ( null !== $parent_country_id && '' !== $parent_country_id ) ? intval( $parent_country_id ) : '';
		}
		if ( 'federation' === $type ) {
			return ( null !== $country_id && '' !== $country_id ) ? intval( $country_id ) : '';
		}
		return 'conf_' . intval( $id );
	}
}

if ( ! function_exists( 'f365_count_presidents_by_nationality' ) ) {
	// President.objects.filter(person__nationality=country).count()
	function f365_count_presidents_by_nationality( $country_id ) {
		global $wpdb;
		if ( null === $country_id || '' === $country_id ) {
			return 0;
		}
		$pres   = $wpdb->prefix . 'person_president';
		$person = $wpdb->prefix . 'person_person';
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$pres} pr JOIN {$person} pe ON pr.person_id = pe.id WHERE pe.nationality_id = %d",
				intval( $country_id )
			)
		);
	}
}

if ( ! function_exists( 'f365_count_presidents' ) ) {
	function f365_count_presidents( $fed_id, $fed_type, $parent_type, $country_id ) {
		global $wpdb;
		$result = f365_count_presidents_by_nationality( $country_id );

		if ( 'confederation' === $fed_type && 'confederation' === $parent_type ) {
			$fed_table   = $wpdb->prefix . 'federation_federation';
			$country_tbl = $wpdb->prefix . 'country_country';
			$subs        = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT f.country_id
					   FROM {$fed_table} f
					   LEFT JOIN {$country_tbl} c ON f.country_id = c.id
					  WHERE f.federation_id = %d
					    AND ( c.id IS NULL OR CAST( c.is_extinct AS CHAR ) NOT IN ( '1', 't', 'true' ) )",
					intval( $fed_id )
				),
				OBJECT
			);
			foreach ( (array) $subs as $s ) {
				$result += f365_count_presidents_by_nationality( $s->country_id );
			}
		}
		return $result;
	}
}

$is_confed = $fed ? ( 'confederation' === $fed->federation_type ) : false;

$descendents = array();
$presidents  = array();
$total_pages = 1;
$cur_page    = isset( $_GET['pagina'] ) ? max( 1, intval( $_GET['pagina'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification

if ( $fed ) {
	if ( $is_confed ) {
		// federation.get_descendents() — children with a non-extinct (or null) country.
		$descendents = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.id, f.shortname, f.country_id, f.federation_type
				   FROM {$fed_table} f
				   LEFT JOIN {$country_tbl} c ON f.country_id = c.id
				  WHERE f.federation_id = %d
				    AND ( c.id IS NULL OR CAST( c.is_extinct AS CHAR ) NOT IN ( '1', 't', 'true' ) )
				  ORDER BY f.shortname ASC",
				$federation_id
			),
			OBJECT
		);
	} else {
		$per_page    = 30;
		$country_id  = intval( $fed->country_id );
		$total_items = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$pres_table} pr JOIN {$person_table} pe ON pr.person_id = pe.id WHERE pe.nationality_id = %d",
				$country_id
			)
		);
		$total_pages = max( 1, (int) ceil( $total_items / $per_page ) );
		$cur_page    = min( $cur_page, $total_pages );
		$offset      = ( $cur_page - 1 ) * $per_page;

		if ( $total_items > 0 ) {
			$presidents = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pr.id, pr.person_id, pe.shortname, pe.birth_place, pe.birth_date, pe.death_date
					   FROM {$pres_table} pr
					   JOIN {$person_table} pe ON pr.person_id = pe.id
					  WHERE pe.nationality_id = %d
					  ORDER BY pe.shortname ASC, pr.id ASC
					  LIMIT %d OFFSET %d",
					$country_id,
					$per_page,
					$offset
				),
				OBJECT
			);
		}
	}
}
?>

<main id="primary" class="site-main single-article-page">
	<?php
	get_template_part(
		'template-parts/federation/f365-federation-header',
		null,
		array(
			'post_id'  => $current_post_id,
			'sections' => $sections,
		)
	);
	?>

	<div class="ink-grid futebol365-container">

		<section class="left-column-block">
			<div class="breadcrumbs-f365">
				<div class="ink-navigation breadcrumbs">
					<?php yoast_breadcrumb(); ?>
				</div>
			</div>

			<h3 class="condensed-400 headerTitle green"><?php esc_html_e( 'Dirigentes', 'futebol365' ); ?></h3>

			<?php if ( $is_confed ) : ?>
				<div class="column-group half-gutters">
					<?php foreach ( $descendents as $federation_member ) :
						$m_flag  = f365_federation_member_flag_id( $federation_member->federation_type, $federation_member->id, $federation_member->country_id, $fed->country_id );
						$m_url   = esc_url( home_url( '/federacao/' . intval( $federation_member->id ) . '/dirigentes' ) );
						$m_label = $federation_member->shortname;
						$m_count = f365_count_presidents( $federation_member->id, $federation_member->federation_type, $fed->federation_type, $federation_member->country_id );
						$plural  = ( 1 === $m_count ) ? '' : 's';
						?>
						<div class="all-50 xlarge-33 small-100 tiny-100">
							<div class="obj-right-div">
								<div class="obj-flag">
									<a href="<?php echo $m_url; ?>"><i class="flag-icon flag-icon-<?php echo esc_attr( $m_flag ); ?>" title="<?php echo esc_attr( $m_label ); ?>"></i></a>
								</div>
								<div class="obj-detail">
									<div class="obj-name"><a href="<?php echo $m_url; ?>"><?php echo esc_html( $m_label ); ?></a></div>
									<div class="obj-link"><?php echo esc_html( $m_count ); ?> <?php esc_html_e( 'dirigente', 'futebol365' ); ?><?php echo esc_html( $plural ); ?></div>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<?php if ( ! empty( $presidents ) ) : ?>
					<div class="column-group half-gutters">
						<?php
						$pres_loader = esc_url( get_template_directory_uri() . '/website/img/responsive/ajax-loader.gif' );
						foreach ( $presidents as $president ) :
							$pres_url  = esc_url( home_url( '/dirigente/' . intval( $president->id ) . '/' ) );
							$filename  = intval( $president->person_id ) . '_president';
							$photo_url = esc_url( f365_get_image_in_path( 'images/person/', $filename, 'jpg' ) );
							$image_url = file_exists($photo_url)
								? home_url($photo_url)
								: futebol365_fallback_image();
							$age       = f365_president_age( $president->birth_date );
							$is_dead   = ( ! empty( $president->death_date ) && '0000-00-00' !== $president->death_date );
							$has_birth = ( ! empty( $president->birth_date ) && '0000-00-00' !== $president->birth_date );
							?>
							<div class="all-50 xlarge-33 small-100 tiny-100">
								<div class="obj-right-div">
									<div class="obj-image-person">
										<figure class="ink-image ink-lazyload" data-delta="100">
											<a href="<?php echo $pres_url; ?>">
												<img src="<?php echo esc_url($image_url); ?>"
												     data-src="<?php echo $image_url; ?>"
												     class="lazyload-item"
												     alt="<?php echo esc_attr( $president->shortname ); ?>"
												     title="<?php echo esc_attr( $president->shortname ); ?>"
													 onerror="this.onerror=null;this.src='<?php echo esc_url( futebol365_fallback_image() ); ?>';"/>
											</a>
										</figure>
									</div>
									<div class="obj-detail">
										<div class="obj-name"><a href="<?php echo $pres_url; ?>"><?php echo esc_html( $president->shortname ); ?></a></div>
										<div class="obj-link"><?php echo esc_html( $president->birth_place ?? '' ); ?></div>
										<div class="obj-link">
											<?php if ( $has_birth ) : ?>
												<?php if ( $is_dead ) : ?>
													Falecido
												<?php else : ?>
													<?php echo esc_html( $age ); ?> <?php esc_html_e( 'anos', 'futebol365' ); ?>
												<?php endif; ?>
											<?php endif; ?>
										</div>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>

					<?php
					if ( isset( $cur_page, $total_pages ) && function_exists( 'futebol365_render_pagination' ) ) :
						futebol365_render_pagination( $cur_page, $total_pages );
					endif;
					?>

				<?php else : ?>
					<h5 class="no-info"><?php esc_html_e( 'Sem informação disponível', 'futebol365' ); ?></h5>
				<?php endif; ?>
			<?php endif; ?>

			<?php get_template_part( 'template-parts/template-front-page-posts' ); ?>
		</section>

		<section class="right-column-block">
			<?php
			if ( is_active_sidebar( 'post-sidebar' ) ) {
				dynamic_sidebar( 'post-sidebar' );
			} else {
				get_sidebar();
			}
			?>
		</section>

	</div>
</main>

<?php
get_footer();
