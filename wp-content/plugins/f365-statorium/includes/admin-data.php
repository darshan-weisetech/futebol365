<?php
/**
 * Statorium Data — audit pages, one per module.
 *
 * Lists every post / taxonomy term / mirror row that the Statorium importer
 * created or linked: what was imported on which day, with frontend links
 * (including the module's sub-pages) and edit links.
 *
 * Import day sources, in order of reliability:
 *   1. `_statorium_synced_at` meta — stamped on first create/link (new builds)
 *   2. post_date               — for posts the importer created
 *   3. review-table created_at — for posts the matcher auto-linked
 *
 * @package F365_Statorium
 */

defined( 'ABSPATH' ) || exit;

/** Module registry: post-type modules + the two special ones. */
function f365_statorium_data_modules(): array {
	return [
		'competition' => [
			'label'       => 'Competitions',
			'post_type'   => 'f365_competition',
			'pub_meta'    => 'django_competition_id',
			'review_type' => 'season',
			'subs'        => [ 'jogos', 'calendario', 'classificacao', 'equipas', 'estatisticas', 'transferencias', 'vencedores', 'estadios' ],
		],
		'team'        => [
			'label'       => 'Teams',
			'post_type'   => 'f365_team',
			'pub_meta'    => 'django_id',
			'review_type' => 'team',
			'front'       => 'equipa',
			'subs'        => [ 'jogos', 'calendario', 'info', 'plantel', 'transferencias', 'palmares', 'historico' ],
		],
		'player'      => [
			'label'       => 'Players',
			'post_type'   => 'f365_player',
			'pub_meta'    => 'django_player_id',
			'review_type' => 'player',
			'front'       => 'jogador',
			'subs'        => [ 'jogos', 'palmares', 'premios', 'transferencias', 'noticias', 'videos', 'dados-pessoais' ],
		],
		'match'       => [
			'label'       => 'Matches',
			'post_type'   => 'f365_match',
			'pub_meta'    => 'django_match_id',
			'review_type' => 'match',
			'front'       => 'jogo',
			'subs'        => [],
		],
		'stadium'     => [
			'label'       => 'Stadiums',
			'post_type'   => 'f365_stadium',
			'pub_meta'    => 'django_stadium_id',
			'review_type' => 'venue',
			'front'       => 'estadio',
			'subs'        => [ 'ultimos-jogos', 'proximos-jogos', 'mapa', 'info' ],
		],
		'seasons'     => [ 'label' => 'Seasons' ],
		'duplicates'  => [ 'label' => 'Competition Duplicates' ],
		'merge'       => [ 'label' => 'Merge Duplicates' ],
		'review'      => [ 'label' => 'Review Queue' ],
		'livegames'   => [ 'label' => 'Live Matches' ],
		'countries'   => [ 'label' => 'Countries' ],
		'transfers'   => [ 'label' => 'Transfers' ],
	];
}

add_action( 'admin_menu', function () {
	add_menu_page(
		'Statorium Data',
		'Statorium Data',
		'manage_options',
		'f365-statorium-data',
		'f365_statorium_data_render',
		'dashicons-database-import',
		58
	);
	foreach ( f365_statorium_data_modules() as $key => $module ) {
		add_submenu_page(
			'f365-statorium-data',
			'Statorium — ' . $module['label'],
			$module['label'],
			'manage_options',
			'competition' === $key ? 'f365-statorium-data' : 'f365-statorium-data-' . $key,
			'f365_statorium_data_render'
		);
	}

	// Last item: not a data page, just a link out to Settings → Statorium
	// Sync (a different top-level admin menu — WP submenus can't point
	// straight at another page's URL, so this uses a thin redirect callback).
	add_submenu_page(
		'f365-statorium-data',
		'Statorium — Settings',
		'Settings',
		'manage_options',
		'f365-statorium-data-settings',
		function () {
			wp_safe_redirect( admin_url( 'options-general.php?page=f365-statorium' ) );
			exit;
		}
	);
} );

/** Current module key from the page slug. */
function f365_statorium_data_current(): string {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	$key  = str_replace( 'f365-statorium-data-', '', $page );
	$mods = f365_statorium_data_modules();
	return isset( $mods[ $key ] ) ? $key : 'competition';
}

function f365_statorium_data_render(): void {
	$key    = f365_statorium_data_current();
	$module = f365_statorium_data_modules()[ $key ];

	echo '<div class="wrap"><h1>Statorium Data — ' . esc_html( $module['label'] ) . '</h1>';

	if ( isset( $_GET['f365stat_msg'] ) ) {
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sanitize_text_field( wp_unslash( $_GET['f365stat_msg'] ) ) )
		);
	}

	echo '<p class="description">Everything below was created or linked by the Statorium importer. '
		. '<a href="' . esc_url( admin_url( 'options-general.php?page=f365-statorium' ) ) . '">Settings &amp; guide →</a></p>';

	if ( 'countries' === $key ) {
		f365_statorium_data_render_countries();
	} elseif ( 'transfers' === $key ) {
		f365_statorium_data_render_transfers();
	} elseif ( 'livegames' === $key ) {
		f365_statorium_data_render_livegames();
	} elseif ( 'seasons' === $key ) {
		f365_statorium_data_render_seasons();
	} elseif ( 'duplicates' === $key ) {
		f365_statorium_data_render_duplicates();
	} elseif ( 'merge' === $key ) {
		f365_statorium_data_render_merge();
	} elseif ( 'review' === $key ) {
		f365_statorium_data_render_review();
	} else {
		f365_statorium_data_render_posts( $key, $module );
	}
	echo '</div>';
}

// ═══════════════════════════════════════════════════════════════════════════
// Post-type modules
// ═══════════════════════════════════════════════════════════════════════════

