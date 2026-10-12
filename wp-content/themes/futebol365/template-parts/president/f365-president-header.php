<?php
/**
 * President Header Template Part
 *
 * File: template-parts/president/f365-president-header.php
 *
 * Uses the HTML structure from the approved design (document 10).
 * All dynamic values sourced from f365_get_president_data() — no queries here.
 *
 * Receives via get_template_part() $args:
 *   $args['president'] array  — from f365_get_president_data()
 *   $args['section']   string — '' (resumo) or 'info'
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

// ── Guard ─────────────────────────────────────────────────────────────────────
$president = isset( $args['president'] ) && is_array( $args['president'] )
    ? $args['president']
    : [];

if ( empty( $president ) ) {
    return;
}

$section = isset( $args['section'] ) ? (string) $args['section'] : '';

// ── Extract variables — all from the prepared data array ─────────────────────
$post_id       = (int) ( $president['post_id']    ?? 0 );
$shortname     = (string) ( $president['shortname'] ?? '' );
$age           = $president['age'];          // int|null — pre-computed
$is_deceased   = (bool) ( $president['is_deceased'] ?? false );
$photo_url     = (string) ( $president['photo_url']  ?? '' );
$base_url      = (string) ( $president['base_url']   ?? '' );
$info_url      = (string) ( $president['info_url']   ?? '' );

// Header country (naturality preferred, falls back to nationality)
$header_country = $president['header_country'] ?? [];
$country_name   = (string) ( $header_country['name']     ?? '' );
$country_flag_url = (string) ( $header_country['flag_url'] ?? '' );
$birth_place = (string) ( $president['birth_place']   ?? '' );

// Second nationality (only when naturality ≠ nationality)
$show_nationality_row = (bool) ( $president['show_nationality_row'] ?? false );
$nationality_data     = $president['nationality'] ?? [];

$social_links = (array) ( $president['social_links'] ?? [] );
$website_url  = (string) ( $president['website_url']  ?? '' );

$nav_sections = [
    'details' => esc_html__( 'Resumo',         'futebol365' ),
    'info'    => esc_html__( 'Dados Pessoais', 'futebol365' ),
];
// Rewrite passes '' for main page; nav uses 'details' as the active key
$active_nav = ( $section === '' || $section === 'details' ) ? 'details' : $section;
?>

<div class="ink-grid vertical-space presidents-page">

    <?php get_template_part( 'template-parts/template-offer-widget' ); ?>

    <div class="ink-header-f365 competicoes"
         style="background-image: linear-gradient(to left, rgba(250, 250, 250, 0) 40%, rgba(0, 0, 0, 1)), url(<?php echo esc_url( FB_THEME_URL . '/assets/images/president-bg.jpg' ); ?>);">

        <div class="column-group">
            <div class="align-left all-60 medium-100 small-100 tiny-100 banner-left-content">

                <?php // ── Photo ──────────────────────────────────────────── ?>
                <?php if ( $photo_url ) : ?>
                    <div class="flag hide-small hide-tiny">
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

                    <?php // ── Name ───────────────────────────────────────── ?>
                    <?php if ( $shortname ) : ?>
                        <h1><?php echo esc_html( $shortname ); ?></h1>
                    <?php endif; ?>

                    <?php // ── Role + age/deceased ────────────────────────── ?>
                    <div>
                        <?php esc_html_e( 'Dirigente', 'futebol365' ); ?>
                        <?php if ( $is_deceased ) : ?>
                            <?php echo ', ' . esc_html__( 'Falecido', 'futebol365' ); ?>
                        <?php elseif ( $age !== null ) : ?>
                            <?php
                            printf(
                                /* translators: %d = age in years */
                                ', ' . esc_html__( '%d anos', 'futebol365' ),
                                (int) $age
                            );
                            ?>
                        <?php endif; ?>
                    </div>

                    <?php // ── Country + flag ─────────────────────────────── ?>
                    <?php // flag_url = SVG URL from ACF image field on f365_country term ?>
                    <?php if ( $country_name ) : ?>
                        <div>
                            <?php if ( $country_flag_url ) : ?>
                                <i class="flag-bg"
                                   style="background-image: url(<?php echo esc_url( $country_flag_url ); ?>);"
                                   title="<?php echo esc_attr( $country_name ); ?>"></i>
                            <?php endif; ?>
                            <?php echo esc_html( $country_name ); 
                            if ( ! empty( $birth_place ) ) {
                                echo ' - ' . esc_html( $birth_place );
                            }
                            ?> 
                            
                        </div>
                    <?php endif; ?>

                    <?php // Second nationality row (naturality ≠ nationality) ?>
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

                    <?php // ── Social icons ───────────────────────────────── ?>
                    <?php if ( $social_links || $website_url ) : ?>
                        <div>

                            <?php foreach ( $social_links as $link ) : ?>
                                <?php
                                $link_url      = (string) ( $link['url']   ?? '' );
                                $link_label    = (string) ( $link['label'] ?? '' );
                                $link_icon     = (string) ( $link['icon']  ?? '' );
                                if ( ! $link_url || ! $link_icon ) continue;
                                ?>
                                <a href="<?php echo esc_url( $link_url ); ?>"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   aria-label="<?php echo esc_attr( $link_label ); ?>">
                                    <svg class="quarter-right-space" viewBox="0 0 16 16"
                                         width="1em" height="1em"
                                         aria-hidden="true" focusable="false">
                                        <use href="#<?php echo esc_attr( $link_icon ); ?>"></use>
                                    </svg>
                                </a>
                            <?php endforeach; ?>

                            <?php if ( $website_url ) : ?>
                                <a href="<?php echo esc_url( $website_url ); ?>"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   aria-label="<?php esc_attr_e( 'Website oficial', 'futebol365' ); ?>">
                                    <svg class="quarter-right-space" viewBox="0 0 16 16"
                                         width="1em" height="1em"
                                         aria-hidden="true" focusable="false">
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
            <?php foreach ( $nav_sections as $slug => $label ) : ?>
                <?php
                $is_active = ( $active_nav === $slug );
                $tab_url   = ( $slug === 'details' ) ? $base_url : $info_url;
                ?>
                <li>
                    <a class="<?php echo $is_active ? 'active' : ''; ?>"
                       href="<?php echo esc_url( $tab_url ); ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <nav class="nav-mobile" id="nav-mobile"></nav>
    </div>


