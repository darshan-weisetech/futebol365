<?php
/**
 * Live game helper functions + raw-SQL live readers.
 *
 * Loaded once per request by single-f365_livegames.php.
 *
 * @package Futebol365
 */
defined('ABSPATH') || exit;

if ( ! function_exists( 'f365_livegame_array' ) ) {

	/**
	 * Normalize a repeater, serialized value or JSON value to an array.
	 */
	function f365_livegame_array( $value ) {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				$value = $decoded;
			} else {
				$value = maybe_unserialize( $value );
			}
		}
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Read the first available key from an array or object.
	 */
	function f365_livegame_value( $item, $keys, $default = '' ) {
		foreach ( $keys as $key ) {
			if ( is_array( $item ) && array_key_exists( $key, $item ) ) {
				return $item[ $key ];
			}
			if ( is_object( $item ) && isset( $item->{$key} ) ) {
				return $item->{$key};
			}
		}
		return $default;
	}

	/**
	 * Determine whether a stored boolean is true.
	 */
	function f365_livegame_bool( $value ) {
		if ( is_bool( $value ) )    { return $value; }
		if ( is_numeric( $value ) ) { return (int) $value === 1; }
		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, array( '1', 'true', 'yes', 'on', 't' ), true );
	}

	/**
	 * Return a human-readable match status.
	 */
	function f365_livegame_status( $status ) {
		$labels = array(
			'-1' => '',
			'AD'  => 'AD',
			'SP'  => 'SP',
			'RF'  => 'RF',
			'HT'  => 'HT',
			'ET'  => 'ET',
			'P'   => 'Penáltis',
		);
		if ( isset( $labels[ $status ] ) ) {
			return $labels[ $status ];
		}
		return is_numeric( $status ) ? $status . "'" : $status;
	}

	function f365_livegame_media_url() {
        if ( defined( 'F365_MEDIA_URL' ) ) {
            return trailingslashit( F365_MEDIA_URL );
        }

        $upload = wp_upload_dir();

        return trailingslashit( $upload['baseurl'] . '/f365' );
    }

function f365_livegame_media_root() {
	if ( defined( 'F365_MEDIA_ROOT' ) ) {
		return untrailingslashit( F365_MEDIA_ROOT );
	}

	$upload = wp_upload_dir();

	return untrailingslashit( $upload['basedir'] . '/f365' );
}

