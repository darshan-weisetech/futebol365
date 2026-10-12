<?php
/**
 * Template for displaying Rankings archives
 *
 * @package Futebol365
 */

get_header();

$section = get_query_var( 'f365_ranking_section' );
$term_id = 0;
$term_name = '';

if ( is_tax( 'f365_ranking_subtype' ) ) {
    $section = 'player';
    $term = get_queried_object();
    if ( $term instanceof WP_Term ) {
        $term_id = $term->slug; // The slug contains the numeric Django ID
        $term_name = $term->name;
    }
}
?>
<div class="f365-rankings-placeholder" style="padding: 50px; text-align: center; font-family: sans-serif;">
    <h1>Ranking Archive</h1>
    <p>This is the template for displaying the ranking archives.</p>
    <p><strong>Section:</strong> <?php echo esc_html( $section ?: 'default' ); ?></p>
    <?php if ( $term_id ) : ?>
        <p><strong>Player Subtype Name:</strong> <?php echo esc_html( $term_name ); ?></p>
        <p><strong>Player Subtype ID:</strong> <?php echo esc_html( $term_id ); ?></p>
    <?php endif; ?>
</div>
<?php
get_footer();
