<?php
/**
 * Header match ticker — the 4-tab bar (Jogos em direto / terminados /
 * próximos / na televisão), faithful port of the legacy Django widget
 * (`/highlight-*-matches/` AJAX endpoints) using the mirror tables.
 *
 * - Shortcode: [f365_match_ticker tab="inlive|finished|tostart|ontv"]
 * - Auto-injection: hooked on wp_footer; fills the theme header's
 *   #topTab-{1..4}-pane placeholders via a tiny inline script. Rendering in
 *   the footer keeps the ticker LIVE even on pages served by the F365 Cache
 *   fragment cache (header/footer always execute).
 * - Markup matches the legacy cards exactly (theme CSS already styles them):
 *   li.cardLiveGame > a > ul.competicaodatahora + ul.equipasresultado
 * - Enable/disable in Settings → Statorium Sync.
 *
 * Odds note: the legacy site showed no per-match odds in these cards (only
 * the Bet365 banner + Probabilidades page); Statorium odds are a premium
 * add-on not active on this key. When activated, odds can be added to the
 * card footer here.
 *
 * @package F365_Statorium
 */

defined( 'ABSPATH' ) || exit;

/**
 * Query the cards for one tab. Returns array of row objects.
 */
function f365_ticker_rows( string $tab, int $limit = 14 ): array {
	global $wpdb;
	$p = $wpdb->prefix;

	$cache_key = 'f365_ticker_' . $tab;
	$cached    = get_transient( $cache_key );
	if ( false !== $cached && is_array( $cached ) ) {
		return $cached;
	}

	$select = "SELECT m.id, m.date_time, m.situation, m.home_score, m.visitor_score,
	                  m.home_score_extra, m.visitor_score_extra, m.penalty_home_score, m.penalty_visitor_score,
	                  m.home_team_display_name, m.visitor_team_display_name,
	                  ht.team_id AS home_tid, vt.team_id AS visitor_tid,
	                  htm.shortname AS home_name, vtm.shortname AS visitor_name,
	                  c.name AS comp_name, c.country_id AS comp_country,
	                  fc.federation_id AS comp_federation";
	$from   = " FROM {$p}competition_match m
	            JOIN {$p}team_teamstep ht ON ht.id = m.home_team_id
	            JOIN {$p}team_teamstep vt ON vt.id = m.visitor_team_id
	            JOIN {$p}team_team htm ON htm.id = ht.team_id
	            JOIN {$p}team_team vtm ON vtm.id = vt.team_id
	            JOIN {$p}competition_competition c ON c.id = m.competition_id
	            LEFT JOIN {$p}federation_federationcompetition fc ON fc.id = c.federation_competition_id";
	$highlight = " AND ( c.is_highlight IN ('t','1') OR fc.is_highlight IN ('t','1') )";

	switch ( $tab ) {
		case 'inlive':
			$where = " WHERE m.date_time BETWEEN DATE_SUB(NOW(), INTERVAL 140 MINUTE) AND NOW()
			           AND ( m.situation IS NULL OR m.situation = '' )";
			$order = ' ORDER BY m.date_time ASC';
			break;

		case 'finished':
			$where = " WHERE m.situation = 'finished'
			           AND m.date_time > DATE_SUB(NOW(), INTERVAL 7 DAY)";
			$order = ' ORDER BY m.date_time DESC';
			break;

		case 'tostart':
			$where = ' WHERE m.date_time > NOW() AND ( m.situation IS NULL OR m.situation = "" )';
			$order = ' ORDER BY m.date_time ASC';
			break;

		case 'ontv':
			$select .= ', tv.name AS tv_channel, mt.datetime AS tv_datetime';
			$from   .= " JOIN {$p}live_matchontv mt ON mt.match_id = m.id
			             LEFT JOIN {$p}multimedia_tvchannel tv ON tv.id = mt.channel_id";
			$where   = ' WHERE mt.datetime > NOW()';
			$order   = ' ORDER BY mt.datetime ASC';
			break;

		default:
			return [];
	}

	$rows = $wpdb->get_results( $select . $from . $where . $highlight . $order . ' LIMIT ' . (int) $limit );
	if ( ! $rows ) {
		// Highlight filter can starve the list — fall back to any competition.
		$rows = $wpdb->get_results( $select . $from . $where . $order . ' LIMIT ' . (int) $limit );
	}
	if ( 'finished' === $tab && count( (array) $rows ) < $limit ) {
		// The tab must always carry the full card count: top up the 7-day
		// window with the latest OLDER finished matches (highlight-preferred,
		// then any), excluding cards already selected.
		$rows    = (array) $rows;
		$have    = array_map( fn( $r ) => (int) $r->id, $rows );
		$exclude = $have ? ' AND m.id NOT IN (' . implode( ',', $have ) . ')' : '';
		$missing = $limit - count( $rows );

		$no_window = " WHERE m.situation = 'finished' AND m.date_time <= NOW()" . $exclude;
		$more      = $wpdb->get_results( $select . $from . $no_window . $highlight . $order . ' LIMIT ' . (int) $missing );
		$rows      = array_merge( $rows, (array) $more );

		if ( count( $rows ) < $limit ) {
			$have    = array_map( fn( $r ) => (int) $r->id, $rows );
			$exclude = $have ? ' AND m.id NOT IN (' . implode( ',', $have ) . ')' : '';
			$more    = $wpdb->get_results( $select . $from . " WHERE m.situation = 'finished' AND m.date_time <= NOW()" . $exclude . $order . ' LIMIT ' . ( $limit - count( $rows ) ) );
			$rows    = array_merge( $rows, (array) $more );
		}
	}

	$rows = is_array( $rows ) ? $rows : [];
	set_transient( $cache_key, $rows, MINUTE_IN_SECONDS );
	return $rows;
}

