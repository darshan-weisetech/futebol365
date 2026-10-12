<?php
/**
 * Federation — Equipas
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

get_header();
global $wpdb;

$current_post_id = get_the_ID();

//$django_federation_id = get_post_meta( $current_post_id, 'django_federation_id', true );
$django_federation_id = get_legacy_competition_id( $current_post_id );
$federation_id        = intval( $django_federation_id );
$sections             = futebol365_get_federation_sections();

// Definição exata das tabelas mapeadas da sua aplicação Python
$fed_table   = $wpdb->prefix . 'federation_federation';
$country_tbl = $wpdb->prefix . 'country_country';
$team_table  = $wpdb->prefix . 'team_team';
$ts_table    = $wpdb->prefix . 'team_teamstep';

$fed = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT f.id, f.shortname, f.longname, f.url, f.facebook, f.twitter, f.youtube,
		        f.instagram, f.googleplus, f.country_id, f.foundation, f.federation_id, f.federation_type,
		        pf.country_id AS parent_country_id
		   FROM {$fed_table} f
		   LEFT JOIN {$fed_table} pf ON f.federation_id = pf.id
		  WHERE f.id = %d",
		$federation_id
	),
	OBJECT
);


/* -------------------------------------------------------------------------
 * Helpers.
 * ---------------------------------------------------------------------- */