function f365_statorium_data_render_posts( string $key, array $module ): void {
	global $wpdb;

	$per_page = 50;
	$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	$day      = isset( $_GET['day'] ) ? sanitize_text_field( wp_unslash( $_GET['day'] ) ) : '';
	$origin   = isset( $_GET['origin'] ) ? sanitize_key( wp_unslash( $_GET['origin'] ) ) : '';
	$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	if ( $day && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
		$day = '';
	}

	$review = F365_Statorium_Matcher::review_table();

	// One row per post that carries the module's statorium id.
	// CREATED = has the `_f365_source=statorium` meta (only stamped on insert).
	// The wp_posts.api_source COLUMN is set on linked legacy posts too (schema
	// backfill from the id maps) — it must NOT be used to tell the two apart.
	// imported_at = synced stamp → post_date (created only) → review date.
	$imported_expr = "COALESCE( syn.meta_value,
		IF( src.post_id IS NOT NULL, p.post_date, NULL ),
		rv.created_at )";

	$from = $wpdb->prepare(
		"FROM {$wpdb->posts} p
		  LEFT JOIN {$wpdb->postmeta} src ON src.post_id = p.ID AND src.meta_key = '_f365_source' AND src.meta_value = 'statorium'
		  LEFT JOIN {$wpdb->postmeta} pub ON pub.post_id = p.ID AND pub.meta_key = %s
		  LEFT JOIN {$wpdb->postmeta} syn ON syn.post_id = p.ID AND syn.meta_key = '_statorium_synced_at'
		  LEFT JOIN {$wpdb->postmeta} mm  ON mm.post_id  = p.ID AND mm.meta_key  = '_f365_match_method'
		  LEFT JOIN {$review} rv ON rv.entity_type = %s
		       AND rv.stat_id = p.api_id
		       AND rv.status IN ( 'auto_linked', 'linked' )
		 WHERE p.post_type = %s AND p.post_status = 'publish'
		   AND p.api_source = 'statorium' AND p.api_id IS NOT NULL",
		$module['pub_meta'], $module['review_type'], $module['post_type']
	);

	$where = '';
	if ( 'created' === $origin ) {
		$where .= ' AND src.post_id IS NOT NULL';
	} elseif ( 'linked' === $origin ) {
		$where .= ' AND src.post_id IS NULL';
	}
	if ( $day ) {
		$where .= $wpdb->prepare( " AND DATE( {$imported_expr} ) = %s", $day );
	}
	if ( '' !== $search ) {
		$where .= $wpdb->prepare( ' AND p.post_title LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
	}

	// Headline counts + per-day summary (last 30 days with activity).
	$counts = $wpdb->get_row(
		"SELECT COUNT(*) AS total,
		        SUM( src.post_id IS NOT NULL ) AS created,
		        SUM( src.post_id IS NULL ) AS linked
		   {$from}"
	);
	$days = $wpdb->get_results(
		"SELECT DATE( {$imported_expr} ) AS d,
		        COUNT(*) AS total,
		        SUM( src.post_id IS NOT NULL ) AS created
		   {$from}
		  GROUP BY d ORDER BY d DESC LIMIT 30"
	);

	$total = (int) $wpdb->get_var( "SELECT COUNT(*) {$from} {$where}" );
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT p.ID, p.post_title,
		        ( src.post_id IS NOT NULL ) AS is_created,
		        p.api_id AS stat_id, pub.meta_value AS pub_id,
		        mm.meta_value AS method, {$imported_expr} AS imported_at
		   {$from} {$where}
		  ORDER BY imported_at DESC, p.ID DESC
		  LIMIT %d OFFSET %d",
		$per_page, ( $paged - 1 ) * $per_page
	) );

	// Match the per-row Origin column below: a created competition whose
	// FEDCOMP (league) already resolved to legacy counts as linked here too,
	// or this headline flatly contradicts what the table underneath shows
	// for the exact same rows (e.g. "9 created new" above a table where all
	// 9 read "Linked legacy").
	$created_n = (int) $counts->created;
	$linked_n  = (int) $counts->linked;
	if ( 'competition' === $key ) {
		// {$from} already ends in its own WHERE clause (built via
		// wpdb::prepare() above) — appending another JOIN + WHERE after it
		// is invalid SQL, so this is a standalone query, not a reuse of it.
		$fedcomp_linked = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} src ON src.post_id = p.ID AND src.meta_key = '_f365_source' AND src.meta_value = 'statorium'
			   JOIN {$wpdb->postmeta} pm  ON pm.post_id  = p.ID AND pm.meta_key = 'django_competition_id'
			   JOIN {$wpdb->prefix}competition_competition cc ON cc.id = CAST( pm.meta_value AS UNSIGNED )
			  WHERE p.post_type = 'f365_competition' AND p.post_status = 'publish'
			    AND p.api_source = 'statorium' AND p.api_id IS NOT NULL
			    AND cc.federation_competition_id > 0 AND cc.federation_competition_id < " . F365_Statorium_Importer::ID_OFFSET
		);
		$created_n -= $fedcomp_linked;
		$linked_n  += $fedcomp_linked;
	}

	printf(
		'<p style="font-size:14px"><strong>%d</strong> %s from Statorium — <span style="color:#0a7d33"><strong>%d</strong> created</span> new, <span style="color:#996800"><strong>%d</strong> linked</span> to existing legacy records.</p>',
		(int) $counts->total,
		esc_html( strtolower( $module['label'] ) ),
		$created_n,
		$linked_n
	);
	f365_statorium_data_day_summary( $days, $day );
	f365_statorium_data_filters( $day, $origin, $search, true );

	// "Origin" (Created/Linked legacy) is import PROVENANCE, fixed forever at
	// import time — it was being read as if it were the competition's current
	// state, which it never reflected. Competitions get a real derived-state
	// column too; the concept doesn't apply to team/player/match/stadium rows.
	$show_status = ( 'competition' === $key );

	echo '<table class="widefat striped"><thead><tr>'
		. '<th>Title</th><th style="width:110px">Statorium ID</th><th style="width:110px">Public ID</th>'
		. '<th style="width:190px">Origin</th>'
		. ( $show_status ? '<th style="width:170px">Status</th>' : '' )
		. '<th style="width:130px">Imported</th><th>Links</th>'
		. '</tr></thead><tbody>';

	if ( ! $rows ) {
		echo '<tr><td colspan="' . ( $show_status ? 7 : 6 ) . '">Nothing found for this filter.</td></tr>';
	}

	foreach ( $rows as $r ) {
		$created = (bool) $r->is_created;
		$pub     = (int) $r->pub_id;

		// Frontend URL + collapsed sub-page links.
		$view = '';
		$subs = [];
		$fc   = null;
		if ( 'competition' === $key ) {
			$fc = $wpdb->get_row( $wpdb->prepare(
				"SELECT federation_competition_id AS fc, edition, is_closed FROM {$wpdb->prefix}competition_competition WHERE id = %d",
				$pub
			) );
			if ( $fc && $fc->fc ) {
				$base = home_url( "/competicao/{$fc->fc}/" );
				$view = add_query_arg( 'edition', $fc->edition, $base );
				foreach ( $module['subs'] as $sub ) {
					$subs[] = '<a href="' . esc_url( add_query_arg( 'edition', $fc->edition, $base . $sub . '/' ) ) . '" target="_blank">' . esc_html( $sub ) . '</a>';
				}
			}
		} elseif ( $pub ) {
			$base = home_url( '/' . $module['front'] . "/{$pub}/" );
			$view = $base;
			foreach ( $module['subs'] as $sub ) {
				$subs[] = '<a href="' . esc_url( $base . $sub . '/' ) . '" target="_blank">' . esc_html( $sub ) . '</a>';
			}
		}

		$links_cell = '—';
		if ( $view ) {
			$links_cell = '<a href="' . esc_url( $view ) . '" target="_blank" class="button button-small">View page ↗</a>';
			if ( $subs ) {
				$links_cell .= ' <details style="display:inline-block;vertical-align:middle;margin-left:6px"><summary style="cursor:pointer">sub-pages (' . count( $subs ) . ')</summary>'
					. '<span style="display:block;padding:6px 2px 0;max-width:340px">' . implode( ' &nbsp;·&nbsp; ', $subs ) . '</span></details>';
			}
		}

		$status_cell = '';
		if ( $show_status ) {
			// Same derivation as the Seasons audit page: is_closed only ever
			// gets set for knockout competitions with a Final
			// (finalize_competitions()) — leagues are read from actual match
			// completion instead, since the API has no native "closed" flag
			// for them. 0 finished means "not started" regardless of any
			// begin date — a fixture list can exist well before kickoff.
			$mrow = $pub ? $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS total, SUM( situation = 'finished' ) AS finished FROM {$wpdb->prefix}competition_match WHERE competition_id = %d",
				$pub
			) ) : null;
			$m_total    = $mrow ? (int) $mrow->total : 0;
			$m_finished = $mrow ? (int) $mrow->finished : 0;

			if ( $fc && 't' === (string) $fc->is_closed ) {
				$state = '🏆 Closed';
			} elseif ( 0 === $m_total || 0 === $m_finished ) {
				$state = '🕒 Not started';
			} elseif ( $m_finished >= $m_total ) {
				$state = '🏁 Finished';
			} else {
				$state = "▶️ In progress ({$m_finished}/{$m_total})";
			}
			$status_cell = '<td>' . esc_html( $state ) . '</td>';
		}

		// "Created" on its own reads as "we invented this competition" — true
		// at the POST level (this specific edition/season row is new) but
		// misleading for the common case import_season()/find_legacy_fedcomp()
		// exist for: a brand-new 2026-27 season of a league that has existed
		// for decades in the legacy data (Premier League, La Liga, …). The
		// fedcomp (league) is what actually carries that history, and it's
		// already linked — so distinguish "new edition of an existing legacy
		// league" from a genuinely new, fedcomp-less competition instead of
		// flattening both to the same "Created" label.
		$fedcomp_is_legacy = ( 'competition' === $key && $fc && ! empty( $fc->fc ) && (int) $fc->fc < F365_Statorium_Importer::ID_OFFSET );
		if ( $created && $fedcomp_is_legacy ) {
			$origin_cell = '<span style="color:#996800;font-weight:600">Linked legacy</span><br><small>league; new edition this season</small>';
		} elseif ( $created ) {
			$origin_cell = '<span style="color:#0a7d33;font-weight:600">Created</span>';
		} else {
			$origin_cell = '<span style="color:#996800;font-weight:600">Linked legacy</span>'
				. ( $r->method ? '<br><small>' . esc_html( $r->method ) . '</small>' : '' );
		}

		printf(
			'<tr><td><a href="%s"><strong>%s</strong></a><br><small>post #%d — <a href="%s">edit</a></small></td>'
			. '<td>%s</td><td>%s</td><td>%s</td>%s<td>%s</td><td>%s</td></tr>',
			esc_url( (string) get_edit_post_link( $r->ID ) ),
			esc_html( $r->post_title ),
			(int) $r->ID,
			esc_url( (string) get_edit_post_link( $r->ID ) ),
			esc_html( (string) $r->stat_id ),
			$pub ? esc_html( (string) $pub ) : '—',
			$origin_cell,
			$status_cell,
			$r->imported_at ? esc_html( mysql2date( 'Y-m-d', $r->imported_at ) ) : '—',
			$links_cell
		);
	}
	echo '</tbody></table>';

	f365_statorium_data_pagination( $total, $per_page, $paged );
}

