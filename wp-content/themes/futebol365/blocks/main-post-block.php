<?php
/**
 * Block Template: Main Posts Block (SQL Optimized)
 */

$post_fields = get_fields() ?: [];
$posts_count = !empty($post_fields['main_posts_count']) ? absint($post_fields['main_posts_count']) : 5;
$selected_posts = $post_fields['select_main_posts'] ?? [];

$posts = [];

// Gather posts
if (!empty($selected_posts) && is_array($selected_posts)) {
    $raw_items = array_slice($selected_posts, 0, $posts_count);
    foreach ($raw_items as $item) {
        $p_obj = get_post($item);
        if ($p_obj && 'publish' === $p_obj->post_status) {
            $posts[] = $p_obj;
        }
    }
} else {
    global $wpdb;
    // Optimized raw SQL query to replace heavy WP_Query object overhead
    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_title, post_modified FROM {$wpdb->posts}
         WHERE post_type = 'post' AND post_status = 'publish'
         ORDER BY post_date DESC LIMIT %d",
        $posts_count
    ));
}

if (empty($posts)) {
    return;
}

$fallback_image = function_exists('futebol365_fallback_image') ? futebol365_fallback_image() : '';
?>

<section class="highlight">
    <div class="column-group gutters">

        <?php foreach ($posts as $post_index => $post):
            $post_id = $post->ID;
            $title = esc_html($post->post_title);
            $permalink = esc_url(get_permalink($post_id));
            // Pull layout elements cleanly
            $legacy_news_id = (int) ($post->legacy_id ?? 0);
            if (has_post_thumbnail($post_id)) {
                $image = get_the_post_thumbnail_url($post_id, 'large');
            } elseif ($legacy_news_id > 0 && function_exists('f365_module_image_url')) {
                $image = f365_module_image_url('news', $legacy_news_id) ?: $fallback_image;
            } else {
                $image = $fallback_image;
            }

            $image = esc_url($image);

            // Get categories efficiently without loading the full taxonomy payload
            $categories = get_the_terms($post_id, 'category');
            $category_name = (!empty($categories) && !is_wp_error($categories)) ? esc_html($categories[0]->name) : '';

            // Formatting dates utilizing already retrieved post property
            $timestamp = strtotime($post->post_modified);
            $date = new DateTime(get_post_field('post_date', $post_id));
            $time = date_i18n('H:i', $timestamp);

            $meta_display = ($category_name ? $category_name . ' · ' : '');
            ?>

            <?php if ($post_index < 2): ?>
                <div class="all-50 small-100 tiny-100">
                    <figure class="ink-image ink-lazyload my-thumb-3-2">
                        <a href="<?php echo $permalink; ?>">
                            <img src="<?php echo $image; ?>" alt="<?php echo $title; ?>"
                                onerror="this.onerror=null;this.src='<?php echo $fallback_image; ?>';"
                                title="<?php echo $title; ?>" class="lazyload-item my-thumb-image loaded">
                        </a>
                        <figcaption class="over-bottom smooth">
                            <div class="highlight_topic small uppercase condensed-400">
                                <?php
                                $date->setTimezone(wp_timezone());
                                ?>
                                <?php echo $meta_display . esc_html($date->format('d-m-Y · H:i')); ?>
                            </div>
                            <h2 class="title extralarge condensed-700">
                                <a href="<?php echo $permalink; ?>"><?php echo $title; ?></a>
                            </h2>
                        </figcaption>
                    </figure>
                </div>

            <?php else: ?>
                <div class="all-33 small-40 tiny-45">
                    <a href="<?php echo $permalink; ?>">
                        <figure class="ink-image ink-lazyload my-thumb-3-2">
                            <img src="<?php echo $image; ?>" alt="<?php echo $title; ?>" title="<?php echo $title; ?>"
                                class="lazyload-item my-thumb-image loaded">
                        </figure>
                    </a>

                    <div class="hide-small hide-tiny">
                        <div class="half-top-space quarter-bottom-space medium condensed-400 uppercase"><?php
                        $date->setTimezone(wp_timezone());
                        ?>
                            <?php echo $meta_display . esc_html($date->format('d-m-Y · H:i')); ?>

                        </div>
                        <h2 class="large condensed-700">
                            <a href="<?php echo $permalink; ?>"><?php echo $title; ?></a>
                        </h2>
                    </div>
                </div>

                <div class="hide-xlarge hide-large hide-medium small-60 tiny-55">
                    <div class="medium condensed-400 uppercase">

                        <?php
                        $date = new DateTime(get_post_field('post_date', $post_id), new DateTimeZone('UTC'));
                        $date->setTimezone(wp_timezone());
                        echo esc_html($date->format('d-m-Y · H:i')); ?> · <?php echo $time;
                           ?>

                    </div>
                    <h2 class="large condensed-700">
                        <a href="<?php echo $permalink; ?>"><?php echo $title; ?></a>
                    </h2>
                </div>
            <?php endif; ?>

        <?php endforeach; ?>

    </div>
</section>