<?php
/**
 * Futebol365 — Player Rewrite Rules & Permalink Handling
 *
 * Handles custom routing for player pages and their sub-sections:
 * - /jogador/{id}/
 * - /jogador/{id}/jogos/
 * - /jogador/{id}/palmares/
 * - /jogador/{id}/premios/
 * - /jogador/{id}/transferencias/
 * - /jogador/{id}/noticias/
 * - /jogador/{id}/videos/
 * - /jogador/{id}/dados-pessoais/
 *
 * @package Futebol365
 * @since   2.2.0
 */

defined('ABSPATH') || exit;

/**
 * -------------------------------------------------------
 * `1. REWRITE RULES`
 * -------------------------------------------------------
 */
function f365_player_rewrite_rules(): void
{

    $sections = [
        'jogos',
        'palmares',
        'premios',
        'transferencias',
        'noticias',
        'videos',
        'dados-pessoais',
    ];

    foreach ($sections as $section) {
        add_rewrite_rule(
            '^jogador/([0-9]+)/' . $section . '/?$',
            'index.php?post_type=f365_player&f365_player_id=$matches[1]&f365_section=' . $section,
            'top'
        );
    }

    add_rewrite_rule(
        '^jogador/([0-9]+)/?$',
        'index.php?post_type=f365_player&f365_player_id=$matches[1]',
        'top'
    );

    add_rewrite_rule(
        '^jogador/pais/([^/]+)/?$',
        'index.php?post_type=f365_player&f365_country=$matches[1]',
        'top'
    );

    add_rewrite_rule(
        '^jogador/posicao/([^/]+)/?$',
        'index.php?post_type=f365_player&f365_position=$matches[1]',
        'top'
    );


}
add_action('init', 'f365_player_rewrite_rules', 20);


/**
 * -------------------------------------------------------
 * 2. QUERY VARS
 * -------------------------------------------------------
 */
add_filter('query_vars', 'f365_player_query_vars');

function f365_player_query_vars(array $vars): array
{
    $new = [
        'f365_player_id',
        'f365_section',
        'f365_country',
        'f365_position',
    ];

    foreach ($new as $var) {
        if (!in_array($var, $vars, true)) {
            $vars[] = $var;
        }
    }

    return $vars;
}


/**
 * -------------------------------------------------------
 * 3. PRE GET POSTS — ID resolution
 * -------------------------------------------------------
 */
function f365_player_pre_get_posts(WP_Query $query): void
{
    if (is_admin() || !$query->is_main_query()) {
        return;
    }

    $player_id = (int) $query->get('f365_player_id');
    if (!$player_id) {
        return;
    }
    $wp_post_id = f365_player_resolve_id($player_id) ?: $player_id;
    $query->set('post_type', 'f365_player');
    $query->set('p', $wp_post_id);
    $query->set('name', '');

    // Correct query flags for custom rewrite matching so that Yoast SEO and WP recognize it as singular
    $query->is_single            = true;
    $query->is_singular          = true;
    $query->is_archive           = false;
    $query->is_post_type_archive = false;
}
add_action('pre_get_posts', 'f365_player_pre_get_posts');


/**
 * -------------------------------------------------------
 * 4. RESOLVE DJANGO ID → WP ID
 * -------------------------------------------------------
 */
function f365_player_resolve_id(int $player_id): ?int
{
    static $cache = [];

    if (isset($cache[$player_id])) {
        return $cache[$player_id];
    }

    global $wpdb;

    $wp_id = $wpdb->get_var(
        $wpdb->prepare(
            "
            SELECT ID
            FROM {$wpdb->posts}
            WHERE legacy_id = %d
            AND post_type = 'f365_player'
            LIMIT 1
            ",
            $player_id
        )
    );

    // Fallback to django_player_id in postmeta if not found in columns
    if (!$wp_id) {
        $wp_id = $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT pm.post_id
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key = 'django_player_id'
                AND pm.meta_value = %s
                AND p.post_type = 'f365_player'
                LIMIT 1
                ",
                $player_id
            )
        );
    }

    $cache[$player_id] = $wp_id ? (int) $wp_id : null;

    return $cache[$player_id];
}