// ═══════════════════════════════════════════════════════════════════════════
// Countries (taxonomy terms)
// ═══════════════════════════════════════════════════════════════════════════

function f365_statorium_data_render_countries(): void {
	global $wpdb;

	$per_page = 50;
	$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

	$where = '';
	if ( '' !== $search ) {
		$where = $wpdb->prepare( ' AND t.name LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
	}

	$from = "FROM {$wpdb->terms} t
		  JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'f365_country'
		  JOIN {$wpdb->termmeta} sid ON sid.term_id = t.term_id AND sid.meta_key = 'statorium_country_id'
		  LEFT JOIN {$wpdb->termmeta} code ON code.term_id = t.term_id AND code.meta_key = 'statorium_country_code'
		  LEFT JOIN {$wpdb->termmeta} mid  ON mid.term_id  = t.term_id AND mid.meta_key  = 'country_country_id'
		 WHERE 1=1";

	$total = (int) $wpdb->get_var( "SELECT COUNT(*) {$from} {$where}" );
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT t.term_id, t.name, t.slug, tt.count,
		        sid.meta_value AS stat_id, code.meta_value AS iso, mid.meta_value AS mirror_id
		   {$from} {$where}
		  ORDER BY t.name ASC LIMIT %d OFFSET %d",
		$per_page, ( $paged - 1 ) * $per_page
	) );

	echo '<p><strong>' . (int) $total . '</strong> f365_country terms mapped to Statorium country ids '
		. '(one-time <code>countries</code> command; also mirrored into <code>wp_country_country</code>).</p>';
	f365_statorium_data_filters( '', '', $search, false );

	echo '<table class="widefat striped"><thead><tr>'
		. '<th>Country</th><th style="width:100px">Statorium ID</th><th style="width:80px">ISO</th>'
		. '<th style="width:110px">Mirror row ID</th><th style="width:90px">Tagged posts</th><th>Links</th>'
		. '</tr></thead><tbody>';

	if ( ! $rows ) {
		echo '<tr><td colspan="6">No mapped countries — run <code>wp f365-statorium countries</code>.</td></tr>';
	}

	foreach ( $rows as $r ) {
		$edit  = get_edit_term_link( (int) $r->term_id, 'f365_country' );
		$links = [];
		foreach ( [ 'jogador' => 'jogadores', 'estadio' => 'estádios', 'treinador' => 'treinadores', 'arbitro' => 'árbitros' ] as $pre => $lbl ) {
			$links[] = '<a href="' . esc_url( home_url( "/{$pre}/pais/{$r->slug}/" ) ) . '" target="_blank">' . esc_html( $lbl ) . '</a>';
		}
		printf(
			'<tr><td><a href="%s"><strong>%s</strong></a><br><small>term #%d (%s)</small></td>'
			. '<td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_url( (string) $edit ),
			esc_html( $r->name ),
			(int) $r->term_id,
			esc_html( $r->slug ),
			esc_html( (string) $r->stat_id ),
			$r->iso ? esc_html( strtoupper( $r->iso ) ) : '—',
			$r->mirror_id ? esc_html( (string) $r->mirror_id ) : '—',
			esc_html( (string) $r->count ),
			implode( ' &nbsp;·&nbsp; ', $links )
		);
	}
	echo '</tbody></table>';

	f365_statorium_data_pagination( $total, $per_page, $paged );
}

// ═══════════════════════════════════════════════════════════════════════════
// Transfers (mirror rows — /transferencias/ reads wp_transfer_transfer)
// ═══════════════════════════════════════════════════════════════════════════

