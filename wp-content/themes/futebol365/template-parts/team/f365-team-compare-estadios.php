<?php
/**
 * Team Compare - Stadiums
 * URL: /equipa/{first_team_id}/comparar-com/{second_team_id}/estadios/
 */

defined('ABSPATH') || exit;

get_header();

global $wpdb;

$post_id = $args['post_id'] ?? get_the_ID();

$sections        = futebol365_get_competition_sections();
$current_section = 'estadios';
if ( ! isset( $sections[ $current_section ] ) ) {
    $current_section = 'resumo';
}

$current_config = $sections[ $current_section ];
$level_1_title  = 'Estádios';

$comptn_edition = get_field( 'season', $post_id ) ?: ( get_post_meta( $post_id, 'season', true ) ?: '' );


$ids = f365_compare_get_team_ids_from_url();

$first_team_id  = $ids['first_team_id'];
$second_team_id = $ids['second_team_id'];

$edition = f365_compare_get_param('edition', date('Y'));
if (!f365_compare_valid_edition($edition)) {
    $edition = date('Y');
}

$step1     = f365_compare_get_param('step1', 'principal');
$gender1   = f365_compare_get_param('gender1', 'male');
$modality1 = f365_compare_get_param('modality1', 'football');

$step2     = f365_compare_get_param('step2', 'principal');
$gender2   = f365_compare_get_param('gender2', 'male');
$modality2 = f365_compare_get_param('modality2', 'football');

$first_team  = f365_compare_get_team($first_team_id);
$second_team = f365_compare_get_team($second_team_id);

$teamstep1 = $first_team ? f365_compare_get_teamstep($first_team_id, $edition, $step1, $gender1, $modality1) : null;
$teamstep2 = $second_team ? f365_compare_get_teamstep($second_team_id, $edition, $step2, $gender2, $modality2) : null;

$first_stadium  = f365_compare_stadium_for_team($first_team, $teamstep1);
$second_stadium = f365_compare_stadium_for_team($second_team, $teamstep2);

$first_name  = f365_compare_teamstep_name($first_team, $teamstep1);
$second_name = f365_compare_teamstep_name($second_team, $teamstep2);

$query_args = [
    'edition'   => $edition,
    'step1'     => $step1,
    'gender1'   => $gender1,
    'modality1' => $modality1,
    'step2'     => $step2,
    'gender2'   => $gender2,
    'modality2' => $modality2,
];

$base_compare_url = home_url('/equipa/' . $first_team_id . '/comparar-com/' . $second_team_id . '/');
?>