if ( ! function_exists( 'f365_federation_flag_id' ) ) {
	function f365_federation_flag_id( $fed ) {
		if ( 'association' === $fed->federation_type ) {
			return ( null !== $fed->parent_country_id && '' !== $fed->parent_country_id ) ? intval( $fed->parent_country_id ) : '';
		}
		if ( 'federation' === $fed->federation_type ) {
			return ( null !== $fed->country_id && '' !== $fed->country_id ) ? intval( $fed->country_id ) : '';
		}
		return 'conf_' . intval( $fed->id );
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

if ( ! function_exists( 'f365_teamstep_modality_order' ) ) {
	// team.choices.MODALITY order.
	function f365_teamstep_modality_order() {
		return array( 'football', 'futsal' );
	}
}

if ( ! function_exists( 'f365_teamstep_gender_order' ) ) {
	// common.choices.GENDER order.
	function f365_teamstep_gender_order() {
		return array( 'male', 'female' );
	}
}

if ( ! function_exists( 'f365_teamstep_step_order' ) ) {
	// team.choices.STEP order => display label (get_step_display).
	function f365_teamstep_step_order() {
		return array(
			'principal'    => 'Principal',
			'b_team'       => 'B team',
			'c_team'       => 'C team',
			'sub_23'       => 'S23',
			'sub_22'       => 'S22',
			'sub_21'       => 'S21',
			'sub_20'       => 'S20',
			'sub_19'       => 'S19',
			'sub_19_b'     => 'S19 B',
			'sub_18'       => 'S18',
			'sub_17'       => 'S17',
			'sub_17_b'     => 'S17 B',
			'sub_16'       => 'S16',
			'sub_15'       => 'S15',
			'sub_15_b'     => 'S15 B',
			'sub_14'       => 'S14',
			'sub_13'       => 'S13',
			'sub_13_b'     => 'S13 B',
			'sub_12'       => 'S12',
			'sub_12_b'     => 'S12 B',
			'sub_11'       => 'S11',
			'sub_11_b'     => 'S11 B',
			'sub_10'       => 'S10',
			'sub_10_b'     => 'S10 B',
			'sub_9'        => 'S9',
			'sub_8'        => 'S8',
			'sub_7'        => 'S7',
			'sub_6'        => 'S6',
			'veterans'     => 'Veterans',
			'reservations' => 'Reservations',
		);
	}
}

if ( ! function_exists( 'f365_teamstep_label' ) ) {
	function f365_teamstep_label( $team_name, $modality, $gender, $step, $step_display ) {
		$rest = '';
		if ( 'futsal' === $modality ) {
			$rest .= ' FS';
		}
		if ( 'female' === $gender ) {
			$rest .= ' FM';
		}
		if ( ! in_array( $step, array( 'principal', 'b_team', 'c_team', 'veterans', 'reservations' ), true ) ) {
			$rest .= ' ' . $step_display;
		}
		if ( 'b_team' === $step ) {
			$rest .= ' B';
		}
		if ( 'c_team' === $step ) {
			$rest .= ' C';
		}
		if ( 'veterans' === $step ) {
			$rest .= ' VET';
		}
		if ( 'reservations' === $step ) {
			$rest .= ' RES';
		}
		$rest = trim( $rest );
		return ( '' !== $rest ) ? ( $team_name . ' ' . $rest ) : $team_name;
	}
}

$is_assoc  = $fed ? ( 'association' === $fed->federation_type ) : false;
$is_confed = $fed ? ( 'confederation' === $fed->federation_type ) : false;
$is_federation = $fed ? ( 'federation' === $fed->federation_type ) : false;

$national_teams = array();
$descendents    = array();
$teams          = array();
$total_pages    = 1;
$cur_page       = isset( $_GET['pagina'] ) ? max( 1, intval( $_GET['pagina'] ) ) : 1;

if ( $fed ) {
	if ( $is_federation && 1 === $cur_page ) {
		$nt_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.id, t.shortname, t.foundation, t.federation_id, ftf.shortname AS fed_shortname
				   FROM {$team_table} t
				   LEFT JOIN {$fed_table} ftf ON t.federation_id = ftf.id
				  WHERE t.federation_id = %d AND t.country_id = %d
				  ORDER BY t.shortname ASC",
				intval( $fed->federation_id ),
				intval( $fed->country_id )
			),
			OBJECT
		);

		if ( $nt_rows ) {
			$nt_ids        = array_map( 'intval', wp_list_pluck( $nt_rows, 'id' ) );
			$nt_ph         = implode( ',', array_fill( 0, count( $nt_ids ), '%d' ) );
			$ts_rows       = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, team_id, modality, gender, step
					   FROM {$ts_table}
					  WHERE team_id IN ( {$nt_ph} )
					  ORDER BY id ASC",
					$nt_ids
				),
				OBJECT
			);

			$first_ts = array(); 
			foreach ( (array) $ts_rows as $r ) {
				$tid = (int) $r->team_id;
				$key = $r->modality . '|' . $r->gender . '|' . $r->step;
				if ( ! isset( $first_ts[ $tid ][ $key ] ) ) {
					$first_ts[ $tid ][ $key ] = $r;
				}
			}

			$mod_order  = f365_teamstep_modality_order();
			$gen_order  = f365_teamstep_gender_order();
			$step_order = f365_teamstep_step_order();
			foreach ( $nt_rows as $nt ) {
				$tid    = (int) $nt->id;
				$combos = array();
				foreach ( $mod_order as $mod ) {
					foreach ( $gen_order as $gen ) {
						foreach ( $step_order as $step => $step_label ) {
							$key = $mod . '|' . $gen . '|' . $step;
							if ( isset( $first_ts[ $tid ][ $key ] ) ) {
								$combos[] = array(
									'modality' => $mod,
									'gender'   => $gen,
									'step'     => $step,
									'label'    => f365_teamstep_label( $nt->shortname, $mod, $gen, $step, $step_label ),
								);
							}
						}
					}
				}
				$national_teams[] = array(
					'id'            => $tid,
					'foundation'    => $nt->foundation,
					'fed_shortname' => $nt->fed_shortname,
					'combos'        => $combos,
				);
			}
		}
	}

	if ( $is_confed ) {
		$descendents = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.id, f.shortname, f.longname, f.country_id, f.federation_type,
				        (SELECT COUNT(1) FROM {$team_table} t WHERE t.federation_id = f.id) AS count_teams
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

		$per_page = 30;
		$total_items = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(1) FROM {$team_table} WHERE federation_id = %d", $federation_id )
		);

		$total_pages = max( 1, (int) ceil( $total_items / $per_page ) );
		$cur_page    = min( $cur_page, $total_pages );
		$offset      = ( $cur_page - 1 ) * $per_page;

		if ( $total_items > 0 ) {
			$teams = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT t.id, t.shortname, t.longname, t.city, t.foundation, t.extinction
					   FROM {$team_table} t
					  WHERE t.federation_id = %d
					  ORDER BY t.shortname ASC
					  LIMIT %d OFFSET %d",
					$federation_id,
					$per_page,
					$offset
				),
				OBJECT
			);

		}

		$visible      = 3;
		$window_start = max( 1, $cur_page - intval( floor( $visible / 2 ) ) );
		$window_end   = min( $total_pages, $window_start + $visible - 1 );
		$window_start = max( 1, $window_end - $visible + 1 );
	}
}