function f365_statorium_data_render_transfers(): void {
	global $wpdb;

	$table = $wpdb->prefix . 'transfer_transfer';
	$cols  = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
	if ( ! in_array( 'api_source', $cols, true ) ) {
		echo '<p>The <code>api_source</code> column is missing — deactivate/reactivate the plugin to run the schema update, then run <code>wp f365-statorium transfers</code>.</p>';
		return;
	}

	$per_page = 50;
	$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	$day      = isset( $_GET['day'] ) ? sanitize_text_field( wp_unslash( $_GET['day'] ) ) : '';
	$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	if ( $day && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
		$day = '';
	}

	$from = "FROM {$table} tr
		  LEFT JOIN {$wpdb->prefix}person_player pl ON pl.id = tr.player_id
		  LEFT JOIN {$wpdb->prefix}person_person pr ON pr.id = pl.person_id
		  LEFT JOIN {$wpdb->prefix}team_teamstep ts_o ON ts_o.id = tr.team_origin_id
		  LEFT JOIN {$wpdb->prefix}team_team t_o ON t_o.id = ts_o.team_id
		  LEFT JOIN {$wpdb->prefix}team_teamstep ts_d ON ts_d.id = tr.team_destination_id
		  LEFT JOIN {$wpdb->prefix}team_team t_d ON t_d.id = ts_d.team_id
		 WHERE tr.api_source = 'statorium'";

	$where = '';
	if ( $day ) {
		$where .= $wpdb->prepare( ' AND DATE( COALESCE( tr.api_created_at, tr.transfer_date ) ) = %s', $day );
	}
	if ( '' !== $search ) {
		$like   = '%' . $wpdb->esc_like( $search ) . '%';
		$where .= $wpdb->prepare( ' AND ( pr.shortname LIKE %s OR t_o.shortname LIKE %s OR t_d.shortname LIKE %s )', $like, $like, $like );
	}

	$days = $wpdb->get_results(
		"SELECT DATE( COALESCE( tr.api_created_at, tr.transfer_date ) ) AS d, COUNT(*) AS total, COUNT(*) AS created
		   {$from} GROUP BY d ORDER BY d DESC LIMIT 30"
	);

	$total = (int) $wpdb->get_var( "SELECT COUNT(*) {$from} {$where}" );
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT tr.*, pr.shortname AS player_name,
		        t_o.id AS from_id, t_o.shortname AS from_name,
		        t_d.id AS to_id,   t_d.shortname AS to_name
		   {$from} {$where}
		  ORDER BY tr.transfer_date DESC, tr.id DESC LIMIT %d OFFSET %d",
		$per_page, ( $paged - 1 ) * $per_page
	) );

	f365_statorium_data_day_summary( $days, $day, 'rows' );
	f365_statorium_data_filters( $day, '', $search, false );
	echo '<p class="description">Rows written to <code>wp_transfer_transfer</code> — the table the '
		. '<a href="' . esc_url( home_url( '/transferencias/' ) ) . '" target="_blank">/transferencias/</a> page reads. '
		. 'Type: D = definitivo, E = empréstimo.</p>';

	echo '<table class="widefat striped"><thead><tr>'
		. '<th style="width:110px">Transfer date</th><th>Player</th><th>From → To</th>'
		. '<th style="width:70px">Type</th><th style="width:120px">Value</th><th style="width:80px">Edition</th><th style="width:130px">Imported</th>'
		. '</tr></thead><tbody>';

	if ( ! $rows ) {
		echo '<tr><td colspan="7">No imported transfers yet — run <code>wp f365-statorium transfers</code>.</td></tr>';
	}

	foreach ( $rows as $r ) {
		printf(
			'<tr><td>%s</td><td><a href="%s" target="_blank">%s</a><br><small>row #%d, player %d</small></td>'
			. '<td><a href="%s" target="_blank">%s</a> → <a href="%s" target="_blank">%s</a></td>'
			. '<td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( $r->transfer_date ),
			esc_url( home_url( "/jogador/{$r->player_id}/" ) ),
			esc_html( $r->player_name ?: "#{$r->player_id}" ),
			(int) $r->id,
			(int) $r->player_id,
			esc_url( home_url( "/equipa/{$r->from_id}/" ) ),
			esc_html( $r->from_name ?: '—' ),
			esc_url( home_url( "/equipa/{$r->to_id}/" ) ),
			esc_html( $r->to_name ?: '—' ),
			esc_html( (string) $r->transfer_type ),
			is_numeric( $r->value ) && '' !== $r->value
				? esc_html( number_format( (float) $r->value, 0, '', ' ' ) ) . ' €'
				: esc_html( $r->value ?: '—' ),
			esc_html( (string) $r->edition ),
			$r->api_created_at ? esc_html( mysql2date( 'Y-m-d', $r->api_created_at ) ) : '—'
		);
	}
	echo '</tbody></table>';

	f365_statorium_data_pagination( $total, $per_page, $paged );
}

// ═══════════════════════════════════════════════════════════════════════════
// Seasons — human-readable view of the raw `seasons` setting (settings page
// used to just dump the comma-separated Statorium season ids, meaningless
// without cross-referencing the API). One row per configured id: what
// competition/edition it resolved to, and its team/match counts.
// ═══════════════════════════════════════════════════════════════════════════

function f365_statorium_data_render_seasons(): void {
	global $wpdb;

	$settings = F365_Statorium_Importer::settings();
	$ids      = array_filter( array_map( 'intval', explode( ',', (string) $settings['seasons'] ) ) );

	echo '<p class="description">Every Statorium season id configured in <a href="' . esc_url( admin_url( 'options-general.php?page=f365-statorium' ) ) . '">Settings → Statorium Sync</a> '
		. '(auto-extended as backfill/onboarding discovers new seasons), with what it actually resolved to.</p>';

	if ( ! $ids ) {
		echo '<p>No season ids configured yet.</p>';
		return;
	}

	echo '<table class="widefat striped"><thead><tr>'
		. '<th style="width:90px">Season ID</th><th style="width:140px">Status</th><th>Competition</th>'
		. '<th style="width:110px">Edition</th><th style="width:140px">Federation</th>'
		. '<th style="width:70px">Teams</th><th style="width:70px">Matches</th><th style="width:160px">Links</th>'
		. '</tr></thead><tbody>';

	foreach ( $ids as $sid ) {
		$wp_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE api_source = 'statorium' AND api_id = %d AND post_type = 'f365_competition' LIMIT 1",
			$sid
		) );

		if ( ! $wp_id ) {
			$blocked = F365_Statorium_Importer::season_unsubscribed_league( $sid );
			if ( $blocked ) {
				printf(
					'<tr><td><code>%d</code></td><td>🚫 Not subscribed</td>'
					. '<td colspan="6"><span class="description">"%s" — league %d is not in this API key\'s subscription. Nothing to run here; this changes only if that league gets subscribed.</span></td></tr>',
					$sid, esc_html( $blocked['title'] ), (int) $blocked['league_id']
				);
				continue;
			}
			printf(
				'<tr><td><code>%d</code></td><td>⏳ Not imported</td>'
				. '<td colspan="6"><span class="description">Run <code>wp f365-statorium season --season=%d</code>, or use the Settings checklist.</span></td></tr>',
				$sid, $sid
			);
			continue;
		}

		$pub       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT legacy_id FROM {$wpdb->posts} WHERE ID = %d", $wp_id ) );
		$is_legacy = $pub > 0 && $pub < F365_Statorium_Importer::ID_OFFSET;
		$edition   = (string) get_post_meta( $wp_id, 'season', true );
		$fed_wp    = (int) get_post_meta( $wp_id, 'federation', true );
		$fed_name  = $fed_wp ? get_the_title( $fed_wp ) : '—';
		$comp      = $pub ? $wpdb->get_row( $wpdb->prepare(
			"SELECT federation_competition_id, is_closed FROM {$wpdb->prefix}competition_competition WHERE id = %d", $pub
		) ) : null;
		$fedcomp   = $comp ? (int) $comp->federation_competition_id : 0;
		$teams     = $pub ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}competition_participant WHERE competition_id = %d", $pub ) ) : 0;
		$mrow      = $pub ? $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS total, SUM( situation = 'finished' ) AS finished FROM {$wpdb->prefix}competition_match WHERE competition_id = %d", $pub
		) ) : null;
		$matches   = $mrow ? (int) $mrow->total : 0;
		$finished  = $mrow ? (int) $mrow->finished : 0;

		// This used to just show the row's ORIGIN (created vs linked to
		// legacy) under the "Status" header — a permanent import-provenance
		// label that never changed, so a season showed "Created" forever
		// even once it had kicked off or finished. Derived instead from
		// actual match progress (the API has no native "closed" concept for
		// leagues; is_closed only ever gets set for knockout competitions
		// with a Final, via finalize_competitions()). $finished === 0 means
		// "not started" regardless of the mirror's `begin` date — a
		// fixture list can exist well before a ball is kicked, and `begin`
		// isn't reliably precise enough to tell "kicks off today" from
		// "kicked off weeks ago, first results just haven't landed yet".
		if ( $comp && 't' === (string) $comp->is_closed ) {
			$state = '🏆 Closed (winner decided)';
		} elseif ( 0 === $matches || 0 === $finished ) {
			$state = '🕒 Not started';
		} elseif ( $finished >= $matches ) {
			$state = '🏁 All matches finished';
		} else {
			$state = "▶️ In progress ({$finished}/{$matches})";
		}

		printf(
			'<tr><td><code>%d</code></td><td>%s<br><small>%s</small></td>'
			. '<td><a href="%s"><strong>%s</strong></a></td><td>%s</td><td>%s</td><td>%d</td><td>%d</td>'
			. '<td><a href="%s">Edit</a>%s</td></tr>',
			$sid,
			esc_html( $state ),
			$is_legacy ? 'Linked to legacy' : 'Created',
			esc_url( (string) get_edit_post_link( $wp_id ) ),
			esc_html( get_the_title( $wp_id ) ),
			esc_html( $edition ?: '—' ),
			esc_html( $fed_name ),
			$teams,
			$matches,
			esc_url( (string) get_edit_post_link( $wp_id ) ),
			$fedcomp ? ' · <a href="' . esc_url( home_url( '/competicao/' . $fedcomp . '/?edition=' . rawurlencode( $edition ) ) ) . '" target="_blank">Front-end →</a>' : ''
		);
	}

	echo '</tbody></table>';
}

