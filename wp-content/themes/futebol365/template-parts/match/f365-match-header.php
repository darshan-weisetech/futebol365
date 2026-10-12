<?php
/**
 * Competition header (match page). The competition, federation competition,
 * federation, editions and nav-visibility flags are read from the cloned
 * external tables (wp_competition_competition, wp_federation_federationcompetition,
 * wp_federation_federation, wp_competition_competitionphase / ...match /
 * ...competitionphasewinners). The flag image is the one exception: it is taken
 * from the competition post's f365_country term via term meta.
 *
 * Args:
 *   competition_id   (int)    external competition row id (wp_competition_competition.id)
 *   post_id          (int)    competition WP post id (used only for the flag term)
 *   sections         (array)  slug => [ 'label' => ... ] nav map
 *   current_section  (string) active section slug, '' for none (a match is not a tab)
 */

global $wpdb;

$final_competition_id = isset( $args['competition_id'] ) ? (int) $args['competition_id'] : 0;
$current_post_id      = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
$sections             = isset( $args['sections'] ) ? (array) $args['sections'] : array();
$current_section      = isset( $args['current_section'] ) ? (string) $args['current_section'] : '';

$request_uri     = ! empty( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
$form_action_url = $request_uri ? strtok( $request_uri, '?' ) : '';

// Flag image — taken from the competition post's f365_country term, read from
// term meta (not the external table). Requires the competition post id.
 $country_flag_url = '';
$featured_img_url = get_the_post_thumbnail_url( $current_post_id, 'full' );
$countries        = get_the_terms( $current_post_id, 'f365_country' );
$comp_federation_id = get_field( 'federation_competition', $current_post_id );
$wp_federation_id      = $comp_federation_id ? get_field( 'federation', $comp_federation_id ) : null;

if ( ! empty( $countries ) && ! is_wp_error( $countries ) ) {
    $country      = reset( $countries );
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


// Header values (populated from the external tables below).
$competition_name     = '';
$competition_season   = '';
$competition_edition  = '';
$competition_local    = '';
$federation_id        = 0;   // holds federation_competition_id (the routable id)
$federation_shortname = 'Futebol 365';
$editions             = array();

// Federation competition social links (Entity base columns).
$fc_facebook  = '';
$fc_twitter   = '';
$fc_youtube   = '';
$fc_instagram = '';
$fc_url       = '';

// Navigation visibility flags (replicate competition_detail.html conditions).
$competition_type       = '';
$statistics_visible     = false;
$transfers_visible      = false;
$stadiums_visible       = false;
$classification_visible = false;
$winners_visible        = false;

if ( $final_competition_id ) {

    $table_name = $wpdb->prefix . 'competition_competition';

    // Step 1: the competition row (name/season/edition/local + its federation competition id).
    $competition = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT name, season, edition, local, federation_competition_id FROM {$table_name} WHERE id = %d",
            $final_competition_id
        ),
        OBJECT
    );

    if ( $competition ) {
        $competition_name    = $competition->name;
        $competition_season  = $competition->season;
        $competition_edition = $competition->edition;
        $competition_local   = ! empty( $competition->local ) ? $competition->local : '';
        $federation_id       = (int) $competition->federation_competition_id;

        // Step 2: federation competition (+ federation) details in a single join.
        $fc = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT fc.competition_type, fc.show_stadiums, fc.url AS fc_url,
                        fc.facebook, fc.twitter, fc.youtube, fc.instagram,
                        f.shortname
                   FROM {$wpdb->prefix}federation_federationcompetition fc
                   JOIN {$wpdb->prefix}federation_federation f ON fc.federation_id = f.id
                  WHERE fc.id = %d",
                $federation_id
            ),
            OBJECT
        );

        if ( $fc ) {
            $federation_shortname = ! empty( $fc->shortname ) ? $fc->shortname : 'Futebol 365';
            $fc_facebook          = $fc->facebook;
            $fc_twitter           = $fc->twitter;
            $fc_youtube           = $fc->youtube;
            $fc_instagram         = $fc->instagram;
            $fc_url               = $fc->fc_url;
            $competition_type     = $fc->competition_type;

            $statistics_visible = ( 'friendly' !== $competition_type );
            $transfers_visible  = ( 'championship' === $competition_type );
            $stadiums_visible   = (bool) (int) $fc->show_stadiums;

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

        // Step 3: every edition of this federation competition for the dropdown.
        $editions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT edition, season FROM {$table_name} WHERE federation_competition_id = %d ORDER BY edition DESC",
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

                <?php if ( '' !== $final_img_url ) : ?>
                    <div class="flag hide-small hide-tiny">
                        <i class="flag-icon" title="competition">
                            <a href="<?php echo esc_url( home_url( "/competicao/{$federation_id}/?edition={$competition_edition}" ) ); ?>"><img alt="" src="<?php echo esc_url( $final_img_url ); ?>" onerror="this.onerror=null; this.src='<?php echo esc_url( futebol365_fallback_image() ); ?>';"></a>
                        </i>
                    </div>
                <?php endif; ?>


                <div class="headerdata" >
                    <a href="<?php echo esc_url( home_url( "/competicao/{$federation_id}/?edition={$competition_edition}" ) ); ?>" >
                        <h1 ><?php echo esc_html( $competition_name ); ?></h1>
                    </a>
                    <div >Edição: <?php echo esc_html( $competition_season ); ?></div>

                    <?php if ( ! empty( $competition_local ) ) : ?>
                        <div >Local: <?php echo esc_html( $competition_local ); ?></div>
                    <?php endif; ?>

                    <div >
                        <?php if ( '' !== $country_flag_url ) : ?>
                            <i class="hide-xlarge hide-large hide-medium quarter-right-space flag-icon com-full-icon">
                                <img alt="<?php echo esc_attr( $federation_shortname ); ?>" src="<?php echo esc_url( $country_flag_url ); ?>">
                            </i>
                        <?php endif; ?>
                        <?php echo esc_html( $federation_shortname ); ?>
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
                        <select name="edition" id="edition" onchange="location = this.value;">
                            <?php if ( ! empty( $editions ) ) : ?>
                                <?php foreach ( $editions as $ed ) : ?>
                                    <?php
                                    $ed_value   = (string) $ed->edition;
                                    $ed_label   = (string) $ed->season;
                                    $selected   = ( $ed_value === (string) $competition_edition ) ? ' selected="selected"' : '';
                                    $target_url = esc_url( home_url( "/competicao/{$federation_id}/?edition={$ed_value}" ) );
                                    ?>
                                    <option value="<?php echo $target_url; ?>"<?php echo $selected; ?>>
                                        <?php echo esc_html( $ed_label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <option value="<?php echo esc_url( home_url( "/competicao/{$federation_id}/?edition={$competition_edition}" ) ); ?>" selected="selected">
                                    <?php echo esc_html( $competition_season ); ?>
                                </option>
                            <?php endif; ?>
                        </select>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php
    $visibility_map = array(
        'classificacao'  => $classification_visible,
        'estatisticas'   => $statistics_visible,
        'transferencias' => $transfers_visible,
        'estadios'       => $stadiums_visible,
        'vencedores'     => $winners_visible,
    );
    ?>

    <nav class="nav-main" id="nav-main">
        <ul>
            <?php foreach ( $sections as $slug => $section ) : ?>

                <?php
                // Skip sections hidden by the competition-type visibility flags.
                if ( isset( $visibility_map[ $slug ] ) && ! $visibility_map[ $slug ] ) {
                    continue;
                }

                $url = ( '' === $slug )
                    ? home_url( "/competicao/{$federation_id}/?edition={$competition_edition}" )
                    : home_url( "/competicao/{$federation_id}/{$slug}/?edition={$competition_edition}" );

                $is_active = ( (string) $current_section === (string) $slug );
                ?>

                <li>
                    <a<?php echo $is_active ? ' class="active"' : ''; ?> href="<?php echo esc_url( $url ); ?>">
                        <?php echo esc_html( $section['label'] ); ?>
                    </a>
                </li>

            <?php endforeach; ?>
        </ul>
    </nav>

    <nav class="nav-mobile" id="nav-mobile"></nav>

</div>
