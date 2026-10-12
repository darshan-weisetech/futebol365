<?php
/**
 * Coach — Single Template (Unified Controller)
 *
 * Handles ALL sections: resumo (default), info, noticias.
 * All sections share: get_header/footer, header part, breadcrumbs, sidebar.
 * Only the content area changes.
 *
 * Fixes:
 *  - Issue 1/5: layout never breaks — missing data shows only in content area
 *  - Issue 4: $coach data loaded HERE and passed via get_template_part() $args
 *             so f365-coach-info.php and f365-coach-noticias.php receive it correctly
 *
 * The rewrite file (coach-rewrite.php) routes ALL sections here via template_include.
 * f365-coach-info.php and f365-coach-noticias.php are get_template_part() includes,
 * NOT standalone full-page templates.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

get_header();

$coach_post = f365_get_current_coach_post();

if ( ! $coach_post instanceof WP_Post ) {
    global $wp_query;
    $wp_query->set_404();
    status_header( 404 );
    nocache_headers();
    get_template_part( '404' );
    exit;
}

global $post;
$post = $coach_post;
setup_postdata( $post );

// ── Load data once — all templates read from this array ──────────────────
$coach = f365_get_coach_data( $coach_post->ID );

// ── Section routing ───────────────────────────────────────────────────────
$current_section = (string) get_query_var( 'f365_section' );
$sections        = get_coach_section_functions();

if ( ! isset( $sections[ $current_section ] ) ) {
    $current_section = 'resumo';
}

$current_config = $sections[ $current_section ];

// Template slug for get_template_part: 'resumo', 'info', or 'noticias'
$content_template = $current_config['template'];
?>

<main id="primary" class="site-main single-article-page coach-page">

    <?php // ── Shared: header (photo, name, country, social, nav) ─────────── ?>
    <?php get_template_part(
        'template-parts/coach/f365-coach-header',
        null,
        [
            'coach'           => $coach,
            'current_section' => $current_section,
            'sections'        => $sections,
        ]
    ); ?>

    <div class="ink-grid futebol365-container">

        <section class="left-column-block">

            <?php // ── Shared: breadcrumbs ───────────────────────────────── ?>
                <div class="ink-navigation breadcrumbs">
                    <?php if ( function_exists( 'yoast_breadcrumb' ) ) : ?>
                        <?php yoast_breadcrumb(
                            '<nav class="breadcrumbs-f365" aria-label="' . esc_attr__( 'Breadcrumbs', 'futebol365' ) . '">',
                            '</nav>'
                        ); ?>
                    <?php endif; ?>
                </div>

            <?php // ── Section content — only this area changes ─────────── ?>
            <?php if ( empty( $coach ) ) : ?>
                <p><?php esc_html_e( 'Sem informação disponível.', 'futebol365' ); ?></p>
            <?php else : ?>
                <?php get_template_part(
                    'template-parts/coach/f365-coach-' . $content_template,
                    null,
                    [ 'coach' => $coach ]
                ); ?>
            <?php endif; ?>

            <?php get_template_part('template-parts/template-front-page-posts'); ?>


        </section>

        <?php // ── Shared: sidebar ───────────────────────────────────────── ?>
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