// ═══════════════════════════════════════════════════════════════════════════
// Competition duplicates — moved here from the Settings page. A live
// self-check, not a static log: it re-runs find_legacy_competition() for
// every Statorium-CREATED competition right now, since the failure mode
// (old plugin builds importing a season before its legacy skeleton could be
// matched) can self-resolve if a legacy row shows up later. Offers a
// one-click merge when it finds one.
// ═══════════════════════════════════════════════════════════════════════════

function f365_statorium_data_render_duplicates(): void {
	global $wpdb;

	$owned_comps = $wpdb->get_results(
		"SELECT p.ID, p.post_title, p.api_id AS season_stat FROM {$wpdb->posts} p
		   JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_f365_source' AND s.meta_value = 'statorium'
		  WHERE p.api_source = 'statorium' AND p.post_type = 'f365_competition' AND p.post_status = 'publish'"
	);

	if ( ! $owned_comps ) {
		echo '<p><em>No Statorium-created competitions — every imported season is linked to a legacy competition.</em></p>';
		return;
	}

	$importer_tmp = new F365_Statorium_Importer();
	echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Created competition</th><th>Legacy counterpart found</th><th>Action</th></tr></thead><tbody>';
	foreach ( $owned_comps as $comp ) {
		// The season's own Statorium id lives in the indexed api_id COLUMN,
		// not a postmeta — read it from there instead of the now-removed
		// statorium_season_id key (was never actually written by
		// import_season() to begin with; api_id already carries the same
		// value reliably for every competition post).
		$season_stat = (int) $comp->season_stat;
		$league_id   = (int) get_post_meta( (int) $comp->ID, 'statorium_league_id', true );
		$season_name = (string) get_post_meta( (int) $comp->ID, 'season', true );
		$legacy_wp   = $importer_tmp->find_legacy_competition( $league_id, $season_name, (string) $comp->post_title );
		$legacy_row  = $legacy_wp ? (int) get_post_meta( $legacy_wp, 'django_competition_id', true ) : 0;

		echo '<tr><td><strong>' . esc_html( $comp->post_title ) . '</strong> <code>#' . (int) $comp->ID . '</code><br><small>season ' . (int) $season_stat . ', league ' . (int) $league_id . '</small></td>';
		if ( $legacy_row ) {
			$merge_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=f365stat_merge_comp&season=' . $season_stat . '&into=' . $legacy_row ),
				'f365stat_merge_comp'
			);
			echo '<td>"' . esc_html( get_the_title( $legacy_wp ) ) . '" <code>#' . (int) $legacy_wp . '</code> (comp row ' . $legacy_row . ')</td>';
			echo '<td><a class="button button-primary" href="' . esc_url( $merge_url ) . '" onclick="return confirm(\'Merge all data of this created competition into the legacy one? Fixtures are paired, the duplicate is removed.\');">Merge into legacy</a></td>';
		} else {
			echo '<td><em>none found — genuinely new competition</em></td><td>—</td>';
		}
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo '<p class="description">A duplicate appears when a season was imported before its legacy competition could be matched (old plugin builds did this). Merging pairs the fixtures, moves all data across, and deletes the duplicate — same as <code>wp f365-statorium merge-competition</code>.</p>';
}

// ═══════════════════════════════════════════════════════════════════════════
// Merge Duplicates — manual search-and-merge for Team / Player / Stadium.
//
// The automatic dedup sweeps (auto_merge_duplicate_teams/players/stadiums,
// every sync) only catch a duplicate when their name comparison — exact, or
// generic-word-stripped fuzzy — actually matches. A pair that diverges a
// different way (an acronym vs its expansion, e.g. "RSC Anderlecht" vs
// "Royal Sporting Club Anderlecht", or "FC Red Bull Salzburg" vs "Fußball
// Club Red Bull Salzburg") produces no automatic signal at all: the matcher
// never even logs it as ambiguous, because it never found a candidate to
// compare against. This page is the general-purpose fallback — an admin who
// spots a duplicate on the frontend picks both sides by hand, on whichever
// server they're looking at (staging/prod post IDs never need to match this
// or any other environment — everything here is driven by live AJAX name
// search against THAT server's own database).
//
// Left box searches Statorium-CREATED records only (the duplicate/source);
// right box searches LEGACY records only (the merge target) — pre-filtered
// server-side so an invalid pick isn't possible, no manual double-checking
// needed. Runs through the exact same merge_team()/merge_player()/
// merge_stadium() the CLI's merge-team/merge-player/merge-stadium commands
// call, so it carries the same safety guarantees, and every successful merge
// is written to the wp_f365_stat_merge_log audit table (who, what, when) —
// see the log table below the form.
//
// Competitions are NOT included here — they already have their own
// auto-detecting page (Statorium Data → Competition Duplicates) since
// find_legacy_competition() can re-run live with no search step needed.
//
// The search dropdowns are hand-rolled vanilla JS (debounced admin-ajax.php
// fetch + a positioned results list), not the Select2 library — same
// type-to-search UX, no new dependency (this plugin ships with none beyond
// WordPress + ACF; pulling in Select2 here would be the first).
// ═══════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_f365stat_merge_search', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json( [ 'results' => [] ], 403 );
	}
	check_ajax_referer( 'f365stat_merge_search', 'nonce' );

	global $wpdb;
	$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
	$side = isset( $_GET['side'] ) ? sanitize_key( wp_unslash( $_GET['side'] ) ) : '';
	$q    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

	$modules = f365_statorium_data_modules();
	if ( ! isset( $modules[ $type ] ) || '' === trim( $q ) ) {
		wp_send_json( [ 'results' => [] ] );
	}
	$post_type = $modules[ $type ]['post_type'];
	$like      = '%' . $wpdb->esc_like( $q ) . '%';

	if ( 'source' === $side ) {
		// Only Statorium-CREATED records are valid merge sources.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_f365_source' AND s.meta_value = 'statorium'
			  WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_title LIKE %s
			  ORDER BY p.post_title LIMIT 20",
			$post_type, $like
		) );
	} else {
		// Only LEGACY records are valid merge targets. is_legacy() isn't a
		// single SQL predicate shared across all three types, so over-fetch
		// by title and filter in PHP.
		$candidates = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title FROM {$wpdb->posts} p
			  WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_title LIKE %s
			  ORDER BY p.post_title LIMIT 100",
			$post_type, $like
		) );
		$importer = new F365_Statorium_Importer();
		$rows     = [];
		foreach ( $candidates as $c ) {
			if ( $importer->is_legacy( (int) $c->ID ) ) {
				$rows[] = $c;
				if ( count( $rows ) >= 20 ) {
					break;
				}
			}
		}
	}

	wp_send_json( [ 'results' => array_map(
		static fn( $r ) => [ 'id' => (int) $r->ID, 'text' => $r->post_title . ' (#' . (int) $r->ID . ')' ],
		$rows
	) ] );
} );

