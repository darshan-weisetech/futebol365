<?php
/**
 * Team Notícias template — /equipa/{id}/noticias/
 *
 * Place in:
 *   theme/f365-team-noticias.php           (theme override — checked first)
 *   plugin/templates/f365-team-noticias.php (plugin default — fallback)
 *
 * Variables via get_query_var():
 *   f365_team_id  — Django team.id from URL
 *   f365_section  — 'noticias'
 *   f365_edition  — edition year (?edition=2024)
 *   f365_step     — step slug    (?step=principal)
 *   f365_modality — modality     (?modality=football)
 *   f365_gender   — gender       (?gender=male)
 */
get_header(); ?>

<div class="f365-team-page f365-team-noticias">

    <?php
    $team = f365_get_current_team_post();

    if ( ! $team ) {
        echo '<p>Equipa não encontrada.</p>';
        get_footer();
        return;
    }

    $shortname = get_post_meta( $team->ID, 'shortname', true );
    $longname  = get_post_meta( $team->ID, 'longname',  true );
    $edition   = get_query_var( 'f365_edition' )
                ?: sanitize_text_field( $_GET['edition'] ?? '' )
                ?: (string) get_post_meta( $team->ID, 'current_edition', true )
                ?: (string) date( 'Y' );
    $step      = get_query_var( 'f365_step' )     ?: sanitize_text_field( $_GET['step']     ?? 'principal' );
    $modality  = get_query_var( 'f365_modality' ) ?: sanitize_text_field( $_GET['modality'] ?? 'football' );
    $gender    = get_query_var( 'f365_gender' )   ?: sanitize_text_field( $_GET['gender']   ?? 'male' );
    ?>

    <h1><?php echo esc_html( $shortname ?: get_the_title( $team->ID ) ); ?> — Notícias</h1>

    <!-- TODO: implement Notícias content for this section -->

</div>

<?php get_footer();
