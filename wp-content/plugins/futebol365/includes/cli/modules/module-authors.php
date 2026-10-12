<?php
/**
 * Migration module: Authors.
 *
 * Django table: publish_source
 * WordPress target: native WP users (role = 'author')
 *
 * Source model fields (from Django):
 *   id, name, summary, display_source, slug,
 *   email, url, facebook, twitter, youtube, instagram, googleplus
 *
 * NOTE: Source has NO image field in the Django model — confirmed from
 * publish/models.py. No image is downloaded for authors.
 *
 * WP user meta mapping:
 *   summary        → description  (native WP user bio)
 *   url            → user_url     (native WP user field)
 *   twitter        → twitter (also used by Yoast SEO for Twitter Cards)
 *   facebook       → facebook
 *   youtube        → youtube
 *   instagram      → instagram
 *   googleplus     → googleplus
 *   display_source → f365_display_source
 *   slug           → f365_source_slug
 *   id             → django_source_id
 *
 * Email: only stored if present in the source row — never auto-generated.
 *
 * @package Futebol365
 * @since   2.1.1
 */

defined('ABSPATH') || exit;

if (!(defined('WP_CLI') && WP_CLI)) {
    return;
}

trait F365_Module_Authors
{

    protected function import_authors(): void
    {
        $logger = 'authors';
        $rows = $this->pg_all('SELECT * FROM publish_source ORDER BY id');
        $n = $skip = $fail = 0;

        foreach ($rows as $r) {
            if ($n >= $this->limit)
                break;

            $source_id = (int) $r['id'];

            // ── Skip if already imported ──────────────────────────────────
            if (!$this->force && $this->map_resolve('f365_author', $source_id)) {
                $skip++;
                $this->log($logger, "SKIP source_id={$source_id} — already imported");
                continue;
            }

            // ── Build user_login from slug, fallback to sanitised name ────
            $raw_slug = trim($r['slug'] ?? '');
            $raw_name = trim($r['name'] ?? '');
            $login = $raw_slug
                ? sanitize_user($raw_slug, true)
                : sanitize_user($raw_name, true);

            // Final safety: login must be non-empty and valid
            if (!$login) {
                $login = 'f365-source-' . $source_id;
            }

            // ── Email: use DB value only — never auto-generate ────────────
            $raw_email = trim($r['email'] ?? '');
            $email = ($raw_email && is_email($raw_email)) ? $raw_email : '';

            // ── Try to reuse an existing WP user ─────────────────────────
            $existing = get_user_by('login', $login);

            if ($existing) {
                $wp_user_id = (int) $existing->ID;
                $this->log($logger, "REUSE user_id={$wp_user_id} for source_id={$source_id} ({$raw_name})");
            } elseif ($this->dry_run) {
                $wp_user_id = 9999;
                $this->log($logger, "DRY-RUN source_id={$source_id} would create user login={$login}");
            } else {
                // ── Insert new WP user ────────────────────────────────────
                $user_data = [
                    'user_login' => $login,
                    'display_name' => $raw_name ?: $login,
                    'user_pass' => wp_generate_password(24, true, true),
                    'role' => 'author',
                    'description' => trim($r['summary'] ?? ''),
                    'user_url' => trim($r['url'] ?? ''),
                ];

                // Only include user_email if we have a valid one
                if ($email) {
                    $user_data['user_email'] = $email;
                }

                $result = wp_insert_user($user_data);

                if (is_wp_error($result)) {
                    $fail++;
                    $this->log($logger, "FAIL source_id={$source_id} — " . $result->get_error_message());
                    continue;
                }

                $wp_user_id = (int) $result;
            }

            // ── Write user meta ───────────────────────────────────────────
            if (!$this->dry_run && $wp_user_id) {

                // Django source fields
                update_user_meta($wp_user_id, 'django_source_id', $source_id);
                update_user_meta($wp_user_id, 'f365_source_slug', $raw_slug);
                update_user_meta($wp_user_id, 'f365_display_source', $r['display_source'] ? '1' : '0');
                update_user_meta($wp_user_id, 'display_source', $r['display_source'] ? 1 : 0);
                update_user_meta($wp_user_id, 'is_active', 1);

                // SEO & Yoast Meta Mappings
                update_user_meta($wp_user_id, 'wpseo_title', $raw_name . ' - Futebol 365');
                update_user_meta($wp_user_id, 'wpseo_metadesc', 'As mais recentes notícias do futebol nacional e internacional acompanhadas ao minuto.');

                // Social — native WP / Yoast-compatible keys where possible
                // Yoast SEO reads 'twitter' user meta for Twitter Card author
                $this->_set_user_meta_if_present($wp_user_id, 'twitter', $r['twitter'] ?? '');
                $this->_set_user_meta_if_present($wp_user_id, 'facebook', $r['facebook'] ?? '');
                $this->_set_user_meta_if_present($wp_user_id, 'youtube', $r['youtube'] ?? '');
                $this->_set_user_meta_if_present($wp_user_id, 'instagram', $r['instagram'] ?? '');
                $this->_set_user_meta_if_present($wp_user_id, 'googleplus', $r['googleplus'] ?? '');

                // Update description (bio) if it came from an existing user
                // that may not have had it set
                $bio = trim($r['summary'] ?? '');
                if ($bio) {
                    update_user_meta($wp_user_id, 'description', $bio);
                }

                // Update user_url if present and user already existed
                $url = trim($r['url'] ?? '');
                if ($url && $existing) {
                    wp_update_user(['ID' => $wp_user_id, 'user_url' => $url]);
                }

                // Sideload and assign author profile photo
                $media_base = rtrim(getenv('DJANGO_MEDIA_URL') ?: 'https://media.futebol365.pt', '/');
                $image_url = $media_base . '/images/publish_source/' . $source_id . '.jpg';
                $att_id = $this->sideload_image($image_url, 0, $raw_name);
                if ($att_id) {
                    update_user_meta($wp_user_id, 'author_photo', $att_id);
                }
            }

            $this->map_set('f365_author', $source_id, $wp_user_id);
            $this->log($logger, "OK source_id={$source_id} → wp_user_id={$wp_user_id} ({$raw_name})");
            $n++;
        }

        $this->map_save('f365_author');
        $this->log($logger, "SUMMARY: imported={$n} skipped={$skip} failed={$fail}");
        WP_CLI::success("Authors: {$n} imported, {$skip} skipped, {$fail} failed.");
    }

    /**
     * Write user meta only when the value is non-empty.
     */
    private function _set_user_meta_if_present(int $user_id, string $key, string $value): void
    {
        $value = trim($value);
        if ($value) {
            update_user_meta($user_id, $key, $value);
        }
    }
}