<?php
/**
 * Block Name: Category Posts Block
 * Description: Dynamic block to show category posts in full or half layout.
 * @param array $block The block settings and attributes.
 */

// Track globally used IDs to avoid duplicates across blocks
global $futebol365_used_post_ids;
if ( ! isset( $futebol365_used_post_ids ) || ! is_array( $futebol365_used_post_ids ) ) {
    $futebol365_used_post_ids = [];
}

// Pass the block ID context to fetch fields correctly inside Gutenberg editor
$block_id       = isset( $block['id'] ) ? $block['id'] : get_the_ID();
$fields         = get_fields( $block_id ) ?: [];

$half_layout    = $fields['half_layout'] ?? '';
$is_half_layout = ( 'yes' === $half_layout || ( is_array( $half_layout ) && in_array( 'yes', $half_layout, true ) ) );
$fallback_image = function_exists( 'futebol365_fallback_image' ) ? futebol365_fallback_image() : '';

// Helper function to safely extract Term ID regardless of ACF Return Format settings
$get_clean_cat_id = function( $field_value ) {
    if ( is_object( $field_value ) && isset( $field_value->term_id ) ) {
        return (int) $field_value->term_id;
    }
    if ( is_array( $field_value ) && isset( $field_value['term_id'] ) ) {
        return (int) $field_value['term_id'];
    }
    return ! empty( $field_value ) ? (int) $field_value : 0;
};

// Map the configurations dynamically 
$sections = [];
$sections[] = [
    'category_id' => $get_clean_cat_id( $fields['block_category'] ?? '' ),
    'title'       => $fields['add_title'] ?? '',
    'posts_count' => ! empty( $fields['posts_count'] ) ? (int) $fields['posts_count'] : 4,
    'color'       => $fields['select_color'] ?? '',
    'card_class'  => $is_half_layout ? 'all-50 tiny-100' : 'all-25 small-50 tiny-100'
];

if ( $is_half_layout ) {
    $sections[] = [
        'category_id' => $get_clean_cat_id( $fields['block_category_second'] ?? '' ),
        'title'       => $fields['add_title_second'] ?? '',
        'posts_count' => ! empty( $fields['posts_count_second'] ) ? (int) $fields['posts_count_second'] : 4,
        'color'       => $fields['select_color_second'] ?? '',
        'card_class'  => 'all-50 tiny-100'
    ];
}
?>

<?php if ( $is_half_layout ) : ?>
<div class="column-group xlarge-horizontal-gutters">
<?php endif; ?>

    <?php foreach ( $sections as $section ) : 
    
        if ( $is_half_layout ) echo '<div class="all-100 xlarge-50">';

        if ( ! empty( $section['category_id'] ) ) :
            
            $section_title = $section['title'];
            if ( empty( $section_title ) ) {
                $term          = get_term( $section['category_id'], 'category' );
                $section_title = ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
            }

            $query_args = [
                'post_type'           => 'post',
                'post_status'         => 'publish',
                'posts_per_page'      => $section['posts_count'],
                'ignore_sticky_posts' => true,
                'no_found_rows'       => true,
                'fields'              => 'ids',
                'post__not_in'        => $futebol365_used_post_ids, 
                'tax_query'           => [
                    [
                        'taxonomy'         => 'category',
                        'field'            => 'term_id',
                        'terms'            => $section['category_id'],
                        'include_children' => true,
                        'operator'         => 'IN',
                    ]
                ]
            ];

            $posts_query = new WP_Query( $query_args );
            $post_ids    = $posts_query->posts;
            $style       = ! empty( $section['color'] ) ? ' style="background:' . esc_attr( $section['color'] ) . ';"' : '';
            ?>

            <div class="news-block">
                <?php if ( ! empty( $section_title ) ) : ?>
                    <h3 class="condensed-400 modtitle">
                        <a href="<?php echo esc_url( get_category_link( $section['category_id'] ) ); ?>">
                            <?php echo esc_html( $section_title ); ?>
                        </a>
                        <span class="modtitle-border" <?php echo $style; ?>></span>
                    </h3>
                <?php endif; ?>

                <div class="column-group gutters news-list-homepage">
                    <?php 
                if ( ! empty( $post_ids ) ) : 
                    foreach ( $post_ids as $pid ) : 
                        // 1. Safely get the post object using the ID
                        $post_obj = get_post( $pid );
                        if ( ! $post_obj ) continue; // Skip if the post doesn't exist

                        $futebol365_used_post_ids[] = $pid; 
                        
                        $legacy_news_id = (int) ( $post_obj->legacy_id ?? 0 );

                        if ( has_post_thumbnail( $pid ) ) {
                            $img_url = get_the_post_thumbnail_url( $pid, 'medium_large' );
                        } elseif ( $legacy_news_id > 0 && function_exists( 'f365_module_image_url' ) ) {
                            $img_url = f365_module_image_url( 'news', $legacy_news_id ) ?: $fallback_image;
                        } else {
                            $img_url = $fallback_image;
                        }
                        
                        // 3. FIX: Safely read the modified date using the object property
                        $timestamp = strtotime( $post_obj->post_modified );
                        $time      = date_i18n( 'H:i', $timestamp );
                        
                        // 4. FIX: Safely read the post_date directly from the object
                        $date      = new DateTime( $post_obj->post_date );
                ?>
                            <div class="<?php echo esc_attr( $section['card_class'] ); ?>">
                                <a href="<?php echo esc_url( get_permalink( $pid ) ); ?>">
                                    <figure class="ink-image ink-lazyload my-thumb-3-2" data-delta="300" data-loaded-class="loaded">
                                        <img src="<?php echo esc_url( $img_url ); ?>" onerror="this.onerror=null;this.src='<?php echo $fallback_image; ?>';"
                                        alt="<?php echo esc_attr( get_the_title( $pid ) ); ?>" class="lazyload-item my-thumb-image loaded">
                                    </figure>
                                </a>
                                <div class="half-top-space medium condensed-400 uppercase">
                                    <?php echo esc_html($date->format('d-m-Y · H:i')); ?>
                                </div>
                                <h2 class="large condensed-700">
                                    <a href="<?php echo esc_url( get_permalink( $pid ) ); ?>">
                                        <?php echo esc_html( get_the_title( $pid ) ); ?>
                                    </a>
                                </h2>
                            </div>
                        <?php 
                        endforeach;
                    else : ?>
                        <p class="all-100"><?php esc_html_e( 'No posts found.', 'futebol365' ); ?></p>
                    <?php endif; ?>
                </div>
            </div>

        <?php else : ?>
            <p class="all-100"><?php esc_html_e( 'Please select a valid category in block settings.', 'futebol365' ); ?></p>
        <?php 
        endif;

        if ( $is_half_layout ) echo '</div>'; 
    endforeach; ?>

<?php if ( $is_half_layout ) : ?>
</div>
<?php endif; ?>