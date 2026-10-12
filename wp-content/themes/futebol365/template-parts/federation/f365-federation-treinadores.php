<?php
/**
 * Federation — Treinadores
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

get_header();
global $wpdb;

$current_post_id = get_the_ID();
$django_federation_id = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT legacy_id
         FROM {$wpdb->posts}
         WHERE ID = %d",
        $current_post_id
    )
);
$federation_id        = intval( $django_federation_id );
$sections = futebol365_get_federation_sections();

// Definição das tabelas
$fed_table   = $wpdb->prefix . 'federation_federation';
$country_tbl = $wpdb->prefix . 'country_country';
$coach_table = $wpdb->prefix . 'person_coach';  
$person_table = $wpdb->prefix . 'person_person';

$fed = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT f.id, f.shortname, f.longname, f.url, f.facebook, f.twitter, f.youtube,
                f.instagram, f.googleplus, f.country_id, f.foundation, f.federation_id, f.federation_type,
                pf.country_id AS parent_country_id
           FROM {$fed_table} f
           LEFT JOIN {$fed_table} pf ON f.federation_id = pf.id
          WHERE f.id = %d",
        $federation_id
    ),
    OBJECT
);

/* Helpers. */
if ( ! function_exists( 'f365_federation_member_flag_id' ) ) {
    function f365_federation_member_flag_id( $type, $id, $country_id, $parent_country_id ) {
        if ( 'association' === $type ) {
            return ( null !== $parent_country_id && '' !== $parent_country_id ) ? intval( $parent_country_id ) : '';
        }
        if ( 'federation' === $type ) {
            return ( null !== $country_id && '' !== $country_id ) ? intval( $country_id ) : '';
        }
        return 'conf_' . intval( $id );
    }
}

$is_assoc  = $fed ? ( 'association' === $fed->federation_type ) : false;
$is_confed = $fed ? ( 'confederation' === $fed->federation_type ) : false;

$descendents = array();
$coaches     = array();
$total_pages = 1;
$cur_page    = 1;

// Fetch country flag image URLs from f365_country taxonomy terms mapped by django_country_id
$flags_map = array();
$terms = get_terms( array(
    'taxonomy'   => 'f365_country',
    'hide_empty' => false,
) );

if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
    foreach ( $terms as $term ) {
        $django_id = get_term_meta( $term->term_id, 'django_country_id', true );
        if ( $django_id ) {
            $flag_meta = get_term_meta( $term->term_id, 'country_flag', true );
            if ( $flag_meta ) {
                if ( is_numeric( $flag_meta ) ) {
                    $flags_map[$django_id] = wp_get_attachment_image_url( $flag_meta, 'full' );
                } else {
                    $flags_map[$django_id] = $flag_meta;
                }
            }
        }
    }
}

if ( $fed && $is_confed ) {

    // Busca as sub-federações descendentes e calcula a contagem correta para confederações ou nações
    $descendents = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT f.id, f.shortname, f.longname, f.country_id, f.federation_type,
                    CASE 
                        WHEN f.federation_type = 'confederation' THEN
                            (SELECT COUNT(1) 
                             FROM {$coach_table} c 
                             INNER JOIN {$person_table} pers ON c.person_id = pers.id
                             INNER JOIN {$fed_table} sub_f ON pers.nationality_id = sub_f.country_id
                             WHERE sub_f.federation_id = f.id)
                        ELSE
                            (SELECT COUNT(1) 
                             FROM {$coach_table} c 
                             INNER JOIN {$person_table} pers ON c.person_id = pers.id 
                             WHERE pers.nationality_id = f.country_id)
                    END AS count_coachs
               FROM {$fed_table} f
               LEFT JOIN {$country_tbl} c ON f.country_id = c.id
              WHERE f.federation_id = %d
                AND ( c.id IS NULL OR CAST( c.is_extinct AS CHAR ) NOT IN ( '1', 't', 'true' ) )
              ORDER BY f.shortname ASC",
            $federation_id
        ),
        OBJECT
    );

} elseif ( $fed ) {

    // Paginação para a listagem direta de treinadores
    $per_page = 30;
    $cur_page = isset( $_GET['pagina'] ) ? max( 1, intval( $_GET['pagina'] ) ) : 1;

    $total_items = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(1) 
               FROM {$coach_table} c
               INNER JOIN {$person_table} person ON c.person_id = person.id
              WHERE person.nationality_id = %d",
            intval( $fed->country_id )
        )
    );
    $total_pages = max( 1, (int) ceil( $total_items / $per_page ) );
    $cur_page    = min( $cur_page, $total_pages );
    $offset      = ( $cur_page - 1 ) * $per_page;

    if ( $total_items > 0 ) {
        // Seleção trazendo os dados de person juntamente com treinadores
        $coaches = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.person_id, person.shortname, person.birth_place, person.birth_date, person.death_date
                   FROM {$coach_table} c
                   INNER JOIN {$person_table} person ON c.person_id = person.id
                  WHERE person.nationality_id = %d
                  ORDER BY person.shortname ASC
                  LIMIT %d OFFSET %d",
                intval( $fed->country_id ),
                $per_page,
                $offset
            ),
            OBJECT
        );
    }

    $visible      = 3;
    $window_start = max( 1, $cur_page - intval( floor( $visible / 2 ) ) );
    $window_end   = min( $total_pages, $window_start + $visible - 1 );
    $window_start = max( 1, $window_end - $visible + 1 );
}