/**
 * -------------------------------------------------------
 * 5. PERMALINKS
 * -------------------------------------------------------
 */
add_filter('post_type_link', 'f365_player_permalink', 10, 2);

function f365_player_permalink(string $post_link, WP_Post $post): string
{

    if ('f365_player' !== $post->post_type) {
        return $post_link;
    }

    $legacy_id = f365_player_legacy_id($post->ID);
    $final_id = $legacy_id ?: $post->ID;

    return user_trailingslashit(
        home_url("jogador/{$final_id}")
    );
}

/**
 * Player URL Helper
 */
function f365_player_url(int $post_id, string $section = ''): string
{
    $legacy_id = f365_player_legacy_id($post_id);
    $final_id = $legacy_id ?: $post_id;

    $path = "jogador/{$final_id}";

    if ($section) {
        $path .= '/' . trim($section, '/');
    }

    return user_trailingslashit(
        home_url($path)
    );
}


/**
 * -------------------------------------------------------
 * 6. TEMPLATE LOADER
 * -------------------------------------------------------
 */
add_filter('template_include', 'f365_player_template_include');

function f365_player_template_include(string $template): string
{

    $player_id = get_query_var('f365_player_id');
    $section = get_query_var('f365_section');

    if (!$player_id) {
        return $template;
    }

    switch ($section) {

        case 'jogos':
            $tpl = f365_player_locate_template('f365-player-jogos');
            break;

        case 'palmares':
            $tpl = f365_player_locate_template('f365-player-palmares');
            break;

        case 'premios':
            $tpl = f365_player_locate_template('f365-player-premios');
            break;

        case 'transferencias':
            $tpl = f365_player_locate_template('f365-player-transferencias');
            break;

        case 'noticias':
            // case 'News':
            $tpl = f365_player_locate_template('f365-player-noticias');
            break;

        case 'videos':
            $tpl = f365_player_locate_template('f365-player-videos');
            break;

        case 'dados-pessoais':
            $tpl = f365_player_locate_template('f365-player-dados-pessoais');
            break;

        default:
            $tpl = f365_player_locate_template('single-f365_player');
            break;
    }

    return $tpl ?: $template;
}


/**
 * -------------------------------------------------------
 * 7. TEMPLATE LOCATOR
 * -------------------------------------------------------
 */
function f365_player_locate_template(string $name): ?string
{

    /**
     * Theme override
     */
    $theme_file = locate_template(
        ["template-parts/player/{$name}.php", "{$name}.php"],
        false,
        false
    );

    if ($theme_file) {
        return $theme_file;
    }

    /**
     * Plugin template
     */
    if (defined('F365_PLUGIN_DIR')) {

        $plugin_file = trailingslashit(F365_PLUGIN_DIR) . "templates/player-template/{$name}.php";

        if (file_exists($plugin_file)) {
            return $plugin_file;
        }

        $fallback_file = trailingslashit(F365_PLUGIN_DIR) . "templates/{$name}.php";

        if (file_exists($fallback_file)) {
            return $fallback_file;
        }
    }

    return null;
}


/**
 * -------------------------------------------------------
 * 8. GET CURRENT PLAYER POST
 * -------------------------------------------------------
 */
function f365_get_current_player_post(): ?WP_Post
{

    global $post;

    if ($post instanceof WP_Post && $post->post_type === 'f365_player') {
        return $post;
    }

    $player_id = (int) get_query_var('f365_player_id');

    if (!$player_id) {
        return null;
    }

    $wp_post_id = f365_player_resolve_id($player_id);

    if (!$wp_post_id) {
        $wp_post_id = $player_id;
    }

    return get_post($wp_post_id);
}


 
/* ===========================================================================
 * Section label maps (source parity). Centralized for title + description.
 * Keys are the f365_section query var values.
 * =========================================================================== */
