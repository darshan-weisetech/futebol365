<?php
/**
 * Migration module: Persons — Player, Coach, President, Referee.  (corrected)
 *
 *
 * Live SEO reference
 *   Title  (base appends " - Futebol 365"):
 *     {name}{ - longname?} - Resumo            (name = coach_name | shortname)
 *   Description:
 *     player    → Player.get_seo_description() (dynamic — mirrored here)
 *     coach     → "Toda a informação sobre o treinador {shortname}. Jogos,
 *                  estatísticas, palmarés, notícias, vídeos e muito mais!"
 *     president → "Toda a informação sobre o dirigente {longname}."
 *     referee   → "Toda a informação sobre {shortname}. Jogos, golos,
 *                  estatísticas, palmarés, notícias, vídeos e muito mais!"
 *
 * @package Futebol365
 * @since   2.1.1
 */

defined( 'ABSPATH' ) || exit;

/*
 * NOTE: This file now serves two contexts.
 *   - Under WP-CLI it declares the migration traits (F365_Module_Persons,
 *     F365_Module_PersonLinks) used by the migrate command.
 *   - In ALL contexts (including the front-end and admin) it declares the
 *     player lazy-hydration class + hooks at the bottom of the file.
 * The CLI traits are therefore wrapped in an `if ( WP_CLI )` block instead of
 * an early `return`, so the front-end hydration code below still loads.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) :

trait F365_Module_Persons {

    // In-memory term cache — avoids repeated get_term_by() hits for the same
    // nationality/position name. Populated once per unique value, then pure PHP.
    private array $_person_term_cache = [];

    // ════════════════════════════════════════════════════════════════════════
    // SHARED SQL
    // ════════════════════════════════════════════════════════════════════════
 
    /**
     * Extended SELECT for players — conditionally includes SEO team columns
     * so that get_player_seo_description() needs zero extra PostgreSQL queries.
     */
    private function player_select_extended( bool $is_seo = false ): string {
        $seo_cols = "";
        if ( $is_seo ) {
            $seo_cols = ",
                   -- Latest club team for SEO description (replaces squad_info query)
                   (SELECT t2.shortname
                    FROM   team_squad s2 JOIN team_team t2 ON t2.id = s2.team_id
                    WHERE  s2.player_id = pl.id AND t2.team_type = 'club'
                    ORDER  BY s2.edition DESC, s2.id DESC LIMIT 1
                   ) AS seo_team_name,

                   (SELECT c2.shortname
                    FROM   team_squad s2
                    JOIN   team_team  t2 ON t2.id = s2.team_id
                    LEFT JOIN country_country c2 ON c2.id = t2.country_id
                    WHERE  s2.player_id = pl.id AND t2.team_type = 'club'
                    ORDER  BY s2.edition DESC, s2.id DESC LIMIT 1
                   ) AS seo_team_country,

                   -- NULL  = still active (no newer TeamStep); NOT NULL = ex-player
                   (SELECT ts2.id
                    FROM   team_squad s2
                    JOIN   team_team  t2  ON t2.id  = s2.team_id
                    JOIN   team_teamstep ts2 ON ts2.team_id = s2.team_id
                                            AND ts2.edition  > s2.edition
                    WHERE  s2.player_id = pl.id AND t2.team_type = 'club'
                    LIMIT  1
                   ) AS seo_has_newer_step";
        }

        return "
            SELECT pl.*, p.shortname, p.longname, p.slug,
                   p.birth_date, p.death_date, p.birth_place,
                   p.url AS person_url, p.email, p.facebook, p.twitter,
                   p.youtube, p.instagram, p.googleplus,
                   p.id AS person_id, p.nationality_id, p.naturality_id,
                   c.shortname  AS nationality_name,
                   nc.shortname AS naturality_name
                   {$seo_cols}
            FROM   person_player pl
            JOIN   person_person p   ON p.id   = pl.person_id
            LEFT JOIN country_country c  ON c.id  = p.nationality_id
            LEFT JOIN country_country nc ON nc.id = p.naturality_id
        ";
    }

    /** SELECT + JOINs for a role table, WITHOUT order/where/limit. */
    private function person_select_base( string $role_table, string $alias ): string {
        return "
            SELECT {$alias}.*, p.shortname, p.longname, p.slug,
                   p.birth_date, p.death_date, p.birth_place,
                   p.url AS person_url, p.email, p.facebook, p.twitter,
                   p.youtube, p.instagram, p.googleplus,
                   p.id AS person_id, p.nationality_id, p.naturality_id,
                   c.shortname AS nationality_name,
                   nc.shortname AS naturality_name
            FROM {$role_table} {$alias}
            JOIN person_person p ON p.id = {$alias}.person_id
            LEFT JOIN country_country c  ON c.id  = p.nationality_id
            LEFT JOIN country_country nc ON nc.id = p.naturality_id
        ";
    }
 
    /** Back-compat: full ordered query (used by single-pass importers). */
    private function person_sql( string $role_table, string $role_alias = 'ro' ): string {
        return $this->person_select_base( $role_table, $role_alias )
             . " ORDER BY {$role_alias}.id";
    }
 
    private function person_meta( array $r ): array {
 
        // ACF taxonomy fields (nationality / naturality) store their own term
        // id in postmeta — they are NOT populated by wp_set_object_terms unless
        // "load_terms" is enabled (it is not). So we resolve the country term
        // and write the id here for EVERY role (this is what player already did
        // and coach/president/referee previously omitted → empty field).
        $nationality_id = ! empty( $r['nationality_name'] )
            ? $this->_ensure_term_cached( $r['nationality_name'], 'f365_country' )
            : 0;
        $naturality_id  = ! empty( $r['naturality_name'] )
            ? $this->_ensure_term_cached( $r['naturality_name'], 'f365_country' )
            : 0;
 
        return [
            'shortname'        => $r['shortname']   ?? '',
            'longname'         => $r['longname']    ?? '',
            'slug'             => $r['slug']        ?? '',
            'birth_date'       => $this->d( $r['birth_date'] ?? '' ),
            'death_date'       => $this->d( $r['death_date'] ?? '' ),
            'birth_place'      => $r['birth_place'] ?? '',
            'url'              => $r['person_url']  ?? '',
            'email'            => $r['email']       ?? '',
            'facebook'         => $r['facebook']    ?? '',
            'twitter'          => $r['twitter']     ?? '',
            'youtube'          => $r['youtube']     ?? '',
            'instagram'        => $r['instagram']   ?? '',
            'googleplus'       => $r['googleplus']  ?? '',
            'nationality'      => $nationality_id ?: '',
            'naturality'       => $naturality_id  ?: '',
            'django_person_id' => (int) ( $r['person_id'] ?? 0 ),
        ];
    }
 
    // ════════════════════════════════════════════════════════════════════════
    // IDEMPOTENT EXISTENCE CHECK (shared by all roles)
    // ════════════════════════════════════════════════════════════════════════
 
    private function person_find_existing( string $cpt, string $meta_key, int $django_id, string $slug ): int {
        global $wpdb;
 
        // 1) id map
        $wp_id = $this->map_resolve( $cpt, $django_id );
        if ( $wp_id && get_post( (int) $wp_id ) ) {
            return (int) $wp_id;
        }
 
        // 2) authoritative meta
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT pm.post_id
               FROM {$wpdb->postmeta} pm
               JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key = %s AND pm.meta_value = %d
                AND p.post_type = %s AND p.post_status != 'trash'
              LIMIT 1",
            $meta_key, $django_id, $cpt
        ) );
        if ( $found ) {
            $this->map_set( $cpt, $django_id, (int) $found );
            $this->map_save( $cpt );
            return (int) $found;
        }
 
        // 3) slug or numeric post_name
        $candidates = array_values( array_filter( [ $slug, (string) $django_id ] ) );
        if ( $candidates ) {
            $place = implode( ',', array_fill( 0, count( $candidates ), '%s' ) );
            $found = $wpdb->get_var( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                  WHERE post_name IN ($place) AND post_type = %s
                    AND post_status != 'auto-draft' LIMIT 1",
                array_merge( $candidates, [ $cpt ] )
            ) );
            if ( $found ) {
                $this->map_set( $cpt, $django_id, (int) $found );
                $this->map_save( $cpt );
                return (int) $found;
            }
        }
 
        return 0;
    }
 
    private function person_set_seo( int $wp_id, string $title, string $desc ): void {
        if ( $this->dry_run || ! function_exists( 'f365_set_yoast_seo' ) ) {
            return;
        }
        f365_set_yoast_seo( $wp_id, $title, $desc );
    }
 
    private function person_seo_title( string $name, string $longname ): string {
        $name     = trim( $name );
        $longname = trim( $longname );
        $title    = $name;
        if ( $longname !== '' && $longname !== $name ) {
            $title .= ' - ' . $longname;
        }
        return $title . ' - Resumo - Futebol 365';
    }
 
    // ════════════════════════════════════════════════════════════════════════
    // PLAYERS  (keyset pagination)
    // ════════════════════════════════════════════════════════════════════════
 
