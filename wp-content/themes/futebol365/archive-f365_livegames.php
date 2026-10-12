<?php
/**
 * Archive template for f365_livegames  →  /jogos-em-direto/
 *
 * Mirrors Django live.views.live_matches → live/live_matches.html +
 * live/includes/live_matches_content.html.
 *
 * Today's live matches (±2h window), grouped by competition, rendered
 * server-side straight from the wp_-prefixed tables via
 * f365_sql_get_current_livematches() in /inc/f365-livegame-function.php.
 *
 * @package Futebol365
 */
defined('ABSPATH') || exit;

get_header();

$post_id      = $args['post_id'] ?? get_the_ID();
$bg_field = get_field('federation_header_bg_image', 'option');
$bg_image_url = $bg_field['url'] ?? futebol365_fallback_image();

// Load the SQL helper functions.
$file = get_stylesheet_directory() . '/inc/f365-livegame-function.php';
if (file_exists($file)) {
    require_once $file;
}

$status_filter      = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$competition_filter = isset($_GET['competition']) ? absint($_GET['competition']) : 0;
$current_url        = get_post_type_archive_link('f365_livegames');

/* -----------------------------------------------------------------------
 * Data — every query + cache now lives in inc/f365-livegame-function.php so
 * this template only retrieves and renders:
 *   1. Competition dropdown (distinct competitions with matches today)
 *   2. Today's live matches (short shared cache), grouped by competition
 * -------------------------------------------------------------------- */
$competitions = f365_livegame_competitions_today();
$live_matches = f365_livegame_current_matches( $status_filter, $competition_filter );
$grouped      = f365_livegame_group_by_competition( $live_matches );

// Detail page base for per-match links: /jogos-em-direto/{livematch_id}/
$detail_base = trailingslashit( $current_url );
?>

<div class="ink-grid vertical-space <?php echo esc_attr($post_id); ?>">
    <?php get_template_part('template-parts/template-offer-widget'); ?>

    <div class="ink-header-f365 competicoes"
        style="background-image: linear-gradient(to left, rgba(250,250,250,0) 40%, rgba(0,0,0,1)), url(<?php echo esc_url($bg_image_url); ?>);">
        <div class="column-group">
            <div class="align-left all-60 medium-100 small-100 tiny-100 banner-left-content">
                <div class="headerdata">
                    <h1 class="multimedia-title"><?php esc_html_e('Jogos em direto', 'futebol365'); ?></h1>
                </div>
            </div>
        </div>
    </div>

    <div class="nav-trigger" id="nav-trigger"><span><?php esc_html_e('Menu', 'futebol365'); ?></span></div>

    <nav class="nav-main" id="nav-main">
        <ul>
            <li><a href="<?php echo esc_url(get_post_type_archive_link('f365_livegames')); ?>" <?php if (!in_array($_GET['status'] ?? '', ['1', '2', '3', '4'])) echo 'class="active"'; ?>>Todos</a></li>
            <li><a href="<?php echo esc_url(add_query_arg('status', '1', get_post_type_archive_link('f365_livegames'))); ?>" <?php if (($_GET['status'] ?? '') === '1') echo 'class="active"'; ?>>Direto</a></li>
            <li><a href="<?php echo esc_url(add_query_arg('status', '2', get_post_type_archive_link('f365_livegames'))); ?>" <?php if (($_GET['status'] ?? '') === '2') echo 'class="active"'; ?>>Próximos</a></li>
            <li><a href="<?php echo esc_url(add_query_arg('status', '3', get_post_type_archive_link('f365_livegames'))); ?>" <?php if (($_GET['status'] ?? '') === '3') echo 'class="active"'; ?>>Terminados</a></li>
            <li><a href="<?php echo esc_url(add_query_arg('status', '4', get_post_type_archive_link('f365_livegames'))); ?>" <?php if (($_GET['status'] ?? '') === '4') echo 'class="active"'; ?>>Meus Jogos<span class="totalJogos">(0)</span></a></li>
        </ul>
    </nav>
    <nav class="nav-mobile" id="nav-mobile"></nav>
</div>

