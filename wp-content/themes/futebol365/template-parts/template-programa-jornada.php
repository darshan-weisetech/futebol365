<?php
/**
 * Match players / lineup  (template-parts/match-players.php)
 *
 * Reads the Django MIRROR TABLES directly (no ACF / postmeta):
 *   {prefix}competition_matchplayer  (match_id, team_id[=teamstep], player_id, position,
 *                                      minutes, is_titular, is_in/out(_minute),
 *                                      goals, goals_penalty, own_goals,
 *                                      yellow/double_yellow/red_card(_minute), shirt_number)
 *   {prefix}person_person            (id -> shortname, slug)
 *   {prefix}competition_match        (id -> home_team_id / visitor_team_id [teamstep ids])
 *   {prefix}team_teamstep -> team_team (teamstep id -> club shortname)
 *
 * $args['match_id'] = f365_match WP post id (resolved to Django id via legacy_id),
 * or pass the Django match id directly.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

$match_postid = absint( $args['match_id'] ?? 0 );
if ( ! $match_postid ) {
	return;
}

global $wpdb;
$mt = $wpdb->prefix;

/* WP post id -> Django match id. */
$match_id = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT legacy_id FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $match_postid )
);
if ( ! $match_id ) {
	$match_id = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT id FROM {$mt}competition_match WHERE id = %d LIMIT 1", $match_postid )
	);
}
if ( ! $match_id ) {
	return;
}

/* Match: home/visitor teamstep ids + club names. */
$match = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT
			m.home_team_id, m.visitor_team_id,
			hteam.shortname AS home_name,
			vteam.shortname AS visitor_name
		FROM {$mt}competition_match m
		LEFT JOIN {$mt}team_teamstep hts ON hts.id = m.home_team_id
		LEFT JOIN {$mt}team_team     hteam ON hteam.id = hts.team_id
		LEFT JOIN {$mt}team_teamstep vts ON vts.id = m.visitor_team_id
		LEFT JOIN {$mt}team_team     vteam ON vteam.id = vts.team_id
		WHERE m.id = %d
		LIMIT 1",
		$match_id
	)
);
if ( ! $match ) {
	return;
}

/* All players of this match (one query). */
$players = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT
			mp.team_id, mp.player_id, mp.position, mp.minutes, mp.shirt_number,
			mp.is_titular, mp.is_in, mp.is_out, mp.is_in_minute, mp.is_out_minute,
			mp.goals, mp.goals_penalty, mp.own_goals,
			mp.yellow_card, mp.double_yellow_card, mp.red_card,
			mp.yellow_card_minute, mp.double_yellow_card_minute, mp.red_card_minute,
			pe.shortname AS player_name
		FROM {$mt}competition_matchplayer mp
		INNER JOIN {$mt}person_person pe ON pe.id = mp.player_id
		WHERE mp.match_id = %d
		ORDER BY mp.is_titular DESC, mp.shirt_number ASC, pe.shortname ASC",
		$match_id
	)
);
if ( empty( $players ) ) {
	return;
}

/* Map person ids -> f365_player WP posts (batched) for links. */
$person_ids = array_values( array_unique( array_map( static fn( $p ) => (int) $p->player_id, $players ) ) );
$player_post = array();
if ( $person_ids ) {
	$in   = implode( ',', $person_ids );
	$rows = $wpdb->get_results(
		"SELECT ID, legacy_id FROM {$wpdb->posts}
		  WHERE legacy_id IN ($in) AND post_type = 'f365_player' AND post_status = 'publish'"
	);
	foreach ( $rows as $r ) {
		$player_post[ (int) $r->legacy_id ] = (int) $r->ID;
	}
	if ( $player_post ) {
		_prime_post_caches( array_values( $player_post ), false, false );
	}
}

/* Split into home / visitor by teamstep id. */
$home = array();
$away = array();
foreach ( $players as $p ) {
	if ( (int) $p->team_id === (int) $match->home_team_id ) {
		$home[] = $p;
	} elseif ( (int) $p->team_id === (int) $match->visitor_team_id ) {
		$away[] = $p;
	}
}

