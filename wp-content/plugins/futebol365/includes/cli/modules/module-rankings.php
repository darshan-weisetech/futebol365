<?php
/**
 * Migration module: Rankings (optimized for large datasets)
 *
 * Django tables:
 *   ranking_clubranking         → f365_ranking (ranking_type='club')
 *   ranking_nationalranking     → f365_ranking (ranking_type='national')
 *   ranking_playerranking       → f365_ranking (ranking_type='player')
 *   ranking_playerrankingtype   → stored as f365_ranking_subtype taxonomy terms
 *
 * Map keys are prefixed ('club_N', 'national_N', 'player_N').
 *
 * WP-CLI usage:
 *   wp f365 migrate rankings              ← all three types in sequence
 *   wp f365 migrate rankings_club         ← club only
 *   wp f365 migrate rankings_national     ← national only
 *   wp f365 migrate rankings_player       ← player only
 *
 * Dependency order:
 *   Club and national rankings require teams to be imported first.
 *   Player rankings require players to be imported first.
 *   The three types are independent of each other — no ordering required.
 *
 * @package Futebol365
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    return;
}

trait F365_Module_Rankings {

    protected function import_rankings(): void {
        // Prevent memory leaks during long-running WP-CLI scripts
        wp_suspend_cache_addition( true );

        $this->import_club_rankings();
        $this->import_national_rankings();
        $this->import_player_rankings();

        wp_suspend_cache_addition( false ); // restore after run
    }

    protected function import_club_rankings(): void {
        wp_suspend_cache_addition( true );
        $this->map_get( 'f365_ranking' );
        $this->map_get( 'f365_team' );

        $total = (int) $this->pg_val( 'SELECT COUNT(*) FROM ranking_clubranking' );
        $cap   = min( $total, $this->limit );

        WP_CLI::log( "Club Rankings total: $total | cap: $cap | batch: {$this->batch} | start-after-id: {$this->offset}" );
        $progress = WP_CLI\Utils\make_progress_bar( 'Club Rankings', $cap );

        $n = $skip = $fail = 0;
        $handled = 0;

        // Independent resume cursor — reads from stored option, falls back to --offset
        $last_stored = get_option( 'f365_last_club_ranking_import', [] );
        $last_id = isset( $last_stored['source_id'] ) && ! $this->force
            ? max( (int) $last_stored['source_id'], (int) $this->offset )
            : (int) $this->offset;

        while ( $handled < $this->limit ) {
            $rows = $this->pg_all(
                'SELECT
                    rk.id, rk.edition, rk.position, rk.points,
                    rk.variation, rk.month, rk.year, rk.coefficient,
                    rk.team_id,
                    t.shortname AS team_name
                FROM ranking_clubranking rk
                LEFT JOIN team_team t ON t.id = rk.team_id
                WHERE rk.id > $1
                ORDER BY rk.id
                LIMIT $2',
                [ $last_id, $this->batch ]
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $r ) {
                if ( $handled >= $this->limit ) {
                    break;
                }

                $last_id = (int) $r['id'];
                $handled++;
                $progress->tick();

                $composite = 'club_' . $r['id'];
                $wp_id     = $this->_ranking_find_existing( $composite, $r['id'], 'club' );

                if ( $wp_id && ! $this->force ) {
                    $skip++;
                    continue;
                }

                $title = sprintf( 'IFFHS Club #%d — %s (%s/%02d)',
                    (int) $r['position'],
                    $r['team_name'] ?? '?',
                    $r['year'] ?? '',
                    (int) ( $r['month'] ?? 0 )
                );

                if ( ! $wp_id ) {
                    $wp_id = $this->insert_post( [
                        'post_type'   => 'f365_ranking',
                        'post_title'  => $title,
                        'post_name'   => $composite,
                        'post_status' => 'publish',
                    ] );
                    if ( ! $wp_id ) {
                        $fail++;
                        $this->log( 'rankings', "FAIL club_{$r['id']} insert_post() returned 0" );
                        continue;
                    }
                } else {
                    global $wpdb;
                    if ( ! $this->dry_run ) {
                        $wpdb->update(
                            $wpdb->posts,
                            [ 'post_title' => $title ],
                            [ 'ID' => $wp_id ]
                        );
                        clean_post_cache( $wp_id );
                    }
                }

                $edition_term_id = 0;
                if ( ! empty( $r['edition'] ) ) {
                    $edition_term_id = $this->ensure_term( trim( (string) $r['edition'] ), 'f365_season' );
                }

                $this->set_meta( $wp_id, [
                    'ranking_type'        => 'club',
                    'edition'             => $edition_term_id ?: '',
                    'position'            => (int) $r['position'],
                    'points'              => (int) ( $r['points']      ?? 0 ),
                    'variation'           => (int) ( $r['variation']   ?? 0 ),
                    'month'               => (int) ( $r['month']       ?? 0 ),
                    'year'                => (int) ( $r['year']        ?? 0 ),
                    'coefficient'         => (int) ( $r['coefficient'] ?? 0 ),
                    'django_ranking_id'   => (int) $r['id'],
                    'django_team_id'      => (int) ( $r['team_id'] ?? 0 ),
                    'django_source_table' => 'ranking_clubranking',
                ] );

                if ( $edition_term_id ) {
                    update_post_meta( $wp_id, '_edition', 'field_rk_edition' );
                    wp_set_object_terms( $wp_id, [ $edition_term_id ], 'f365_season', false );
                }

                // Resolve team CPT association
                if ( ! empty( $r['team_id'] ) ) {
                    $team_wp_id = $this->map_resolve( 'f365_team', (int) $r['team_id'] );
                    if ( $team_wp_id ) {
                        update_post_meta( $wp_id, 'team', $team_wp_id );
                        update_post_meta( $wp_id, '_team', 'field_rk_team' );
                    } else {
                        $this->log( 'rankings', "REL-MISS team django_id={$r['team_id']} ranking_id={$r['id']}" );
                    }
                }

                // f365_ranking_type taxonomy + Yoast written into wpseo_taxonomy_meta option
                $type_term_id = $this->ensure_term( 'Clubes', 'f365_ranking_type', 'clubes' );
                if ( $type_term_id ) {
                    wp_set_object_terms( $wp_id, [ $type_term_id ], 'f365_ranking_type', false );
                    $this->f365_ranking_set_term_yoast(
                        $type_term_id,
                        'f365_ranking_type',
                        'Ranking de Clubes IFFHS - Futebol 365',
                        'O Portal do Futebol Mundial. Tudo sobre os principais campeonatos e competições nacionais e internacionais de clubes e seleções.'
                    );
                }

                $this->map_set( 'f365_ranking', $composite, $wp_id );
                $this->log( 'rankings', "OK club_{$r['id']} → wp_id=$wp_id pos={$r['position']} " . ( $r['team_name'] ?? '?' ) . " (" . ( $r['year'] ?? '' ) . "/" . sprintf( '%02d', (int) ( $r['month'] ?? 0 ) ) . ")" );
                $n++;
            }

            $this->map_save( 'f365_ranking' );
            update_option( 'f365_last_club_ranking_import', [
                'source_id' => $last_id,
                'n'         => $n,
            ], false );
            unset( $rows );
        }

        $progress->finish();
        $this->log( 'rankings', "SUMMARY club: imported=$n skipped=$skip failed=$fail" );
        WP_CLI::success( "Club Rankings: $n processed, $skip skipped, $fail failed." );
        wp_suspend_cache_addition( false );
    }

    protected function import_national_rankings(): void {
        wp_suspend_cache_addition( true );
        $this->map_get( 'f365_ranking' );
        $this->map_get( 'f365_team' );

        $total = (int) $this->pg_val( 'SELECT COUNT(*) FROM ranking_nationalranking' );
        $cap   = min( $total, $this->limit );

        WP_CLI::log( "National Rankings total: $total | cap: $cap | batch: {$this->batch} | start-after-id: {$this->offset}" );
        $progress = WP_CLI\Utils\make_progress_bar( 'National Rankings', $cap );

        $n = $skip = $fail = 0;
        $handled = 0;

        $last_stored = get_option( 'f365_last_national_ranking_import', [] );
        $last_id = isset( $last_stored['source_id'] ) && ! $this->force
            ? max( (int) $last_stored['source_id'], (int) $this->offset )
            : (int) $this->offset;

        while ( $handled < $this->limit ) {
            $rows = $this->pg_all(
                'SELECT
                    rk.id, rk.edition, rk.position, rk.points,
                    rk.variation, rk.month, rk.year,
                    rk.team_id,
                    t.shortname AS team_name
                FROM ranking_nationalranking rk
                LEFT JOIN team_team t ON t.id = rk.team_id
                WHERE rk.id > $1
                ORDER BY rk.id
                LIMIT $2',
                [ $last_id, $this->batch ]
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $r ) {
                if ( $handled >= $this->limit ) {
                    break;
                }

                $last_id = (int) $r['id'];
                $handled++;
                $progress->tick();

                $composite = 'national_' . $r['id'];
                $wp_id     = $this->_ranking_find_existing( $composite, $r['id'], 'national' );

                if ( $wp_id && ! $this->force ) {
                    $skip++;
                    continue;
                }

                $title = sprintf( 'FIFA #%d — %s (%s/%02d)',
                    (int) $r['position'],
                    $r['team_name'] ?? '?',
                    $r['year'] ?? '',
                    (int) ( $r['month'] ?? 0 )
                );

                if ( ! $wp_id ) {
                    $wp_id = $this->insert_post( [
                        'post_type'   => 'f365_ranking',
                        'post_title'  => $title,
                        'post_name'   => $composite,
                        'post_status' => 'publish',
                    ] );
                    if ( ! $wp_id ) {
                        $fail++;
                        $this->log( 'rankings', "FAIL national_{$r['id']} insert_post() returned 0" );
                        continue;
                    }
                } else {
                    global $wpdb;
                    if ( ! $this->dry_run ) {
                        $wpdb->update(
                            $wpdb->posts,
                            [ 'post_title' => $title ],
                            [ 'ID' => $wp_id ]
                        );
                        clean_post_cache( $wp_id );
                    }
                }

                $edition_term_id = 0;
                if ( ! empty( $r['edition'] ) ) {
                    $edition_term_id = $this->ensure_term( trim( (string) $r['edition'] ), 'f365_season' );
                }

                $this->set_meta( $wp_id, [
                    'ranking_type'        => 'national',
                    'edition'             => $edition_term_id ?: '',
                    'position'            => (int) $r['position'],
                    'points'              => (int) ( $r['points']    ?? 0 ),
                    'variation'           => (int) ( $r['variation'] ?? 0 ),
                    'month'               => (int) ( $r['month']     ?? 0 ),
                    'year'                => (int) ( $r['year']      ?? 0 ),
                    'django_ranking_id'   => (int) $r['id'],
                    'django_team_id'      => (int) ( $r['team_id'] ?? 0 ),
                    'django_source_table' => 'ranking_nationalranking',
                ] );

                if ( $edition_term_id ) {
                    update_post_meta( $wp_id, '_edition', 'field_rk_edition' );
                    wp_set_object_terms( $wp_id, [ $edition_term_id ], 'f365_season', false );
                }

                if ( ! empty( $r['team_id'] ) ) {
                    $team_wp_id = $this->map_resolve( 'f365_team', (int) $r['team_id'] );
                    if ( $team_wp_id ) {
                        update_post_meta( $wp_id, 'team', $team_wp_id );
                        update_post_meta( $wp_id, '_team', 'field_rk_team' );
                    } else {
                        $this->log( 'rankings', "REL-MISS team django_id={$r['team_id']} ranking_id={$r['id']}" );
                    }
                }

                $type_term_id = $this->ensure_term( 'Seleções', 'f365_ranking_type', 'seleccoes' );
                if ( $type_term_id ) {
                    wp_set_object_terms( $wp_id, [ $type_term_id ], 'f365_ranking_type', false );
                    $this->f365_ranking_set_term_yoast(
                        $type_term_id,
                        'f365_ranking_type',
                        'Ranking de Seleções FIFA - Futebol 365',
                        'O Portal do Futebol Mundial. Tudo sobre os principais campeonatos e competições nacionais e internacionais de clubes e seleções.'
                    );
                }

                $this->map_set( 'f365_ranking', $composite, $wp_id );
                $this->log( 'rankings', "OK national_{$r['id']} → wp_id=$wp_id pos={$r['position']} " . ( $r['team_name'] ?? '?' ) . " (" . ( $r['year'] ?? '' ) . "/" . sprintf( '%02d', (int) ( $r['month'] ?? 0 ) ) . ")" );
                $n++;
            }

            $this->map_save( 'f365_ranking' );
            update_option( 'f365_last_national_ranking_import', [
                'source_id' => $last_id,
                'n'         => $n,
            ], false );
            unset( $rows );
        }

        $progress->finish();
        $this->log( 'rankings', "SUMMARY national: imported=$n skipped=$skip failed=$fail" );
        WP_CLI::success( "National Rankings: $n processed, $skip skipped, $fail failed." );
        wp_suspend_cache_addition( false );
    }

    protected function import_player_rankings(): void {
        wp_suspend_cache_addition( true );
        $this->map_get( 'f365_ranking' );
        $this->map_get( 'f365_player' );

        $total = (int) $this->pg_val( 'SELECT COUNT(*) FROM ranking_playerranking' );
        $cap   = min( $total, $this->limit );

        WP_CLI::log( "Player Rankings total: $total | cap: $cap | batch: {$this->batch} | start-after-id: {$this->offset}" );
        $progress = WP_CLI\Utils\make_progress_bar( 'Player Rankings', $cap );

        $n = $skip = $fail = 0;
        $handled = 0;

        $last_stored = get_option( 'f365_last_player_ranking_import', [] );
        $last_id = isset( $last_stored['source_id'] ) && ! $this->force
            ? max( (int) $last_stored['source_id'], (int) $this->offset )
            : (int) $this->offset;

        while ( $handled < $this->limit ) {
            $rows = $this->pg_all(
                'SELECT
                    rk.id, rk.edition, rk.position, rk.points,
                    rk.player_id, rk.season, rk.ranking_type_id,
                    pp.shortname AS player_name,
                    rt.shortname AS rt_shortname,
                    rt.longname  AS rt_longname,
                    rt.points_description AS rt_points_desc
                FROM ranking_playerranking rk
                LEFT JOIN person_player pl  ON pl.id  = rk.player_id
                LEFT JOIN person_person pp  ON pp.id  = pl.person_id
                LEFT JOIN ranking_playerrankingtype rt ON rt.id = rk.ranking_type_id
                WHERE rk.id > $1
                ORDER BY rk.id
                LIMIT $2',
                [ $last_id, $this->batch ]
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $r ) {
                if ( $handled >= $this->limit ) {
                    break;
                }

                $last_id = (int) $r['id'];
                $handled++;
                $progress->tick();

                $composite = 'player_' . $r['id'];
                $wp_id     = $this->_ranking_find_existing( $composite, $r['id'], 'player' );

                if ( $wp_id && ! $this->force ) {
                    $skip++;
                    continue;
                }

                $title = sprintf( 'Player #%d %s — %s (%s)',
                    (int) $r['position'],
                    $r['rt_shortname'] ?? '',
                    $r['player_name']  ?? '?',
                    $r['edition']      ?? ''
                );

                if ( ! $wp_id ) {
                    $wp_id = $this->insert_post( [
                        'post_type'   => 'f365_ranking',
                        'post_title'  => $title,
                        'post_name'   => $composite,
                        'post_status' => 'publish',
                    ] );
                    if ( ! $wp_id ) {
                        $fail++;
                        $this->log( 'rankings', "FAIL player_{$r['id']} insert_post() returned 0" );
                        continue;
                    }
                } else {
                    global $wpdb;
                    if ( ! $this->dry_run ) {
                        $wpdb->update(
                            $wpdb->posts,
                            [ 'post_title' => $title ],
                            [ 'ID' => $wp_id ]
                        );
                        clean_post_cache( $wp_id );
                    }
                }

                // edition → f365_season term (year string e.g. "2019")
                $edition_term_id = 0;
                if ( ! empty( $r['edition'] ) ) {
                    $edition_term_id = $this->ensure_term( trim( (string) $r['edition'] ), 'f365_season' );
                }

                // FIX 2: season is a display string (e.g. "2019-2020"), NOT a taxonomy term.
                // Storing it as a term would pollute f365_season with non-year values.
                // It is stored as plain postmeta only for display purposes.
                $season_display = trim( (string) ( $r['season'] ?? '' ) );

                $this->set_meta( $wp_id, [
                    'ranking_type'           => 'player',
                    'edition'                => $edition_term_id ?: '',
                    'position'               => (int) $r['position'],
                    'points'                 => (int) ( $r['points'] ?? 0 ),
                    'season'                 => $season_display,  // plain meta, not a term
                    // subtype shortname/longname/points_description stored as
                    // term meta on f365_ranking_subtype — not duplicated here
                    'django_ranking_id'      => (int) $r['id'],
                    'django_player_id'       => (int) ( $r['player_id']       ?? 0 ),
                    'django_ranking_type_id' => (int) ( $r['ranking_type_id'] ?? 0 ),
                    'django_source_table'    => 'ranking_playerranking',
                ] );

                if ( $edition_term_id ) {
                    update_post_meta( $wp_id, '_edition', 'field_rk_edition' );
                    wp_set_object_terms( $wp_id, [ $edition_term_id ], 'f365_season', false );
                }

                if ( $season_display !== '' ) {
                    update_post_meta( $wp_id, '_season', 'field_rk_season' );
                }

                // FIX 3: write ACF field-key reference for django_player_id
                // so get_field('django_player_id') works correctly in admin/templates
                update_post_meta( $wp_id, '_django_player_id', 'field_rk_django_player_id' );

                // Resolve player CPT association
                if ( ! empty( $r['player_id'] ) ) {
                    $player_wp_id = $this->map_resolve( 'f365_player', (int) $r['player_id'] );
                    if ( $player_wp_id ) {
                        update_post_meta( $wp_id, 'player', $player_wp_id );
                        update_post_meta( $wp_id, '_player', 'field_rk_player' );
                    } else {
                        $this->log( 'rankings', "REL-MISS player django_id={$r['player_id']} ranking_id={$r['id']}" );
                    }
                }

                // FIX 4: _ensure_ranking_subtype_term — look up by slug first (Django ID),
                // then fall back to name. This prevents silent collision when two
                // PlayerRankingType rows share the same longname.
                if ( ! empty( $r['rt_longname'] ) ) {
                    $subtype_term_id = $this->_ensure_ranking_subtype_term(
                        $r['rt_longname'],
                        $r['rt_shortname']  ?? '',
                        $r['rt_points_desc'] ?? '',
                        (int) ( $r['ranking_type_id'] ?? 0 )
                    );
                    if ( $subtype_term_id ) {
                        wp_set_object_terms( $wp_id, [ $subtype_term_id ], 'f365_ranking_subtype', false );
                        update_post_meta( $wp_id, 'ranking_subtype', $subtype_term_id );
                        update_post_meta( $wp_id, '_ranking_subtype', 'field_rk_ranking_subtype' );
                    }
                }

                $type_term_id = $this->ensure_term( 'Jogadores', 'f365_ranking_type', 'jogadores' );
                if ( $type_term_id ) {
                    wp_set_object_terms( $wp_id, [ $type_term_id ], 'f365_ranking_type', false );
                }

                $this->map_set( 'f365_ranking', $composite, $wp_id );
                $this->log( 'rankings', "OK player_{$r['id']} → wp_id=$wp_id pos={$r['position']} " . ( $r['rt_shortname'] ?? '' ) . " " . ( $r['player_name'] ?? '?' ) . " (" . ( $r['edition'] ?? '' ) . ")" );
                $n++;
            }

            $this->map_save( 'f365_ranking' );
            update_option( 'f365_last_player_ranking_import', [
                'source_id' => $last_id,
                'n'         => $n,
            ], false );
            unset( $rows );
        }

        $progress->finish();
        $this->log( 'rankings', "SUMMARY player: imported=$n skipped=$skip failed=$fail" );
        WP_CLI::success( "Player Rankings: $n processed, $skip skipped, $fail failed." );
        wp_suspend_cache_addition( false );
    }

    /**
     * f365_ranking_set_term_yoast()
     *
     * Write Yoast SEO title and description for a taxonomy term.
     *
     * Yoast stores ALL taxonomy term SEO data in a single WordPress option
     * ('wpseo_taxonomy_meta') — NOT in wp_termmeta. Writing to wp_termmeta
     * with update_term_meta() has no effect on Yoast output.
     *
     * Option structure:
     *   [
     *     'f365_ranking_type' => [
     *       term_id => [ 'wpseo_title' => '...', 'wpseo_desc' => '...' ],
     *     ],
     *     'f365_ranking_subtype' => [ ... ],
     *   ]
     *
     * Note: Yoast uses 'wpseo_title' and 'wpseo_desc' (no underscore prefix)
     * for taxonomy terms. The underscore-prefixed keys (_yoast_wpseo_title,
     * _yoast_wpseo_metadesc) are for individual post postmeta only.
     *
     * @param int    $term_id   WP term ID.
     * @param string $taxonomy  Taxonomy slug e.g. 'f365_ranking_type'.
     * @param string $title     Yoast SEO title.
     * @param string $desc      Yoast meta description.
     */
    private function f365_ranking_set_term_yoast(
        int    $term_id,
        string $taxonomy,
        string $title,
        string $desc
    ): void {
        if ( $this->dry_run ) {
            return;
        }

        $all = get_option( 'wpseo_taxonomy_meta', [] );
        if ( ! is_array( $all ) ) {
            $all = [];
        }

        if ( ! isset( $all[ $taxonomy ] ) ) {
            $all[ $taxonomy ] = [];
        }

        $all[ $taxonomy ][ $term_id ] = [
            'wpseo_title' => $title,
            'wpseo_desc'  => $desc,
        ];

        update_option( 'wpseo_taxonomy_meta', $all );
    }

    private array $_ranking_subtype_term_cache = [];

    /**
     * Ensure an f365_ranking_subtype term exists for a PlayerRankingType row.
     *
     * FIX 4: Look up by slug (the Django ID) FIRST to prevent silent collision
     * when two PlayerRankingType rows share the same longname. The slug is the
     * canonical identifier because it maps directly to the Django URL:
     * /rank-jogadores/{django_ranking_type_id}/
     *
     * Lookup order:
     *   1. In-memory $_ranking_subtype_term_cache (key = Django ID)
     *   2. get_term_by('slug', $django_id)   ← find the exact term
     *   3. get_term_by('name', $longname)    ← fallback for legacy data
     *   4. wp_insert_term()                  ← create if not found
     */
    private function _ensure_ranking_subtype_term(
        string $longname,
        string $shortname,
        string $points_description,
        int    $django_ranking_type_id
    ): int {
        $longname = trim( $longname );
        if ( $longname === '' || $django_ranking_type_id <= 0 ) {
            return 0;
        }

        // 1. In-memory cache keyed by Django ID (not name — avoids collision)
        $cache_key = 'id_' . $django_ranking_type_id;
        if ( isset( $this->_ranking_subtype_term_cache[ $cache_key ] ) ) {
            return $this->_ranking_subtype_term_cache[ $cache_key ];
        }

        // 2. Look up by slug = Django ID (the canonical identifier)
        $term = get_term_by( 'slug', (string) $django_ranking_type_id, 'f365_ranking_subtype' );

        // 3. Fall back to name only when slug lookup fails (legacy imports without slug set)
        if ( ! $term ) {
            $term = get_term_by( 'name', $longname, 'f365_ranking_subtype' );
        }

        if ( $term ) {
            $term_id = (int) $term->term_id;

            // Ensure slug is set to Django ID (repair any legacy term without it)
            if ( (string) $term->slug !== (string) $django_ranking_type_id ) {
                wp_update_term( $term_id, 'f365_ranking_subtype', [
                    'slug' => (string) $django_ranking_type_id,
                ] );
            }
        } else {
            // 4. Create new term with slug = Django ID
            $res = wp_insert_term( $longname, 'f365_ranking_subtype', [
                'slug' => (string) $django_ranking_type_id,
            ] );
            if ( is_wp_error( $res ) ) {
                $this->log( 'rankings', "SUBTYPE-FAIL longname=$longname django_id=$django_ranking_type_id err=" . $res->get_error_message() );
                return 0;
            }
            $term_id = (int) $res['term_id'];
        }

        // Write term meta (shortname, points_description, Yoast)
        update_term_meta( $term_id, 'shortname',              $shortname );
        update_term_meta( $term_id, 'points_description',     $points_description );
        update_term_meta( $term_id, 'django_ranking_type_id', $django_ranking_type_id );
        // Write Yoast SEO into wpseo_taxonomy_meta option (correct storage for taxonomy terms)
        $this->f365_ranking_set_term_yoast(
            $term_id,
            'f365_ranking_subtype',
            $shortname . ' - Ranking de Jogadores - Futebol 365',
            'O Portal do Futebol Mundial. Tudo sobre os principais campeonatos e competições nacionais e internacionais de clubes e seleções.'
        );

        $this->_ranking_subtype_term_cache[ $cache_key ] = $term_id;
        return $term_id;
    }

    private function _ranking_find_existing(
        string $composite,
        int    $django_id,
        string $type
    ): int {
        global $wpdb;

        // Step 1: RAM map
        $wp_id = $this->map_resolve( 'f365_ranking', $composite );
        if ( $wp_id ) {
            return $wp_id;
        }

        // Step 2: postmeta check with type guard (prevents cross-type collisions)
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT pm.post_id
               FROM {$wpdb->postmeta} pm
               JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key   = 'django_ranking_id'
                AND pm.meta_value = %d
                AND p.post_type   = 'f365_ranking'
                AND p.post_status != 'trash'
                AND EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} pm2
                    WHERE pm2.post_id   = pm.post_id
                      AND pm2.meta_key  = 'ranking_type'
                      AND pm2.meta_value = %s
                )
              LIMIT 1",
            $django_id,
            $type
        ) );

        if ( $found ) {
            $this->map_set( 'f365_ranking', $composite, (int) $found );
            return (int) $found;
        }

        // Step 3: post_name check
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
              WHERE post_name   = %s
                AND post_type   = 'f365_ranking'
                AND post_status != 'auto-draft'
              LIMIT 1",
            $composite
        ) );

        if ( $found ) {
            $this->map_set( 'f365_ranking', $composite, (int) $found );
            return (int) $found;
        }

        return 0;
    }
}