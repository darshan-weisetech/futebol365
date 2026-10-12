<?php
/**
 * Single President Template — Controller for ALL sections
 *
 * Handles both:
 *   /dirigente/{id}/        → $section = ''     → Resumo content
 *   /dirigente/{id}/info/   → $section = 'info' → Info (Dados Pessoais) content
 *
 * Shared elements rendered ONCE regardless of section:
 *   - get_header() / get_footer()
 *   - President header (photo, name, nav)
 *   - Breadcrumbs
 *   - Sidebar
 *   - Outer page wrapper
 *
 * f365-president-info.php is a thin delegate that simply requires this file.
 * f365-president-header.php is untouched.
 */

defined( 'ABSPATH' ) || exit;

// ── Resolve post ──────────────────────────────────────────────────────────────
$president_post = f365_get_current_president_post();

if ( ! $president_post instanceof WP_Post ) {
    global $wp_query;
    $wp_query->set_404();
    status_header( 404 );
    nocache_headers();
    get_template_part( '404' );
    exit;
}

global $post;
$post = $president_post;
setup_postdata( $post );

// ── Load data once ────────────────────────────────────────────────────────────
$president = f365_get_president_data( $president_post->ID );


if ( empty( $president ) ) {
    global $wp_query;
    $wp_query->set_404();
    status_header( 404 );
    nocache_headers();
    get_template_part( '404' );
    exit;
}

// ── Active section ────────────────────────────────────────────────────────────
$section = (string) get_query_var( 'f365_section' );

get_header();
?>