<main id="primary" class="site-main single-article-page">
    
    <?php
    // get_template_part(
    //     'template-parts/competition/f365-competition-header',
    //     null,
    //     [
    //         'post_id'         => $post_id,
    //         'current_section' => $current_section,
    //         'sections'        => $sections,
    //         'edition'         => $comptn_edition,
    //     ]
    // );
    ?>

    <div class="ink-grid vertical-space">
        <div class="all-100 ink-header-f365 multimedia">
            <div class="column-group">
                <div class="all-70 medium-100 small-100 tiny-100">
                    <div class="headerdata">
                        <h1 class="multimedia-title">Frente-a-frente</h1>
                    </div>
                </div>
            </div>
        </div>
        <nav class="nav-main" id="nav-main">
            <ul>
                <li>
                    <a href="<?php echo esc_url(add_query_arg($query_args, $base_compare_url)); ?>">Resumo</a>
                </li>
                <li>
                    <a href="<?php echo esc_url(add_query_arg($query_args, $base_compare_url . 'confrontos/')); ?>">Confrontos</a>
                </li>
                <li>
                    <a href="<?php echo esc_url(add_query_arg($query_args, $base_compare_url . 'forma/')); ?>">Forma</a>
                </li>
                <li>
                    <a href="<?php echo esc_url(add_query_arg($query_args, $base_compare_url . 'palmares/')); ?>">Palmarés</a>
                </li>
                <li>
                    <a href="<?php echo esc_url(add_query_arg($query_args, $base_compare_url . 'estadios/')); ?>"><strong>Estádios</strong></a>
                </li>
            </ul>
        </nav>
    </div>

    <div class="ink-grid futebol365-container">
            
        <section class="left-column-block">

            <div class="breadcrumbs-f365">
                <div class="ink-navigation breadcrumbs">
                    <span>
                        <span><a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e( 'Home', 'futebol365' ); ?></a></span>
                        <span class="breadcrumb_last" aria-current="page"><?php esc_html_e( 'Frente-a-frente', 'futebol365' ); ?></span>
                    </span>
                </div>
            </div>

            <?php if (!$first_team || !$second_team) : ?>

                <h5 class="no-info"><?php esc_html_e( 'Equipas não encontradas', 'futebol365' ); ?></h5>

            <?php else : ?>

                <div class="column-group match-div">
                    <div class="all-40 small-30 tiny-30 align-center">
                        <h5 class="match-team-name"><?php echo esc_html($first_name); ?></h5>
                    </div>

                    <div class="all-20 small-40 tiny-40 align-center"></div>

                    <div class="all-40 small-30 tiny-30 align-center">
                        <h5 class="match-team-name"><?php echo esc_html($second_name); ?></h5>
                    </div>

                    <div class="all-40 small-30 tiny-30 align-center">
                        <?php
                        $wp_id1 = function_exists('f365_team_resolve_id') ? (int) f365_team_resolve_id($first_team_id) : 0;
                        $logo1_url = function_exists('f365_cmp_team_logo') ? f365_cmp_team_logo($wp_id1, $first_team_id) : ($wp_id1 ? get_the_post_thumbnail_url($wp_id1, 'full') : '');
                        ?>
                        <img class="team-logo" src="<?php echo esc_url($logo1_url ?: (function_exists('futebol365_fallback_image') ? futebol365_fallback_image() : '')); ?>" alt="<?php echo esc_attr($first_name); ?>">
                    </div>

                    <div class="all-20 small-40 tiny-40 align-center">
                        <h2 class="match-score">
                            <span class="ink-badge black no-margin quarter-vertical-padding">VS</span>
                        </h2>
                    </div>

                    <div class="all-40 small-30 tiny-30 align-center">
                        <?php
                        $wp_id2 = function_exists('f365_team_resolve_id') ? (int) f365_team_resolve_id($second_team_id) : 0;
                        $logo2_url = function_exists('f365_cmp_team_logo') ? f365_cmp_team_logo($wp_id2, $second_team_id) : ($wp_id2 ? get_the_post_thumbnail_url($wp_id2, 'full') : '');
                        ?>
                        <img class="team-logo" src="<?php echo esc_url($logo2_url ?: (function_exists('futebol365_fallback_image') ? futebol365_fallback_image() : '')); ?>" alt="<?php echo esc_attr($second_name); ?>">
                    </div>
                </div>

                <h5 class="condensed-400 headerTitle blue">
                    <?php esc_html_e( 'Estádios', 'futebol365' ); ?>
                    <span class="headerTitle-border"></span>
                </h5>

                <div class="column-group">
                    <table class="ink-table ink-table-f365 alternating">
                        <thead>
                            <tr>
                                <th class="align-center all-33"><?php echo esc_html($first_name); ?></th>
                                <th class="align-center all-33"></th>
                                <th class="align-center all-33"><?php echo esc_html($second_name); ?></th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr>
                                <td class="align-center">
                                    <?php if ($first_stadium) : ?>
                                        <a href="<?php echo esc_url(f365_compare_stadium_url($first_stadium)); ?>">
                                            <?php echo f365_compare_stadium_name($first_stadium); ?>
                                        </a>
                                    <?php else : ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td class="align-center"><?php esc_html_e( 'Nome', 'futebol365' ); ?></td>
                                <td class="align-center">
                                    <?php if ($second_stadium) : ?>
                                        <a href="<?php echo esc_url(f365_compare_stadium_url($second_stadium)); ?>">
                                            <?php echo f365_compare_stadium_name($second_stadium); ?>
                                        </a>
                                    <?php else : ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <tr>
                                <td class="align-center"><?php echo $first_stadium ? f365_compare_value($first_stadium->capacity) : '-'; ?></td>
                                <td class="align-center">Capacidade</td>
                                <td class="align-center"><?php echo $second_stadium ? f365_compare_value($second_stadium->capacity) : '-'; ?></td>
                            </tr>

                            <tr>
                                <td class="align-center"><?php echo $first_stadium ? f365_compare_year($first_stadium->opening) : '-'; ?></td>
                                <td class="align-center">Inauguração</td>
                                <td class="align-center"><?php echo $second_stadium ? f365_compare_year($second_stadium->opening) : '-'; ?></td>
                            </tr>

                            <tr>
                                <td class="align-center"><?php echo $first_stadium ? f365_compare_value($first_stadium->city) : '-'; ?></td>
                                <td class="align-center">Local</td>
                                <td class="align-center"><?php echo $second_stadium ? f365_compare_value($second_stadium->city) : '-'; ?></td>
                            </tr>

                            <tr>
                                <td class="align-center"><?php echo $first_stadium ? f365_compare_value($first_stadium->dimensions) : '-'; ?></td>
                                <td class="align-center">Dimensões</td>
                                <td class="align-center"><?php echo $second_stadium ? f365_compare_value($second_stadium->dimensions) : '-'; ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>

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

<?php
get_footer();