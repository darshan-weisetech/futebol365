<?php
ob_start();
/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://ays-pro.com/
 * @since             1.0.0
 * @package           Poll_Maker_Ays
 *
 * @wordpress-plugin
 * Plugin Name:       Poll Maker
 * Plugin URI:        https://ays-pro.com/wordpress/poll-maker/
 * Description:       Poll Maker is a powerful plugin for creating custom polls and gathering opinions. It lets you design and share polls quickly to engage your audience and collect feedback.
 * Version:           6.4.5
 * Author:            Poll Maker Team
 * Author URI:        https://ays-pro.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       poll-maker
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define('POLL_MAKER_AYS_VERSION', '6.4.5');
define('POLL_MAKER_AYS_NAME', 'poll-maker-ays');

if (!defined('POLL_MAKER_AYS_DIR')) {
	define('POLL_MAKER_AYS_DIR', plugin_dir_path(__FILE__));
}

if (!defined('POLL_MAKER_AYS_BASE_URL')) {
	define('POLL_MAKER_AYS_BASE_URL', plugin_dir_url(__FILE__));
}
if (!defined('POLL_MAKER_AYS_ADMIN_URL')) {
	define('POLL_MAKER_AYS_ADMIN_URL', plugin_dir_url(__FILE__) . 'admin');
}

if (!defined('POLL_MAKER_AYS_PUBLIC_URL')) {
	define('POLL_MAKER_AYS_PUBLIC_URL', plugin_dir_url(__FILE__) . 'public');
}

if( ! defined( 'POLL_MAKER_AYS_BASENAME' ) ) {
    define( 'POLL_MAKER_AYS_BASENAME', plugin_basename( __FILE__ ) );
}

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-poll-maker-ays-activator.php
 */