/** Status cell text for a card. */
function f365_ticker_status( string $tab, object $r ): string {
	if ( 'finished' === $tab ) {
		return 'RF';
	}
	if ( 'ontv' === $tab ) {
		return esc_html( (string) ( $r->tv_channel ?? '' ) );
	}
	if ( 'inlive' === $tab ) {
		// No fabricated minute count — it was computed purely from
		// date_time vs a raw SQL NOW() (server-timezone-dependent, proven
		// off by hours from the site's real local time) and had no
		// connection to Statorium's actual matchTicker value. A fixed
		// label avoids implying precision the data doesn't have.
		return 'AO VIVO';
	}
	return '';
}

/** Score cell text for a card. */
function f365_ticker_score( string $tab, object $r ): string {
	if ( 'tostart' === $tab || 'ontv' === $tab ) {
		return '-';
	}
	if ( null === $r->home_score || '' === (string) $r->home_score ) {
		return '-';
	}
	$score = (int) $r->home_score . ' - ' . (int) $r->visitor_score;
	if ( null !== $r->penalty_home_score && '' !== (string) $r->penalty_home_score ) {
		$score .= ' g.p.';
	} elseif ( null !== $r->home_score_extra && '' !== (string) $r->home_score_extra ) {
		$score .= ' a.p';
	}
	return $score;
}

/**
 * Render one tab's slider (legacy markup, styled by the theme CSS).
 */
