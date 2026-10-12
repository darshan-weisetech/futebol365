<?php
/**
 * Live Streaming — SQL helper for  /live-streaming/{django_livestream_id}/
 *
 * Mirrors Django:
 *   live.urls  →  live-streaming/(?P<obj_id>\d+)/  →  common_views.read_cached_obj
 *   model      →  live.LiveStreaming  (table: {prefix}live_livestreaming)
 *
 * live_livestreaming only stores id, url, datetime, match_id. Team names, scores,
 * datetime and competition are reached THROUGH match_id → competition_match and
 * its related tables (team_teamstep → team_team, competition_competition) — the
 * same path Django's LiveStreaming.get_livestreaming_title() uses. The result is
 * cached, so the join cost is paid at most once per TTL. No stadium / live_livematch
 * joins (not shown on the page) and no wp_posts / wp_postmeta lookups.
 *
 * Optionally uses f365_livegame_cached() / F365_LIVEGAME_CACHE_TTL when the
 * companion plugin is active; otherwise it caches with a plain transient.
 *
 * @package Futebol365
 */

if (!function_exists('f365_sql_get_livestreaming_details')) {

    /**
     * Fetch one live-streaming row joined through the match to its competition and
     * both teams (team names, scores, datetime, competition). Single row + PK joins,
     * cached below.
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
            // live_livestreaming only stores id, url, datetime, match_id; the team
            // names / competition / datetime are reached THROUGH the match row.
            // Single row + PK joins (fast), and the result is cached below. No
            // stadium / live_livematch joins (not shown), no wp_posts / wp_postmeta.
            $sql = "SELECT
                ls.id                 AS livestream_id,
                ls.url                AS stream_url,
                ls.datetime           AS stream_datetime,

                cm.id                 AS match_id,
                cm.date_time          AS match_datetime,
                cm.competition_id     AS competition_id,

                cc.name               AS competition_name,
                cc.season             AS competition_season,
                cc.edition            AS competition_edition,
                cc.federation_competition_id AS federation_competition_id,

                htm.id                AS home_team_id,
                htm.shortname         AS home_team_shortname,
                htm.longname          AS home_team_longname,

                vtm.id                AS visitor_team_id,
                vtm.shortname         AS visitor_team_shortname,
                vtm.longname          AS visitor_team_longname

            FROM {$wpdb->prefix}live_livestreaming        AS ls
            LEFT JOIN {$wpdb->prefix}competition_match       AS cm  ON cm.id  = ls.match_id
            LEFT JOIN {$wpdb->prefix}competition_competition AS cc  ON cc.id  = cm.competition_id
            LEFT JOIN {$wpdb->prefix}team_teamstep           AS ht  ON ht.id  = cm.home_team_id
            LEFT JOIN {$wpdb->prefix}team_team               AS htm ON htm.id = ht.team_id
            LEFT JOIN {$wpdb->prefix}team_teamstep           AS vt  ON vt.id  = cm.visitor_team_id
            LEFT JOIN {$wpdb->prefix}team_team               AS vtm ON vtm.id = vt.team_id
            WHERE ls.id = %d
            LIMIT 1";

            return $wpdb->get_row($wpdb->prepare($sql, $ls_id));
        };

        // Use the shared transient cache when available; otherwise cache directly
        // so the page never re-runs the query on every hit within the TTL window.
        if (function_exists('f365_livegame_cached')) {
            return f365_livegame_cached('f365_ls_details_v2_' . $ls_id, $ttl, $fetch);
        }

        $cache_key = 'f365_ls_details_v2_' . $ls_id;
        $cached    = wp_cache_get($cache_key, 'livestream');
        if (false !== $cached) {
            return $cached;
        }
        $row = $fetch();
        if ($row) {
            wp_cache_set($cache_key, $row, 'livestream', max(1, (int) $ttl));
        }
        return $row;
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

if (!function_exists('f365_livestreaming_status_ttl')) {

    /**
     * Cache lifetime per tab status. In-play windows move minute-to-minute,
     * the "finished" list is effectively static.
     */
    function f365_livestreaming_status_ttl($status) {
        switch ((string) $status) {
            case 'inlive':
                return 30;
            case 'tostart':
                return MINUTE_IN_SECONDS;
            case 'finished':
                return 5 * MINUTE_IN_SECONDS;
            default:
                return MINUTE_IN_SECONDS;
        }
    }
}

