<?php
/**
 * Live Streaming — shared status tabs + row renderer.
 *
 * Mirrors live/live_streaming_base.html navigation and the table row used by
 * live_streaming_homepage.html / live_streaming_by_status.html / live_streaming_list.html.
 *
 * Loaded by the homepage, by-status and (indirectly) detail templates. The
 * helper functions are guarded with function_exists() so the partial is safe to
 * include more than once per request.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'f365_live_streaming_tabs' ) ) {
	function f365_live_streaming_tabs() {

		global $wp;

		$current_path = trim( $wp->request, '/' );

		$active = 'homepage';

		if ( strpos( $current_path, 'live-streaming/inlive' ) !== false ) {
			$active = 'inlive';
		} elseif ( strpos( $current_path, 'live-streaming/tostart' ) !== false ) {
			$active = 'tostart';
		} elseif ( strpos( $current_path, 'live-streaming/finished' ) !== false ) {
			$active = 'finished';
		}

		$tabs = array(
			'homepage' => array(
				'label' => __( 'Inicio', 'futebol365' ),
				'url'   => home_url( '/live-streaming/' ),
			),
			'inlive' => array(
				'label' => __( 'Em direto', 'futebol365' ),
				'url'   => home_url( '/live-streaming/inlive/' ),
			),
			'tostart' => array(
				'label' => __( 'Brevemente', 'futebol365' ),
				'url'   => home_url( '/live-streaming/tostart/' ),
			),
			'finished' => array(
				'label' => __( 'Mais recentes', 'futebol365' ),
				'url'   => home_url( '/live-streaming/finished/' ),
			),
		);
		?>
		<nav class="nav-main" id="nav-main">
			<ul>
				<?php foreach ( $tabs as $key => $tab ) : ?>
					<li>
						<a href="<?php echo esc_url( $tab['url'] ); ?>"
						   class="<?php echo ( $active === $key ) ? 'active' : ''; ?>">
							<?php echo esc_html( $tab['label'] ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<?php
	}
}
if ( ! function_exists( 'f365_live_streaming_table' ) ) {
	/**
	 * Render a results table for a WP_Query of f365_livestream posts.
	 */
	function f365_live_streaming_table( WP_Query $q ): void {

		if ( ! $q->have_posts() ) {
			echo '<h6 class="no-info">Sem informação disponível</h6>';
			return;
		}
		?>

		<div class="overflow-auto">
			<table class="ink-table ink-table-f365 alternating all-100">
				<thead>
					<tr>
						<th class="all-10 small-15 tiny-15 align-left">Data</th>
						<th class="all-10 hide-tiny hide-small align-center">Hora</th>
						<th class="all-30 align-right hide-tiny hide-small">Visitado</th>
						<th class="all-45 align-left hide-medium hide-large hide-xlarge">Jogo</th>
						<th class="all-10 small-20 tiny-20"></th>
						<th class="all-30 align-left hide-tiny hide-small">Visitante</th>
						<th class="all-15 small-20 tiny-20"></th>
					</tr>
				</thead>

				<tbody>
					<?php
					while ( $q->have_posts() ) {
						$q->the_post();

						// Render individual row
						f365_live_streaming_row( get_the_ID() );
					}
					wp_reset_postdata();
					?>
				</tbody>
			</table>
		</div>

		<?php
	}
}

if ( ! function_exists( 'f365_live_streaming_row' ) ) {
	function f365_live_streaming_row( int $post_id ): void {

		$home_team  = get_field( 'home_team_name', $post_id );
		$away_team  = get_field( 'visitor_team_name', $post_id );

		$home_score = get_field( 'home_score', $post_id );
		$away_score = get_field( 'visitor_score', $post_id );

		$match_datetime = get_field( 'match_datetime', $post_id );

		$watch_url = f365_live_streaming_url( $post_id );

		$date = '—';
		$time = '';

		if ( ! empty( $match_datetime ) ) {
			$timestamp = strtotime( $match_datetime );

			$date = date_i18n( 'd-m-y', $timestamp );
			$time = date_i18n( 'H:i', $timestamp );
		}

		$home_class = '';
		$away_class = '';

		if ( is_numeric( $home_score ) && is_numeric( $away_score ) ) {

			if ( (int) $home_score > (int) $away_score ) {
				$home_class = 'teamWinner';
			}

			if ( (int) $away_score > (int) $home_score ) {
				$away_class = 'teamWinner';
			}
		}

		?>
		<tr>

			<td class="align-left cell-nowrap">
				<a href="<?php echo esc_url( $watch_url ); ?>" class="<?php echo esc_attr( $away_class ); ?>">
					<?php echo esc_html( $date ); ?>

					<span class="hide-xlarge hide-large hide-medium break">
						<?php echo esc_html( $time ); ?>
					</span>
				</a>
			</td>

			<td class="align-center hide-tiny hide-small">
				<?php echo esc_html( $time ); ?>
			</td>

			<td class="align-right hide-tiny hide-small">
				<a href="<?php echo esc_url( $watch_url ); ?>" class="<?php echo esc_attr( $home_class ); ?>">
					<?php echo esc_html( $home_team ); ?>
				</a>
			</td>

			<td class="align-left hide-xlarge hide-large hide-medium">
				<a href="<?php echo esc_url( $watch_url ); ?>" class="<?php echo esc_attr( $home_class ); ?>">
					<?php echo esc_html( $home_team ); ?>
				</a>

				<span class="break">
					<a href="<?php echo esc_url( $watch_url ); ?>" class="<?php echo esc_attr( $away_class ); ?>">
						<?php echo esc_html( $away_team ); ?>
					</a>
				</span>
			</td>

			<td class="align-center">
				<span class="ink-label ink-label-score silver">
					<a href="<?php echo esc_url( $watch_url ); ?>" class="<?php echo esc_attr( $away_class ); ?>">

						<?php
						if ( $home_score !== '' && $away_score !== '' ) {
							echo esc_html( $home_score . ' - ' . $away_score );
						} else {
							echo '-';
						}
						?>

					</a>
				</span>
			</td>

			<td class="align-left hide-tiny hide-small">
				<a href="<?php echo esc_url( $watch_url ); ?>" class="<?php echo esc_attr( $away_class ); ?>">
					<?php echo esc_html( $away_team ); ?>
				</a>
			</td>

			<td class="align-left">
				<a href="<?php echo esc_url( $watch_url ); ?>" class="<?php echo esc_attr( $away_class ); ?>">
					<span class="ink-tooltip ink-button-related red">
						ASSISTIR
					</span>
				</a>
			</td>
		</tr>
		<?php
	}
}