function f365_ticker_render( string $tab ): string {
	$rows     = f365_ticker_rows( $tab );
	$odds_map = (array) get_option( F365_Statorium_Importer::ODDS_OPTION, [] );

	ob_start();
	?>
	<div class="slider">
		<span class="previous" aria-hidden="true"><svg class="slider-arrow" width="1" height="1" focusable="false"><use href="#icon-chevron-left"></use></svg></span>
		<div class="wrapper">
			<ul>
				<?php if ( ! $rows ) : ?>
					<li class="noGame"><?php esc_html_e( 'Sem informação disponível', 'f365-statorium' ); ?></li>
				<?php else : ?>
					<?php foreach ( $rows as $r ) :
						$flag = $r->comp_country
							? 'flag-icon-' . (int) $r->comp_country
							: 'flag-icon-conf_' . (int) $r->comp_federation;
						$home = $r->home_team_display_name ?: $r->home_name;
						$away = $r->visitor_team_display_name ?: $r->visitor_name;
						$dt   = ( 'ontv' === $tab && ! empty( $r->tv_datetime ) ) ? $r->tv_datetime : $r->date_time;
						?>
						<li class="cardLiveGame">
							<a href="<?php echo esc_url( home_url( '/jogo/' . (int) $r->id . '/' ) ); ?>">
								<ul class="competicaodatahora">
									<li class="competicao" title="<?php echo esc_attr( $r->comp_name ); ?>"><i class="flag-icon <?php echo esc_attr( $flag ); ?>"></i><span><?php echo esc_html( $r->comp_name ); ?></span></li>
									<li class="datahorastatus">
										<div class="datahora"><?php echo esc_html( date_i18n( 'd-m / H:i', strtotime( $dt ) ) ); ?></div>
										<div class="status"><span class="negrito<?php echo 'inlive' === $tab ? ' live' : ''; ?>"><?php echo esc_html( f365_ticker_status( $tab, $r ) ); ?></span></div>
									</li>
								</ul>
								<ul class="equipasresultado">
									<li class="equipas">
										<div class="hometeam"><?php echo esc_html( $home ); ?></div>
										<div class="visitorteam"><?php echo esc_html( $away ); ?></div>
									</li>
									<?php
									// Cell priority: 1st ODDS (when available), 2nd score.
									// Finished matches always show the final score — odds
									// on a completed match would mislead.
									$score_class = 'finished' === $tab ? 'finished' : ( 'inlive' === $tab ? 'live' : '' );
									$odds        = 'finished' !== $tab ? ( $odds_map[ (int) $r->id ] ?? null ) : null;
									if ( $odds ) : ?>
										<li class="resultado"><span class="oddscell" title="<?php echo esc_attr( $odds['bk'] ); ?>"><b>1</b><?php echo esc_html( number_format( (float) $odds['1'], 2 ) ); ?><br><b>X</b><?php echo esc_html( number_format( (float) $odds['x'], 2 ) ); ?><br><b>2</b><?php echo esc_html( number_format( (float) $odds['2'], 2 ) ); ?></span></li>
									<?php else : ?>
										<li class="resultado"><span<?php echo $score_class ? ' class="' . esc_attr( $score_class ) . '"' : ''; ?>><?php echo esc_html( f365_ticker_score( $tab, $r ) ); ?></span></li>
									<?php endif; ?>
								</ul>
							</a>
						</li>
					<?php endforeach; ?>
				<?php endif; ?>
			</ul>
		</div>
		<span class="next" aria-hidden="true"><svg class="slider-arrow" width="1" height="1" focusable="false"><use href="#icon-chevron-right"></use></svg></span>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Shortcode: [f365_match_ticker tab="inlive"]
 */
add_shortcode( 'f365_match_ticker', function ( $atts ): string {
	$atts = shortcode_atts( [ 'tab' => 'inlive' ], $atts, 'f365_match_ticker' );
	$tab  = in_array( $atts['tab'], [ 'inlive', 'finished', 'tostart', 'ontv' ], true ) ? $atts['tab'] : 'inlive';
	return f365_ticker_render( $tab );
} );

/**
 * Header injection: the theme header ships placeholder panes
 * (#topTab-1..4-pane). Rendered in wp_footer so the data is live on every
 * request (fragment cache and header-shell object cache never freeze it),
 * then moved into the panes by a tiny script.
 */
add_action( 'wp_footer', function () {
	if ( is_admin() ) {
		return;
	}
	$settings = F365_Statorium_Importer::settings();
	if ( empty( $settings['ticker_ui'] ) ) {
		return;
	}

	$tabs = [ 1 => 'inlive', 2 => 'finished', 3 => 'tostart', 4 => 'ontv' ];

	// "Hide empty tabs": computed here (not in JS) because f365_ticker_rows()
	// is transient-cached — this costs nothing extra, f365_ticker_render()
	// below reuses the same cached result instead of re-querying. If EVERY
	// tab is empty, leave all four visible rather than collapsing the bar to
	// nothing (still better than a fully-empty header widget).
	$empty_tabs = [];
	if ( ! empty( $settings['hide_empty_tabs'] ) ) {
		foreach ( $tabs as $n => $tab ) {
			if ( ! f365_ticker_rows( $tab ) ) {
				$empty_tabs[] = $n;
			}
		}
		if ( count( $empty_tabs ) === count( $tabs ) ) {
			$empty_tabs = [];
		}
	}
	?>
	<style id="f365-ticker-css">
	/* Completes the theme's legacy slider CSS: the horizontal scroll strip
	   (.wrapper) was only defined in the old static stylesheet. Scoped to the
	   header bar; colors/cards come from the theme. */
	.top-tab-block .slider { display: flex; align-items: stretch; overflow: hidden; }
	.top-tab-block .slider .previous,
	.top-tab-block .slider .next { flex: 0 0 42px; height: auto; min-height: 118px; }
	.top-tab-block .slider .wrapper { flex: 1 1 auto; overflow: hidden; }
	/* Flex applies ONLY to the card strip — the lists inside each card keep
	   the legacy stacked layout (competition line, then date/status line). */
	.top-tab-block .slider .wrapper > ul { display: flex; flex-wrap: nowrap; width: max-content; min-width: 100%; transform: translateX(0); transition: transform .35s ease; }
	.top-tab-block .slider .cardLiveGame { float: none; flex: 0 0 auto; width: 224px; height: 118px; border-right: 1px solid #ddd; box-sizing: border-box; }
	.top-tab-block .slider .cardLiveGame a { display: flex; flex-direction: column; height: 100%; text-decoration: none; padding-bottom: 6px; box-sizing: border-box; }
	.top-tab-block .slider .cardLiveGame ul { display: block; width: auto; min-width: 0; transform: none; transition: none; }
	/* Vertical rhythm: the legacy .competicao top padding (12px) plus the old
	   8px here left all the air ABOVE the content and none below; club-league
	   competition names ("UEFA Champions League 2026-27 Qualifying") also
	   wrapped to two lines and shoved the teams against the card's bottom
	   edge. Balance the padding, pin teams/score to the bottom, and keep the
	   competition on ONE ellipsized line (full name on hover). */
	.top-tab-block .slider .competicaodatahora { padding-top: 0; max-width: 100%; }
	/* .competicao is a flex container inside a column-flex <a> (line above,
	   .cardLiveGame a). As a flex item its default min-width:auto floors it
	   at its content's min-content size — and since the long club-league
	   names below are nowrap, that min-content size is the FULL unwrapped
	   text width (~245px for "UEFA Champions League 2026-27 Qualifying" vs
	   a 224px card), so the box grew past the card edge and visibly spilled
	   into the next card instead of respecting overflow:hidden/ellipsis.
	   min-width:0 + max-width:100% here (not just on the span) makes the
	   container itself shrinkable so the ellipsis on the span can actually
	   take effect within the card's real width. */
	.top-tab-block .slider .competicao { padding: 6px 8px 2px; display: flex; align-items: center; white-space: nowrap; overflow: hidden; min-width: 0; max-width: 100%; box-sizing: border-box; }
	.top-tab-block .slider .competicao span { overflow: hidden; text-overflow: ellipsis; min-width: 0; }
	.top-tab-block .slider .competicao .flag-icon { flex: 0 0 auto; }
	.top-tab-block .slider .equipasresultado { margin-top: auto; }
	.top-tab-block .slider .datahorastatus::after,
	.top-tab-block .slider .equipasresultado::after { content: ""; display: block; clear: both; }
	.top-tab-block .slider .datahorastatus .status { margin-left: 6px; }
	.top-tab-block .slider .equipasresultado { display: block; width: 100%; box-sizing: border-box; }
	.top-tab-block .slider .noGame { margin: 40px auto; text-align: center; flex: 1 1 auto; }
	.top-tab-block .slider .resultado .oddscell { display: block; font-size: 9px; line-height: 11px; font-weight: 600; color: #333; text-align: center; }
	.top-tab-block .slider .resultado .oddscell b { font-weight: 700; color: #888; margin-right: 2px; }
	.top-tab-block .slider .odds1x2 { display: flex !important; gap: 4px; margin: 2px 8px 0; padding: 0; list-style: none; }
	.top-tab-block .slider .odds1x2 li { flex: 1 1 0; text-align: center; font-size: 10px; line-height: 14px; background: #f0f0f1; border-radius: 3px; color: #333; }
	.top-tab-block .slider .odds1x2 li span { font-weight: 700; margin-right: 3px; color: #777; }
	</style>
	<?php
	echo '<div id="f365-ticker-payload" hidden>';
	foreach ( $tabs as $n => $tab ) {
		echo '<template data-pane="topTab-' . (int) $n . '-pane">' . f365_ticker_render( $tab ) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</div>';
	?>
	<script>
	(function () {
		var payload = document.getElementById('f365-ticker-payload');
		if (!payload) return;
		payload.querySelectorAll('template').forEach(function (tpl) {
			var pane = document.getElementById(tpl.dataset.pane);
			if (pane) {
				pane.innerHTML = '';
				pane.appendChild(tpl.content.cloneNode(true));
			}
		});
		// The theme hides the empty placeholder bar (.top-tab-block{display:none})
		// — reveal it only now that it holds real content.
		var bar = document.querySelector('.top-tab-block');
		if (bar) bar.style.display = 'block';
		// "Hide empty tabs" setting: $empty_tabs was computed server-side from
		// the same cached rows the templates above were built from.
		var emptyTabs = <?php echo wp_json_encode( array_values( $empty_tabs ) ); ?>;
		if (emptyTabs.length && bar) {
			var activeBtn = bar.querySelector('.nav-link.active');
			emptyTabs.forEach(function (n) {
				var btn = document.getElementById('topTab-' + n);
				var item = btn && btn.closest('.nav-item');
				if (item) item.style.display = 'none';
			});
			if (activeBtn && emptyTabs.indexOf(parseInt(activeBtn.id.replace('topTab-', ''), 10)) !== -1) {
				var firstVisibleBtn = bar.querySelector('.nav-item:not([style*="display: none"]) .nav-link');
				if (firstVisibleBtn) {
					activeBtn.classList.remove('active');
					activeBtn.setAttribute('aria-selected', 'false');
					var oldPane = document.getElementById(activeBtn.getAttribute('aria-controls'));
					if (oldPane) oldPane.classList.remove('show', 'active');
					firstVisibleBtn.classList.add('active');
					firstVisibleBtn.setAttribute('aria-selected', 'true');
					var newPane = document.getElementById(firstVisibleBtn.getAttribute('aria-controls'));
					if (newPane) newPane.classList.add('show', 'active');
				}
			}
		}
		// Slider arrows: slide the card strip (legacy technique — translate the
		// UL) by ~3 cards, clamped to the strip bounds.
		document.querySelectorAll('.top-tab-block .slider').forEach(function (slider) {
			var wrap = slider.querySelector('.wrapper');
			var list = wrap && wrap.querySelector('ul');
			if (!list) return;
			var offset = 0;
			var step = 3 * 224;
			function slide(delta) {
				var max = Math.max(0, list.scrollWidth - wrap.clientWidth);
				offset = Math.min(max, Math.max(0, offset + delta));
				list.style.transform = 'translateX(-' + offset + 'px)';
			}
			var prev = slider.querySelector('.previous');
			var next = slider.querySelector('.next');
			if (prev) prev.addEventListener('click', function () { slide(-step); });
			if (next) next.addEventListener('click', function () { slide(step); });
		});
	})();
	</script>
	<?php
} );