function f365_player_section_title_labels(): array {
	return [
		'jogos'           => 'Jogos',
		'palmares'        => 'Palmarés',
		'premios'         => 'Prémios',
		'transferencias'  => 'Transferências',
		'noticias'        => 'Notícias',
		'videos'          => 'Vídeos',
		'dados-pessoais'  => 'Dados Pessoais',
	];
}
function f365_player_section_desc_labels(): array {
	return [
		'jogos'           => 'jogos, golos, cartões e estatísticas detalhadas',
		'palmares'        => 'palmarés, títulos, troféus e conquistas da carreira',
		'premios'         => 'prémios individuais, distinções e classificações de rankings',
		'transferencias'  => 'histórico de transferências, mercado e clubes representados',
		'noticias'        => 'notícias de última hora, reportagens e atualizações',
		'videos'          => 'vídeos de lances, golos, interviews e melhores momentos',
		'dados-pessoais'  => 'dados pessoais, idade, altura, peso e pé preferido',
	];
}
 
 
/* ===========================================================================
 * Resolve the current f365_player post id (singular only), else 0.
 * =========================================================================== */
function f365_player_current_post_id(): int {
	if ( ! is_singular( 'f365_player' ) ) {
		return 0;
	}
	$id = get_queried_object_id();
	return $id > 0 ? (int) $id : 0;
}

function f365_player_legacy_id(int $post_id): int
{
    static $cache = [];
    if (array_key_exists($post_id, $cache)) {
        return $cache[$post_id];
    }
    if ($post_id <= 0) {
        return $cache[$post_id] = 0;
    }

    $post = get_post($post_id);
    if ($post && isset($post->legacy_id) && (int) $post->legacy_id > 0) {
        return $cache[$post_id] = (int) $post->legacy_id;
    }

    global $wpdb;
    $legacy_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT legacy_id FROM {$wpdb->posts} WHERE ID = %d AND legacy_table = %s LIMIT 1",
        $post_id, 'person_player'
    ));

    if ($legacy_id <= 0) {
        $meta = get_post_meta($post_id, 'django_player_id', true);
        $legacy_id = is_numeric($meta) ? (int) $meta : 0;
    }

    return $cache[$post_id] = $legacy_id;
}
 
 
/* ===========================================================================
 * Core SEO data for a player — built from custom tables, cached per request +
 * object cache. Returns [] when not resolvable (callers then leave Yoast as-is).
 *   [ 'shortname', 'longname', 'seo_description' ]
 * =========================================================================== */