$pos_labels = array(
	'goalkeeper' => __( 'Guarda-redes', 'f365' ),
	'defender'   => __( 'Defesa', 'f365' ),
	'midfielder' => __( 'Médio', 'f365' ),
	'forward'    => __( 'Avançado', 'f365' ),
);

/* Renders one player line with proper goal/card/sub markers. */
$render_player = static function ( $p ) use ( $player_post, $pos_labels ) {
	$name = $p->player_name;
	$pid  = $player_post[ (int) $p->player_id ] ?? 0;
	$link = $pid ? get_permalink( $pid ) : '';

	$marks = array();

	$goals = (int) $p->goals + (int) $p->goals_penalty;
	if ( $goals > 0 ) {
		$g = ( $goals > 1 ) ? 'Golos x' . $goals : 'Golo';
		if ( (int) $p->goals_penalty > 0 ) {
			$g .= ' (' . (int) $p->goals_penalty . ' gp)';
		}
		$marks[] = $g;
	}
	if ( (int) $p->own_goals > 0 ) {
		$marks[] = 'Autogolo';
	}
	if ( 't' === $p->yellow_card ) {
		$marks[] = 'Amarelo' . ( $p->yellow_card_minute ? ' ' . (int) $p->yellow_card_minute . "'" : '' );
	}
	if ( 't' === $p->double_yellow_card ) {
		$marks[] = 'Duplo amarelo' . ( $p->double_yellow_card_minute ? ' ' . (int) $p->double_yellow_card_minute . "'" : '' );
	}
	if ( 't' === $p->red_card ) {
		$marks[] = 'Vermelho' . ( $p->red_card_minute ? ' ' . (int) $p->red_card_minute . "'" : '' );
	}
	if ( 't' === $p->is_out && $p->is_out_minute ) {
		$marks[] = 'Saiu ' . (int) $p->is_out_minute . "'";
	}
	if ( 't' === $p->is_in && $p->is_in_minute ) {
		$marks[] = 'Entrou ' . (int) $p->is_in_minute . "'";
	}

	$shirt = $p->shirt_number ? (int) $p->shirt_number : '';
	$pos   = $pos_labels[ $p->position ] ?? '';
	?>
	<div class="match-player">
		<span class="mp-shirt"><?php echo esc_html( $shirt ); ?></span>
		<?php if ( $link ) : ?>
			<a class="mp-name" href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $name ); ?></a>
		<?php else : ?>
			<span class="mp-name"><?php echo esc_html( $name ); ?></span>
		<?php endif; ?>
		<?php if ( $pos ) : ?><span class="mp-pos"><?php echo esc_html( $pos ); ?></span><?php endif; ?>
		<?php if ( $marks ) : ?><span class="mp-marks"><?php echo esc_html( implode( ' ', $marks ) ); ?></span><?php endif; ?>
	</div>
	<?php
};

$render_team = static function ( $list, $title ) use ( $render_player ) {
	$titulares = array_filter( $list, static fn( $p ) => 't' === $p->is_titular );
	$suplentes = array_filter( $list, static fn( $p ) => 't' !== $p->is_titular );
	?>
	<div class="match-team-col">
		<b class="large"><?php echo esc_html( $title ); ?></b>
		<?php foreach ( $titulares as $p ) {
			$render_player( $p );
		} ?>
		<?php if ( $suplentes ) : ?>
			<div class="mp-subs-head"><b><?php esc_html_e( 'Suplentes', 'f365' ); ?></b></div>
			<?php foreach ( $suplentes as $p ) {
				$render_player( $p );
			} ?>
		<?php endif; ?>
	</div>
	<?php
};
?>

<div class="conteudo match-players">
	<b class="large"><?php esc_html_e( 'Jogadores', 'f365' ); ?></b>
	<div class="match-players-grid">
		<?php
		$render_team( $home, $match->home_name ?: __( 'Casa', 'f365' ) );
		$render_team( $away, $match->visitor_name ?: __( 'Visitante', 'f365' ) );
		?>
	</div>
</div>
