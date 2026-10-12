<?php
/**
 * Migration modules: Countries (taxonomy) + admin utilities (status, reset, rollback).
 *
 * @package Futebol365
 * @since   2.1.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    return;
}

trait F365_Module_Util {

    protected function cmd_status(): void {
        $types = [
            'term'             => 'Countries (taxonomy terms)',
            'f365_stadium'     => 'Stadiums',
            'f365_federation'  => 'Federations',
            'f365_fed_comp'    => 'Fed Competitions',
            'f365_player'      => 'Players',
            'f365_coach'       => 'Coaches',
            'f365_president'   => 'Presidents',
            'f365_referee'     => 'Referees',
            'f365_team'        => 'Teams',
            'f365_competition' => 'Competitions',
            'f365_match'       => 'Matches',
            'f365_transfer'    => 'Transfers',
            'f365_video'       => 'Videos',
            'f365_gallery'     => 'Galleries',
            'f365_news'        => 'News (WP posts)',
            'f365_author'      => 'Authors (WP users)',
            'f365_publisher'   => 'Publishers',
            'f365_newsstand'   => 'Newsstand Editions',
            'f365_livestream'  => 'Live Streamings',
            'f365_ranking'     => 'Rankings',
        ];
        $rows = [];
        foreach ( $types as $key => $label ) {
            $rows[] = [ 'Type' => $label, 'Imported' => count( $this->map_get( $key ) ) ];
        }
        WP_CLI\Utils\format_items( 'table', $rows, [ 'Type', 'Imported' ] );
    }

    protected function cmd_reset(): void {
        WP_CLI::confirm( '⚠ This will delete ALL migration maps. Posts will NOT be deleted. Continue?' );
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'f365_mig_%'" );
        $this->map_cache = [];
        WP_CLI::success( 'All migration maps cleared.' );
    }

    protected function cmd_rollback(string $module): void {

        $module_to_map = [
            'polls'           => 'ayspoll_polls',
            'newsstand'       => 'f365_newsstand',
            'publishers'     => 'f365_publisher',
            'livestreaming'  => 'f365_livestream',
            'rankings'       => 'f365_ranking',
            'news'           => 'f365_news',
            'videos'         => 'f365_video',
            'galleries'      => 'f365_gallery',
            'transfers'      => 'f365_transfer',
            'matches'        => 'f365_match',
            'competitions'   => 'f365_competition',
            'teams'          => 'f365_team',
            'squad'          => 'f365_squad',
            'teamsteps'      => 'f365_teamstep',
            'team_presidents' => 'f365_team_president',
            'players'        => 'f365_player',
            'coaches'        => 'f365_coach',
            'presidents'     => 'f365_president',
            'referees'       => 'f365_referee',
            'stadiums'       => 'f365_stadium',
            'federations'    => 'f365_federation',
            'fed_competitions'=> 'f365_fed_comp',
            'livegames'=> 'f365_livegames',
            'livestreaming'=> 'f365_livestream',
            'livegame_players' => 'f365_livegame_player',
            'photos'          => 'f365_photo',
        ];

        if (!isset($module_to_map[$module])) {
            WP_CLI::error("Unknown module '$module'.");
        }

        $map_key = $module_to_map[$module];

        // =========================================================
        // Get WP IDs (map OR fallback)
        // =========================================================
        $map = $this->map_get($map_key);
        $wp_ids = [];

        if (!empty($map)) {
            $wp_ids = array_values($map);
        } else {
            global $wpdb;

            if ($map_key === 'f365_photo') {
                $wp_ids = $wpdb->get_col("
                    SELECT p.ID
                    FROM {$wpdb->posts} p
                    JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                    WHERE p.post_type = 'attachment'
                      AND pm.meta_key = 'django_source_table'
                      AND pm.meta_value = 'multimedia_photo'
                ");
            } elseif ($module === 'polls') {
                $wp_ids = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}ayspoll_polls");
            } elseif ($map_key === 'f365_livegame_player') {

                $wp_ids = $wpdb->get_col("
                    SELECT ID
                    FROM {$wpdb->posts}
                    WHERE post_type = 'f365_livegame_player'
                ");

            }else {
                $wp_ids = $wpdb->get_col($wpdb->prepare("
                    SELECT ID
                    FROM {$wpdb->posts}
                    WHERE post_type = %s
                ", $map_key));
            }
        }

        $total = count($wp_ids);

        if ($total === 0) {
            WP_CLI::warning("No records found for '{$map_key}'. Nothing to delete.");
            return;
        }

        if (!$this->yes) {
            WP_CLI::confirm("⚠ This will permanently delete {$total} '{$map_key}' records. Continue?");
        }

        // =========================================================
        // Delete records
        // =========================================================
        $deleted = 0;

        global $wpdb;

        if ($module === 'polls') {
            foreach ($wp_ids as $wp_id) {
                $wp_id = (int) $wp_id;
                if (!$wp_id) continue;

                // Delete from custom tables
                $wpdb->delete($wpdb->prefix . 'ayspoll_polls', ['id' => $wp_id]);
                $wpdb->delete($wpdb->prefix . 'ayspoll_answers', ['poll_id' => $wp_id]);
                $reports_table = $wpdb->prefix . 'ayspoll_reports';
                if ($wpdb->get_var("SHOW TABLES LIKE '{$reports_table}'") == $reports_table) {
                    $wpdb->delete($reports_table, ['poll_id' => $wp_id]);
                }
                $deleted++;
            }
        } else {
            foreach ($wp_ids as $wp_id) {
                $wp_id = (int) $wp_id;
                if (!$wp_id) continue;

                // 1. Delete postmeta manually (extra safety)
                $wpdb->delete(
                    $wpdb->postmeta,
                    ['post_id' => $wp_id]
                );

                // 2. Delete post
                if (wp_delete_post($wp_id, true)) {
                    $deleted++;
                }
            }
        }

        // =========================================================
        // Cleanup migration map
        // =========================================================
        $wpdb->delete(
            $wpdb->options,
            ['option_name' => "f365_mig_{$map_key}"]
        );

        $this->map_cache[$map_key] = [];

        // Clear progressive last import option if any
        $singular = rtrim( $module, 's' );
        if ( $singular === 'gallerie' ) {
            $singular = 'gallery';
        }
        delete_option( "f365_last_{$singular}_import" );

        WP_CLI::success("Rollback '{$module}': deleted {$deleted} / {$total} posts + meta. Map cleared.");
    }
}

trait F365_Module_Countries {

    /**
     * Static map: django_country_id (int) => SVG filename (string).
     *
     * Derived from flag-icon.css (4x3 selectors with numeric IDs only).
     * Non-numeric selectors (e.g. .flag-icon-aq) have no django_country_id
     * and are intentionally excluded.
     *
     * Source files live in: futebol365/flags/4x3/*.svg
     *
     * @var array<int,string>
     */
    // Static property (not a trait constant — those need PHP 8.2; keeps CLI usable on PHP 8.1).
    private static array $FLAG_MAP = [
        101 => 'ad.svg',
        114 => 'ae.svg',
        148 => 'af.svg',
        218 => 'ag.svg',
        217 => 'ai.svg',
         61 => 'al.svg',
         63 => 'am.svg',
          4 => 'ao.svg',
          5 => 'ar.svg',
        223 => 'as.svg',
          3 => 'at.svg',
         94 => 'au.svg',
        198 => 'aw.svg',
        100 => 'az.svg',
         56 => 'ba.svg',
        133 => 'bb.svg',
        187 => 'bd.svg',
          6 => 'be.svg',
        103 => 'bf.svg',
          8 => 'bg.svg',
        162 => 'bh.svg',
        136 => 'bi.svg',
         50 => 'bj.svg',
        134 => 'bm.svg',
        173 => 'bn.svg',
         72 => 'bo.svg',
          7 => 'br.svg',
        132 => 'bs.svg',
        228 => 'bt.svg',
        135 => 'bw.svg',
         58 => 'by.svg',
        150 => 'bz.svg',
         91 => 'ca.svg',
        127 => 'cd.svg',
        180 => 'cf.svg',
         87 => 'cg.svg',
         37 => 'ch.svg',
         76 => 'ci.svg',
        226 => 'ck.svg',
          9 => 'cl.svg',
         51 => 'cm.svg',
        106 => 'cn.svg',
         67 => 'co.svg',
         73 => 'cr.svg',
        112 => 'cu.svg',
        153 => 'cv.svg',
        175 => 'cw.svg',
         59 => 'cy.svg',
         32 => 'cz.svg',
          1 => 'de.svg',
        183 => 'dj.svg',
         11 => 'dk.svg',
        200 => 'dm.svg',
        147 => 'do.svg',
        111 => 'dz.svg',
         69 => 'ec.svg',
         98 => 'ee.svg',
         77 => 'eg.svg',
        138 => 'er.svg',
         15 => 'es.svg',
        139 => 'et.svg',
         17 => 'fi.svg',
        220 => 'fj.svg',
        102 => 'fo.svg',
         18 => 'fr.svg',
        130 => 'ga.svg',
        234 => 'gb.svg',
        119 => 'gd.svg',
         62 => 'ge.svg',
        237 => 'gf.svg',
         78 => 'gh.svg',
        232 => 'gi.svg',
        137 => 'gm.svg',
         79 => 'gn.svg',
        120 => 'gp.svg',
        181 => 'gq.svg',
         19 => 'gr.svg',
        131 => 'gt.svg',
        227 => 'gu.svg',
        154 => 'gw.svg',
        196 => 'gy.svg',
        172 => 'hk.svg',
         74 => 'hn.svg',
         10 => 'hr.svg',
        121 => 'ht.svg',
         21 => 'hu.svg',
        163 => 'id.svg',
         23 => 'ie.svg',
         25 => 'il.svg',
        161 => 'in.svg',
        113 => 'iq.svg',
        107 => 'ir.svg',
         24 => 'is.svg',
         26 => 'it.svg',
        116 => 'jm.svg',
        128 => 'jo.svg',
         27 => 'jp.svg',
        141 => 'ke.svg',
        210 => 'kg.svg',
        199 => 'kh.svg',
        177 => 'km.svg',
        195 => 'kn.svg',
        109 => 'kp.svg',
        108 => 'kr.svg',
         92 => 'kw.svg',
        151 => 'ky.svg',
         64 => 'kz.svg',
        203 => 'la.svg',
        166 => 'lb.svg',
        145 => 'lc.svg',
         65 => 'li.svg',
        212 => 'lk.svg',
        104 => 'lr.svg',
        155 => 'ls.svg',
         47 => 'lt.svg',
         97 => 'lu.svg',
         60 => 'lv.svg',
         88 => 'ly.svg',
         81 => 'ma.svg',
         54 => 'md.svg',
         96 => 'me.svg',
        240 => 'mf.svg',
        156 => 'mg.svg',
         57 => 'mk.svg',
         80 => 'ml.svg',
        206 => 'mm.svg',
        207 => 'mn.svg',
        204 => 'mo.svg',
        216 => 'mq.svg',
        157 => 'mr.svg',
        208 => 'ms.svg',
         99 => 'mt.svg',
        178 => 'mu.svg',
        205 => 'mv.svg',
        126 => 'mw.svg',
         28 => 'mx.svg',
        185 => 'my.svg',
         29 => 'mz.svg',
         82 => 'na.svg',
        221 => 'nc.svg',
        158 => 'ne.svg',
         83 => 'ng.svg',
        117 => 'ni.svg',
         20 => 'nl.svg',
         30 => 'no.svg',
        209 => 'np.svg',
         95 => 'nz.svg',
        140 => 'om.svg',
        122 => 'pa.svg',
         71 => 'pe.svg',
        123 => 'pf.svg',
        171 => 'pg.svg',
        174 => 'ph.svg',
        189 => 'pk.svg',
         31 => 'pl.svg',
        152 => 'pr.svg',
        197 => 'ps.svg',
          2 => 'pt.svg',
         68 => 'py.svg',
        115 => 'qa.svg',
         33 => 'ro.svg',
         35 => 'rs.svg',
         34 => 'ru.svg',
        146 => 'rw.svg',
        105 => 'sa.svg',
        224 => 'sb.svg',
        182 => 'sc.svg',
         85 => 'sd.svg',
         36 => 'se.svg',
        186 => 'sg.svg',
         14 => 'si.svg',
         13 => 'sk.svg',
        159 => 'sl.svg',
        110 => 'sm.svg',
         84 => 'sn.svg',
        160 => 'so.svg',
        144 => 'sr.svg',
        230 => 'ss.svg',
        211 => 'st.svg',
        118 => 'sv.svg',
        239 => 'sx.svg',
        129 => 'sy.svg',
        179 => 'sz.svg',
        193 => 'tc.svg',
        176 => 'td.svg',
         89 => 'tg.svg',
        184 => 'th.svg',
        213 => 'tj.svg',
        215 => 'tl.svg',
        165 => 'tm.svg',
         75 => 'tn.svg',
        225 => 'to.svg',
         38 => 'tr.svg',
        124 => 'tt.svg',
        214 => 'tw.svg',
        143 => 'tz.svg',
         39 => 'ua.svg',
        142 => 'ug.svg',
         16 => 'us.svg',
         52 => 'uy.svg',
        125 => 'uz.svg',
        191 => 'vc.svg',
         70 => 've.svg',
        202 => 'vg.svg',
        201 => 'vi.svg',
        164 => 'vn.svg',
        219 => 'vu.svg',
        222 => 'ws.svg',
        188 => 'ye.svg',
         49 => 'za.svg',
         86 => 'zm.svg',
         90 => 'zw.svg',
         22 => 'gb-eng.svg',
         53 => 'gb-nir.svg',
         12 => 'gb-sct.svg',
         55 => 'gb-wls.svg',
        229 => 'xk.svg',
        241 => 'bon.svg',
        231 => 'zz.svg',
    ];

    protected function import_countries(): void {
        $rows = $this->pg_all( 'SELECT * FROM country_country ORDER BY shortname' );
        $n = $skip = 0;

        foreach ( $rows as $r ) {
            if ( $n >= $this->limit ) break;
            $name = trim( $r['shortname'] ?? '' );
            if ( ! $name ) continue;

            $slug    = $r['slug'] ?? sanitize_title( $name );
            $term_id = $this->ensure_term( $name, 'f365_country', $slug );
            $this->map_set( 'term', 'country_' . $r['id'], $term_id );

            if ( ! $this->dry_run ) {
                // Native ID mapping
                update_term_meta( $term_id, 'django_country_id', (int) $r['id'] );

                // ACF Meta updates using field keys for performance
                $acf_id = 'f365_country_' . $term_id; // ACF format for taxonomy terms

                update_field( 'field_country_longname',   sanitize_text_field( $r['longname'] ?? '' ), $acf_id );
                update_field( 'field_country_capital',    sanitize_text_field( $r['capital'] ?? '' ), $acf_id );
                update_field( 'field_country_city',       sanitize_text_field( $r['city'] ?? '' ), $acf_id );
                update_field( 'field_country_language',   sanitize_text_field( $r['language'] ?? '' ), $acf_id );
                update_field( 'field_country_area',       sanitize_text_field( $r['area'] ?? '' ), $acf_id );
                update_field( 'field_country_population', sanitize_text_field( $r['population'] ?? '' ), $acf_id );
                update_field( 'field_country_coin',       sanitize_text_field( $r['coin'] ?? '' ), $acf_id );
                
                update_field( 'field_country_is_extinct', in_array( strtolower( (string) ( $r['is_extinct'] ?? false ) ), [ '1', 't', 'true', 'on', 'yes' ], true ) ? 1 : 0, $acf_id );
                
                // URLs and Emails (with sanitization)
                update_field( 'field_country_url',        sanitize_url( $r['url'] ?? '' ), $acf_id );
                update_field( 'field_country_email',      sanitize_email( $r['email'] ?? '' ), $acf_id );
                update_field( 'field_country_facebook',   sanitize_url( $r['facebook'] ?? '' ), $acf_id );
                update_field( 'field_country_twitter',    sanitize_url( $r['twitter'] ?? '' ), $acf_id );
                update_field( 'field_country_youtube',    sanitize_url( $r['youtube'] ?? '' ), $acf_id );
                update_field( 'field_country_instagram',  sanitize_url( $r['instagram'] ?? '' ), $acf_id );
                update_field( 'field_country_googleplus', sanitize_url( $r['googleplus'] ?? '' ), $acf_id );

                // ── Flag image import ─────────────────────────────────────
                // Look up SVG filename from static map (zero I/O, zero external dependency).
                // import_flag_svg() copies from futebol365/flags/4x3/ to WP media library
                // and deduplicates: same SVG is never uploaded twice.
                $django_id = (int) $r['id'];
                if ( isset( self::$FLAG_MAP[ $django_id ] ) ) {
                    $att_id = $this->import_flag_svg( $django_id, self::$FLAG_MAP[ $django_id ] );
                    if ( $att_id ) {
                        update_field( 'field_country_flag', $att_id, $acf_id );
                    }
                } else {
                    $this->log( 'countries', "FLAG_WARN No mapping for country_id={$django_id} ({$name})" );
                }
            }

            $this->log( 'countries', "OK country_id={$r['id']} term_id=$term_id ($name)" );
            $n++;
        }

        $this->map_save( 'term' );
        $this->log( 'countries', "SUMMARY: imported=$n skipped=$skip" );
        WP_CLI::success( "Countries: $n terms imported." );
    }

    /**
     * Import a single SVG flag into the WordPress Media Library.
     *
     * Reads the source SVG from the futebol365 plugin's own flags/4x3/ directory
     * (no dependency on any other plugin or theme). Copies the file into the current
     * WP uploads month directory and registers it as an attachment.
     *
     * Deduplication: if an attachment with _f365_flag_file == $filename already
     * exists, the existing attachment ID is returned immediately — the file is
     * never copied or inserted twice, even across multiple migration runs.
     *
     * @param int    $django_country_id  Source country ID (for log messages).
     * @param string $filename           SVG filename, e.g. 'ad.svg'.
     * @return int  Attachment ID on success, 0 on failure.
     */
    private function import_flag_svg( int $django_country_id, string $filename ): int {

        // Source: futebol365 plugin's own flags/4x3/ directory.
        // F365_PLUGIN_DIR is defined in futebol365.php as plugin_dir_path( __FILE__ ).
        $svg_path = F365_PLUGIN_DIR . 'flags/4x3/' . $filename;

        if ( ! file_exists( $svg_path ) ) {
            $this->log(
                'countries',
                "FLAG_WARN SVG not found on disk: {$svg_path} (country_id={$django_country_id})"
            );
            return 0;
        }

        global $wpdb;

        // Deduplicate: return existing attachment if this file was already imported.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $existing = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_f365_flag_file' AND meta_value = %s LIMIT 1",
                $filename
            )
        );
        if ( $existing ) {
            $this->log( 'countries', "FLAG_SKIP {$filename} → reusing attachment_id={$existing}" );
            return $existing;
        }

        // Ensure WP media helpers are available (safe to call multiple times).
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Resolve destination inside the current uploads month directory.
        $upload = wp_upload_dir();
        $dest   = trailingslashit( $upload['path'] ) . $filename;

        if ( ! copy( $svg_path, $dest ) ) {
            $this->log(
                'countries',
                "FLAG_FAIL Cannot copy SVG: {$svg_path} → {$dest}"
            );
            return 0;
        }

        $att_id = wp_insert_attachment(
            [
                'guid'           => $upload['url'] . '/' . $filename,
                'post_mime_type' => 'image/svg+xml',
                'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ],
            $dest
        );

        if ( is_wp_error( $att_id ) || ! $att_id ) {
            $this->log( 'countries', "FLAG_FAIL wp_insert_attachment failed for {$filename}" );
            return 0;
        }

        // Tag this attachment so we can detect duplicates on subsequent runs.
        update_post_meta( (int) $att_id, '_f365_flag_file', $filename );

        $this->log( 'countries', "FLAG_OK {$filename} → attachment_id={$att_id}" );
        return (int) $att_id;
    }
}
