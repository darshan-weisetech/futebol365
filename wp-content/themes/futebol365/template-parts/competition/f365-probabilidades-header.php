<?php
/**
 * competiiton Header
*/

$post_id         = $args['post_id'] ?? get_the_ID();
$bg_field     = get_field( 'federation_header_bg_image', 'option' );
$bg_image_url = $bg_field['url'] ?? futebol365_fallback_image(); ?>

<div class="ink-grid vertical-space <?php echo esc_attr( $post_id ); ?>">
    <?php get_template_part( 'template-parts/template-offer-widget' ); ?>
    <div class="ink-header-f365 competicoes" style="background-image:
            linear-gradient(
                to left,
                rgba(250,250,250,0) 40%,
                rgba(0,0,0,1)
            ),
            url(<?php echo esc_url( $bg_image_url ); ?>);">

        <div class="column-group">
            <div class="align-left all-60 medium-100 small-100 tiny-100 banner-left-content">
                <div class="headerdata">
                    <h1><?php the_title() ?></h1>
                </div>
            </div>
        </div>
    </div>
    <div class="nav-trigger" id="nav-trigger">
        <span>Menu</span>
    </div>
    <nav class="nav-main" id="nav-main">
        <ul class="f365-schedule-menu-list">
            <?php
            // Sets anchor time to the user's active WordPress timezone state
            $anchor_time = current_time('timestamp');
            $scheduled_date_db = isset($_GET['schedule_date_input']) ? sanitize_text_field($_GET['schedule_date_input']) : date('Y-m-d', $anchor_time);
            // Executes exactly 8 cycles: -1 (Ontem), 0 (Hoje), up to 6 days ahead
            for ($offset = -1; $offset <= 6; $offset++) {
                $node_timestamp = strtotime(($offset >= 0 ? "+{$offset}" : "{$offset}") . " days", $anchor_time);
                $node_iso_date   = date('Y-m-d', $node_timestamp);

                // Activates highlight css tracking class for the selected database date node
                $node_active_class = ($node_iso_date === $scheduled_date_db) ? ' active' : '';

                // Resolves textual string labels exactly matching the layout framework
                if ($offset === -1) {
                    $day_string_title = 'Ontem';
                } elseif ($offset === 0) {
                    $day_string_title = 'Hoje';
                } elseif ($offset === 1) {
                    $day_string_title = 'Amanhã';
                } else {
                    // 'l' gives the full native word in Portuguese (e.g., "Segunda-feira", "Sábado")
                    $day_string_title = date_i18n('l', $node_timestamp);
                }

                $day_numeric_title = date('d', $node_timestamp);
                $node_destination_link = esc_url(add_query_arg('schedule_date_input', $node_iso_date, get_permalink()));
                
                echo '<li>';
                echo '<a href="' . $node_destination_link . '" class="f365-schedule-day-anchor' . $node_active_class . '">';
                echo esc_html($day_string_title);
                // Clean numerical format container without spaces or extraneous string characters
                echo '<span>' .  " , " .esc_html($day_numeric_title) . '</span>';
                echo '</a>';
                echo '</li>';
            }
            ?>
        </ul>
    </nav>
    <nav class="nav-mobile" id="nav-mobile"></nav>
</div>