function activate_poll_maker_ays() {
	require_once plugin_dir_path(__FILE__) . 'includes/class-poll-maker-ays-activator.php';
	Poll_Maker_Ays_Activator::ays_poll_update_db_check();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-poll-maker-ays-deactivator.php
 */
function deactivate_poll_maker_ays() {
	require_once plugin_dir_path(__FILE__) . 'includes/class-poll-maker-ays-deactivator.php';
	Poll_Maker_Ays_Deactivator::deactivate();
}

register_activation_hook(__FILE__, 'activate_poll_maker_ays');
register_deactivation_hook(__FILE__, 'deactivate_poll_maker_ays');

add_action('plugins_loaded', 'activate_poll_maker_ays');
/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path(__FILE__) . 'includes/class-poll-maker-ays.php';

/**
 * The Gutenberg block registration.
 */
require plugin_dir_path(__FILE__) . 'poll/poll-maker-block.php';


if (!function_exists('array_column')) {
	function array_column( array $array, $columnKey, $indexKey = null ) {
		$result = array();
		foreach ( $array as $subArray ) {
			if (!is_array($subArray)) {
				continue;
			} elseif (is_null($indexKey) && array_key_exists($columnKey, $subArray)) {
				$result[] = $subArray[$columnKey];
			} elseif (array_key_exists($indexKey, $subArray)) {
				if (is_null($columnKey)) {
					$result[$subArray[$indexKey]] = $subArray;
				} elseif (array_key_exists($columnKey, $subArray)) {
					$result[$subArray[$indexKey]] = $subArray[$columnKey];
				}
			}
		}

		return $result;
	}
}

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_poll_maker_ays() {
	add_action('admin_notices', 'poll_maker_admin_notice');
	$plugin = new Poll_Maker_Ays();
	$plugin->run();

}

function poll_maker_activation_redirect_method( $plugin ) {
    if( $plugin == plugin_basename( __FILE__ ) ) {
        exit( wp_redirect( admin_url( 'admin.php?page=' . POLL_MAKER_AYS_NAME ) ) );
    }
}

function poll_maker_admin_notice() {
	if (isset($_GET['page']) && strpos($_GET['page'], POLL_MAKER_AYS_NAME) !== false) {
		?>

        <div class="ays-notice-banner">
            <div class="navigation-bar">
                <div id="navigation-container">
                    <div class="ays-poll-logo-container-upgrade">
                        <div class="logo-container">
                            <a href="https://ays-pro.com/wordpress/poll-maker?utm_source=dashboard&utm_medium=poll-free&utm_campaign=poll-maker-top-banner-logo-link-<?php echo esc_attr( POLL_MAKER_AYS_VERSION ); ?>" target="_blank" style="box-shadow: none;">
                                <img  class="poll-logo" src="<?php echo esc_attr(POLL_MAKER_AYS_ADMIN_URL) . '/images/icons/icon-poll-128x128.png'; ?>" alt="<?php echo esc_html__( "Poll Maker", "poll-maker" ); ?>" title="<?php echo esc_html__( "Poll Maker", "poll-maker" ); ?>"/>
                            </a>
                        </div>
                        
                        <div class="ays-poll-top-banner-title">
                            <span class="ays-poll-top-banner-main-title"><?php echo esc_html__( "Poll Maker by AYS", 'poll-maker' ); ?></span>
                            <span class="ays-poll-top-banner-sub-title"><?php echo esc_html__( "Build online interactive polls", 'poll-maker' ); ?></span>
                        </div>
                        <div class="ays-poll-upgrade-container">
                            <a href="https://ays-pro.com/wordpress/poll-maker?utm_source=dashboard&utm_medium=poll-free&utm_campaign=poll-top-banner-upgrade-button-<?php echo esc_attr( POLL_MAKER_AYS_VERSION ); ?>" target="_blank" class="poll-maker-upgrade-to-pro">
                                <img src="<?php echo esc_url(POLL_MAKER_AYS_ADMIN_URL) . '/images/icons/lightning-white.svg' ?>" class="poll-maker-upgrade-white-icon">
                                <span><?php echo esc_html__( "Upgrade", "poll-maker" ); ?></span>
                            </a>
                            <span class="ays-poll-logo-container-one-time-text"><?php echo esc_html__( "One-time payment", "poll-maker" ); ?></span>
                        </div>                        
                    </div>
                    <ul id="menu">
                        <li class="modile-ddmenu-lg"><a class="ays-btn" href="https://ays-pro.com/wordpress/poll-maker/?utm_source=dashboard&utm_medium=poll-free&utm_campaign=poll-top-banner-pricing-link-<?php echo esc_attr( POLL_MAKER_AYS_VERSION ); ?>" target="_blank"><?php echo esc_html__( "Pricing", 'poll-maker' ); ?></a></li>
                        <li class="modile-ddmenu-lg"><a class="ays-btn" href="https://poll-plugin.com/wordpress-poll-plugin-free-demo/" target="_blank"><?php echo esc_html__( "Demo", 'poll-maker' ); ?></a></li>
                        <li class="modile-ddmenu-lg"><a class="ays-btn" href="https://wordpress.org/support/plugin/poll-maker/" target="_blank"><?php echo esc_html__( "Free Support", 'poll-maker' ); ?></a></li>                        
                        <li class="ays_poll_take_gift"><a class="ays-btn" href="https://poll-plugin.com/poll-coupon-code-as-a-gift/" target="_blank"><?php echo esc_html__( "Get 50% discount", 'poll-maker' ); ?></a></li>
                        <li class="modile-ddmenu-lg"><a class="ays-btn" href="https://ays-pro.com/contact" target="_blank"><?php echo esc_html__( "Contact us", 'poll-maker' ); ?></a></li>
                        <li class="modile-ddmenu-md">
                            <a class="toggle_ddmenu" href="javascript:void(0);"><i class="ays_poll_fa ays_fa_ellipsis_h"></i></a>
                            <ul class="ddmenu" data-expanded="false">
                                <li><a class="ays-btn" href="https://ays-pro.com/wordpress/poll-maker/?utm_source=dashboard&utm_medium=poll-free&utm_campaign=poll-top-banner-pricing-link-<?php echo esc_attr( POLL_MAKER_AYS_VERSION ); ?>" target="_blank"><?php echo esc_html__( "Pricing", 'poll-maker' ); ?></a></li>
                                <li class="ays_poll_take_gift"><a class="ays-btn" href="https://poll-plugin.com/poll-coupon-code-as-a-gift/" target="_blank"><?php echo esc_html__( "Get 50% discount", 'poll-maker' ); ?></a></li>
                                <li><a class="ays-btn" href="https://poll-plugin.com/wordpress-poll-plugin-free-demo/" target="_blank"><?php echo esc_html__( "Demo", 'poll-maker' ); ?></a></li>
                                <li><a class="ays-btn" href="https://wordpress.org/support/plugin/poll-maker/" target="_blank"><?php echo esc_html__( "Free Support", 'poll-maker' ); ?></a></li>
                                <li><a class="ays-btn" href="https://ays-pro.com/contact" target="_blank"><?php echo esc_html__( "Contact us", 'poll-maker' ); ?></a></li>
                            </ul>
                        </li>
                        <li class="modile-ddmenu-sm">
                            <a class="toggle_ddmenu" href="javascript:void(0);"><i class="ays_poll_fa ays_fa_ellipsis_h"></i></a>
                            <ul class="ddmenu" data-expanded="false">                               
                                <li><a class="ays-btn" href="https://ays-pro.com/wordpress/poll-maker/?utm_source=dashboard&utm_medium=poll-free&utm_campaign=poll-top-banner-pricing-link-<?php echo esc_attr( POLL_MAKER_AYS_VERSION ); ?>" target="_blank"><?php echo esc_html__( "Pricing", 'poll-maker' ); ?></a></li>
                                <li><a class="ays-btn" href="https://poll-plugin.com/wordpress-poll-plugin-free-demo/" target="_blank"><?php echo esc_html__( "Demo", 'poll-maker' ); ?></a></li>
                                <li><a class="ays-btn" href="https://wordpress.org/support/plugin/poll-maker/" target="_blank"><?php echo esc_html__( "Free Support", 'poll-maker' ); ?></a></li>
                                <li class="ays_poll_take_gift"><a class="ays-btn" href="https://poll-plugin.com/poll-coupon-code-as-a-gift/" target="_blank"><?php echo esc_html__( "Get 50% discount", 'poll-maker' ); ?></a></li>
                                <li><a class="ays-btn" href="https://ays-pro.com/contact" target="_blank"><?php echo esc_html__( "Contact us", 'poll-maker' ); ?></a></li>
                            </ul>
                        </li>
                    </ul>
                    <div class="ays-poll-checklist-open-icon" style="<?php echo (1 == 0 ) ? 'display: none;' : ''; ?>" title="<?php echo esc_html__( "Checklist", 'poll-maker' ); ?>">
                        <svg width="18" height="24" viewBox="0 0 18 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <g clip-path="url(#clip0_130_42)">
                        <path d="M9 0C7.26094 0 5.83125 1.3125 5.64375 3H5.25C4.27031 3 3.43594 3.62812 3.12656 4.5H3C1.34531 4.5 0 5.84531 0 7.5V21C0 22.6547 1.34531 24 3 24H15C16.6547 24 18 22.6547 18 21V7.5C18 5.84531 16.6547 4.5 15 4.5H14.8734C14.5641 3.62812 13.7297 3 12.75 3H12.3563C12.1688 1.3125 10.7391 0 9 0ZM0.75 7.5C0.75 6.25781 1.75781 5.25 3 5.25V6C3 6.82969 3.67031 7.5 4.5 7.5H13.5C14.3297 7.5 15 6.82969 15 6V5.25C16.2422 5.25 17.25 6.25781 17.25 7.5V21C17.25 22.2422 16.2422 23.25 15 23.25H3C1.75781 23.25 0.75 22.2422 0.75 21V7.5ZM6.375 3.375C6.375 1.92656 7.55156 0.75 9 0.75C10.4484 0.75 11.625 1.92656 11.625 3.375C11.625 3.58125 11.7937 3.75 12 3.75H12.75C13.5797 3.75 14.25 4.42031 14.25 5.25V6C14.25 6.4125 13.9125 6.75 13.5 6.75H4.5C4.0875 6.75 3.75 6.4125 3.75 6V5.25C3.75 4.42031 4.42031 3.75 5.25 3.75H6C6.20625 3.75 6.375 3.58125 6.375 3.375ZM9 4.5C9.19891 4.5 9.38968 4.42098 9.53033 4.28033C9.67098 4.13968 9.75 3.94891 9.75 3.75C9.75 3.55109 9.67098 3.36032 9.53033 3.21967C9.38968 3.07902 9.19891 3 9 3C8.80109 3 8.61032 3.07902 8.46967 3.21967C8.32902 3.36032 8.25 3.55109 8.25 3.75C8.25 3.94891 8.32902 4.13968 8.46967 4.28033C8.61032 4.42098 8.80109 4.5 9 4.5ZM7.39219 11.1422C7.5375 10.9969 7.5375 10.7578 7.39219 10.6125C7.24687 10.4672 7.00781 10.4672 6.8625 10.6125L4.5 12.9703L3.64219 12.1078C3.49687 11.9625 3.25781 11.9625 3.1125 12.1078C2.96719 12.2531 2.96719 12.4922 3.1125 12.6375L4.2375 13.7625C4.38281 13.9078 4.62187 13.9078 4.76719 13.7625L7.39219 11.1375V11.1422ZM9 12.375C9 12.5813 9.16875 12.75 9.375 12.75H14.625C14.8313 12.75 15 12.5813 15 12.375C15 12.1687 14.8313 12 14.625 12H9.375C9.16875 12 9 12.1687 9 12.375ZM7.5 18C7.5 18.2063 7.66875 18.375 7.875 18.375H14.625C14.8313 18.375 15 18.2063 15 18C15 17.7937 14.8313 17.625 14.625 17.625H7.875C7.66875 17.625 7.5 17.7937 7.5 18ZM4.5 18.75C4.69891 18.75 4.88968 18.671 5.03033 18.5303C5.17098 18.3897 5.25 18.1989 5.25 18C5.25 17.8011 5.17098 17.6103 5.03033 17.4697C4.88968 17.329 4.69891 17.25 4.5 17.25C4.30109 17.25 4.11032 17.329 3.96967 17.4697C3.82902 17.6103 3.75 17.8011 3.75 18C3.75 18.1989 3.82902 18.3897 3.96967 18.5303C4.11032 18.671 4.30109 18.75 4.5 18.75Z" fill="black"/>
                        </g>
                        <defs>
                        <clipPath id="clip0_130_42">
                        <rect width="18" height="24" fill="white"/>
                        </clipPath>
                        </defs>
                        </svg>
                    </div>
                    <div class="ays-poll-checklist-popup" style="display:none; position: absolute; top: 80px; right: 0; background: #111; color: #fff; padding: 16px; border-radius: 6px; z-index: 9999; max-width: 250px;">
                        <div style="position: absolute; top: -10px; right: 20px; width: 0; height: 0; border-left: 10px solid transparent; border-right: 10px solid transparent; border-bottom: 10px solid #111;"></div>
                        <strong><?php echo esc_html__( "Looking for your Launchpad Checklist?", 'poll-maker' ); ?></strong>
                        <p><?php echo esc_html__( "Click the launch icon to continue setting up your poll.", 'poll-maker' ); ?></p>
                        <button class="ays-poll-checklist-popup-close" style="background-color: #07acc1; color: #fff; padding: 5px 10px; border: none; border-radius: 4px; cursor: pointer;"><?php echo esc_html__( "Got it", 'poll-maker' ); ?></button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ask a question box start -->
        <div class="ays_ask_question_content">
            <div class="ays_ask_question_content_inner">
                <a href="https://wordpress.org/support/plugin/poll-maker" class="ays_poll_question_link" target="_blank">
                    <span class="ays-ask-question-content-inner-question-mark-text">?</span>
                    <span class="ays-ask-question-content-inner-hidden-text"><?php echo esc_html__( "Ask a question", 'poll-maker' ); ?></span>
                </a>
            </div>
        </div>
        <!-- Ask a question box end -->        
<?php
	}
}

run_poll_maker_ays();