function f365_player_seo_data( int $post_id ): array {
 
	static $memo = [];
	if ( array_key_exists( $post_id, $memo ) ) {
		return $memo[ $post_id ];
	}
	if ( $post_id <= 0 ) {
		return $memo[ $post_id ] = [];
	}
 
	$cache_key = 'f365_player_seo_' . $post_id;
	$cached    = wp_cache_get( $cache_key, 'f365' );
	if ( is_array( $cached ) ) {
		return $memo[ $post_id ] = $cached;
	}
 
	$legacy_id = function_exists( 'f365_player_legacy_id' ) ? f365_player_legacy_id( $post_id ) : 0;
	if ( $legacy_id <= 0 ) {
		return $memo[ $post_id ] = []; 
	}
 
	global $wpdb;
	$T_PLAYER = $wpdb->prefix . 'person_player';
	$T_PERS   = $wpdb->prefix . 'person_person';
	$T_CTRY   = $wpdb->prefix . 'country_country';
	if ( ! f365_src_table_exists( $T_PLAYER ) || ! f365_src_table_exists( $T_PERS ) ) {
		return $memo[ $post_id ] = [];
	}
 
	// Single query: player + person + nationality name.
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT  pl.position, pl.weight, pl.height, pl.right_foot, pl.left_foot,
		         p.shortname, p.longname, p.birth_date, p.death_date, p.birth_place,
		         nat.shortname AS nationality_name
		   FROM  {$T_PLAYER} pl
		   JOIN  {$T_PERS} p   ON p.id = pl.person_id
		   LEFT JOIN {$T_CTRY} nat ON nat.id = p.nationality_id
		  WHERE  pl.id = %d
		  LIMIT  1",
		$legacy_id
	), ARRAY_A );
 
	if ( $wpdb->last_error !== '' || ! is_array( $row ) ) {
		if ( function_exists( 'f365_president_log' ) && $wpdb->last_error !== '' ) {
			f365_president_log( "player seo: SQL error legacy_id={$legacy_id}: {$wpdb->last_error}" );
		}
		return $memo[ $post_id ] = [];
	}
	
	$shortname = isset( $row['shortname'] ) ? (string) $row['shortname'] : '';
	$longname  = isset( $row['longname'] ) ? (string) $row['longname'] : '';
 
	$data = [
		'shortname'       => $shortname,
		'longname'        => $longname,
		'seo_description' => f365_player_build_seo_description( $row, $legacy_id ),
	];
 
	wp_cache_set( $cache_key, $data, 'f365', 12 * HOUR_IN_SECONDS );
	return $memo[ $post_id ] = $data;
}
 
 
/* ===========================================================================
 * Build the rich PT description — faithful port of Player.get_seo_description().
 * Tense varies by deceased / ex-player / active. Active club via squad chain.
 * =========================================================================== */
