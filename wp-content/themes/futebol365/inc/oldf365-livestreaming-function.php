<?php
/**
 * Live Streaming — SQL helper for  /live-streaming/{django_livestream_id}/
 *
 * Mirrors Django:
 *   live.urls  →  live-streaming/(?P<obj_id>\d+)/  →  common_views.read_cached_obj
 *   model      →  live.LiveStreaming  (table: {prefix}live_livestreaming)
 *
 * LiveStreaming only stores:  id, url, datetime, match_id.
 * Everything shown on the page (title, teams, scores, competition, stadium) is
 * reached THROUGH match_id → competition_match and its related tables — exactly
 * the way LiveStreaming.get_livestreaming_title() / get_related_links() do.
 *
 * Depends on the shared helpers in f365-livegame-function.php
 * (f365_livegame_cached, f365_livegame_team_name, f365_livegame_team_logo,
 *  f365_team_id_by_title, f365_livegame_status, F365_LIVEGAME_CACHE_TTL).
 *
 * @package Futebol365
 */

if (!function_exists('f365_sql_get_livestreaming_details')) {

    /**
     * Fetch one live-streaming row joined with its match / competition / teams /
     * stadium, plus the live-match row (if any) for live scores & status.
     *
     * @param int $ls_id  live_livestreaming.id  (the {id} in the URL)
     * @return object|null  One row, or null when the id is unknown.
     */
    function f365_sql_get_livestreaming_details($ls_id) {
        global $wpdb;
        $ls_id = (int) $ls_id;
        if (!$ls_id) {
            return null;
        }

        $ttl = defined('F365_LIVEGAME_CACHE_TTL') ? F365_LIVEGAME_CACHE_TTL : 60;

        $fetch = function () use ($wpdb, $ls_id) {
            $sql = "SELECT
                ls.id              AS livestream_id,
                ls.url             AS stream_url,
                ls.datetime        AS stream_datetime,

                cm.id              AS match_id,
                cm.date_time       AS match_datetime,
                cm.match_round,
                cm.phase,
                cm.situation,
                cm.home_score      AS match_home_score,
                cm.visitor_score   AS match_visitor_score,
                cm.home_score_extra,
                cm.visitor_score_extra,
                cm.penalty_home_score,
                cm.penalty_visitor_score,
                cm.stadium_id      AS match_stadium_id,

                cc.id              AS competition_id,
                cc.name            AS competition_name,
                cc.season          AS competition_season,
                cc.edition,

                cp.id              AS phase_id,
                cp.name            AS phase_name,

                htm.id             AS home_team_id,
                htm.shortname      AS home_team_shortname,
                htm.longname       AS home_team_longname,

                vtm.id             AS visitor_team_id,
                vtm.shortname      AS visitor_team_shortname,
                vtm.longname       AS visitor_team_longname,

                ss.id              AS stadium_id,
                ss.longname        AS stadium_longname,
                ss.shortname       AS stadium_name,
                ss.city            AS stadium_city,
                ss.capacity        AS stadium_capacity,

                lm.id              AS livematch_id,
                lm.status          AS live_status,
                lm.home_score      AS live_home_score,
                lm.visitor_score   AS live_visitor_score,
                lm.goal            AS live_goal

            FROM {$wpdb->prefix}live_livestreaming             AS ls
            LEFT JOIN {$wpdb->prefix}competition_match            AS cm  ON cm.id  = ls.match_id
            LEFT JOIN {$wpdb->prefix}competition_competition      AS cc  ON cc.id  = cm.competition_id
            LEFT JOIN {$wpdb->prefix}competition_competitionphase AS cp  ON cp.id  = cm.competition_phase_id
            LEFT JOIN {$wpdb->prefix}team_teamstep                AS ht  ON ht.id  = cm.home_team_id
            LEFT JOIN {$wpdb->prefix}team_team                    AS htm ON htm.id = ht.team_id
            LEFT JOIN {$wpdb->prefix}team_teamstep                AS vt  ON vt.id  = cm.visitor_team_id
            LEFT JOIN {$wpdb->prefix}team_team                    AS vtm ON vtm.id = vt.team_id
            LEFT JOIN {$wpdb->prefix}stadium_stadium              AS ss  ON ss.id  = cm.stadium_id
            LEFT JOIN {$wpdb->prefix}live_livematch               AS lm  ON lm.match_id = cm.id
            WHERE ls.id = %d
            LIMIT 1";

            return $wpdb->get_row($wpdb->prepare($sql, $ls_id));
        };

        // Use the shared transient cache when available; fall back to a direct query.
        if (function_exists('f365_livegame_cached')) {
            return f365_livegame_cached('f365_ls_details_' . $ls_id, $ttl, $fetch);
        }
        return $fetch();
    }

    /**
     * Build the "title" shown for a live stream — same shape as Django's
     * LiveStreaming.get_livestreaming_title():
     *   "Competition: Home - Visitor (Season)"
     */
    function f365_livestreaming_title($row) {
        if (!$row) {
            return '';
        }
        $home = $row->home_team_shortname ?: $row->home_team_longname;
        $away = $row->visitor_team_shortname ?: $row->visitor_team_longname;
        $title = trim(sprintf(
            '%s: %s - %s',
            $row->competition_name,
            $home ?: __('Visitado', 'futebol365'),
            $away ?: __('Visitante', 'futebol365')
        ));
        if ($row->competition_season) {
            $title .= ' (' . $row->competition_season . ')';
        }
        return $title;
    }
}