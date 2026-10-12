<?php
/**
 * Live Game — single detail  /jogos-em-direto/{django_livematch_id}/
 *
 * Scoreboard, goal scorers, timeline of events, both lineups (starters and
 * substitutes), coaches/formations and live statistics.
 *
 * @package Futebol365
 */
defined('ABSPATH') || exit;
get_header();

$post_id = $args['post_id'] ?? get_the_ID();
$bg_field = get_field('federation_header_bg_image', 'option');
$bg_image_url = $bg_field['url'] ?? futebol365_fallback_image();

$file = get_stylesheet_directory() . '/inc/f365-livegame-function.php';

if (file_exists($file)) {
    require_once $file;
}


?>

<div class="ink-grid vertical-space <?php echo esc_attr($post_id); ?>">

    <?php get_template_part('template-parts/template-offer-widget'); ?>

    <div class="ink-header-f365 competicoes"
        style="background-image: linear-gradient(to left, rgba(250,250,250,0) 40%, rgba(0,0,0,1)), url(<?php echo esc_url($bg_image_url); ?>);">
        <div class="column-group">
            <div class="align-left all-60 medium-100 small-100 tiny-100 banner-left-content">
                <div class="headerdata">

                    <h1 class="multimedia-title">
                        <?php esc_html_e( 'Jogos em direto', 'futebol365' ); ?>
                    </h1>
                </div>
            </div>
        </div>
    </div>

    <div class="nav-trigger" id="nav-trigger"><span>
            <?php esc_html_e('Menu', 'futebol365'); ?>
        </span></div>

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

<?php
        $lm_id      = f365_livegame_resolve_single_id();
        $match_data = f365_sql_get_livematch_details($lm_id);

