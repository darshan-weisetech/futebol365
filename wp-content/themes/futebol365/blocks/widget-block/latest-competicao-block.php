<?php
/**
 * Latest News Block Template - Competitions
 */

$latest_news_fields = get_fields();

$section_title = !empty($latest_news_fields['competicao_add_title'])
    ? $latest_news_fields['competicao_add_title']
    : __('Últimas Notícias', 'futebol365');

$section_link   = $latest_news_fields['competicao_add_link'] ?? ['url' => '#'];
$section_color  = $latest_news_fields['select_color_competicao_section'] ?? '';
$selected_posts = $latest_news_fields['select_competicao_posts'] ?? [];

$posts = [];

if (!empty($selected_posts)) {
    $sliced_posts = array_slice($selected_posts, 0, 10);
    
    $posts = array_map(function($p) { 
        return is_object($p) ? $p : get_post($p); 
    }, $sliced_posts);
    
    $posts = array_filter($posts); 
    
    $post_ids = wp_list_pluck($posts, 'ID');
    update_meta_cache('post', $post_ids);
    update_object_term_cache($post_ids, 'f365_competition');
} else {
    global $wpdb;
    $query = "
        SELECT ID, post_title, post_date 
        FROM {$wpdb->posts} 
        WHERE post_type = 'f365_competition' 
          AND post_status = 'publish' 
        ORDER BY post_date DESC 
        LIMIT 4
    ";
    $raw_posts = $wpdb->get_results($query);
    if (!empty($raw_posts)) {
        $post_ids = wp_list_pluck($raw_posts, 'ID');
        _prime_post_caches($post_ids, true, true);
        update_meta_cache('post', $post_ids);
        update_object_term_cache($post_ids, 'f365_competition');
        
        $posts = array_map('get_post', $post_ids);
    }
}

if ( ! empty( $posts ) ) {
    $all_term_ids = [];
    $loop_post_ids = wp_list_pluck($posts, 'ID');
    
    if ( function_exists( 'update_post_thumbnail_cache' ) ) {
        update_post_thumbnail_cache( new WP_Query( array( 'post__in' => $loop_post_ids, 'post_type' => 'any', 'no_found_rows' => true ) ) );
    }

    foreach ( $posts as $post ) {
        $terms = get_the_terms($post->ID, 'f365_country');
        if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
            $all_term_ids[] = reset($terms)->term_id;
        }
    }
    if ( ! empty( $all_term_ids ) ) {
        update_meta_cache('term', array_unique($all_term_ids));
    }
}

$fallback_global_img = function_exists('futebol365_fallback_image') ? futebol365_fallback_image() : '';

$shortnames_map = [];

if ( ! empty( $posts ) ) {
    global $wpdb;
    $loop_post_ids = wp_list_pluck($posts, 'ID');

    $fed_comp_ids = [];
    $post_to_fed_comp = [];

    foreach ( $loop_post_ids as $p_id ) {
        $comp_fed_id = get_field( 'federation_competition', $p_id );
        if ( $comp_fed_id ) {
            $wp_fed_id = get_field( 'federation', $comp_fed_id );
            if ( $wp_fed_id ) {
                $fed_comp_ids[] = intval($wp_fed_id);
                $post_to_fed_comp[$p_id] = intval($wp_fed_id);
            }
        }
    }

    if ( ! empty( $fed_comp_ids ) ) {
        $fed_comp_ids_csv = implode( ',', array_unique( $fed_comp_ids ) );
        
        $sql = "
            SELECT comp.id AS comp_id, f.shortname 
            FROM {$wpdb->prefix}competition_competition comp
            JOIN {$wpdb->prefix}federation_federationcompetition fc ON comp.federation_competition_id = fc.id
            JOIN {$wpdb->prefix}federation_federation f ON fc.federation_id = f.id
            WHERE comp.id IN ($fed_comp_ids_csv)
        ";
        
        $db_results = $wpdb->get_results($sql);

        if ( ! empty( $db_results ) ) {
            $comp_to_shortname = [];
            foreach ( $db_results as $row ) {
                $comp_to_shortname[$row->comp_id] = $row->shortname;
            }

            foreach ( $post_to_fed_comp as $p_id => $c_id ) {
                if ( isset( $comp_to_shortname[$c_id] ) ) {
                    $shortnames_map[$p_id] = $comp_to_shortname[$c_id];
                }
            }
        }
    }
}

if ( ! empty( $posts ) ) : ?>

<h2 class="condensed-400 extralarge modtitle xlarge-half-top-space large-half-top-space small-top-space tiny-top-space">
    <a href="<?php echo esc_url($section_link['url'] ?? '#'); ?>">
        <?php echo esc_html($section_title); ?>
    </a>
    <span class="modtitle-border" <?php if ($section_color) echo 'style="background:' . esc_attr($section_color) . ';"'; ?>></span>
</h2>

<div class="column-group half-horizontal-gutters quarter-vertical-gutters small-gutters tiny-gutters ">

    <?php 
    foreach ($posts as $post) : 
        // Using $post object properties prevents unnecessary DB calls
        $post_id = $post->ID; 
        
        $date  = get_the_date('d-m-Y · H:i', $post);
        $title = get_the_title($post);
        $link  = get_permalink($post);

        $country_flag_url = '';
        $featured_img_url = get_the_post_thumbnail_url($post, 'full'); // Now completely safe & cached
        
        $countries = get_the_terms($post_id, 'f365_country');
        $federation_id = get_post_meta($post_id, 'federation_id', true); 

        if ( ! empty( $countries ) && ! is_wp_error( $countries ) ) {
            $country = reset( $countries );
            $flag = get_term_meta( $country->term_id, 'country_flag', true );

            if ( is_numeric( $flag ) ) {
                $country_flag_url = wp_get_attachment_image_url( $flag, 'full' );
            } elseif ( is_array( $flag ) && ! empty( $flag['url'] ) ) {
                $country_flag_url = $flag['url'];
            } elseif ( is_string( $flag ) ) {
                $country_flag_url = $flag;
            }
        } elseif ( empty( $featured_img_url ) && $federation_id ) {
            $featured_img_url = get_the_post_thumbnail_url(intval($federation_id), 'full');
        }

        $final_img_url = $featured_img_url ?: ($country_flag_url ?: $fallback_global_img);
        $federation_shortname = $shortnames_map[$post_id] ?? 'Futebol 365';
        ?>
        <div class="all-100">
            <div class="last-comp-right-div">
                <div class="last-comp-flag">
                    <i class="flag-icon">
                        <img class="lazyload-item my-thumb-image" src="<?php echo esc_url($final_img_url); ?>" alt="<?php echo esc_attr($title); ?>" title="<?php echo esc_attr($title); ?>">
                    </i>
                </div>
                <div class="last-comp-detail">
                    <div class="condensed-700 quarter-bottom-space">
                        <a href="<?php echo esc_url($link); ?>">
                            <?php echo esc_html($title); ?>
                        </a>
                    </div>
                    <div class="small condensed-400 uppercase"><?php echo esc_html($federation_shortname); ?></div>
                </div>
            </div>
        </div>
        
    <?php endforeach; ?>

</div>

<?php endif; ?>