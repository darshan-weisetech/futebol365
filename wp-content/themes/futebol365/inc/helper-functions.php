<?php
/**
 * Retrieves an array of seasons linked to related competitions sharing the same federation meta.
 *
 * @param int|null $post_id The current post ID.
 * @return array Multi-dimensional array with season slugs as keys, and names, URLs, and active state as values.
 */

function f365_get_competition_seasons_dropdown( $post_id = null ) {

    $post_id = $post_id ?: get_the_ID();

    if ( ! $post_id ) {
        return [];
    }

    global $wpdb;

    $federation_comp = get_post_meta( $post_id, 'federation_competition', true );

    if ( empty( $federation_comp ) ) {
        return [];
    }

    // current season (taxonomy only once)
    $current_season = wp_get_post_terms(
        $post_id,
        'f365_season',
        [ 'fields' => 'slugs' ]
    );

    $current_season_slug = $current_season[0] ?? '';

    /** STEP 1: single SQL query (NO JOINS except postmeta filter)*/
    $sql = $wpdb->prepare("
        SELECT p.ID
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} fm
            ON fm.post_id = p.ID
            AND fm.meta_key = 'federation_competition'
        WHERE p.post_type = 'f365_competition'
          AND p.post_status = 'publish'
          AND fm.meta_value = %s
    ", $federation_comp );

    $competition_ids = $wpdb->get_col( $sql );

    if ( empty( $competition_ids ) ) {
        return [];
    }

    /** STEP 2: preload meta cache (BIG performance win)*/
    update_meta_cache( 'post', $competition_ids );

    $dropdown = [];

    foreach ( $competition_ids as $competition_id ) {

        // taxonomy (cached internally after first call)
        $terms = get_the_terms( $competition_id, 'f365_season' );

        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            continue;
        }

        $term = $terms[0];

        // meta (now from cache, not DB)
        $season_label = get_post_meta( $competition_id, 'season', true );

        // avoid duplicates
        if ( isset( $dropdown[ $term->slug ] ) ) {
            continue;
        }

        $dropdown[ $term->slug ] = [
            'name'      => $season_label ?: $term->name,
            'url'       => get_permalink( $competition_id ),
            'is_active' => ( $term->slug === $current_season_slug ),
        ];
    }

    krsort( $dropdown, SORT_NATURAL );

    return $dropdown;
}

function futebol365_get_competition_sections( string $competition_type = '', array $flags = [] ) : array {
    $sections = [
        '' => [
            'label'   => 'Resumo',
            'template'=> '',
        ],
        'jogos' => [
            'label'   => 'Jogos',
            'template'=> 'jogos',
        ],
        'calendario' => [
            'label'   => 'Calendário',
            'template'=> 'calendario',
        ],
        'classificacao' => [
            'label'   => 'Classificação',
            'template'=> 'classificacao',
        ],
        'equipas' => [
            'label'   => 'Equipas',
            'template'=> 'equipas',
        ],
        'estatisticas' => [
            'label'   => 'Estatísticas',
            'template'=> 'estatisticas',
        ],
        'transferencias' => [
            'label'   => 'Transferências',
            'template'=> 'transferencias',
        ],
        'estadios' => [
            'label'   => 'Estádios',
            'template'=> 'estadios',
        ],
        'vencedores' => [
            'label'   => 'Vencedores',
            'template'=> 'vencedores',
        ],
        'noticias' => [
            'label'   => 'Notícias',
            'template'=> 'noticias',
        ],
        'videos' => [
            'label'   => 'Vídeos',
            'template'=> 'videos',
        ],
    ];

    return $sections;
}



if ( ! function_exists( 'futebol365_render_pagination' ) ) {
    function futebol365_render_pagination( $current_page, $total_pages ) {
        if ( $total_pages <= 1 ) {
            return;
        }
        $links = paginate_links( [
            'base'      => add_query_arg( 'pagina', '%#%' ),
            'format'    => '',
            'current'   => max( 1, $current_page ),
            'total'     => $total_pages,
            'prev_text' => '&laquo; ' . __( 'Anterior', 'futebol365' ),
            'next_text' => __( 'Seguinte', 'futebol365' ) . ' &raquo;',
            'type'      => 'array',
            'end_size'  => 1,
            'mid_size'  => 2,
        ] );

        if ( empty( $links ) ) {
            return;
        }
        ?>
        <div class="paginacao-new">
            <nav class="ink-navigation">
                <ul class="pagination">
                    <?php
                    foreach ( $links as $link ) {
                        $li_classes = array();

                        if ( strpos( $link, 'current' ) !== false ) {
                            $li_classes[] = 'active';
                            $link = str_replace( 'page-numbers current', 'page-numbers active', $link );
                        }
                        if ( strpos( $link, 'prev' ) !== false ) {
                            $li_classes[] = 'hide-tiny hide-small';
                        }
                        if ( strpos( $link, 'next' ) !== false ) {
                            $li_classes[] = 'hide-tiny hide-small';
                        }
                        $class_attr = ! empty( $li_classes ) ? ' class="' . implode( ' ', $li_classes ) . '"' : '';

                        echo "<li{$class_attr}>" . $link . "</li>";
                    }
                    ?>
                </ul>
            </nav>
        </div>
        <?php
    }
}
class F365_Transfer_Repository {

	/** @var wpdb */
	private $db;

	/** @var string e.g. "wp_". */
	private $p;

	/** @var F365_Competition_Repository|null Used only for participant teamstep ids. */
	private $competition;

	/** @var array<int, object> teamstep id => row (id, team_id, step, modality, gender, edition, team_shortname). */
	private $teamstep_cache = array();

	public function __construct( ?F365_Competition_Repository $competition = null ) {
		global $wpdb;
		$this->db          = $wpdb;
		$this->p           = $wpdb->prefix;
		$this->competition = $competition;
	}