function f365_livegame_image_url( $path, $filename, $ext ) {
	$path     = trailingslashit( ltrim( (string) $path, '/' ) );
	$filename = sanitize_file_name( (string) $filename );
	$ext      = ltrim( sanitize_file_name( (string) $ext ), '.' );

	if ( '' === $filename ) {
		$filename = 'no-image';
	}

	$relative      = $path . $filename . '.' . $ext;
	$fallback      = $path . 'no-image.' . $ext;
	$local_root    = trailingslashit( f365_livegame_media_root() );
	$local_file    = $local_root . $relative;
	$fallback_file = $local_root . $fallback;

	if ( file_exists( $local_file ) ) {
		return f365_livegame_media_url() . $relative;
	}

	if ( file_exists( $fallback_file ) ) {
		return f365_livegame_media_url() . $fallback;
	}

	if ( defined( 'F365_REMOTE_MEDIA_URL' ) ) {
		return trailingslashit( F365_REMOTE_MEDIA_URL ) . $relative;
	}

	return 'https://media.futebol365.pt/' . $relative;
}


	/* =====================================================================
	 * LOCAL /media RESOLVER — mirrors Django common.templatetags.get_image_in_path()
	 * so logos load straight from the root /media folder. No wp_posts / wp_postmeta.
	 *
	 *   {MEDIA_URL}images/logotipo/100x100/{team_team.id}.gif   (file exists)
	 *   theme /assets/images/no-image-list.jpg                  (otherwise)
	 * ================================================================== */

	/**
	 * Shared "no image" placeholder URL — the theme asset used as the fallback for
	 * any missing logo/image. Override with the f365_livegame_no_image_url filter.
	 */
	function f365_livegame_no_image_url() {
		$base = defined( 'FB_THEME_URL' ) ? FB_THEME_URL : get_template_directory_uri();
		return apply_filters( 'f365_livegame_no_image_url', $base . '/assets/images/no-image-list.jpg' );
	}

	/**
	 * Base URL (no trailing slash) for the local /media folder — Django MEDIA_URL.
	 * Defaults to the site's /media/; override with the F365_MEDIA_URL constant or
	 * the f365_media_base_url filter (e.g. a CDN such as https://media.futebol365.pt).
	 */
	function f365_media_base_url() {
		$base = defined( 'F365_MEDIA_URL' ) ? F365_MEDIA_URL : home_url( '/media/' );
		return untrailingslashit( apply_filters( 'f365_media_base_url', $base ) );
	}

	/**
	 * Filesystem root (no trailing slash) for the same /media folder — Django
	 * MEDIA_ROOT. Used only for the optional file-exists check.
	 *
	 * Defaults to '' (check skipped) so the real /media URL is always emitted:
	 * on subdirectory / aliased / symlinked deployments the /media files are
	 * web-served from a path the PHP process cannot stat, so ABSPATH . 'media'
	 * made every logo fall back to the placeholder even though the URL is
	 * publicly reachable. Define F365_MEDIA_ROOT (or use the f365_media_root_path
	 * filter) to point at the real on-disk media folder to re-enable the check.
	 * Either way the <img onerror> handlers still swap a genuinely missing image
	 * to the no-image placeholder client-side.
	 */
	function f365_media_root_path() {
		$root = defined( 'F365_MEDIA_ROOT' ) ? F365_MEDIA_ROOT : '';
		return untrailingslashit( (string) apply_filters( 'f365_media_root_path', $root ) );
	}

	/**
	 * Build a media URL like Django get_image_in_path( path, filename, ext ).
	 * When the file is missing on disk it returns $fallback_url if provided (e.g.
	 * the theme placeholder), otherwise the same folder's no-image.{ext}.
	 */
	function f365_media_image_url( $path, $filename, $ext, $fallback_url = '' ) {
		$path     = trailingslashit( ltrim( (string) $path, '/' ) );
		$filename = sanitize_file_name( (string) $filename );
		$ext      = ltrim( sanitize_file_name( (string) $ext ), '.' );
		if ( '' === $filename ) {
			$filename = 'no-image';
		}

		$relative = $path . $filename . '.' . $ext;
		$root     = f365_media_root_path();

		// When MEDIA_ROOT is reachable and the file is missing, use the caller's
		// placeholder (if any), else fall back to the folder's own no-image.{ext}.
		if ( $root && @is_dir( $root ) && ! @file_exists( $root . '/' . $relative ) ) {
			if ( '' !== (string) $fallback_url ) {
				return $fallback_url;
			}
			$relative = $path . 'no-image.' . $ext;
		}

		return f365_media_base_url() . '/' . $relative;
	}

	/**
	 * Convenience: a team logo URL by Django team id (team_team.id).
	 *   images/logotipo/100x100/{team_id}.gif  ->  theme no-image-list.jpg
	 */
	function f365_team_logo_url( $team_id ) {
		$team_id = (int) $team_id;
		$base = f365_src_base_url( F365_MEDIA_DIR ) . '/images/logotipo/100x100/';

		if ( $team_id > 0 ) {
			return $base . $team_id . '.gif';
		}
		return $base . 'no-image.gif';
	}


	function f365_livegame_team_url( $team_id, $team_type = '', $competition_id = 0, $edition = '', $modality = 'football', $gender = 'male', $step = 'first' ) {
		$team_id = (int) $team_id;
		if ( ! $team_id ) {
			return '';
		}

		$url = home_url( '/equipa/' . $team_id . '/jogos/' );
		$args = array_filter(
			array(
				'edition'  => $edition,
				'modality' => $modality,
				'gender'   => $gender,
				'step'     => $step,
			),
			static function ( $value ) {
				return '' !== (string) $value;
			}
		);

		if ( 'national' === $team_type && $competition_id ) {
			$args['competition'] = (int) $competition_id;
		}

		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * Print a team name, linked when a Django team URL exists.
	 */
	function f365_livegame_team_name($team_id, $name)
    {
        // Only the name is rendered — the surrounding template already wraps it in
        // the team link. (Previously this ran get_permalink() on the Django team id
        // and discarded the URL, i.e. a wasted post query per team name — costly on
        // the archive list where it fired for every match.)
        printf(
            '<h6 class="match-team-name">%s</h6>',
            esc_html($name)
        );
    }

	/**
	 * Print a Django media team logo, falling back to the theme placeholder.
	 */
	function f365_livegame_team_logo($team_id, $name, $is_mapped = false, $url = '')
    {
        // Logo straight from the local /media folder, mirroring Django:
        //   images/logotipo/100x100/{team_team.id}.gif
        // No post thumbnail / WP_Query-by-title / wp_postmeta lookups. A missing
        // logo (or unmapped team) falls back to the theme placeholder, both
        // server-side and via the onerror handler.
        $no_image = f365_livegame_no_image_url();
        $logo_url = f365_team_logo_url($team_id);

        printf(
            '<img class="team-logo" src="%1$s" alt="%2$s" title="%2$s" width="100" height="100" loading="lazy" onerror="this.onerror=null;this.src=\'%3$s\';">',
            esc_url($logo_url),
            esc_attr($name),
            esc_url($no_image)
        );
    }

	function f365_livegame_render_team_logo( $match_data, $side, $url = '' ) {
		$side = 'visitor' === $side ? 'visitor' : 'home';
		$id_key = $side . '_team_id';
		$name_key = $side . '_team_shortname';
		$fallback_key = $side . '_team_name';

		$team_id = isset( $match_data->{$id_key} ) ? (int) $match_data->{$id_key} : 0;
		$name = '';
		if ( ! empty( $match_data->is_mapped ) && ! empty( $match_data->{$name_key} ) ) {
			$name = $match_data->{$name_key};
		} elseif ( ! empty( $match_data->{$fallback_key} ) ) {
			$name = $match_data->{$fallback_key};
		}

		f365_livegame_team_logo(
			$team_id,
			$name,
			f365_livegame_bool( $match_data->is_mapped ?? false ),
			$url
		);
	}

	/**
	 * Return event display text.
	 */
	/**
	 * Portuguese TEXT label — used by the Eventos tab. KEEP THIS AS-IS.
	 */
	function f365_livegame_event_label( $type ) {
		$labels = array(
			'goal'               => 'Golo',
			'goal_penalty'       => 'Golo de penálti',
			'own_goal'           => 'Autogolo',
			'yellow_card'        => 'Cartão amarelo',
			'double_yellow_card' => 'Segundo cartão amarelo',
			'red_card'           => 'Cartão vermelho',
			'substitution_in'    => 'Substituição',
			'substitution_out'   => 'Substituição',
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : ucwords( str_replace( '_', ' ', $type ) );
	}

	/**
	 * ICON CSS class — used by NARRAÇÃO (and Eventos icon span).
	 * This is YOUR pasted mapping, renamed so it doesn't clash with the text label.
	 * Returns '' for events that have no icon (corners, fouls, etc.) so no empty
	 * icon is drawn — matching the live site.
	 */
	function f365_livegame_event_icon( $type ) {
		$icons = array(
			'goal'               => 'goal',
			'penalty_goal'       => 'goal_penalty',
			'goal_penalty'       => 'goal_penalty',
			'own_goal'           => 'own_goal',
			'yellow_card'        => 'yellow_card',
			'double_yellow_card' => 'double_yellow_card',
			'red_card'           => 'red_card',
			'substitution'       => 'substitution',
			'substitution_in'    => 'substitution_in',
			'substitution_out'   => 'substitution_out',
			'end'                => 'whistle',
			'end_match'          => 'whistle',
			'final'              => 'whistle',
			'full_time'          => 'whistle',
		);
		$type = strtolower( trim( (string) $type ) );
		return isset( $icons[ $type ] ) ? $icons[ $type ] : '';
	}

	function f365_livegame_cache_get( $cache_key, $callback, $ttl ) {
		$cached = wp_cache_get( $cache_key, 'livegame' );
		if ( false !== $cached ) {
			return $cached;
		}

		$value = call_user_func( $callback );
		wp_cache_set( $cache_key, $value, 'livegame', max( 1, (int) $ttl ) );
		return $value;
	}

	function f365_livegame_cache_ttl_for_status( $status ) {
		$status = (string) $status;
		if ( 'RF' === $status ) {
			return DAY_IN_SECONDS;
		}
		if ( in_array( $status, array( 'AD', 'SP', '-1' ), true ) ) {
			return MINUTE_IN_SECONDS;
		}
		return 20;
	}

	function f365_livegame_detail_cache_ttl( $row ) {
		if ( ! $row || ! isset( $row->status ) ) {
			return MINUTE_IN_SECONDS;
		}
		return f365_livegame_cache_ttl_for_status( $row->status );
	}

	/* =====================================================================
	 * RAW-SQL LIVE READERS — read live data straight from the Django mirror.
	 * (WP-prefixed legacy tables; Django {app}_{model} naming.)
	 * ================================================================== */

	/**
	 * Full scalar row for a live match (core + competition + teams + stadium + stats).
	 *
	 * Every join is a LEFT JOIN on purpose: for *unmapped* matches lm.match_id is
	 * NULL, so cm / htm / vtm / ss_match / cp resolve to NULL and the template
	 * falls back to lm.home_team_name / lm.visitor_team_name. Using INNER JOIN
	 * here would make unmapped live matches disappear entirely.
	 */
	function f365_sql_get_livematch_details( $lm_id ) {
	global $wpdb;

	$lm_id = (int) $lm_id;
	if ( ! $lm_id ) {
		return null;
	}

	$cache_key = 'f365_lm_details_v3_' . $lm_id;
	$cached    = wp_cache_get( $cache_key, 'livegame' );

	if ( false !== $cached ) {
		return $cached;
	}

	$sql = "SELECT
		lm.id AS livematch_id,
		lm.datetime,
		lm.status,
		lm.home_team_name,
		lm.visitor_team_name,
		lm.home_score,
		lm.visitor_score,
		lm.goal,
		lm.finished_at,
		lm.is_mapped,
		lm.external_audio_narration,
		lm.home_score_extra,
		lm.visitor_score_extra,
		lm.penalty_home_score,
		lm.penalty_visitor_score,

		cc.id AS competition_id,
		cc.federation_competition_id,
		cc.name AS competition_name,
		cc.edition,
		cc.season,

        cm.id AS match_id,
        cm.match_round,
        cm.phase,
        cm.group_home_team,
        cm.group_visitor_team,
        cm.group_phase_round,
        cm.leg,
		cm.date_time AS match_datetime,
		cm.stadium_id AS match_stadium_id,

		ht.id AS home_teamstep_id,
		htm.id AS home_team_id,
		htm.shortname AS home_team_shortname,
		htm.longname AS home_team_longname,
		htm.team_type AS home_team_type,

		vt.id AS visitor_teamstep_id,
		vtm.id AS visitor_team_id,
		vtm.shortname AS visitor_team_shortname,
		vtm.longname AS visitor_team_longname,
		vtm.team_type AS visitor_team_type,

		COALESCE(ss_match.id, ss_home.id) AS stadium_id,
		COALESCE(ss_match.longname, ss_home.longname) AS stadium_longname,
		COALESCE(ss_match.shortname, ss_home.shortname) AS stadium_name,
		COALESCE(ss_match.city, ss_home.city) AS stadium_city,
		COALESCE(ss_match.capacity, ss_home.capacity) AS stadium_capacity,

		cp.id AS phase_id,
		cp.name AS phase_name,
        cp.phase_type AS phase_type,

		lms.home_ball_possession,
		lms.home_shots,
		lms.home_shots_on_goal,
		lms.home_fouls,
		lms.home_cautions,
		lms.home_expulsions,
		lms.home_corner_kicks,
		lms.home_offsides,
		lms.home_saves,
		lms.home_goals,
		lms.home_goals_penalty,
		lms.home_own_goals,
		lms.home_substitutions,
		lms.home_yellow_cards,
		lms.home_double_yellow_cards,
		lms.home_red_cards,
		lms.home_throw_ins,
		lms.home_free_kicks,

		lms.visitor_ball_possession,
		lms.visitor_shots,
		lms.visitor_shots_on_goal,
		lms.visitor_fouls,
		lms.visitor_cautions,
		lms.visitor_expulsions,
		lms.visitor_corner_kicks,
		lms.visitor_offsides,
		lms.visitor_saves,
		lms.visitor_goals,
		lms.visitor_goals_penalty,
		lms.visitor_own_goals,
		lms.visitor_substitutions,
		lms.visitor_yellow_cards,
		lms.visitor_double_yellow_cards,
		lms.visitor_red_cards,
		lms.visitor_throw_ins,
		lms.visitor_free_kicks,

		(
			SELECT GROUP_CONCAT( CONCAT_WS('|', tv.name) SEPARATOR ';;' )
			FROM {$wpdb->prefix}live_matchontv AS motv
			LEFT JOIN {$wpdb->prefix}multimedia_tvchannel AS tv
				ON tv.id = motv.channel_id
			WHERE motv.match_id = lm.match_id
		) AS tv_channels

	FROM {$wpdb->prefix}live_livematch AS lm

	LEFT JOIN {$wpdb->prefix}competition_competition AS cc
		ON cc.id = lm.competition_id

	LEFT JOIN {$wpdb->prefix}competition_match AS cm
		ON cm.id = lm.match_id

	LEFT JOIN {$wpdb->prefix}live_livematchstats AS lms
		ON lms.id = lm.stats_id

	LEFT JOIN {$wpdb->prefix}competition_competitionphase AS cp
		ON cp.id = cm.competition_phase_id

	LEFT JOIN {$wpdb->prefix}team_teamstep AS ht
		ON ht.id = cm.home_team_id

	LEFT JOIN {$wpdb->prefix}team_team AS htm
		ON htm.id = ht.team_id

	LEFT JOIN {$wpdb->prefix}team_teamstep AS vt
		ON vt.id = cm.visitor_team_id

	LEFT JOIN {$wpdb->prefix}team_team AS vtm
		ON vtm.id = vt.team_id

	LEFT JOIN {$wpdb->prefix}stadium_stadium AS ss_match
		ON ss_match.id = cm.stadium_id

	LEFT JOIN {$wpdb->prefix}stadium_stadium AS ss_home
		ON ss_home.id = ht.stadium_id

	WHERE lm.id = %d
	LIMIT 1";

	$row = $wpdb->get_row( $wpdb->prepare( $sql, $lm_id ) );

	if ( $row ) {
		wp_cache_set( $cache_key, $row, 'livegame', f365_livegame_detail_cache_ttl( $row ) );
	}

	return $row;
}

	function f365_sql_get_livematch_events( $lm_id ) {
		global $wpdb;
		$lm_id = (int) $lm_id;
		return f365_livegame_cache_get(
			'f365_lm_events_' . $lm_id,
			function () use ( $wpdb, $lm_id ) {
				$sql = "SELECT id, event_type, player_name, minute, is_home_team
						FROM {$wpdb->prefix}live_livematchevent
						WHERE live_match_id = %d
						ORDER BY minute ASC, id ASC";
				return $wpdb->get_results( $wpdb->prepare( $sql, $lm_id ) );
			},
			20
		);
	}

	function f365_sql_get_livematch_narrations( $lm_id ) {
    	global $wpdb;
		$lm_id = (int) $lm_id;
		return f365_livegame_cache_get(
			'f365_lm_narrations_' . $lm_id,
			function () use ( $wpdb, $lm_id ) {
				$sql = "SELECT minute, minute_extra, period, event, description
						FROM {$wpdb->prefix}live_livenarration
						WHERE live_match_id = %d
						ORDER BY minute DESC, minute_extra DESC, period DESC";
				return $wpdb->get_results( $wpdb->prepare( $sql, $lm_id ), ARRAY_A );
			},
			20
		);
	}

	function f365_sql_get_livematch_players( $lm_id ) {
		global $wpdb;
		$lm_id = (int) $lm_id;
		return f365_livegame_cache_get(
			'f365_lm_players_' . $lm_id,
			function () use ( $wpdb, $lm_id ) {
				$players_sql = "SELECT id, player_name, player_number, player_order, is_field_player, is_home_team
								FROM {$wpdb->prefix}live_livematchplayer
								WHERE live_match_id = %d
								ORDER BY is_field_player DESC, player_order ASC, player_number ASC";
				$players = $wpdb->get_results( $wpdb->prepare( $players_sql, $lm_id ) );

				if ( $players ) {
					$player_ids   = array_map( 'intval', wp_list_pluck( $players, 'id' ) );
					$placeholders = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );
					$events_sql   = "SELECT live_match_player_id, event_type, minute
									 FROM {$wpdb->prefix}live_livematchplayerevent
									 WHERE live_match_player_id IN ($placeholders)
									 ORDER BY minute ASC";
					$events = $wpdb->get_results( $wpdb->prepare( $events_sql, $player_ids ) );

					$events_by_player = array();
					foreach ( $events as $e ) {
						$events_by_player[ $e->live_match_player_id ][] = $e;
					}

					foreach ( $players as &$p ) {
						$p->player_events = isset( $events_by_player[ $p->id ] ) ? $events_by_player[ $p->id ] : array();
					}
					unset( $p );
				}
				return $players;
			},
			MINUTE_IN_SECONDS
		);
	}

	function f365_sql_get_livematch_coaches( $lm_id ) {
		global $wpdb;
		$lm_id = (int) $lm_id;
		return f365_livegame_cache_get(
			'f365_lm_coaches_' . $lm_id,
			function () use ( $wpdb, $lm_id ) {
				$sql = "SELECT coach_name, is_home_team
						FROM {$wpdb->prefix}live_livematchcoach
						WHERE live_match_id = %d";
				return $wpdb->get_results( $wpdb->prepare( $sql, $lm_id ) );
			},
			MINUTE_IN_SECONDS
		);
	}

	function f365_sql_get_livematch_formations( $lm_id ) {
		global $wpdb;
		$lm_id = (int) $lm_id;
		return f365_livegame_cache_get(
			'f365_lm_formations_' . $lm_id,
			function () use ( $wpdb, $lm_id ) {
				$sql = "SELECT formation, is_home_team
						FROM {$wpdb->prefix}live_livematchformation
						WHERE live_match_id = %d";
				return $wpdb->get_results( $wpdb->prepare( $sql, $lm_id ) );
			},
			MINUTE_IN_SECONDS
		);
	}

	/* =====================================================================
	 * VIEW-MODEL + LIST HELPERS (refactor of single/archive templates).
	 *
	 * Pure shaping of the already-cached reader rows into the exact arrays /
	 * strings the templates render — plus the archive's list/dropdown queries.
	 * No behaviour change: each reproduces the previous in-template logic
	 * verbatim, so output stays byte-identical.
	 * ================================================================== */

	/**
	 * Resolve the live-match id for the single template: query var first, then
	 * the /jogos-em-direto/{id}/ path. (Was inline in single-f365_livegames.php.)
	 */
	function f365_livegame_resolve_single_id() {
		$lm_id = (int) get_query_var( 'f365_livematch_id' );
		if ( ! $lm_id && preg_match( '#/jogos-em-direto/(\d+)#', $_SERVER['REQUEST_URI'] ?? '', $mm ) ) {
			$lm_id = (int) $mm[1];
		}
		return $lm_id;
	}

	/**
	 * Running scoreboard per goal/own-goal event id, in chronological order.
	 * Sorts a COPY so the caller's $events order stays intact.
	 *
	 * @return array<int,string>  event_id => "home-visitor"
	 */
	function f365_livegame_event_scores( $events ) {
		$scores           = array();
		$events_for_score = is_array( $events ) ? $events : array();

		usort(
			$events_for_score,
			function ( $a, $b ) {
				$a_min = (int) f365_livegame_value( $a, array( 'minute' ), 0 );
				$b_min = (int) f365_livegame_value( $b, array( 'minute' ), 0 );
				if ( $a_min === $b_min ) {
					return (int) f365_livegame_value( $a, array( 'id' ), 0 ) <=> (int) f365_livegame_value( $b, array( 'id' ), 0 );
				}
				return $a_min <=> $b_min;
			}
		);

		$running_home    = 0;
		$running_visitor = 0;

		foreach ( $events_for_score as $score_event ) {
			$event_id   = (int) f365_livegame_value( $score_event, array( 'id' ), 0 );
			$event_type = f365_livegame_value( $score_event, array( 'event_type', 'type' ) );
			$is_home    = f365_livegame_bool( f365_livegame_value( $score_event, array( 'is_home_team' ), false ) );

			if ( in_array( $event_type, array( 'goal', 'goal_penalty' ), true ) ) {
				if ( $is_home ) {
					$running_home++;
				} else {
					$running_visitor++;
				}
				$scores[ $event_id ] = $running_home . '-' . $running_visitor;
			}

			if ( 'own_goal' === $event_type ) {
				if ( $is_home ) {
					$running_visitor++;
				} else {
					$running_home++;
				}
				$scores[ $event_id ] = $running_home . '-' . $running_visitor;
			}
		}

		return $scores;
	}

	/**
	 * Event row text, e.g. "Golo (1-0) para (Benfica)". Named version of the old
	 * in-template closure — keeps its own label map (intentionally different from
	 * f365_livegame_event_label()).
	 */
	function f365_livegame_event_text( $event, $home_name, $visitor_name, $event_scores ) {
		$event_id   = (int) f365_livegame_value( $event, array( 'id' ), 0 );
		$event_type = f365_livegame_value( $event, array( 'event_type', 'type' ) );
		$is_home    = f365_livegame_bool( f365_livegame_value( $event, array( 'is_home_team' ), false ) );

		$team_name = $is_home ? $home_name : $visitor_name;

		$labels = array(
			'goal'               => 'Golo',
			'goal_penalty'       => 'Golo',
			'own_goal'           => 'Autogolo',
			'yellow_card'        => 'Cartão amarelo',
			'double_yellow_card' => 'Segundo cartão amarelo',
			'red_card'           => 'Cartão vermelho',
			'substitution_in'    => 'Substituição',
			'substitution_out'   => 'Substituição',
		);

		$label = $labels[ $event_type ] ?? ucwords( str_replace( '_', ' ', $event_type ) );

		if ( isset( $event_scores[ $event_id ] ) && in_array( $event_type, array( 'goal', 'goal_penalty', 'own_goal' ), true ) ) {
			return sprintf( '%s (%s) para (%s)', $label, $event_scores[ $event_id ], $team_name );
		}

		return sprintf( '%s para (%s)', $label, $team_name );
	}

	/**
	 * Goal-scorer suffix for the scoreboard marcadores list.
	 */
	function f365_livegame_goal_suffix( $event ) {
		$etype = f365_livegame_value( $event, array( 'event_type', 'type' ) );
		if ( 'own_goal' === $etype ) {
			return ' (p.b.)';
		}
		if ( 'goal_penalty' === $etype ) {
			return ' (pen.)';
		}
		return '';
	}

	/**
	 * Split events into home/visitor goal lists (goal, goal_penalty, own_goal),
	 * each row cast to an array — exactly as the scoreboard built them.
	 *
	 * @return array{home:array,visitor:array}
	 */
	function f365_livegame_split_goals( $events ) {
		$home    = array();
		$visitor = array();

		foreach ( (array) $events as $event ) {
			$type = $event->event_type;
			if ( in_array( $type, array( 'goal', 'goal_penalty', 'own_goal' ), true ) ) {
				if ( f365_livegame_bool( $event->is_home_team ) ) {
					$home[] = (array) $event;
				} else {
					$visitor[] = (array) $event;
				}
			}
		}

		return array( 'home' => $home, 'visitor' => $visitor );
	}

	/**
	 * Split the players list into the four lineup buckets.
	 *
	 * @return array{home_players:array,visitor_players:array,home_subs:array,visitor_subs:array}
	 */
	function f365_livegame_split_players( $players ) {
		$home_players    = array();
		$visitor_players = array();
		$home_subs       = array();
		$visitor_subs    = array();

		foreach ( (array) $players as $player ) {
			$is_home  = f365_livegame_bool( $player->is_home_team );
			$is_field = f365_livegame_bool( $player->is_field_player );
			if ( $is_home && $is_field ) {
				$home_players[] = (array) $player;
			} elseif ( $is_home ) {
				$home_subs[] = (array) $player;
			} elseif ( $is_field ) {
				$visitor_players[] = (array) $player;
			} else {
				$visitor_subs[] = (array) $player;
			}
		}

		return array(
			'home_players'    => $home_players,
			'visitor_players' => $visitor_players,
			'home_subs'       => $home_subs,
			'visitor_subs'    => $visitor_subs,
		);
	}

	/**
	 * Home/visitor coach names ('' when absent).
	 *
	 * @return array{home:string,visitor:string}
	 */
	function f365_livegame_split_coaches( $coaches ) {
		$home    = '';
		$visitor = '';
		foreach ( (array) $coaches as $coach ) {
			if ( f365_livegame_bool( $coach->is_home_team ) ) {
				$home = $coach->coach_name;
			} else {
				$visitor = $coach->coach_name;
			}
		}
		return array( 'home' => $home, 'visitor' => $visitor );
	}

	/**
	 * Home/away formation strings ('' when absent).
	 *
	 * @return array{home:string,away:string}
	 */
	function f365_livegame_split_formations( $formations ) {
		$home = '';
		$away = '';
		foreach ( (array) $formations as $formation ) {
			if ( f365_livegame_bool( $formation->is_home_team ) ) {
				$home = $formation->formation;
			} else {
				$away = $formation->formation;
			}
		}
		return array( 'home' => $home, 'away' => $away );
	}

	/**
	 * Flat statistics array consumed by the Estatísticas table.
	 */
	function f365_livegame_build_stats( $match_data ) {
		return array(
			'home_ball_possession'        => $match_data->home_ball_possession,
			'visitor_ball_possession'     => $match_data->visitor_ball_possession,
			'home_shots'                  => $match_data->home_shots,
			'visitor_shots'               => $match_data->visitor_shots,
			'home_shots_on_goal'          => $match_data->home_shots_on_goal,
			'visitor_shots_on_goal'       => $match_data->visitor_shots_on_goal,
			'home_fouls'                  => $match_data->home_fouls,
			'visitor_fouls'               => $match_data->visitor_fouls,
			'home_cautions'               => $match_data->home_cautions,
			'visitor_cautions'            => $match_data->visitor_cautions,
			'home_expulsions'             => $match_data->home_expulsions,
			'visitor_expulsions'          => $match_data->visitor_expulsions,
			'home_corner_kicks'           => $match_data->home_corner_kicks,
			'visitor_corner_kicks'        => $match_data->visitor_corner_kicks,
			'home_offsides'               => $match_data->home_offsides,
			'visitor_offsides'            => $match_data->visitor_offsides,
			'home_saves'                  => $match_data->home_saves,
			'visitor_saves'               => $match_data->visitor_saves,
			'home_goals'                  => $match_data->home_goals,
			'visitor_goals'               => $match_data->visitor_goals,
			'home_goals_penalty'          => $match_data->home_goals_penalty,
			'visitor_goals_penalty'       => $match_data->visitor_goals_penalty,
			'home_own_goals'              => $match_data->home_own_goals,
			'visitor_own_goals'           => $match_data->visitor_own_goals,
			'home_substitutions'          => $match_data->home_substitutions,
			'visitor_substitutions'       => $match_data->visitor_substitutions,
			'home_yellow_cards'           => $match_data->home_yellow_cards,
			'visitor_yellow_cards'        => $match_data->visitor_yellow_cards,
			'home_double_yellow_cards'    => $match_data->home_double_yellow_cards,
			'visitor_double_yellow_cards' => $match_data->visitor_double_yellow_cards,
			'home_red_cards'              => $match_data->home_red_cards,
			'visitor_red_cards'           => $match_data->visitor_red_cards,
			'home_throw_ins'              => $match_data->home_throw_ins,
			'visitor_throw_ins'           => $match_data->visitor_throw_ins,
			'home_free_kicks'             => $match_data->home_free_kicks,
			'visitor_free_kicks'          => $match_data->visitor_free_kicks,
		);
	}

	/**
	 * Team + competition URLs and the display / round labels for the scoreboard.
	 * Mirrors the Django get_absolute_url() lines exactly.
	 *
	 * @return array
	 */
	function f365_livegame_competition_urls( $match_data ) {
		$home_team_id      = (int) ( $match_data->home_team_id ?? 0 );
		$visitor_team_id   = (int) ( $match_data->visitor_team_id ?? 0 );
		$home_team_type    = (string) ( $match_data->home_team_type ?? '' );
		$visitor_team_type = (string) ( $match_data->visitor_team_type ?? '' );

		$home_team_url = f365_livegame_bool( $match_data->is_mapped )
			? f365_livegame_team_url( $home_team_id, $home_team_type, $match_data->competition_id, $match_data->edition )
			: '';
		$visitor_team_url = f365_livegame_bool( $match_data->is_mapped )
			? f365_livegame_team_url( $visitor_team_id, $visitor_team_type, $match_data->competition_id, $match_data->edition )
			: '';

		$competition_base_url    = '';
		$competition_url         = '';
		$competition_display     = trim( $match_data->competition_name . ' ' . (string) ( $match_data->season ?? '' ) );
		$competition_round_label = ! empty( $match_data->phase_name )
			? $match_data->phase_name
			: ( '' !== (string) ( $match_data->match_round ?? '' ) ? $match_data->match_round . 'ª Jornada' : '' );

		if ( ! empty( $match_data->federation_competition_id ) ) {
			$competition_base     = home_url( '/competicao/' . (int) $match_data->federation_competition_id . '/' );
			$competition_base_url = add_query_arg( array( 'edition' => $match_data->edition ), $competition_base );

			$round_args = array( 'edition' => $match_data->edition );
			if ( ! empty( $match_data->phase_name ) ) {
				$round_args['competition_phase'] = $match_data->phase_name;
			} elseif ( '' !== (string) ( $match_data->match_round ?? '' ) ) {
				$round_args['competition_round'] = $match_data->match_round;
			}
			$competition_url = add_query_arg( $round_args, $competition_base );
		}

		return array(
			'home_team_url'           => $home_team_url,
			'visitor_team_url'        => $visitor_team_url,
			'competition_base_url'    => $competition_base_url,
			'competition_url'         => $competition_url,
			'competition_display'     => $competition_display,
			'competition_round_label' => $competition_round_label,
		);
	}

	/**
	 * Related videos for a match (cached). Moved verbatim from the single
	 * template; reuses the same transient helper + key + TTL.
	 */
	function f365_sql_get_livematch_videos( $match_id ) {
		global $wpdb;
		$match_id = (int) $match_id;

		return f365_livegame_cache_get(
			'f365_lm_videos_' . $match_id,
			function () use ( $wpdb, $match_id ) {
				return $wpdb->get_results(
					$wpdb->prepare(
						"SELECT *
						FROM {$wpdb->prefix}multimedia_video
						WHERE match_id = %d
						ORDER BY datetime DESC
						LIMIT 12",
						$match_id
					)
				);
			},
			MINUTE_IN_SECONDS
		);
	}

	/* ---- Archive (/jogos-em-direto/) list helpers ---------------------- */

	/**
	 * Distinct competitions with matches in today's ±2h window (the archive
	 * "Competição" dropdown). Cached once per day — same key/TTL as before.
	 *
	 * @return array<int,object>  rows of { id, name }
	 */
	function f365_livegame_competitions_today() {
		global $wpdb;

		$today = current_time( 'Y-m-d' );
		$lower = gmdate( 'Y-m-d H:i:s', strtotime( $today . ' 00:00:00' ) - 2 * HOUR_IN_SECONDS );
		$upper = gmdate( 'Y-m-d H:i:s', strtotime( $today . ' 23:59:59' ) + 2 * HOUR_IN_SECONDS );

		$competitions_cb = function () use ( $wpdb, $lower, $upper ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT cc.id, cc.name
					 FROM {$wpdb->prefix}live_livematch AS lm
					 INNER JOIN {$wpdb->prefix}competition_competition AS cc ON cc.id = lm.competition_id
					 WHERE lm.datetime >= %s AND lm.datetime <= %s
					 ORDER BY cc.name ASC
					 LIMIT 200",
					$lower,
					$upper
				)
			);
		};

		return function_exists( 'f365_livegame_cached' )
			? f365_livegame_cached( 'f365_lg_competitions_' . $today, F365_LIVEGAME_CACHE_TTL, $competitions_cb )
			: $competitions_cb();
	}

	/**
	 * Today's live_livematch rows (±2h window, same scope as
	 * f365_livegame_competitions_today's dropdown) for the /jogos-em-direto/
	 * archive, grouped by competition by the caller. This function was called
	 * from archive-f365_livegames.php but never defined — the archive's
	 * Todos/Direto/Próximos/Terminados tabs silently rendered "Sem jogos
	 * disponíveis" regardless of how much live data existed, because the only
	 * caller guards with function_exists() and returns [] when it's missing.
	 *
	 * $status_filter (the archive's ?status= query arg, NOT the DB status
	 * column — this UI vocabulary is distinct):
	 *   ''  Todos      — no status restriction, still window-scoped
	 *   '1' Direto     — DB status is a live indicator (not -1/RF/AD/SP/empty)
	 *   '2' Próximos   — DB status = '-1' (scheduled) and kickoff still ahead
	 *   '3' Terminados — DB status = 'RF' (finished)
	 *   '4' Meus Jogos — no favourites/watchlist feature exists on this site
	 *                    (the archive's own tab label hardcodes "(0)") — []
	 *
	 * Row shape matches what f365_sql_get_livematch_details() returns for a
	 * single match (livematch_id, team ids/names, scores, competition name),
	 * since archive-f365_livegames.php and single-f365_livegames.php share
	 * f365_livegame_team_name()/f365_livegame_status() against the same
	 * property names.
	 *
	 * @return array<int,object>
	 */
	function f365_sql_get_current_livematches( $status_filter = '', $competition_filter = 0 ) {
		global $wpdb;

		if ( '4' === (string) $status_filter ) {
			return array();
		}

		$today = current_time( 'Y-m-d' );
		$lower = gmdate( 'Y-m-d H:i:s', strtotime( $today . ' 00:00:00' ) - 2 * HOUR_IN_SECONDS );
		$upper = gmdate( 'Y-m-d H:i:s', strtotime( $today . ' 23:59:59' ) + 2 * HOUR_IN_SECONDS );

		$where  = 'lm.datetime >= %s AND lm.datetime <= %s';
		$params = array( $lower, $upper );

		if ( '1' === (string) $status_filter ) {
			$where .= " AND lm.status NOT IN ( '-1', 'RF', 'AD', 'SP' ) AND lm.status <> ''";
		} elseif ( '2' === (string) $status_filter ) {
			$where .= ' AND lm.status = %s AND lm.datetime > %s';
			$params[] = '-1';
			$params[] = current_time( 'mysql', true );
		} elseif ( '3' === (string) $status_filter ) {
			$where   .= ' AND lm.status = %s';
			$params[] = 'RF';
		}

		if ( $competition_filter ) {
			$where   .= ' AND lm.competition_id = %d';
			$params[] = (int) $competition_filter;
		}

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT lm.id AS livematch_id, lm.match_id, lm.competition_id, lm.datetime,
			        lm.status, lm.goal, lm.is_mapped,
			        lm.home_score, lm.visitor_score, lm.home_score_extra, lm.visitor_score_extra,
			        lm.penalty_home_score, lm.penalty_visitor_score,
			        lm.home_team_name, lm.visitor_team_name,
			        htm.id AS home_team_id, htm.shortname AS home_team_shortname,
			        vtm.id AS visitor_team_id, vtm.shortname AS visitor_team_shortname,
			        cc.name AS competition_name
			   FROM {$wpdb->prefix}live_livematch AS lm
			   LEFT JOIN {$wpdb->prefix}competition_match AS cm ON cm.id = lm.match_id
			   LEFT JOIN {$wpdb->prefix}team_teamstep AS ht ON ht.id = cm.home_team_id
			   LEFT JOIN {$wpdb->prefix}team_team AS htm ON htm.id = ht.team_id
			   LEFT JOIN {$wpdb->prefix}team_teamstep AS vt ON vt.id = cm.visitor_team_id
			   LEFT JOIN {$wpdb->prefix}team_team AS vtm ON vtm.id = vt.team_id
			   LEFT JOIN {$wpdb->prefix}competition_competition AS cc ON cc.id = lm.competition_id
			  WHERE {$where}
			  ORDER BY lm.datetime ASC
			  LIMIT 300",
			$params
		) );
	}

	/**
	 * Score/status text for one archive row — the flat-list equivalent of the
	 * inline scoreboard logic in single-f365_livegames.php (penalties > extra
	 * time > regular score, "vs" before kickoff, ADI/SUS/ANU for irregular
	 * situations). Was called from archive-f365_livegames.php but, like
	 * f365_sql_get_current_livematches(), never defined — dormant only
	 * because the archive's match loop never ran with an empty $grouped.
	 */
	function f365_livegame_result_text( $lm ) {
		$status = (string) ( $lm->status ?? '-1' );
		if ( 'AD' === $status ) {
			return 'ADI';
		}
		if ( 'SP' === $status ) {
			return 'SUS';
		}
		$home_pen = (string) ( $lm->penalty_home_score ?? '' );
		$away_pen = (string) ( $lm->penalty_visitor_score ?? '' );
		if ( '' !== $home_pen && '' !== $away_pen ) {
			return $home_pen . ' - ' . $away_pen . ' g.p';
		}
		$home_ext = (string) ( $lm->home_score_extra ?? '' );
		$away_ext = (string) ( $lm->visitor_score_extra ?? '' );
		if ( '' !== $home_ext && '' !== $away_ext ) {
			return $home_ext . ' - ' . $away_ext . ' a.p';
		}
		$home_score = $lm->home_score ?? null;
		$away_score = $lm->visitor_score ?? null;
		if ( null !== $home_score && '' !== (string) $home_score && null !== $away_score && '' !== (string) $away_score && '-' !== $home_score ) {
			return $home_score . ' - ' . $away_score;
		}
		return 'vs';
	}

	/**
	 * Today's live matches (flat list) with a short shared cache so a burst of
	 * visitors + the 30s auto-refresh don't each re-run the heavy join. Returns
	 * the SAME rows the plugin reader returns; grouping is done separately.
	 *
	 * @return array<int,object>
	 */
	function f365_livegame_current_matches( $status_filter = '', $competition_filter = 0 ) {
		if ( ! function_exists( 'f365_sql_get_current_livematches' ) ) {
			return array();
		}

		$matches_cb = function () use ( $status_filter, $competition_filter ) {
			return f365_sql_get_current_livematches( $status_filter, $competition_filter );
		};

		$key = 'f365_lg_matches_' . sanitize_key( (string) $status_filter ) . '_' . (int) $competition_filter;

		return function_exists( 'f365_livegame_cached' )
			? f365_livegame_cached( $key, 15, $matches_cb )
			: $matches_cb();
	}

	/**
	 * Group a flat live-matches list by competition (id => name + matches),
	 * preserving order. Same shape the archive built inline.
	 *
	 * @return array<int,array{id:int,name:string,matches:array}>
	 */
	function f365_livegame_group_by_competition( $live_matches ) {
		$grouped = array();
		foreach ( (array) $live_matches as $lm ) {
			$cid = (int) $lm->competition_id;
			if ( ! isset( $grouped[ $cid ] ) ) {
				$grouped[ $cid ] = array(
					'id'      => $cid,
					'name'    => $lm->competition_name,
					'matches' => array(),
				);
			}
			$grouped[ $cid ]['matches'][] = $lm;
		}
		return $grouped;
	}

	/**
	 * True when any grouped match is currently in play (drives the 30s refresh).
	 */
	function f365_livegame_has_live( $grouped ) {
		foreach ( (array) $grouped as $competition ) {
			foreach ( $competition['matches'] as $lm ) {
				if ( ! in_array( (string) ( $lm->status ?: '-1' ), array( 'RF', 'AD', 'SP', '-1' ), true ) ) {
					return true;
				}
			}
		}
		return false;
	}

}

