<?php
/**
 * Futebol365 — WP-CLI Migration Command (concrete class + registration)
 *
 * This is the single file that calls WP_CLI::add_command(). All migration
 * logic lives in the module traits loaded below. The base class holds all
 * shared helpers. Nothing else in the plugin calls WP_CLI::add_command().
 *
 * Command:  wp futebol365 migrate
 * Alias:    wp f365 migrate  (registered as a second command, not re-using the same handle)
 *
 * Usage examples:
 *   wp futebol365 migrate all
 *   wp futebol365 migrate countries
 *   wp futebol365 migrate matches --batch=1000 --offset=5000
 *   wp futebol365 migrate polls --limit=50 --dry-run
 *   wp futebol365 migrate rankings --force
 *   wp futebol365 migrate resolve_fk
 *   wp futebol365 migrate status
 *   wp futebol365 migrate reset
 *   wp futebol365 migrate rollback --module=polls
 *
 * @package Futebol365
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    return;
}

// ── Load base (must come first) ──────────────────────────────────────────────
require_once __DIR__ . '/class-f365-db-connection.php';
require_once __DIR__ . '/class-f365-migrate-base.php';

// ── Load modules (order only matters for readability) ────────────────────────
require_once __DIR__ . '/modules/module-countries.php';
require_once __DIR__ . '/modules/module-stadiums-federations.php';
require_once __DIR__ . '/modules/module-teams.php';
require_once __DIR__ . '/modules/modules-transfers.php';
require_once __DIR__ . '/modules/module-videos.php';
require_once __DIR__ . '/modules/module-galleries.php';
require_once __DIR__ . '/modules/module-news.php';
require_once __DIR__ . '/modules/module-polls.php';
require_once __DIR__ . '/modules/module-newsstand.php';
require_once __DIR__ . '/modules/module-live-streaming.php';
require_once __DIR__ . '/modules/module-rankings.php';
require_once __DIR__ . '/modules/module-authors.php';
require_once __DIR__ . '/modules/module-resolve-fk.php';
require_once __DIR__ . '/modules/module-download-images.php';
require_once __DIR__ . '/modules/module-sondagem.php';
// require_once __DIR__ . '/modules/module-competitions-matches.php';
// require_once __DIR__ . '/modules/module-live-games.php';
require_once __DIR__ . '/modules/module-persons.php';
require_once __DIR__ . '/modules/module-news-csv.php';

/**
 * Futebol365 PostgreSQL → WordPress migration.
 *
 * Composes the abstract base and all module traits into one concrete class.
 * All methods are protected/private — WP-CLI calls __invoke().
 */
class F365_Migrate_Command extends F365_Migrate_Base {

    use F365_Module_Util;
    use F365_Module_Countries;
    use F365_Module_Stadiums;
    use F365_Module_Federations;
    use F365_Module_FedCompetitions;
    use F365_Module_Teams;
    use F365_Module_TeamModule;
    //use F365_Module_CM_Helpers;
    use F365_Module_Transfers;
    use F365_Module_Videos;
    use F365_Module_Galleries;
    use F365_Module_News;
    use F365_Module_Authors;
    use F365_Module_Newsstand;
    use F365_Module_Polls;
    use F365_Module_Sondagem;
    use F365_Module_LiveStreaming;
    use F365_Module_Rankings;
    use F365_Module_ResolveFk;
    use F365_Module_Download_Images;
    use F365_Module_Download_Video_Images;
    use F365_Module_Download_Player_Images;
    use F365_Module_Download_Coach_Images;
    use F365_Module_Download_President_Images;
    use F365_Module_Sync_News_Categories;
    use F365_Module_Sync_News_Tags;
    // use F365_Module_LiveGames;
    // use F365_Module_Matches;
    //use F365_Module_Competitions;
    use F365_Module_Persons;
    use F365_Module_PersonLinks;
    use F365_Module_News_CSV;

    // ── Step registry ────────────────────────────────────────────────────────

