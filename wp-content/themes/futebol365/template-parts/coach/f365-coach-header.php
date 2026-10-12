<?php
/**
 * Coach Header Template Part
 *
 * File: template-parts/coach/f365-coach-header.php
 *
 * Uses the same HTML structure as f365-president-header.php.
 * All dynamic values from f365_get_coach_data() — no queries here.
 *
 * Receives via get_template_part() $args:
 *   $args['coach']           array  — from f365_get_coach_data()
 *   $args['current_section'] string — active section slug
 *   $args['sections']        array  — from get_coach_section_functions()
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

$coach           = isset( $args['coach'] ) && is_array( $args['coach'] ) ? $args['coach'] : [];
$current_section = isset( $args['current_section'] ) ? (string) $args['current_section'] : 'resumo';
$sections        = isset( $args['sections'] ) && is_array( $args['sections'] ) ? $args['sections'] : [];

if ( empty( $coach ) ) {
    return;
}

// ── Extract variables ─────────────────────────────────────────────────────
$post_id        = (int) ( $coach['post_id']    ?? 0 );
$shortname      = (string) ( $coach['shortname'] ?? '' );
$age            = $coach['age'];
$is_deceased    = (bool) ( $coach['is_deceased'] ?? false );
$photo_url      = (string) ( $coach['photo_url']  ?? '' );
$base_url       = (string) ( $coach['base_url']   ?? '' );
$info_url       = (string) ( $coach['info_url']   ?? '' );
$news_url       = (string) ( $coach['news_url']   ?? '' );

$header_country   = $coach['header_country'] ?? [];
$country_name     = (string) ( $header_country['name']     ?? '' );
$country_flag_url = (string) ( $header_country['flag_url'] ?? '' );
// Issue 2 fix: "{Country} - {Birth Place}" pre-built in data layer
$header_location  = (string) ( $coach['header_location'] ?? $country_name );


$show_nationality_row = (bool) ( $coach['show_nationality_row'] ?? false );
$nationality_data     = $coach['nationality'] ?? [];

$social_links = (array) ( $coach['social_links'] ?? [] );
$website_url  = (string) ( $coach['website_url']  ?? '' );
?>

<div class="ink-grid vertical-space coach-page">

    <?php get_template_part( 'template-parts/template-offer-widget' ); ?>

    <div class="ink-header-f365 competicoes treinador"
         style="background-image: linear-gradient(to left, rgba(250, 250, 250, 0) 40%, rgba(0, 0, 0, 1)), url(<?php echo esc_url( FB_THEME_URL . '/assets/images/coach-bg.jpg' ); ?>);">

        <div class="column-group">
            <div class="align-left all-60 medium-100 small-100 tiny-100 banner-left-content">

                <?php if ( $photo_url ) : ?>
                    <div class="flag">
                        <i class="flag-icon flag-icon-conf_2"
                           title="<?php echo esc_attr( $shortname ); ?>">
                            <img src="<?php echo esc_url( $photo_url ); ?>"
                            onerror="this.onerror=null;this.src='<?php echo esc_url( FB_THEME_URL . '/assets/images/no-image.jpg' ); ?>';"
                            title="<?php echo esc_attr( $shortname ); ?>"
                                 alt="<?php echo esc_attr( $shortname ); ?>">
                        </i>
                    </div>
                <?php endif; ?>

                <div class="headerdata">

                    <?php if ( $shortname ) : ?>
                        <h1><?php echo esc_html( $shortname ); ?></h1>
                    <?php endif; ?>

                    <div>
                        <?php esc_html_e( 'Técnico', 'futebol365' ); ?>
                        <?php if ( $is_deceased ) : ?>
                            <?php echo ', ' . esc_html__( 'Falecido', 'futebol365' ); ?>
                        <?php elseif ( $age !== null ) : ?>
                            <?php printf( ', ' . esc_html__( '%d anos', 'futebol365' ), (int) $age ); ?>
                        <?php endif; ?>
                    </div>

                    <?php if ( $header_location ) : ?>
                        <div>
                            <?php if ( $country_flag_url ) : ?>
                                <i class="flag-bg"
                                   style="background-image: url(<?php echo esc_url( $country_flag_url ); ?>);"
                                   title="<?php echo esc_attr( $country_name ); ?>"></i>
                            <?php endif; ?>
                            <?php echo esc_html( $header_location ); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ( $show_nationality_row && ! empty( $nationality_data['name'] ) ) : ?>
                        <div>
                            <?php if ( ! empty( $nationality_data['flag_url'] ) ) : ?>
                                <i class="flag-bg"
                                   style="background-image: url(<?php echo esc_url( $nationality_data['flag_url'] ); ?>);"
                                   title="<?php echo esc_attr( $nationality_data['name'] ); ?>"></i>
                            <?php endif; ?>
                            <?php echo esc_html( $nationality_data['name'] ); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ( $social_links || $website_url ) : ?>
                        <div>
                            <?php foreach ( $social_links as $link ) :
                                $l_url   = (string) ( $link['url']   ?? '' );
                                $l_label = (string) ( $link['label'] ?? '' );
                                $l_icon  = (string) ( $link['icon']  ?? '' );
                                if ( ! $l_url || ! $l_icon ) continue;
                            ?>
                                <a href="<?php echo esc_url( $l_url ); ?>"
                                   target="_blank" rel="noopener noreferrer"
                                   aria-label="<?php echo esc_attr( $l_label ); ?>">
                                    <svg class="quarter-right-space" viewBox="0 0 16 16"
                                         width="1em" height="1em" aria-hidden="true" focusable="false">
                                        <use href="#<?php echo esc_attr( $l_icon ); ?>"></use>
                                    </svg>
                                </a>
                            <?php endforeach; ?>

                            <?php if ( $website_url ) : ?>
                                <a href="<?php echo esc_url( $website_url ); ?>"
                                   target="_blank" rel="noopener noreferrer"
                                   aria-label="<?php esc_attr_e( 'Website oficial', 'futebol365' ); ?>">
                                    <svg class="quarter-right-space" viewBox="0 0 16 16"
                                         width="1em" height="1em" aria-hidden="true" focusable="false">
                                        <use href="#icon-link"></use>
                                    </svg>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>

    <div class="nav-trigger" id="nav-trigger">
        <span><?php esc_html_e( 'Menu', 'futebol365' ); ?></span>
    </div>

    <nav class="nav-main" id="nav-main">
        <ul>
            <?php foreach ( $sections as $slug => $config ) :
                $is_active = ( $current_section === $slug );
                $tab_url   = match( $slug ) {
                    'resumo'   => $base_url,
                    'info'     => $info_url,
                    'noticias' => $news_url,
                    default    => $base_url,
                };
            ?>
                <li>
                    <a class="<?php echo $is_active ? 'active' : ''; ?>"
                       href="<?php echo esc_url( $tab_url ); ?>">
                        <?php echo esc_html( $config['label'] ); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <nav class="nav-mobile" id="nav-mobile"></nav>

</div>