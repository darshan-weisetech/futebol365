<?php
/**
 * Ranking Clubes helpers.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'F365_RANKING_CLUBES_TERM_ID' ) ) {
	define( 'F365_RANKING_CLUBES_TERM_ID', 28767 );
}

if ( ! function_exists( 'f365_rankings_clubes_table' ) ) {
	function f365_rankings_clubes_table() {
		global $wpdb;

		return $wpdb->prefix . 'ranking_clubranking';
	}
}

if ( ! function_exists( 'f365_rankings_clubes_cache_key' ) ) {
	function f365_rankings_clubes_cache_key( $suffix, array $args = array() ) {
		return 'clubes_' . $suffix . '_' . md5( wp_json_encode( $args ) );
	}
}

if ( ! function_exists( 'f365_rankings_clubes_default_period' ) ) {
	function f365_rankings_clubes_default_period() {
		global $wpdb;

		$cache_key = 'clubes_default_period';
		$period    = wp_cache_get( $cache_key, 'f365_rankings' );

		if ( false !== $period ) {
			return $period;
		}

		$table = f365_rankings_clubes_table();
		// Latest period in ONE query (highest year, then highest month of that year).
		$row = $wpdb->get_row( "SELECT year, month FROM {$table} ORDER BY year DESC, month DESC LIMIT 1" );

		$period = $row
			? array( 'year' => (int) $row->year, 'month' => (int) $row->month )
			: array( 'year' => 0, 'month' => 0 );

		wp_cache_set( $cache_key, $period, 'f365_rankings', 15 * MINUTE_IN_SECONDS );
		return $period;
	}
}

if ( ! function_exists( 'f365_rankings_clubes_period_options' ) ) {
	function f365_rankings_clubes_period_options() {
		$cache_key = 'clubes_period_options_v2';
		$options   = wp_cache_get( $cache_key, 'f365_rankings' );

		if ( false !== $options ) {
			return $options;
		}

		$options = array(
			'years'  => range( 2007, (int) gmdate( 'Y' ) ),
			'months' => range( 1, 12 ),
		);

		wp_cache_set( $cache_key, $options, 'f365_rankings', 12 * HOUR_IN_SECONDS );

		return $options;
	}
}

if ( ! function_exists( 'f365_rankings_clubes_country_terms' ) ) {
	function f365_rankings_clubes_country_terms() {
		$cache_key = 'clubes_country_terms_v2';
		$countries = wp_cache_get( $cache_key, 'f365_rankings' );

		if ( false !== $countries ) {
			return $countries;
		}

		$countries = get_terms(
			array(
				'taxonomy'   => 'f365_country',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		$countries = is_wp_error( $countries ) ? array() : $countries;
		wp_cache_set( $cache_key, $countries, 'f365_rankings', 12 * HOUR_IN_SECONDS );

		return $countries;
	}
}

if ( ! function_exists( 'f365_rankings_clubes_query' ) ) {
	function f365_rankings_clubes_query( array $args ) {
		global $wpdb;

		$defaults = array(
			'country' => 0,
			'month'   => 0,
			'year'    => 0,
			'order'   => '',
			'paged'   => 1,
			'limit'   => 20,
		);
		$args = wp_parse_args( $args, $defaults );

		$cache_key = f365_rankings_clubes_cache_key( 'query', $args );
		$cached = wp_cache_get( $cache_key, 'f365_rankings' );

		if ( false !== $cached ) {
			return $cached;
		}

		$table = f365_rankings_clubes_table();
		$team  = $wpdb->prefix . 'team_team';
		$where = array( 'r.month = %d', 'r.year = %d' );
		$params = array( (int) $args['month'], (int) $args['year'] );

		// All display data comes from the source team table (no wp_posts / taxonomy joins).
		$joins = " INNER JOIN {$team} t ON t.id = r.team_id ";

		// Country filter on the SOURCE country id; the dropdown still submits an
		// f365_country term id, so translate it once via its 'django_country_id' meta.
		if ( (int) $args['country'] ) {
			$legacy_country = (int) get_term_meta( (int) $args['country'], 'django_country_id', true );
			$where[]  = 't.country_id = %d';
			$params[] = $legacy_country;
		}

		if ( 'variation' === $args['order'] ) {
			$where[] = 'r.variation < 0';
			$orderby = 'r.variation ASC';
		} elseif ( '-variation' === $args['order'] ) {
			$where[] = 'r.variation > 0';
			$orderby = 'r.variation DESC';
		} else {
			$orderby = 'r.position ASC';
		}

		$where_sql = implode( ' AND ', $where );
		$offset = max( 0, ( (int) $args['paged'] - 1 ) * (int) $args['limit'] );

		$count_sql = "SELECT COUNT(*) FROM {$table} r {$joins} WHERE {$where_sql}";
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

		if ( ! $total ) {
			$result = array( 'rows' => array(), 'total' => 0, 'total_pages' => 0 );
			wp_cache_set( $cache_key, $result, 'f365_rankings', 12 * HOUR_IN_SECONDS  );
			return $result;
		}

		if ( $offset >= $total ) {
			$offset = max( 0, ( (int) ceil( $total / (int) $args['limit'] ) - 1 ) * (int) $args['limit'] );
		}

		$select_sql = "
			SELECT
				r.id,
				r.position,
				r.points,
				r.variation,
				r.coefficient,
				r.team_id,
				t.shortname  AS team_name,
				t.country_id AS legacy_country_id
			FROM {$table} r
			{$joins}
			WHERE {$where_sql}
			ORDER BY {$orderby}
			LIMIT %d OFFSET %d
		";

		$query_params = array_merge( $params, array( (int) $args['limit'], $offset ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $select_sql, $query_params ) );
		$result = array(
			'rows'        => $rows,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / (int) $args['limit'] ),
		);

		wp_cache_set( $cache_key, $result, 'f365_rankings', 15 * MINUTE_IN_SECONDS );
		return $result;
	}
}

if ( ! function_exists( 'f365_rankings_clubes_variation_icon' ) ) {
	function f365_rankings_clubes_variation_icon( $variation ) {
		$variation = (int) $variation;

		if ( $variation > 0 ) {
			return array( 'ranking-up', 'icon-chevron-up' );
		}

		if ( $variation < 0 ) {
			return array( 'ranking-down', 'icon-chevron-down' );
		}

		return array( '', '' );
	}
}

if ( ! function_exists( 'f365_rankings_render_clubes' ) ) {
	function f365_rankings_render_clubes() {
		$base_url = home_url( '/rank-clubes/' );
		$order = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : '';
		$order = in_array( $order, array( 'variation', '-variation' ), true ) ? $order : '';
		$country = isset( $_GET['country'] ) ? absint( $_GET['country'] ) : 0;
		$month = isset( $_GET['ranking_month'] ) ? absint( $_GET['ranking_month'] ) : 0;
		$month = isset( $_GET['month'] ) && ! $month ? absint( $_GET['month'] ) : $month;
		$year = isset( $_GET['ranking_year'] ) ? absint( $_GET['ranking_year'] ) : 0;
		$year = isset( $_GET['year'] ) && ! $year ? absint( $_GET['year'] ) : $year;
		$paged = isset( $_GET['pagina'] ) ? max( 1, absint( $_GET['pagina'] ) ) : 1;
		$posts_per_page = 20;

		$period = f365_rankings_clubes_default_period();
		$month = $month ? $month : (int) $period['month'];
		$year = $year ? $year : (int) $period['year'];

		if ( ! $month || ! $year ) {
			return '<div class="f365-ranking-page f365-ranking-fifa"><p class="f365-ranking-empty">' . esc_html__( 'Ranking indisponivel.', 'futebol365' ) . '</p></div>';
		}

		$query_args = array();
		if ( $country ) {
			$query_args['country'] = $country;
		}
		if ( $month ) {
			$query_args['ranking_month'] = $month;
		}
		if ( $year ) {
			$query_args['ranking_year'] = $year;
		}

		$url_base = add_query_arg( $query_args, $base_url );
		$url_subidas = add_query_arg( 'order', '-variation', $url_base );
		$url_descidas = add_query_arg( 'order', 'variation', $url_base );

		$countries = f365_rankings_clubes_country_terms();
		$period_options = f365_rankings_clubes_period_options();
		$ranking_data = f365_rankings_clubes_query(
			array(
				'country' => $country,
				'month'   => $month,
				'year'    => $year,
				'order'   => $order,
				'paged'   => $paged,
				'limit'   => $posts_per_page,
			)
		);

		$months_pt = array(
			1  => 'Janeiro',
			2  => 'Fevereiro',
			3  => 'Março',
			4  => 'Abril',
			5  => 'Maio',
			6  => 'Junho',
			7  => 'Julho',
			8  => 'Agosto',
			9  => 'Setembro',
			10 => 'Outubro',
			11 => 'Novembro',
			12 => 'Dezembro',
		);

		ob_start();
		?>
		<script type="text/javascript">
			jQuery(document).ready(function ($) {
				$('#id_country, #id_month, #id_year').change(function () {
					var form = $('#ranking-form');
					form.find('input[name="pagina"]').remove();
					form.submit();
				});
			});
		</script>
		<div class="ink-grid vertical-space f365-ranking-page f365-ranking-clubes">
			<?php get_template_part( 'template-parts/template-offer-widget' ); ?>
			<div class="ink-header-f365 rankings">
				<div class="column-group">
					<div class="align-left all-60 medium-100 small-100 tiny-100 banner-left-content">
						<div class="headerdata">
							<h1 class="multimedia-title">
								<?php esc_html_e( 'Ranking Clubes', 'futebol365' ); ?>
							</h1>
						</div>
					</div>
				</div>
			</div>
			<div class="nav-trigger" id="nav-trigger">
				<span>Menu</span>
			</div>
			<nav class="nav-main" id="nav-main">
				<ul>
					<li><a class="<?php echo ( 'variation' !== $order && '-variation' !== $order ) ? 'active' : ''; ?>"
							href="<?php echo esc_url( $base_url ); ?>">Ranking Atual</a></li>
					<li><a class="<?php echo ( '-variation' === $order ) ? 'active' : ''; ?>"
							href="<?php echo esc_url( $url_subidas ); ?>">Maiores Subidas</a></li>
					<li><a class="<?php echo ( 'variation' === $order ) ? 'active' : ''; ?>"
							href="<?php echo esc_url( $url_descidas ); ?>">Maiores Descidas</a></li>
					<?php if ( 'variation' === $order || '-variation' === $order ) : ?>
						<li><a href="<?php echo esc_url( $url_base ); ?>">Ranking Completo</a></li>
					<?php endif; ?>
				</ul>
			</nav>
			<nav class="nav-mobile" id="nav-mobile"></nav>
		</div>

		<div class="ink-grid futebol365-container">
			<section class="left-column-block">
				<div class="breadcrumbs-f365">
					<?php if ( function_exists( 'yoast_breadcrumb' ) ) : ?>
						<nav class="ink-navigation breadcrumbs">
							<?php yoast_breadcrumb(); ?>
						</nav>
					<?php endif; ?>
				</div>

				<form class="ink-form ink-form-option" method="get" name="ranking_form" id="ranking-form"
					action="<?php echo esc_url( $base_url ); ?>">
					<div class="column-group">
						<div class="control-group all-33 tiny-100 small-100">
							<div class="control">
								<select name="country" id="id_country">
									<option value="">País</option>
									<?php foreach ( $countries as $c ) : ?>
										<option value="<?php echo esc_attr( $c->term_id ); ?>" <?php selected( $country, (int) $c->term_id ); ?>>
											<?php echo esc_html( $c->name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						<div class="control-group all-33 tiny-100 small-100">
							<div class="control">
								<select name="ranking_month" id="id_month">
									<?php foreach ( $period_options['months'] as $m ) : ?>
										<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $month, (int) $m ); ?>>
											<?php echo esc_html( $months_pt[ (int) $m ] ?? $m ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						<div class="control-group all-33 tiny-100 small-100">
							<div class="control">
								<select name="ranking_year" id="id_year">
									<?php foreach ( $period_options['years'] as $y ) : ?>
										<option value="<?php echo esc_attr( $y ); ?>" <?php selected( $year, (int) $y ); ?>>
											<?php echo esc_html( $y ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
					</div>
					<?php if ( $order ) : ?>
						<input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>">
					<?php endif; ?>
				</form>

				<?php if ( ! empty( $ranking_data['rows'] ) ) : ?>
					<table class="ink-table alternating ink-table-f365">
						<thead>
							<tr>
								<th class="align-left">Posição</th>
								<th class="align-left">Equipa</th>
								<th class="align-left hide-medium hide-small hide-tiny">Coeficiente</th>
								<th class="align-left">Pontos</th>
								<th colspan="2" class="align-left hide-small hide-tiny">Variação</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $ranking_data['rows'] as $row ) : ?>
								<?php
								list( $variation_icon_class, $variation_icon ) = f365_rankings_clubes_variation_icon( $row->variation );
								$team_permalink = $row->team_id ? user_trailingslashit( home_url( 'equipa/' . (int) $row->team_id ) ) : '#';
								$flag_class = $row->legacy_country_id ? ' flag-icon-' . absint( $row->legacy_country_id ) : '';
								?>
								<tr>
									<td><?php echo esc_html( $row->position ); ?></td>
									<td>
										<i class="hide-tiny flag-icon quarter-right-space<?php echo esc_attr( $flag_class ); ?>"></i>
										<a href="<?php echo esc_url( $team_permalink ); ?>">
											<?php echo esc_html( $row->team_name ); ?>
										</a>
									</td>
									<td class="hide-medium hide-small hide-tiny"><?php echo esc_html( $row->coefficient ); ?></td>
									<td><?php echo esc_html( $row->points ); ?></td>
									<td class="hide-small hide-tiny"><?php echo esc_html( $row->variation ); ?></td>
									<td class="hide-small hide-tiny">
										<?php if ( $variation_icon ) : ?>
											<svg class="ranking-variation-icon <?php echo esc_attr( $variation_icon_class ); ?>" width="14"
												height="14" aria-hidden="true" focusable="false">
												<use href="#<?php echo esc_attr( $variation_icon ); ?>"></use>
											</svg>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<?php if ( $ranking_data['total_pages'] > 1 ) : ?>
						<?php
						$pagination_base_args = array();
						if ( $country ) {
							$pagination_base_args['country'] = $country;
						}
						if ( $month ) {
							$pagination_base_args['ranking_month'] = $month;
						}
						if ( $year ) {
							$pagination_base_args['ranking_year'] = $year;
						}
						if ( $order ) {
							$pagination_base_args['order'] = $order;
						}

						$pagination_base = add_query_arg( $pagination_base_args, $base_url );
						$pages = paginate_links(
							array(
								'base'      => add_query_arg( 'pagina', '%#%', $pagination_base ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $ranking_data['total_pages'],
								'type'      => 'array',
								'prev_text' => '« Anterior',
								'next_text' => 'Seguinte »',
								'mid_size'  => 1,
								'end_size'  => 0,
							)
						);
						?>
						<?php if ( is_array( $pages ) ) : ?>
							<div class="all-100">
								<div class="paginacao-new">
									<nav class="ink-navigation">
										<ul class="pagination">
											<?php foreach ( $pages as $page ) : ?>
												<?php
												$page = str_replace(
													array( 'current', 'page-numbers' ),
													array( 'active', '' ),
													$page
												);
												?>
												<?php if ( false !== strpos( $page, 'Seguinte' ) || false !== strpos( $page, 'Anterior' ) ) : ?>
													<li class="hide-tiny hide-small"><?php echo wp_kses_post( $page ); ?></li>
												<?php else : ?>
													<li><?php echo wp_kses_post( $page ); ?></li>
												<?php endif; ?>
											<?php endforeach; ?>
										</ul>
									</nav>
								</div>
							</div>
						<?php endif; ?>
					<?php endif; ?>
				<?php else : ?>
					<h5 class="no-info">Sem informação disponível</h5>
				<?php endif; ?>

				<?php get_template_part( 'template-parts/template-front-page-posts' ); ?>
			</section>
			<section class="right-column-block">
				<?php get_sidebar(); ?>
			</section>
		</div>
		<?php

		return ob_get_clean();
	}
}