function f365_statorium_data_render_merge(): void {
	global $wpdb;

	$types = [
		'team'    => 'Team',
		'player'  => 'Player',
		'stadium' => 'Stadium',
	];
	$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'team';
	if ( ! isset( $types[ $type ] ) ) {
		$type = 'team';
	}

	echo '<p>';
	foreach ( $types as $t => $label ) {
		$url = add_query_arg( [ 'page' => 'f365-statorium-data-merge', 'type' => $t ], admin_url( 'admin.php' ) );
		printf(
			'<a class="button%s" href="%s" style="margin-right:6px">%s</a>',
			$t === $type ? ' button-primary' : '', esc_url( $url ), esc_html( $label )
		);
	}
	echo '</p>';
	echo '<p class="description">Search and pick both sides — the duplicate (Statorium-created) on the left, the legacy record to merge it into on the right — then Merge. Works entirely by name; no post IDs to look up, and nothing here depends on any other server\'s ids.</p>';

	$search_nonce = wp_create_nonce( 'f365stat_merge_search' );
	$merge_nonce  = wp_create_nonce( 'f365stat_manual_merge' );
	?>
	<style>
		.f365-merge-form{display:flex;gap:24px;align-items:flex-start;max-width:900px;margin:16px 0}
		.f365-merge-col{flex:1;position:relative}
		.f365-merge-col label{display:block;font-weight:600;margin-bottom:6px}
		.f365-merge-search{width:100%;padding:6px 10px;font-size:14px;line-height:1.6}
		.f365-merge-results{display:none;position:absolute;z-index:10;top:100%;left:0;right:0;background:#fff;border:1px solid #8c8f94;border-top:none;max-height:260px;overflow-y:auto;box-shadow:0 2px 6px rgba(0,0,0,.15)}
		.f365-merge-results div{padding:8px 10px;cursor:pointer;border-bottom:1px solid #f0f0f1}
		.f365-merge-results div:hover,.f365-merge-results div.active{background:#2271b1;color:#fff}
		.f365-merge-selected{margin-top:8px;padding:8px 10px;background:#f0f6fc;border:1px solid #c3d4e0;border-radius:3px;display:none}
		.f365-merge-selected.filled{display:block}
		.f365-merge-arrow{align-self:center;font-size:22px;line-height:1;margin-top:30px;color:#787c82}
		.f365-merge-actions{max-width:900px;margin:10px 0 20px}
	</style>
	<div class="f365-merge-form">
		<div class="f365-merge-col">
			<label>Duplicate (Statorium-created)</label>
			<input type="text" class="f365-merge-search" data-side="source" autocomplete="off" placeholder="Type a name…">
			<div class="f365-merge-results" data-side="source"></div>
			<div class="f365-merge-selected" data-side="source"></div>
		</div>
		<div class="f365-merge-arrow">→</div>
		<div class="f365-merge-col">
			<label>Merge into (legacy)</label>
			<input type="text" class="f365-merge-search" data-side="target" autocomplete="off" placeholder="Type a name…">
			<div class="f365-merge-results" data-side="target"></div>
			<div class="f365-merge-selected" data-side="target"></div>
		</div>
	</div>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="f365-merge-actions">
		<input type="hidden" name="action" value="f365stat_manual_merge">
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $merge_nonce ); ?>">
		<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>">
		<input type="hidden" name="created_id" id="f365-merge-source-id" value="">
		<input type="hidden" name="legacy_id" id="f365-merge-target-id" value="">
		<button type="submit" class="button button-primary button-hero" id="f365-merge-submit" disabled>Merge</button>
	</form>
	<script>
	(function () {
		var type = <?php echo wp_json_encode( $type ); ?>;
		var searchNonce = <?php echo wp_json_encode( $search_nonce ); ?>;
		var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		var selected = { source: null, target: null };
		var submitBtn = document.getElementById( 'f365-merge-submit' );

		function updateSubmit() {
			submitBtn.disabled = ! ( selected.source && selected.target );
		}

		document.querySelectorAll( '.f365-merge-search' ).forEach( function ( input ) {
			var side    = input.getAttribute( 'data-side' );
			var results = document.querySelector( '.f365-merge-results[data-side="' + side + '"]' );
			var chosen  = document.querySelector( '.f365-merge-selected[data-side="' + side + '"]' );
			var hidden  = document.getElementById( side === 'source' ? 'f365-merge-source-id' : 'f365-merge-target-id' );
			var timer   = null;

			input.addEventListener( 'input', function () {
				selected[ side ] = null;
				hidden.value     = '';
				chosen.classList.remove( 'filled' );
				chosen.textContent = '';
				updateSubmit();

				clearTimeout( timer );
				var q = input.value.trim();
				if ( q.length < 2 ) {
					results.style.display = 'none';
					return;
				}
				timer = setTimeout( function () {
					var url = ajaxUrl + '?action=f365stat_merge_search&nonce=' + encodeURIComponent( searchNonce )
						+ '&type=' + encodeURIComponent( type ) + '&side=' + encodeURIComponent( side )
						+ '&q=' + encodeURIComponent( q );
					fetch( url ).then( function ( r ) { return r.json(); } ).then( function ( data ) {
						results.innerHTML = '';
						var list = ( data && data.results ) || [];
						if ( ! list.length ) {
							results.style.display = 'none';
							return;
						}
						list.forEach( function ( item ) {
							var row = document.createElement( 'div' );
							row.textContent = item.text;
							row.addEventListener( 'click', function () {
								selected[ side ] = item;
								hidden.value = item.id;
								input.value = item.text;
								chosen.textContent = 'Selected: ' + item.text;
								chosen.classList.add( 'filled' );
								results.style.display = 'none';
								updateSubmit();
							} );
							results.appendChild( row );
						} );
						results.style.display = 'block';
					} );
				}, 300 );
			} );

			document.addEventListener( 'click', function ( e ) {
				if ( e.target !== input ) {
					results.style.display = 'none';
				}
			} );
		} );

		submitBtn.closest( 'form' ).addEventListener( 'submit', function ( e ) {
			if ( ! selected.source || ! selected.target ) {
				e.preventDefault();
				return;
			}
			if ( ! confirm( 'Merge "' + selected.source.text + '" into "' + selected.target.text + '"? The duplicate is removed; this cannot be undone from here.' ) ) {
				e.preventDefault();
			}
		} );
	})();
	</script>
	<?php

	// ── Audit log: recent manual merges of this type ────────────────────────
	$log_table = $wpdb->prefix . 'f365_stat_merge_log';
	$log_rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$log_table} WHERE entity_type = %s ORDER BY merged_at DESC LIMIT 50",
		$type
	) );

	echo '<h3>Merge log — ' . esc_html( $types[ $type ] ) . '</h3>';
	if ( ! $log_rows ) {
		echo '<p><em>No manual merges recorded yet for this module.</em></p>';
		return;
	}
	echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th style="width:150px">When</th><th style="width:150px">Admin</th><th>Merged</th></tr></thead><tbody>';
	foreach ( $log_rows as $row ) {
		echo '<tr><td>' . esc_html( mysql2date( 'Y-m-d H:i', $row->merged_at ) ) . '</td>';
		echo '<td>' . esc_html( $row->user_login ?: ( 'user #' . (int) $row->user_id ) ) . '</td>';
		echo '<td>' . esc_html( $row->source_title ) . ' &laquo;&laquo; ' . esc_html( $row->target_title ) . '</td></tr>';
	}
	echo '</tbody></table>';
}

// ═══════════════════════════════════════════════════════════════════════════
// Review queue — moved here from the Settings page. Records the matcher
// couldn't confidently auto-link or auto-create (contradicting signals, or
// several unconfirmed candidates) under review_mode=auto; each row needs a
// human pick: link to a specific candidate, force-create, or ignore.
// ═══════════════════════════════════════════════════════════════════════════

function f365_statorium_data_render_review(): void {
	global $wpdb;

	$pending = $wpdb->get_results( 'SELECT * FROM ' . F365_Statorium_Matcher::review_table() . " WHERE status='pending' ORDER BY id LIMIT 50" );
	if ( ! $pending ) {
		echo '<p><em>No records waiting for review.</em></p>';
		return;
	}

	echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>#</th><th>Type</th><th>Incoming record</th><th>Candidates</th><th>Actions</th></tr></thead><tbody>';
	foreach ( $pending as $row ) {
		$cands = (array) json_decode( (string) $row->candidates, true );
		echo '<tr>';
		echo '<td>' . (int) $row->id . '</td>';
		echo '<td>' . esc_html( $row->entity_type ) . '</td>';
		echo '<td><strong>' . esc_html( $row->label ) . '</strong><br><code>stat_id=' . (int) $row->stat_id . '</code></td>';
		echo '<td>';
		foreach ( $cands as $c ) {
			$link_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=f365stat_resolve&id=' . (int) $row->id . '&wp_id=' . (int) $c['wp_id'] ),
				'f365stat_resolve'
			);
			printf(
				'<a href="%s">Link → #%d “%s”</a> <small>(score %d: %s)</small><br>',
				esc_url( $link_url ), (int) $c['wp_id'], esc_html( $c['title'] ), (int) $c['score'], esc_html( $c['signals'] )
			);
		}
		echo '</td><td>';
		printf(
			'<a class="button button-small" href="%s">Create new</a> ',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=f365stat_resolve&id=' . (int) $row->id . '&decision=create' ), 'f365stat_resolve' ) )
		);
		printf(
			'<a class="button button-small" href="%s">Ignore</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=f365stat_resolve&id=' . (int) $row->id . '&decision=ignore' ), 'f365stat_resolve' ) )
		);
		echo '</td></tr>';
	}
	echo '</tbody></table>';
}