protected function import_players(): void {
	global $wpdb;
 
	wp_suspend_cache_addition( true );
 
	$this->player_ensure_legacy_columns();
 
	$T_PLAYER = 'wp_person_player';
	$T_PERSON = 'wp_person_person';
	$tag      = 'person_player';
	$ptype    = 'f365_player';
 
	if ( ! $this->player_src_table_exists( $T_PLAYER ) || ! $this->player_src_table_exists( $T_PERSON ) ) {
		WP_CLI::error( "Source tables missing: ensure {$T_PLAYER} and {$T_PERSON} are imported." );
		wp_suspend_cache_addition( false );
		return;
	}
 
	// Keyset cursor + optional range bound.
	$after  = (int) ( $this->assoc_args['after-id'] ?? $this->offset );
	$max_id = isset( $this->assoc_args['max-id'] ) ? (int) $this->assoc_args['max-id'] : 0;
 
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T_PLAYER}" );
	$cap   = min( $total, (int) $this->limit );
	WP_CLI::log( "Players (thin): total=$total cap=$cap batch={$this->batch} after-id={$after}" . ( $max_id ? " max-id={$max_id}" : '' ) );
	$progress = WP_CLI\Utils\make_progress_bar( 'Players (thin)', $cap );
 
	$created = $updated = $fail = 0;
	$handled = 0;
 
	while ( $handled < (int) $this->limit ) {
 
		// KEYSET page: strictly greater than the last id we saw. O(1) on PK.
		$where_max = $max_id > 0 ? ' AND pl.id <= ' . $max_id : '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pl.id, pl.person_id, pl.updated_at,
				        (SELECT p.shortname FROM {$T_PERSON} p WHERE p.id = pl.person_id) AS shortname,
				        (SELECT p.slug      FROM {$T_PERSON} p WHERE p.id = pl.person_id) AS slug
				   FROM {$T_PLAYER} pl
				  WHERE pl.id > %d {$where_max}
				  ORDER BY pl.id ASC
				  LIMIT %d",
				$after,
				(int) $this->batch
			),
			ARRAY_A
		);
 
		if ( empty( $rows ) ) {
			break;
		}
 
		foreach ( $rows as $r ) {
			if ( $handled >= (int) $this->limit ) {
				break;
			}
			$after = (int) $r['id']; // advance the keyset cursor every row
			$handled++;
			$progress->tick();
 
			$django_id = (int) $r['id'];
			$person_id = (int) $r['person_id'];
			$shortname = (string) ( $r['shortname'] ?? '' );
			$pd = ! empty( $r['updated_at'] ) ? date( 'Y-m-d H:i:s', strtotime( $r['updated_at'] ) ) : '';
 
			$wp_id = $this->player_find_existing( $django_id );
 
			if ( ! $wp_id ) {
				if ( $this->dry_run ) { $created++; continue; }
 
				$wpdb->query( $wpdb->prepare(
					"INSERT INTO {$wpdb->posts} (
						post_author, post_title, post_name, post_status, post_type,
						post_date, post_date_gmt, post_modified, post_modified_gmt,
						comment_status, ping_status, post_content, post_excerpt,
						to_ping, pinged, post_content_filtered, post_mime_type,
						legacy_table, legacy_id
					) VALUES (
						%d, %s, %s, %s, %s, %s, %s, %s, %s,
						%s, %s, %s, %s, %s, %s, %s, %s, %s, %d
					)",
					1, $shortname, (string) $django_id, 'publish', $ptype,
					$pd ?: current_time( 'mysql' ),
					$pd ? get_gmt_from_date( $pd ) : current_time( 'mysql', 1 ),
					$pd ?: current_time( 'mysql' ),
					$pd ? get_gmt_from_date( $pd ) : current_time( 'mysql', 1 ),
					'closed', 'closed', '', '', '', '', '', '',
					$tag, $django_id
				) );
				$wp_id = (int) $wpdb->insert_id;
 
				if ( ! $wp_id ) {
					$fail++;
					$this->log( 'players', "Missing/insert-failed player_id={$django_id}" );
					continue;
				}
 
				$wpdb->query( $wpdb->prepare(
					"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES (%d, 'django_player_id', %s)",
					$wp_id, (string) $django_id
				) );
				$wpdb->query( $wpdb->prepare(
					"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES (%d, 'django_person_id', %s)",
					$wp_id, (string) $person_id
				) );
 
				$created++;
				$this->log( 'players', "Created Player #{$django_id} -> wp_id={$wp_id} ({$shortname})" );
 
			} else {
				if ( $this->dry_run ) { $updated++; continue; }
 
				$upd = [ 'post_title' => $shortname, 'post_name' => (string) $django_id ];
				if ( $pd ) {
					$upd['post_modified']     = $pd;
					$upd['post_modified_gmt'] = get_gmt_from_date( $pd );
				}
				$wpdb->update( $wpdb->posts, $upd, [ 'ID' => $wp_id ] );
 
				if ( ! get_post_meta( $wp_id, 'django_player_id', true ) ) {
					update_post_meta( $wp_id, 'django_player_id', $django_id );
				}
				if ( ! get_post_meta( $wp_id, 'django_person_id', true ) ) {
					update_post_meta( $wp_id, 'django_person_id', $person_id );
				}
				$this->player_set_legacy_cols( $wp_id, $tag, $django_id, true );
 
				$updated++;
				$this->log( 'players', "Updated Player #{$django_id} -> wp_id={$wp_id} ({$shortname})" );
			}
		}
 
		// If the batch returned fewer than requested, we've reached the end.
		if ( count( $rows ) < (int) $this->batch ) {
			unset( $rows );
			break;
		}
		unset( $rows );
	}
 
	$this->log( 'players', "SUMMARY thin: created=$created updated=$updated failed=$fail (handled=$handled last-id=$after)" );
	WP_CLI::success( "Players (thin): $created created, $updated updated, $fail failed. Last id=$after. Meta hydrates on demand." );
 
	wp_suspend_cache_addition( false );
}

    // ── Thin-import helpers (CLI side) ───────────────────────────────────────

    /**
     * Match an existing player post for a given Django id.
     * Order: django_player_id meta (always present on prior imports) → legacy
     * columns (only if they exist) → in-memory map. Defensive against installs
     * where legacy_table/legacy_id were never added.
     */
    private function player_find_existing( int $django_id ): int {
        global $wpdb;
    
        if ( $this->player_legacy_cols_exist() ) {
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                  WHERE post_type = %s AND legacy_table = %s AND legacy_id = %d
                  LIMIT 1",
                'f365_player', 'person_player', $django_id
            ) );
        }
    
        // Fallback only if legacy columns do not exist in the schema
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT pm.post_id
               FROM {$wpdb->postmeta} pm
               JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key = 'django_player_id' AND pm.meta_value = %s
                AND p.post_type = %s
              LIMIT 1",
            (string) $django_id, 'f365_player'
        ) );
    }

    /** Write legacy columns if they exist. $only_if_empty backfills without clobbering. */
    private function player_set_legacy_cols( int $wp_id, string $tag, int $django_id, bool $only_if_empty = false ): void {
        global $wpdb;
        if ( ! $this->player_legacy_cols_exist() ) {
            return;
        }
        if ( $only_if_empty ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->posts}
                    SET legacy_table = %s, legacy_id = %d
                  WHERE ID = %d AND ( legacy_table IS NULL OR legacy_id IS NULL )",
                $tag, $django_id, $wp_id
            ) );
        } else {
            $wpdb->update( $wpdb->posts, [ 'legacy_table' => $tag, 'legacy_id' => $django_id ], [ 'ID' => $wp_id ] );
        }
    }

    /**
     * True when wp_posts has BOTH legacy columns. Caches only a POSITIVE result
     * so that a negative answer before player_ensure_legacy_columns() runs does
     * not get stuck once the columns are added during the same request.
     */
    private function player_legacy_cols_exist(): bool {
        global $wpdb;
        static $exists = false;
        if ( $exists ) {
            return true;
        }
        $cols = $wpdb->get_col(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$wpdb->posts}'
                AND COLUMN_NAME IN ('legacy_table','legacy_id')"
        );
        $exists = ( in_array( 'legacy_table', (array) $cols, true ) && in_array( 'legacy_id', (array) $cols, true ) );
        return $exists;
    }

    /** Best-effort: add legacy columns + index if absent (idempotent). */
    private function player_ensure_legacy_columns(): void {
        global $wpdb;
        if ( $this->player_legacy_cols_exist() ) {
            return;
        }
        $cols = $wpdb->get_col(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$wpdb->posts}'
                AND COLUMN_NAME IN ('legacy_table','legacy_id')"
        );
        if ( ! in_array( 'legacy_table', (array) $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->posts} ADD COLUMN legacy_table VARCHAR(50) DEFAULT NULL" );
        }
        if ( ! in_array( 'legacy_id', (array) $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->posts} ADD COLUMN legacy_id INT DEFAULT NULL" );
        }
        $wpdb->query( "ALTER TABLE {$wpdb->posts} ADD INDEX idx_legacy_lookup (legacy_table, legacy_id)" );
    }

    private function player_src_table_exists( string $table ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }
 
    protected function import_coaches(): void {
        global $wpdb;

        // Prevent memory leaks during long-running WP-CLI scripts
        wp_suspend_cache_addition( true );

        // Defer term and comment counting to reduce database overhead during bulk insert
        wp_defer_term_counting( true );
        wp_defer_comment_counting( true );

        $this->map_get( 'f365_coach' );
        $this->map_get( 'f365_competition' );
 
        $total = (int) $this->pg_val( 'SELECT COUNT(*) FROM person_coach' );
        $cap   = min( $total, $this->limit );
        WP_CLI::log( "Coaches total: $total | cap: $cap | batch: {$this->batch} | start-after-id: {$this->offset}" );
        $progress = WP_CLI\Utils\make_progress_bar( 'Coaches', $cap );
 
        $coach_competitions = [];
        foreach ( $this->pg_all( "SELECT coach_id, competition_id FROM person_coachcompetition" ) as $cc ) {
            $coach_competitions[ (int) $cc['coach_id'] ][] = (int) $cc['competition_id'];
        }
 
        $created = $updated = $relations_ok = $fail = $skip = 0;
        $handled = 0;
        $after   = (int) $this->offset;
 
        $is_relations = isset( $this->assoc_args['relations'] ) || isset( $this->assoc_args['update-relations-only'] );

        while ( $handled < $this->limit ) {
            $rows = $this->pg_all(
                $this->person_select_base( 'person_coach', 'co' )
                    . ' WHERE co.id > $1 ORDER BY co.id LIMIT $2',
                [ $after, $this->batch ]
            );
            if ( empty( $rows ) ) {
                break;
            }
 
            foreach ( $rows as $r ) {
                if ( $handled >= $this->limit ) {
                    break;
                }
                $after = (int) $r['id'];
                $handled++;
                $progress->tick();
 
                $coach_name = $r['coach_name'] ?? $r['shortname'] ?? '';
 
                $wp_id = $this->person_find_existing( 'f365_coach', 'django_coach_id', (int) $r['id'], $r['slug'] ?? '' );
 
                if ( $is_relations ) {
                    if ( $wp_id ) {
                        // Relations-only mode: Only update competitions ACF relationships (extremely fast)
                        $django_comp_ids = $coach_competitions[ (int) $r['id'] ] ?? [];
                        $comp_wp_ids = $this->coach_resolve_competitions( $django_comp_ids );
                        $this->person_write_acf_relation( $wp_id, 'competitions', 'field_co_competitions', $comp_wp_ids );
                        $this->set_meta( $wp_id, [ 'django_competition_ids' => $django_comp_ids ] );
                        
                        $this->log( 'coaches', "REL-OK coach_id={$r['id']} → wp_id=$wp_id ({$coach_name})" );
                        $relations_ok++;
                        continue;
                    }
                    // If coach doesn't exist, fall through to create them fully
                }

                if ( $wp_id && ! $this->force ) {
                    $skip++;
                    continue;
                }
 
                $is_new = ! $wp_id;
                if ( ! $wp_id ) {
                    $pd = ! empty( $r['updated_at'] ) ? date( 'Y-m-d H:i:s', strtotime( $r['updated_at'] ) ) : '';
                    $wp_id = $this->insert_post( [
                        'post_type'     => 'f365_coach',
                        'post_title'    => $coach_name,
                        'post_name'     => (string) $r['id'],
                        'post_status'   => 'publish',
                        'post_date'     => $pd ?: current_time( 'mysql' ),
                        'post_modified' => $pd ?: current_time( 'mysql' ),
                    ] );
                    if ( ! $wp_id ) {
                        $fail++;
                        $this->log( 'coaches', "FAIL coach_id={$r['id']}" );
                        continue;
                    }
                    $this->map_set( 'f365_coach', $r['id'], $wp_id );
                    $created++;
                    // map_save() intentionally NOT called here — saved once per batch below
                } else {
                    if ( ! $this->dry_run ) {
                        $upd = [ 'post_title' => $coach_name, 'post_name' => (string) $r['id'] ];
                        if ( ! empty( $r['updated_at'] ) ) {
                            $up = date( 'Y-m-d H:i:s', strtotime( $r['updated_at'] ) );
                            $upd['post_modified'] = $up;
                            $upd['post_modified_gmt'] = get_gmt_from_date( $up );
                        }
                        $wpdb->update( $wpdb->posts, $upd, [ 'ID' => $wp_id ] );
                    }
                    $updated++;
                }
 
                $django_comp_ids = $coach_competitions[ (int) $r['id'] ] ?? [];
 
                $meta = $this->person_meta( $r );
                $meta['coach_name']             = $coach_name;
                $meta['level']                  = $r['level'] ?? '';
                $meta['django_coach_id']        = (int) $r['id'];
                $meta['django_source_table']    = 'person_coach';
                $meta['django_competition_ids'] = $django_comp_ids;
                $this->set_meta( $wp_id, $meta );
 
                $comp_wp_ids = $this->coach_resolve_competitions( $django_comp_ids );
                $this->person_write_acf_relation( $wp_id, 'competitions', 'field_co_competitions', $comp_wp_ids );
 
                if ( ! empty( $r['nationality_name'] ) ) {
                    $this->set_terms( $wp_id, [ $r['nationality_name'] ], 'f365_country' );
                }
 
                if ( ! $this->dry_run && ( ! has_post_thumbnail( $wp_id ) || $this->force ) ) {
                    $att_id = $this->sideload_image(
                        $this->media_url( "images/person/{$r['person_id']}_coach.jpg" ),
                        $wp_id, $r['shortname'] ?? '', 'coaches'
                    );
                    if ( $att_id ) {
                        set_post_thumbnail( $wp_id, $att_id );
                    }
                }
 
                $this->person_set_seo(
                    $wp_id,
                    $this->person_seo_title( $coach_name, $r['longname'] ?? '' ),
                    sprintf(
                        'Toda a informação sobre o treinador %s. Jogos, estatísticas, palmarés, notícias, vídeos e muito mais!',
                        trim( $r['shortname'] ?? $coach_name )
                    )
                );
 
                $this->log( 'coaches', ( $is_new ? 'OK' : 'UPDATE' ) . " coach_id={$r['id']} → wp_id=$wp_id ({$coach_name})" );
            }
 
            $this->map_save( 'f365_coach' ); // once per batch
            unset( $rows );
        }
 
        $this->map_save( 'f365_coach' ); // final flush
        $this->log( 'coaches', "SUMMARY: created=$created updated=$updated relations_ok=$relations_ok skipped=$skip failed=$fail (handled=$handled)" );
        WP_CLI::success( "Coaches: $created created, $updated updated, $relations_ok relations-updated, $skip skipped, $fail failed." );

        // Re-enable counting and cache additions
        wp_defer_term_counting( false );
        wp_defer_comment_counting( false );
        wp_suspend_cache_addition( false );
    }
 
    protected function import_presidents(): void {
        $this->map_get( 'f365_president' );
        $rows = $this->pg_all( $this->person_sql( 'person_president', 'pr' ) );
        $n = $skip = $fail = 0;
 
        foreach ( $rows as $r ) {
            if ( $n >= $this->limit ) {
                break;
            }
 
            $wp_id = $this->person_find_existing( 'f365_president', 'django_president_id', (int) $r['id'], $r['slug'] ?? '' );
 
            if ( $wp_id && ! $this->force ) {
                $skip++;
                $this->log( 'presidents', "SKIP president_id={$r['id']}" );
                continue;
            }
 
            $pd = ! empty( $r['updated_at'] ) ? date( 'Y-m-d H:i:s', strtotime( $r['updated_at'] ) ) : '';
 
            if ( ! $wp_id ) {
                $wp_id = $this->insert_post( [
                    'post_type'     => 'f365_president',
                    'post_title'    => $r['shortname'] ?? '',
                    'post_name'     => (string) $r['id'],
                    'post_status'   => 'publish',
                    'post_date'     => $pd ?: current_time( 'mysql' ),
                    'post_modified' => $pd ?: current_time( 'mysql' ),
                ] );
                if ( ! $wp_id ) {
                    $fail++;
                    $this->log( 'presidents', "FAIL president_id={$r['id']}" );
                    continue;
                }
                $this->map_set( 'f365_president', $r['id'], $wp_id );
                $this->map_save( 'f365_president' );
            } elseif ( ! $this->dry_run ) {
                global $wpdb;
                $upd = [
                    'post_title' => $r['shortname'] ?? '',
                    'post_name'  => (string) $r['id'],
                ];
                if ( $pd ) {
                    $upd['post_modified']     = $pd;
                    $upd['post_modified_gmt'] = get_gmt_from_date( $pd );
                }
                $wpdb->update( $wpdb->posts, $upd, [ 'ID' => $wp_id ] );
            }
 
            $meta = $this->person_meta( $r );
            $meta['django_president_id'] = (int) $r['id'];
            $meta['django_source_table'] = 'person_president';
            $this->set_meta( $wp_id, $meta );
 
            if ( ! empty( $r['nationality_name'] ) ) {
                $this->set_terms( $wp_id, [ $r['nationality_name'] ], 'f365_country' );
            }
 
            $name_for_desc = trim( $r['longname'] ?? '' ) ?: trim( $r['shortname'] ?? '' );
            if ( ! $this->dry_run && ( ! has_post_thumbnail( $wp_id ) || $this->force ) ) {
                $att_id = $this->sideload_image(
                    $this->media_url( "images/person/{$r['person_id']}_president.jpg" ),
                    $wp_id, $r['shortname'] ?? '', 'presidents'
                );
                if ( $att_id ) {
                    set_post_thumbnail( $wp_id, $att_id );
                }
            }

            $this->person_set_seo(
                $wp_id,
                $this->person_seo_title( $r['shortname'] ?? '', $r['longname'] ?? '' ),
                sprintf( 'Toda a informação sobre o dirigente %s.', $name_for_desc )
            );
 
            update_option( 'f365_last_president_import', [ 'source_id' => (int) $r['id'], 'wp_id' => $wp_id ], false );
            $this->log( 'presidents', ( $this->force ? 'UPDATE' : 'OK' ) . " president_id={$r['id']} → wp_id=$wp_id ({$r['shortname']})" );
            $n++;
        }
 
        $this->log( 'presidents', "SUMMARY: imported=$n skipped=$skip failed=$fail" );
        WP_CLI::success( "Presidents: $n imported/updated, $skip skipped, $fail failed." );
    }
 
    protected function import_referees(): void {
        $this->map_get( 'f365_referee' );
        $this->map_get( 'f365_federation' );

        $rows = $this->pg_all( $this->person_sql( 'person_referee', 're' ) );
        $n = $skip = $fail = 0;

        foreach ( $rows as $r ) {
            if ( $n >= $this->limit ) {
                break;
            }

            $wp_id = $this->person_find_existing( 'f365_referee', 'django_referee_id', (int) $r['id'], $r['slug'] ?? '' );

            if ( $wp_id && ! $this->force ) {
                $skip++;
                $this->log( 'referees', "SKIP referee_id={$r['id']}" );
                continue;
            }

            $pd = ! empty( $r['updated_at'] ) ? date( 'Y-m-d H:i:s', strtotime( $r['updated_at'] ) ) : '';

            if ( ! $wp_id ) {
                $wp_id = $this->insert_post( [
                    'post_type'     => 'f365_referee',
                    'post_title'    => $r['shortname'] ?? '',
                    'post_name'     => (string) $r['id'],
                    'post_status'   => 'publish',
                    'post_date'     => $pd ?: current_time( 'mysql' ),
                    'post_modified' => $pd ?: current_time( 'mysql' ),
                ] );
                if ( ! $wp_id ) {
                    $fail++;
                    $this->log( 'referees', "FAIL referee_id={$r['id']}" );
                    continue;
                }
                $this->map_set( 'f365_referee', $r['id'], $wp_id );
                // map_save() called once at end of loop
            } elseif ( ! $this->dry_run ) {
                global $wpdb;
                $upd = [
                    'post_title' => $r['shortname'] ?? '',
                    'post_name'  => (string) $r['id'],
                ];
                if ( $pd ) {
                    $upd['post_modified']     = $pd;
                    $upd['post_modified_gmt'] = get_gmt_from_date( $pd );
                }
                $wpdb->update( $wpdb->posts, $upd, [ 'ID' => $wp_id ] );
            }

            $assoc_django_id = (int) ( $r['association_id'] ?? 0 );

            $meta = $this->person_meta( $r );
            $meta['level']                 = $r['level'] ?? '';
            $meta['django_referee_id']     = (int) $r['id'];
            $meta['django_association_id'] = $assoc_django_id;
            $meta['django_source_table']   = 'person_referee';
            $this->set_meta( $wp_id, $meta );

            $assoc_wp_id = $this->referee_resolve_association( $assoc_django_id );
            $this->person_write_acf_object( $wp_id, 'association', 'field_re_association', $assoc_wp_id );

            if ( ! empty( $r['nationality_name'] ) ) {
                $this->set_terms( $wp_id, [ $r['nationality_name'] ], 'f365_country' );
            }

            if ( ! $this->dry_run && ( ! has_post_thumbnail( $wp_id ) || $this->force ) ) {
                $att_id = $this->sideload_image(
                    $this->media_url( "images/person/{$r['person_id']}_referee.jpg" ),
                    $wp_id, $r['shortname'] ?? '', 'referees'
                );
                if ( $att_id ) {
                    set_post_thumbnail( $wp_id, $att_id );
                }
            }

            $this->person_set_seo(
                $wp_id,
                $this->person_seo_title( $r['shortname'] ?? '', $r['longname'] ?? '' ),
                sprintf(
                    'Toda a informação sobre %s. Jogos, golos, estatísticas, palmarés, notícias, vídeos e muito mais!',
                    trim( $r['shortname'] ?? '' )
                )
            );

            $this->log( 'referees', ( $this->force ? 'UPDATE' : 'OK' ) . " referee_id={$r['id']} → wp_id=$wp_id ({$r['shortname']})" );
            $n++;
        }

        $this->map_save( 'f365_referee' );
        $this->log( 'referees', "SUMMARY: imported=$n skipped=$skip failed=$fail" );
        WP_CLI::success( "Referees: $n imported/updated, $skip skipped, $fail failed." );
    }
 
    private function coach_resolve_competitions( array $django_ids ): array {
        $out = [];
        foreach ( $django_ids as $cid ) {
            $cid = (int) $cid;
            if ( ! $cid ) { continue; }
            $wp = $this->map_resolve( 'f365_competition', $cid );
            if ( $wp ) {
                $out[] = (int) $wp;
            } else {
                $this->log( 'coaches', "COMP-MISS coach competition django_id={$cid}" );
            }
        }
        return array_values( array_unique( $out ) );
    }
 
    private function person_write_acf_relation( int $wp_id, string $name, string $key, array $wp_ids ): void {
        if ( $this->dry_run ) { return; }
        update_post_meta( $wp_id, $name, array_map( 'intval', $wp_ids ) );
        update_post_meta( $wp_id, '_' . $name, $key );
    }

    private function person_write_acf_object( int $wp_id, string $name, string $key, ?int $target_id ): void {
        if ( $this->dry_run ) { return; }
        update_post_meta( $wp_id, $name, $target_id ?: '' );
        update_post_meta( $wp_id, '_' . $name, $key );
    }

    private function referee_resolve_association( ?int $assoc_id ): ?int {
        if ( ! $assoc_id ) { return null; }
        $wp_id = $this->map_resolve( 'f365_federation', $assoc_id );
        if ( $wp_id ) { return (int) $wp_id; }

        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
              WHERE meta_key = 'django_federation_id' AND meta_value = %d LIMIT 1",
            $assoc_id
        ) );

        if ( $found ) {
            $this->map_set( 'f365_federation', $assoc_id, (int) $found );
            $this->map_save( 'f365_federation' );
            return (int) $found;
        }
        return null;
    }
 
    /**
     * Cached wrapper around ensure_term().
     *
     * Countries and positions are small, finite sets (~200 countries, 4
     * positions). Without caching, every player fires 3 get_term_by() MySQL
     * reads for values that never change. After the first occurrence each
     * lookup is a PHP array read — zero database cost.
     */
    private function _ensure_term_cached( string $name, string $taxonomy ): int {
        if ( ! $name ) {
            return 0;
        }
        $key = $taxonomy . '|' . $name;
        if ( ! isset( $this->_person_term_cache[ $key ] ) ) {
            $this->_person_term_cache[ $key ] = $this->ensure_term( $name, $taxonomy );
        }
        return $this->_person_term_cache[ $key ];
    }

    private function parse_pg_bool_person( $val ): bool {
        if ( $val === null ) { return false; }
        if ( is_bool( $val ) ) { return $val; }
        $s = strtolower( trim( (string) $val ) );
        return in_array( $s, [ 't', 'true', '1', 'y', 'yes' ], true );
    }
 
    private function get_player_seo_title( array $r ): string {
        return $this->person_seo_title( $r['shortname'] ?? '', $r['longname'] ?? '' );
    }
 
    /**
     * Player description — faithful port of Player.get_seo_description().
     *
     * PERF: The original fired 2 extra PostgreSQL queries per player
     * (squad_info + newer_steps). These are now pre-joined into the main
     * batch SELECT via seo_team_name / seo_team_country / seo_has_newer_step
     * columns. If those columns are absent (e.g. older caller), the method
     * falls back gracefully to empty team info.
     */
    private function get_player_seo_description( array $r ): string {
        $shortname        = trim( $r['shortname'] ?? '' );
        $birth_date       = trim( $r['birth_date'] ?? '' );
        $birth_place      = trim( $r['birth_place'] ?? '' );
        $nationality_name = trim( $r['nationality_name'] ?? '' );
        $position         = trim( $r['position'] ?? '' );
        $weight           = $r['weight'] ? (int) $r['weight'] : 0;
        $height           = $r['height'] ? (int) $r['height'] : 0;
        $right_foot       = $this->parse_pg_bool_person( $r['right_foot'] ?? false );
        $left_foot        = $this->parse_pg_bool_person( $r['left_foot']  ?? false );
        $is_deceased      = ! empty( $r['death_date'] );
 
        // Active team detection — data pre-joined into the batch SELECT via
        // player_select_extended() as seo_team_name / seo_team_country / seo_has_newer_step.
        // This replaces 2 PostgreSQL round-trips per player with zero.
        //
        // seo_has_newer_step is NULL  → no newer TeamStep exists → player still active at that club
        // seo_has_newer_step is NOT NULL → a newer step was found → treat as ex-player
        $team_name       = trim( $r['seo_team_name']    ?? '' );
        $team_country    = trim( $r['seo_team_country']  ?? '' );
        $has_active_team = $team_name !== '' && array_key_exists( 'seo_has_newer_step', $r ) && $r['seo_has_newer_step'] === null;
 
        $is_ex_player = ! $is_deceased && ! $has_active_team;
        $parts = [];
 
        if ( $is_deceased ) {
            $base = $shortname ? sprintf( '%s foi um jogador de futebol', $shortname ) : '';
        } elseif ( $is_ex_player ) {
            $base = $shortname ? sprintf( '%s é um ex-jogador de futebol', $shortname ) : '';
        } else {
            $base = $shortname ? sprintf( '%s é um jogador de futebol', $shortname ) : '';
            if ( $birth_date && ( $ts = strtotime( $birth_date ) ) ) {
                $age = date_diff( date_create( date( 'Y-m-d', $ts ) ), date_create( 'today' ) )->y;
                $base .= sprintf( ' de %d anos', $age );
            }
        }
        if ( $birth_date && ( $ts = strtotime( $birth_date ) ) ) {
            $base .= sprintf( ' nascido em %s', date( 'd-m-Y', $ts ) );
        }
        if ( $birth_place ) {
            $base .= sprintf( ' em %s', $birth_place );
            if ( $nationality_name ) {
                $base .= sprintf( ', %s', $nationality_name );
            }
        }
        if ( $base ) { $parts[] = $base; }
 
        if ( $position ) {
            $map = [ 'goalkeeper' => 'guarda-redes', 'defender' => 'defesa', 'midfielder' => 'médio', 'forward' => 'avançado' ];
            $label = $map[ strtolower( $position ) ] ?? strtolower( $position );
            $txt   = ( $is_deceased || $is_ex_player ) ? sprintf( 'Jogava como %s', $label ) : sprintf( 'Joga como %s', $label );
            if ( $team_name ) {
                $txt .= sprintf( ' em %s', $team_name );
                if ( $team_country ) { $txt .= sprintf( ', %s', $team_country ); }
            }
            $parts[] = $txt;
        }
 
        if ( $is_deceased ) {
            if ( $weight && $height ) { $parts[] = sprintf( 'O seu peso era %d kg e a sua altura era %d cm', $weight, $height ); }
            elseif ( $weight )       { $parts[] = sprintf( 'O seu peso era %d kg', $weight ); }
            elseif ( $height )       { $parts[] = sprintf( 'A sua altura era %d cm', $height ); }
        } else {
            if ( $weight && $height ) { $parts[] = sprintf( 'O seu peso é %d kg e a sua altura é %d cm', $weight, $height ); }
            elseif ( $weight )       { $parts[] = sprintf( 'O seu peso é %d kg', $weight ); }
            elseif ( $height )       { $parts[] = sprintf( 'A sua altura é %d cm', $height ); }
        }
 
        if ( $right_foot && $left_foot ) {
            $parts[] = ( $is_deceased || $is_ex_player ) ? 'Era ambidestro e jogava com os dois pés' : 'É ambidestro e joga com eficácia com os dois pés';
        } elseif ( $right_foot ) {
            $parts[] = ( $is_deceased || $is_ex_player ) ? 'Jogava preferencialmente com o pé direito' : 'Joga preferencialmente com o pé direito';
        } elseif ( $left_foot ) {
            $parts[] = ( $is_deceased || $is_ex_player ) ? 'Jogava preferencialmente com o pé esquerdo' : 'Joga preferencialmente com o pé esquerdo';
        }
 
        return implode( '. ', $parts ) . '.';
    }
}