    /** Return all available migration steps in dependency order. */
    private function get_steps(): array {
        return [
            'countries'        => [ $this, 'import_countries' ],
            'stadiums'         => [ $this, 'import_stadiums' ],
            'federations'      => [ $this, 'import_federations' ],
            'fed_competitions' => [ $this, 'import_fed_competitions' ],
            'teams'            => [ $this, 'import_teams' ],
            'teamsteps'       => [ $this, 'import_teamsteps' ],
            'squad'           => [ $this, 'import_squad' ],
            'team_presidents' => [ $this, 'import_team_presidents' ],
            'team_module'     => [ $this, 'import_team_module' ],  
            'transfers'        => [ $this, 'import_transfers' ],
            'videos'           => [ $this, 'import_videos' ],
            'galleries'        => [ $this, 'import_galleries' ],
            'news'             => [ $this, 'import_news' ],
            'news_csv'         => [ $this, 'import_news_csv' ],
            // New modules
            'authors'          => [ $this, 'import_authors' ],
            'publishers'       => [ $this, 'import_publishers' ],
            'newsstand'        => [ $this, 'import_newsstand' ],
            'polls'            => [ $this, 'import_polls' ],
            'sondagens'        => [ $this, 'sync_sondagens' ],
            'livestreaming'    => [ $this, 'import_live_streaming' ],
            'rankings'         => [ $this, 'import_rankings' ],
            'rankings_club'     => [ $this, 'import_club_rankings' ],
            'rankings_national' => [ $this, 'import_national_rankings' ],
            'rankings_player'   => [ $this, 'import_player_rankings' ],
            // Resolution
            'resolve_fk'       => [ $this, 'resolve_fk' ],
            'standings'        => [ $this, 'compute_standings' ],  
            'download_images'  => [ $this, 'download_news_images_standalone' ],
            'download_video_images'  => [ $this, 'download_video_images_standalone' ],
            'download_player_images'  => [ $this, 'download_player_images' ],
            'download_coach_images'  => [ $this, 'download_coach_images' ],
            'download_president_images'  => [ $this, 'download_president_images' ],
            'sync_news_categories'  => [ $this, 'sync_news_categories' ],
            'sync_news_tags'  => [ $this, 'sync_news_tags' ],
            'players'          => [ $this, 'import_players' ],
            'coaches'          => [ $this, 'import_coaches' ],
            'presidents'       => [ $this, 'import_presidents' ],
            'referees'         => [ $this, 'import_referees' ],
            'person_links'     => [ $this, 'resolve_person_links' ],
            // 'competitions'     => [ $this, 'import_competitions' ],
            // 'matches'          => [ $this, 'import_matches' ],
            'match_details'    => [ $this, 'import_match_details' ], 
            //'livegames'        => [ $this, 'import_live_games' ],
        ];
    }