// ═══════════════════════════════════════════════════════════════════════════
// Live matches (f365_livegames posts + wp_live_livematch mirror rows)
//
// A different identity scheme from every other module: ensure_livegame()
// keys the backing post via wp_posts.legacy_id/legacy_table directly (no
// django_*_id postmeta, no api_source/api_id columns — a livegame has no
// Statorium id of its own, it's keyed to the underlying match's). It also
// sits among ~297k pre-existing legacy rows from the original Django
// migration, of which only the `_f365_source=statorium` ones are ours.
// ═══════════════════════════════════════════════════════════════════════════

function f365_statorium_data_render_livegames(): void {
	global $wpdb;

	$per_page = 50;
	$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	$day      = isset( $_GET['day'] ) ? sanitize_text_field( wp_unslash( $_GET['day'] ) ) : '';
	$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	if ( $day && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
		$day = '';
	}

	$imported_expr = "COALESCE( syn.meta_value, p.post_date )";

	$from = "FROM {$wpdb->posts} p
		  JOIN {$wpdb->prefix}live_livematch lm ON lm.id = p.legacy_id AND p.legacy_table = 'wp_live_livematch'
		  JOIN {$wpdb->postmeta} src ON src.post_id = p.ID AND src.meta_key = '_f365_source' AND src.meta_value = 'statorium'
		  LEFT JOIN {$wpdb->postmeta} syn ON syn.post_id = p.ID AND syn.meta_key = '_statorium_synced_at'
		  LEFT JOIN {$wpdb->posts} mp ON mp.legacy_id = lm.match_id AND mp.post_type = 'f365_match'
		  LEFT JOIN {$wpdb->postmeta} mstat ON mstat.post_id = mp.ID AND mstat.meta_key = 'statorium_status_id'
		 WHERE p.post_type = 'f365_livegames' AND p.post_status = 'publish'";

	$where = '';
	if ( 'live' === $status ) {
		$where .= " AND lm.status NOT IN ( '-1', 'RF' ) AND lm.status <> ''";
	} elseif ( 'scheduled' === $status ) {
		$where .= " AND lm.status = '-1'";
	} elseif ( 'finished' === $status ) {
		$where .= " AND lm.status = 'RF'";
	}
	if ( $day ) {
		$where .= $wpdb->prepare( " AND DATE( {$imported_expr} ) = %s", $day );
	}
	if ( '' !== $search ) {
		$like   = '%' . $wpdb->esc_like( $search ) . '%';
		$where .= $wpdb->prepare( ' AND ( lm.home_team_name LIKE %s OR lm.visitor_team_name LIKE %s )', $like, $like );
	}

	$counts = $wpdb->get_row(
		"SELECT COUNT(*) AS total,
		        SUM( lm.status = '-1' ) AS scheduled,
		        SUM( lm.status NOT IN ( '-1', 'RF' ) AND lm.status <> '' ) AS live,
		        SUM( lm.status = 'RF' ) AS finished,
		        SUM( mp.ID IS NULL ) AS orphan_match
		   {$from}"
	);
	$days = $wpdb->get_results(
		"SELECT DATE( {$imported_expr} ) AS d, COUNT(*) AS total, COUNT(*) AS created
		   {$from}
		  GROUP BY d ORDER BY d DESC LIMIT 30"
	);

	$total = (int) $wpdb->get_var( "SELECT COUNT(*) {$from} {$where}" );
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT p.ID, lm.id AS live_id, lm.match_id AS pub, lm.datetime, lm.status,
		        lm.home_score, lm.visitor_score, lm.home_team_name, lm.visitor_team_name,
		        lm.competition_id, mp.ID AS match_post_id, mp.api_id AS match_stat_id,
		        {$imported_expr} AS imported_at
		   {$from} {$where}
		  ORDER BY imported_at DESC, lm.id DESC
		  LIMIT %d OFFSET %d",
		$per_page, ( $paged - 1 ) * $per_page
	) );

	printf(
		'<p style="font-size:14px"><strong>%d</strong> live match rows from Statorium — '
		. '<span style="color:#996800"><strong>%d</strong> scheduled</span>, '
		. '<span style="color:#c00000"><strong>%d</strong> live</span>, '
		. '<span style="color:#0a7d33"><strong>%d</strong> finished</span>.%s</p>',
		(int) $counts->total,
		(int) $counts->scheduled,
		(int) $counts->live,
		(int) $counts->finished,
		(int) $counts->orphan_match
			? ' <span style="color:#d63638"><strong>' . (int) $counts->orphan_match . '</strong> row(s) have no matching f365_match post — possible orphan.</span>'
			: ''
	);
	echo '<p class="description">Backing posts for <a href="' . esc_url( home_url( '/jogos-em-direto/' ) ) . '" target="_blank">/jogos-em-direto/</a>. '
		. 'Keyed via <code>wp_posts.legacy_id</code> (not <code>api_id</code> — a live row has no Statorium id of its own, it mirrors the underlying match). '
		. 'Status: <code>-1</code> scheduled, a number = live minute, <code>RF</code> finished.</p>';

	f365_statorium_data_day_summary( $days, $day );

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'f365-statorium-data-livegames';
	echo '<form method="get" style="margin:8px 0 12px">';
	echo '<input type="hidden" name="page" value="' . esc_attr( $page ) . '">';
	if ( $day ) {
		echo '<input type="hidden" name="day" value="' . esc_attr( $day ) . '">';
	}
	echo '<select name="status"><option value="">Any status</option>'
		. '<option value="scheduled"' . selected( $status, 'scheduled', false ) . '>Scheduled</option>'
		. '<option value="live"' . selected( $status, 'live', false ) . '>Live now</option>'
		. '<option value="finished"' . selected( $status, 'finished', false ) . '>Finished</option></select> ';
	echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Search team…"> ';
	submit_button( 'Filter', 'secondary', '', false );
	echo '</form>';

	echo '<table class="widefat striped"><thead><tr>'
		. '<th>Teams</th><th style="width:130px">Kickoff</th><th style="width:110px">Status</th>'
		. '<th style="width:100px">Score</th><th style="width:130px">Imported</th><th>Links</th>'
		. '</tr></thead><tbody>';

	if ( ! $rows ) {
		echo '<tr><td colspan="6">Nothing found for this filter.</td></tr>';
	}

	foreach ( $rows as $r ) {
		$live_url  = home_url( '/jogos-em-direto/' . (int) $r->live_id . '/' );
		$match_url = $r->pub ? home_url( '/jogo/' . (int) $r->pub . '/' ) : '';

		$status_label = 'RF' === $r->status
			? '<span style="color:#0a7d33;font-weight:600">Finished</span>'
			: ( '-1' === (string) $r->status
				? '<span style="color:#996800;font-weight:600">Scheduled</span>'
				: '<span style="color:#c00000;font-weight:600">Live — ' . esc_html( (string) $r->status ) . "'</span>" );

		$links = '<a href="' . esc_url( $live_url ) . '" target="_blank" class="button button-small">Live page ↗</a>';
		if ( $match_url ) {
			$links .= ' <a href="' . esc_url( $match_url ) . '" target="_blank" class="button button-small">Match ↗</a>';
		} else {
			$links .= ' <span style="color:#d63638">no match post</span>';
		}

		printf(
			'<tr><td><strong>%s</strong> vs <strong>%s</strong><br><small>live row #%d, match pub %d%s</small></td>'
			. '<td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( (string) $r->home_team_name ),
			esc_html( (string) $r->visitor_team_name ),
			(int) $r->live_id,
			(int) $r->pub,
			$r->match_stat_id ? ', statorium #' . esc_html( (string) $r->match_stat_id ) : '',
			$r->datetime ? esc_html( mysql2date( 'Y-m-d H:i', $r->datetime ) ) : '—',
			$status_label,
			esc_html( trim( ( $r->home_score ?: '-' ) . ' - ' . ( $r->visitor_score ?: '-' ) ) ),
			$r->imported_at ? esc_html( mysql2date( 'Y-m-d', $r->imported_at ) ) : '—',
			$links
		);
	}
	echo '</tbody></table>';

	f365_statorium_data_pagination( $total, $per_page, $paged );
}

