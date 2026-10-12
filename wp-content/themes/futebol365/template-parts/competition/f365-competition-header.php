<?php
/**
 * Competition Header
 */

global $wpdb;

$current_post_id = $args['post_id'];
$raw_permalink = get_permalink( $current_post_id );
$clean_path    = strtok( $raw_permalink, '?' );
$base_url      = user_trailingslashit( trailingslashit( $clean_path ) . 'transferencias' );
$form_action_url = ! empty( $_SERVER['REQUEST_URI'] ) ? strtok( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '?' ) : $base_url;

$sections = $args['sections'];

$final_competition_id = $args['competition_id'];

// Default variables for fallback
$competition_name     = 'Jogos Olímpicos';
$competition_season   = '2024';
$competition_edition  = '2024';
$competition_local    = 'Paris, França';
$federation_id        = '1';
$federation_shortname = 'Futebol 365';
$flag_id              = 'conf_1';
$editions             = array();

// Federation social links (competition.federation_competition.* via the Entity base)
$fc_facebook  = '';
$fc_twitter   = '';
$fc_youtube   = '';
$fc_instagram = '';
$fc_url       = '';

// Navigation visibility flags (replicate competition_detail.html conditions)
$competition_type       = '';
$statistics_visible     = false;
$transfers_visible      = false;
$stadiums_visible       = false;
$classification_visible = false;
$winners_visible        = false;