<main id="primary" class="site-main single-article-page president">

    <?php // ── Shared: President header (photo, name, country, nav) ─────────── ?>
    <?php get_template_part(
        'template-parts/president/f365-president-header',
        null,
        [ 'president' => $president, 'section' => $section ]
    ); ?>

    <div class="ink-grid futebol365-container">

        <section class="left-column-block">

            <?php // ── Shared: Breadcrumbs ────────────────────────────────── ?>
            <div class="breadcrumbs-f365">
            <?php if ( function_exists( 'yoast_breadcrumb' ) ) : ?>
                <?php yoast_breadcrumb(
                    '<div class="ink-navigation breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumbs', 'futebol365' ) . '">',
                    '</div>'
                ); ?>
            <?php endif; ?>
            </div>

            <?php // ── Section content ───────────────────────────────────── ?>
            <?php if ( $section === 'info' ) : ?>

                <?php // ════════════════════════════════════════════════════ ?>
                <?php // INFO — Dados Pessoais                               ?>
                <?php // ════════════════════════════════════════════════════ ?>

                <h5 class="condensed-400 headerTitle green">
                    <?php esc_html_e( 'Dados Pessoais', 'futebol365' ); ?>
                </h5>

                <table class="ink-table ink-table-f365 alternating all-100">
                    <tbody>

                        <tr>
                            <td class="align-right all-35 font-blue">
                                <?php esc_html_e( 'Nome:', 'futebol365' ); ?>
                            </td>
                            <td class="all-65">
                                <?php
                                $full_name = $president['longname'] !== ''
                                    ? $president['longname']
                                    : $president['shortname'];
                                echo esc_html( $full_name ?: '—' );
                                ?>
                            </td>
                        </tr>

                        <?php if ( $president['birth_date'] !== '' ) : ?>
                            <tr>
                                <td class="align-right font-blue">
                                    <?php esc_html_e( 'Data Nascimento:', 'futebol365' ); ?>
                                </td>
                                <td>
                                    <?php
                                    $ts = strtotime( $president['birth_date'] );
                                    echo $ts
                                        ? esc_html( date_i18n( 'd-m-Y', $ts ) )
                                        : esc_html( $president['birth_date'] );
                                    ?>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <tr>
                            <?php if ( $president['is_deceased'] && $president['death_date'] !== '' ) : ?>
                                <td class="align-right font-blue">
                                    <?php esc_html_e( 'Falecido a:', 'futebol365' ); ?>
                                </td>
                                <td>
                                    <?php
                                    $ts = strtotime( $president['death_date'] );
                                    echo $ts
                                        ? esc_html( date_i18n( 'd-m-Y', $ts ) )
                                        : esc_html( $president['death_date'] );
                                    ?>
                                </td>
                            <?php elseif ( $president['age'] !== null ) : ?>
                                <td class="align-right font-blue">
                                    <?php esc_html_e( 'Idade:', 'futebol365' ); ?>
                                </td>
                                <td>
                                    <?php printf(
                                        /* translators: %d = age in years */
                                        esc_html__( '%d anos', 'futebol365' ),
                                        (int) $president['age']
                                    ); ?>
                                </td>
                            <?php else : ?>
                                <td class="align-right font-blue">
                                    <?php esc_html_e( 'Idade:', 'futebol365' ); ?>
                                </td>
                                <td>—</td>
                            <?php endif; ?>
                        </tr>

                        <?php if ( $president['birth_place'] !== '' ) : ?>
                            <tr>
                                <td class="align-right font-blue">
                                    <?php esc_html_e( 'Local Nascimento:', 'futebol365' ); ?>
                                </td>
                                <td><?php echo esc_html( $president['birth_place'] ); ?></td>
                            </tr>
                        <?php endif; ?>

                        <?php
                        $primary_country = $president['naturality']['name'] !== ''
                            ? $president['naturality']
                            : $president['nationality'];
                        ?>
                        <?php if ( $primary_country['name'] !== '' ) : ?>
                            <tr>
                                <td class="align-right font-blue">
                                    <?php esc_html_e( 'Nacionalidade:', 'futebol365' ); ?>
                                </td>
                                <td>
                                    <?php if ( $primary_country['flag_url'] !== '' ) : ?>
                                        <i class="flag-bg"
                                           style="background-image: url(<?php echo esc_url( $primary_country['flag_url'] ); ?>);"
                                           title="<?php echo esc_attr( $primary_country['name'] ); ?>"></i>
                                    <?php endif; ?>
                                    <?php echo esc_html( $primary_country['name'] ); ?>
                                    <?php if ( $president['show_nationality_row'] ) :
                                        $nat = $president['nationality']; ?>
                                        <br>
                                        <?php if ( $nat['flag_url'] !== '' ) : ?>
                                            <i class="flag-bg quarter-top-space"
                                               style="background-image: url(<?php echo esc_url( $nat['flag_url'] ); ?>);"
                                               title="<?php echo esc_attr( $nat['name'] ); ?>"></i>
                                        <?php endif; ?>
                                        <?php echo esc_html( $nat['name'] ); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php if ( ! empty( $president['social_links'] ) || $president['website_url'] !== '' ) : ?>
                            <tr>
                                <td class="align-right font-blue">
                                    <?php esc_html_e( 'Redes Sociais:', 'futebol365' ); ?>
                                </td>
                                <td>
                                    <?php foreach ( $president['social_links'] as $link ) :
                                        $l_url   = (string) ( $link['url']   ?? '' );
                                        $l_label = (string) ( $link['label'] ?? '' );
                                        $l_icon  = (string) ( $link['icon']  ?? '' );
                                        if ( ! $l_url || ! $l_icon ) continue;
                                    ?>
                                        <a href="<?php echo esc_url( $l_url ); ?>"
                                           target="_blank" rel="noopener noreferrer"
                                           aria-label="<?php echo esc_attr( $l_label ); ?>">
                                            <svg class="icon-style quarter-right-space"
                                                 viewBox="0 0 16 16" width="1em" height="1em"
                                                 aria-hidden="true" focusable="false">
                                                <use href="#<?php echo esc_attr( $l_icon ); ?>"></use>
                                            </svg>
                                        </a>
                                    <?php endforeach; ?>
                                    <?php if ( $president['website_url'] !== '' ) : ?>
                                        <a href="<?php echo esc_url( $president['website_url'] ); ?>"
                                           target="_blank" rel="noopener noreferrer"
                                           aria-label="<?php esc_attr_e( 'Website', 'futebol365' ); ?>">
                                            <svg class="icon-style quarter-right-space"
                                                 viewBox="0 0 16 16" width="1em" height="1em"
                                                 aria-hidden="true" focusable="false">
                                                <use href="#icon-link"></use>
                                            </svg>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php if ( $president['linked_coach_id'] > 0 && $president['linked_coach_url'] !== '' ) : ?>
                            <tr>
                                <td class="align-right font-blue">
                                    <?php esc_html_e( 'Outra Função:', 'futebol365' ); ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url( $president['linked_coach_url'] ); ?>"
                                       title="<?php esc_attr_e( 'Técnico', 'futebol365' ); ?>">
                                        <?php esc_html_e( 'Técnico', 'futebol365' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endif; ?>

                    </tbody>
                </table>



            <?php else : ?>

                <?php // ════════════════════════════════════════════════════ ?>
                <?php // RESUMO — Career history (default section)           ?>
                <?php // ════════════════════════════════════════════════════ ?>

                <?php $resume = $president['resume'] ?? []; ?>

                <h5 class="condensed-400 headerTitle green">
                    <?php esc_html_e( 'Resumo', 'futebol365' ); ?>
                </h5>

                <table class="ink-table ink-table-f365 alternating all-100">
                    <thead>
                        <tr>
                            <th class="align-left all-15"><?php esc_html_e( 'Desde', 'futebol365' ); ?></th>
                            <th class="align-left all-15"><?php esc_html_e( 'Até',   'futebol365' ); ?></th>
                            <th class="align-left all-35"><?php esc_html_e( 'Clube', 'futebol365' ); ?></th>
                            <th class="align-left all-35 hide-tiny hide-small"><?php esc_html_e( 'País', 'futebol365' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $resume ) ) : ?>
                            <tr>
                                <td colspan="4" class="align-left">
                                    <h5><?php esc_html_e( 'Sem informação disponível.', 'futebol365' ); ?></h5>
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $resume as $row ) :
                                $flag_html = '';
                                if ( ! empty( $row['country_flag'] ) ) {
                                    $flag_html = '<i class="flag-bg" style="background-image:url('
                                        . esc_url( $row['country_flag'] )
                                        . ');" title="'
                                        . esc_attr( $row['country_name'] )
                                        . '"></i>';
                                }
                            ?>
                                <tr>
                                    <td><?php echo esc_html( $row['from'] ); ?></td>
                                    <td><?php echo esc_html( $row['to'] ); ?></td>
                                    <td>
                                        <?php if ( $row['team_url'] ) : ?>
                                            <a href="<?php echo esc_url( $row['team_url'] ); ?>">
                                                <?php echo esc_html( $row['team_name'] ); ?>
                                            </a>
                                        <?php else : ?>
                                            <?php echo esc_html( $row['team_name'] ); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="hide-tiny hide-small">
                                        <?php echo $flag_html; ?>
                                        <?php echo esc_html( $row['country_name'] ); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

            <?php endif; ?>

             <?php get_template_part('template-parts/template-front-page-posts'); ?>


        </section>

        <?php // ── Shared: Sidebar ───────────────────────────────────────── ?>
        <section class="right-column-block">
            <?php if ( is_active_sidebar( 'post-sidebar' ) ) : ?>
                <?php dynamic_sidebar( 'post-sidebar' ); ?>
            <?php else : ?>
                <?php get_sidebar(); ?>
            <?php endif; ?>
        </section>

    </div>

</main>

<?php
wp_reset_postdata();
get_footer();