if ( ! function_exists( 'f365_fed_page_link' ) ) {
	function f365_fed_page_link( $page ) {
		$params = $_GET; // phpcs:ignore WordPress.Security.NonceVerification
		$params = array_map( 'sanitize_text_field', wp_unslash( (array) $params ) );
		if ( $page <= 1 ) {
			unset( $params['pagina'] );
		} else {
			$params['pagina'] = $page;
		}
		$qs = http_build_query( $params );
		return esc_url( ( '' !== $qs ) ? ( '?' . $qs ) : '?' );
	}
}
?>

<main id="primary" class="site-main single-article-page" <?php echo $current_post_id; ?>>
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

			<?php if ( $is_federation && ! empty( $national_teams ) && 1 === $cur_page ) : ?>
				<h3 class="condensed-400 headerTitle blue"><?php esc_html_e( 'Seleções', 'futebol365' ); ?></h3>
				<div class="column-group half-gutters bottom-space">
					<?php
					$nt_loader = esc_url( get_template_directory_uri() . '/website/img/responsive/ajax-loader.gif' );
					foreach ( $national_teams as $national_team ) :
						$logo_url      = '/media/images/logotipo/100x100/' . intval( $national_team['id'] ) . '.gif';
						$logo_src      = esc_url( home_url( $logo_url ) );
						$fed_teams_url = esc_url( home_url( '/federacao/' . intval( $federation_id ) . '/equipas' ) );
						$found_year    = ! empty( $national_team['foundation'] ) ? mysql2date( 'Y', $national_team['foundation'] ) : '';
						// One card per (modality, gender, step) combination of the team.
						foreach ( $national_team['combos'] as $combo ) :
							$matches_url = esc_url(
								home_url( '/equipa/' . intval( $national_team['id'] ) . '/jogos/' )
								. '?step=' . rawurlencode( $combo['step'] )
								. '&modality=' . rawurlencode( $combo['modality'] )
								. '&gender=' . rawurlencode( $combo['gender'] )
							);
							?>
							<div class="all-50 xlarge-33 small-100 tiny-100">
								<div class="obj-right-div">
									<div class="obj-image-team">
										<figure class="ink-image ink-lazyload" data-delta="100">
											<a href="<?php echo $fed_teams_url; ?>">
												<img src="<?php echo $nt_loader; ?>"
												     data-src="<?php echo $logo_src; ?>"
												     class="lazyload-item"
												     alt="<?php echo esc_attr( $fed->shortname ?? '' ); ?>"
												     title="<?php echo esc_attr( $fed->shortname ?? '' ); ?>"
													 onerror="this.onerror=null;this.src='<?php echo esc_url( futebol365_fallback_image() ); ?>';"/>
											</a>
										</figure>
									</div>
									<div class="obj-detail">
										<div class="obj-name">
											<a href="<?php echo $matches_url; ?>">
												<?php echo esc_html( $combo['label'] ); ?>
											</a>
										</div>
										<div class="obj-link">
											<?php echo esc_html( $national_team['fed_shortname'] ?? '' ); ?>
										</div>
										<?php if ( ! empty( $found_year ) ) : ?>
											<div class="obj-link">
												<?php esc_html_e( 'Fundação:', 'futebol365' ); ?> <?php echo esc_html( $found_year ); ?>
											</div>
										<?php endif; ?>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<h3 class="condensed-400 headerTitle blue"><?php esc_html_e( 'Equipas', 'futebol365' ); ?></h3>

			<?php if ( $is_confed ) : ?>
				<div class="column-group half-gutters">
					<?php foreach ( $descendents as $federation_member ) :
						$m_flag  = f365_federation_member_flag_id( $federation_member->federation_type, $federation_member->id, $federation_member->country_id, $fed->country_id );
						$m_url   = esc_url( home_url( '/federacao/' . intval( $federation_member->id ) . '/equipas' ) );
						$m_label = $federation_member->shortname;
						
						// Add the +1 to the total count first so pluralization matches what is displayed
						$m_count = intval( $federation_member->count_teams ) + 1;
						$plural  = ( 1 === $m_count ) ? '' : 's';
					?>
						<div class="all-50 xlarge-33 small-100 tiny-100">
							<div class="obj-right-div">
								<div class="obj-flag">
									<a href="<?php echo $m_url; ?>"><i class="flag-icon flag-icon-<?php echo esc_attr( $m_flag ); ?>" title="<?php echo esc_attr( $m_label ); ?>"></i></a>
								</div>
								<div class="obj-detail">
									<div class="obj-name"><a href="<?php echo $m_url; ?>"><?php echo esc_html( $m_label ); ?></a></div>
									<div class="obj-link">
										<?php echo esc_html( $m_count ); ?> equipa<?php echo esc_html( $plural ); ?>
									</div>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<?php if ( ! empty( $teams ) ) : ?>
					<div class="column-group half-gutters">
						<?php foreach ( $teams as $team ) :
							$team_url   = esc_url( home_url( '/equipa/' . intval( $team->id ) . '/' ) );
							$logo_url   = '/media/images/logotipo/100x100/' . intval( $team->id ) . '.gif';
							$found_year = ! empty( $team->foundation ) ? mysql2date( 'Y', $team->foundation ) : '';
							
							$city_truncated = ( mb_strlen( $team->city ) > 28 ) ? mb_substr( $team->city, 0, 25 ) . '...' : $team->city;
							
							$fallback_img = esc_url( futebol365_fallback_image() );
						?>
							<div class="all-50 xlarge-33 small-100 tiny-100">
								<div class="obj-right-div">
									<div class="obj-image-team">
										<figure class="ink-image ink-lazyload" data-delta="100">
											<a href="<?php echo $team_url; ?>">
												<img src="<?php echo esc_url( home_url( $logo_url ) ); ?>"
													alt="<?php echo esc_attr( $team->shortname ); ?>" 
													title="<?php echo esc_attr( $team->shortname ); ?>" 
													onerror="this.onerror=null; this.src='<?php echo $fallback_img; ?>';"
												/>
											</a>
										</figure>
									</div>
									<div class="obj-detail">
										<div class="obj-name"><a href="<?php echo $team_url; ?>"><?php echo esc_html( $team->shortname ); ?></a></div>
										<div class="obj-link">
											<?php echo esc_html( $city_truncated ); ?>
										</div>
										<div class="obj-link">
											<?php if ( ! empty( $found_year ) ) : ?>
												<?php esc_html_e( 'Fundação:', 'futebol365' ); ?> <?php echo esc_html( $found_year ); ?>
											<?php endif; ?>
											<?php if ( ! empty( $team->extinction ) && '0000-00-00' !== $team->extinction ) : ?>
												| <i class="font-red"><?php esc_html_e( 'Extinto', 'futebol365' ); ?></i>
											<?php endif; ?>
										</div>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>

					<?php //if ( $total_pages > 1 ) : ?>
						
					<?php 
					
					if ( isset( $cur_page, $total_pages ) && function_exists( 'futebol365_render_pagination' ) ):
						futebol365_render_pagination( $cur_page, $total_pages );
					endif;
					?>
					
				<?php else : ?>
					<h5 class="no-info"><?php esc_html_e( 'Sem informação disponível', 'futebol365' ); ?></h5>
				<?php endif; ?>
			<?php endif; ?>

			<?php get_template_part('template-parts/template-front-page-posts'); ?>
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