if (!function_exists('f365_livestreaming_status_dataset')) {

    /**
     * Return the FULL set of live-streaming rows for one tab status in a single
     * query and cache the whole set — "cache all data first". The by-status
     * template then paginates this array in PHP, so each page view is one
     * transient read instead of a fresh paginated WP_Query plus per-row ACF
     * (wp_postmeta) lookups.
     *
     * Status windows mirror live.views.live_streaming_by_status. Team names come
     * from competition_match → team_teamstep → team_team; filtering/ordering use
     * competition_match.date_time / situation. The whole-set caching is what keeps
     * the join cost off the hot path. No wp_posts / wp_postmeta.
     *
     * @param string $status  inlive | tostart | finished
     * @return array<int,object>  Cached rows (possibly empty).
     */
    function f365_livestreaming_status_dataset($status) {
        global $wpdb;

        $status  = (string) $status;
        $windows = array(
            'inlive'   => array(
                'where' => 'cm.situation IS NULL AND cm.date_time <= NOW() AND cm.date_time >= DATE_SUB( NOW(), INTERVAL 105 MINUTE )',
                'order' => 'ASC',
            ),
            'tostart'  => array(
                'where' => 'cm.situation IS NULL AND cm.date_time >= NOW()',
                'order' => 'ASC',
            ),
            'finished' => array(
                'where' => "cm.situation = 'finished'",
                'order' => 'DESC',
            ),
        );

        if (!isset($windows[$status])) {
            return array();
        }

        // Hard cap on the cached set (default 10 pages × 30). Filterable.
        $max = (int) apply_filters('f365_livestreaming_status_dataset_max', 300, $status);
        $max = max(1, $max);

        $cache_key = 'f365_ls_dataset_v2_' . $status . '_' . $max;
        $cached    = wp_cache_get($cache_key, 'livestream');
        if (false !== $cached) {
            return is_array($cached) ? $cached : array();
        }

        $where = $windows[$status]['where'];
        $order = $windows[$status]['order'];

        $sql = "SELECT
                ls.id                 AS livestream_id,
                ls.url                AS stream_url,

                cm.id                 AS match_id,
                cm.date_time          AS match_datetime,
                cm.competition_id     AS competition_id,
                cm.situation,
                cm.home_score         AS home_score,
                cm.visitor_score      AS visitor_score,
                cm.home_score_extra,
                cm.visitor_score_extra,
                cm.penalty_home_score,
                cm.penalty_visitor_score,

                htm.id                AS home_team_id,
                htm.shortname         AS home_team_shortname,
                htm.longname          AS home_team_longname,
                htm.team_type         AS home_team_type,

                vtm.id                AS visitor_team_id,
                vtm.shortname         AS visitor_team_shortname,
                vtm.longname          AS visitor_team_longname,
                vtm.team_type         AS visitor_team_type
            FROM {$wpdb->prefix}live_livestreaming AS ls
            INNER JOIN {$wpdb->prefix}competition_match AS cm  ON cm.id  = ls.match_id
            LEFT JOIN  {$wpdb->prefix}team_teamstep     AS ht  ON ht.id  = cm.home_team_id
            LEFT JOIN  {$wpdb->prefix}team_team         AS htm ON htm.id = ht.team_id
            LEFT JOIN  {$wpdb->prefix}team_teamstep     AS vt  ON vt.id  = cm.visitor_team_id
            LEFT JOIN  {$wpdb->prefix}team_team         AS vtm ON vtm.id = vt.team_id
            WHERE {$where}
            ORDER BY cm.date_time {$order}
            LIMIT %d";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $max));
        if (!is_array($rows)) {
            $rows = array();
        }

        wp_cache_set($cache_key, $rows, 'livestream', f365_livestreaming_status_ttl($status));

        return $rows;
    }
}

if (!function_exists('f365_livestreaming_flush_dataset_cache')) {

    /**
     * Drop the cached tab datasets (all statuses, default cap). The short TTLs
     * already keep data fresh; this is for an explicit flush after a sync.
     */
    function f365_livestreaming_flush_dataset_cache() {
        $max = (int) apply_filters('f365_livestreaming_status_dataset_max', 300, '');
        $max = max(1, $max);
        foreach (array('inlive', 'tostart', 'finished') as $status) {
            wp_cache_delete('f365_ls_dataset_v2_' . $status . '_' . $max, 'livestream');
        }
    }
    add_action('save_post_f365_livestream', 'f365_livestreaming_flush_dataset_cache');
}