// ═══════════════════════════════════════════════════════════════════════════
// Shared UI bits
// ═══════════════════════════════════════════════════════════════════════════

/** Per-day activity strip: date · total (created). Click a day to filter. */
function f365_statorium_data_day_summary( array $days, string $active, string $noun = 'items' ): void {
	if ( ! $days ) {
		return;
	}
	echo '<h2 class="screen-reader-text">Per-day summary</h2><p style="line-height:2.2">';
	foreach ( $days as $d ) {
		if ( ! $d->d ) {
			// Items linked before per-day tracking existed (2026-07-23).
			printf(
				'<span style="background:#f0f0f1;color:#666;padding:4px 10px;margin-right:6px;border-radius:12px;display:inline-block">date not recorded — <strong>%d</strong></span>',
				(int) $d->total
			);
			continue;
		}
		$url   = add_query_arg( [ 'day' => $d->d, 'paged' => false ] );
		$style = $d->d === $active
			? 'background:#2271b1;color:#fff;'
			: 'background:#f0f0f1;';
		printf(
			'<a href="%s" style="%spadding:4px 10px;margin-right:6px;border-radius:12px;text-decoration:none;display:inline-block">%s — <strong>%d</strong>%s</a>',
			esc_url( $url ),
			esc_attr( $style ),
			esc_html( mysql2date( 'd M Y', $d->d ) ),
			(int) $d->total,
			isset( $d->created ) && (int) $d->created !== (int) $d->total
				? ' <small>(' . (int) $d->created . ' created)</small>' : ''
		);
	}
	if ( $active ) {
		echo '<a href="' . esc_url( remove_query_arg( [ 'day', 'paged' ] ) ) . '" style="padding:4px 10px">× clear day filter</a>';
	}
	echo '</p>';
}

function f365_statorium_data_filters( string $day, string $origin, string $search, bool $show_origin ): void {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'f365-statorium-data';
	echo '<form method="get" style="margin:8px 0 12px">';
	echo '<input type="hidden" name="page" value="' . esc_attr( $page ) . '">';
	if ( $day ) {
		echo '<input type="hidden" name="day" value="' . esc_attr( $day ) . '">';
	}
	if ( $show_origin ) {
		echo '<select name="origin"><option value="">Created + linked</option>'
			. '<option value="created"' . selected( $origin, 'created', false ) . '>Created only</option>'
			. '<option value="linked"' . selected( $origin, 'linked', false ) . '>Linked legacy only</option></select> ';
	}
	echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Search name…"> ';
	submit_button( 'Filter', 'secondary', '', false );
	echo '</form>';
}

function f365_statorium_data_pagination( int $total, int $per_page, int $paged ): void {
	$pages = (int) ceil( $total / $per_page );
	echo '<p><strong>' . (int) $total . '</strong> item(s).</p>';
	if ( $pages < 2 ) {
		return;
	}
	echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( [
		'base'    => add_query_arg( 'paged', '%#%' ),
		'format'  => '',
		'current' => $paged,
		'total'   => $pages,
	] ) . '</div></div>';
}