<div class="ink-grid futebol365-container">
    <section class="left-column-block">
        <div class="breadcrumbs-f365">
            <?php if (function_exists('yoast_breadcrumb')): ?>
                <nav class="ink-navigation breadcrumbs">
                    <?php yoast_breadcrumb(); ?>
                </nav>
            <?php endif; ?>
        </div>

        <!-- Competition filter (server-side GET, mirrors the Django dropdown) -->
        <form class="ink-form ink-form-option" method="get" id="f365-livegames-filters">
            <?php if ($status_filter): ?>
                <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
            <?php endif; ?>
            <div class="column-group">
                <div class="control-group all-33 tiny-100 small-100">
                    <div class="control">
                        <label class="screen-reader-text" for="competition_select"><?php esc_html_e('Competição', 'futebol365'); ?></label>
                        <select id="competition_select" name="competition" onchange="this.form.submit()">
                            <option value=""><?php esc_html_e('Competição', 'futebol365'); ?></option>
                            <?php foreach ($competitions as $comp): ?>
                                <option value="<?php echo esc_attr($comp->id); ?>" <?php selected($competition_filter, $comp->id); ?>>
                                    <?php echo esc_html($comp->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </form>

        <!-- Live matches list, grouped by competition -->
        <div id="live-matches-content" class="f365-livegames-list">
            <?php if (!empty($grouped)): ?>
                <?php foreach ($grouped as $competition): ?>
                    <h4 class="condensed-400 headerTitle blue live-tit"><?php echo esc_html($competition['name']); ?></h4>
                    <div class="overflow-auto">
                        <table class="ink-table alternating ink-table-f365 double-bottom-space live-tab">
                            <thead>
                                <tr>
                                    <th class="all-5 align-center"></th>
                                    <th class="all-5 align-center hide-tiny hide-small"><?php esc_html_e('Hora', 'futebol365'); ?></th>
                                    <th class="all-10 hide-medium hide-large hide-xlarge"></th>
                                    <th class="all-30 align-right hide-tiny hide-small"><?php esc_html_e('Visitado', 'futebol365'); ?></th>
                                    <th class="all-45 align-left hide-medium hide-large hide-xlarge"><?php esc_html_e('Jogo', 'futebol365'); ?></th>
                                    <th class="all-10 tiny-20 small-20"></th>
                                    <th class="all-30 align-left hide-tiny hide-small"><?php esc_html_e('Visitante', 'futebol365'); ?></th>
                                    <th class="all-5 hide-tiny hide-small"></th>
                                    <th class="all-15 tiny-20 small-20"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($competition['matches'] as $lm):
                                    $status      = (string) ($lm->status ?: '-1');
                                    $not_started = in_array($status, array('AD', 'SP', '-1'), true);
                                    $in_live     = !in_array($status, array('RF', 'AD', 'SP', '-1'), true);
                                    $goal_flash  = f365_livegame_bool($lm->goal);

                                    $home_name = $lm->is_mapped ? $lm->home_team_shortname : $lm->home_team_name;
                                    $away_name = $lm->is_mapped ? $lm->visitor_team_shortname : $lm->visitor_team_name;
                                    if (!$home_name) $home_name = 'Visitado';
                                    if (!$away_name) $away_name = 'Visitante';

                                    $time      = $lm->datetime ? date_i18n('H:i', strtotime($lm->datetime)) : '';
                                    $status_lbl = f365_livegame_status($status);
                                    $result     = f365_livegame_result_text($lm);
                                    $detail_url = esc_url($detail_base . (int) $lm->livematch_id . '/');
                                    ?>
                                    <tr id="tr<?php echo (int) $lm->livematch_id; ?>"<?php echo $goal_flash ? ' class="match-live-time-goal-background"' : ''; ?>>
                                        <td class="align-center"></td>
                                        <td class="align-left hide-tiny hide-small"><?php echo esc_html($time); ?></td>
                                        <td class="align-left hide-medium hide-large hide-xlarge">
                                            <span class="match-live-time-litle"><?php echo esc_html($not_started ? $time : $status_lbl); ?></span>
                                        </td>

                                        <td class="align-right hide-tiny hide-small">
                                            <?php f365_livegame_team_name($lm->home_team_id, $home_name); ?>
                                        </td>

                                        <td class="align-left hide-xlarge hide-large hide-medium">
                                            <?php f365_livegame_team_name($lm->home_team_id, $home_name); ?>
                                            <span class="break"><?php f365_livegame_team_name($lm->visitor_team_id, $away_name); ?></span>
                                        </td>

                                        <td class="align-center">
                                            <span class="ink-label ink-label-score silver">
                                                <a href="<?php echo $detail_url; ?>"<?php echo $in_live ? ' class="live"' : ''; ?>><?php echo esc_html($result); ?></a>
                                            </span>
                                        </td>

                                        <td class="align-left hide-tiny hide-small">
                                            <?php f365_livegame_team_name($lm->visitor_team_id, $away_name); ?>
                                        </td>

                                        <td class="align-center hide-tiny hide-small">
                                            <span class="match-live-time-litle"><?php echo esc_html($status_lbl); ?></span>
                                        </td>

                                        <td>
                                            <?php if ($goal_flash): ?>
                                                <span class="match-live-time-goal"><b><?php esc_html_e('GOLO', 'futebol365'); ?></b></span>
                                            <?php else: ?>
                                                <a class="ink-tooltip ink-button-related red" href="<?php echo $detail_url; ?>"><?php esc_html_e('DETALHES', 'futebol365'); ?></a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <h5 class="condensed-400 headerTitle blue"><?php esc_html_e('Jogos em direto', 'futebol365'); ?></h5>
                <h6 class="no-info double-bottom-padding"><?php esc_html_e('Sem jogos disponíveis', 'futebol365'); ?></h6>
            <?php endif; ?>
        </div>

        <?php get_template_part('template-parts/template-front-page-posts'); ?>
    </section>

    <section class="right-column-block">
        <?php if (is_active_sidebar('post-sidebar')) {
            dynamic_sidebar('post-sidebar');
        } else {
            get_sidebar();
        } ?>
    </section>
</div>

<script>
    // Auto-refresh while there are live games on screen (mirrors the 30s Django refresh).
    <?php
    $has_live = f365_livegame_has_live( $grouped );
    if ($has_live):
    ?>
    window.setTimeout(function () { window.location.reload(); }, 30000);
    <?php endif; ?>
</script>

<?php get_footer(); ?>