function f365_player_build_seo_description( array $row, int $legacy_id ): string {
 
	$shortname   = isset( $row['shortname'] ) ? (string) $row['shortname'] : '';
	$birth_date  = isset( $row['birth_date'] ) ? (string) $row['birth_date'] : '';
	$death_date  = isset( $row['death_date'] ) ? (string) $row['death_date'] : '';
	$birth_place = isset( $row['birth_place'] ) ? (string) $row['birth_place'] : '';
	$nationality = isset( $row['nationality_name'] ) && $row['nationality_name'] !== null ? (string) $row['nationality_name'] : '';
	$position    = isset( $row['position'] ) && $row['position'] !== null ? (string) $row['position'] : '';
	$weight      = isset( $row['weight'] ) && $row['weight'] !== null ? (int) $row['weight'] : 0;
	$height      = isset( $row['height'] ) && $row['height'] !== null ? (int) $row['height'] : 0;
	$right_foot  = in_array( strtolower( trim( (string) ( $row['right_foot'] ?? '' ) ) ), [ 't', '1', 'true' ], true );
	$left_foot   = in_array( strtolower( trim( (string) ( $row['left_foot']  ?? '' ) ) ), [ 't', '1', 'true' ], true );
 
	if ( $birth_date === '0000-00-00' ) { $birth_date = ''; }
	if ( $death_date === '0000-00-00' ) { $death_date = ''; }
 
	$is_deceased = ( $death_date !== '' );
 
	// Active club (and country) via the squad→teamstep→team chain.
	$team = f365_player_active_club( $legacy_id ); // ['name','country'] | null
	$is_ex_player = ( ! $is_deceased && $team === null );
 
	$parts = [];
 
	// ── Base: status + age + birth ────────────────────────────────────────────
	if ( $shortname !== '' ) {
		if ( $is_deceased ) {
			$base = sprintf( '%s foi um jogador de futebol', $shortname );
		} elseif ( $is_ex_player ) {
			$base = sprintf( '%s é um ex-jogador de futebol', $shortname );
		} else {
			$base = sprintf( '%s é um jogador de futebol', $shortname );
			$age  = f365_player_age( $birth_date, $death_date );
			
			if ( $age !== null ) {
				$base .= sprintf( ' de %d anos', $age );
			}
		}
	} else {
		$base = '';
	}
 
	if ( $birth_date !== '' ) {
		$ts = strtotime( $birth_date );
		if ( $ts ) {
			$base .= sprintf( ' nascido em %s', gmdate( 'd-m-Y', $ts ) );
		}
	}
	if ( $birth_place !== '' ) {
		$birth_info = sprintf( ' em %s', $birth_place );
		if ( $nationality !== '' ) {
			$birth_info .= sprintf( ', %s', $nationality );
		}
		$base .= $birth_info;
	}
	if ( $base !== '' ) {
		$parts[] = $base;
	}
 
	// ── Position + club ────────────────────────────────────────────────────────
	if ( $position !== '' ) {
		$pos_display = f365_player_position_display( $position );
		$position_text = ( $is_deceased || $is_ex_player )
			? sprintf( 'Jogava como %s', $pos_display )
			: sprintf( 'Joga como %s', $pos_display );
		if ( $team !== null && $team['name'] !== '' ) {
			$position_text .= sprintf( ' em %s', $team['name'] );
			if ( $team['country'] !== '' ) {
				$position_text .= sprintf( ', %s', $team['country'] );
			}
		}
		$parts[] = $position_text;
	}
 
	// ── Weight + height ──────────────────────────────────────────────────────
	if ( $is_deceased ) {
		if ( $weight && $height ) {
			$parts[] = sprintf( 'O seu peso era %d kg e a sua altura era %d cm', $weight, $height );
		} elseif ( $weight ) {
			$parts[] = sprintf( 'O seu peso era %d kg', $weight );
		} elseif ( $height ) {
			$parts[] = sprintf( 'A sua altura era %d cm', $height );
		}
	} else {
		if ( $weight && $height ) {
			$parts[] = sprintf( 'O seu peso é %d kg e a sua altura é %d cm', $weight, $height );
		} elseif ( $weight ) {
			$parts[] = sprintf( 'O seu peso é %d kg', $weight );
		} elseif ( $height ) {
			$parts[] = sprintf( 'A sua altura é %d cm', $height );
		}
	}
 
	// ── Preferred foot ────────────────────────────────────────────────────────
	if ( $right_foot && $left_foot ) {
		$parts[] = ( $is_deceased || $is_ex_player )
			? 'Era ambidestro e jogava com os dois pés'
			: 'É ambidestro e joga com eficácia com os dois pés';
	} elseif ( $right_foot ) {
		$parts[] = ( $is_deceased || $is_ex_player )
			? 'Jogava preferencialmente com o pé direito'
			: 'Joga preferencialmente com o pé direito';
	} elseif ( $left_foot ) {
		$parts[] = ( $is_deceased || $is_ex_player )
			? 'Jogava preferencialmente com o pé esquerdo'
			: 'Joga preferencialmente com o pé esquerdo';
	}
 
	if ( empty( $parts ) ) {
		return '';
	}
	return implode( '. ', $parts ) . '.';
}
 
 
/* ===========================================================================
 * Active club for a player — faithful port of Player.get_squad() /
 * get_team_as_player_on_edition() (person/models.py).
 *
 * Django (edition = None):
 *   squad = squad_set.filter(team__team__team_type='club')
 *                    .order_by('-edition','order')[0]
 *   later = squad.team.team.teamstep_set.filter(
 *               edition__gt=squad.edition, step=squad.team.step,
 *               gender=squad.team.gender, modality=squad.team.modality)
 *   if later.count() > 0: return None   # player left → ex-player
 *   return squad.team.team
 *
 * Returns ['name','country'] when the player is CURRENTLY at a club,
 * or null (→ ex-player) otherwise. One-or-two queries, cached per legacy id.
 *
 * Notes on schema (confirmed against source):
 *   - team_squad.team_id      → team_teamstep.id   (squad.team is a TeamStep)
 *   - team_teamstep.team_id   → team_team.id       (teamstep.team is the Team)
 *   - editions are 4-digit year strings, so '>' / DESC string compares are
 *     numerically correct and match Django's CharField behaviour exactly.
 *   - `order` is a reserved word → must be back-quoted.
 * =========================================================================== */
