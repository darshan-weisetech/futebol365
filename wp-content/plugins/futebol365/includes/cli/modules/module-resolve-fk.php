<?php
/**
 * Migration module: Foreign Key Resolution.
 *
 * Second-pass resolution: replaces all `django_*_id` postmeta with
 * resolved WordPress post IDs using the migration maps built during
 * the first pass.
 *
 * Covers all CPTs — both original and new modules.
 *
 * @package Futebol365
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    return;
}

trait F365_Module_ResolveFk {

    protected function resolve_fk(): void {
        $this->log( 'resolve_fk', 'START resolve_fk pass' );
        WP_CLI::log( 'Loading migration maps…' );

        $team_map      = $this->map_get( 'f365_team' );
        $fed_map       = $this->map_get( 'f365_federation' );
        $stadium_map   = $this->map_get( 'f365_stadium' );
        $comp_map      = $this->map_get( 'f365_competition' );
        $match_map     = $this->map_get( 'f365_match' );
        $player_map    = $this->map_get( 'f365_player' );
        $coach_map     = $this->map_get( 'f365_coach' );
        $president_map = $this->map_get( 'f365_president' );
        $referee_map   = $this->map_get( 'f365_referee' );
        $video_map     = $this->map_get( 'f365_video' );
        $gallery_map   = $this->map_get( 'f365_gallery' );
        $fed_comp_map  = $this->map_get( 'f365_fed_comp' );
        $pub_map       = $this->map_get( 'f365_publisher' );
        $author_map    = $this->map_get( 'f365_author' );

        // ── Teams ────────────────────────────────────────────────────────────
        WP_CLI::log( '  Teams…' );
        $this->log( 'resolve_fk', 'Teams…' );
        foreach ( $this->get_all_posts_of_type( 'f365_team' ) as $wp_id ) {
            $this->resolve_single( $wp_id, 'federation',  'django_federation_id', $fed_map );
            $this->resolve_single( $wp_id, 'stadium',     'django_stadium_id',    $stadium_map );

            // Squad repeater: replace django_player_id with WP post ID
            $squad_raw = get_post_meta( $wp_id, 'squad_raw', true );
            if ( $squad_raw && is_array( $squad_raw ) ) {
                $resolved = [];
                foreach ( $squad_raw as $sq ) {
                    $player_wp = $player_map[ (string)(int) ( $sq['django_player_id'] ?? 0 ) ] ?? null;
                    unset( $sq['django_player_id'] );
                    if ( $player_wp ) $sq['player'] = (int) $player_wp;
                    $resolved[] = $sq;
                }
                if ( ! $this->dry_run ) {
                    update_post_meta( $wp_id, 'squad', $resolved );
                    delete_post_meta( $wp_id, 'squad_raw' );
                }
            }

            // TeamStep repeater: resolve stadium FKs within repeater rows
            $steps = get_post_meta( $wp_id, 'team_steps', true );
            if ( $steps && is_array( $steps ) ) {
                foreach ( $steps as &$ts ) {
                    $stad_wp = $stadium_map[ (string)(int) ( $ts['django_stadium_id'] ?? 0 ) ] ?? null;
                    if ( $stad_wp ) $ts['stadium'] = (int) $stad_wp;
                }
                unset( $ts );
                if ( ! $this->dry_run ) update_post_meta( $wp_id, 'team_steps', $steps );
            }
        }

        // ── Fed competitions ─────────────────────────────────────────────────
        WP_CLI::log( '  Fed competitions…' );
        $this->log( 'resolve_fk', 'Fed competitions…' );
        foreach ( $this->get_all_posts_of_type( 'f365_fed_comp' ) as $wp_id ) {
            $this->resolve_single( $wp_id, 'federation', 'django_federation_id', $fed_map );
        }

        // ── Competitions ─────────────────────────────────────────────────────
        WP_CLI::log( '  Competitions…' );
        $this->log( 'resolve_fk', 'Competitions…' );
        foreach ( $this->get_all_posts_of_type( 'f365_competition' ) as $wp_id ) {
            $this->resolve_single( $wp_id, 'federation_competition', 'django_fed_comp_id', $fed_comp_map );
            // Participants repeater
            $parts = get_post_meta( $wp_id, 'participants', true );
            if ( $parts && is_array( $parts ) ) {
                foreach ( $parts as &$par ) {
                    $team_wp = $team_map[ (string)(int) ( $par['django_team_id'] ?? 0 ) ] ?? null;
                    if ( $team_wp ) $par['team'] = (int) $team_wp;
                }
                unset( $par );
                if ( ! $this->dry_run ) update_post_meta( $wp_id, 'participants', $parts );
            }
        }

        // ── Matches ──────────────────────────────────────────────────────────
        WP_CLI::log( '  Matches…' );
        $this->log( 'resolve_fk', 'Matches…' );
        $progress = WP_CLI\Utils\make_progress_bar( 'FK Matches', count( $match_map ) );
        foreach ( array_values( $match_map ) as $wp_id ) {
            $progress->tick();
            $this->resolve_single( $wp_id, 'competition',  'django_competition_id',  $comp_map );
            $this->resolve_single( $wp_id, 'home_team',    'django_home_team_id',    $team_map );
            $this->resolve_single( $wp_id, 'visitor_team', 'django_visitor_team_id', $team_map );
            $this->resolve_single( $wp_id, 'stadium',      'django_stadium_id',      $stadium_map );
        }
        $progress->finish();

        // ── Transfers ────────────────────────────────────────────────────────
        WP_CLI::log( '  Transfers…' );
        $this->log( 'resolve_fk', 'Transfers…' );
        foreach ( $this->get_all_posts_of_type( 'f365_transfer' ) as $wp_id ) {
            $this->resolve_single( $wp_id, 'player',           'django_player_id',      $player_map );
            $this->resolve_single( $wp_id, 'team_origin',      'django_origin_team_id', $team_map );
            $this->resolve_single( $wp_id, 'team_destination', 'django_dest_team_id',   $team_map );
        }

        // ── Referees ─────────────────────────────────────────────────────────
        WP_CLI::log( '  Referees…' );
        $this->log( 'resolve_fk', 'Referees…' );
        foreach ( $this->get_all_posts_of_type( 'f365_referee' ) as $wp_id ) {
            $this->resolve_single( $wp_id, 'association', 'django_association_id', $fed_map );
        }

        // ── Coaches ──────────────────────────────────────────────────────────
        WP_CLI::log( '  Coaches…' );
        $this->log( 'resolve_fk', 'Coaches…' );
        foreach ( $this->get_all_posts_of_type( 'f365_coach' ) as $wp_id ) {
            $this->resolve_array( $wp_id, 'competitions', 'django_competition_ids', $comp_map );
        }

        // ── Videos ───────────────────────────────────────────────────────────

        WP_CLI::log( '  Videos…' );
        $this->log( 'resolve_fk', 'Videos…' );
        foreach ( $this->get_all_posts_of_type( 'f365_video' ) as $wp_id ) {
            $this->resolve_single( $wp_id, 'competition', 'django_competition_id', $comp_map );
            $this->resolve_single( $wp_id, 'match',       'django_match_id',       $match_map );
            $this->resolve_array( $wp_id, 'teams',      'django_team_ids',      $team_map );
            $this->resolve_array( $wp_id, 'players',    'django_player_ids',    $player_map );
            $this->resolve_array( $wp_id, 'coaches',    'django_coach_ids',     $coach_map );
            $this->resolve_array( $wp_id, 'presidents', 'django_president_ids', $president_map );
            $this->resolve_array( $wp_id, 'referees',   'django_referee_ids',   $referee_map );
        }

        // ── Galleries ────────────────────────────────────────────────────────
        WP_CLI::log( '  Galleries…' );
        $this->log( 'resolve_fk', 'Galleries…' );
        foreach ( $this->get_all_posts_of_type( 'f365_gallery' ) as $wp_id ) {
            $this->resolve_single( $wp_id, 'competition', 'django_competition_id', $comp_map );
            $this->resolve_single( $wp_id, 'match',       'django_match_id',       $match_map );
            $this->resolve_array( $wp_id, 'teams',      'django_team_ids',      $team_map );
            $this->resolve_array( $wp_id, 'players',    'django_player_ids',    $player_map );
            $this->resolve_array( $wp_id, 'coaches',    'django_coach_ids',     $coach_map );
            $this->resolve_array( $wp_id, 'presidents', 'django_president_ids', $president_map );
            $this->resolve_array( $wp_id, 'referees',   'django_referee_ids',   $referee_map );
        }

        // ── News (WP posts) ──────────────────────────────────────────────────
        WP_CLI::log( '  News…' );
        $this->log( 'resolve_fk', 'News…' );
        $news_map = $this->map_get( 'f365_news' );
        $progress = WP_CLI\Utils\make_progress_bar( 'FK News', count( $news_map ) );
        foreach ( array_values( $news_map ) as $wp_id ) {
            $progress->tick();
            $this->resolve_single( $wp_id, 'competition',  'django_competition_id',  $comp_map );
            $this->resolve_single( $wp_id, 'match',        'django_match_id',        $match_map );
            $this->resolve_single( $wp_id, 'photogallery', 'django_photogallery_id', $gallery_map );
            $this->resolve_array( $wp_id, 'teams',      'django_team_ids',      $team_map );
            $this->resolve_array( $wp_id, 'players',    'django_player_ids',    $player_map );
            $this->resolve_array( $wp_id, 'coaches',    'django_coach_ids',     $coach_map );
            $this->resolve_array( $wp_id, 'presidents', 'django_president_ids', $president_map );
            $this->resolve_array( $wp_id, 'referees',   'django_referee_ids',   $referee_map );
            $this->resolve_single( $wp_id, 'video_embed',  'django_video_embed_id',  $video_map );
            $this->resolve_array( $wp_id, 'videos',        'django_video_ids',       $video_map );

            // Assign post_author from source → WP user
            $src_id = (int) get_post_meta( $wp_id, 'django_news_source_id', true );
            if ( $src_id ) {
                $user_id = $author_map[ (string) $src_id ] ?? null;
                if ( $user_id && ! $this->dry_run ) {
                    wp_update_post( [ 'ID' => (int) $wp_id, 'post_author' => (int) $user_id ] );
                }
            }
        }
        $progress->finish();

        // ── Newsstand ────────────────────────────────────────────────────────
        WP_CLI::log( '  Newsstand…' );
        $this->log( 'resolve_fk', 'Newsstand…' );
        foreach ( $this->get_all_posts_of_type( 'f365_newsstand' ) as $wp_id ) {
            $this->resolve_single( $wp_id, 'publisher', 'django_publisher_id', $pub_map );
        }

        // ── Live streaming ───────────────────────────────────────────────────
        WP_CLI::log( '  Live streaming…' );
        $this->log( 'resolve_fk', 'Live streaming…' );
        foreach ( $this->get_all_posts_of_type( 'f365_livestream' ) as $wp_id ) {
            $this->resolve_single( $wp_id, 'match',       'django_match_id',       $match_map );
            $this->resolve_single( $wp_id, 'competition', 'django_competition_id', $comp_map );
        }

        // ── Rankings ─────────────────────────────────────────────────────────
        WP_CLI::log( '  Rankings…' );
        $this->log( 'resolve_fk', 'Rankings…' );
        foreach ( $this->get_all_posts_of_type( 'f365_ranking' ) as $wp_id ) {
            $rtype = get_post_meta( $wp_id, 'ranking_type', true );
            if ( $rtype === 'player' ) {
                $this->resolve_single( $wp_id, 'player', 'django_player_id', $player_map );
            } else {
                $this->resolve_single( $wp_id, 'team', 'django_team_id', $team_map );
            }
        }

        $this->log( 'resolve_fk', 'END resolve_fk pass complete' );
        WP_CLI::success( 'FK resolution complete.' );
    }
}
