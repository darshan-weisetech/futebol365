<?php
/**
 * Footer Column Menus Block Template.
 *
 * @param   array $block The block settings and attributes.
 * @param   string $content The block inner HTML (empty).
 * @param   bool $is_preview True during AJAX preview in Gutenberg editor.
 * @param   (int|string) $post_id The post ID this block is saved to.
 */

// Fetch the block's repeater data (No 'option' parameter needed for blocks)
$menus = get_field( 'block_select_menus' ) ?: [];

if ( empty( $menus ) && $is_preview ) {
    echo '<p style="padding: 10px; border: 1px dashed #ccc; text-align: center;">' . esc_html__( 'Configure Footer Menus Here...', 'futebol365' ) . '</p>';
    return;
}

if ( ! empty( $menus ) && is_array( $menus ) ) : ?>
    <div class="footer-widget-menu-column">
        <?php
        foreach ( $menus as $menu_item ) :
            $menu_title = $menu_item['add_menu_title'] ?? '';
            $menu_id    = $menu_item['footer_menu_assign'] ?? '';

            if ( empty( $menu_title ) && empty( $menu_id ) ) {
                continue;
            }
            ?>
            <div class="footer-menu-group">

                <?php if ( ! empty( $menu_title ) ) : ?>
                    <h4 class="footer-menu-title">
                        <?php echo esc_html( $menu_title ); ?>
                    </h4>
                <?php endif; ?>

                <?php
                if ( ! empty( $menu_id ) ) :
                    wp_nav_menu(
                        array(
                            'menu'        => $menu_id,
                            'container'   => false,
                            'menu_class'  => 'footer-menu',
                            'fallback_cb' => false,
                        )
                    );
                endif;
                ?>

            </div>
        <?php 
        endforeach; 
        ?>
    </div>
<?php 
endif; 
?>