	/**
	 * Full view model for the competition transfers tab.
	 *
	 * @return array{edition:string, teams: array<int, array<string,mixed>>}
	 */
	public function get_competition_transfers_view(
		int $competition_django_id,
		string $edition,
		string $transfer_type = 'trans_type_all',
		int $selected_teamstep_id = 0
	): array {
		$edition = trim( $edition );
		$empty   = array(
			'edition' => $edition,
			'teams'   => array(),
		);
		if ( $competition_django_id <= 0 || '' === $edition ) {
			return $empty;
		}

		/* 1. Participant teamsteps for this competition (from the match table). */
		$participant_ids = $this->get_participant_teamstep_ids( $competition_django_id );
		$participants    = $this->get_teamsteps( $participant_ids );
		$participants    = array_filter( $participants, static fn( $r ) => (string) $r->edition === $edition );

		if ( $selected_teamstep_id > 0 ) {
			$one = $this->get_teamsteps( array( $selected_teamstep_id ) );
			if ( empty( $one[ $selected_teamstep_id ] ) ) {
				return $empty;
			}
			$participants = array( $selected_teamstep_id => $one[ $selected_teamstep_id ] );
		}

		if ( empty( $participants ) ) {
			return $empty;
		}

		/* 2. Previous-edition teamstep map (OUT branch). */
		$prev_map = $this->get_prev_teamstep_map( $participants, (string) ( (int) $edition - 1 ) );

		/* 3. Single joined transfer fetch (names + country ids + dest edition). */
		$scope_ids = $participant_ids;
		foreach ( $prev_map as $prev_id ) {
			if ( $prev_id ) {
				$scope_ids[] = $prev_id;
			}
		}
		$transfers = $this->get_transfers_in_scope( $edition, $scope_ids, $transfer_type );

		/* Pre-compute rendering fields on each transfer row once. */
		foreach ( $transfers as $tr ) {
			$this->decorate_row( $tr, $edition );
		}

		/* 4. Bucket per teamstep using the exact Django rules. */
		$teams = array();
		foreach ( $participants as $tid => $ts ) {
			$ted     = (string) $ts->edition;
			$prev_id = (int) ( $prev_map[ $tid ] ?? 0 );

			$in         = array();
			$out        = array();
			$has_direct = false;

			foreach ( $transfers as $tr ) {
				if ( (int) $tr->team_destination_id === (int) $tid ) {
					$in[]       = $tr;
					$has_direct = true;
				}
				if ( (int) $tr->team_origin_id === (int) $tid ) {
					$out[]      = $tr;
					$has_direct = true;
				} elseif ( $prev_id
					&& (int) $tr->team_origin_id === $prev_id
					&& (string) $tr->dest_edition === $ted ) {
					$out[] = $tr;
				}
			}

			if ( ! $has_direct && $selected_teamstep_id <= 0 ) {
				continue;
			}

			$teams[ $tid ] = array(
				'teamstep_id' => (int) $tid,
				'team_id'     => (int) $ts->team_id,
				'title'       => $this->teamstep_label( $ts ),
				'team_url'    => $this->team_url( (int) $ts->team_id, $edition ),
				'in'          => $in,
				'out'         => $out,
			);
		}

		if ( empty( $teams ) ) {
			return $empty;
		}

		/* 5. Order by team display title (mirrors team__team__shortname). */
		uasort( $teams, static fn( $a, $b ) => strcasecmp( (string) $a['title'], (string) $b['title'] ) );

		return array(
			'edition' => $edition,
			'teams'   => $teams,
		);
	}

	/* ---------------------------------------------------------------------
	 * Queries
	 * ------------------------------------------------------------------- */