if ($match_data):
        $post_id = $lm_id; // Mapping to maintain compatibility below (cache keys etc.)

        /* ── Team relations ─────────────────────────────────────────────
         * The SQL returns legacy Django ids (team_team.id). Resolve them to
         * WP post ids so permalinks / logos / names link correctly.
         */
        $home_team_id = (int) ($match_data->home_team_id ?? 0);
        $visitor_team_id = (int) ($match_data->visitor_team_id ?? 0);
        $home_team_type = (string) ($match_data->home_team_type ?? '');
        $visitor_team_type = (string) ($match_data->visitor_team_type ?? '');
        $home_name = $match_data->is_mapped ? $match_data->home_team_shortname : $match_data->home_team_name;
        $visitor_name = $match_data->is_mapped ? $match_data->visitor_team_shortname : $match_data->visitor_team_name;

        if (!$home_name) $home_name = 'Visitado';
        if (!$visitor_name) $visitor_name = 'Visitante';

        /* ── Competition / Stadium ──────────────────────────────────── */
        $competition_name = $match_data->competition_name;
        $stadium_id = $match_data->stadium_id; // legacy id — drives the /estadio/{id}/ rewrite
        $stadium_name = $match_data->stadium_longname;
        $stadium_city = $match_data->stadium_city;
        $tv_name = $match_data->tv_channels;


        /* ── Match meta ─────────────────────────────────────────────── */
        $datetime = $match_data->datetime;
        $status = (string) ($match_data->status ?: '-1');
        $in_live = !in_array($status, array('RF', 'AD', 'SP', '-1'), true);
        $goal_flash = f365_livegame_bool($match_data->goal);
        $home_score = $match_data->home_score !== null ? $match_data->home_score : '-';
        $visitor_score = $match_data->visitor_score !== null ? $match_data->visitor_score : '-';
        $home_extra = (string) ($match_data->home_score_extra ?? '');
        $visitor_extra = (string) ($match_data->visitor_score_extra ?? '');
        $home_penalties = (string) ($match_data->penalty_home_score ?? '');
        $away_penalties = (string) ($match_data->penalty_visitor_score ?? '');

        /* ── Events / Narrations ────────────────────────────────────── */
        $events = f365_sql_get_livematch_events($lm_id);
        $narrations = (array) f365_sql_get_livematch_narrations($lm_id);
        // Event score map + event-row text are prepared in the function file
        // (f365_livegame_event_scores() / f365_livegame_event_text()).
        $f365_event_scores = f365_livegame_event_scores($events);

        /* ── Players (sub-posts) ────────────────────────────────────── */
        $players = f365_sql_get_livematch_players($lm_id);

        /* ── Coaches / Formations ───────────────────────────────────── */
        $coaches = f365_sql_get_livematch_coaches($lm_id);
        $formations = f365_sql_get_livematch_formations($lm_id);

        /* ── Stats ──────────────────────────────────────────────────── */
        $stats = f365_livegame_build_stats($match_data);

        /* ── Derived: goals, team lineups, coaches, formations ──────── */
        $goals          = f365_livegame_split_goals($events);
        $home_goals     = $goals['home'];
        $visitor_goals  = $goals['visitor'];

        $lineups         = f365_livegame_split_players($players);
        $home_players    = $lineups['home_players'];
        $visitor_players = $lineups['visitor_players'];
        $home_subs       = $lineups['home_subs'];
        $visitor_subs    = $lineups['visitor_subs'];

        $coach_split   = f365_livegame_split_coaches($coaches);
        $home_coach    = $coach_split['home'];
        $visitor_coach = $coach_split['visitor'];

        $formation_split = f365_livegame_split_formations($formations);
        $home_formation  = $formation_split['home'];
        $away_formation  = $formation_split['away'];
        /* ── Competition links ───────────────────────────────────────────────
         * Mirrors the Django scoreboard line:
         *   <a href="{competition.get_absolute_url}">{name} {season}</a>
         *   - <a href="{match.get_competition_round_absolute_url}">{round_or_phase}</a>
         *
         *   base : /competicao/{federation_competition_id}/?edition={edition}
         *   round: /competicao/{federation_competition_id}/?edition={edition}&competition_round={match_round}
         *   e.g.   /competicao/572/?edition=2025  and  …&competition_round=33
         */
        $competition_urls        = f365_livegame_competition_urls($match_data);
        $home_team_url           = $competition_urls['home_team_url'];
        $visitor_team_url        = $competition_urls['visitor_team_url'];
        $competition_base_url    = $competition_urls['competition_base_url'];
        $competition_url         = $competition_urls['competition_url'];
        $competition_display     = $competition_urls['competition_display'];
        $competition_round_label = $competition_urls['competition_round_label'];

        // Goal suffix (p.b. / pen.) is now f365_livegame_goal_suffix() and the
        // related videos are fetched via the cached reader, both in the
        // function file.
        $videos = f365_sql_get_livematch_videos((int) $match_data->match_id);

        ?>

        <main id="primary" class="site-main single-livegame-page">
            <div class="ink-grid futebol365-container">
                <section class="left-column-block">

                    <!-- Breadcrumbs -->
                    <div class="breadcrumbs-f365">
                        <?php if (function_exists('yoast_breadcrumb')): ?>
                            <nav class="ink-navigation breadcrumbs">
                                <?php yoast_breadcrumb(); ?>
                            </nav>
                        <?php endif; ?>
                    </div>

                    <!-- Section switcher -->
                    <form class="ink-form ink-form-option f365-livegame-section-form">
                        <div class="column-group">
                            <div class="control-group all-33 tiny-100 small-100">
                                <div class="control">
                                    <label class="screen-reader-text" for="live-matches-section">
                                        <?php esc_html_e('Secção do jogo', 'futebol365'); ?>
                                    </label>
                                    <select id="live-matches-section">
                                        <?php if ($narrations): ?>
                                            <option value="livematch-narration">
                                                <?php esc_html_e('Narração', 'futebol365'); ?>
                                            </option>
                                        <?php endif; ?>
                                        <option value="livematch-events">
                                            <?php esc_html_e('Eventos', 'futebol365'); ?>
                                        </option>
                                        <option value="livematch-players">
                                            <?php esc_html_e('Ficha', 'futebol365'); ?>
                                        </option>
                                        <option value="livematch-stats">
                                            <?php esc_html_e('Estatísticas', 'futebol365'); ?>
                                        </option>
                                        <option value="livematch-videos"><?php esc_html_e('Videos', 'futebol365'); ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </form>

                    <!-- Scoreboard -->
                    <div class="column-group match-div f365-livegame-scoreboard">
                        <div class="all-100 align-center">
                            <h6 class="match-detail">
                                <?php if ($stadium_name): ?>
                                    <a href="<?php echo esc_url(home_url('/estadio/' . (int) $stadium_id . '/')); ?>">
                                        <?php echo esc_html($stadium_name); ?>,
                                    </a>
                                    <?php if ($stadium_city): ?> <?php echo esc_html($stadium_city); ?><?php endif; ?>
                                    - 
                                <?php endif; ?>
                                <?php
                                if ($datetime) {
                                    $timestamp = is_numeric($datetime) ? (int) $datetime : strtotime($datetime);
                                    /* Raw render — livematch.datetime is stored in LOCAL time
                                       (same value as competition_match.date_time, which the jogo
                                       ficha renders raw). wp_date() treated it as UTC and shifted
                                       +2h, making live and ficha pages disagree on kickoff time. */
                                    echo esc_html(date('d-m-Y \à\s H:i', $timestamp));
                                }
                                ?>

                                <?php if ($tv_name): ?> <?php echo esc_html($tv_name); ?><?php endif; ?>
                            </h6>
                            <?php /* TV channel suffix intentionally NOT rendered. */ ?>
                            <?php if ('' !== $competition_display): ?>
                                <h6 class="match-detail">
                                    <?php if ($competition_base_url): ?>
                                        <a href="<?php echo esc_url($competition_base_url); ?>"><?php echo esc_html($competition_display); ?></a>
                                    <?php else: ?>
                                        <?php echo esc_html($competition_display); ?>
                                    <?php endif; ?>
                                    <?php if ('' !== $competition_round_label): ?>
                                        -
                                        <?php if ($competition_url): ?>
                                            <a href="<?php echo esc_url($competition_url); ?>"><?php echo esc_html($competition_round_label); ?> <?php if ( 60058 === (int) $lm_id ) : ?> - 4ª Jornada     <?php endif; ?></a>
                                        <?php else: ?>
                                            <?php echo esc_html($competition_round_label); ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </h6>
                            <?php endif; ?>

                        </div>

                        <div class="all-40 small-30 tiny-30 align-center">
                            <a href="<?php echo esc_url($home_team_url); ?>">
                                <h5 class="match-team-name">
                                    <?php f365_livegame_team_name($home_team_id, $home_name, $home_team_url); ?>
                                </h5>
                            </a>
                        </div>
                        <div class="all-20 small-40 tiny-40 align-center">
                            <h5 class="match-live-time">
                                <?php echo esc_html(f365_livegame_status($status)); ?>
                            </h5>
                        </div>
                        <div class="all-40 small-30 tiny-30 align-center">
                            <a href="<?php echo esc_url($visitor_team_url); ?>">
                                <h5 class="match-team-name">
                                    <?php f365_livegame_team_name($visitor_team_id, $visitor_name, $visitor_team_url); ?>
                                </h5>
                            </a>
                        </div>

                        <div class="all-40 small-30 tiny-30 align-center">
                            <a href="<?php echo esc_url($home_team_url); ?>">
                                <?php f365_livegame_render_team_logo($match_data, 'home', $home_team_url); ?>
                            </a>
                        </div>
                        <div class="all-20 small-40 tiny-40 align-center">
                            <h2 class="match-score">
                                <span
                                    class="ink-badge black no-margin quarter-vertical-padding<?php echo $in_live ? ' font-orange' : ''; ?>">
                                    <?php
                                    if ('' !== $home_penalties && '' !== $away_penalties) {
                                        echo esc_html($home_penalties . ' - ' . $away_penalties . ' g.p');
                                    } elseif ('' !== $home_extra && '' !== $visitor_extra) {
                                        echo esc_html($home_extra . ' - ' . $visitor_extra . ' a.p');
                                    } else {
                                        echo esc_html($home_score . ' - ' . $visitor_score);
                                    }
                                    ?>
                                </span>
                            </h2>
                            <?php if (('' !== $home_extra && '' !== $visitor_extra) || ('' !== $home_penalties && '' !== $away_penalties)): ?>
                                <span class="match-extra-score">[<?php echo esc_html($home_score . ' - ' . $visitor_score); ?>] 90'</span>
                            <?php endif; ?>
                            <?php if ('' !== $home_extra && '' !== $visitor_extra): ?>
                                <span class="match-extra-score">[<?php echo esc_html($home_extra . ' - ' . $visitor_extra); ?>] a.p</span>
                            <?php endif; ?>
                            <?php if ('' !== $home_penalties && '' !== $away_penalties): ?>
                                <span class="match-extra-score">[<?php echo esc_html($home_penalties . ' - ' . $away_penalties); ?>] g.p</span>
                            <?php endif; ?>
                            <?php if ($goal_flash): ?>
                                <span class="match-live-goal-score"><?php esc_html_e('GOLOOO!', 'futebol365'); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="all-40 small-30 tiny-30 align-center">
                            <a href="<?php echo esc_url($visitor_team_url); ?>">
                                <?php f365_livegame_render_team_logo($match_data, 'visitor', $visitor_team_url); ?>
                            </a>
                        </div>

                        <div class="all-40 small-30 tiny-30 align-center half-top-space">
                            <?php foreach ($home_goals as $event): ?>
                                <span class="match-player-goals-scores">
                                    <?php echo esc_html(f365_livegame_value($event, array('player_name', 'player')) . ' ' . f365_livegame_value($event, array('minute')) . "'" . f365_livegame_goal_suffix($event)); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <div class="all-20 small-40 tiny-40 align-center half-top-space"></div>
                        <div class="all-40 small-30 tiny-30 align-center half-top-space">
                            <?php foreach ($visitor_goals as $event): ?>
                                <span class="match-player-goals-scores">
                                    <?php echo esc_html(f365_livegame_value($event, array('player_name', 'player')) . ' ' . f365_livegame_value($event, array('minute')) . "'" . f365_livegame_goal_suffix($event)); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Player Golo FM -->
                    <?php if ($in_live && $match_data->external_audio_narration): ?>
                    <div class="column-group vertical-space">
                        <div class="all-100">
                            <div class="jVzQRR">
                                <div class="hOoEix">
                                    <div class="WPhZV">
                                        <div class="jTjVKK">
                                            <div class="jgmTwE">
                                                <div class="cuHQPn">
                                                    <div class="ijnwJt">
                                                        <a href="https://golo.fm/" target="_blank"><img class="bKmYOd" src="<?php echo esc_url(get_template_directory_uri() . '/website/img/responsive/golo-fm-logo.png'); ?>" alt="Golo FM" title="Golo FM"></a>
                                                    </div>
                                                </div>
                                                <div class="hMVzIl">
                                                    <div class="bHqlOR">Ouvir o relato em direto</div>
                                                    <div class="eFQtqG">
                                                        <div class="audio-player">
                                                            <audio src="https://sp0.redeaudio.com/8154/live" id="audio"></audio>
                                                            <div class="controls">
                                                                <div class="play-container">
                                                                    <div class="toggle-play play"></div>
                                                                </div>
                                                                <div class="volume-container">
                                                                    <div class="volume-button">
                                                                        <div class="volume icono-volumeMedium"></div>
                                                                    </div>
                                                                    <div class="volume-slider">
                                                                        <div class="volume-percentage"></div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Tabbed content -->
                    <div id="live-match-details-content">

                        <!-- Narration -->
                        <section id="livematch-narration" class="f365-livegame-section">
                            <h5 class="condensed-400 headerTitle">
                                <?php esc_html_e('Narração', 'futebol365'); ?> <span class="headerTitle-border"></span>
                            </h5>
                            <?php if ($narrations): ?>
                                <div class="overflow-auto">
                                    <table class="ink-table ink-table-f365 alternating all-100">
                                        <thead>
                                            <tr>
                                                <th class="align-center all-10">Min</th>
                                                <th class="align-left all-90">Ocorrência</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($narrations as $narration): ?>
                                                <tr>
                                                    <td class="align-center">
                                                        <b>
                                                            <?php echo esc_html($narration['minute'] . "'" . (!empty($narration['minute_extra']) ? ' + ' . $narration['minute_extra'] : '')); ?>
                                                        </b>
                                                    </td>
                                                    <td>
                                                        <?php
                                                            $n_event = f365_livegame_value($narration, array('event'));
                                                            $n_icon  = $n_event ? f365_livegame_event_icon($n_event) : '';
                                                            if ($n_icon):
                                                            ?>
                                                                <span class="<?php echo esc_attr(sanitize_html_class($n_icon)); ?> event-type-right-padding"></span>
                                                            <?php endif; ?>
                                                            <?php echo wp_kses_post(f365_livegame_value($narration, array('description'))); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <h6 class="no-info double-bottom-padding">
                                    <?php esc_html_e('Sem informação disponível', 'futebol365'); ?>
                                </h6>
                            <?php endif; ?>
                        </section>

                        <!-- Events -->
                        <section id="livematch-events" class="f365-livegame-section">
                            <h5 class="condensed-400 extralarge modtitle">
                                <?php esc_html_e('Eventos', 'futebol365'); ?> <span class="modtitle-border"></span>
                            </h5>
                            <?php if ($events): ?>
                                <div class="overflow-auto">
                                    <table class="ink-table ink-table-f365 alternating">
                                        <thead>
                                            <tr>
                                                <th class="align-center all-10">
                                                    <?php esc_html_e('Min', 'futebol365'); ?>
                                                </th>
                                                <th class="align-left all-90">
                                                    <?php esc_html_e('Ocorrência', 'futebol365'); ?>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_reverse($events) as $event):
                                                $event_type = f365_livegame_value($event, array('event_type', 'type'));
                                                $event_home = f365_livegame_bool(f365_livegame_value($event, array('is_home_team', 'home')));
                                                $event_team = $event_home ? $home_name : $visitor_name;
                                                ?>
                                                <tr>
                                                    <td class="align-center">
                                                        <b>
                                                            <?php echo esc_html(f365_livegame_value($event, array('minute')) . "'"); ?>
                                                        </b>
                                                    </td>
                                                    <td>
                                                        <span
                                                            class="<?php echo esc_attr(sanitize_html_class($event_type)); ?> event-type-right-padding"></span>
                                                        <?php
                                                            $event_text = f365_livegame_event_text($event, $home_name, $visitor_name, $f365_event_scores);

                                                            $event_text = preg_replace(
                                                                '/\((\d+\-\d+)\)/',
                                                                '<b>($1)</b>',
                                                                esc_html($event_text)
                                                            );

                                                            echo wp_kses($event_text, array(
                                                                'b' => array(),
                                                            ));
                                                            ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <h6 class="no-info double-bottom-padding">
                                    <?php esc_html_e('Sem informação disponível', 'futebol365'); ?>
                                </h6>
                            <?php endif; ?>
                        </section>

                        <!-- Players / Lineups -->
                        <section id="livematch-players" class="f365-livegame-section">
                            <?php if ($players): ?>
                                <div class="column-group half-horizontal-gutters">
                                    <?php
                                    $team_columns = array(
                                        array($home_name, $home_formation, $home_players, $home_subs, $home_coach, 'blue'),
                                        array($visitor_name, $away_formation, $visitor_players, $visitor_subs, $visitor_coach, 'green'),
                                    );
                                    foreach ($team_columns as $team_column):
                                        list($team_name, $formation, $starters, $substitutes, $coach, $colour) = $team_column;
                                        ?>
                                        <div class="all-50 small-100 tiny-100">
                                            <h5 class="condensed-400 headerTitle <?php echo esc_attr($colour); ?>">
                                                <?php echo esc_html($team_name); ?>
                                                <?php if ($formation): ?>
                                                    <i class="push-right half-right-padding">
                                                        <?php echo esc_html($formation); ?>
                                                    </i>
                                                <?php endif; ?>
                                            </h5>
                                            <div class="overflow-auto">
                                                <table class="ink-table ink-table-f365 alternating all-100">
                                                    <thead>
                                                        <tr>
                                                            <th class="align-center all-10">
                                                                <?php esc_html_e('Nº', 'futebol365'); ?>
                                                            </th>
                                                            <th class="align-left all-90">
                                                                <?php esc_html_e('Jogador', 'futebol365'); ?>
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($starters as $player): ?>
                                                            <tr>
                                                                <td class="align-center">
                                                                    <span
                                                                        class="ink-label ink-label-number <?php echo esc_attr($colour); ?>">
                                                                        <?php echo esc_html(f365_livegame_value($player, array('player_number', 'number'), '-')); ?>
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    <?php echo esc_html(f365_livegame_value($player, array('player_name', 'name'))); ?>
                                                                    <?php foreach (f365_livegame_array(f365_livegame_value($player, array('player_events'), array())) as $player_event): ?>
                                                                        <span class="f365-livegame-player-event">
                                                                            <?php echo esc_html(f365_livegame_value($player_event, array('minute')) . "' " . f365_livegame_event_label(f365_livegame_value($player_event, array('event_type')))); ?>
                                                                        </span>
                                                                    <?php endforeach; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <?php if ($substitutes): ?>
                                                <h5 class="condensed-400 headerTitle <?php echo esc_attr($colour); ?>">
                                                    <?php esc_html_e('Suplentes', 'futebol365'); ?>
                                                </h5>
                                                <div class="overflow-auto">
                                                    <table class="ink-table ink-table-f365 alternating all-100">
                                                        <tbody>
                                                            <?php foreach ($substitutes as $player): ?>
                                                                <tr>
                                                                    <td class="align-center all-10">
                                                                        <span
                                                                            class="ink-label ink-label-number <?php echo esc_attr($colour); ?>">
                                                                            <?php echo esc_html(f365_livegame_value($player, array('player_number', 'number'), '-')); ?>
                                                                        </span>
                                                                    </td>
                                                                    <td class="all-90">
                                                                        <?php echo esc_html(f365_livegame_value($player, array('player_name', 'name'))); ?>
                                                                        <?php foreach (f365_livegame_array(f365_livegame_value($player, array('player_events'), array())) as $player_event): ?>
                                                                            <span class="f365-livegame-player-event">
                                                                                <?php echo esc_html(f365_livegame_value($player_event, array('minute')) . "' " . f365_livegame_event_label(f365_livegame_value($player_event, array('event_type')))); ?>
                                                                            </span>
                                                                        <?php endforeach; ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($coach): ?>
                                                <h5 class="condensed-400 headerTitle <?php echo esc_attr($colour); ?>">
                                                    <?php esc_html_e('Equipa Técnica', 'futebol365'); ?>
                                                </h5>
                                                <div class="overflow-auto">
                                                    <table class="ink-table ink-table-f365 alternating">
                                                        <tbody>
                                                            <tr>
                                                                <td class="align-center all-10">
                                                                    <span
                                                                        class="ink-label ink-label-number <?php echo esc_attr($colour); ?>">T</span>
                                                                </td>
                                                                <td>
                                                                    <?php echo esc_html($coach); ?>
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <h5 class="condensed-400 extralarge modtitle">
                                    <?php esc_html_e('Ficha do Jogo', 'futebol365'); ?> <span class="modtitle-border"></span>
                                </h5>
                                <h6 class="no-info double-bottom-padding">
                                    <?php esc_html_e('Sem informação disponível', 'futebol365'); ?>
                                </h6>
                            <?php endif; ?>
                        </section>

                        <!-- Statistics -->
                        <section id="livematch-stats" class="f365-livegame-section">
                            <h5 class="condensed-400 extralarge modtitle">
                                <?php esc_html_e('Estatísticas', 'futebol365'); ?> <span class="modtitle-border"></span>
                            </h5>
                            <?php
                            $stat_rows = array(
                                'ball_possession' => 'Posse de bola (%)',
                                'shots' => 'Remates',
                                'shots_on_goal' => 'Remates à baliza',
                                'fouls' => 'Faltas',
                                'corner_kicks' => 'Cantos',
                                'offsides' => 'Foras-de-jogo',
                                'saves' => 'Defesas do guarda-redes',
                                'throw_ins' => 'Lançamentos',
                                'free_kicks' => 'Livres',
                                'goals' => 'Golos',
                                'goals_penalty' => 'Golos de Penálti',
                                'own_goals' => 'Autogolos',
                                'yellow_cards' => 'Cartões Amarelos',
                                'double_yellow_cards' => 'Acumulação de Amarelos',
                                'red_cards' => 'Cartões Vermelhos',
                                'substitutions' => 'Substituições',
                            );
                            ?>
                            <?php if ($stats): ?>
                                <div class="overflow-auto">
                                    <table class="ink-table ink-table-f365 alternating">
                                        <thead>
                                            <tr>
                                                <th class="align-center all-33">
                                                    <?php echo esc_html($home_name); ?>
                                                </th>
                                                <th class="align-center all-33"></th>
                                                <th class="align-center all-33">
                                                    <?php echo esc_html($visitor_name); ?>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($stat_rows as $stat_key => $stat_label):
                                                $home_stat = f365_livegame_value($stats, array('home_' . $stat_key), null);
                                                $away_stat = f365_livegame_value($stats, array('visitor_' . $stat_key, 'away_' . $stat_key), null);
                                                if (null === $home_stat && null === $away_stat) {
                                                    continue;
                                                }
                                                ?>
                                                <tr>
                                                    <td class="align-center">
                                                        <?php echo esc_html($home_stat); ?>
                                                    </td>
                                                    <td class="align-center">
                                                        <?php echo esc_html($stat_label); ?>
                                                    </td>
                                                    <td class="align-center">
                                                        <?php echo esc_html($away_stat); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <h6 class="no-info double-bottom-padding">
                                    <?php esc_html_e('Sem informação disponível', 'futebol365'); ?>
                                </h6>
                            <?php endif; ?>
                        </section>

                    </div><!-- #live-match-details-content -->
                    
                    <!-- Videos -->
                    <section id="livematch-videos" class="f365-livegame-section">
                        <h5 class="condensed-400 extralarge modtitle">Vídeos <span class="modtitle-border"></span>	</h5>
                        <?php if ( ! empty( $videos ) ) : ?>
                            <div class="column-group gutters">

                                <?php foreach ( $videos as $video ) : ?>

                                    <?php
                                    $video_post_id = $video->id;

                                    $video_page_url = home_url(
                                        '/multimedia/videos/' . $video_post_id . '/'
                                    );
                                    
                                    $image       = ! empty( $video->image ) ? get_site_url() . '/media/' . ltrim( $video->image, '/' ) : '';
                                    $video_url   = $video->url ?? '';
                                    $video_title = $video->title ?? '';
                                    $video_date  = ! empty( $video->datetime ) ? date_i18n( 'd-m-Y', strtotime( $video->datetime ) ) : '';
                                    $video_length = $video->length ?? '';
                                    ?>

                                    <div class="all-25 small-50 tiny-100">

                                        <figure
                                            class="ink-image ink-lazyload half-bottom-space my-thumb-16-9"
                                            data-delta="300"
                                            data-loaded-class="loaded"
                                        >
                                            <a href="<?php echo esc_url( $video_url ); ?>" target="_blank">

                                                <img
                                                    class="lazyload-item my-thumb-image loaded"
                                                    src="<?php echo esc_url( $image ); ?>"
                                                    alt="<?php echo esc_attr( $video_title ); ?>"
                                                    title="<?php echo esc_attr( $video_title ); ?>"
                                                    onerror="this.onerror=null;this.src='<?php echo esc_url( FB_THEME_URL . '/assets/images/no-image-list.jpg' ); ?>';"
                                                >

                                            </a>

                                            <?php if ( ! empty( $video_length ) ) : ?>
                                                <figcaption class="over-bottom dark figcaption-videos">
                                                    <?php echo esc_html( $video_length ); ?>
                                                </figcaption>
                                            <?php endif; ?>

                                        </figure>

                                        <?php if ( ! empty( $video_date ) ) : ?>
                                            <h3 class="quarter-vertical-space medium uppercase condensed-400">
                                                <?php echo esc_html( $video_date ); ?>
                                            </h3>
                                        <?php endif; ?>

                                        <h2 class="large condensed-700">
                                            <a href="<?php echo esc_url( $video_page_url ); ?>" target="_blank">
                                                <?php echo esc_html( $video_title ); ?>
                                            </a>
                                        </h2>

                                    </div>

                                <?php endforeach; ?>

                            </div>
                        <?php else : ?>
                            <h6 class="no-info">Sem informação disponível</h6>
                        <?php endif; ?>
                    </section>

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

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var selector = document.getElementById('live-matches-section');
                var sections = document.querySelectorAll('.f365-livegame-section');

                var showSection = function () {
                    sections.forEach(function (section) {
                        section.style.display = (selector && section.id === selector.value) ? 'block' : 'none';
                    });
                };

                if (selector) {
                    selector.addEventListener('change', showSection);
                    showSection();
                }

                <?php if ($in_live): ?>
                    window.setTimeout(function () { window.location.reload(); }, 30000);
                <?php endif; ?>
            });
        </script>

<?php
else:
    ?>
        <main id="primary" class="site-main single-livegame-page">
            <div class="ink-grid futebol365-container">
                <section class="left-column-block">
                    <h6 class="no-info double-bottom-padding">
                        <?php esc_html_e('Sem informação disponível', 'futebol365'); ?>
                    </h6>
                </section>
            </div>
        </main>
    <?php
endif;

get_footer();