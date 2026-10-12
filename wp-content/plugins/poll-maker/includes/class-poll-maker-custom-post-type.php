<?php
/**
 * The admin-facing custom post type functionality of the plugin.
 *
 * @link       http://ays-pro.com/
 * @since      5.7.7
 *
 * @package    Poll_Maker_Ays
 * @subpackage Poll_Maker_Ays/includes
 */

/**
 * The admin-facing custom post type functionality of the plugin.
 *
 * Defines the plugin name, version, flush version, name prefix
 *
 * @package    Poll_Maker_Ays
 * @subpackage Poll_Maker_Ays/includes
 * @author     Poll Maker Team <info@ays-pro.com>
 */
class Poll_Maker_Custom_Post_Type {

    private $plugin_name;
    private $version;
    private $ays_poll_flush_version;
    public  $name_prefix;

    public function __construct($plugin_name, $version){
        $this->plugin_name = $plugin_name;
        $this->name_prefix = 'ays-';
        $this->version = $version;
        $this->ays_poll_flush_version = '1.0.0';
        add_action( 'init', array( $this, 'ays_poll_register_custom_post_type' ) );
    }

    public function ays_poll_register_custom_post_type(){
        $args = array(
            'public'                => true,
            'rewrite'               => true,
            'show_in_menu'          => false,
            'exclude_from_search'   => false, 
            'show_ui'               => false,
            'show_in_nav_menus'     => false,
            'show_in_rest'          => false
        );

        register_post_type( 'ays-poll-maker', $args );
        $this->ays_poll_custom_rewrite_rule();
        $this->ays_poll_flush_permalinks();
    }

    public static function ays_poll_add_custom_post($args, $update = true){
        
        $poll_id    = (isset($args['poll_id']) && $args['poll_id'] != '' && $args['poll_id'] != 0) ? esc_attr($args['poll_id']) : '';
        $poll_title = (isset($args['poll_title']) && $args['poll_title'] != '') ? esc_attr($args['poll_title']) : '';
        $author_id  = (isset($args['author_id']) && $args['author_id'] != '') ? esc_attr($args['author_id']) : get_current_user_id();

        $post_content = '[ays_poll id="'.$poll_id.'"]';

        $new_post = array(
            'post_title'        => $poll_title,
            'post_author'       => $author_id,
            'post_type'         => 'ays-poll-maker', // Custom post type name is -> ays-poll-maker
            'post_content'      => $post_content,
            'post_status'       => 'draft',
            'post_date'         => current_time( 'mysql' ),
        );
        $post_id = wp_insert_post($new_post);
        if($update){
            if(isset($post_id) && $post_id > 0){
                self::update_polls_table_custom_post_id($post_id, $poll_id);
            }
        }
        return $post_id;
    }

    public static function update_polls_table_custom_post_id($custom_post_id, $poll_id){
        global $wpdb;
        $table = esc_sql( $wpdb->prefix . "ayspoll_polls" );
        $result = $wpdb->update(// phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $table,
            array('custom_post_id' => $custom_post_id),
            array('id' => $poll_id),
            array('%d'),
            array('%d')
        );
    }

    public function ays_poll_flush_permalinks(){
        if ( get_site_option( 'ays_poll_flush_version' ) != $this->ays_poll_flush_version ) {
            flush_rewrite_rules();
        }
        update_option( 'ays_poll_flush_version', $this->ays_poll_flush_version );            
    }
    
    public function ays_poll_custom_rewrite_rule() {
        add_rewrite_rule(
            'ays-poll-maker/([^/]+)/?',
            'index.php?post_type=ays-poll-maker&name=$matches[1]',
            'top'
        );
    }
}
