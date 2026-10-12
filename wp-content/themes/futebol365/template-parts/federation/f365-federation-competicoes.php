<?php
/**
 * Federation — Competições /federacao/{federation_id}/competicoes
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

get_header();
$sections = futebol365_get_federation_sections();

if ( ! function_exists( 'futebol365_is_comp_active' ) ) {
    function futebol365_is_comp_active( $comp ) {
        if ( ! $comp ) {
            return false;
        }
        $is_active_val = null;
        if ( is_object( $comp ) ) {
            if ( isset( $comp->is_active ) ) {
                $is_active_val = $comp->is_active;
            } elseif ( isset( $comp->active ) ) {
                $is_active_val = $comp->active;
            } elseif ( isset( $comp->IS_ACTIVE ) ) {
                $is_active_val = $comp->IS_ACTIVE;
            }
        }
        if ( $is_active_val === null ) {
            $comp_arr = (array) $comp;
            foreach ( $comp_arr as $k => $v ) {
                $k_clean = strtolower( trim( str_replace( "\0", '', $k ) ) );
                if ( $k_clean === 'is_active' || $k_clean === 'active' || strpos( $k_clean, 'active' ) !== false ) {
                    $is_active_val = $v;
                    break;
                }
            }
        }
        if ( $is_active_val === null ) {
            return true;
        }
        if ( $is_active_val === true || $is_active_val === 1 || $is_active_val === '1' ) {
            return true;
        }
        if ( is_string( $is_active_val ) ) {
            $val_lower = strtolower( trim( $is_active_val ) );
            if ( in_array( $val_lower, [ 't', 'true', 'y', 'yes' ], true ) ) {
                return true;
            }
        }
        return false;
    }
}

$has_active_in_list = false;
if ( ! empty( $comps ) ) {
    foreach ( $comps as $c ) {
        if ( futebol365_is_comp_active( $c ) ) {
            $has_active_in_list = true;
            break;
        }
    }
}

global $wpdb;

$current_post_id = get_the_ID();
//$django_federation_id = get_post_meta( $current_post_id, 'django_federation_id', true );
$django_federation_id = get_legacy_competition_id( $current_post_id );

$federation_id        = intval( $django_federation_id );

if ( function_exists( 'futebol365_get_federation_sections' ) ) {
    $sections = futebol365_get_federation_sections();
}

$fed = $wpdb->get_row( $wpdb->prepare(
    "SELECT f.*, c.shortname AS country_name, pf.country_id AS parent_country_id
     FROM {$wpdb->prefix}federation_federation f
     LEFT JOIN {$wpdb->prefix}country_country c ON f.country_id = c.id
     LEFT JOIN {$wpdb->prefix}federation_federation pf ON f.federation_id = pf.id
     WHERE f.id = %d",
    $federation_id
) );

$comps = [];
if ( $federation_id > 0 ) {
    $comps = $wpdb->get_results( $wpdb->prepare(
        "SELECT fc.id, fc.shortname, fc.is_active, fc.gender, fc.scope, COUNT(c.id) AS count_editions
         FROM {$wpdb->prefix}federation_federationcompetition fc
         JOIN {$wpdb->prefix}competition_competition c ON c.federation_competition_id = fc.id
         WHERE fc.federation_id = %d AND c.is_available
         GROUP BY fc.id, fc.shortname, fc.is_active, fc.gender, fc.scope, fc.order
         ORDER BY fc.order ASC, fc.shortname ASC",
        $federation_id
    ) );
}


$descendents = [];
if ( $federation_id > 0 ) {
    $descendents = $wpdb->get_results( $wpdb->prepare(
        "SELECT f.id, f.shortname, f.longname, f.federation_type, f.country_id,
                pf.country_id AS parent_country_id,
                CASE 
                    WHEN f.federation_type = 'confederation' AND pf.federation_type = 'confederation' THEN
                        (SELECT COUNT(DISTINCT fc.id) 
                         FROM {$wpdb->prefix}federation_federationcompetition fc
                         JOIN {$wpdb->prefix}competition_competition c ON c.federation_competition_id = fc.id
                         WHERE c.is_available AND (
                             fc.federation_id = f.id
                             OR fc.federation_id IN (
                                 SELECT id FROM {$wpdb->prefix}federation_federation WHERE federation_id = f.id
                             )
                         )
                        )
                    ELSE
                        (SELECT COUNT(DISTINCT fc.id) 
                         FROM {$wpdb->prefix}federation_federationcompetition fc
                         JOIN {$wpdb->prefix}competition_competition c ON c.federation_competition_id = fc.id
                         WHERE fc.federation_id = f.id AND c.is_available
                        )
                END AS count_competitions
           FROM {$wpdb->prefix}federation_federation f
           LEFT JOIN {$wpdb->prefix}country_country c ON f.country_id = c.id
           LEFT JOIN {$wpdb->prefix}federation_federation pf ON f.federation_id = pf.id
           WHERE f.federation_id = %d
             AND ( c.is_extinct IS NULL OR c.is_extinct = false OR c.is_extinct = 'f' OR c.is_extinct = '0' OR f.country_id IS NULL )
           ORDER BY f.shortname ASC",
        $federation_id
    ) );
}


$male_national_comps = [];
$female_national_comps = [];
$male_regional_comps = [];
$female_regional_comps = [];
$male_inter_clubs_comps = [];
$female_inter_clubs_comps = [];
$male_inter_nat_comps = [];
$female_inter_nat_comps = [];

if ( ! empty( $comps ) ) {
    foreach ( $comps as $comp ) {
        $gender = $comp->gender;
        $scope  = $comp->scope;

        if ( strpos( $scope, 'national' ) === 0 ) {
            if ( $gender === 'male' ) {
                $male_national_comps[] = $comp;
            } else {
                $female_national_comps[] = $comp;
            }
        } elseif ( strpos( $scope, 'regional' ) === 0 ) {
            if ( $gender === 'male' ) {
                $male_regional_comps[] = $comp;
            } else {
                $female_regional_comps[] = $comp;
            }
        } elseif ( $scope === 'international_clubs' || $scope === 'international_friendly_clubs' ) {
            if ( $gender === 'male' ) {
                $male_inter_clubs_comps[] = $comp;
            } else {
                $female_inter_clubs_comps[] = $comp;
            }
        } elseif ( $scope === 'international_national_team' || $scope === 'international_friendly_nationals' ) {
            if ( $gender === 'male' ) {
                $male_inter_nat_comps[] = $comp;
            } else {
                $female_inter_nat_comps[] = $comp;
            }
        }
    }
}
?>
<main id="primary" class="site-main single-article-page">
    
    <?php
    get_template_part(
        'template-parts/federation/f365-federation-header',
        null,
        array( 
            'post_id'  => $current_post_id,
            'sections' => $sections,
        )
    );?>

    <div class="ink-grid futebol365-container">
        <section class="left-column-block">
            <div class="breadcrumbs-f365">
                <div class="ink-navigation breadcrumbs">
                    <?php yoast_breadcrumb(); ?>
                </div>
            </div>

            <!-- Competições Nacionais -->
            <?php if ( ! empty( $male_national_comps ) || ! empty( $female_national_comps ) ) : ?>
                <h3 class="condensed-400 headerTitle"><?php esc_html_e( 'Competições Nacionais', 'futebol365' ); ?></h3>

                <?php if ( ! empty( $male_national_comps ) ) : ?>
                    <h4 class="condensed-700 uppercase silver-bar-width-titles"><?php esc_html_e( 'Futebol Masculino', 'futebol365' ); ?></h4>
                    <div class="column-group half-gutters bottom-space">
                        <?php foreach ( $male_national_comps as $comp ) : 
                            $is_active = $has_active_in_list ? futebol365_is_comp_active( $comp ) : true;
                            ?>
                            <div class="all-50 xlarge-33 small-100 tiny-100">
                                <div class="obj-right-div-no-flex">
                                    <div class="obj-name">
                                        <a href="<?php echo home_url();?>/competicao/<?php echo esc_attr( $comp->id ); ?>/"><?php echo esc_html( $comp->shortname ); ?></a>
                                    </div>
                                    <div class="obj-link">
                                        <?php echo esc_html( $comp->count_editions . ' ' . ( $comp->count_editions == 1 ? 'edição' : 'edições' ) ); ?>
                                        <?php if ( ! $is_active ) : ?>
                                            | <i class="font-red"><?php esc_html_e( 'Extinta', 'futebol365' ); ?></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $female_national_comps ) ) : ?>
                    <h4 class="condensed-700 uppercase silver-bar-width-titles"><?php esc_html_e( 'Futebol Feminino', 'futebol365' ); ?></h4>
                    <div class="column-group half-gutters bottom-space">
                        <?php foreach ( $female_national_comps as $comp ) : 
                            $comp_arr = (array) $comp;
                            $is_active_val = null;
                            foreach ( $comp_arr as $k => $v ) {
                                if ( strcasecmp( $k, 'is_active' ) === 0 ) {
                                    $is_active_val = $v;
                                    break;
                                }
                            }
                            $is_active = futebol365_is_comp_active( $comp );
                            ?>
                            <div class="all-50 xlarge-33 small-100 tiny-100">
                                <div class="obj-right-div-no-flex">
                                    <div class="obj-name">
                                        <a href="<?php echo home_url();?>/competicao/<?php echo esc_attr( $comp->id ); ?>/"><?php echo esc_html( $comp->shortname ); ?></a>
                                    </div>
                                    <div class="obj-link">
                                        <?php echo esc_html( $comp->count_editions . ' ' . ( $comp->count_editions == 1 ? 'edição' : 'edições' ) ); ?>
                                        <?php if ( ! $is_active ) : ?>
                                            | <i class="font-red"><?php esc_html_e( 'Extinta', 'futebol365' ); ?></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Competições Regionais -->
            <?php if ( ! empty( $male_regional_comps ) || ! empty( $female_regional_comps ) ) : ?>
                <h3 class="condensed-400 headerTitle">Competições Regionais</h3>

                <?php if ( ! empty( $male_regional_comps ) ) : ?>
                    <h4 class="condensed-700 uppercase silver-bar-width-titles"><?php esc_html_e( 'Futebol Masculino', 'futebol365' ); ?></h4>
                    <div class="column-group half-gutters bottom-space">
                        <?php foreach ( $male_regional_comps as $comp ) : 
                            $comp_arr = (array) $comp;
                            $is_active_val = null;
                            foreach ( $comp_arr as $k => $v ) {
                                if ( strcasecmp( $k, 'is_active' ) === 0 ) {
                                    $is_active_val = $v;
                                    break;
                                }
                            }
                            $is_active = futebol365_is_comp_active( $comp );
                            ?>
                            <div class="all-50 xlarge-33 small-100 tiny-100">
                                <div class="obj-right-div-no-flex">
                                    <div class="obj-name">
                                        <a href="<?php echo home_url();?>/competicao/<?php echo esc_attr( $comp->id ); ?>/"><?php echo esc_html( $comp->shortname ); ?></a>
                                    </div>
                                    <div class="obj-link">
                                        <?php echo esc_html( $comp->count_editions . ' ' . ( $comp->count_editions == 1 ? 'edição' : 'edições' ) ); ?>
                                        <?php if ( ! $is_active ) : ?>
                                            | <i class="font-red">Extinta</i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $female_regional_comps ) ) : ?>
                    <h4 class="condensed-700 uppercase silver-bar-width-titles"><?php esc_html_e( 'Futebol Feminino', 'futebol365' ); ?></h4>
                    <div class="column-group half-gutters bottom-space">
                        <?php foreach ( $female_regional_comps as $comp ) : 
                            $comp_arr = (array) $comp;
                            $is_active_val = null;
                            foreach ( $comp_arr as $k => $v ) {
                                if ( strcasecmp( $k, 'is_active' ) === 0 ) {
                                    $is_active_val = $v;
                                    break;
                                }
                            }
                            $is_active = futebol365_is_comp_active( $comp );
                            ?>
                            <div class="all-50 xlarge-33 small-100 tiny-100">
                                <div class="obj-right-div-no-flex">
                                    <div class="obj-name">
                                        <a href="<?php echo home_url();?>/competicao/<?php echo esc_attr( $comp->id ); ?>/"><?php echo esc_html( $comp->shortname ); ?></a>
                                    </div>
                                    <div class="obj-link">
                                        <?php echo esc_html( $comp->count_editions . ' ' . ( $comp->count_editions == 1 ? 'edição' : 'edições' ) ); ?>
                                        <?php if ( ! $is_active ) : ?>
                                            | <i class="font-red"><?php esc_html_e( 'Extinta', 'futebol365' ); ?></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Competições Internacionais de Clubes -->
            <?php if ( ! empty( $male_inter_clubs_comps ) || ! empty( $female_inter_clubs_comps ) ) : ?>
                <h3 class="condensed-400 headerTitle"><?php esc_html_e( 'Competições Internacionais de Clubes', 'futebol365' ); ?></h3>

                <?php if ( ! empty( $male_inter_clubs_comps ) ) : ?>
                    <h4 class="condensed-700 uppercase silver-bar-width-titles"><?php esc_html_e( 'Futebol Masculino', 'futebol365' ); ?></h4>
                    <div class="column-group half-gutters bottom-space">
                        <?php foreach ( $male_inter_clubs_comps as $comp ) : 
                            $comp_arr = (array) $comp;
                            $is_active_val = null;
                            foreach ( $comp_arr as $k => $v ) {
                                if ( strcasecmp( $k, 'is_active' ) === 0 ) {
                                    $is_active_val = $v;
                                    break;
                                }
                            }
                            $is_active = futebol365_is_comp_active( $comp );
                            ?>
                            <div class="all-50 xlarge-33 small-100 tiny-100">
                                <div class="obj-right-div-no-flex">
                                    <div class="obj-name">
                                        <a href="<?php echo home_url();?>/competicao/<?php echo esc_attr( $comp->id ); ?>/"><?php echo esc_html( $comp->shortname ); ?></a>
                                    </div>
                                    <div class="obj-link">
                                        <?php echo esc_html( $comp->count_editions . ' ' . ( $comp->count_editions == 1 ? 'edição' : 'edições' ) ); ?>
                                        <?php if ( ! $is_active ) : ?>
                                            | <i class="font-red"><?php esc_html_e( 'Extinta', 'futebol365' ); ?></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $female_inter_clubs_comps ) ) : ?>
                    <h4 class="condensed-700 uppercase silver-bar-width-titles"><?php esc_html_e( 'Futebol Feminino', 'futebol365' ); ?></h4>
                    <div class="column-group half-gutters bottom-space">
                        <?php foreach ( $female_inter_clubs_comps as $comp ) : 
                            $comp_arr = (array) $comp;
                            $is_active_val = null;
                            foreach ( $comp_arr as $k => $v ) {
                                if ( strcasecmp( $k, 'is_active' ) === 0 ) {
                                    $is_active_val = $v;
                                    break;
                                }
                            }
                            $is_active = futebol365_is_comp_active( $comp );
                            ?>
                            <div class="all-50 xlarge-33 small-100 tiny-100">
                                <div class="obj-right-div-no-flex">
                                    <div class="obj-name">
                                        <a href="<?php echo home_url();?>/competicao/<?php echo esc_attr( $comp->id ); ?>/"><?php echo esc_html( $comp->shortname ); ?></a>
                                    </div>
                                    <div class="obj-link">
                                        <?php echo esc_html( $comp->count_editions . ' ' . ( $comp->count_editions == 1 ? 'edição' : 'edições' ) ); ?>
                                        <?php if ( ! $is_active ) : ?>
                                            | <i class="font-red"><?php esc_html_e( 'Extinta', 'futebol365' ); ?></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Competições Internacionais de Selecções -->
            <?php if ( ! empty( $male_inter_nat_comps ) || ! empty( $female_inter_nat_comps ) ) : ?>
                <h3 class="condensed-400 headerTitle"><?php echo esc_html__( 'Competições Internacionais de Selecções', 'futebol365' ); ?></h3>

                <?php if ( ! empty( $male_inter_nat_comps ) ) : ?>
                    <h4 class="condensed-700 uppercase silver-bar-width-titles"><?php esc_html_e( 'Futebol Masculino', 'futebol365' ); ?></h4>
                    <div class="column-group half-gutters bottom-space">
                        <?php foreach ( $male_inter_nat_comps as $comp ) : 
                            $comp_arr = (array) $comp;
                            $is_active_val = null;
                            foreach ( $comp_arr as $k => $v ) {
                                if ( strcasecmp( $k, 'is_active' ) === 0 ) {
                                    $is_active_val = $v;
                                    break;
                                }
                            }
                            $is_active = futebol365_is_comp_active( $comp );
                            ?>
                            <div class="all-50 xlarge-33 small-100 tiny-100">
                                <div class="obj-right-div-no-flex">
                                    <div class="obj-name">
                                        <a href="<?php echo home_url();?>/competicao/<?php echo esc_attr( $comp->id ); ?>/"><?php echo esc_html( $comp->shortname ); ?></a>
                                    </div>
                                    <div class="obj-link">
                                        <?php echo esc_html( $comp->count_editions . ' ' . ( $comp->count_editions == 1 ? 'edição' : 'edições' ) ); ?>
                                        <?php if ( ! $is_active ) : ?>
                                            | <i class="font-red"><?php esc_html_e( 'Extinta', 'futebol365' ); ?></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $female_inter_nat_comps ) ) : ?>
                    <h4 class="condensed-700 uppercase silver-bar-width-titles"><?php esc_html_e( 'Futebol Feminino', 'futebol365' ); ?></h4>
                    <div class="column-group half-gutters bottom-space">
                        <?php foreach ( $female_inter_nat_comps as $comp ) : 
                            $comp_arr = (array) $comp;
                            $is_active_val = null;
                            foreach ( $comp_arr as $k => $v ) {
                                if ( strcasecmp( $k, 'is_active' ) === 0 ) {
                                    $is_active_val = $v;
                                    break;
                                }
                            }
                            $is_active = futebol365_is_comp_active( $comp );
                            ?>
                            <div class="all-50 xlarge-33 small-100 tiny-100">
                                <div class="obj-right-div-no-flex">
                                    <div class="obj-name">
                                        <a href="<?php echo home_url();?>/competicao/<?php echo esc_attr( $comp->id ); ?>/"><?php echo esc_html( $comp->shortname ); ?></a>
                                    </div>
                                    <div class="obj-link">
                                        <?php echo esc_html( $comp->count_editions . ' ' . ( $comp->count_editions == 1 ? 'edição' : 'edições' ) ); ?>
                                        <?php if ( ! $is_active ) : ?>
                                            | <i class="font-red"><?php esc_html_e( 'Extinta', 'futebol365' ); ?></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Member Sub-federations (Descendents) -->
            <?php if ( ! empty( $descendents ) ) : ?>
                <?php if ( $fed && $fed->federation_type === 'confederation' ) : ?>
                    <h3 class="condensed-400 headerTitle"><?php esc_html_e( 'Competições Nacionais de Clubes', 'futebol365' ); ?></h3>
                <?php elseif ( $fed && $fed->federation_type === 'federation' ) : ?>
                    <h3 class="condensed-400 headerTitle"><?php esc_html_e( 'Associações Distritais', 'futebol365' ); ?></h3>
                <?php endif; ?>

                <div class="column-group half-gutters">
                    <?php foreach ( $descendents as $member ) :
                        // Determine flag ID based on type
                        $flag_id = '';
                        if ( $member->federation_type === 'association' ) {
                            $flag_id = $member->parent_country_id;
                        } elseif ( $member->federation_type === 'federation' ) {
                            $flag_id = $member->country_id;
                        } else {
                            $flag_id = 'conf_' . $member->id;
                        }
                        ?>
                        <div class="all-50 xlarge-33 small-100 tiny-100">
                            <div class="obj-right-div">
                                <div class="obj-flag">
                                    <a href="<?php echo home_url();?>/federacao/<?php echo esc_attr( $member->id ); ?>/competicoes">
                                        <i class="flag-icon flag-icon-<?php echo esc_attr( $flag_id ); ?>" title="<?php echo esc_attr( $member->shortname ); ?>"></i>
                                    </a>
                                </div>
                                <div class="obj-detail">
                                    <div class="obj-name">
                                        <a href="<?php echo home_url();?>/federacao/<?php echo esc_attr( $member->id ); ?>/competicoes"><?php echo esc_html( $member->shortname ); ?></a>
                                    </div>
                                    <div class="obj-link">
                                        <?php echo esc_html( $member->count_competitions . ' ' . ( $member->count_competitions == 1 ? 'competição' : 'competições' ) ); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php get_template_part('template-parts/template-front-page-posts'); ?>
        </section>
        <section class="right-column-block">
            <?php
            if ( is_active_sidebar( 'post-sidebar' ) ) {
                dynamic_sidebar( 'post-sidebar' );
            } else {
                get_sidebar();
            }
            ?>
        </section>
    </div>
</main>
<?php
get_footer();