	/**
	 * One competition row by its Django competition id (wp_competition_competition.id).
	 * Self-contained so the template needs no F365_Competition_Repository.
	 *
	 * @return object|null
	 */
	public function get_competition( int $competition_id ) {
		if ( $competition_id <= 0 ) {
			return null;
		}

		$cache_key = 'f365_comp_' . $competition_id;
		$cached    = wp_cache_get( $cache_key, 'f365_competition' );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM `{$this->p}competition_competition` WHERE id = %d LIMIT 1",
				$competition_id
			)
		);

		wp_cache_set( $cache_key, $row ?: 0, 'f365_competition', HOUR_IN_SECONDS );
		return $row;
	}

	/**
	 * Participant teamstep ids of a competition, from the union of home/visitor
	 * team ids across its matches.
	 *
	 * @return int[]
	 */
	public function get_participant_teamstep_ids( int $competition_id ): array {
		if ( $this->competition ) {
			return $this->competition->get_participant_teamstep_ids( $competition_id );
		}
		if ( $competition_id <= 0 ) {
			return array();
		}
		$rows = $this->db->get_col(
			$this->db->prepare(
				"SELECT home_team_id FROM `{$this->p}competition_match` WHERE competition_id = %d
				 UNION
				 SELECT visitor_team_id FROM `{$this->p}competition_match` WHERE competition_id = %d",
				$competition_id,
				$competition_id
			)
		);
		return array_values( array_unique( array_filter( array_map( 'intval', (array) $rows ) ) ) );
	}

	/**
	 * Teamstep rows (with team shortname), keyed by id and cached.
	 *
	 * @param int[] $ids
	 * @return array<int, object>
	 */
	private function get_teamsteps( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$missing = array_diff( $ids, array_keys( $this->teamstep_cache ) );
		if ( $missing ) {
			$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
			$rows         = $this->db->get_results(
				$this->db->prepare(
					"SELECT ts.id, ts.team_id, ts.step, ts.modality, ts.gender, ts.edition,
					        tt.shortname AS team_shortname
					   FROM `{$this->p}team_teamstep` ts
					   LEFT JOIN `{$this->p}team_team` tt ON tt.id = ts.team_id
					  WHERE ts.id IN ({$placeholders})",
					$missing
				) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
			foreach ( (array) $rows as $row ) {
				$this->teamstep_cache[ (int) $row->id ] = $row;
			}
		}
		$out = array();
		foreach ( $ids as $id ) {
			if ( isset( $this->teamstep_cache[ $id ] ) ) {
				$out[ $id ] = $this->teamstep_cache[ $id ];
			}
		}
		return $out;
	}

	/**
	 * current teamstep id => previous-edition teamstep id (0 if none).
	 *
	 * @param array<int,object> $participants
	 * @return array<int,int>
	 */
	private function get_prev_teamstep_map( array $participants, string $prev_edition ): array {
		$map = array();
		foreach ( $participants as $tid => $ts ) {
			$map[ $tid ] = 0;
		}
		if ( '' === $prev_edition || empty( $participants ) ) {
			return $map;
		}
		$team_ids     = array_values( array_unique( array_map( static fn( $r ) => (int) $r->team_id, $participants ) ) );
		$placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
		$rows         = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, team_id, step, modality, gender
				   FROM `{$this->p}team_teamstep`
				  WHERE edition = %s AND team_id IN ({$placeholders})",
				array_merge( array( $prev_edition ), $team_ids )
			) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		$index = array();
		foreach ( (array) $rows as $row ) {
			$key = $row->team_id . '|' . $row->step . '|' . $row->modality . '|' . $row->gender;
			if ( ! isset( $index[ $key ] ) ) {
				$index[ $key ] = (int) $row->id;
			}
		}
		foreach ( $participants as $tid => $ts ) {
			$key = $ts->team_id . '|' . $ts->step . '|' . $ts->modality . '|' . $ts->gender;
			if ( isset( $index[ $key ] ) ) {
				$map[ $tid ] = $index[ $key ];
			}
		}
		return $map;
	}

	/**
	 * All edition transfers touching any teamstep in $scope_ids, joined to the
	 * player/person/country and origin/destination team/country tables so the
	 * template needs no further lookups.
	 *
	 * @param int[] $scope_ids
	 * @return object[]
	 */
	private function get_transfers_in_scope( string $edition, array $scope_ids, string $transfer_type ): array {
		$scope_ids = array_values( array_unique( array_filter( array_map( 'intval', $scope_ids ) ) ) );
		if ( ! $scope_ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) );
		$params       = array( $edition );
		array_push( $params, ...$scope_ids );
		array_push( $params, ...$scope_ids );

		$type_sql = '';
		if ( 'trans_type_definitive' === $transfer_type ) {
			$type_sql = " AND t.transfer_type = 'D'";
		} elseif ( 'trans_type_loan' === $transfer_type ) {
			$type_sql = " AND t.transfer_type = 'E'";
		}

		$sql = "
			SELECT
				t.id, t.transfer_type, t.value, t.transfer_date,
				t.player_id, t.team_origin_id, t.team_destination_id, t.tmp_origin_team_id,
				pp.shortname        AS player_name,
				pp.nationality_id   AS player_country_id,
				pc.shortname        AS player_country_name,
				ots.team_id         AS origin_team_id,
				ots.step            AS origin_step,
				ots.modality        AS origin_modality,
				ots.gender          AS origin_gender,
				oteam.shortname     AS origin_team_name,
				oteam.country_id    AS origin_country_id,
				occ.shortname       AS origin_country_name,
				dts.team_id         AS dest_team_id,
				dts.step            AS dest_step,
				dts.modality        AS dest_modality,
				dts.gender          AS dest_gender,
				dts.edition         AS dest_edition,
				dteam.shortname     AS dest_team_name,
				dteam.country_id    AS dest_country_id,
				dcc.shortname       AS dest_country_name
			FROM `{$this->p}transfer_transfer` t
			LEFT JOIN `{$this->p}person_player`  pl    ON pl.id    = t.player_id
			LEFT JOIN `{$this->p}person_person`  pp    ON pp.id    = pl.person_id
			LEFT JOIN `{$this->p}country_country` pc   ON pc.id    = pp.nationality_id
			LEFT JOIN `{$this->p}team_teamstep`  ots   ON ots.id   = t.team_origin_id
			LEFT JOIN `{$this->p}team_team`      oteam ON oteam.id = ots.team_id
			LEFT JOIN `{$this->p}country_country` occ  ON occ.id   = oteam.country_id
			LEFT JOIN `{$this->p}team_teamstep`  dts   ON dts.id   = t.team_destination_id
			LEFT JOIN `{$this->p}team_team`      dteam ON dteam.id = dts.team_id
			LEFT JOIN `{$this->p}country_country` dcc  ON dcc.id   = dteam.country_id
			WHERE t.edition = %s
			  AND ( t.team_origin_id IN ({$placeholders})
			     OR t.team_destination_id IN ({$placeholders}) )
			      {$type_sql}
			ORDER BY t.transfer_date DESC, t.id DESC
		";

		return (array) $this->db->get_results(
			$this->db->prepare( $sql, $params ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/* ---------------------------------------------------------------------
	 * Rendering helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Attach ready-to-print fields to a transfer row (labels, urls, flag ids).
	 * Origin is shown only when team_origin_id is set (mirrors the template).
	 */
	private function decorate_row( object $tr, string $edition ): void {
		$tr->date_fmt   = $tr->transfer_date ? wp_date( 'd-m-Y', strtotime( (string) $tr->transfer_date ) ) : '';
		$tr->player_url = $tr->player_id ? $this->player_url( (int) $tr->player_id ) : '';

		$tr->origin_present = ! empty( $tr->team_origin_id );
		$tr->origin_label   = $tr->origin_present
			? $this->teamstep_label_parts( $tr->origin_team_name, $tr->origin_step, $tr->origin_modality, $tr->origin_gender )
			: '';
		$tr->origin_url     = ( $tr->origin_present && ! empty( $tr->origin_team_id ) )
			? $this->team_url( (int) $tr->origin_team_id, $edition )
			: '';

		$tr->dest_label = $this->teamstep_label_parts( $tr->dest_team_name, $tr->dest_step, $tr->dest_modality, $tr->dest_gender );
		$tr->dest_url   = ! empty( $tr->dest_team_id ) ? $this->team_url( (int) $tr->dest_team_id, $edition ) : '';
	}

	/** TeamStep.__unicode__ for a participant row. */
	private function teamstep_label( object $ts ): string {
		return $this->teamstep_label_parts( $ts->team_shortname, $ts->step, $ts->modality, $ts->gender );
	}

	/** TeamStep.__unicode__ from raw parts. */
	private function teamstep_label_parts( ?string $team_shortname, ?string $step, ?string $modality, ?string $gender ): string {
		$name = (string) $team_shortname;
		$rest = '';
		if ( 'futsal' === $modality ) {
			$rest .= ' FS';
		}
		if ( 'female' === $gender ) {
			$rest .= ' FM';
		}
		$named = array( 'principal', 'b_team', 'c_team', 'veterans', 'reservations' );
		if ( $step && ! in_array( $step, $named, true ) ) {
			$rest .= ' ' . $step;
		}
		if ( 'b_team' === $step ) {
			$rest .= ' B';
		} elseif ( 'c_team' === $step ) {
			$rest .= ' C';
		} elseif ( 'veterans' === $step ) {
			$rest .= ' VET';
		} elseif ( 'reservations' === $step ) {
			$rest .= ' RES';
		}
		$rest = trim( $rest );
		return '' !== $rest ? trim( $name . ' ' . $rest ) : $name;
	}

	/** equipa/{team_id}/?edition= */
	private function team_url( int $team_id, string $edition ): string {
		if ( ! $team_id ) {
			return '';
		}
		$url = user_trailingslashit( home_url( 'equipa/' . $team_id ) );
		return $edition ? add_query_arg( 'edition', $edition, $url ) : $url;
	}

	/** jogador/{player_id}/ */
	private function player_url( int $player_id ): string {
		if ( ! $player_id ) {
			return '';
		}
		return user_trailingslashit( home_url( 'jogador/' . $player_id ) );
	}
}
class F365_Competition_Repository {

	/** @var wpdb */
	private $db;

	/** @var string Table prefix, e.g. "wp_competition_". */
	private $t;

	/** @var string Bare WP prefix, e.g. "wp_" (for cross-app tables). */
	private $p;

	/**
	 * Request-scoped memo for resolved Django ID -> WP post ID maps,
	 * keyed by "{post_type}|{meta_key}".
	 *
	 * @var array<string, array<int,int>>
	 */
	private $resolve_memo = array();

	/**
	 * Meta key that stores the legacy Django ID on each WP post.
	 * Override per post type via the 'f365_competition_source_meta_key' filter.
	 *
	 * @var string
	 */
	private $source_meta_key = 'django_source_id';

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
		$this->t  = $wpdb->prefix . 'competition_';
		$this->p  = $wpdb->prefix;
	}

	/* ---------------------------------------------------------------------
	 * Competition / phases / winners
	 * ------------------------------------------------------------------- */

	/**
	 * One competition row by its Django competition id.
	 *
	 * @return object|null
	 */
	public function get_competition( int $competition_id ) {
		if ( $competition_id <= 0 ) {
			return null;
		}

		$cache_key = 'f365_comp_' . $competition_id;
		$cached    = wp_cache_get( $cache_key, 'f365_competition' );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM `{$this->t}competition` WHERE id = %d LIMIT 1",
				$competition_id
			)
		);

		wp_cache_set( $cache_key, $row ?: 0, 'f365_competition', HOUR_IN_SECONDS );
		return $row;
	}

	/**
	 * Phases of a competition, ordered by `order` then id.
	 *
	 * @return object[]
	 */
	public function get_phases( int $competition_id ): array {
		if ( $competition_id <= 0 ) {
			return array();
		}

		return (array) $this->db->get_results(
			$this->db->prepare(
				"SELECT id, competition_id, `order`, name, phase_type
				   FROM `{$this->t}competitionphase`
				  WHERE competition_id = %d
			   ORDER BY `order` ASC, id ASC",
				$competition_id
			)
		);
	}

	/**
	 * Phase winners of a competition, ordered by `order` then id.
	 *
	 * @return object[]
	 */
	public function get_phase_winners( int $competition_id ): array {
		if ( $competition_id <= 0 ) {
			return array();
		}

		return (array) $this->db->get_results(
			$this->db->prepare(
				"SELECT id, competition_id, `order`,
				        first_place_teamstep_id, second_place_teamstep_id,
				        competition_phase_name, `group`
				   FROM `{$this->t}competitionphasewinners`
				  WHERE competition_id = %d
			   ORDER BY `order` ASC, id ASC",
				$competition_id
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Federation competition / stadiums
	 * ------------------------------------------------------------------- */

	/**
	 * Federation-competition row (holds scope + show_stadiums) by its id.
	 *
	 * @return object|null
	 */
	public function get_federation_competition( int $fed_comp_id ) {
		if ( $fed_comp_id <= 0 ) {
			return null;
		}
		$cache_key = 'f365_fedcomp_' . $fed_comp_id;
		$cached    = wp_cache_get( $cache_key, 'f365_competition' );
		if ( false !== $cached ) {
			return $cached ?: null;
		}
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM `{$this->p}federation_federationcompetition` WHERE id = %d LIMIT 1",
				$fed_comp_id
			)
		);
		wp_cache_set( $cache_key, $row ?: 0, 'f365_competition', HOUR_IN_SECONDS );
		return $row;
	}

	/**
	 * Stadiums of a competition, ordered by shortname — the relational port of
	 * competition.views._get_stadiums_for_competition().
	 *
	 *   show_stadiums == false        → []
	 *   championship scope            → per participant teamstep: teamstep.stadium ?: team.stadium
	 *   other (cup / national team)   → per match: match.stadium ?: home_teamstep.stadium ?: team.stadium
	 *
	 * @return object[] Rows: id, shortname, longname, capacity, city, country_id, country_name.
	 */
	public function get_competition_stadiums( int $competition_id ): array {
		if ( $competition_id <= 0 ) {
			return array();
		}

		$competition = $this->get_competition( $competition_id );
		if ( ! $competition ) {
			return array();
		}

		$fed_comp = $this->get_federation_competition( (int) $competition->federation_competition_id );

		/* show_stadiums defaults to true when the row/flag is absent. */
		if ( $fed_comp && property_exists( $fed_comp, 'show_stadiums' )
			&& null !== $fed_comp->show_stadiums && ! (int) $fed_comp->show_stadiums ) {
			return array();
		}

		$is_championship = $fed_comp
			&& in_array( (string) $fed_comp->scope, array( 'national_championship', 'regional_championship' ), true );

		if ( $is_championship ) {
			/* Participant teamsteps (home ∪ visitor across matches): teamstep.stadium ?: team.stadium. */
			$stadium_ids = $this->db->get_col(
				$this->db->prepare(
					"SELECT DISTINCT COALESCE(ts.stadium_id, t.stadium_id) AS sid
					   FROM (
					        SELECT home_team_id    AS tsid FROM `{$this->t}match` WHERE competition_id = %d
					        UNION
					        SELECT visitor_team_id AS tsid FROM `{$this->t}match` WHERE competition_id = %d
					   ) parts
					   INNER JOIN `{$this->p}team_teamstep` ts ON ts.id = parts.tsid
					   LEFT  JOIN `{$this->p}team_team`     t  ON t.id  = ts.team_id",
					$competition_id,
					$competition_id
				)
			);
		} else {
			/* Per match: match.stadium ?: home_teamstep.stadium ?: team.stadium. */
			$stadium_ids = $this->db->get_col(
				$this->db->prepare(
					"SELECT DISTINCT COALESCE(m.stadium_id, ts.stadium_id, t.stadium_id) AS sid
					   FROM `{$this->t}match` m
					   LEFT JOIN `{$this->p}team_teamstep` ts ON ts.id = m.home_team_id
					   LEFT JOIN `{$this->p}team_team`     t  ON t.id  = ts.team_id
					  WHERE m.competition_id = %d",
					$competition_id
				)
			);
		}

		$stadium_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $stadium_ids ) ) ) );
		if ( ! $stadium_ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $stadium_ids ), '%d' ) );
		return (array) $this->db->get_results(
			$this->db->prepare(
				"SELECT s.id, s.shortname, s.longname, s.capacity, s.city,
				        s.country_id, c.shortname AS country_name
				   FROM `{$this->p}stadium_stadium` s
				   LEFT JOIN `{$this->p}country_country` c ON c.id = s.country_id
				  WHERE s.id IN ({$placeholders})
			   ORDER BY s.shortname ASC, s.id ASC",
				$stadium_ids
			) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/* ---------------------------------------------------------------------
	 * Matches
	 * ------------------------------------------------------------------- */

	/**
	 * Matches with flexible filtering. All filters optional but at least one
	 * scoping filter (competition_id or team_id) is strongly recommended.
	 *
	 * @param array{
	 *   competition_id?: int,
	 *   competition_phase_id?: int,
	 *   match_round?: int,
	 *   team_id?: int,         // home OR visitor teamstep id
	 *   date_from?: string,    // 'Y-m-d' or 'Y-m-d H:i:s'
	 *   date_to?: string,
	 *   order?: string,        // 'ASC' | 'DESC' on date_time
	 *   limit?: int,
	 *   offset?: int
	 * } $args
	 * @return object[]
	 */
	public function get_matches( array $args = array() ): array {
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['competition_id'] ) ) {
			$where[]  = 'competition_id = %d';
			$params[] = (int) $args['competition_id'];
		}
		if ( ! empty( $args['competition_phase_id'] ) ) {
			$where[]  = 'competition_phase_id = %d';
			$params[] = (int) $args['competition_phase_id'];
		}
		if ( isset( $args['match_round'] ) && '' !== $args['match_round'] ) {
			$where[]  = 'match_round = %d';
			$params[] = (int) $args['match_round'];
		}
		if ( ! empty( $args['team_id'] ) ) {
			$where[]  = '(home_team_id = %d OR visitor_team_id = %d)';
			$params[] = (int) $args['team_id'];
			$params[] = (int) $args['team_id'];
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'date_time >= %s';
			$params[] = (string) $args['date_from'];
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'date_time <= %s';
			$params[] = (string) $args['date_to'];
		}

		$order = ( isset( $args['order'] ) && 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC';

		$limit_sql = '';
		if ( ! empty( $args['limit'] ) ) {
			$limit_sql = $this->db->prepare(
				' LIMIT %d OFFSET %d',
				(int) $args['limit'],
				isset( $args['offset'] ) ? (int) $args['offset'] : 0
			);
		}

		$sql = "SELECT * FROM `{$this->t}match`
		         WHERE " . implode( ' AND ', $where ) . "
		      ORDER BY date_time {$order}, id {$order}{$limit_sql}";

		if ( $params ) {
			$sql = $this->db->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (array) $this->db->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Single match by Django match id.
	 *
	 * @return object|null
	 */
	public function get_match( int $match_id ) {
		if ( $match_id <= 0 ) {
			return null;
		}
		return $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM `{$this->t}match` WHERE id = %d LIMIT 1",
				$match_id
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Per-match detail — batched (single query for N matches)
	 * ------------------------------------------------------------------- */

	/**
	 * Formations for one or many matches, grouped by match_id.
	 *
	 * @param int|int[] $match_ids
	 * @return array<int, object[]>
	 */
	public function get_match_formations( $match_ids ): array {
		return $this->fetch_match_children(
			'matchformation',
			'id, match_id, formation, is_home_team',
			$match_ids
		);
	}

	/**
	 * Coaches for one or many matches, grouped by match_id.
	 *
	 * @param int|int[] $match_ids
	 * @return array<int, object[]>
	 */
	public function get_match_coaches( $match_ids ): array {
		return $this->fetch_match_children(
			'matchcoach',
			'id, match_id, `order`, is_home_team, coach_id, coach_type',
			$match_ids,
			'`order` ASC, id ASC'
		);
	}

	/**
	 * Players for one or many matches, grouped by match_id.
	 *
	 * @param int|int[] $match_ids
	 * @return array<int, object[]>
	 */
	public function get_match_players( $match_ids ): array {
		return $this->fetch_match_children(
			'matchplayer',
			'id, match_id, team_id, player_id, position, minutes, classification,
			 is_titular, is_in, is_out, is_in_minute, is_out_minute,
			 goals, goals_penalty, own_goals, yellow_card, double_yellow_card,
			 red_card, yellow_card_minute, double_yellow_card_minute,
			 red_card_minute, shirt_number',
			$match_ids,
			'is_titular DESC, shirt_number ASC, id ASC'
		);
	}

	/**
	 * Generic batched child fetch keyed by match_id.
	 *
	 * @param int|int[] $match_ids
	 * @return array<int, object[]>
	 */
	private function fetch_match_children(
		string $table,
		string $columns,
		$match_ids,
		string $order_by = 'id ASC'
	): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $match_ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT {$columns}
				   FROM `{$this->t}{$table}`
				  WHERE match_id IN ({$placeholders})
			   ORDER BY match_id ASC, {$order_by}",
				$ids
			) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$grouped = array();
		foreach ( (array) $rows as $row ) {
			$grouped[ (int) $row->match_id ][] = $row;
		}
		return $grouped;
	}

	/* ---------------------------------------------------------------------
	 * Participant teams — derived from the match table
	 * ------------------------------------------------------------------- */

	/**
	 * Distinct participating teamstep ids of a competition, taken from the
	 * union of home_team_id and visitor_team_id across its matches.
	 *
	 * This is the reliable replacement for reading the ACF `participants`
	 * field on the competition post.
	 *
	 * @return int[] Django teamstep ids.
	 */
	public function get_participant_teamstep_ids( int $competition_id ): array {
		if ( $competition_id <= 0 ) {
			return array();
		}

		$rows = $this->db->get_col(
			$this->db->prepare(
				"SELECT home_team_id    AS tid FROM `{$this->t}match` WHERE competition_id = %d
				 UNION
				 SELECT visitor_team_id AS tid FROM `{$this->t}match` WHERE competition_id = %d",
				$competition_id,
				$competition_id
			)
		);

		return array_values( array_unique( array_filter( array_map( 'intval', (array) $rows ) ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Django ID -> WP post ID resolution (batched + memoized)
	 * ------------------------------------------------------------------- */

	/**
	 * Map a list of legacy Django IDs to WP post IDs for a given post type.
	 * Returns [ django_id => wp_post_id ]; unresolved IDs are omitted.
	 *
	 * @param int[]  $django_ids
	 * @param string $post_type   e.g. 'f365_teamstep', 'f365_player', 'f365_coach'.
	 * @return array<int,int>
	 */
	public function resolve_wp_post_ids( array $django_ids, string $post_type ): array {
		$django_ids = array_values( array_unique( array_filter( array_map( 'intval', $django_ids ) ) ) );
		if ( ! $django_ids ) {
			return array();
		}

		$meta_key = apply_filters(
			'f365_competition_source_meta_key',
			$this->source_meta_key,
			$post_type
		);

		$memo_key = $post_type . '|' . $meta_key;
		if ( ! isset( $this->resolve_memo[ $memo_key ] ) ) {
			$this->resolve_memo[ $memo_key ] = array();
		}

		$missing = array_diff( $django_ids, array_keys( $this->resolve_memo[ $memo_key ] ) );
		if ( $missing ) {
			$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );

			$rows = $this->db->get_results(
				$this->db->prepare(
					"SELECT pm.meta_value AS django_id, pm.post_id AS wp_id
					   FROM `{$this->db->postmeta}` pm
					   INNER JOIN `{$this->db->posts}` p
					           ON p.ID = pm.post_id
					          AND p.post_type = %s
					          AND p.post_status = 'publish'
					  WHERE pm.meta_key = %s
					    AND CAST(pm.meta_value AS UNSIGNED) IN ({$placeholders})",
					array_merge( array( $post_type, $meta_key ), array_values( $missing ) )
				) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);

			foreach ( (array) $rows as $row ) {
				$this->resolve_memo[ $memo_key ][ (int) $row->django_id ] = (int) $row->wp_id;
			}
			foreach ( $missing as $miss ) {
				if ( ! isset( $this->resolve_memo[ $memo_key ][ $miss ] ) ) {
					$this->resolve_memo[ $memo_key ][ $miss ] = 0;
				}
			}
		}

		$out = array();
		foreach ( $django_ids as $id ) {
			$wp = $this->resolve_memo[ $memo_key ][ $id ] ?? 0;
			if ( $wp ) {
				$out[ $id ] = $wp;
			}
		}
		return $out;
	}

	/**
	 * Convenience: resolve a single Django ID to a WP post ID (0 if not found).
	 */
	public function resolve_wp_post_id( int $django_id, string $post_type ): int {
		$map = $this->resolve_wp_post_ids( array( $django_id ), $post_type );
		return $map[ $django_id ] ?? 0;
	}

	/**
	 * Prime the WP object cache for a set of WP post IDs so that downstream
	 * get_the_title()/get_permalink()/get_post_meta() calls hit cache, not DB.
	 *
	 * @param int[] $wp_post_ids
	 */
	public function prime_posts( array $wp_post_ids ): void {
		$wp_post_ids = array_values( array_unique( array_filter( array_map( 'intval', $wp_post_ids ) ) ) );
		if ( $wp_post_ids ) {
			_prime_post_caches( $wp_post_ids, false, true );
		}
	}
	public function get_participant_team_options( int $competition_id ): array {
		if ( $competition_id <= 0 ) {
			return array();
		}
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT DISTINCT ts.id AS teamstep_id, t.shortname AS team_name,
				        ts.step, ts.modality, ts.gender
				   FROM (
				        SELECT home_team_id    AS tid FROM `{$this->t}match` WHERE competition_id = %d
				        UNION
				        SELECT visitor_team_id AS tid FROM `{$this->t}match` WHERE competition_id = %d
				   ) parts
				   INNER JOIN `{$this->p}team_teamstep` ts ON ts.id = parts.tid
				   LEFT  JOIN `{$this->p}team_team`     t  ON t.id  = ts.team_id",
				$competition_id,
				$competition_id
			)
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'teamstep_id' => (int) $r->teamstep_id,
				'label'       => $this->teamstep_label_parts( $r->team_name, $r->step, $r->modality, $r->gender ),
			);
		}
		usort( $out, static fn( $a, $b ) => strcasecmp( $a['label'], $b['label'] ) );
		return $out;
	}
	private function teamstep_label_parts( ?string $team_shortname, ?string $step, ?string $modality, ?string $gender ): string {
		$name = (string) $team_shortname;
		$rest = '';
		if ( 'futsal' === $modality ) {
			$rest .= ' FS';
		}
		if ( 'female' === $gender ) {
			$rest .= ' FM';
		}
		if ( 'b_team' === $step ) {
			$rest .= ' B';
		} elseif ( 'c_team' === $step ) {
			$rest .= ' C';
		} elseif ( 'veterans' === $step ) {
			$rest .= ' VET';
		} elseif ( 'reservations' === $step ) {
			$rest .= ' RES';
		}
		$rest = trim( $rest );
		return '' !== $rest ? trim( $name . ' ' . $rest ) : $name;
	}
	private function match_winner( object $m, bool $home ): bool {
		if ( $this->present( $m->penalty_home_score ) && $this->present( $m->penalty_visitor_score ) ) {
			return $home ? ( $m->penalty_home_score > $m->penalty_visitor_score ) : ( $m->penalty_home_score < $m->penalty_visitor_score );
		}
		if ( $this->present( $m->home_score_extra ) && $this->present( $m->visitor_score_extra ) ) {
			return $home ? ( $m->home_score_extra > $m->visitor_score_extra ) : ( $m->home_score_extra < $m->visitor_score_extra );
		}
		if ( $this->present( $m->home_score ) && $this->present( $m->visitor_score ) ) {
			return $home ? ( $m->home_score > $m->visitor_score ) : ( $m->home_score < $m->visitor_score );
		}
		return false;
	}
	private function round_header( object $m ): array {
		$args  = array( 'edition' => (string) $m->comp_edition );
		$phase = trim( (string) ( $m->phase_name ?? '' ) );

		if ( null !== $m->competition_phase_id && '' !== $phase ) {
			$args['competition_phase'] = $phase;
			$label                     = $phase;
			if ( $this->present( $m->group_phase_round ) ) {
				$args['phase_round'] = $m->group_phase_round;
				$label              .= ' - ' . $m->group_phase_round . 'ª Jornada';
			}
		} else {
			$args['competition_round'] = (int) $m->match_round;
			$label                     = $m->match_round . 'ª Jornada';
		}

		$base = user_trailingslashit( home_url( 'competicao/' . (int) $m->fed_comp_id ) );
		return array(
			'label' => $label,
			'url'   => add_query_arg( $args, $base ),
		);
	}
	private function team_match_url( object $m, string $side ): string {
		$team_id = (int) ( 'home' === $side ? $m->home_team_team_id : $m->visitor_team_team_id );
		if ( ! $team_id ) {
			return '';
		}
		$args = array(
			'edition'  => (string) ( 'home' === $side ? $m->home_edition : $m->visitor_edition ),
			'modality' => (string) ( 'home' === $side ? $m->home_modality : $m->visitor_modality ),
			'gender'   => (string) ( 'home' === $side ? $m->home_gender : $m->visitor_gender ),
			'step'     => (string) ( 'home' === $side ? $m->home_step : $m->visitor_step ),
		);
		$type = (string) ( 'home' === $side ? $m->home_type : $m->visitor_type );
		if ( 'national' === $type ) {
			$args['competition'] = (int) $m->competition_id;
		}
		$base = user_trailingslashit( home_url( 'equipa/' . $team_id . '/jogos' ) );
		return add_query_arg( $args, $base );
	}
	public function get_competition_calendar( int $competition_id, ?int $team_step = null ): array {
		if ( $competition_id <= 0 ) {
			return array();
		}

		$where  = 'm.competition_id = %d';
		$params = array( $competition_id );
		if ( $team_step ) {
			$where   .= ' AND ( m.home_team_id = %d OR m.visitor_team_id = %d )';
			$params[] = (int) $team_step;
			$params[] = (int) $team_step;
		}

		$sql = "
			SELECT
				m.id, m.competition_id, m.match_round, m.competition_phase_id,
				m.group_phase_round, m.group_home_team, m.group_visitor_team,
				m.date_time, m.situation,
				m.home_team_id, m.visitor_team_id,
				m.home_score, m.visitor_score, m.home_score_extra, m.visitor_score_extra,
				m.penalty_home_score, m.penalty_visitor_score,
				cp.name        AS phase_name,
				cp.phase_type  AS phase_type,
				hts.team_id    AS home_team_team_id, hts.edition AS home_edition,
				hts.modality   AS home_modality, hts.gender AS home_gender, hts.step AS home_step,
				hteam.shortname  AS home_name, hteam.team_type AS home_type,
				vts.team_id    AS visitor_team_team_id, vts.edition AS visitor_edition,
				vts.modality   AS visitor_modality, vts.gender AS visitor_gender, vts.step AS visitor_step,
				vteam.shortname  AS visitor_name, vteam.team_type AS visitor_type,
				c.federation_competition_id AS fed_comp_id, c.edition AS comp_edition
			FROM `{$this->t}match` m
			LEFT JOIN `{$this->t}competitionphase` cp ON cp.id = m.competition_phase_id
			LEFT JOIN `{$this->p}team_teamstep`    hts ON hts.id = m.home_team_id
			LEFT JOIN `{$this->p}team_team`        hteam ON hteam.id = hts.team_id
			LEFT JOIN `{$this->p}team_teamstep`    vts ON vts.id = m.visitor_team_id
			LEFT JOIN `{$this->p}team_team`        vteam ON vteam.id = vts.team_id
			LEFT JOIN `{$this->t}competition`      c ON c.id = m.competition_id
			WHERE {$where}
			ORDER BY m.date_time ASC, hteam.shortname ASC, m.id ASC
		";

		$rows = (array) $this->db->get_results(
			$this->db->prepare( $sql, $params ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		foreach ( $rows as $m ) {
			$this->decorate_match( $m );
		}
		return $rows;
	}
		private function decorate_match( object $m ): void {
		$ts = $m->date_time ? strtotime( (string) $m->date_time ) : 0;

		$m->date_fmt   = $ts ? wp_date( 'd-m-y', $ts ) : '';
		$m->time_fmt   = $ts ? wp_date( 'H:i', $ts ) : '';
		$m->date_short = $ts ? wp_date( 'd-m', $ts ) : '';

		$m->result          = $this->match_result( $m );
		$m->home_is_winner  = $this->match_winner( $m, true );
		$m->visitor_is_winner = $this->match_winner( $m, false );

		$header          = $this->round_header( $m );
		$m->round_label  = $header['label'];
		$m->round_url    = $header['url'];

		$m->home_label    = (string) $m->home_name;
		$m->visitor_label = (string) $m->visitor_name;
		$m->home_url      = $this->team_match_url( $m, 'home' );
		$m->visitor_url   = $this->team_match_url( $m, 'visitor' );

		$m->match_url = user_trailingslashit( home_url( 'jogo/' . (int) $m->id ) );
	}
	private function present( $v ): bool {
		return null !== $v && '' !== $v;
	}
	private function match_result( object $m ): string {
		switch ( (string) $m->situation ) {
			case 'delayed':
				return 'ADI';
			case 'suspended':
				return 'SUS';
			case 'abrogated':
				return 'ANU';
		}
		if ( $this->present( $m->penalty_home_score ) && $this->present( $m->penalty_visitor_score ) ) {
			return $m->penalty_home_score . ' - ' . $m->penalty_visitor_score . ' g.p';
		}
		if ( $this->present( $m->home_score_extra ) && $this->present( $m->visitor_score_extra ) ) {
			return $m->home_score_extra . ' - ' . $m->visitor_score_extra . ' a.p';
		}
		if ( $this->present( $m->home_score ) && $this->present( $m->visitor_score ) ) {
			return $m->home_score . ' - ' . $m->visitor_score;
		}
		return 'vs';
	}
	public function get_competition_rounds( int $competition_id ): array {
		if ( $competition_id <= 0 ) {
			return array();
		}
		$rows = $this->db->get_col(
			$this->db->prepare(
				"SELECT DISTINCT match_round
				   FROM `{$this->t}match`
				  WHERE competition_id = %d
			   ORDER BY match_round ASC",
				$competition_id
			)
		);
		return array_values( array_filter( array_map( 'intval', (array) $rows ), static fn( $r ) => $r > 0 ) );
	}
	public function get_competition_last_round( int $competition_id ): int {
		if ( $competition_id <= 0 ) {
			return 1;
		}
		$round = $this->db->get_var(
			$this->db->prepare(
				"SELECT match_round
				   FROM `{$this->t}match`
				  WHERE competition_id = %d AND date_time IS NOT NULL
			   ORDER BY ABS( TIMESTAMPDIFF( SECOND, date_time, NOW() ) ) ASC, date_time DESC
			      LIMIT 1",
				$competition_id
			)
		);
		return $round ? (int) $round : 1;
	}

}


if ( ! function_exists( 'f365_get_current_timezone' ) ) {
	function f365_get_current_timezone() {
		global $wpdb;

		$tz_table      = $wpdb->prefix . 'common_timezone';
		$country_table = $wpdb->prefix . 'country_country';
		$default_code  = wp_timezone_string();
		$selected_id = isset( $_COOKIE['timezone'] ) ? trim( (string) wp_unslash( $_COOKIE['timezone'] ) ) : '';

		$tz = null;
		if ( '' !== $selected_id && ctype_digit( $selected_id ) ) {
			$tz = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, clock_id, city, country_id FROM {$tz_table} WHERE id = %d",
					(int) $selected_id
				)
			);
		}
		if ( null === $tz ) {
			$tz = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, clock_id, city, country_id FROM {$tz_table} WHERE code = %s",
					$default_code
				)
			);
		}
		if ( null === $tz ) {
			return null;
		}
		$tz->country = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT shortname FROM {$country_table} WHERE id = %d",
				(int) $tz->country_id
			)
		);

		return $tz;
	}
}