if ( ! empty( $final_competition_id ) ) {

    $country_flag_url = '';
    $featured_img_url = get_the_post_thumbnail_url( $current_post_id, 'full' );
    $countries        = get_the_terms( $current_post_id, 'f365_country' );
    $comp_federation_id = get_field( 'federation_competition', $current_post_id );
    $wp_federation_id      = $comp_federation_id ? get_field( 'federation', $comp_federation_id ) : null;

    if ( ! empty( $countries ) && ! is_wp_error( $countries ) ) {
        $country      = reset( $countries );
        $country_name = $country->name;
        $flag         = get_field( 'country_flag', 'term_' . $country->term_id );


        if ( is_numeric( $flag ) ) {
            $country_flag_url = wp_get_attachment_image_url( $flag, 'full' );
        } elseif ( is_array( $flag ) && ! empty( $flag['url'] ) ) {
            $country_flag_url = $flag['url'];
        } elseif ( is_string( $flag ) ) {
            $country_flag_url = $flag;
        }
    } elseif ( empty( $featured_img_url ) && $wp_federation_id ) {
        $featured_img_url = get_the_post_thumbnail_url( $wp_federation_id, 'full' );
    }


    $final_img_url = $featured_img_url ?: $country_flag_url;

    $table_name = $wpdb->prefix . 'competition_competition';

    // Step 1: Query the current record to get basic info and its federation_competition_id
    $competition = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT name, season, edition, local, federation_competition_id FROM $table_name WHERE id = %d",
            $final_competition_id
        ),
        OBJECT
    );

    if ( $competition ) {
        $competition_name    = esc_html( $competition->name );
        $competition_season  = esc_html( $competition->season );
        $competition_edition = esc_html( $competition->edition );
        $competition_local   = ! empty( $competition->local ) ? esc_html( $competition->local ) : '';
        $federation_id       = intval( $competition->federation_competition_id );

        // Step 2: Federation competition (+ federation) details in a single join:
        $fc = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT fc.competition_type, fc.show_stadiums, fc.url AS fc_url,
                        fc.facebook, fc.twitter, fc.youtube, fc.instagram,
                        f.id AS fed_id, f.shortname, f.federation_type, f.country_id,
                        f.federation_id AS parent_fed_id
                   FROM {$wpdb->prefix}federation_federationcompetition fc
                   JOIN {$wpdb->prefix}federation_federation f ON fc.federation_id = f.id
                  WHERE fc.id = %d",
                $federation_id
            ),
            OBJECT
        );

        if ( $fc ) {
            $federation_shortname = ! empty( $fc->shortname ) ? esc_html( $fc->shortname ) : 'Futebol 365';
            $fc_facebook          = $fc->facebook;
            $fc_twitter           = $fc->twitter;
            $fc_youtube           = $fc->youtube;
            $fc_instagram         = $fc->instagram;
            $fc_url               = $fc->fc_url;
            $competition_type     = $fc->competition_type;

            // Flag id — replicates Federation.get_flag_id().
            if ( 'association' === $fc->federation_type ) {
                // Parent federation's country.
                $parent_country_id = ! empty( $fc->parent_fed_id ) ? $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT country_id FROM {$wpdb->prefix}federation_federation WHERE id = %d",
                        $fc->parent_fed_id
                    )
                ) : '';
                $flag_id = ! empty( $parent_country_id ) ? $parent_country_id : '';
            } elseif ( 'federation' === $fc->federation_type ) {
                $flag_id = ! empty( $fc->country_id ) ? $fc->country_id : '';
            } else {
                $flag_id = 'conf_' . intval( $fc->fed_id );
            }

            $statistics_visible = ( 'friendly' !== $competition_type );
            $transfers_visible  = ( 'championship' === $competition_type );
            $stadiums_visible   = (bool) intval( $fc->show_stadiums );
            
            if ( 'friendly' !== $competition_type ) {
                $has_round_phases = (bool) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT EXISTS(
                            SELECT 1
                              FROM {$wpdb->prefix}competition_match m
                              JOIN {$wpdb->prefix}competition_competitionphase cp ON m.competition_phase_id = cp.id
                             WHERE m.competition_id = %d
                               AND cp.phase_type IN ('with_rounds','with_rounds_and_groups','single_round','mixed_groups')
                        )",
                        $final_competition_id
                    )
                );

                if ( $has_round_phases ) {
                    $classification_visible = true;
                } else {
                    $has_competition_phases = (bool) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT EXISTS(SELECT 1 FROM {$wpdb->prefix}competition_competitionphase WHERE competition_id = %d)",
                            $final_competition_id
                        )
                    );
                    $classification_visible = ! $has_competition_phases;
                }
            }
            $winners_visible = (bool) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT EXISTS(
                        SELECT 1
                          FROM {$wpdb->prefix}competition_competition c
                         WHERE c.federation_competition_id = %d
                           AND c.is_closed = 't'
                           AND (
                                c.first_place_teamstep_id IS NOT NULL
                             OR c.second_place_teamstep_id IS NOT NULL
                             OR EXISTS(
                                    SELECT 1 FROM {$wpdb->prefix}competition_competitionphasewinners w
                                     WHERE w.competition_id = c.id
                                )
                           )
                    )",
                    $federation_id
                )
            );
        }
        $editions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT edition, season FROM $table_name WHERE federation_competition_id = %d ORDER BY edition DESC",
                $federation_id
            ),
            OBJECT
        );

    }
}
?>
<div class="ink-grid vertical-space">

    <?php get_template_part( 'template-parts/template-offer-widget' ); ?>

    <div class="ink-header-f365 competicoes" >
        <div class="column-group" >
            <div class="align-left all-60 medium-100 small-100 tiny-100 banner-left-content" >

                <?php if ( $final_img_url ) : ?>
                    <div class="flag hide-small hide-tiny">
                        <i class="flag-icon" title="competition">
                            <img alt="<?php echo esc_attr( $competition_name ?: 'futebol365' ); ?>" title="<?php echo esc_attr( $competition_name ?: 'futebol365' ); ?>" src="<?php echo esc_url( $final_img_url ); ?>">
                        </i>
                    </div>

                <?php endif; ?>


                <div class="headerdata" >
                    <a href="<?php echo esc_url( home_url( "/competicao/{$federation_id}/?edition={$competition_edition}" ) ); ?>" >
                        <h1 ><?php echo $competition_name; ?></h1>
                    </a>
                    <div ><?php esc_html_e( 'Edição: ', 'futebol365' ); ?><?php echo $competition_season; ?></div>

                    <?php if ( ! empty( $competition_local ) ) : ?>
                        <div >Local: <?php echo $competition_local; ?></div>
                    <?php endif; ?>

                    
                    <div>
                        <?php if ( ! empty( $final_img_url ) ) : ?>
                        <i class="quarter-right-space flag-icon com-full-icon" >
                            <img alt="<?php echo esc_attr( $competition_name ?: 'futebol365' ); ?>" title="<?php echo esc_attr( $competition_name ?: 'futebol365' ); ?>" src="<?php echo esc_url( $final_img_url ); ?>">
                        </i>
                        <?php endif; ?>
                        <?php echo $federation_shortname; ?>
                    </div>
                    

                    <div >
                        <?php if ( ! empty( $fc_facebook ) ) : ?>
                            <a href="https://www.facebook.com/<?php echo esc_attr( $fc_facebook ); ?>" target="_blank" aria-label="Facebook"><svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false"><use href="#social-facebook"></use></svg></a>
                        <?php endif; ?>
                        <?php if ( ! empty( $fc_twitter ) ) : ?>
                            <a href="https://x.com/<?php echo esc_attr( $fc_twitter ); ?>" target="_blank" aria-label="X (Twitter)"><svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false"><use href="#social-x"></use></svg></a>
                        <?php endif; ?>
                        <?php if ( ! empty( $fc_youtube ) ) : ?>
                            <a href="https://www.youtube.com/<?php echo esc_attr( $fc_youtube ); ?>" target="_blank" aria-label="YouTube"><svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false"><use href="#social-youtube"></use></svg></a>
                        <?php endif; ?>
                        <?php if ( ! empty( $fc_instagram ) ) : ?>
                            <a href="https://www.instagram.com/<?php echo esc_attr( $fc_instagram ); ?>" target="_blank" aria-label="Instagram"><svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false"><use href="#social-instagram"></use></svg></a>
                        <?php endif; ?>
                        <?php if ( ! empty( $fc_url ) ) : ?>
                            <a href="<?php echo esc_url( $fc_url ); ?>" target="_blank" aria-label="Website oficial"><svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false"><use href="#icon-link"></use></svg></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="align-right medium-align-left small-align-left tiny-align-left all-40 medium-100 small-100 tiny-100 medium-top-space small-top-space tiny-top-space" >
                <form class="ink-form" action="<?php echo esc_url( $form_action_url ); ?>">

                    <div class="control-group" >
                        <select name="edition" id="edition"  onchange="location = this.value;">
                            <?php
                            if ( ! empty( $editions ) ) :
                                foreach ( $editions as $ed ) :
                                    $ed_value = esc_attr( $ed->edition );
                                    $ed_label = esc_html( $ed->season ); // Displays '2024-2025' or '2024' inside the dropdown text
                                    $uri = $_SERVER['REQUEST_URI'];
                                    $path = parse_url($uri, PHP_URL_PATH);
                                    $segments = explode('/', trim($path, '/'));
                                    $last_slug = end($segments);

                                    // Check if this option matches the currently viewed edition
                                    $selected   = ( $ed_value === $competition_edition ) ? 'selected="selected"' : '';
                                    $target_url = esc_url(
                                        home_url(
                                            'competicao/' . $federation_id  . '/' .
                                            '?edition=' . $ed_value
                                        ));
                                                                        ?>
                                    <option value="<?php echo $target_url; ?>" <?php echo $selected; ?> >
                                        <?php echo $ed_label; ?>
                                    </option>
                                <?php
                                endforeach;
                            else :
                                // Fallback option if no database entries were found
                            ?>
                                <option value="<?php echo esc_url( home_url( "/competicao/{$federation_id}/?edition={$competition_edition}" ) ); ?>" selected="selected">
                                    <?php echo $competition_season; ?>
                                </option>
                            <?php endif; ?>
                        </select>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php
    $visibility_map = [
        'classificacao'  => $classification_visible,
        'estatisticas'   => $statistics_visible,
        'transferencias' => $transfers_visible,
        'estadios'       => $stadiums_visible,
        'vencedores'     => $winners_visible,
    ];

    function is_active_section( $section ) {
        if ( empty( $section ) ) {
            return preg_match(
                '#/competicao/[^/]+/?(\?.*)?$#',
                $_SERVER['REQUEST_URI']
            );
        }

        return strpos( $_SERVER['REQUEST_URI'], "/{$section}/" ) !== false;
    }
    ?>

    <nav class="nav-main" id="nav-main">
        <ul>
            <?php foreach ( $sections as $slug => $section ) : ?>

                <?php
                // Skip sections based on visibility flags.
                if (
                    isset( $visibility_map[ $slug ] ) &&
                    ! $visibility_map[ $slug ]
                ) {
                    continue;
                }

                $url = empty( $slug )
                    ? home_url( "/competicao/{$federation_id}/?edition={$competition_edition}" )
                    : home_url( "/competicao/{$federation_id}/{$slug}/?edition={$competition_edition}" );
                ?>

                <li>
                    <a<?php echo is_active_section( $slug ) ? ' class="active"' : ''; ?>
                        href="<?php echo esc_url( $url ); ?>">
                        <?php echo esc_html( $section['label'] ); ?>
                    </a>
                </li>

            <?php endforeach; ?>
        </ul>
    </nav>

    <nav class="nav-mobile" id="nav-mobile"></nav>

</div>