trait F365_Module_PersonLinks {
 
    private array $pl_role_cpt = [
        'player'    => 'f365_player',
        'coach'     => 'f365_coach',
        'president' => 'f365_president',
        'referee'   => 'f365_referee',
    ];
 
    private array $pl_link_fields = [
        'player'    => [ 'coach'  => [ 'linked_coach',  'field_pl_link_coach' ] ],
        'coach'     => [ 'player' => [ 'linked_player', 'field_co_link_player' ] ],
        'president' => [ 'coach'  => [ 'linked_coach',  'field_pr_link_coach' ] ],
    ];
 
    protected function resolve_person_links(): void {
        global $wpdb;
        $cpt_role = array_flip( $this->pl_role_cpt );
        $chunk    = max( 500, (int) $this->batch );
        WP_CLI::log( 'Finding multi-role persons...' );
        $pids = $wpdb->get_col(
            "SELECT pm.meta_value FROM {$wpdb->postmeta} pm
               JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key = 'django_person_id' AND pm.meta_value <> ''
                AND p.post_type IN ( 'f365_player', 'f365_coach', 'f365_president', 'f365_referee' )
                AND p.post_status NOT IN ( 'trash', 'auto-draft' )
           GROUP BY pm.meta_value HAVING COUNT( DISTINCT p.post_type ) >= 2"
        );
        $pids = array_map( 'intval', (array) $pids );
        if ( $this->limit && count( $pids ) > $this->limit ) { $pids = array_slice( $pids, 0, $this->limit ); }
        $total = count( $pids );
        WP_CLI::log( "Multi-role persons: $total" );
        if ( ! $total ) { WP_CLI::success( 'Person links: nothing to link.' ); return; }
        $progress = WP_CLI\Utils\make_progress_bar( 'Person links', $total );
        $linked_persons = 0;
        $links_written  = 0;
        foreach ( array_chunk( $pids, $chunk ) as $chunk_pids ) {
            $place = implode( ',', array_fill( 0, count( $chunk_pids ), '%d' ) );
            $rows  = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.post_type AS pt, pm.post_id AS wid, pm.meta_value AS pid
                   FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                  WHERE pm.meta_key = 'django_person_id' AND pm.meta_value IN ( $place )
                    AND p.post_type IN ( 'f365_player', 'f365_coach', 'f365_president', 'f365_referee' )
                    AND p.post_status NOT IN ( 'trash', 'auto-draft' )",
                $chunk_pids
            ), ARRAY_A );
            $group = [];
            foreach ( $rows as $row ) {
                $role = $cpt_role[ $row['pt'] ] ?? '';
                $pid  = (int) $row['pid'];
                if ( $role && ! isset( $group[ $pid ][ $role ] ) ) { $group[ $pid ][ $role ] = (int) $row['wid']; }
            }
            foreach ( $group as $pid => $roles ) {
                $progress->tick();
                if ( count( $roles ) < 2 ) { continue; }
                $wrote_for_person = false;
                foreach ( $roles as $role => $wp_id ) {
                    if ( empty( $this->pl_link_fields[ $role ] ) ) { continue; }
                    foreach ( $this->pl_link_fields[ $role ] as $other => $field ) {
                        list( $name, $key ) = $field;
                        $target = $roles[ $other ] ?? 0;
                        if ( $this->dry_run ) {
                            if ( $target ) { $links_written++; $wrote_for_person = true; }
                            continue;
                        }
                        update_post_meta( $wp_id, $name, $target ?: '' );
                        update_post_meta( $wp_id, '_' . $name, $key );
                        if ( $target ) { $links_written++; $wrote_for_person = true; }
                    }
                }
                if ( $wrote_for_person ) { $linked_persons++; $this->log( 'person_links', "OK person_id={$pid} roles=" . implode( ',', array_keys( $roles ) ) ); }
            }
            unset( $rows, $group );
            wp_cache_flush();
        }
        $progress->finish();
        $this->log( 'person_links', "SUMMARY: linked_persons=$linked_persons links_written=$links_written" );
        WP_CLI::success( "Person links: $linked_persons persons linked, $links_written links written." . ( $this->dry_run ? ' (dry-run)' : '' ) );
    }
}
endif; // end CLI-only trait declarations.

