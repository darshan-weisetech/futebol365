<?php
/**
 * Player Rankings — Single Template (f365-ranking-player.php)
 *
 * Self-contained: ALL data is fetched from the custom tables here, upfront, in
 * a small fixed number of queries (type meta + count + entries + one batched
 * team-name lookup). NOTHING is queried inside the render loops. No WordPress
 * post-id resolution, no get_permalink, no term lookups — player/nav URLs are
 * built from the legacy ids exactly like the source:
 *     player → /jogador/{player_id}/          (reverse 'player-resume')
 *     nav    → /rank-jogadores/{type_id}/      (reverse 'players-ranks-list')
 * Team is plain text (source renders team_as_player_on_edition with no link).
 *
 * Route: /rank-jogadores/{id}/ — the rewrite sets f365_ranking_subtype={id}
 * (the legacy PlayerRankingType id), read directly from the query var.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

get_header();

global $wpdb;

$per_page = 20;

$type_id = (int) get_query_var( 'f365_ranking_subtype' );
if ( $type_id <= 0 ) {
	$q = get_queried_object();
	$type_id = ( $q instanceof WP_Term ) ? (int) $q->slug : 0;
}

$page = isset( $_GET['pagina'] ) ? max( 1, (int) $_GET['pagina'] ) : 1; 

// -- Table names -------------------------------------------------------------
$T_TYPE   = $wpdb->prefix . 'ranking_playerrankingtype';
$T_RANK   = $wpdb->prefix . 'ranking_playerranking';
$T_PLAYER = $wpdb->prefix . 'person_player';
$T_PERS   = $wpdb->prefix . 'person_person';
$T_SQUAD  = $wpdb->prefix . 'team_squad';
$T_TS     = $wpdb->prefix . 'team_teamstep';
$T_TEAM   = $wpdb->prefix . 'team_team';

$tables_ok = function_exists( 'f365_src_table_exists' )
	&& f365_src_table_exists( $T_TYPE )
	&& f365_src_table_exists( $T_RANK );

// -- Ranking type meta (cached) ---------------------------------------------
$rank_name   = '';
$points_desc = '';
if ( $type_id > 0 && $tables_ok ) {
	$type_key  = 'f365_prank_type_' . $type_id;
	$type_meta = wp_cache_get( $type_key, 'f365' );
	if ( ! is_array( $type_meta ) ) {
		$type_row  = $wpdb->get_row( $wpdb->prepare(
			"SELECT shortname, longname, points_description FROM {$T_TYPE} WHERE id = %d LIMIT 1",
			$type_id
		), ARRAY_A );
		$type_meta = is_array( $type_row ) ? $type_row : [];
		wp_cache_set( $type_key, $type_meta, 'f365', HOUR_IN_SECONDS );
	}
	$rank_name = (string) ( $type_meta['shortname'] ?? '' );
	if ( $rank_name === '' ) {
		$rank_name = (string) ( $type_meta['longname'] ?? '' );
	}
	$points_desc = (string) ( $type_meta['points_description'] ?? '' );
}
if ( $rank_name === '' ) {
	$rank_name = __( 'Ranking de Jogadores', 'futebol365' );
}
$show_points = ( $points_desc !== '' );

// -- ALL winning entries in ONE query, processed once and cached -------------
$all = [];
if ( $type_id > 0 && $tables_ok ) {
	$rows_key = 'f365_prank_rows_' . $type_id;
	$cached   = wp_cache_get( $rows_key, 'f365' );

	if ( is_array( $cached ) ) {
		$all = $cached;
	} else {
		$has_team = f365_src_table_exists( $T_SQUAD )
			&& f365_src_table_exists( $T_TS )
			&& f365_src_table_exists( $T_TEAM );

		$team_select = $has_team
			? "( SELECT t.shortname
			       FROM {$T_SQUAD} sq
			       JOIN {$T_TS}   ts ON ts.id = sq.team_id
			       JOIN {$T_TEAM} t  ON t.id  = ts.team_id AND t.team_type = 'club'
			      WHERE sq.player_id = r.player_id AND sq.edition = r.edition
			      ORDER BY COALESCE( sq.`order`, 99999 ) ASC, t.id ASC
			      LIMIT 1 )"
			: "NULL";

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT r.player_id      AS player_id,
			        r.edition         AS edition,
			        r.season          AS season,
			        r.points          AS points,
			        pe.shortname      AS player_name,
			        pe.nationality_id AS nationality_id,
			        {$team_select}    AS team_name
			   FROM {$T_RANK} r
			   LEFT JOIN {$T_PLAYER} pl ON pl.id = r.player_id
			   LEFT JOIN {$T_PERS}   pe ON pe.id = pl.person_id
			  WHERE r.ranking_type_id = %d AND r.position = 1
			  ORDER BY r.edition DESC, r.id DESC",
			$type_id
		), ARRAY_A );

		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$pid = (int) $r['player_id'];
				$nat = (int) ( $r['nationality_id'] ?? 0 );
				$all[] = [
					'season'      => (string) ( $r['season'] ?? '' ),
					'player_name' => (string) ( $r['player_name'] ?? '' ),
					'player_url'  => $pid > 0 ? home_url( '/jogador/' . $pid . '/' ) : '',
					'flag_id'     => $nat,
					'team_name'   => (string) ( $r['team_name'] ?? '' ),
					'points'      => (int) ( $r['points'] ?? 0 ),
				];
			}
		}
		wp_cache_set( $rows_key, $all, 'f365', 12 * HOUR_IN_SECONDS );
	}
}

// -- Pagination: count + slice in PHP (no count query) -----------------------
$total       = count( $all );
$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;
if ( $total_pages > 0 && $page > $total_pages ) {
	$page = $total_pages;
}
$rows = $total > 0 ? array_slice( $all, ( $page - 1 ) * $per_page, $per_page ) : [];

// -- Headline nav (no queries, no term lookups): /rank-jogadores/{id}/ --------
$nav_source = [
	1  => 'Bola de Ouro',
	4  => 'The Best FIFA',
	6  => 'Bota de Ouro',
	7  => 'World Soccer',
	13 => 'Golden Boy',
	15 => 'Melhor Jogador na Europa',
];
?>

<main id="primary" class="site-main single-article-page ranking-page ranking-player-page">

	<div class="ink-grid vertical-space">
		<?php get_template_part( 'template-parts/template-offer-widget' ); ?>

		<?php // -- Header: title ------------------------------------------- ?>
		<div class="ink-header-f365 rankings">
			<div class="column-group">
				<div class="align-left">
					<div class="headerdata">
						<h1><?php echo esc_html( $rank_name ); ?></h1>
					</div>
				</div>
			</div>
		</div>

		<div class="nav-trigger" id="nav-trigger">
			<span>Menu</span>
		</div>

		<?php // -- Header: headline-awards nav ----------------------------- ?>
		<nav class="nav-main ranking-nav-main" id="nav-main" aria-label="<?php echo esc_attr__( 'Rankings de Jogadores', 'futebol365' ); ?>">
			<ul>
				<?php foreach ( $nav_source as $nav_id => $nav_label ) : ?>
					<li>
						<a href="<?php echo esc_url( home_url( '/rank-jogadores/' . (int) $nav_id . '/' ) ); ?>"
						   <?php echo ( (int) $nav_id === $type_id ) ? 'class="active" aria-current="page"' : ''; ?>>
							<?php echo esc_html( $nav_label ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
	</div>

	<div class="ink-grid futebol365-container">

		<section class="left-column-block">

			<?php // ── Breadcrumbs ──────────────────────────────────────── ?>
			<div class="breadcrumbs-f365">
			<?php if ( function_exists( 'yoast_breadcrumb' ) ) : ?>
				<?php yoast_breadcrumb(
					'<div class="ink-navigation breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumbs', 'futebol365' ) . '">',
					'</div>'
				); ?>
			<?php endif; ?>
			</div>

			<?php // -- Rankings table -------------------------------------- ?>
			<?php if ( empty( $rows ) ) : ?>

				<p class="ranking-empty"><?php echo esc_html__( 'No information available.', 'futebol365' ); ?></p>

			<?php else : ?>

				<table class="ink-table alternating ink-table-f365">
					<thead class="align-left">
						<tr>
							<th class="align-left"><?php echo esc_html__( 'Edição', 'futebol365' ); ?></th>
							<th class="align-left"><?php echo esc_html__( 'Jogador', 'futebol365' ); ?></th>
							<th class="align-left hide-small hide-tiny"><?php echo esc_html__( 'Equipa', 'futebol365' ); ?></th>
							<?php if ( $show_points ) : ?>
								<th class="align-left"><?php echo esc_html( $points_desc ); ?></th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $r ) :
							$player_name = (string) $r['player_name'];
							$player_url  = (string) $r['player_url'];
							$flag_id     = (int) $r['flag_id'];
							$team_name   = (string) $r['team_name'];
							?>
							<tr>
								<td><?php echo esc_html( (string) $r['season'] ); ?></td>
								<td>
									<?php if ( $flag_id > 0 ) : ?>
										<i class="hide-tiny flag-icon quarter-right-space flag-icon-<?php echo (int) $flag_id; ?>"></i>
									<?php endif; ?>
									<?php if ( $player_url !== '' && $player_name !== '' ) : ?>
										<a href="<?php echo esc_url( $player_url ); ?>"><?php echo esc_html( $player_name ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $player_name ); ?>
									<?php endif; ?>
								</td>
								<td class="hide-small hide-tiny"><?php echo esc_html( $team_name ); ?></td>
								<?php if ( $show_points ) : ?>
									<td><?php echo (int) $r['points']; ?></td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

			<?php endif; ?>

			<?php // -- Pagination ------------------------------------------ ?>
			<?php if ( $total_pages > 1 ) : ?>
				<div class="paginacao-new ranking-pagination">
					<nav class="ink-navigation">
						<ul class="pagination">
							<?php if ( $page > 1 ) : ?>
								<li class="hide-tiny hide-small">
									<a href="<?php echo esc_url( add_query_arg( 'pagina', $page - 1 ) ); ?>">« <?php esc_html_e( 'Anterior', 'futebol365' ); ?></a>
								</li>
							<?php endif; ?>

							<?php
							$links = paginate_links( [
								'base'      => add_query_arg( 'pagina', '%#%' ),
								'format'    => '',
								'current'   => $page,
								'total'     => $total_pages,
								'prev_next' => false,
								'type'      => 'array',
								'end_size'  => 1,
								'mid_size'  => 2,
							] );

							if ( ! empty( $links ) ) {
								foreach ( $links as $link ) {
									if ( strpos( $link, 'current' ) !== false ) {
										echo '<li>' . str_replace( 'page-numbers current', 'active', $link ) . '</li>'; // phpcs:ignore
									} else {
										echo '<li>' . $link . '</li>'; // phpcs:ignore
									}
								}
							}
							?>

							<?php if ( $page < $total_pages ) : ?>
								<li class="hide-tiny hide-small">
									<a href="<?php echo esc_url( add_query_arg( 'pagina', $page + 1 ) ); ?>"><?php esc_html_e( 'Seguinte', 'futebol365' ); ?> »</a>
								</li>
							<?php endif; ?>
						</ul>
					</nav>
				</div>
			<?php endif; ?>
			<?php get_template_part( 'template-parts/template-front-page-posts' ); ?>

		</section>

		<?php // -- Sidebar ------------------------------------------------- ?>
		<section class="right-column-block">
			<?php if ( is_active_sidebar( 'post-sidebar' ) ) : ?>
				<?php dynamic_sidebar( 'post-sidebar' ); ?>
			<?php else : ?>
				<?php get_sidebar(); ?>
			<?php endif; ?>
		</section>

	</div>
</main>

<?php
get_footer();