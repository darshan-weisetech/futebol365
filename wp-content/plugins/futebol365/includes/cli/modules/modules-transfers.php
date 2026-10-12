<?php

/**
 * Migration modules: Transfers (Optimized WordPress API Parallel Edition).
 *
 * @package Futebol365
 * @since   2.0.0
 */

trait F365_Module_Transfers {

    protected function import_transfers(): void {
        global $wpdb;

        $script_start_time = microtime(true);
        $batchSize = 3000;

        $offset    = isset($this->assoc_args['offset']) ? (int)$this->assoc_args['offset'] : 0;
        $cli_limit = isset($this->assoc_args['limit']) ? (int)$this->assoc_args['limit'] : 0;

        $total_processed = 0;
        $total_inserted  = 0;
        $total_updated   = 0;
        $total_skipped   = 0;
        $total_failed    = 0;

        while (true) {

            if ($cli_limit > 0 && ($total_processed + $batchSize) > $cli_limit) {
                $batchSize = $cli_limit - $total_processed;
            }

            if ($batchSize <= 0) {
                break;
            }

            // =========================
            // MAIN QUERY
            // =========================
            $rows = $wpdb->get_results($wpdb->prepare("
                SELECT tr.*,
                       pp.shortname AS player_name,
                       ot.team_id AS origin_real_team_id,
                       dt.team_id AS dest_real_team_id,
                       (r.id IS NOT NULL) AS is_rumor
                FROM wp_transfer_transfer tr

                LEFT JOIN wp_person_player pl ON pl.id = tr.player_id
                LEFT JOIN wp_person_person pp ON pp.id = pl.person_id

                LEFT JOIN wp_team_teamstep ot ON ot.id = tr.team_origin_id
                LEFT JOIN wp_team_teamstep dt ON dt.id = tr.team_destination_id

                LEFT JOIN wp_transfer_transferrumor r
                    ON r.player_id = tr.player_id
                   AND r.team_origin_id = tr.team_origin_id
                   AND r.team_destination_id = tr.team_destination_id

                ORDER BY tr.id ASC
                LIMIT %d OFFSET %d
            ", $batchSize, $offset), ARRAY_A);

            if (empty($rows)) {
                break;
            }

            $django_ids = array_column($rows, 'id');

            // =========================
            // EXISTING POSTS MAP
            // =========================
            $existing_mappings = [];

            if (!empty($django_ids)) {
                $meta_lookup_rows = $wpdb->get_results("
                    SELECT pm.post_id, pm.meta_value
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                    WHERE pm.meta_key = 'django_transfer_id'
                      AND p.post_type = 'f365_transfer'
                      AND p.post_status != 'trash'
                      AND pm.meta_value IN (" . implode(',', array_map('intval', $django_ids)) . ")
                ", ARRAY_A);

                foreach ($meta_lookup_rows as $mlr) {
                    $existing_mappings[(int)$mlr['meta_value']] = (int)$mlr['post_id'];
                }
            }

            // =========================
            // BULK LOOKUPS
            // =========================
            $batch_player_ids   = array_unique(array_column($rows, 'player_id'));
            $batch_teamstep_ids = array_unique(array_merge(
                array_column($rows, 'team_origin_id'),
                array_column($rows, 'team_destination_id')
            ));
            $batch_team_ids = array_unique(array_merge(
                array_column($rows, 'origin_real_team_id'),
                array_column($rows, 'dest_real_team_id')
            ));

            $wp_player_map = [];
            $wp_teamstep_map = [];
            $wp_team_map = [];

            // players
            if (!empty($batch_player_ids)) {
                $p = $wpdb->get_results("
                    SELECT pm.post_id, pm.meta_value
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                    WHERE pm.meta_key = 'django_player_id'
                      AND p.post_type = 'f365_player'
                      AND pm.meta_value IN (" . implode(',', array_map('intval', $batch_player_ids)) . ")
                ", ARRAY_A);

                foreach ($p as $row) {
                    $wp_player_map[(int)$row['meta_value']] = (int)$row['post_id'];
                }
            }

            // teamsteps
            if (!empty($batch_teamstep_ids)) {
                $t = $wpdb->get_results("
                    SELECT pm.post_id, pm.meta_value
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                    WHERE pm.meta_key = 'django_teamstep_id'
                      AND p.post_type = 'f365_teamstep'
                      AND pm.meta_value IN (" . implode(',', array_map('intval', $batch_teamstep_ids)) . ")
                ", ARRAY_A);

                foreach ($t as $row) {
                    $wp_teamstep_map[(int)$row['meta_value']] = (int)$row['post_id'];
                }
            }

            // teams
            if (!empty($batch_team_ids)) {
                $t = $wpdb->get_results("
                    SELECT pm.post_id, pm.meta_value
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                    WHERE pm.meta_key = 'django_id'
                      AND p.post_type = 'f365_team'
                      AND pm.meta_value IN (" . implode(',', array_map('intval', $batch_team_ids)) . ")
                ", ARRAY_A);

                foreach ($t as $row) {
                    $wp_team_map[(int)$row['meta_value']] = (int)$row['post_id'];
                }
            }

            // =========================
            // PROCESS ROWS
            // =========================
            foreach ($rows as $r) {

                $django_transfer_id = (int)$r['id'];

                $title = sprintf(
                    '%s: %s → %s',
                    $r['player_name'] ?? '?',
                    $r['origin_real_team_id'] ?? '?',
                    $r['dest_real_team_id'] ?? '?'
                );

                $ttype = $r['transfer_type'] ?? '';

                $label_map = [
                    '?' => 'and',
                    'D' => 'definitive',
                    'E' => 'loan'
                ];

                $label = $label_map[$ttype] ?? '';
                $is_rumor_val = !empty($r['is_rumor']) ? 1 : 0;

                // =========================
                // METADATA (RESTORED)
                // =========================
                $metadata = [
                    'edition'            => $r['edition'] ?? '',
                    'shirt_number'       => (int)($r['shirt_number'] ?? 0),
                    'value'              => $r['value'] ?? '',
                    'transfer_date'      => $r['transfer_date'] ?? '',
                    'transfer_type'      => $ttype,
                    'is_rumor'           => $is_rumor_val,
                    'django_transfer_id' => $django_transfer_id,
                    'django_player_id'   => (int)$r['player_id'],
                    'django_origin_ts_id'=> (int)$r['team_origin_id'],
                    'django_dest_ts_id'  => (int)$r['team_destination_id'],
                ];

                $wp_player_id     = $wp_player_map[(int)$r['player_id']] ?? '';
                $wp_team_orig_id  = $wp_team_map[(int)$r['origin_real_team_id']] ?? '';
                $wp_team_dest_id  = $wp_team_map[(int)$r['dest_real_team_id']] ?? '';
                $wp_tstep_orig_id = $wp_teamstep_map[(int)$r['team_origin_id']] ?? '';
                $wp_tstep_dest_id = $wp_teamstep_map[(int)$r['team_destination_id']] ?? '';

                // =========================
                // UPDATE
                // =========================
                if (isset($existing_mappings[$django_transfer_id])) {

                    $wp_id = $existing_mappings[$django_transfer_id];
                    $total_updated++;

                    $slug = sanitize_title($title . '-' . $wp_id);

                    $wpdb->update(
                        $wpdb->posts,
                        [
                            'post_title' => $title,
                            'post_name'  => $slug,
                            'post_modified' => current_time('mysql'),
                            'post_modified_gmt' => current_time('mysql', 1),
                        ],
                        ['ID' => $wp_id]
                    );

                    foreach ($metadata as $k => $v) {
                        update_post_meta($wp_id, $k, $v);
                    }

                    update_post_meta($wp_id, 'player', $wp_player_id);
                    update_post_meta($wp_id, 'team_origin', $wp_team_orig_id);
                    update_post_meta($wp_id, 'team_destination', $wp_team_dest_id);
                    update_post_meta($wp_id, 'teamstep_origin', $wp_tstep_orig_id);
                    update_post_meta($wp_id, 'teamstep_destination', $wp_tstep_dest_id);

                    $this->log('transfers', sprintf(
                        "[UPDATE] transfer_id=%d | wp_id=%d | player=%s | %s → %s",
                        $django_transfer_id,
                        $wp_id,
                        $r['player_name'] ?? '?',
                        $r['origin_real_team_id'] ?? '?',
                        $r['dest_real_team_id'] ?? '?'
                    ));

                // =========================
                // INSERT
                // =========================
                } else {

                    $wpdb->insert(
                        $wpdb->posts,
                        [
                            'post_author' => 1,
                            'post_date' => current_time('mysql'),
                            'post_date_gmt' => current_time('mysql', 1),
                            'post_title' => $title,
                            'post_status' => 'publish',
                            'post_type' => 'f365_transfer',
                            'post_name' => sanitize_title($title),
                            'post_modified' => current_time('mysql'),
                            'post_modified_gmt' => current_time('mysql', 1),
                        ]
                    );

                    $wp_id = (int)$wpdb->insert_id;
                    $total_inserted++;

                    foreach ($metadata as $k => $v) {
                        update_post_meta($wp_id, $k, $v);
                    }

                    update_post_meta($wp_id, 'player', $wp_player_id);
                    update_post_meta($wp_id, 'team_origin', $wp_team_orig_id);
                    update_post_meta($wp_id, 'team_dest', $wp_team_dest_id);
                    update_post_meta($wp_id, 'teamstep_origin', $wp_tstep_orig_id);
                    update_post_meta($wp_id, 'teamstep_dest', $wp_tstep_dest_id);

                    if (!empty($label)) {
                        wp_set_object_terms($wp_id, $label, 'f365_transfer_type', false);
                    }

                    $this->log('transfers', sprintf(
                        "[INSERT] transfer_id=%d | wp_id=%d | player=%s | %s → %s",
                        $django_transfer_id,
                        $wp_id,
                        $r['player_name'] ?? '?',
                        $r['origin_real_team_id'] ?? '?',
                        $r['dest_real_team_id'] ?? '?'
                    ));
                }

                $total_processed++;
            }

            $offset += $batchSize;

            unset($rows, $meta_lookup_rows, $existing_mappings);

            if ($cli_limit > 0 && $total_processed >= $cli_limit) {
                break;
            }
        }

        $this->log('transfers', sprintf(
            "SUMMARY: inserted=%d updated=%d skipped=%d failed=%d",
            $total_inserted,
            $total_updated,
            $total_skipped,
            $total_failed
        ));

        $duration = round((microtime(true) - $script_start_time) / 60, 2);

        WP_CLI::success("Transfers completed in {$duration} minutes.");
    }
}