if ( ! function_exists( 'f365_fed_page_link' ) ) {
    function f365_fed_page_link( $page ) {
        $params = $_GET; // phpcs:ignore WordPress.Security.NonceVerification
        $params = array_map( 'sanitize_text_field', wp_unslash( (array) $params ) );
        if ( $page <= 1 ) {
            unset( $params['pagina'] );
        } else {
            $params['pagina'] = $page;
        }
        $qs = http_build_query( $params );
        return esc_url( ( '' !== $qs ) ? ( '?' . $qs ) : '?' );
    }
}
?>

<main id="primary" class="site-main single-article-page" <?php echo $current_post_id; ?>>
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
    
            <h3 class="condensed-400 headerTitle yellow"><?php esc_html_e( 'Treinadores', 'futebol365' ); ?></h3>

            <?php if ( $is_confed ) : ?>
                <div class="column-group half-gutters">
                    <?php foreach ( $descendents as $federation_member ) :
                        $m_flag  = f365_federation_member_flag_id( $federation_member->federation_type, $federation_member->id, $federation_member->country_id, $fed->country_id );
                        $m_url   = esc_url( home_url( '/federacao/' . intval( $federation_member->id ) . '/treinadores' ) );
                        $m_label = $federation_member->shortname;
                        $m_count = intval( $federation_member->count_coachs );
                        $plural  = ( 1 === $m_count ) ? '' : 'es';

                        // Flag matching from taxonomy meta
                        $member_country_id = ( 'federation' === $federation_member->federation_type ) ? (int) $federation_member->country_id : 0;
                        if ( ! $member_country_id && 'association' === $federation_member->federation_type && $fed ) {
                            $member_country_id = (int) $fed->country_id;
                        }
                        $flag_url = isset( $flags_map[$member_country_id] ) ? $flags_map[$member_country_id] : '';
                        ?>
                        <div class="all-50 xlarge-33 small-100 tiny-100">
                            <div class="obj-right-div">
                                <div class="obj-flag">
                                    <a href="<?php echo $m_url; ?>">
                                        <?php if ( ! empty( $flag_url ) ) : ?>
                                            <i class="flag-icon">
                                                <img src="<?php echo esc_url( $flag_url ); ?>" class="country-flag-icon" alt="" />    
                                            </i>
                                        <?php else : ?>
                                            <i class="flag-icon flag-icon-<?php echo esc_attr( $m_flag ); ?>" title="<?php echo esc_attr( $m_label ); ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </div>
                                <div class="obj-detail">
                                    <div class="obj-name"><a href="<?php echo $m_url; ?>"><?php echo esc_html( $m_label ); ?></a></div>
                                    <div class="obj-link"><?php echo esc_html( $m_count ); ?> <?php esc_html_e( 'treinador', 'futebol365' ); ?><?php echo esc_html( $plural ); ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <?php if ( ! empty( $coaches ) ) : ?>
                    <div class="column-group half-gutters">
                        <?php foreach ( $coaches as $coach ) :
                            $coach_url = esc_url( home_url( '/treinador/' . intval( $coach->id ) . '/' ) );
                            $filename  = $coach->person_id . '_coach.jpg';
                            $img_url   = home_url( 'media/images/person/' . $filename );
                            $noimg     = home_url( 'media/images/person/no-image.gif' ); // Imagem padrão
                            if (file_exists($img_path)) {
								$img_url = esc_url(home_url('media/images/person/' . (int) $player->person_id . '.jpg'));
							} else {
								$img_url = $noimg;
							}
                            // Lógica do get_age Python adaptada para PHP
                            $age_display = '';
                            if ( ! empty( $coach->birth_date ) ) {
                                if ( ! empty( $coach->death_date ) && '0000-00-00' !== $coach->death_date ) {
                                    $age_display = 'Falecido';
                                } else {
                                    $birthDate   = new DateTime( $coach->birth_date );
                                    $today       = new DateTime( 'today' );
                                    $age_display = $birthDate->diff( $today )->y . ' anos';
                                }
                            }
                            ?>
                            <div class="all-50 xlarge-33 small-100 tiny-100">
                                <div class="obj-right-div">
                                    <div class="obj-image-person">
                                        <figure class="ink-image ink-lazyload" data-delta="100">
                                            <a href="<?php echo $coach_url; ?>">
                                                <img src="<?php echo esc_url( $img_url ); ?>" 
                                                     data-src="<?php echo esc_url( $img_url ); ?>" 
                                                     class="lazyload-item" 
                                                     alt="<?php echo esc_attr( $coach->shortname ); ?>" 
                                                     title="<?php echo esc_attr( $coach->shortname ); ?>" 
                                                     onerror="this.onerror=null;this.src='<?php echo esc_url( $noimg ); ?>';" />
                                            </a>
                                        </figure>
                                    </div>
                                    <div class="obj-detail">
                                        <div class="obj-name"><a href="<?php echo $coach_url; ?>"><?php echo esc_html( $coach->shortname ); ?></a></div>
                                        <div class="obj-link">
                                            <?php echo esc_html( $coach->birth_place ); ?>
                                        </div>
                                        <div class="obj-link">
                                            <?php echo esc_html( $age_display ); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ( $total_pages > 1 ) : ?>
                        <?php echo futebol365_render_pagination( $cur_page, $total_pages ); ?>
                    <?php endif; ?>
                <?php else : ?>
                    <h5 class="no-info"><?php esc_html_e( 'Sem informação disponível', 'futebol365' ); ?></h5>
                <?php endif; ?>
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