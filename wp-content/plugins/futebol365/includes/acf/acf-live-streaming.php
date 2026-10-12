<?php
/**
 * ACF field groups for f365_livestream.
 *
 * Django models: LiveStreaming (extends MatchOnChannel)
 *   match      → post_object → f365_match
 *   datetime   → ACF date_time_picker (mirrors post_date)
 *   url        → ACF url (streaming URL)
 *
 * Also stores LiveMatch relationship where available:
 *   home_team_name   → ACF text (from LiveMatch)
 *   visitor_team_name→ ACF text (from LiveMatch)
 *   home_score       → ACF text
 *   visitor_score    → ACF text
 *   status           → ACF text (RF = finished, etc.)
 *
 * NOTE: MatchOnTV and LiveStream (betting channel) are handled by
 * the same CPT with a `channel_type` discriminator field.
 *
 * SEO stored as standard Yoast postmeta keys.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'acf/init', 'f365_acf_live_streaming' );

function f365_acf_live_streaming(): void {

    acf_add_local_field_group( [
        'key'      => 'group_f365_livestream',
        'title'    => 'Live Streaming Details',
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'f365_livestream' ] ] ],
        'fields'   => [
            [
                'key'   => 'field_ls_tab_relations',
                'label' => 'Relations',
                'type'  => 'tab',
                'placement' => 'top',
            ],
            // ── Match relationship ────────────────────────────────────────
            [
                'key'           => 'field_ls_match',
                'label'         => 'Match',
                'name'          => 'match',
                'type'          => 'post_object',
                'post_type'     => [ 'f365_match' ],
                'return_format' => 'id',
                'required'      => 0,
                'allow_null'    => 1,
                'instructions'  => 'Linked f365_match post. Resolved in second pass from django_match_id.',
            ],

            // ── Competition (denormalised for quick display) ──────────────
            [
                'key'           => 'field_ls_competition',
                'label'         => 'Competition',
                'name'          => 'competition',
                'type'          => 'post_object',
                'post_type'     => [ 'f365_competition' ],
                'return_format' => 'id',
                'required'      => 0,
                'allow_null'    => 1,
            ],

            // ── Streaming details ─────────────────────────────────────────
            [
                'key'   => 'field_ls_tab_streaming',
                'label' => 'Streaming',
                'type'  => 'tab',
                'placement' => 'top',
            ],
            [
                'key'          => 'field_ls_url',
                'label'        => 'Streaming URL',
                'name'         => 'url',
                'type'         => 'url',
                'instructions' => 'Embed or direct stream URL (LiveStreaming.url).',
            ],
            [
                'key'            => 'field_ls_datetime',
                'label'          => 'Scheduled Datetime',
                'name'           => 'datetime',
                'type'           => 'date_time_picker',
                'display_format' => 'd/m/Y H:i',
                'return_format'  => 'Y-m-d H:i:s',
                'instructions'   => 'MatchOnChannel.datetime — may differ from match kick-off.',
            ],

            // ── Match-status snapshot (drives inlive/tostart/finished routes) ──
            // Mirrors LiveStreaming.get_livestreaming_by_type(), which derives
            // status from the related Match (situation + date_time).
            [
                'key'   => 'field_ls_tab_snapshot',
                'label' => 'Match Snapshot',
                'type'  => 'tab',
                'placement' => 'top',
            ],
            [
                'key'          => 'field_ls_match_situation',
                'label'        => 'Match Situation (snapshot)',
                'name'         => 'match_situation',
                'type'         => 'select',
                'choices'      => [
                    'scheduled' => 'Scheduled (no situation → tostart/inlive)',
                    'finished'  => 'Finished',
                    'delayed'   => 'Delayed',
                    'suspended' => 'Suspended',
                    'abrogated' => 'Abrogated',
                ],
                'default_value' => 'scheduled',
                'instructions'  => 'Snapshot of Match.situation. NULL is stored as "scheduled".',
            ],
            [
                'key'            => 'field_ls_match_datetime',
                'label'          => 'Match Datetime (snapshot)',
                'name'           => 'match_datetime',
                'type'           => 'date_time_picker',
                'display_format' => 'd/m/Y H:i',
                'return_format'  => 'Y-m-d H:i:s',
                'instructions'   => 'Snapshot of Match.date_time — used for the status window.',
            ],
            // [
            //     'key'          => 'field_ls_match_timestamp',
            //     'label'        => 'Match Timestamp (unix)',
            //     'name'         => 'match_timestamp',
            //     'type'         => 'number',
            //     'instructions' => 'Unix time of Match.date_time. Numeric meta_query key for the inlive/tostart/finished routes.',
            // ],
           
            // ── Denormalised team names (from LiveMatch / LiveStreaming) ──
            [
                'key'   => 'field_ls_tab_teams',
                'label' => 'Teams',
                'type'  => 'tab',
                'placement' => 'top',
            ],
            [
                'key'           => 'field_ls_home_teamstep',
                'label'         => 'Home Team Step',
                'name'          => 'home_teamstep',
                'type'          => 'post_object',
                'post_type'     => [ 'f365_teamstep' ],
                'return_format' => 'id',
                'required'      => 0,
                'allow_null'    => 1,
            ],
            [
                'key'          => 'field_ls_visitor_teamstep',
                'label'        => 'Visitor Team Step',
                'name'         => 'visitor_teamstep',
                'type'          => 'post_object',
                'post_type'     => [ 'f365_teamstep' ],
                'return_format' => 'id',
                'required'      => 0,
                'allow_null'    => 1,
            ],
            [
                'key'          => 'field_ls_home_team_name',
                'label'        => 'Home Team Name',
                'name'         => 'home_team_name',
                'type'         => 'text',
            ],
            [
                'key'          => 'field_ls_visitor_team_name',
                'label'        => 'Visitor Team Name',
                'name'         => 'visitor_team_name',
                'type'         => 'text',
            ],

            // ── Live match stats snapshot ─────────────────────────────────
            [
                'key'   => 'field_ls_tab_teams',
                'label' => 'Match Score',
                'type'  => 'tab',
                'placement' => 'top',
            ],
            [
                'key'   => 'field_ls_home_score',
                'label' => 'Home Score',
                'name'  => 'home_score',
                'type'  => 'text',
            ],
            [
                'key'   => 'field_ls_visitor_score',
                'label' => 'Visitor Score',
                'name'  => 'visitor_score',
                'type'  => 'text',
            ],
            [
                'key'          => 'field_ls_competition_season',
                'label'        => 'Competition Season',
                'name'         => 'competition_season',
                'type'         => 'text',
            ],
            // [
            //     'key'          => 'field_ls_match_status',
            //     'label'        => 'Match Status',
            //     'name'         => 'match_status',
            //     'type'         => 'text',
            //     'instructions' => 'Live status code (RF=finished, AD=scheduled, etc.).',
            // ],

            
            // ── Migration FKs ─────────────────────────────────────────────
            // [
            //     'key'   => 'field_ls_django_id',
            //     'label' => 'Django LiveStreaming ID',
            //     'name'  => 'django_livestream_id',
            //     'type'  => 'number',
            // ],
            // [
            //     'key'          => 'field_ls_django_source_table',
            //     'label'        => 'Django Source Table',
            //     'name'         => 'django_source_table',
            //     'type'         => 'text',
            //     'instructions' => 'live_livestreaming | live_matchontv | live_livestream (collision-safe map key).',
            // ],
            // [
            //     'key'   => 'field_ls_django_match_id',
            //     'label' => 'Django Match ID (FK)',
            //     'name'  => 'django_match_id',
            //     'type'  => 'number',
            // ],
            // [
            //     'key'   => 'field_ls_django_competition_id',
            //     'label' => 'Django Competition ID (FK)',
            //     'name'  => 'django_competition_id',
            //     'type'  => 'number',
            // ],
        ],
    ] );
}
