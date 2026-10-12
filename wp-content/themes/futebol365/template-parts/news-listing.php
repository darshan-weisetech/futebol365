<?php
$postId = get_the_ID();

$postobj = get_post($postId);

$legacy_news_id = ! empty($postobj->legacy_id)
    ? (int) $postobj->legacy_id
    : 0;

$image_url = '';

if ( has_post_thumbnail() ) {
    $image_url = get_the_post_thumbnail_url( get_the_ID(), 'medium_large' );
} elseif ( $legacy_news_id > 0 && function_exists( 'f365_module_image_url' )) {
    $image_url = f365_module_image_url( 'news', $legacy_news_id );
}

$image_url = $image_url ?: futebol365_fallback_image()
?>

<div class="news-list-cat">
    <article <?php post_class('column-group gutters'); ?>>

        <!-- Image -->
        <div class="all-30 small-100 tiny-100">

            <a href="<?php the_permalink(); ?>">

                <figure class="ink-image ink-lazyload my-thumb-3-2">

                <img
                    class="lazyload-item my-thumb-image loaded"
                    src="<?php echo esc_url( $image_url ); ?>"
                    onerror="this.onerror=null;this.src='<?php echo esc_url( FB_THEME_URL . '/assets/images/no-image-list.jpg' ); ?>';"
                    alt="<?php echo esc_attr( get_the_title() ); ?>"
                    title="<?php echo esc_attr( get_the_title() ); ?>"
                    loading="lazy"
                    decoding="async">

                </figure>

            </a>

        </div>

        <!-- Content -->
        <div class="all-70 small-100 tiny-100">

            <?php
            $category_name = '';

            $categories = get_the_category();

            if (!empty($categories)) {
                $category_name = $categories[0]->name;
            } elseif ($legacy_news_id) {
                $category_name = f365_news_category_name(
                    $legacy_news_id
                );
            }

            if (!empty($category_name)) :
            ?>
                <h2 class="medium condensed-400 uppercase">
                    <?php echo esc_html($category_name); ?>
                </h2>
            <?php endif; ?>

            <h3 class="condensed-700">
                <a href="<?php the_permalink(); ?>">
                    <?php the_title(); ?>
                </a>
            </h3>

            <h4 class="fw-400 hide-medium hide-small hide-tiny">
                <?php echo esc_html(get_the_excerpt()); ?>
            </h4>

            <h5 class="uppercase condensed-400">
                <?php echo esc_html(get_the_date('d-m-Y · H:i')); ?>
            </h5>

        </div>

    </article>
</div>