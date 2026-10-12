<?php
/**
 * Player Personal Data template
 */
get_header();

$post_id = $args['post_id'] ?? null;

if (!$post_id) {
    $django_player_id = '';

    // Extract ID directly from the URL e.g. /jogador/5963/
    if (isset($_SERVER['REQUEST_URI']) && preg_match('#/jogador/([0-9]+)#i', $_SERVER['REQUEST_URI'], $matches)) {
        $django_player_id = $matches[1];
    } elseif (get_query_var('f365_player')) {
        $django_player_id = get_query_var('f365_player');
    } else {
        $django_player_id = get_query_var('name');
    }

    if (!empty($django_player_id) && is_numeric($django_player_id)) {
        $cache_key = 'player_post_id_' . $django_player_id;
        $cache_group = 'f365_player_mappings';
        $found_id = wp_cache_get($cache_key, $cache_group);

        if (false === $found_id) {
            global $wpdb;
            $found_id = $wpdb->get_var($wpdb->prepare("
                SELECT ID FROM {$wpdb->posts} WHERE legacy_id = %d AND post_type = 'f365_player' LIMIT 1
            ", $django_player_id));

            if ($found_id) {
                wp_cache_set($cache_key, $found_id, $cache_group, HOUR_IN_SECONDS);
            }
        }

        $post_id = $found_id ? (int) $found_id : get_the_ID();
    } else {
        $post_id = get_the_ID();
    }
}

// ─── 2. Resolve django_player_id from post_id ───────────────────────────────
global $wpdb;

$django_player_id = get_post_meta($post_id, 'django_player_id', true);

if (empty($django_player_id) || !is_numeric($django_player_id)) {
    $django_player_id = 0;
}

// ─── 3. Section config ──────────────────────────────────────────────────────
$current_section = $args['current_section'] ?? 'dados-pessoais';
$sections = $args['sections'] ?? (function_exists('futebol365_get_player_sections') ? futebol365_get_player_sections() : []);

if (!isset($sections[$current_section])) {
    $current_section = 'dados-pessoais';
}

$current_config = $sections[$current_section];

// ─── 4. Optimised single SQL query with Caching ────────────────────────────
$cache_key = 'player_data_pessoais_' . $django_player_id;
$cache_group = 'f365_player_data';
$player_data = wp_cache_get($cache_key, $cache_group);

if (false === $player_data) {
    // Python stores right_foot / left_foot as boolean → MySQL imports them as 't' / 'f'
    $player_data = $wpdb->get_row($wpdb->prepare("
        SELECT
            -- Nome
            CASE
                WHEN p.longname IS NOT NULL AND p.longname != '' THEN p.longname
                ELSE p.shortname
            END AS nome_completo,

            -- Datas
            p.birth_date  AS data_nascimento,
            p.death_date  AS data_falecimento,
            CASE
                WHEN p.death_date IS NOT NULL THEN NULL
                ELSE TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE())
            END AS idade,

            p.birth_place AS local_nascimento,
            p.url         AS pagina_internet,

            -- Nacionalidade / Naturalidade
            p.nationality_id,
            nat.shortname  AS nacionalidade_nome,
            p.naturality_id,
            ntr.shortname  AS naturalidade_nome,

            -- Dados Técnicos
            CASE pr.position
                WHEN 'goalkeeper' THEN 'Guarda-redes'
                WHEN 'defender'   THEN 'Defesa'
                WHEN 'midfielder' THEN 'Médio'
                WHEN 'forward'    THEN 'Avançado'
                ELSE pr.position
            END AS posicao,

            pr.weight AS peso_kg,
            pr.height AS altura_cm,

            -- Pé preferido  (Python: t = True, f = False)
            CASE
                WHEN (pr.right_foot IN ('t','1','true') AND pr.left_foot IN ('t','1','true')) THEN 'Ambos'
                WHEN pr.right_foot IN ('t','1','true') THEN 'Direito'
                WHEN pr.left_foot  IN ('t','1','true') THEN 'Esquerdo'
                ELSE NULL
            END AS pe_preferido,

            -- Equipa atual (club only, matching Player::get_squad())
            t.id        AS team_id,
            t.shortname AS equipa_atual,
            t.country_id AS equipa_pais_id,

            -- Redes Sociais
            p.facebook,
            p.twitter,
            p.youtube,
            p.instagram

        FROM {$wpdb->prefix}person_player pr
        JOIN {$wpdb->prefix}person_person p ON pr.person_id = p.id
        LEFT JOIN {$wpdb->prefix}country_country nat ON p.nationality_id = nat.id
        LEFT JOIN {$wpdb->prefix}country_country ntr ON p.naturality_id  = ntr.id
        LEFT JOIN (
            SELECT s1.team_id, s1.player_id
            FROM {$wpdb->prefix}team_squad s1
            INNER JOIN {$wpdb->prefix}team_teamstep ts1 ON s1.team_id = ts1.id
            INNER JOIN {$wpdb->prefix}team_team t1 ON ts1.team_id = t1.id
            WHERE s1.player_id = %d
              AND t1.team_type = 'club'
              AND NOT EXISTS (
                  SELECT 1
                  FROM {$wpdb->prefix}team_teamstep next_ts
                  WHERE next_ts.team_id = ts1.team_id
                    AND next_ts.edition > s1.edition
                    AND next_ts.step = ts1.step
                    AND next_ts.gender = ts1.gender
                    AND next_ts.modality = ts1.modality
              )
            ORDER BY s1.edition DESC, s1.order ASC, s1.id DESC
            LIMIT 1
        ) latest_squad ON latest_squad.player_id = pr.id
        LEFT JOIN {$wpdb->prefix}team_teamstep ts ON latest_squad.team_id = ts.id
        LEFT JOIN {$wpdb->prefix}team_team     t  ON ts.team_id = t.id
        WHERE pr.id = %d
    ", $django_player_id, $django_player_id));

    if ($player_data) {
        wp_cache_set($cache_key, $player_data, $cache_group, HOUR_IN_SECONDS);
    }
}

// ─── 5. Country flag helper ──────────────────────────────────────────────────
if (!function_exists('f365_get_country_flag_url')) {
    function f365_get_country_flag_url($country_id)
    {
        if (!$country_id)
            return '';

        $cache_key = 'country_term_id_' . $country_id;
        $cache_group = 'f365_country_mappings';
        $term_id = wp_cache_get($cache_key, $cache_group);

        if (false === $term_id) {
            global $wpdb;
            $term_id = $wpdb->get_var($wpdb->prepare(
                "SELECT term_id FROM {$wpdb->termmeta}
                 WHERE meta_key = 'django_country_id' AND meta_value = %s LIMIT 1",
                $country_id
            ));
            if ($term_id) {
                wp_cache_set($cache_key, $term_id, $cache_group, DAY_IN_SECONDS);
            }
        }

        if ($term_id) {
            $flag = get_field('country_flag', 'term_' . $term_id);
            if (is_numeric($flag))
                return wp_get_attachment_image_url($flag, 'full');
            if (is_array($flag) && !empty($flag['url']))
                return $flag['url'];
            if (is_string($flag) && $flag)
                return $flag;
        }
        return '';
    }
}

$nationality_flag_url = f365_get_country_flag_url($player_data->nationality_id ?? null);
$naturality_flag_url = f365_get_country_flag_url($player_data->naturality_id ?? null);
$team_flag_url = f365_get_country_flag_url($player_data->equipa_pais_id ?? null);
$data = function_exists('f365_get_player_header_data') ? f365_get_player_header_data($post_id) : [];
if (empty($data)) {
    return; // no resolvable player — render nothing rather than a broken header
}
$club = $data['club'] ?? null;
?>


<main id="primary" class="site-main single-article-page">
    <?php
    get_template_part(
        'template-parts/player/f365-player-header',
        null,
        [
            'post_id' => $post_id,
            'current_section' => $current_section,
            'sections' => $sections,
        ]
    );
    ?>

    <div class="ink-grid futebol365-container">

        <section class="left-column-block">

            <div class="breadcrumbs-f365">
                <div class="ink-navigation breadcrumbs">
                    <?php yoast_breadcrumb(); ?>
                </div>
            </div>

            <?php

            get_template_part(
                'template-parts/players/f365-player-' . $current_config['template'],
                null,
                [
                    'post_id' => $post_id,
                ]
            );
            ?>
            <h4 class="condensed-400 headerTitle top-space"><?php esc_html_e('Dados Pessoais', 'f365'); ?> <span
                    class="headerTitle-border"></span></h4>
            <div class="overflow-auto">
                <?php if ($player_data): ?>
                    <table class="ink-table ink-table-f365 alternating">
                        <tbody>

                            <?php if ($player_data->nome_completo): ?>
                                <tr>
                                    <td class="align-right all-35 font-blue"><?php esc_html_e('Nome', 'f365'); ?>:</td>
                                    <td class="align-left"><?php echo esc_html($player_data->nome_completo); ?></td>
                                </tr>
                            <?php endif; ?>

                            <?php if ($player_data->data_nascimento): ?>
                                <tr>
                                    <td class="align-right font-blue"><?php esc_html_e('Data Nascimento', 'f365'); ?>:</td>
                                    <td class="align-left">
                                        <?php echo esc_html(date('d-m-Y', strtotime($player_data->data_nascimento))); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <tr>
                                <?php if ($player_data->data_falecimento): ?>
                                    <td class="align-right font-blue"><?php esc_html_e('Falecido a', 'f365'); ?>:</td>
                                    <td class="align-left">
                                        <?php echo esc_html(date('d-m-Y', strtotime($player_data->data_falecimento))); ?>
                                    </td>
                                <?php else: ?>
                                    <td class="align-right font-blue"><?php esc_html_e('Idade', 'f365'); ?>:</td>
                                    <td class="align-left">
                                        <?php echo $player_data->idade ? esc_html($player_data->idade) . ' anos' : '-'; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>

                            <?php if ($player_data->local_nascimento): ?>
                                <tr>
                                    <td class="align-right font-blue"><?php esc_html_e('Local Nascimento', 'f365'); ?>:</td>
                                    <td class="align-left"><?php echo esc_html($player_data->local_nascimento); ?></td>
                                </tr>
                            <?php endif; ?>

                            <?php if ($player_data->nacionalidade_nome || $player_data->naturalidade_nome): ?>
                                <tr>
                                    <td class="align-right font-blue"><?php esc_html_e('Nacionalidade', 'f365'); ?>:</td>
                                    <td class="align-left">
                                        <?php if ($player_data->naturalidade_nome): ?>
                                            <?php if ($naturality_flag_url): ?>
                                                <i class="flag-bg"
                                                    style="background-image:url(<?php echo esc_url($naturality_flag_url); ?>);"></i>
                                            <?php endif; ?>
                                            <?php echo esc_html($player_data->naturalidade_nome); ?>
                                            <?php if ($player_data->nacionalidade_nome && $player_data->nacionalidade_nome !== $player_data->naturalidade_nome): ?>
                                                <br>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php if ($player_data->nacionalidade_nome && $player_data->nacionalidade_nome !== $player_data->naturalidade_nome): ?>
                                            <?php if ($nationality_flag_url): ?>
                                                <i class="flag-bg"
                                                    style="background-image:url(<?php echo esc_url($nationality_flag_url); ?>);"></i>
                                            <?php endif; ?>
                                            <?php echo esc_html($player_data->nacionalidade_nome); ?>
                                        <?php elseif ($player_data->nacionalidade_nome && !$player_data->naturalidade_nome): ?>
                                            <?php if ($nationality_flag_url): ?>
                                                <i class="flag-bg"
                                                    style="background-image:url(<?php echo esc_url($nationality_flag_url); ?>);"></i>
                                            <?php endif; ?>
                                            <?php echo esc_html($player_data->nacionalidade_nome); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php if ($player_data->pagina_internet): ?>
                                <tr>
                                    <td class="align-right font-blue"><?php esc_html_e('Página Internet', 'f365'); ?>:</td>
                                    <td class="align-left">
                                        <a target="_blank" href="<?php echo esc_url($player_data->pagina_internet); ?>">
                                            <?php echo esc_html($player_data->pagina_internet); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php if ($player_data->posicao): ?>
                                <tr>
                                    <td class="align-right font-blue"><?php esc_html_e('Posição', 'f365'); ?>:</td>
                                    <td class="align-left"><?php echo esc_html($player_data->posicao); ?></td>
                                </tr>
                            <?php endif; ?>

                            <?php if ($player_data->peso_kg): ?>
                                <tr>
                                    <td class="align-right font-blue"><?php esc_html_e('Peso', 'f365'); ?>:</td>
                                    <td class="align-left"><?php echo esc_html($player_data->peso_kg); ?>
                                        <?php esc_html_e('kg', 'f365'); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php if ($player_data->altura_cm): ?>
                                <tr>
                                    <td class="align-right font-blue"><?php esc_html_e('Altura', 'f365'); ?>:</td>
                                    <td class="align-left"><?php echo esc_html($player_data->altura_cm); ?>
                                        <?php esc_html_e('cm', 'f365'); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php if ($player_data->pe_preferido): ?>
                                <tr>
                                    <td class="align-right font-blue"><?php esc_html_e('Pé', 'f365'); ?>:</td>
                                    <td class="align-left"><?php echo esc_html($player_data->pe_preferido); ?></td>
                                </tr>
                            <?php endif; ?>

                            <?php if ($player_data->equipa_atual && !$player_data->data_falecimento): ?>
                                <tr>
                                    <td class="align-right font-blue"><?php esc_html_e('Equipa', 'f365'); ?>:</td>
                                    <td class="align-left">
                                        <?php if ($team_flag_url): ?>
                                            <i class="flag-bg"
                                                style="background-image:url(<?php echo esc_url($team_flag_url); ?>);"></i>
                                        <?php endif; ?>
                                        <?php if ($club['url']): ?>
                                            <a href="<?php echo esc_url($club['url']); ?>">
                                                <?php echo esc_html($player_data->equipa_atual); ?>
                                            </a>
                                        <?php else: ?>
                                            <?php echo esc_html($player_data->equipa_atual); ?>
                                        <?php endif; ?>

                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php if ($player_data->facebook || $player_data->twitter || $player_data->youtube || $player_data->instagram): ?>
                                <tr>
                                    <td class="align-right font-blue"><?php esc_html_e('Redes Sociais', 'f365'); ?>:</td>
                                    <td>
                                        <?php if ($player_data->facebook): ?>
                                            <a href="https://www.facebook.com/<?php echo esc_attr($player_data->facebook); ?>"
                                                target="_blank" aria-label="Facebook">
                                                <svg class="icon-style quarter-right-space" viewBox="0 0 16 16" width="1em"
                                                    height="1em" aria-hidden="true" focusable="false">
                                                    <use href="#social-facebook"></use>
                                                </svg>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($player_data->twitter): ?>
                                            <a href="https://x.com/<?php echo esc_attr($player_data->twitter); ?>" target="_blank"
                                                aria-label="X (Twitter)">
                                                <svg class="icon-style quarter-right-space" viewBox="0 0 16 16" width="1em"
                                                    height="1em" aria-hidden="true" focusable="false">
                                                    <use href="#social-x"></use>
                                                </svg>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($player_data->youtube): ?>
                                            <a href="https://www.youtube.com/<?php echo esc_attr($player_data->youtube); ?>"
                                                target="_blank" aria-label="YouTube">
                                                <svg class="icon-style quarter-right-space" viewBox="0 0 16 16" width="1em"
                                                    height="1em" aria-hidden="true" focusable="false">
                                                    <use href="#social-youtube"></use>
                                                </svg>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($player_data->instagram): ?>
                                            <a href="https://www.instagram.com/<?php echo esc_attr($player_data->instagram); ?>"
                                                target="_blank" aria-label="Instagram">
                                                <svg class="icon-style quarter-right-space" viewBox="0 0 16 16" width="1em"
                                                    height="1em" aria-hidden="true" focusable="false">
                                                    <use href="#social-instagram"></use>
                                                </svg>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>

                        </tbody>
                    </table>
                <?php else: ?>
                    <p><?php esc_html_e('Dados do jogador não encontrados.', 'f365'); ?></p>
                <?php endif; ?>
            </div>
            <?php get_template_part('template-parts/template-front-page-posts'); ?>
        </section>



        <section class="right-column-block">
            <?php
            if (is_active_sidebar('post-sidebar')) {
                dynamic_sidebar('post-sidebar');
            } else {
                get_sidebar();
            }
            ?>
        </section>

    </div>

</main>

<?php get_footer();