function f365_player_active_club( int $legacy_player_id ): ?array {
	static $cache = [];
	if ( array_key_exists( $legacy_player_id, $cache ) ) {
		return $cache[ $legacy_player_id ];
	}
	if ( $legacy_player_id <= 0 ) {
		return $cache[ $legacy_player_id ] = null;
	}

	global $wpdb;
	$T_SQUAD = $wpdb->prefix . 'team_squad';
	$T_TS    = $wpdb->prefix . 'team_teamstep';
	$T_TEAM  = $wpdb->prefix . 'team_team';
	$T_CTRY  = $wpdb->prefix . 'country_country';
	if ( ! f365_src_table_exists( $T_SQUAD ) || ! f365_src_table_exists( $T_TS ) || ! f365_src_table_exists( $T_TEAM ) ) {
		return $cache[ $legacy_player_id ] = null;
	}

	// 1) Most recent CLUB squad: order_by('-edition','order')[0].
	$sq = $wpdb->get_row( $wpdb->prepare(
		"SELECT  sq.edition   AS squad_edition,
		         ts.team_id   AS team_id,
		         ts.step      AS step,
		         ts.gender    AS gender,
		         ts.modality  AS modality,
		         t.shortname  AS team_name,
		         t.country_id AS country_id
		   FROM  {$T_SQUAD} sq
		   JOIN  {$T_TS}   ts ON ts.id = sq.team_id
		   JOIN  {$T_TEAM} t  ON t.id  = ts.team_id AND t.team_type = 'club'
		  WHERE  sq.player_id = %d
		  ORDER  BY sq.edition DESC, sq.`order` ASC
		  LIMIT  1",
		$legacy_player_id
	), ARRAY_A );

	if ( $wpdb->last_error !== '' || ! is_array( $sq ) || empty( $sq['team_name'] ) ) {
		return $cache[ $legacy_player_id ] = null;
	}

	// 2) Did the club continue to a LATER edition (same step/gender/modality)
	//    without this player? If so, the player has left → ex-player → null.
	$later = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*)
		   FROM  {$T_TS} ts2
		  WHERE  ts2.team_id  = %d
		    AND  ts2.edition  > %s
		    AND  ts2.step     = %s
		    AND  ts2.gender   = %s
		    AND  ts2.modality = %s",
		(int) $sq['team_id'],
		(string) $sq['squad_edition'],
		(string) $sq['step'],
		(string) $sq['gender'],
		(string) $sq['modality']
	) );

	if ( $wpdb->last_error === '' && $later > 0 ) {
		return $cache[ $legacy_player_id ] = null;
	}

	// 3) Active: resolve the club country shortname (team.country.shortname).
	$country = '';
	if ( ! empty( $sq['country_id'] ) && f365_src_table_exists( $T_CTRY ) ) {
		$cn = $wpdb->get_var( $wpdb->prepare(
			"SELECT shortname FROM {$T_CTRY} WHERE id = %d LIMIT 1",
			(int) $sq['country_id']
		) );
		$country = ( $cn !== null ) ? (string) $cn : '';
	}

	return $cache[ $legacy_player_id ] = [
		'name'    => (string) $sq['team_name'],
		'country' => $country,
	];
}


 
 
/* ===========================================================================
 * Helpers — position display + age.
 * =========================================================================== */
function f365_player_position_display( string $position ): string {
	$map = [
		'goalkeeper' => 'guarda-redes',
		'defender'   => 'defesa',
		'midfielder' => 'médio',
		'forward'    => 'avançado',
	];
	$position = strtolower( trim( $position ) );
	return $map[ $position ] ?? $position;
}
 
function f365_player_age( string $birth_date, string $death_date = '' ): ?int {
	if ( $birth_date === '' || $birth_date === '0000-00-00' ) {
		return null;
	}
	try {
		$b   = new DateTime( $birth_date );
		$ref = ( $death_date !== '' && $death_date !== '0000-00-00' ) ? new DateTime( $death_date ) : new DateTime( 'today' );
		return (int) $b->diff( $ref )->y;
	} catch ( Exception $e ) {
		return null;
	}
}
 
 
/* ===========================================================================
 * FILTERS — title + description (+ OG/Twitter mirrors). Guarded to f365_player.
 * =========================================================================== */