if ( ! function_exists( 'f365_render_timezone_clock' ) ) {
	function f365_render_timezone_clock() {
		$tz = f365_get_current_timezone();
		if ( null === $tz ) {
			return;
		}
		$clock_src = 'https://free.timeanddate.com/clock/i3bydz9o/n' . (string) $tz->clock_id . '/fs12/fcccc/tct/pct/th1';
		$tz_url    = home_url( '/fusos-horarios/' ); // {% url timezones %}
		?>
		<div class="timezone">
			<iframe title="relogio" src="<?php echo esc_url( $clock_src ); ?>"></iframe><a href="<?php echo esc_url( $tz_url ); ?>"><?php echo esc_html( $tz->city ); ?>, <?php echo esc_html( (string) $tz->country ); ?></a>
		</div>
		<?php
	}
}


function futebol365_get_site_logo() {
    $home_url = home_url( '/' );
    $html     = '';

    if ( has_custom_logo() ) {
        $custom_logo_id    = get_theme_mod( 'custom_logo' );
        $custom_attributes = array(
            'alt'   => esc_attr__( 'Site Logo', 'futebol365' ),
            'title' => esc_attr__( 'Site Logo', 'futebol365' ),
        );

        $logo_img = wp_get_attachment_image( $custom_logo_id, 'full', false, $custom_attributes );

        if ( $logo_img ) {
            $html = sprintf(
                '<a href="%1$s" rel="home" class="custom-logo-link">%2$s</a>',
                esc_url( $home_url ),
                $logo_img
            );
        }
    }

    // Fallback if no custom logo is uploaded
    if ( empty( $html ) ) {
        $html = sprintf(
            '<a href="%1$s" rel="home" class="custom-logo-link"><img src="%2$s" alt="%3$s" title="%3$s"></a>',
            esc_url( $home_url ),
            esc_url( get_template_directory_uri() . '/assets/images/logo.png' ),
            esc_attr__( 'Site Logo', 'futebol365' )
        );
    }

    return $html;
}

