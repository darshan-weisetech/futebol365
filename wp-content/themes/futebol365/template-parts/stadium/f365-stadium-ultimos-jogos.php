<?php
/**
 * Recent Games
 *
 * @package Futebol365
 */
$post_id = $args['post_id'];
$posts_per_page = $args['posts_per_page'] ?? 15;

?>
<section class="stadium-games">

	<h2 class="condensed-400 headerTitle black">
		<?php esc_html_e( 'Últimos Jogos', 'f365' ); ?>
		<span class="headerTitle-border"> </span>
	</h2>

	<?php
	$stadium_id = get_post_meta( $post_id, 'django_stadium_id', true );
	
	// FIX: Read $_GET['pagina'] directly because custom query variables are not registered in WP
	$current_page = 1;
	if ( isset( $_GET['pagina'] ) ) {
		$current_page = intval( $_GET['pagina'] );
	} elseif ( get_query_var( 'pagina' ) ) {
		$current_page = intval( get_query_var( 'pagina' ) );
	} elseif ( get_query_var( 'paged' ) ) {
		$current_page = intval( get_query_var( 'paged' ) );
	}
	$current_page = max( 1, $current_page );

	$limit = (int) $posts_per_page;
	$offset       = ( $current_page - 1 ) * $limit;

	// 1. Fetch matches
	$matches = f365_get_stadium_matches1( $stadium_id, 'last', $limit, $offset );

	// 2. Fetch total count from custom tables
	$total_matches = f365_get_stadium_matches_count1( $stadium_id, 'last' );
	$total_pages   = ceil( $total_matches / $limit );

	if ( ! empty( $matches ) ) : ?>
		<table class="ink-table ink-table-f365 alternating all-100">
			<thead>
				<tr>
					<th class="all-10 small-15 tiny-15 align-left">
						<?php esc_html_e( 'Data', 'f365' ); ?>
					</th>
					<th class="all-5 hide-tiny hide-small align-center">
						<?php esc_html_e( 'Hora', 'f365' ); ?>
					</th>
					<th class="all-30 align-right hide-tiny hide-small">
						<?php esc_html_e( 'Visitado', 'f365' ); ?>
					</th>
					<th class="all-45 align-left hide-medium hide-large hide-xlarge">
						<?php esc_html_e( 'Jogo', 'f365' ); ?>
					</th>
					<th class="all-10 small-20 tiny-20"></th>
					<th class="all-30 align-left hide-tiny hide-small">
						<?php esc_html_e( 'Visitante', 'f365' ); ?>
					</th>
					<th class="all-15 small-20 tiny-20"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $matches as $match ) : ?>
					<tr>
						<!-- Date & Time Column -->
						<td class="align-left cell-nowrap">
							<a href="<?php echo esc_url( $match['competition_url'] ); ?>">
								<?php echo esc_html( $match['date'] ); ?>
								<span class="hide-xlarge hide-large hide-medium break">
									<?php echo esc_html( $match['time'] ); ?>
								</span>
							</a>
						</td>

						<!-- Time Only Column -->
						<td class="align-center hide-tiny hide-small">
							<?php echo esc_html( $match['time'] ); ?>
						</td>

						<!-- Home Team (Desktop) Column -->
						<td class="align-right hide-tiny hide-small">
							<a href="<?php echo esc_url( $match['home_team_url'] ); ?>" <?php echo $match['home_winner'] ? 'class="teamWinner"' : ''; ?>>
								<?php echo esc_html( $match['home_team_name'] ); ?>
							</a>
						</td>

						<!-- Home & Visitor Stacked (Mobile) Column -->
						<td class="align-left hide-xlarge hide-large hide-medium">
							<a href="<?php echo esc_url( $match['home_team_url'] ); ?>" <?php echo $match['home_winner'] ? 'class="teamWinner"' : ''; ?>>
								<?php echo esc_html( $match['home_team_name'] ); ?>
							</a>
							<span class="break">
								<a href="<?php echo esc_url( $match['visitor_team_url'] ); ?>" <?php echo $match['visitor_winner'] ? 'class="teamWinner"' : ''; ?>>
									<?php echo esc_html( $match['visitor_team_name'] ); ?>
								</a>
							</span>
						</td>

						<!-- Score / VS Column -->
						<td class="align-center">
							<span class="ink-label ink-label-score silver">
								<a href="<?php echo esc_url( $match['match_url'] ); ?>">
									<?php echo esc_html( $match['score'] ); ?>
								</a>
							</span>
						</td>

						<!-- Visitor Team (Desktop) Column -->
						<td class="align-left hide-tiny hide-small">
							<a href="<?php echo esc_url( $match['visitor_team_url'] ); ?>" <?php echo $match['visitor_winner'] ? 'class="teamWinner"' : ''; ?>>
								<?php echo esc_html( $match['visitor_team_name'] ); ?>
							</a>
						</td>

						<!-- Actions Column (Compare (C) & Affiliate Betting (A)) -->
						<td class="align-left">
							<?php if ( ! empty( $match['compare_url'] ) ) : ?>
								<a href="<?php echo esc_url( $match['compare_url'] ); ?>">
									<span class="ink-tooltip ink-button-related green" data-tip-text="<?php esc_attr_e( 'Comparar equipas', 'f365' ); ?>" data-tip-where="up" data-tip-color="green">C</span>
								</a>
							<?php endif; ?>

							<?php
							// Affiliate Betting Action (A)
							$time_now = time();
							$match_time = strtotime( $match['datetime'] );
							$lower_time = $match_time - ( 3 * 24 * 60 * 60 ); // 3 days before
							$time_ago   = $match_time + 6300; // 105 mins after kick-off
							
							$is_available = ( $match['situation'] !== 'finished' );

							if ( $is_available && $time_now > $lower_time && $time_now < $time_ago ) :
								$betting_url = 'https://www.futebol365.pt/goto/';
								?>
								<a href="<?php echo esc_url( $betting_url ); ?>" target="_blank">
									<span class="ink-tooltip ink-button-related orange" data-tip-text="A" data-tip-where="up" data-tip-color="orange">A</span>
								</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<!-- Pagination links matching WP standards -->
		<?php if ( empty( $args['hide_pagination'] ) && $total_pages > 1 ) : ?>
			<nav class="ink-navigation pagination-stadium" style="margin-top: 20px; text-align: center;">
				<?php
				// FIX: Clean the base URL of any existing 'pagina' parameter before constructing new ones
				$pagination_base = remove_query_arg( 'pagina', html_entity_decode( get_pagenum_link( 1 ) ) );
				$pagination_base = add_query_arg( 'pagina', '%#%', $pagination_base );

				echo futebol365_render_pagination( $current_page, $total_pages );
				?>
			</nav>
		<?php endif; ?>


	<?php else : ?>
		<h5 class="no-info">
			<?php esc_html_e( 'Sem informação disponível', 'f365' ); ?>
		</h5>
	<?php endif; ?>	
</section>