function f365_player_wpseo_title( string $title ): string {
	$post_id = f365_player_current_post_id();
	if ( $post_id <= 0 ) {
		return $title; // not a player page — leave other modules untouched
	}
 
	$data = f365_player_seo_data( $post_id );
	$shortname = ( ! empty( $data['shortname'] ) ) ? $data['shortname'] : get_the_title( $post_id );
	$longname  = $data['longname'] ?? '';
 
	$parts   = [ $shortname ];
	if ( $longname !== '' && $longname !== $shortname ) {
		$parts[] = $longname;
	}
 
	$section = (string) get_query_var( 'f365_section' );
	$labels  = f365_player_section_title_labels();
	$parts[] = ( $section !== '' && isset( $labels[ $section ] ) ) ? $labels[ $section ] : 'Resumo';
 
	$parts[] = 'Futebol 365';
 
	return implode( ' - ', $parts );
}
add_filter( 'wpseo_title', 'f365_player_wpseo_title', 20 );
add_filter( 'wpseo_opengraph_title', 'f365_player_wpseo_title', 20 );
add_filter( 'wpseo_twitter_title', 'f365_player_wpseo_title', 20 );
 
 
function f365_player_wpseo_metadesc( string $desc ): string {
	$post_id = f365_player_current_post_id();
	if ( $post_id <= 0 ) {
		return $desc;
	}

	$data = f365_player_seo_data( $post_id );

	// Source parity: every player section template (jogos, palmares, premios,
	// transferencias, noticias, videos, dados-pessoais) extends player-detail.html
	// and overrides ONLY {% block title %}. None override {% block description %},
	// so every section inherits player-detail's description block, which is
	// {{ obj.get_seo_description }} — the biography. The meta description is
	// therefore the SAME biography on every player page, base or section.
	if ( ! empty( $data['seo_description'] ) ) {
		return $data['seo_description'];
	}

	$shortname = ( ! empty( $data['shortname'] ) ) ? $data['shortname'] : get_the_title( $post_id );
	return sprintf(
		'Toda a informação sobre %s. Jogos, golos, estatísticas, palmarés, notícias, vídeos e muito mais!',
		$shortname
	);
}
add_filter( 'wpseo_metadesc', 'f365_player_wpseo_metadesc', 20 );
add_filter( 'wpseo_opengraph_desc', 'f365_player_wpseo_metadesc', 20 );
add_filter( 'wpseo_twitter_desc', 'f365_player_wpseo_metadesc', 20 );

/* ===========================================================================
 * YOAST SEO — canonical + og:url (self-referencing). Guarded to player routes.
 *
 * URL shape mirrors the rewrite rules:
 *   base:    /jogador/{id}/
 *   section: /jogador/{id}/{section}/
 *
 * {id} is the f365_player_id URL segment (the Django/legacy id), so the
 * canonical always equals the real URL. The country/position list routes
 * (/jogador/pais/.. , /jogador/posicao/..) don't set f365_player_id and are
 * left untouched. Priority 40 runs after the generic override (20) and the
 * pagination canonical (30), fully replacing their output for player pages.
 * =========================================================================== */
function f365_player_wpseo_canonical( $canonical ) {
	$player_id = (int) get_query_var( 'f365_player_id' );
	if ( ! $player_id ) {
		return $canonical;
	}

	$section = (string) get_query_var( 'f365_section' );

	$path = "jogador/{$player_id}";
	if ( $section !== '' ) {
		$path .= '/' . $section;
	}

	return user_trailingslashit( home_url( $path ) );
}
add_filter( 'wpseo_canonical', 'f365_player_wpseo_canonical', 40 );
add_filter( 'wpseo_opengraph_url', 'f365_player_wpseo_canonical', 40 );