/** Register 6 Footer Widget Areas.*/
function futebol365_register_footer_widgets() {

    for ( $i = 1; $i <= 6; $i++ ) {
        register_sidebar(
            array(
                'name'          => sprintf( esc_html__( 'Footer Column %d', 'futebol365' ), $i ),
                'id'            => 'footer-sidebar-' . $i,
                'description'   => sprintf( esc_html__( 'Widgets added here will appear in column %d of the footer.', 'futebol365' ), $i ),
                'before_widget' => '<div id="%1$s" class="widget %2$s">',
                'after_widget'  => '</div>',
                'before_title'  => '<h3 class="widget-title">',
                'after_title'   => '</h3>',
            )
        );
    }

}
add_action( 'widgets_init', 'futebol365_register_footer_widgets' );

add_action( 'acf/init', 'futebol365_register_footer_menu_block' );
function futebol365_register_footer_menu_block() {

    // Check if the block registration function exists
    if ( function_exists( 'acf_register_block_type' ) ) {

        acf_register_block_type( array(
            'name'            => 'footer-column-menus',
            'title'           => __( 'Footer Column Menus', 'futebol365' ),
            'description'     => __( 'A block to assign multiple menus inside a widget column.', 'futebol365' ),
            'render_template' => get_template_directory() . '/blocks/widget-block/footer-menus.php',
            'category'        => 'formatting',
            'icon'            => 'menu-alt',
            'keywords'        => array( 'footer', 'menu', 'select' ),
            'supports'        => array(
                'align' => false, // Disables wide alignment options for widget compatibility
            ),
        ) );
    }
}

/** Force inject a placeholder attribute into the search form input field*/
function f365_force_search_placeholder( $form ) {
    if ( strpos( $form, 'placeholder=' ) !== false ) {
        $form = preg_replace( '/placeholder="[^"]*"/', 'placeholder="' . esc_attr__( 'Pesquisar...', 'futebol365' ) . '"', $form );
    } else {
        $form = str_replace( 'name="s"', 'name="s" placeholder="' . esc_attr__( 'Pesquisar...', 'futebol365' ) . '"', $form );
    }

    return $form;
}
add_filter( 'get_search_form', 'f365_force_search_placeholder', 999 );


/** Function to return lagacy_id for all competitions */

function get_legacy_competition_id( $post_id = null ) {
    global $wpdb;

    if ( empty( $post_id ) ) {
        $post_id = get_the_ID();
    }

    if ( ! $post_id ) {
        return null;
    }

    return $wpdb->get_var( $wpdb->prepare(
        "SELECT legacy_id FROM {$wpdb->posts} WHERE ID = %d",
        $post_id
    ) );
}