/* =====================================================================
 * MEDIA / STATIC SOURCE BASES — generic Django asset URL helpers.
 *
 * Mirrors Django get_image_in_path():
 *     {BASE}/{path}{filename}.{ext}            (when the file exists)
 *  -> {BASE}/{path}no-image.{ext}             (otherwise)
 *
 * Usage:
 *     $base = f365_src_base_url( F365_MEDIA_DIR ) . '/images/person/';
 *     $url  = f365_src_image_url( F365_MEDIA_DIR, 'images/person/', $person_id, 'jpg' );
 * ================================================================== */
if ( ! defined( 'F365_MEDIA_DIR' ) ) {
	define( 'F365_MEDIA_DIR', 'media' );
}
if ( ! defined( 'F365_STATIC_DIR' ) ) {
	define( 'F365_STATIC_DIR', 'static' );
}

if ( ! function_exists( 'f365_src_base_url' ) ) {

	/**
	 * Base URL (NO trailing slash) for a Django asset source dir.
	 * Override per environment:
	 *   add_filter( 'f365_src_base_url', fn( $base, $dir ) => '…', 10, 2 );
	 *
	 * @param string $dir  F365_MEDIA_DIR | F365_STATIC_DIR.
	 * @return string  e.g. https://media.futebol365.pt
	 */
if ( ! function_exists( 'f365_person_image_url' ) ) {
	function f365_person_image_url( int $person_id ): string {
		if ( $person_id <= 0 ) {
			return '';
		}
		$dir  = defined( 'F365_MEDIA_DIR' ) ? F365_MEDIA_DIR : 'media';
		$base = function_exists( 'f365_src_base_url' )
			? f365_src_base_url( $dir )
			: rtrim( home_url( '/' . ltrim( $dir, '/' ) ), '/' );
		return $base . '/images/person/' . $person_id . '.jpg';
	}
}

	/**
	 * Local filesystem root (NO trailing slash) for a source dir, used only for
	 * the file-exists check. Return '' to skip the check (URL emitted as-is, the
	 * <img onerror> handles the fallback).
	 */
	function f365_src_root_path( $dir = F365_MEDIA_DIR ) {
		$roots = array(
			F365_MEDIA_DIR  => dirname( __DIR__, 3 ) . '/media',
			F365_STATIC_DIR => '',
		);
		$root = isset( $roots[ $dir ] ) ? $roots[ $dir ] : '';

		return untrailingslashit( (string) apply_filters( 'f365_src_root_path', $root, $dir ) );
	}

	/**
	 * Build an asset URL exactly like Django get_image_in_path().
	 *
	 * @param string $dir       F365_MEDIA_DIR | F365_STATIC_DIR.
	 * @param string $path      relative dir, e.g. 'images/person/' (no leading slash).
	 * @param string $filename  e.g. the person / team id.
	 * @param string $ext       e.g. 'jpg' | 'gif'.
	 * @return string
	 */
	function f365_src_image_url( $dir, $path, $filename, $ext ) {
		$path     = trailingslashit( ltrim( (string) $path, '/' ) );
		$filename = sanitize_file_name( (string) $filename );
		$ext      = ltrim( sanitize_file_name( (string) $ext ), '.' );

		$relative = $path . $filename . '.' . $ext;

		$root = f365_src_root_path( $dir );
		if ( $root && is_dir( $root ) && ! file_exists( $root . '/' . $relative ) ) {
			$relative = $path . 'no-image.' . $ext;
		}

		return f365_src_base_url( $dir ) . '/' . $relative;
	}

	/**
	 * Convenience: a person (player / coach / president) photo by Django person id.
	 *   images/person/{person_id}.jpg   ->   images/person/no-image.jpg
	 */
	function f365_person_image_url( $person_id ) {
		$person_id = (int) $person_id;
		$filename  = $person_id ? $person_id : 'no-image';

		return f365_src_image_url( F365_MEDIA_DIR, 'images/person/', $filename, 'jpg' );
	}
}