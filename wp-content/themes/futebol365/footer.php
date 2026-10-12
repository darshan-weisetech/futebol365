<?php
/**
 * The template for displaying the footer
 *
 * Contains the closing of the #content div and all content after.
 *
 * @link https://developer.wordpress.org/themes/basics/template-files/#template-partials
 *
 * @package Futebol365
 */
$cache_key = 'footer_html_v1';

$footer_html = wp_cache_get($cache_key, 'theme');

if (false === $footer_html) {

    ob_start();
    ?>



    <footer id="colophon" class="site-footer">
        <div class="ink-grid">
            <div class="footer-inner">
     
    <?php
    // Loop through your 6 registered footer sidebars
    for ( $i = 1; $i <= 5; $i++ ) :
        $sidebar_id = 'footer-sidebar-' . $i;

        if ( is_active_sidebar( $sidebar_id ) ) :
            ?>
            <div class="footer-column footer-column-<?php echo esc_attr( $i ); ?>">
                <?php dynamic_sidebar( $sidebar_id ); ?>
            </div>
            <?php
        endif;
    endfor;
    ?>

                
            </div>
            
            <?php if ( has_nav_menu( 'footer_menu' ) ) : ?>
                <div class="footer-line-link">
                    <?php   
                        wp_nav_menu(
                            array(
                                'theme_location' => 'footer_menu',
                                'container'      => false,
                                'menu_class'     => 'footer-menu',
                            )
                        );
                    ?>  
                </div>
            <?php endif; 
            echo '<div class="footer-bottom-information">';
            $sidebar_6_id = 'footer-sidebar-6';
            if ( is_active_sidebar( $sidebar_6_id ) ) :
                ?>
                <div class="footer-column-special footer-column-6">
                    <?php dynamic_sidebar( $sidebar_6_id ); ?>
                </div>
                <?php
            endif;
            echo '</div>';
            ?>
            
            
        </div>
    </footer>
    <?php

    $footer_html = ob_get_clean();

    wp_cache_set($cache_key, $footer_html, 'theme');
}

echo $footer_html;
?>
</div>
<?php wp_footer(); ?>

</body>
</html>