    // ════════════════════════════════════════════════════════════════════════
    // ENTRY POINT
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Migrate data from PostgreSQL into WordPress.
     *
     * ## OPTIONS
     *
     * <step>
     * : Migration step. Use 'all' to run every step in order, 'all_new' for
     *   only the new modules, or one of: countries, stadiums, federations,
     *   fed_competitions, players, coaches, presidents, referees, person_links, teams,
     *   competitions, matches, transfers, videos, galleries, news,
     *   authors, publishers, newsstand, polls, livestreaming, rankings,
     *   resolve_fk, status, reset
     *
     * [--pg-host=<host>]
     * : PostgreSQL host. Default: localhost
     *
     * [--pg-port=<port>]
     * : PostgreSQL port. Default: 5432
     *
     * [--pg-name=<dbname>]
     * : PostgreSQL database name. Default: futebol365
     *
     * [--pg-user=<user>]
     * : PostgreSQL user. Default: postgres
     *
     * [--pg-pass=<password>]
     * : PostgreSQL password. Default: (empty)
     *
     * [--batch=<n>]
     * : Records per batch. Default: 500
     *
     * [--offset=<n>]
     * : Start offset for resuming. Default: 0
     *
     * [--after-id=<n>]
     * : Keyset starting ID (exclusive). Default: 0
     *
     * [--max-id=<n>]
     * : Keyset ending ID (inclusive). Default: 0
     *
     * [--limit=<n>]
     * : Maximum records to import (useful for testing).
     *
     * [--dry-run]
     * : Show what would be imported without writing anything.
     *
     * [--force]
     * : Re-import records even if already imported.
     *
     * [--module=<module>]
     * : Used with the 'rollback' step to specify which module to roll back.
     *
     * [--relations]
     * : Resolve relations only for the videos, news, or coaches modules.
     * 
     * [--dirty]
     * : For the 'standings' step, only recompute competitions flagged during
     *   the last 'matches' import (and clear the flag). Without it, standings
     *   are recomputed for every competition.
     *
     * [--seo]
     * : Update Yoast SEO description for players (slow). If omitted, SEO updates are skipped for speed.
     *
     * [--file=<file>]
     * : CSV file path relative to plugin root for the news_csv step.
     * [--yes]
     * : Answer yes to all confirmation prompts.
     *
     * ## EXAMPLES
     *
     *   wp futebol365 migrate all --pg-name=futebol365 --pg-pass=secret
     *   wp futebol365 migrate countries
     *   wp futebol365 migrate matches --batch=1000 --offset=5000
     *   wp futebol365 migrate standings --dirty
     *   wp futebol365 migrate polls --limit=50 --dry-run
     *   wp futebol365 migrate all_new --force
     *   wp futebol365 migrate resolve_fk
     *   wp futebol365 migrate status
     *   wp futebol365 migrate rollback --module=polls
     *
     * @when after_wp_load
     */
    public function __invoke( array $args, array $assoc ): void {

        $step = $args[0] ?? 'status';

        // ── Parse CLI flags into object properties ─────────────────────────
        $this->parse_assoc( $assoc );

        // ── Commands that don't need a PG connection ───────────────────────
        if ( $step === 'status' ) {
            $this->cmd_status();
            return;
        }

        if ( $step === 'reset' ) {
            $this->cmd_reset();
            return;
        }

        if ( $step === 'rollback' ) {
            $module = $assoc['module'] ?? '';
            if ( ! $module ) {
                WP_CLI::error( 'rollback requires --module=<name>. E.g. --module=polls' );
            }
            $this->cmd_rollback( $module );
            return;
        }

        // ── Connect to PostgreSQL ──────────────────────────────────────────
        $need_pg = ( strpos( $step, 'download_' ) !== 0 && strpos( $step, 'sync_' ) !== 0 );
        if ( $need_pg ) {
            $this->pg_connect();
        }

        // ── Dispatch step ──────────────────────────────────────────────────
        $steps = $this->get_steps();

        if ( $step === 'all' ) {
            foreach ( $steps as $name => $fn ) {
                WP_CLI::log( '' );
                WP_CLI::log( "━━━ $name ━━━" );
                call_user_func( $fn );
            }
        } elseif ( $step === 'all_new' ) {
            $new_only = [
                'authors', 'publishers', 'newsstand', 'polls', 'livestreaming', 'rankings', 'resolve_fk',
            ];
            foreach ( $new_only as $name ) {
                WP_CLI::log( '' );
                WP_CLI::log( "━━━ $name ━━━" );
                call_user_func( $steps[ $name ] );
            }
        } elseif ( isset( $steps[ $step ] ) ) {
            call_user_func( $steps[ $step ] );
        } else {
            if ( $need_pg ) {
                $this->pg_disconnect();
            }
            $this->close_logs();
            $valid = implode( ', ', array_keys( $steps ) );
            WP_CLI::error( "Unknown step: '$step'. Valid steps: $valid" );
        }

        if ( $need_pg ) {
            $this->pg_disconnect();
        }
        $this->close_logs();
        WP_CLI::success( 'Done.' );
    }
}

// ── Register the command ONCE ────────────────────────────────────────────────
// 'wp futebol365 migrate' is the canonical command.
// 'wp f365 migrate' is a separate alias command registered independently
// (both point to the same class; WP-CLI allows this without any
// has_command() check — it simply registers two separate commands).

WP_CLI::add_command( 'futebol365 migrate', 'F365_Migrate_Command' );
WP_CLI::add_command( 'f365 migrate',       'F365_Migrate_Command' );
