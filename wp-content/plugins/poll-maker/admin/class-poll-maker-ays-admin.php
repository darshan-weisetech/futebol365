<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://ays-pro.com/
 * @since      1.0.0
 *
 * @package    Poll_Maker_Ays
 * @subpackage Poll_Maker_Ays/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Poll_Maker_Ays
 * @subpackage Poll_Maker_Ays/admin
 * @author     Poll Maker Team <info@ays-pro.com>
 */
class Poll_Maker_Ays_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string $plugin_name The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string $version The current version of this plugin.
	 */
	private $version;

	private $polls_obj;
	private $cats_obj;
	private $results_obj;
	private $each_results_obj;
    private $settings_obj;
	private $answer_results_obj;
	private $capability;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param string $plugin_name The name of this plugin.
	 * @param string $version The version of this plugin.
	 *
	 * @since    1.0.0
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		add_filter('set-screen-option', array(__CLASS__, 'set_screen'), 10, 3);
		$per_page_array = array(
            'polls_per_page',
            'poll_cats_per_page',
            'poll_results_per_page',
        );
        foreach($per_page_array as $option_name){
            add_filter('set_screen_option_'.$option_name, array(__CLASS__, 'set_screen'), 10, 3);
        }
	}

	/**
	 * Register the styles for the admin menu area.
	 *
	 * @since    1.5.5
	 */
	public function admin_menu_styles() {
		echo "
        <style>
            #adminmenu a.toplevel_page_poll-maker-ays div.wp-menu-image img {
                width: 28px;
                padding-top: 2px;
            }

            #adminmenu li.toplevel_page_poll-maker-ays ul.wp-submenu.wp-submenu-wrap li:last-child a {
                color: #68A615;
                font-weight: bold;
            }

            .apm-badge {
                position: relative;
                top: -1px;
                right: -3px;
            }

            .apm-badge.badge-danger {
                color: #fff;
                background-color: #ca4a1f;
            }

            .apm-badge.badge {
                display: inline-block;
                vertical-align: top;
                margin: 1px 0 0 2px;
                padding: 0 5px;
                min-width: 7px;
                height: 17px;
                border-radius: 11px;
                font-size: 9px;
                line-height: 17px;
                text-align: center;
                z-index: 26;
            }

            .wp-first-item .apm-badge {
                display: none;
            }

            .apm-badge.badge.apm-no-results {
                display: none;
            }
        </style>
		";
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles( $hook_suffix ) {
		wp_enqueue_style($this->plugin_name . '-admin', plugin_dir_url(__FILE__) . 'css/admin.css', array(), $this->version, 'all');
		wp_enqueue_style($this->plugin_name . '-sweetalert-css', plugin_dir_url(__FILE__) .  'css/poll-maker-sweetalert2.min.css', array(), $this->version, 'all');
		if (false === strpos($hook_suffix, $this->plugin_name)) {
			return;
		}
		// wp_enqueue_style('wp-color-picker');
		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Poll_Maker_Ays_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Poll_Maker_Ays_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */
		
		// You need styling for the datepicker. For simplicity I've linked to the jQuery UI CSS on a CDN.
        wp_register_style( 'jquery-ui', 'https://code.jquery.com/ui/1.12.1/themes/smoothness/jquery-ui.css' );
        wp_enqueue_style( 'jquery-ui' );

		wp_enqueue_style('wp-color-picker');
		wp_enqueue_style('ays_poll_animate.css', plugin_dir_url(__FILE__) . 'css/animate.min.css', array(), $this->version, 'all');
		wp_enqueue_style( $this->plugin_name . '-font-awesome', plugin_dir_url(__FILE__) . 'css/poll-maker-font-awesome-all.css', array(), $this->version, 'all');
		wp_enqueue_style('ays_poll_bootstrap', plugin_dir_url(__FILE__) . 'css/bootstrap.min.css', array(), $this->version, 'all');
		wp_enqueue_style($this->plugin_name.'-jquery-datetimepicker', plugin_dir_url(__FILE__) . 'css/jquery-ui-timepicker-addon.css', array(), $this->version, 'all');
		wp_enqueue_style('ays-poll-select2', plugin_dir_url(__FILE__) . 'css/select2.min.css', array(), $this->version, 'all');
		wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'css/poll-maker-ays-admin.css', array(), $this->version, 'all');
		wp_enqueue_style($this->plugin_name.'popup-layer', plugin_dir_url(__FILE__) . 'css/poll-maker-ays-admin-popup-layer.css', array(), $this->version, 'all');
		wp_enqueue_style( $this->plugin_name . '-dropdown', plugin_dir_url(__FILE__) .  '/css/dropdown.min.css', array(), $this->version, 'all');
        wp_enqueue_style( $this->plugin_name . '-transition', plugin_dir_url(__FILE__) .  '/css/transition.min.css', array(), $this->version, 'all');


	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts( $hook_suffix ) {
		wp_enqueue_script( $this->plugin_name . '-dropdown-min', plugin_dir_url(__FILE__) . '/js/dropdown.min.js', array('jquery'), $this->version, true);
        wp_enqueue_script( $this->plugin_name . '-transition-min', plugin_dir_url(__FILE__) . '/js/transition.min.js', array('jquery'), $this->version, true);
		global $wp_version;
		if (false !== strpos($hook_suffix, "plugins.php")){
			wp_enqueue_script('sweetalert-js-poll', plugin_dir_url(__FILE__) . 'js/sweetalert2.all.min.js', array('jquery'), $this->version, true);

			wp_enqueue_script($this->plugin_name . '-admin', plugin_dir_url(__FILE__) . 'js/admin.js', array('jquery'), $this->version, true);
			wp_localize_script($this->plugin_name . '-admin', 'apm_admin_ajax_obj',
			array(
				'ajaxUrl' => admin_url('admin-ajax.php'),

				'errorMsg'              => __( "Error", 'poll-maker' ),
                'loadResource'          => __( "Can't load resource.", 'poll-maker' ),
                'somethingWentWrong'    => __( "Maybe something went wrong.", 'poll-maker' ),
			));
		}

		$version1 = $wp_version;
        $operator = '>=';
        $version2 = '5.5';
        $versionCompare = $this->versionCompare($version1, $operator, $version2);
        if ($versionCompare) {	
            wp_enqueue_script( $this->plugin_name.'-wp-load-scripts', plugin_dir_url(__FILE__) . 'js/ays-wp-load-scripts.js', array(), $this->version, true);
        }	
		
		if (false === strpos($hook_suffix, $this->plugin_name)) {
			return;
		}

		$poll_banner_date = $this->ays_poll_update_banner_time();

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Poll_Maker_Ays_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Poll_Maker_Ays_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */
		wp_enqueue_script('jquery');
		wp_enqueue_script('jquery-ui-datepicker');
		wp_enqueue_media();
		wp_enqueue_script( $this->plugin_name.'-wp-color-picker-alpha', plugin_dir_url(__FILE__) . 'js/wp-color-picker-alpha.min.js', array('wp-color-picker'), $this->version, true);
		wp_enqueue_script('ays_poll_popper', plugin_dir_url(__FILE__) . 'js/popper.min.js', array('jquery'), $this->version, true);
		wp_enqueue_script('ays_poll_bootstrap', plugin_dir_url(__FILE__) . 'js/bootstrap.min.js', array('jquery'), $this->version, true);
		wp_enqueue_script('ays_poll_select2', plugin_dir_url(__FILE__) . 'js/select2.min.js', array('jquery'), $this->version, true);
		wp_enqueue_script('sweetalert-js-poll', plugin_dir_url(__FILE__) . 'js/sweetalert2.all.min.js', array('jquery'), $this->version, true);
		wp_enqueue_script( $this->plugin_name."-jquery.datetimepicker.js", plugin_dir_url( __FILE__ ) . 'js/jquery-ui-timepicker-addon.js', array( 'jquery' ), $this->version, true );
		wp_enqueue_script('ays-poll-admin-js', plugin_dir_url(__FILE__) . 'js/poll-maker-ays-admin.js', array('jquery', 'wp-color-picker'),  $this->version, true);		
		wp_enqueue_script( $this->plugin_name . '-charts-google', plugin_dir_url(__FILE__) . 'js/google-chart.js', array('jquery'), $this->version, false);
		wp_localize_script('ays-poll-admin-js', 'pollLangObj', array(
			'pollBannerDate' 		  		=> $poll_banner_date,
			'errorMsg'				  		=> esc_html__('Error', "poll-maker"),
            'somethingWentWrong' 	  		=> esc_html__('Maybe something went wrong.', "poll-maker"),
            'add' 					  		=> esc_html__('Add', "poll-maker"),
            'answersMinCount' 		  		=> esc_html__('Sorry minimum count of answers should be 2', "poll-maker"),
            'copied' 				  		=> esc_html__('Copied!', "poll-maker"),
            'clickForCopy' 			  		=> esc_html__('Click for copy.', "poll-maker"),
			'areYouSure' 			  		=> esc_html__('Are you sure you want to redirect to another poll? Note that the changes made in this poll will not be saved.', "poll-maker"),
			'deleteAnswer' 			  		=> esc_html__('Are you sure you want to delete this answer?', "poll-maker"),
			'youPollIsCreated'		  		=> esc_html__('Your Poll is Created!', 'poll-maker'),
			'youCanUuseThisShortcode' 		=> esc_html__('Copy the generated shortcode and paste it into any post or page to display Poll', "poll-maker"),
			'greateJob' 			  		=> esc_html__('Great job', "poll-maker"),
			'editPollPage'			  		=> esc_html__( 'edit poll page', 'poll-maker'),
			'formMoreDetailed' 		  		=> esc_html__('For more detailed configuration visit', "poll-maker"),
            'done' 					  		=> esc_html__('Done', "poll-maker"),
            'thumbsUpGreat' 		  		=> esc_html__('Thumbs up, Done', "poll-maker"),
            "preivewPoll"             		=> esc_html__( "Preview Poll", 'poll-maker' ),
            'successCopyCoupon'       		=> esc_html__( "Coupon code copied!", 'poll-maker' ),
            'failedCopyCoupon'        		=> esc_html__( "Failed to copy coupon code", 'poll-maker' ),
        ) );

		wp_localize_script('ays-poll-admin-js', 'poll', array(
            'ajax' => admin_url('admin-ajax.php'),
            'pleaseEnterMore' =>esc_html__('Please select more', "poll-maker"),
            'urlImg' => (esc_url(POLL_MAKER_AYS_ADMIN_URL) . '/images/'),
            "emptyEmailError"                   => esc_html__( 'Email field is empty', 'poll-maker'),
            "invalidEmailError"                 => esc_html__( 'Invalid Email address', 'poll-maker'),
            'selectUser'                        => esc_html__( 'Select user', 'poll-maker'),
            'pleaseEnterMore'                   => esc_html__( "Please enter 1 or more characters", 'poll-maker' ),
            'searching'                         => esc_html__( "Searching...", 'poll-maker' ),
            'activated'                         => esc_html__( "Activated", 'poll-maker' ),
            'errorMsg'                          => esc_html__( "Error", 'poll-maker' ),
            'loadResource'                      => esc_html__( "Can't load resource.", 'poll-maker' ),
            'somethingWentWrong'                => esc_html__( "Maybe something went wrong.", 'poll-maker' ),
            'greateJob'                         => esc_html__( 'Great job', 'poll-maker'),
            'formMoreDetailed'                  => esc_html__( 'For more detailed configuration visit', 'poll-maker'),
            'greate'                            => esc_html__( 'Great!', 'poll-maker'),

        ));

		wp_enqueue_script( $this->plugin_name . '-quick-start-js', plugin_dir_url(__FILE__) . 'js/poll-maker-poll-quick-start.js', array('jquery'), $this->version, true);
		wp_enqueue_script( $this->plugin_name . '-admin-ajax', plugin_dir_url(__FILE__) . 'js/poll-maker-ays-ajax-admin.js', array('jquery'), $this->version, true);
		
		wp_localize_script($this->plugin_name . '-admin-ajax', 'poll_maker_ajax', array(
            'ajax_url'                          => admin_url('admin-ajax.php'),
            'nonce'                             => wp_create_nonce('ays_poll_nonce'),
            "emptyEmailError"                   => esc_html__( 'Email field is empty', 'poll-maker'),
            "invalidEmailError"                 => esc_html__( 'Invalid Email address', 'poll-maker'),
            'selectUser'                        => esc_html__( 'Select user', 'poll-maker'),
            'pleaseEnterMore'                   => esc_html__( "Please enter 1 or more characters", 'poll-maker' ),
            'searching'                         => esc_html__( "Searching...", 'poll-maker' ),
            'activated'                         => esc_html__( "Activated", 'poll-maker' ),
            'errorMsg'                          => esc_html__( "Error", 'poll-maker' ),
            'loadResource'                      => esc_html__( "Can't load resource.", 'poll-maker' ),
            'somethingWentWrong'                => esc_html__( "Maybe something went wrong.", 'poll-maker' ),
            'youCanUuseThisShortcode'           => esc_html__( 'Copy the generated shortcode and paste it into any post or page to display Poll.', 'poll-maker'),
            'youPollIsCreated'                  => esc_html__( 'Your Poll is Created!', 'poll-maker'),
            'greateJob'                         => esc_html__( 'Great job', 'poll-maker'),
            'formMoreDetailed'                  => esc_html__( 'For more detailed configuration visit', 'poll-maker'),
            'editpollPage'                      => esc_html__( 'edit poll page', 'poll-maker'),
            'greate'                            => esc_html__( 'Great!', 'poll-maker'),
            'thumbsUpGreat'                     => esc_html__( 'Thumbs up, great!', 'poll-maker'),
            "preivewPoll"                       => esc_html__( "Preview Poll", 'poll-maker' ),

            "checklistMarkAsDone"               => esc_html__( "Mark as done", 'poll-maker' ),
            "checklistUnmarkAsDone"             => esc_html__( "Unmark as done", 'poll-maker' ),
        ));
		$color_picker_strings = array(
			'clear' =>esc_html__('Clear', "poll-maker"),
			'clearAriaLabel' =>esc_html__('Clear color', "poll-maker"),
			'defaultString' =>esc_html__('Default', "poll-maker"),
			'defaultAriaLabel' =>esc_html__('Select default color', "poll-maker"),
			'pick' =>esc_html__('Select Color', "poll-maker"),
			'defaultLabel' =>esc_html__('Color value', "poll-maker"),
		);
		wp_localize_script( $this->plugin_name.'-wp-color-picker-alpha', 'wpColorPickerL10n', $color_picker_strings );
	}

	public function ays_poll_add_body_class( $classes ) {
        global $pagenow;

        if ( $pagenow === 'admin.php' ) {
            $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
            
            if ( strpos( $page, $this->plugin_name ) === 0 ) {
                $classes .= ' ays-poll-plugin-admin';
            }
        }

        return $classes;
    }

	/**
	 * De-register JavaScript files for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function disable_scripts($hook_suffix) {
		if (false !== strpos($hook_suffix, $this->plugin_name)) {
            if (is_plugin_active('ai-engine/ai-engine.php')) {
                wp_deregister_script('mwai');
                wp_deregister_script('mwai-vendor');
                wp_dequeue_script('mwai');
                wp_dequeue_script('mwai-vendor');
            }

            if (is_plugin_active('html5-video-player/html5-video-player.php')) {
                wp_dequeue_style('h5vp-admin');
                wp_dequeue_style('fs_common');
            }

            if (is_plugin_active('panorama/panorama.php')) {
                wp_dequeue_style('bppiv_admin_custom_css');
                wp_dequeue_style('bppiv-custom-style');
            }

            if (is_plugin_active('wp-social/wp-social.php')) {
                wp_dequeue_style('wp_social_select2_css');
                wp_deregister_script('wp_social_select2_js');
                wp_dequeue_script('wp_social_select2_js');
            }

            if (is_plugin_active('real-media-library-lite/index.php')) {
                wp_dequeue_style('real-media-library-lite-rml');
            }

            // Theme | Pixel Ebook Store
            wp_dequeue_style('pixel-ebook-store-free-demo-content-style');

            // Theme | Interactive Education
            wp_dequeue_style('interactive-education-free-demo-content-style');

            // Theme | Phlox 2.17.6
            wp_dequeue_style('auxin-admin-style');
		}
	}

	public function ays_poll_disable_all_notice_from_plugin() {
        if (!function_exists('get_current_screen')) {
            return;
        }

        $screen = get_current_screen();

        if (empty($screen) || strpos($screen->id, $this->plugin_name) === false) {
            return;
        }

        global $wp_filter;

        // Keep plugin-specific notices
        $our_plugin_notices = array();

        $exclude_functions = [
            'poll_maker_admin_notice',
        ];

        if (!empty($wp_filter['admin_notices'])) {
            foreach ($wp_filter['admin_notices']->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $key => $callback) {
                    // For class-based methods
                    if (
                        is_array($callback['function']) &&
                        is_object($callback['function'][0]) &&
                        get_class($callback['function'][0]) === __CLASS__
                    ) {
                        $our_plugin_notices[$priority][$key] = $callback;
                    }
                    // For standalone functions
                    elseif (
                        is_string($callback['function']) &&
                        in_array($callback['function'], $exclude_functions)
                    ) {
                        $our_plugin_notices[$priority][$key] = $callback;
                    }
                }
            }
        }

        // Remove all notices
        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');

        // Re-add only your plugin's notices
        foreach ($our_plugin_notices as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                add_action('admin_notices', $callback['function'], $priority);
            }
        }
    }

	public function codemirror_enqueue_scripts($hook) {
        if(strpos($hook, $this->plugin_name) !== false){
            if(function_exists('wp_enqueue_code_editor')){
                $cm_settings['codeEditor'] = wp_enqueue_code_editor(array(
                    'type'              => 'text/css',
                    'codemirror'        => array(
                        'inputStyle'    => 'contenteditable',
                        'theme'         => 'cobalt',
                    )
                ));

                wp_enqueue_script('wp-theme-plugin-editor');
                wp_localize_script('wp-theme-plugin-editor', 'cm_settings', $cm_settings);

                wp_enqueue_style('wp-codemirror');
            }
        }
	}
	
	public function versionCompare($version1, $operator, $version2) {
   
        $_fv = intval ( trim ( str_replace ( '.', '', $version1 ) ) );
        $_sv = intval ( trim ( str_replace ( '.', '', $version2 ) ) );
       
        if (strlen ( $_fv ) > strlen ( $_sv )) {
            $_sv = str_pad ( $_sv, strlen ( $_fv ), 0 );
        }
       
        if (strlen ( $_fv ) < strlen ( $_sv )) {
            $_fv = str_pad ( $_fv, strlen ( $_sv ), 0 );
        }
       
        return version_compare ( ( string ) $_fv, ( string ) $_sv, $operator );
    }

	public function add_plugin_admin_menu() {

		$settings_actions = new Poll_Maker_Settings_Actions($this->plugin_name);
		$options_setting = $settings_actions->ays_get_setting('options');
		$options = ($options_setting === false) ? array() : json_decode(stripcslashes($options_setting), true);
		$options = is_array($options) ? $options : array();
		$disable_poll_menu_notification = isset($options['poll_disable_poll_menu_notification']) && esc_attr($options['poll_disable_poll_menu_notification']) === 'on';

		$menu_item = 'Poll Maker';
		if (!$disable_poll_menu_notification) {
			/*
			 * Check unread results
			 *
			 */
			global $wpdb;
			$sql            = "SELECT COUNT(unread) FROM {$wpdb->prefix}ayspoll_reports WHERE unread=1";
			$unread_results = $wpdb->get_var($sql);

			if ($unread_results > 0) {
				$menu_item .= '<span style="margin-right: 10px;" class="apm-badge badge badge-danger">' . $unread_results . '</span>';
			}
		}

		/*
		 * Add a settings page for this plugin to the Settings menu.
		 *
		 * NOTE:  Alternative menu locations are available via WordPress administration menu functions.
		 *
		 *        Administration Menus: http://codex.wordpress.org/Administration_Menus
		 *
		 */

		$this->capability = $this->poll_maker_capabilities();
        $capability = $this->poll_maker_capabilities();
		$hook_poll = add_menu_page(
			'Poll Maker', 
			$menu_item,
			$capability,
			$this->plugin_name, 
			array($this,'display_plugin_polls_page'),
			esc_url(POLL_MAKER_AYS_ADMIN_URL) . '/images/icons/icon-poll-maker-128x128.svg',
			'6.33'
		);

		add_action("load-$hook_poll", array($this, 'screen_option_polls'));
		add_action("load-$hook_poll", array($this, 'add_tabs'));

		$hook_results_each = add_submenu_page(
			'all_results_slug',
			 esc_html__('Results per poll', "poll-maker"),
			 esc_html__('Results per poll', "poll-maker"),
			$capability,
			$this->plugin_name . '-results-each',
			array($this, 'display_plugin_results_each_page')
		);
		add_action("load-$hook_results_each", array($this, 'screen_option_each_results'));

	}


	public function add_plugin_dashboard_submenu() {
		$capability = $this->poll_maker_capabilities();

		$hook_polls = add_submenu_page(
			$this->plugin_name,
			 esc_html__('Dashboard', "poll-maker"),
			 esc_html__('Dashboard', "poll-maker"),
			$capability,
			$this->plugin_name . "-dashboard",
			array($this, 'display_plugin_dashboard_page')
		);
	}


	public function add_plugin_polls_submenu() {
		$capability = $this->poll_maker_capabilities();

		$hook_polls = add_submenu_page(
			$this->plugin_name,
			 esc_html__('All Polls', "poll-maker"),
			 esc_html__('All Polls', "poll-maker"),
			$capability,
			$this->plugin_name,
			array($this, 'display_plugin_polls_page')
		);
		add_action("load-$hook_polls", array($this, 'screen_option_polls'));
		add_action("load-$hook_polls", array($this, 'add_tabs'));
	}

	public function add_plugin_add_new_poll_submenu() {
		$capability = $this->poll_maker_capabilities();

		$hook_polls = add_submenu_page(
			$this->plugin_name,
			 esc_html__('Add new', "poll-maker"),
			 esc_html__('Add new', "poll-maker"),
			$capability,
			$this->plugin_name . '-add-new',
			array($this, 'display_plugin_add_new_poll_page')
		);
	}

	public function add_plugin_categories_submenu() {
		$capability = $this->poll_maker_capabilities();

		$hook_cats = add_submenu_page(
			$this->plugin_name,
			 esc_html__('Categories', "poll-maker"),
			 esc_html__('Categories', "poll-maker"),
			$capability,
			$this->plugin_name . '-cats',
			array($this, 'display_plugin_cats_page')
		);
		add_action("load-$hook_cats", array($this, 'screen_option_cats'));
		add_action("load-$hook_cats", array($this, 'add_tabs'));
	}

	public function add_plugin_results_submenu() {
		/*
		 * Check unread results
		 *
		 */
		global $wpdb;
		$sql            = "SELECT COUNT(unread) FROM {$wpdb->prefix}ayspoll_reports WHERE unread=1";
		$unread_results = $wpdb->get_var($sql);
		$show           = $unread_results > 0 ? '' : "apm-no-results";

		$capability = $this->poll_maker_capabilities();

		$hook_results = add_submenu_page(
			$this->plugin_name,
			 esc_html__('Results', "poll-maker"),
			 esc_html__('Results', "poll-maker") . " <span class=\"apm-badge badge badge-danger $show\">$unread_results</span>",
			$capability,
			$this->plugin_name . '-results',
			array($this, 'display_plugin_results_page')
		);
		add_action("load-$hook_results", array($this, 'screen_option_results'));
		add_action("load-$hook_results", array($this, 'add_tabs'));

		$hook_all_results = add_submenu_page(
            'all_results_slug',
           esc_html__('Results', "poll-maker"),
            $capability,
            $this->capability,
            $this->plugin_name . '-all-results',
            array($this, 'display_plugin_all_results_page')
		);
		
		add_action("load-$hook_all_results", array($this, 'screen_option_all_poll_results'));

		add_filter('parent_file', array($this,'poll_maker_select_submenu'));
	}

	public function add_plugin_formfields_submenu() {

		$hook_formfields = add_submenu_page(
			$this->plugin_name,
			 esc_html__('Custom Fields', "poll-maker"),
			 esc_html__('Custom Fields', "poll-maker"),
			'manage_options',
			$this->plugin_name . '-formfields',
			array($this, 'display_plugin_formfields_page')
		);
		add_action("load-$hook_formfields", array($this, 'add_tabs'));
	}

	public function add_plugin_general_settings_submenu() {
		$hook_settings = add_submenu_page($this->plugin_name,
			 esc_html__('General Settings', "poll-maker"),
			 esc_html__('General Settings', "poll-maker"),
			'manage_options',
			$this->plugin_name . '-settings',
			array($this, 'display_plugin_settings_page')
		);
		add_action("load-$hook_settings", array($this, 'screen_option_settings'));
		add_action("load-$hook_settings", array($this, 'add_tabs'));
	}

	public function add_plugin_how_to_use_submenu() {
		$hook_pro_features = add_submenu_page(
			$this->plugin_name,
			 esc_html__('How to use', "poll-maker"),
			 esc_html__('How to use', "poll-maker"),
			'manage_options',
			$this->plugin_name . '-how-to-use',
			array($this, 'display_plugin_how_to_use_page')
		);
		add_action("load-$hook_pro_features", array($this, 'add_tabs'));
	}

	public function add_plugin_pro_features_submenu() {
		$hook_pro_features = add_submenu_page(
			$this->plugin_name,
			 esc_html__('PRO features', "poll-maker"),
			 esc_html__('PRO features', "poll-maker"),
			'manage_options',
			$this->plugin_name . '-pro-features',
			array($this, 'display_plugin_pro_features_page')
		);
		add_action("load-$hook_pro_features", array($this, 'add_tabs'));
	}

	public function add_plugin_featured_plugins_submenu(){
        $hook_pro_features = add_submenu_page( $this->plugin_name,
           esc_html__('Our products', "poll-maker"),
           esc_html__('Our products', "poll-maker"),
            'manage_options',
            $this->plugin_name . '-featured-plugins',
            array($this, 'display_plugin_featured_plugins_page') 
        );
		add_action("load-$hook_pro_features", array($this, 'add_tabs'));
	}	

	/**
	 * Add settings action link to the plugins page.
	 *
	 * @since    1.0.0
	 */

	public function add_action_links( $links ) {
		/*
		 *  Documentation : https://codex.wordpress.org/Plugin_API/Filter_Reference/plugin_action_links_(plugin_file_name)
		 */
		$poll_ajax_deactivate_plugin_nonce = wp_create_nonce( 'poll-maker-ajax-deactivate-plugin-nonce' );

		$settings_link = array(
			'<a href="' . admin_url('admin.php?page=' . $this->plugin_name) . '">' .esc_html__('Settings', "poll-maker") . '</a>',
			'<a href="https://poll-plugin.com/wordpress-poll-plugin-free-demo/" target="_blank">' .esc_html__('Demo', "poll-maker") . '</a>',			
			'<a href="https://ays-pro.com/wordpress/poll-maker?utm_source=dashboard&utm_medium=poll-free&utm_campaign=plugins-buy-now-button" class="ays-poll-upgrade-plugin-btn" style="font-weight:bold;color:#01A32A;" target="_blank">' .esc_html__('Upgrade 50% Sale', "poll-maker") . '</a><input type="hidden" id="ays_poll_maker_ajax_deactivate_plugin_nonce" name="ays_poll_maker_ajax_deactivate_plugin_nonce" value="' . $poll_ajax_deactivate_plugin_nonce .'">',
		);

		return array_merge($settings_link, $links);

	}

	public function add_poll_row_meta( $links, $file ) {
        if ( POLL_MAKER_AYS_BASENAME == $file ) {
            $row_meta = array(
                'ays-poll-support'			=> '<a href="' . esc_url( 'https://wordpress.org/support/plugin/poll-maker/' ) . '" target="_blank">' . esc_html__( 'Free Support', "poll-maker" ) . '</a>',
                'ays-poll-documentation'	=> '<a href="' . esc_url( 'https://ays-pro.com/wordpress-poll-maker-user-manual' ) . '" target="_blank">' . esc_html__( 'Documentation', "poll-maker" ) . '</a>',
                'ays-poll-rate-us'			=> '<a href="' . esc_url( 'https://wordpress.org/support/plugin/poll-maker/reviews/?rate=5#new-post' ) . '" target="_blank">' . esc_html__( 'Rate us', "poll-maker" ) . '</a>',
                'ays-poll-video-tutorial'	=> '<a href="' . esc_url( 'https://www.youtube.com/channel/UC-1vioc90xaKjE7stq30wmA' ) . '" target="_blank">' . esc_html__( 'Video tutorial', "poll-maker" ) . '</a>',
                );

            return array_merge( $links, $row_meta );
        }
        return $links;
    }

	/**
	 * Render the settings page for this plugin.
	 *
	 * @since    1.0.0
	 */
	public function display_plugin_dashboard_page() {
		if ( ! class_exists( 'Poll_Maker_Ays_Welcome' ) ) {
			require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-poll-maker-ays-welcome.php';
		}
		$welcome_page = new Poll_Maker_Ays_Welcome();
		$welcome_page->output(true);
	}

	/**
	 * Render the settings page for this plugin.
	 *
	 * @since    1.0.0
	 */

	public function display_plugin_polls_page() {
		$action = (isset($_GET['action'])) ? sanitize_text_field($_GET['action']) : '';

		switch ( $action ) {
			case 'add':
				include_once 'partials/poll-maker-ays-polls-actions.php';
				break;
			case 'edit':
				include_once 'partials/poll-maker-ays-polls-actions.php';
				break;
			default:
				include_once 'partials/poll-maker-ays-admin-display.php';
		}
	}

	public function display_plugin_add_new_poll_page() {
		$add_new_poll_url = admin_url('admin.php?page=' . $this->plugin_name . '&action=add');
		wp_redirect($add_new_poll_url);
	}

	public function display_plugin_cats_page() {
		$action = (isset($_GET['action'])) ? sanitize_text_field($_GET['action']) : '';

		switch ( $action ) {
			case 'add':
				include_once 'partials/categories/actions/poll-maker-ays-categories-actions.php';
				break;
			case 'edit':
				include_once 'partials/categories/actions/poll-maker-ays-categories-actions.php';
				break;
			default:
				include_once 'partials/categories/poll-maker-ays-categories-display.php';
		}
	}

	public function display_plugin_results_page() {
		include_once 'partials/results/poll-maker-ays-results-display.php';
	}

	public function display_plugin_results_each_page() {
		include_once 'partials/results/poll-maker-ays-each-results-display.php';
	}

	public function display_plugin_formfields_page() {
		include_once 'partials/features/poll-maker-formfields_page-display.php';
	}

	public function display_plugin_pro_features_page() {
		include_once 'partials/features/poll-maker-pro-features-display.php';
	}

	public function display_plugin_how_to_use_page() {
		include_once 'partials/features/poll-maker-how-to-use-display.php';
	}

	public function display_plugin_featured_plugins_page(){
        include_once('partials/features/poll-maker-featured-display.php');
    }

	public static function set_screen( $status, $option, $value ) {
		return $value;
	}	

    public function poll_hide_screen_options( $show_screen, $screen ) {
    	// How check screen ID: get_current_screen()->id

	    if ( empty( $screen->id ) ) {
	        return $show_screen;
	    }

	    if (
	        strpos( $screen->id, 'toplevel_page_poll-maker-ays' ) !== false ||
	        strpos( $screen->id, '_page_poll-maker-ays-cats' ) !== false ||	        
	        strpos( $screen->id, '_page_poll-maker-ays-settings' ) !== false
	    ) {
	        return false;
	    }

	    return $show_screen;
	}

	public function screen_option_polls() {
		$option = 'per_page';
		$args   = array(
			'label'   =>esc_html__('Polls', "poll-maker"),
			'default' => 20,
			'option'  => 'polls_per_page',
		);

		if( ! ( isset( $_GET['action'] ) && ( $_GET['action'] == 'add' || $_GET['action'] == 'edit' ) ) ){
			add_screen_option($option, $args);
        }else{
    	 	add_filter('screen_options_show_screen', array($this, 'poll_hide_screen_options'), 10, 2);
        }

		$this->polls_obj = new Polls_List_Table($this->plugin_name);
        $this->settings_obj = new Poll_Maker_Settings_Actions($this->plugin_name);

    }

	public function screen_option_cats() {
		$option = 'per_page';
		$args   = array(
			'label'   =>esc_html__('Categories', "poll-maker"),
			'default' => 5,
			'option'  => 'poll_cats_per_page',
		);

		if( ! ( isset( $_GET['action'] ) && ( $_GET['action'] == 'add' || $_GET['action'] == 'edit' ) ) ){
			add_screen_option($option, $args);
        }else{
        	add_filter('screen_options_show_screen', array($this, 'poll_hide_screen_options'), 10, 2);
        }

		$this->cats_obj = new Pma_Categories_List_Table($this->plugin_name);
		$this->settings_obj = new Poll_Maker_Settings_Actions($this->plugin_name);
	}

	public function screen_option_results() {
		$option = 'per_page';
		$args   = array(
			'label'   =>esc_html__('Results', "poll-maker"),
			'default' => 50,
			'option'  => 'poll_results_per_page',
		);
		
		add_screen_option($option, $args);
		$this->results_obj = new Pma_Results_List_Table($this->plugin_name);
		// $this->answer_results_obj = new Poll_Answer_Results($this->plugin_name);
	}

	public function screen_option_each_results() {
		$option = 'per_page';
		$args   = array(
			'label'   =>esc_html__('Results per poll', "poll-maker"),
			'default' => 50,
			'option'  => 'poll_each_results_per_page',
		);

		add_screen_option($option, $args);
		$this->each_results_obj = new Pma_Each_Results_List_Table($this->plugin_name);
	}

	public function register_poll_ays_widget() {
		global $wpdb;
		$poll_table = esc_sql($wpdb->prefix . "ayspoll_polls");
		$sql = "SELECT COUNT(*) FROM ".$poll_table;

		$c = $wpdb->get_var($sql);
		if ($c == 0) {
			return;
		} else {
			register_widget('Poll_Maker_Widget');
		}
	}

	public function poll_maker_el_widgets_registered() {
        wp_enqueue_style($this->plugin_name . '-admin', plugin_dir_url(__FILE__) . 'css/admin.css', array(), $this->version, 'all');
		// We check if the Elementor plugin has been installed / activated.
        if ( defined( 'ELEMENTOR_PATH' ) && class_exists( 'Elementor\Widget_Base' ) ) {
            // get our own widgets up and running:
            // copied from widgets-manager.php
            if ( class_exists( 'Elementor\Plugin' ) ) {
                if ( is_callable( 'Elementor\Plugin', 'instance' ) ) {
                    $elementor = Elementor\Plugin::instance();
                    if ( isset( $elementor->widgets_manager ) ) {
						if ( defined( 'ELEMENTOR_VERSION' ) && version_compare( ELEMENTOR_VERSION, '3.5.0', '>=' ) ) {
							if ( method_exists( $elementor->widgets_manager, 'register' ) ) {
								$widget_file   = 'plugins/elementor/poll_maker_elementor.php';
								$template_file = locate_template( $widget_file );
								if ( !$template_file || !is_readable( $template_file ) ) {
									$template_file = POLL_MAKER_AYS_DIR.'pb_templates/poll_maker_elementor.php';
								}
								if ( $template_file && is_readable( $template_file ) ) {
									require_once $template_file;
									Elementor\Plugin::instance()->widgets_manager->register( new Elementor\Widget_Poll_Maker_Elementor() );
								}
							}
						} else {
							if ( method_exists( $elementor->widgets_manager, 'register_widget_type' ) ) {
								$widget_file = 'plugins/elementor/poll_maker_elementor.php';
								$template_file = locate_template( $widget_file );
								if ( !$template_file || !is_readable( $template_file ) ) {
									$template_file = POLL_MAKER_AYS_DIR.'pb_templates/poll_maker_elementor.php';
								}
								if ( $template_file && is_readable( $template_file ) ) {
									require_once $template_file;
									Elementor\Plugin::instance()->widgets_manager->register_widget_type( new Elementor\Widget_Poll_Maker_Elementor() );
								}
							}
						}
                    }
                }
            }
        }
    }

	public function apm_deactivate_plugin_option() {
		// Run a security check.
		if (isset($_REQUEST['_ajax_nonce'])) {
			check_ajax_referer('poll-maker-ajax-deactivate-plugin-nonce', sanitize_key($_REQUEST['_ajax_nonce']));
		} else {
			// For multisite, if nonce is missing
			if (function_exists('is_multisite') && is_multisite()) {
				// Skip nonce verification for multisite
			} else {
				wp_send_json_error('Nonce verification failed');
				wp_die();
			}
		}
		// Check for permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			ob_end_clean();
			$ob_get_clean = ob_get_clean();
			echo json_encode(array(
				'option' => ''
			));
			wp_die();
		}
		
		if( is_user_logged_in() ) {
			$request_value = esc_sql( sanitize_text_field( $_REQUEST['upgrade_plugin'] ) );
				$upgrade_option = get_option('ays_poll_maker_upgrade_plugin','');
				if($upgrade_option === ''){
					add_option('ays_poll_maker_upgrade_plugin',$request_value);
				}else{
					update_option('ays_poll_maker_upgrade_plugin',$request_value);
				}
				ob_end_clean();
				$ob_get_clean = ob_get_clean();
				echo json_encode(array(
					'option' => get_option('ays_poll_maker_upgrade_plugin', '')
				));
			wp_die();
		} else {
			ob_end_clean();
			$ob_get_clean = ob_get_clean();
			echo json_encode(array(
				'option' => ''
			));
			wp_die();
		}
	}

	public function apm_show_results() {
		// Run a security check.
		check_ajax_referer( 'poll-maker-ajax-show-details-report-nonce', sanitize_key( $_REQUEST['_ajax_nonce'] ) );

		// Check for permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			ob_end_clean();
			$ob_get_clean = ob_get_clean();
			echo json_encode(array("success" => false));
			wp_die();
		}

		global $wpdb;
		$results_table = $wpdb->prefix . "ayspoll_reports";
		// $polls_obj     = new Polls_List_Table($this->plugin_name);
		if (isset($_POST['action']) && $_POST['action'] == 'apm_show_results') {

			$id         = isset($_POST['result']) ? absint($_POST['result']) : 0;
			$is_details = isset($_POST['is_details']) && absint($_POST['is_details']) > 0 ? true : false;
			$row        = '';
			$wpdb->update($results_table,
				array('unread' => 0),
				array('id' => $id),
				array('%d'),
				array('%d')
			);
			if ($id > 0 && $is_details) {
				$result = $wpdb->get_row("SELECT * FROM $results_table WHERE id=$id", "ARRAY_A");
				$multivote_res = false;
				$result['multi_answer_id'] = json_decode($result['multi_answer_ids']);
				if (isset($result['multi_answer_id']) && count($result['multi_answer_id']) > 1) {
					$multivote_res = true;
				}
				$multivote_answers = array();
				if ($multivote_res) {
					foreach ($result['multi_answer_id'] as $m_key => $m_val) {
						$multi_answer    = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}ayspoll_answers WHERE id=".$m_val, "ARRAY_A");
						$multivote_answers[] = $multi_answer['answer'];
					}
					$answ_poll_id = $multi_answer['poll_id'];
				} else {
					$answer     = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}ayspoll_answers WHERE id={$result['answer_id']}", "ARRAY_A");
					$multivote_answers[] = $answer['answer'];
					$answ_poll_id = $answer['poll_id'];
				}

				$poll       = $this->get_poll_by_id($answ_poll_id);
				$user_ip    = $result['user_ip'];
				$info = ($result['other_info'] == '' || $result['other_info'] === null || $result['other_info'] === 0) ? array() : json_decode($result['other_info'], true);

				$time       = $result['vote_date'];
				$user_email = $result['user_email'];
				$country = '';
				$region = '';
				$city = '';
            	$json    = isset($user_ip) && $user_ip != '' ? json_decode(file_get_contents("http://ipinfo.io/{$user_ip}/json")) : null;

				if ($json !== null) {
					$country = isset($json->country) && $json->country != '' ? $json->country : '';
					$region = isset($json->region) && $json->region != '' ? $json->region : '';
					$city = isset($json->city) && $json->city != '' ? $json->city : '';
				}
				$from    = "$city, $region, $country, $user_ip";
				$row     = '<tr><td colspan="4"><h1>' .esc_html__('Poll Information', "poll-maker") . "</h1></td></tr>
                    <tr class='ays_result_element'>
                        <td>".esc_html__('Poll Title', "poll-maker")."</td>
                        <td>{$poll['title']}</td>
                        <td></td>
                        <td></td>
                    </tr>";
				$row     .= "<tr class='ays_result_element'>
                        <td>".esc_html__('Poll Type', "poll-maker")."</td>
                        <td>" . ucfirst($poll['type']) . "</td>
                        <td></td>
                        <td></td>
                    </tr>";
				switch ( $poll['type'] ) {
					case 'choosing':
						$row .= "<tr class='ays_result_element'>
                        <td>".esc_html__('Answer', "poll-maker")."</td>
                        <td>" . (in_array($poll['answers'][0]['answer'], $multivote_answers) ? "<b><em>" . stripslashes($poll['answers'][0]['answer']) . "</em></b>" : stripslashes($poll['answers'][0]['answer'])) . "</td>
                        <td></td>
                        <td></td>
                    </tr>";
						foreach ( $poll['answers'] as $index => $ans ) {
							if ($index == 0) {
								continue;
							}
							$row .= "<tr class='ays_result_element'>
                            <td></td>
                            <td>" . (in_array($ans['answer'], $multivote_answers) ? "<b><em>" . stripslashes($ans['answer']) . "</em></b>" : stripslashes($ans['answer'])) . "</td>
                            <td></td>
                            <td></td>
                        </tr>";
						}
						break;
					case 'text':
						$row .= "<tr class='ays_result_element'>
							<td>".esc_html__('Answer', "poll-maker")."</td>
							<td><b><em>" . $answer['answer'] . "</em></b></td>
							<td></td>
							<td></td>
						</tr>";
						break;
					case 'rating':
						$row .= "<tr class='ays_result_element'>
                            <td>".esc_html__('Answer', "poll-maker")."</td>
                            <td><div class='apm-rating-res'>";
						if ($poll['view_type'] == 'star') {
							foreach ( $poll['answers'] as $ans ) {
								$row .= "<i class='" . ($ans['answer'] <= $answer['answer'] ? "ays_poll_fas" : "ays_poll_far") . " ays_poll_fa-star'></i>";
							}
						} elseif ('emoji') {
							$emoji = array(
								"ays_poll_fa-dizzy",
								"ays_poll_fa-smile",
								"ays_poll_fa-meh",
								"ays_poll_fa-frown",
								"ays_poll_fa-tired",
							);
							foreach ( $poll['answers'] as $i => $ans ) {
								$index = (count($poll['answers']) / 2 - $i + 1.5);
								$row   .= "<i class='" . ($ans['answer'] == $answer['answer'] ? "ays_poll_fas " : "ays_poll_far ") . $emoji[$index] . "'></i>";
							}
						}
						$row .= "</div></td>
                            <td></td>
                            <td></td>
                        </tr>";
						break;
					case 'voting':
						$row   .= "<tr class='ays_result_element'>
                            <td>".esc_html__('Answer', "poll-maker")."</td>
                            <td><div class='apm-rating-res'>";
						$icons = array(
							'hand'  => array(
								"ays_poll_fa-thumbs-up",
								"ays_poll_fa-thumbs-down",
							),
							'emoji' => array(
								"ays_poll_fa-smile",
								"ays_poll_fa-frown",
							),
						);
						$view  = $poll['view_type'];
						$row   .= "<i class='" . (1 == $answer['answer'] ? "ays_poll_fas " : "ays_poll_far ") . $icons[$view][0] . "'></i>
                        <i class='" . (-1 == $answer['answer'] ? "ays_poll_fas " : "ays_poll_far ") . $icons[$view][1] . "'></i>";
						$row   .= "</div></td>
                            <td></td>
                            <td></td>
                        </tr>";
						break;
				}
				$row .= "<tr class='ays_result_element'>
                        <td>".esc_html__('Answer Datetime', "poll-maker")."</td>
                        <td>" . (date('H:i:s d.m.Y', strtotime($time))) . "</td>
                        <td></td>
                        <td></td>
                    </tr>";
				$row .= "<tr class='hr-line'><td colspan='4'><hr></td></tr>";
				$row .= '<tr><td colspan="4"><h1>' .esc_html__('User Information', "poll-maker") . "</h1></td></tr>";
					if ($json !== null) {
                    	$row .= "<tr class='ays_result_element'>
		                            <td>".esc_html__('User IP', "poll-maker")."</td>
		                            <td>$from</td>
		                            <td></td>
		                            <td></td>
		                        </tr>";
                    }
                if(!empty($user_email)){
                	$row .= "<tr class='ays_result_element'>
		                        <td>".esc_html__('User E-mail', "poll-maker")."</td>
		                        <td>$user_email</td>
		                        <td></td>
		                        <td></td>
		                	 </tr>";
            	}
				foreach ( $info as $key => $value ) {
					if ( ($key == 'not_show_user_id') || ($key == 'email' && !empty($user_email)) ) {
						continue;
					}

					$row .= "<tr class='ays_result_element'>
                            <td>". esc_html__( $key, "poll-maker") ."</td>
                            <td>". $value ."</td>
                            <td></td>
                            <td></td>
                        </tr>";
				}
			}
			ob_end_clean();
			$ob_get_clean = ob_get_clean();
			echo json_encode([
				"status" => true,
				"rows"   => $row,
			]);
			wp_die();
		}
	}

	public function get_poll_by_id( $id, $decode = true ) {
		global $wpdb;

		$sql  = "SELECT * FROM {$wpdb->prefix}ayspoll_polls WHERE id=" . absint(intval($id));
		$poll = $wpdb->get_row($sql, 'ARRAY_A');
		if (empty($poll)) {
			return array();
		}
		$sql             = "SELECT * FROM {$wpdb->prefix}ayspoll_answers WHERE poll_id=" . absint(intval($id)) . " ORDER BY id ASC";
		$poll['answers'] = $wpdb->get_results($sql, 'ARRAY_A');

		if ($decode) {
			$json               = $poll['styles'];
			$poll['styles']	    = !empty($poll['styles']) ? json_decode($poll['styles'], true) : array();
			$poll['categories'] = trim($poll['categories'], ',');
			$cats               = explode(',', $poll['categories']);
			$poll['categories'] = !empty($cats) ? $cats : [];
			$all_fields         = $this->get_all_formfields();
			if (isset($poll['styles']['fields'])) {
				$poll['fields'] = array();
				$fields         = explode(',', $poll['styles']['fields']);
				foreach ( $fields as $field ) {
					$index = array_search($field, array_column($all_fields, 'slug'));
					if ($index !== false) {
						$poll['fields'][] = $all_fields[$index];
					}
				}
			}
			if (isset($poll['styles']['required_fields'])) {
				$poll['required_fields'] = array();
				$fields                  = explode(',', $poll['styles']['required_fields']);
				foreach ( $fields as $field ) {
					$index = array_search($field, array_column($all_fields, 'slug'));
					if ($index !== false) {
						$poll['required_fields'][] = $all_fields[$index];
					}
				}
			}
		}

		return $poll;
	}

	public function get_all_formfields() {
		global $wpdb;
		$all = array(
			array(
				"id"        => 0,
				"name"      => "Name",
				"type"      => "text",
				"slug"      => "apm-name",
				"published" => 1,
			),
			array(
				"id"        => 0,
				"name"      => "E-mail",
				"type"      => "email",
				"slug"      => "apm-email",
				"published" => 1,
			),
			array(
				"id"        => 0,
				"name"      => "Phone",
				"type"      => "tel",
				"slug"      => "apm_phone",
				"published" => 1,
			),
		);

		return $all;
	}

    public function screen_option_settings() {
    	add_filter('screen_options_show_screen', array($this, 'poll_hide_screen_options'), 10, 2);
		$this->polls_obj = new Polls_List_Table($this->plugin_name);
        $this->settings_obj = new Poll_Maker_Settings_Actions($this->plugin_name);
    }

    public function display_plugin_settings_page() {
        include_once('partials/settings/poll-maker-settings.php');
    }

    public function ays_get_mailchimp_lists( $username, $api_key ) {
        if (!empty($api_key) && strpos($api_key, '-') !== false) {
            $api_postfix = explode("-", $api_key)[1];

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL             => "https://" . $api_postfix . ".api.mailchimp.com/3.0/lists",
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_ENCODING        => "",
                CURLOPT_MAXREDIRS       => 10,
                CURLOPT_TIMEOUT         => 30,
                CURLOPT_SSL_VERIFYPEER  => false,
                CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST   => "GET",
                CURLOPT_USERPWD         => "$username:$api_key",
                CURLOPT_HTTPAUTH        => CURLAUTH_BASIC,
	   //   CURLOPT_POSTFIELDS => "undefined=",
                CURLOPT_HTTPHEADER     => array(
                    "Content-Type: application/json",
                    "cache-control: no-cache"
                ),
            ));

            $response = curl_exec($curl);
            $err      = curl_error($curl);

            curl_close($curl);

            if ($err) {
                echo "cURL Error";
            } else {
                return json_decode($response, true);
            }
        }

        return array();
    }

	public function ays_poll_create_author() {

		// Check for permissions.
		if ( !Poll_Maker_Data::check_user_capability() ) {
            ob_end_clean();
            $ob_get_clean = ob_get_clean();
            echo json_encode(array(
                'results' => array()
            ));
            wp_die();
        }

		$search = isset($_REQUEST['search']) && $_REQUEST['search'] != '' ? sanitize_text_field($_REQUEST['search']) : null;
        $checked = isset($_REQUEST['val']) && $_REQUEST['val'] != '' ? sanitize_text_field($_REQUEST['val']) : null;
        $args = array(
            'fields' => array('ID', 'display_name', 'user_email', 'user_login', 'user_nicename')
        );

        if ($search !== null) {
            $args['search'] = '*' . esc_attr($search) . '*';
            $args['search_columns'] = array('ID', 'user_login', 'user_nicename', 'user_email', 'display_name');
        }
        
        $user_query = new WP_User_Query($args);
        $users = $user_query->get_results();
        $response = array(
            'results' => array()
        );

        if(empty($args)){
            $reports_users = '';
        }

        foreach ($users as $key => $user) {
            if ($checked !== null) {
                if ($user->ID == $checked) {
                    continue;
                }else{
                    $response['results'][] = array(
                        'id' => $user->ID,
                        'text' => $user->display_name
                    );
                }
            }else{
                $response['results'][] = array(
                    'id' => $user->ID,
                    'text' => $user->display_name,
                );
            }
        }     

        ob_end_clean();
        echo json_encode($response);
        wp_die();
    }

    public static function validateDate($date, $format = 'Y-m-d H:i:s'){
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) == $date;
    }

	public static function is_classic_editor_plugin_active() {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if ( is_plugin_active( 'classic-editor/classic-editor.php' ) ) {
            return true;
        }

        return false;
    }

	public static function is_active_gutenberg() {
        // Gutenberg plugin is installed and activated.
        $gutenberg = ! ( false === has_filter( 'replace_editor', 'gutenberg_init' ) );
        // Block editor since 5.0.
        $block_editor = version_compare( $GLOBALS['wp_version'], '5.0-beta', '>' );

        if ( ! $gutenberg && ! $block_editor ) {
            return false;
        }

        if ( self::is_classic_editor_plugin_active() ) {
            $editor_option       = get_option( 'classic-editor-replace' );
            $block_editor_active = array( 'no-replace', 'block' );

            return in_array( $editor_option, $block_editor_active, true );
        }

        return true;
    }

    public static function ays_restriction_string($type, $x, $length){
        $output = "";
        switch($type){
            case "char":                
                if(strlen($x)<=$length){
                    $output = $x;
                } else {
                    $output = substr($x,0,$length) . '...';
                }
                break;
            case "word":
                $res = explode(" ", $x);
                if(count($res)<=$length){
                    $output = implode(" ",$res);
                } else {
                    $res = array_slice($res,0,$length);
                    $output = implode(" ",$res) . '...';
                }
            break;
        }
        return $output;
    }

    public static function get_listtables_title_length( $listtable_name ) {
        global $wpdb;

        $settings_table = $wpdb->prefix . "ayspoll_settings";
        $sql = "SELECT meta_value FROM ".$settings_table." WHERE meta_key = 'options'";
        $result = $wpdb->get_var($sql);
        $options = ($result == "") ? array() : json_decode($result, true);

        $listtable_title_length = 5;
        if(! empty($options) ){
            switch ( $listtable_name ) {
                case 'polls':
                    $listtable_title_length = (isset($options['poll_title_length']) && intval($options['poll_title_length']) != 0) ? absint(intval($options['poll_title_length'])) : 5;
                    break;      
                case 'categories':
                    $listtable_title_length = (isset($options['poll_category_title_length']) && intval($options['poll_category_title_length']) != 0) ? absint(intval($options['poll_category_title_length'])) : 5;
                    break;      
                case 'results':
                    $listtable_title_length = (isset($options['poll_results_title_length']) && intval($options['poll_results_title_length']) != 0) ? absint(intval($options['poll_results_title_length'])) : 5;
                    break;      
                default:
                    $listtable_title_length = 5;
                    break;
            }
            return $listtable_title_length;
        }
        return $listtable_title_length;
    }

	public function poll_maker_select_submenu($file) {
        global $plugin_page;
        if ("poll-maker-ays-results-each" == $plugin_page) {
            $plugin_page = $this->plugin_name."-results";
        }

        return $file;
    }

    protected function poll_maker_capabilities(){
        global $wpdb;
		$sql    = "SELECT meta_value FROM {$wpdb->prefix}ayspoll_settings WHERE `meta_key` = 'user_roles'";
		$result = $wpdb->get_var($sql);
		
        $capability = 'manage_options';
        if($result !== null){
            $ays_user_roles = json_decode($result, true);
            if(is_user_logged_in()){
                $current_user = wp_get_current_user();
                $current_user_roles = $current_user->roles;
                $ishmar = 0;
                foreach($current_user_roles as $r){
                    if(in_array($r, $ays_user_roles)){
                        $ishmar++;
                    }
                }
                if($ishmar > 0){
                    $capability = "read";
                }
            }
        }
        return $capability;
	}

	public function ays_poll_exclude_custom_post_type_from_sitemap($exclude, $type){
        if($type == 'ays-poll-maker'){
            return $type;
        }
    }

    public function ays_poll_add_checklist_guide(){

        if(!empty($_REQUEST['page']) && sanitize_text_field( $_REQUEST['page'] ) != $this->plugin_name . "-getting-started"){
            if(false !== strpos( sanitize_text_field( $_REQUEST['page'] ), $this->plugin_name)){
                $current_user_id = get_current_user_id();
                $completed_steps = get_user_meta($current_user_id, 'ays_poll_checklist_completed_steps', true);

                // Make sure we always get an array
                if (!is_array($completed_steps)) {
                    $completed_steps = array();
                }

                $popup_closed = get_user_meta($current_user_id, 'ays_poll_checklist_popup_closed', true);

                $checklist_steps = array(
                    array(
                        'title' => esc_html__('Add a New Poll', 'poll-maker'),                        
                    ),
                    array(
                        'title' => esc_html__('Add Options', 'poll-maker'),                        
                    ),
                    array(
                        'title' => esc_html__('Save the Poll', 'poll-maker'),                        
                    ),
                    array(
                        'title' => esc_html__('Copy the Shortcode', 'poll-maker'),                        
                        'img_style' => 'object-fit:contain; height:auto;',
                    ),
                );
                ?>
                <div class="ays-poll-checklist-panel" id="ays-poll-checklist-container" style="<?php echo ($popup_closed) ? 'display: none;' : ''; ?>">
                    <header class="ays-poll-checklist-header">
                        <h3><?php echo esc_html__('Create a Poll with 4 Easy Steps', 'poll-maker'); ?></h3>

                        <!-- Minimize Button -->
                        <button class="ays-poll-checklist-minimize-btn" aria-label="Minimize">
                            <svg class="ays-poll-checklist-minimize-icon-expanded" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                <path d="M6 12h12" stroke="#000" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            <svg class="ays-poll-checklist-minimize-icon-collapsed" style="display:none;" width="24" height="24" viewBox="0 0 24 24">
                                <path fill-rule="evenodd" clip-rule="evenodd" d="M4 3.25H8C8.41421 3.25 8.75 3.58579 8.75 4C8.75 4.41421 8.41421 4.75 8 4.75H5.81066L10.5303 9.46967C10.8232 9.76256 10.8232 10.2374 10.5303 10.5303C10.2374 10.8232 9.76256 10.8232 9.46967 10.5303L4.75 5.81066V8C4.75 8.41421 4.41421 8.75 4 8.75C3.58579 8.75 3.25 8.41421 3.25 8V4C3.25 3.58579 3.58579 3.25 4 3.25ZM13.4697 13.4697C13.7626 13.1768 14.2374 13.1768 14.5303 13.4697L19.25 18.1893V16C19.25 15.5858 19.5858 15.25 20 15.25C20.4142 15.25 20.75 15.5858 20.75 16V20C20.75 20.4142 20.4142 20.75 20 20.75H16C15.5858 20.75 15.25 20.4142 15.25 20C15.25 19.5858 15.5858 19.25 16 19.25H18.1893L13.4697 14.5303C13.1768 14.2374 13.1768 13.7626 13.4697 13.4697Z"></path>
                            </svg>
                        </button>

                        <!-- Close Button -->
                        <button class="ays-poll-checklist-close-btn" aria-label="Close"><svg width="24" height="24" class="" focusable="false" aria-hidden="true" viewBox="0 0 24 24"><path fill-rule="evenodd" clip-rule="evenodd" d="M18.5303 5.46967C18.8232 5.76256 18.8232 6.23744 18.5303 6.53033L6.53033 18.5303C6.23744 18.8232 5.76256 18.8232 5.46967 18.5303C5.17678 18.2374 5.17678 17.7626 5.46967 17.4697L17.4697 5.46967C17.7626 5.17678 18.2374 5.17678 18.5303 5.46967Z"></path><path fill-rule="evenodd" clip-rule="evenodd" d="M5.46967 5.46967C5.76256 5.17678 6.23744 5.17678 6.53033 5.46967L18.5303 17.4697C18.8232 17.7626 18.8232 18.2374 18.5303 18.5303C18.2374 18.8232 17.7626 18.8232 17.4697 18.5303L5.46967 6.53033C5.17678 6.23744 5.17678 5.76256 5.46967 5.46967Z"></path></svg></button>
                    </header>

                    <ol class="ays-poll-checklist-items">
                    <?php foreach ($checklist_steps as $index => $step): 
                        $is_checked = in_array($index, $completed_steps);
                    ?>
                        <li class="ays-poll-checklist-step<?php echo $is_checked ? ' completed' : ''; ?>" data-step="<?php echo esc_attr($index); ?>">
                            <div class="ays-poll-checklist-header-row">
                                <span class="ays-poll-checklist-step-number"><?php echo esc_html($index + 1 . '.'); ?></span>
                                <span class="ays-poll-checklist-step-title"><?php echo esc_html($step['title']); ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    </ol>
                    <style>
                       #ays-poll-checklist-container.ays-poll-checklist-panel{max-width:100%;background:#fff;font-family:Arial,sans-serif;overflow:hidden;margin:0 auto;color:#0c0d0e;box-shadow:rgba(0,0,0,.2) 0 3px 5px -1px,rgba(0,0,0,.14) 0 5px 8px 0,rgba(0,0,0,.12) 0 1px 14px 0;position:fixed;width:300px;bottom:40px;inset-inline-end:40px;z-index:999;max-height:730px;overflow-y:auto;transition:box-shadow .3s cubic-bezier(.4, 0, .2, 1);border-radius:4px}#ays-poll-checklist-container .ays-poll-checklist-header{box-shadow:none;display:flex;flex-direction:row;align-items:center;width:100%;box-sizing:border-box;flex-shrink:0;position:sticky;z-index:1100;top:0;left:auto;right:0;color:rgba(0,0,0,.87);background-color:#fff;transition:box-shadow .3s cubic-bezier(.4, 0, .2, 1);padding:16px}#ays-poll-checklist-container .ays-poll-checklist-header-row{-webkit-tap-highlight-color:transparent;background-color:transparent;user-select:none;vertical-align:middle;appearance:none;display:flex;-webkit-box-flex:1;flex-grow:1;-webkit-box-pack:start;-webkit-box-align:center;align-items:center;position:relative;min-width:0;box-sizing:border-box;text-align:left;padding:0;color:#0c0d0e;outline:0;border:0;margin:0;border-radius:0;text-decoration:none;transition:background-color 150ms cubic-bezier(.4, 0, .2, 1)}#ays-poll-checklist-container .ays-poll-checklist-header h3{box-sizing:content-box;display:inline;height:auto;padding:0;width:auto;text-transform:none;margin:0;font-weight:600;line-height:1.3;font-family:Arial,sans-serif;font-size:1rem;letter-spacing:.00938em;-webkit-box-flex:1;flex-grow:1}#ays-poll-checklist-container .ays-poll-checklist-close-btn,#ays-poll-checklist-container .ays-poll-checklist-minimize-btn{border:none;background:0 0;font-size:20px;cursor:pointer;outline:unset;transition:background-color .3s ease-in-out;border-radius:4px}#ays-poll-checklist-container .ays-poll-checklist-close-btn:hover,#ays-poll-checklist-container .ays-poll-checklist-minimize-btn:hover{background-color:rgba(0,0,0,.04)}#ays-poll-checklist-container .ays-poll-checklist-items{list-style:none;padding:0;margin:0}#ays-poll-checklist-container .ays-poll-checklist-step{padding:12px 16px;border-bottom:1px solid #f0f0f0;transition:background .2s}#ays-poll-checklist-container .ays-poll-checklist-step:hover{background-color:#f9f9f9}#ays-poll-checklist-container .ays-poll-checklist-step label{display:flex;align-items:center;cursor:pointer}#ays-poll-checklist-container .ays-poll-checklist-step.completed label span{text-decoration:line-through;opacity:.6}#ays-poll-checklist-container .ays-poll-checklist-step-title{margin:0;margin-left:5px;font-family:Arial,sans-serif;font-weight:400;font-size:.875rem;letter-spacing:.01071em;display:block}#ays-poll-checklist-container .ays-poll-checklist-step-content{display:none;margin-top:12px}#ays-poll-checklist-container .ays-poll-checklist-step-content img{width:100%;max-width:100%;border-radius:4px;margin-bottom:0;height:180px;object-fit:contain}#wpwrap #wpfooter #ays-poll-checklist-container .ays-poll-checklist-step-content p{margin:0;font-size:13px;padding:16px 0}#ays-poll-checklist-container .ays-poll-checklist-step-content a:not(.ays-poll-checklist-primary-action){color:#0073aa;text-decoration:underline}#ays-poll-checklist-container .ays-poll-checklist-actions{display:flex;-webkit-box-align:center;align-items:center;-webkit-box-pack:end;justify-content:flex-end;padding-bottom:12px}#ays-poll-checklist-container .ays-poll-checklist-mark-done,#ays-poll-checklist-container .ays-poll-checklist-primary-action{display:inline-flex;position:relative;box-sizing:border-box;-webkit-tap-highlight-color:transparent;user-select:none;vertical-align:middle;appearance:none;text-transform:none;font-family:Arial,sans-serif;font-weight:500;font-size:.8125rem;line-height:1.75;letter-spacing:.02857em;min-width:64px;box-shadow:none;outline:0;text-decoration:none;padding:4px 10px;transition:background-color 250ms cubic-bezier(.4, 0, .2, 1),box-shadow 250ms cubic-bezier(.4, 0, .2, 1),border-color 250ms cubic-bezier(.4, 0, .2, 1),color 250ms cubic-bezier(.4, 0, .2, 1);white-space:nowrap;cursor:pointer}#ays-poll-checklist-container .ays-poll-checklist-mark-done{-webkit-box-align:center;align-items:center;-webkit-box-pack:center;justify-content:center;background-color:transparent;color:#515962;border:0;margin:0;border-radius:4px}#ays-poll-checklist-container .ays-poll-checklist-mark-done:hover{color:#515962;text-decoration:none;background-color:rgba(81,89,98,.04)}#ays-poll-checklist-container .ays-poll-checklist-primary-action{-webkit-box-align:center;align-items:center;-webkit-box-pack:center;justify-content:center;color:#fff;background-color:#00a0d2;border:0 initial initial;border-image:initial;margin:0 0 0 8px;border-radius:4px}#ays-poll-checklist-container .ays-poll-checklist-toggle-step{background:0 0;border:none;font-size:16px;cursor:pointer;transform:rotate(0);transition:transform .2s}#ays-poll-checklist-container .ays-poll-checklist-toggle-step.open{transform:rotate(180deg)}#ays-poll-checklist-container.ays-poll-checklist-panel.ays-poll-checklist-minimized .ays-poll-checklist-items{display:none}.ays-poll-launch-icon::after{content:"";width:8px;height:8px;background-color:#d179f2;border-radius:50%;position:absolute;top:0;right:0}.ays-poll-checklist-open-icon{position:absolute;bottom:36px;right:20px;cursor:pointer}@media (max-width:1280px){div#ays-poll-checklist-container.ays-poll-checklist-panel{max-height:580px;width:300px;bottom:20px;inset-inline-end:20px}div#ays-poll-checklist-container .ays-poll-checklist-step-content img{height:150px}}@media (max-width:782px){#ays-poll-checklist-container.ays-poll-checklist-panel,.ays-poll-checklist-open-icon{display:none}}@media (max-width:480px){#ays-poll-checklist-container.ays-poll-checklist-panel{border-radius:0;max-width:100%}#ays-poll-checklist-container .ays-poll-checklist-header h3{font-size:16px}#ays-poll-checklist-container .ays-poll-checklist-step-content p{font-size:13px}#ays-poll-checklist-container .ays-poll-checklist-actions{flex-direction:column}}
                    </style>
                </div>
                <?php
            }
        }

    }

    public function ays_poll_save_checklist_progress() {
        check_ajax_referer('ays_poll_nonce', 'nonce');

        $user_can_capability = self::poll_maker_capabilities();

        // Check for permissions.
        if ( ! current_user_can( $user_can_capability ) ) {
            wp_send_json_error( esc_html__( 'Something went wrong', 'poll-maker' ) );
        }

        if( !is_user_logged_in() ) {
            wp_send_json_error( esc_html__( 'Something went wrong', 'poll-maker' ) );
        }

        $user_id = get_current_user_id();
        $steps = isset($_POST['steps']) ? array_map('sanitize_text_field', $_POST['steps']) : array();

        update_user_meta($user_id, 'ays_poll_checklist_completed_steps', $steps);

        wp_send_json_success(array('saved' => true));
    }

    public function ays_poll_checklist_close_popup() {
        check_ajax_referer('ays_poll_nonce', 'nonce');

        $user_can_capability = self::poll_maker_capabilities();

        // Check for permissions.
        if ( ! current_user_can( $user_can_capability ) ) {
            wp_send_json_error( esc_html__( 'Something went wrong', 'poll-maker' ) );
        }

        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            update_user_meta($user_id, 'ays_poll_checklist_popup_closed', 1);
            wp_send_json_success( esc_html__( 'Popup marked as closed.', 'poll-maker' ) );
        }

        wp_send_json_error( esc_html__( 'User not logged in.', 'poll-maker' ) );
    }

    public function ays_poll_checklist_reopen_callback() {
        check_ajax_referer('ays_poll_nonce', 'nonce');

        $user_can_capability = self::poll_maker_capabilities();

        // Check for permissions.
        if ( ! current_user_can( $user_can_capability ) ) {
            wp_send_json_error( esc_html__( 'Something went wrong', 'poll-maker' ) );
        }

        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            delete_user_meta($user_id, 'ays_poll_checklist_popup_closed');
            wp_send_json_success('Popup reopened.');
        }

        wp_send_json_error( esc_html__( 'User not logged in.', 'poll-maker' ) );
    }

	public function get_next_or_prev_row_by_id( $id, $type = "next", $table = "ayspoll_polls" ) {
        global $wpdb;

        if ( is_null( $table ) || empty( $table ) ) {
            return null;
        }

        $ays_table = esc_sql( $wpdb->prefix . $table );

        $where = array();
        $where_condition = "";

        $id     = (isset( $id ) && $id != "" && absint($id) != 0) ? absint( sanitize_text_field( $id ) ) : null;
        $type   = (isset( $type ) && $type != "") ? sanitize_text_field( $type ) : "next";

        if ( is_null( $id ) || $id == 0 ) {
            return null;
        }

        switch ( $type ) {
			case 'prev':
                $where[] = ' `id` < ' . $id . ' ORDER BY `id` DESC ';
            break;
            case 'next':
            default:
                $where[] = ' `id` > ' . $id;
                break;
        }

        if( ! empty($where) ){
            $where_condition = " WHERE " . implode( " AND ", $where );
        }

        $sql = "SELECT `id` FROM {$ays_table} ". $where_condition ." LIMIT 1;";
        $results = $wpdb->get_row( $sql, 'ARRAY_A' );

        return $results;

    }
	
	public function poll_maker_admin_footer($a){
        if(isset($_REQUEST['page'])){
            if(false !== strpos($_REQUEST['page'], $this->plugin_name)){
                ?>
				<div class="ays-poll-footer-support-box">
                    <span class="ays-poll-footer-link-row"><a href="https://wordpress.org/support/plugin/poll-maker/" target="_blank"><?php echo esc_html__( "Support", "poll-maker"); ?></a></span>
                    <span class="ays-poll-footer-slash-row">/</span>
                    <span class="ays-poll-footer-link-row"><a href="https://ays-pro.com/wordpress-poll-maker-user-manual" target="_blank"><?php echo esc_html__( "Docs", "poll-maker"); ?></a></span>
                    <span class="ays-poll-footer-slash-row">/</span>
                    <span class="ays-poll-footer-link-row"><a href="https://ays-demo.com/poll-maker-plugin-survey/" target="_blank"><?php echo esc_html__( "Suggest a Feature", "poll-maker"); ?></a></span>
                </div>
                <p style="font-size:13px;text-align:center;font-style:italic;">
                    <span style="margin-left:0px;margin-right:10px;" class="ays_heart_beat"><i class="ays_fa ays_poll_fa_heart_o animated"></i></span>
                    <span><?php echo esc_html__( "If you love our plugin, please do big favor and rate us on WordPress.org", "poll-maker"); ?></span> 
                    <a target="_blank" class="ays-rated-link" href='http://bit.ly/3l5I2iG'>
                    	<span class="ays-dashicons ays-dashicons-star-empty"></span>
                    	<span class="ays-dashicons ays-dashicons-star-empty"></span>
                    	<span class="ays-dashicons ays-dashicons-star-empty"></span>
                    	<span class="ays-dashicons ays-dashicons-star-empty"></span>
                    	<span class="ays-dashicons ays-dashicons-star-empty"></span>
                    </a>
                    <span class="ays_heart_beat"><i class="ays_fa ays_poll_fa_heart_o animated"></i></span>
                </p>
            <?php
            }
        }
    }

	// Sales baner function 
    public function ays_poll_sale_baner(){
		if(isset($_POST['ays_poll_sale_btn_black_friday'])){
			$sale_date = sanitize_text_field($_POST['ays_poll_sale_btn_black_friday']);
			update_option('ays_poll_sale_notification_'.$sale_date, 1); 
			update_option('ays_poll_sale_date_'.$sale_date, current_time( 'mysql' ));
		}

		// if (isset($_POST['ays_poll_sale_btn']) && isset( $_POST[$this->plugin_name . '-sale-banner'] ) 
		//    && wp_verify_nonce( $_POST[$this->plugin_name . '-sale-banner'], $this->plugin_name . '-sale-banner' ) && current_user_can('manage_options')) {
		// 	$sale_date = 'plugin_sale';
        //     update_option('ays_poll_sale_btn_'.$sale_date, 1); 
        //     update_option('ays_poll_sale_date_'.$sale_date, current_time( 'mysql' ));
        // }

		if(isset($_POST['ays_poll_sale_btn_poll_countdown_for_two_months'])){	
			$sale_date = sanitize_text_field($_POST['ays_poll_sale_btn_poll_countdown_for_two_months']);		
			$dismiss_two_months = true;
			update_option('ays_poll_sale_notification_two_months_'.$sale_date, 1); 
			update_option('ays_poll_sale_date_'.$sale_date, current_time( 'mysql' ));
		}

		$one_day = 60*60*24; 
		$poll_sales = array(
			'plugin_sale'     => array(
									'status' => 'active',
									'time'   => ($one_day * 5),
								),
			'mega_bundle'     => array(
									'status' => 'inactive',
									'time'   => ($one_day * 5),
								),
			'new_mega_bundle' => array(
									'status' => 'inactive',
									'time'   => ($one_day * 7),
								),
			'small_spring' 	 => array(
									'status' => 'inactive',
									'time'   => ($one_day * 7),
								),
			'mega_bundle_new' => array(
									'status' => 'inactive',
									'time'   => ($one_day * 7),
								),
			'business_bundle' => array(
									'status' => 'inactive',
									'time'   => ($one_day * 7),
								),
			'black_friday' => array(
									'status' => 'inactive',
									'time'   => ($one_day * 5),
								),
			'winter_bundle' => array(
									'status' => 'inactive',
									'time'   => ($one_day * 5),
								),
			'poll_countdown' => array(
									'status' => 'inactive',
									'time'   => ($one_day * 5),
								),
			'halloween_bundle' => array(
									'status' => 'inactive',
									'time'   => ($one_day * 5),
								),
			'christmas_message' => array(
									'status' => 'inactive',
									'time'   => ($one_day * 7),
								),
		);

		if (isset($_GET['page']) && strpos($_GET['page'], POLL_MAKER_AYS_NAME) !== false) {
			foreach($poll_sales as $sale => $status){
				$ays_poll_sale_date = '';
				if(isset($status['status']) && $status['status'] == 'active'){
					$ays_poll_sale_date = get_option('ays_poll_sale_date_'.$sale);
					$ays_poll_two_months_flag = intval(get_option('ays_poll_sale_notification_two_months_'.$sale));
					$current_date = current_time( 'mysql' );
					$date_diff = strtotime($current_date) -  intval(strtotime($ays_poll_sale_date)) ;
					$val = isset($status['time']) ? $status['time'] : $one_day * 5;
					if($ays_poll_two_months_flag > 0){
						$val = $one_day * 60;
					}

					$days_diff = $date_diff / $val;
					if(intval($days_diff) > 0 ){
						update_option('ays_poll_sale_notification_'.$sale, 0); 
						update_option('ays_poll_sale_btn_'.$sale, 0); 
						update_option('ays_poll_sale_notification_two_months_'.$sale, 0); 
					}
					$ays_poll_flag = intval(get_option('ays_poll_sale_notification_'.$sale));
					$ays_poll_flag += intval(get_option('ays_poll_sale_btn_'.$sale));
					$ays_poll_flag += $ays_poll_two_months_flag;
					if($ays_poll_flag == 0){
						$ays_poll_sale_message = 'ays_poll_sale_message_'.$sale;
						if ( $this->get_max_id('polls') > 1 ){
							// $this->ays_poll_new_halloween_bundle_message_2025();
							// $this->ays_poll_black_friday_message();
							// $this->ays_poll_christmas_banner_message_2025();
							$this->ays_poll_footer_sale_banner_2026($ays_poll_flag);
						}
					}
				}
			}
		}
	}
// Poll Footer Blue Banner Sale 20%
    public function ays_poll_footer_sale_banner_2026($ishmar){
        if($ishmar == 0 ){
            $content = array();

            $poll_cta_button_link = esc_url('https://ays-pro.com/wordpress/poll-maker?utm_source=dashboard&utm_medium=poll-free&utm_campaign=private-offer-20-off-' . POLL_MAKER_AYS_VERSION);

            $content[] = '
                <div class="ays-poll-footer-banner-bottom-banner-wrapper" style="display: none;">
                    <div class="ays-poll-footer-banner-bottom-banner-container">
                        <div class="ays-poll-footer-banner-bottom-banner-content">
                            <div class="ays-poll-footer-banner-bottom-banner-inner">
                                <div class="ays-poll-footer-banner-bottom-banner-left">
                                    <div class="ays-poll-footer-banner-bottom-banner-icon">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M12 7v14"></path>
                                            <path d="M20 11v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-8"></path>
                                            <path d="M7.5 7a1 1 0 0 1 0-5A4.8 8 0 0 1 12 7a4.8 8 0 0 1 4.5-5 1 1 0 0 1 0 5"></path>
                                            <rect x="3" y="7" width="18" height="4" rx="1"></rect>
                                        </svg>
                                    </div>
                                    <span class="ays-poll-footer-banner-bottom-banner-title">'. esc_html__('Poll Maker by AYS', 'poll-maker') .'</span>
                                </div>
                                <span class="ays-poll-footer-banner-bottom-banner-separator"></span>
                                <span class="ays-poll-footer-banner-bottom-banner-discount">'. esc_html__('20% Off', 'poll-maker') .'</span>
                                <div class="ays-poll-footer-banner-bottom-banner-code" onclick="aysPollFooterBannerCopyToClipboard()" title="'. esc_html__('Copy Coupon Code', 'poll-maker') .'">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"></path>
                                        <path d="M13 5v2"></path>
                                        <path d="M13 17v2"></path>
                                        <path d="M13 11v2"></path>
                                    </svg>
                                    <span>POLLPRO20</span>
                                    <button type="button" class="ays-poll-footer-banner-bottom-banner-copy" aria-label="'. esc_html__('Copy Coupon Code', 'poll-maker') .'">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <rect width="14" height="14" x="8" y="8" rx="2" ry="2"></rect>
                                            <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"></path>
                                        </svg>
                                    </button>
                                </div>
                                <button type="button" class="ays-poll-footer-banner-bottom-banner-cta">
                                    '. esc_html__('Get Deal', 'poll-maker') .'
                                </button>
                                <button type="button" class="ays-poll-footer-banner-bottom-banner-close" aria-label="'. esc_html__('Close popup', 'poll-maker') .'">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M18 6 6 18"></path>
                                        <path d="m6 6 12 12"></path>
                                    </svg>
                                </button>
                                    </div>
                        </div>
                    </div>
                </div>';

            $content[] = '<style id="ays-poll-progress-banner-styles-inline-css">';

            $content[] = '@keyframes ays-pro-slide-up{0%{opacity:0;transform:translateY(24px)}100%{opacity:1;transform:translateY(0)}}@keyframes ays-pro-slide-down{0%{opacity:1;transform:translateY(0)}100%{opacity:0;transform:translateY(24px)}}.ays-poll-footer-banner-bottom-banner-wrapper{position:fixed;bottom:0;left:0;right:0;z-index:9999;padding:0 16px 28px;text-align:center;animation:.45s cubic-bezier(.16,1,.3,1) forwards ays-pro-slide-up}.ays-poll-footer-banner-bottom-banner-wrapper.ays-pro-banner-closing{animation:.32s cubic-bezier(.16,1,.3,1) forwards ays-pro-slide-down}.ays-poll-footer-banner-bottom-banner-container{display:inline-block;max-width:100%;border-radius:16px;background:linear-gradient(90deg,#2f6bff 0,#3e7bff 100%);box-shadow:0 20px 40px -15px rgba(47,107,255,.55),0 8px 20px -10px rgba(47,107,255,.35);overflow:hidden}.ays-poll-footer-banner-bottom-banner-code,.ays-poll-footer-banner-bottom-banner-content,.ays-poll-footer-banner-bottom-banner-inner,.ays-poll-footer-banner-bottom-banner-left{display:flex;align-items:center}.ays-poll-footer-banner-bottom-banner-inner{gap:16px;padding:8px 14px 8px 10px;color:#fff;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}.ays-poll-footer-banner-bottom-banner-left{gap:14px}.ays-poll-footer-banner-bottom-banner-icon{display:flex;align-items:center;justify-content:center;flex:0 0 auto;width:48px;height:48px;border-radius:50%;background:rgba(255,255,255,.15);color:#fff}.ays-poll-footer-banner-bottom-banner-icon svg{width:24px;height:24px}.ays-poll-footer-banner-bottom-banner-discount,.ays-poll-footer-banner-bottom-banner-title{font-size:17px;font-weight:600;line-height:1.25;letter-spacing:0;white-space:nowrap;color:#fff}.ays-poll-footer-banner-bottom-banner-separator{display:block;width:1px;height:24px;background:rgba(255,255,255,.4)}.ays-poll-footer-banner-bottom-banner-code{gap:6px;border:1px dashed rgba(255,255,255,.7);border-radius:999px;padding:6px 9px;color:#fff;cursor:pointer;transition:background-color .15s,border-color .15s}.ays-poll-footer-banner-bottom-banner-code:hover{background:rgba(255,255,255,.1);border-color:#fff}.ays-poll-footer-banner-bottom-banner-code button svg,.ays-poll-footer-banner-bottom-banner-code>svg{width:16px;height:16px}.ays-poll-footer-banner-bottom-banner-code span{font-size:14px;font-weight:600;line-height:1;letter-spacing:.05em;color:#fff}.ays-poll-footer-banner-bottom-banner-close,.ays-poll-footer-banner-bottom-banner-copy{display:flex;align-items:center;justify-content:center;margin:0;padding:0;background:0 0;border:0;color:rgba(255,255,255,.9);cursor:pointer;box-shadow:none;transition:color .15s,background-color .15s}.ays-poll-footer-banner-bottom-banner-close:hover,.ays-poll-footer-banner-bottom-banner-copy:hover{color:#fff;background:0 0}.ays-poll-footer-banner-bottom-banner-wrapper .ays-poll-footer-banner-bottom-banner-cta{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:8px 20px;border:0;border-radius:999px;background:#fff;color:#2f6bff;font-size:15px;font-weight:600;line-height:1;white-space:nowrap;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.08);transition:background-color .15s,transform .15s}.ays-poll-footer-banner-bottom-banner-wrapper .ays-poll-footer-banner-bottom-banner-cta:hover{background:rgba(255,255,255,.95);color:#2f6bff;transform:translateY(-1px)}.ays-poll-footer-banner-bottom-banner-close{width:22px;height:22px;margin-left:2px}.ays-poll-footer-banner-bottom-banner-close svg{width:20px;height:20px}.ays-poll-footer-banner-copy-notification{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:rgba(0,0,0,.8);color:#fff;padding:12px 24px;border-radius:8px;font-size:14px;z-index:10000;opacity:0;transition:opacity .3s}.ays-poll-footer-banner-copy-notification.show{opacity:1}@media (max-width:768px){.ays-poll-footer-banner-bottom-banner-wrapper{display:none}}';
            $content[] = '</style>';

            $content[] = '<script>';

            $content[] = "
            /**
             * Footer Banner JavaScript - ES5 Compatible
             * With delayed popup and visit tracking
             */
            
            (function() {
              'use strict';
              
              // Configuration
              var STORAGE_KEY = 'ays_poll_footer_banner_data';
              var POPUP_RULES = [
                { visit: 1, delay: 10000 },   // 1st visit: 10 seconds
                { visit: 2, delay: 10000 },   // 2nd visit: 10 seconds
                { visit: 3, delay: 15000 },   // 3th visit: 15 seconds
                { visit: 4, delay: 15000 },   // 4th visit: 15 seconds
                { visit: 5, delay: 20000 },   // 5th visit: 20 seconds
                { visit: 7, delay: 20000 },   // 7th visit: 20 seconds
                { visit: 10, delay: 30000 }   // 10th visit: 30 seconds
              ];
              var MAX_SHOWS_PER_DAY = 7;
              
              // Wait for DOM to be ready
              if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initBanner);
              } else {
                initBanner();
              }
              
              function initBanner() {
                var banner = document.querySelector('.ays-poll-footer-banner-bottom-banner-wrapper');
                var closeButton = document.querySelector('.ays-poll-footer-banner-bottom-banner-close');
                var ctaButton = document.querySelector('.ays-poll-footer-banner-bottom-banner-cta');
                
                if (!banner) {
                  return;
                }
                
                // Hide banner initially
                banner.style.display = 'none';
                
                // Get or initialize banner data
                var bannerData = getBannerData();
                
                // Check if we need to reset daily data
                if (shouldResetData(bannerData.lastResetDate)) {
                  bannerData = resetDailyData();
                }
                
                // Increment visit count
                bannerData.visitCount++;
                saveBannerData(bannerData);
                
                // Check if banner should be shown
                var shouldShow = shouldShowBanner(bannerData);
                
                if (shouldShow.show) {
                  // Show banner after delay
                  setTimeout(function() {
                    banner.style.display = 'block';
                    
                    // Increment show count
                    bannerData.showCount++;
                    saveBannerData(bannerData);
                  }, shouldShow.delay);
                }
                
                // Close button handler
                if (closeButton) {
                  closeButton.addEventListener('click', function() {
                    closeBanner(banner);
                  });
                }
                
                // CTA button handler
                if (ctaButton) {
                  ctaButton.addEventListener('click', function() {
                    window.open('https://ays-pro.com/wordpress/poll-maker?utm_source=dashboard&utm_medium=poll-free&utm_campaign=private-offer-20-off-". POLL_MAKER_AYS_VERSION ."', '_blank');
                    // Optionally close banner after click
                    closeBanner(banner);
                  });
                }
              }
              
              /**
               * Get banner data from localStorage
               */
              function getBannerData() {
                try {
                  var data = localStorage.getItem(STORAGE_KEY);
                  if (data) {
                    return JSON.parse(data);
                  }
                } catch (e) {
                  // localStorage not available or parsing error
                }
                
                // Return default data
                return {
                  visitCount: 0,
                  showCount: 0,
                  lastResetDate: getTodayDate()
                };
              }
              
              /**
               * Save banner data to localStorage
               */
              function saveBannerData(data) {
                try {
                  localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
                } catch (e) {
                  // localStorage not available
                }
              }
              
              /**
               * Check if data should be reset (new day)
               */
              function shouldResetData(lastResetDate) {
                var today = getTodayDate();
                return lastResetDate !== today;
              }
              
              /**
               * Reset daily data
               */
              function resetDailyData() {
                var newData = {
                  visitCount: 0,
                  showCount: 0,
                  lastResetDate: getTodayDate()
                };
                saveBannerData(newData);
                return newData;
              }
              
              /**
               * Get today's date as string (YYYY-MM-DD)
               */
              function getTodayDate() {
                var date = new Date();
                var year = date.getFullYear();
                var month = String(date.getMonth() + 1).padStart(2, '0');
                var day = String(date.getDate()).padStart(2, '0');
                return year + '-' + month + '-' + day;
              }
              
              /**
               * Check if banner should be shown based on rules
               */
              function shouldShowBanner(bannerData) {
                // Check if already shown max times today
                if (bannerData.showCount >= MAX_SHOWS_PER_DAY) {
                  return { show: false, delay: 0 };
                }
                
                // Check visit count against rules
                for (var i = 0; i < POPUP_RULES.length; i++) {
                  var rule = POPUP_RULES[i];
                  if (bannerData.visitCount === rule.visit) {
                    return { show: true, delay: rule.delay };
                  }
                }
                
                return { show: false, delay: 0 };
              }
              
              /**
               * Close banner with animation
               */
              function closeBanner(banner) {
                if (!banner) {
                  return;
                }
                
                // Add closing animation class
                banner.classList.add('ays-pro-banner-closing');
                
                // Wait for animation to complete
                setTimeout(function() {
                  banner.style.display = 'none';
                }, 400);
              }
              
              // Polyfill for String.padStart (for older browsers)
              if (!String.prototype.padStart) {
                String.prototype.padStart = function(targetLength, padString) {
                  targetLength = targetLength >> 0;
                  padString = String(typeof padString !== 'undefined' ? padString : ' ');
                  if (this.length >= targetLength) {
                    return String(this);
                  } else {
                    targetLength = targetLength - this.length;
                    if (targetLength > padString.length) {
                      padString += padString.repeat(targetLength / padString.length);
                    }
                    return padString.slice(0, targetLength) + String(this);
                  }
                };
              }
            })();
            
            function aysPollFooterBannerCopyToClipboard() {
                var textarea = document.createElement('textarea');
                textarea.value = 'POLLPRO20';
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                
                textarea.select();
                textarea.setSelectionRange(0, 99999);
                
                try {
                    document.execCommand('copy');
                    aysPollFooterBannerShowCopyNotification('". esc_html__('Coupon code copied', 'poll-maker') ."');
                } catch (err) {
                    console.error('Failed to copy text: ', err);
                }
                
                document.body.removeChild(textarea);
            }

            function aysPollFooterBannerShowCopyNotification(message) {
                var existingNotification = document.querySelector('.ays-poll-footer-banner-copy-notification');
                if (existingNotification) {
                    document.body.removeChild(existingNotification);
                }
                
                var notification = document.createElement('div');
                notification.className = 'ays-poll-footer-banner-copy-notification';
                notification.textContent = message;
                document.body.appendChild(notification);
                
                setTimeout(function() {
                    notification.classList.add('show');
                }, 10);
                
                setTimeout(function() {
                    notification.classList.remove('show');
                    setTimeout(function() {
                        if (notification.parentNode) {
                            document.body.removeChild(notification);
                        }
                    }, 300);
                }, 2000);
            }";

            $content[] = '</script>';

            $content = implode( '', $content );
            echo ($content);
        }
    }


	public function ays_poll_dismiss_button(){

        $data = array(
            'status' => false,
        );

        if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'ays_poll_dismiss_button') { 
            if( (isset( $_REQUEST['_ajax_nonce'] ) && wp_verify_nonce( $_REQUEST['_ajax_nonce'], POLL_MAKER_AYS_NAME . '-sale-banner' )) && current_user_can( 'manage_options' )){
				$sale_date = 'plugin_sale';
                update_option('ays_poll_sale_btn_'.$sale_date, 1);
                update_option('ays_poll_sale_date_'.$sale_date, current_time( 'mysql' ));
                $data['status'] = true;
            }
        }

        ob_end_clean();
        $ob_get_clean = ob_get_clean();
        echo json_encode($data);
        wp_die();

    }	

	// Christmas Banner 2025
    public function ays_poll_christmas_banner_message_2025(){
        $content = array();

       $svg_icon = '<svg width="21" height="22" viewBox="0 0 21 22" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path fill-rule="evenodd" clip-rule="evenodd" d="M10.0445 0C10.4587 0 10.7945 0.33579 10.7945 0.75V2.93934L12.5141 1.21967C12.807 0.92678 13.2819 0.92678 13.5748 1.21967C13.8677 1.51256 13.8677 1.98744 13.5748 2.28033L10.7945 5.06066V9.451L14.5965 7.25588L15.6142 3.45788C15.7214 3.05778 16.1326 2.82034 16.5327 2.92755C16.9328 3.03475 17.1703 3.44601 17.0631 3.84611L16.4336 6.19522L18.3296 5.10055C18.6884 4.89344 19.147 5.01635 19.3542 5.37507C19.5613 5.73379 19.4384 6.19248 19.0796 6.39959L17.1836 7.49426L19.5327 8.1237C19.9328 8.23091 20.1703 8.64216 20.0631 9.0423C19.9558 9.4424 19.5446 9.6798 19.1445 9.5726L15.3465 8.55492L11.5445 10.75L15.3467 12.9452L19.1447 11.9275C19.5448 11.8203 19.956 12.0578 20.0633 12.4579C20.1705 12.858 19.933 13.2692 19.5329 13.3764L17.1838 14.0059L19.0798 15.1005C19.4386 15.3077 19.5615 15.7663 19.3544 16.1251C19.1472 16.4838 18.6886 16.6067 18.3298 16.3996L16.4338 15.3049L17.0633 17.654C17.1705 18.0541 16.933 18.4654 16.5329 18.5726C16.1328 18.6798 15.7216 18.4424 15.6144 18.0423L14.5967 14.2443L10.7945 12.049V16.4393L13.5748 19.2197C13.8677 19.5126 13.8677 19.9874 13.5748 20.2803C13.2819 20.5732 12.807 20.5732 12.5141 20.2803L10.7945 18.5607V20.75C10.7945 21.1642 10.4587 21.5 10.0445 21.5C9.63033 21.5 9.29453 21.1642 9.29453 20.75V18.5607L7.57484 20.2803C7.28195 20.5732 6.80707 20.5732 6.51418 20.2803C6.22129 19.9874 6.22129 19.5126 6.51418 19.2197L9.29453 16.4393V12.049L5.4923 14.2443L4.47463 18.0423C4.36742 18.4424 3.95617 18.6798 3.55607 18.5726C3.15597 18.4654 2.91853 18.0541 3.02574 17.654L3.65518 15.3049L1.75916 16.3996C1.40044 16.6067 0.941743 16.4838 0.734643 16.1251C0.527533 15.7663 0.650443 15.3077 1.00916 15.1005L2.90518 14.0059L0.556073 13.3764C0.155973 13.2692 -0.081467 12.858 0.0257431 12.4579C0.132943 12.0578 0.544203 11.8203 0.944303 11.9275L4.7423 12.9452L8.54453 10.75L4.74249 8.55492L0.944493 9.5726C0.544393 9.6798 0.133143 9.4424 0.0259331 9.0423C-0.0812669 8.64216 0.156163 8.23091 0.556263 8.1237L2.90538 7.49426L1.00935 6.39959C0.650633 6.19248 0.527733 5.73379 0.734833 5.37507C0.941943 5.01635 1.40063 4.89344 1.75935 5.10055L3.65538 6.19522L3.02593 3.84611C2.91873 3.44601 3.15616 3.03475 3.55626 2.92755C3.95636 2.82034 4.36762 3.05778 4.47482 3.45788L5.49249 7.25588L9.29453 9.451V5.06066L6.51418 2.28033C6.22129 1.98744 6.22129 1.51256 6.51418 1.21967C6.80707 0.92678 7.28195 0.92678 7.57484 1.21967L9.29453 2.93934V0.75C9.29453 0.33579 9.63033 0 10.0445 0Z" fill="white" fill-opacity="0.2"/>
        </svg>
        ';

        $ays_poll_cta_button_link = esc_url('https://ays-pro.com/wordpress/poll-maker?utm_source=dashboard&utm_medium=poll-free&utm_campaign=christmas-sale-banner-' . POLL_MAKER_AYS_VERSION);

        $content[] = '<div id="ays-poll-christmas-banner-main" class="notice notice-success is-dismissible ays-poll-christmas-banner-info ays_poll_dicount_info">';
            $content[] = '<div id="ays-poll-christmas-banner-month" class="ays-poll-christmas-banner-month">';
                
                // Background effects
                $content[] = '<div class="ays-poll-christmas-banner-bg-effects">';
                    $content[] = '<div class="ays-poll-christmas-banner-bg-gradient-1"></div>';
                    $content[] = '<div class="ays-poll-christmas-banner-bg-gradient-2"></div>';
                    
                    // Snowflakes
                    $content[] = '<div class="ays-poll-christmas-banner-snowflake" style="left: 5%; animation-delay: 0s; animation-duration: 8s;">'. $svg_icon .'</div>';
                    $content[] = '<div class="ays-poll-christmas-banner-snowflake" style="left: 15%; animation-delay: 2s; animation-duration: 10s;">'. $svg_icon .'</div>';
                    $content[] = '<div class="ays-poll-christmas-banner-snowflake" style="left: 25%; animation-delay: 4s; animation-duration: 9s;">'. $svg_icon .'</div>';
                    $content[] = '<div class="ays-poll-christmas-banner-snowflake" style="left: 75%; animation-delay: 1s; animation-duration: 11s;">'. $svg_icon .'</div>';
                    $content[] = '<div class="ays-poll-christmas-banner-snowflake" style="left: 85%; animation-delay: 3s; animation-duration: 8s;">'. $svg_icon .'</div>';
                    $content[] = '<div class="ays-poll-christmas-banner-snowflake" style="left: 92%; animation-delay: 5s; animation-duration: 10s;">'. $svg_icon .'</div>';
                    
                    // Sparkles
                    $content[] = '<svg class="ays-poll-christmas-banner-sparkle" style="top: 20%; left: 8%; animation-delay: 0s; width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';
                        $content[] = '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>';
                    $content[] = '</svg>';
                    $content[] = '<svg class="ays-poll-christmas-banner-sparkle" style="top: 60%; left: 3%; animation-delay: 0.5s; width: 10px; height: 10px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';
                        $content[] = '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>';
                    $content[] = '</svg>';
                    $content[] = '<svg class="ays-poll-christmas-banner-sparkle" style="top: 30%; right: 12%; animation-delay: 1s; width: 12px; height: 12px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';
                        $content[] = '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>';
                    $content[] = '</svg>';
                $content[] = '</div>';

                // Main content
                $content[] = '<div class="ays-poll-christmas-banner-content">';
                    $content[] = '<div class="ays-poll-christmas-banner-left">';
                        // Gift icon with hat
                        $content[] = '<div class="ays-poll-christmas-banner-gift-wrapper">';
                            $content[] = '<svg class="ays-poll-christmas-banner-gift-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">';
                                $content[] = '<rect x="3" y="8" width="18" height="4" rx="1"></rect>';
                                $content[] = '<path d="M12 8v13"></path>';
                                $content[] = '<path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"></path>';
                                $content[] = '<path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"></path>';
                            $content[] = '</svg>';
                            $content[] = '<div class="ays-poll-christmas-banner-hat">';
                                $content[] = '<svg viewBox="0 0 24 24" fill="none" class="ays-poll-christmas-banner-hat-svg">';
                                    $content[] = '<path d="M12 2L4 14h16L12 2z" fill="hsl(0 80% 45%)"></path>';
                                    $content[] = '<path d="M4 14c0 2 3.5 3 8 3s8-1 8-3" fill="hsl(0 0% 100%)"></path>';
                                    $content[] = '<circle cx="12" cy="3" r="2" fill="hsl(0 0% 100%)"></circle>';
                                $content[] = '</svg>';
                            $content[] = '</div>';
                        $content[] = '</div>';

                        $content[] = '<div class="ays-poll-christmas-banner-special-label">';
                            $content[] = '<div class="ays-poll-christmas-banner-special-label-name">';
                                $content[] = '<a href="'. $ays_poll_cta_button_link .'" class="ays-poll-christmas-banner-special-label-name-link" target="_blank">';
                                    $content[] = __( 'Poll Maker', "poll-maker" );
                                $content[] = '</a>';
                            $content[] = '</div>';

                            $content[] = '<div>✦ ' . __( 'CHRISTMAS SPECIAL', "poll-maker" ) . ' ✦</div>';
                        $content[] = '</div>';
                    $content[] = '</div>';

                    $content[] = '<div class="ays-poll-christmas-banner-center">';
                        $content[] = '<div class="ays-poll-christmas-banner-discount-text">25% OFF</div>';
                        $content[] = '<div class="ays-poll-christmas-banner-limited-offer">' . __( 'Limited time offer', "poll-maker" ) . '</div>';
                    $content[] = '</div>';

                    $content[] = '<div class="ays-poll-christmas-banner-right">';
                        $content[] = '<div class="ays-poll-christmas-banner-coupon-box" onclick="aysPollChristmasCopyToClipboard(\'XMAS25\')" title="' . __( 'Click to copy', "poll-maker" ) . '">';
                            $content[] = '<span class="ays-poll-christmas-banner-coupon-text">XMAS25</span>';
                            $content[] = '<svg class="ays-poll-christmas-banner-copy-icon" width="16" height="16" viewBox="0 0 16 16" fill="none">';
                                $content[] = '<path d="M13.5 2.5H6.5C5.67 2.5 5 3.17 5 4V10C5 10.83 5.67 11.5 6.5 11.5H13.5C14.33 11.5 15 10.83 15 10V4C15 3.17 14.33 2.5 13.5 2.5ZM13.5 10H6.5V4H13.5V10ZM2.5 6.5V12.5C2.5 13.33 3.17 14 4 14H10V12.5H4V6.5H2.5Z" fill="white"/>';
                            $content[] = '</svg>';
                        $content[] = '</div>';

                        $content[] = '<a href="'. $ays_poll_cta_button_link .'" class="ays-poll-christmas-banner-buy-now-btn" target="_blank">';
                            $content[] = __( 'Buy Now', "poll-maker" );
                        $content[] = '</a>';
                    $content[] = '</div>';
                $content[] = '</div>';

            $content[] = '</div>';

            if( current_user_can( 'manage_options' ) ){
            $content[] = '<div id="ays-poll-dismiss-buttons-content">';
                $content[] = '<form action="" method="POST" style="position: absolute; bottom: 0; right: 0; color: #fff;">';
                        $content[] = '<button class="btn btn-link ays-button" name="ays_poll_sale_btn" style="color: darkgrey; font-size: 11px; padding: 0 .75rem;">'. __( "Dismiss ad", 'poll-maker' ) .'</button>';
                        $content[] = wp_nonce_field( POLL_MAKER_AYS_NAME . '-sale-banner' ,  POLL_MAKER_AYS_NAME . '-sale-banner' );
                $content[] = '</form>';
            $content[] = '</div>';
            }

        $content[] = '</div>';

        $content[] = '<script>';
        $content[] = "
            function aysPollChristmasCopyToClipboard(text) {
                var textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                
                textarea.select();
                textarea.setSelectionRange(0, 99999);
                
                try {
                    document.execCommand('copy');
                    aysPollChristmasShowCopyNotification('" . __( 'Coupon code copied!', "poll-maker" ) . "');
                } catch (err) {
                    console.error('Failed to copy text: ', err);
                }
                
                document.body.removeChild(textarea);
            }

            function aysPollChristmasShowCopyNotification(message) {
                var existingNotification = document.querySelector('.ays-poll-christmas-banner-copy-notification');
                if (existingNotification) {
                    document.body.removeChild(existingNotification);
                }
                
                var notification = document.createElement('div');
                notification.className = 'ays-poll-christmas-banner-copy-notification';
                notification.textContent = message;
                document.body.appendChild(notification);
                
                setTimeout(function() {
                    notification.classList.add('show');
                }, 10);
                
                setTimeout(function() {
                    notification.classList.remove('show');
                    setTimeout(function() {
                        if (notification.parentNode) {
                            document.body.removeChild(notification);
                        }
                    }, 300);
                }, 2000);
            }";
        $content[] = '</script>';                

        $content[] = '<style>';
        $content[] = '
            /* Christmas banner start */

            div#ays-poll-christmas-banner-main .btn-link {
                background-color: transparent;
                display: inline-block;
                font-weight: 400;
                text-align: center;
                white-space: nowrap;
                vertical-align: middle;
                -webkit-user-select: none;
                -moz-user-select: none;
                -ms-user-select: none;
                user-select: none;
                border: 1px solid transparent;
                padding: .375rem .75rem;
                font-size: 12px;
                line-height: 1.5;
                border-radius: .25rem;
                color: rgba(255, 255, 255, .6);
            }
            
            div#ays-poll-christmas-banner-main.ays-poll-christmas-banner-info {
                background: linear-gradient(to right, hsl(0, 70%, 28%), hsl(0, 65%, 38%), hsl(0, 70%, 28%));
                padding: unset;
                border-left: 0;
                position: relative;
            }
            
            #ays-poll-christmas-banner-main .ays-poll-christmas-banner-month {
                position: relative;
                padding: 15px 40px;
                overflow: hidden;
            }
            
            /* Background effects */
            .ays-poll-christmas-banner-bg-effects {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                pointer-events: none;
                z-index: 1;
            }
            
            .ays-poll-christmas-banner-bg-gradient-1 {
                position: absolute;
                top: 0;
                left: 30%;
                width: 40%;
                height: 100%;
                background: radial-gradient(circle, rgba(202, 43, 43, 0.5) 0%, transparent 60%);
                opacity: 0.4;
            }
            
            .ays-poll-christmas-banner-bg-gradient-2 {
                position: absolute;
                top: 0;
                right: 15%;
                width: 35%;
                height: 100%;
                background: radial-gradient(circle, rgba(246, 201, 85, 0.15) 0%, transparent 50%);
                opacity: 0.3;
            }
            
            .ays-poll-christmas-banner-snowflake {
                position: absolute;
                color: rgba(255, 255, 255, 0.2);
                font-size: 20px;
                animation: ays-poll-christmas-snowfall linear infinite;
                top: -10px;
            }
            
            @keyframes ays-poll-christmas-snowfall {
                0% {
                    transform: translateY(-10px) rotate(0deg);
                    opacity: 0;
                }
                10% {
                    opacity: 0.8;
                }
                90% {
                    opacity: 0.8;
                }
                100% {
                    transform: translateY(100%) rotate(360deg);
                    opacity: 0;
                }
            }
            
            .ays-poll-christmas-banner-sparkle {
                position: absolute;
                color: hsl(43, 90%, 65%);
                animation: ays-poll-christmas-twinkle 2s ease-in-out infinite;
            }
            
            @keyframes ays-poll-christmas-twinkle {
                0%, 100% {
                    opacity: 0.3;
                    transform: scale(0.8);
                }
                50% {
                    opacity: 1;
                    transform: scale(1.2);
                }
            }
            
            /* Main content */
            .ays-poll-christmas-banner-content {
                position: relative;
                display: flex;
                align-items: center;
                justify-content: space-between;
                z-index: 2;
            }
            
            /* Left section */
            .ays-poll-christmas-banner-left {
                display: flex;
                align-items: center;
                gap: 50px;
            }
            
            .ays-poll-christmas-banner-gift-wrapper {
                position: relative;
                animation: ays-poll-christmas-float 3s ease-in-out infinite;
            }
            
            .ays-poll-christmas-banner-gift-icon {
                width: 48px;
                height: 48px;
                color: rgba(255, 247, 237, 0.9);
            }
            
            @keyframes ays-poll-christmas-float {
                0%, 100% {
                    transform: translateY(0);
                }
                50% {
                    transform: translateY(-5px);
                }
            }
            
            .ays-poll-christmas-banner-hat {
                position: absolute;
                top: -12px;
                right: -4px;
                width: 24px;
                height: 24px;
            }
            
            .ays-poll-christmas-banner-hat-svg {
                width: 100%;
                height: 100%;
            }
            
            .ays-poll-christmas-banner-special-label {
                color: hsl(43, 90%, 65%);
                font-size: 14px;
                font-weight: 500;
                text-transform: uppercase;
                letter-spacing: 1px;
                /* font-family: "Outfit", sans-serif; */
            }

            .ays-poll-christmas-banner-special-label-name {
                color: #fffaf0;
                text-align: center;
            }

            div#ays-poll-christmas-banner-main .ays-poll-christmas-banner-special-label-name-link {
                color: #fffaf0;
                box-shadow: unset;
            }
            
            /* Center section */
            .ays-poll-christmas-banner-center {
                display: flex;
                flex-direction: row;
                text-align: center;
                justify-content: center;
                align-items: center;
                gap: 30px;
            }
            
            .ays-poll-christmas-banner-discount-text {
                font-family: "Outfit", sans-serif;
                font-weight: 800;
                font-size: 30px;
                color: hsl(40, 100%, 97%);
                letter-spacing: -1px;
                line-height: 1;
            }
            
            .ays-poll-christmas-banner-limited-offer {
                color: rgba(255, 247, 237, 0.7);
                font-size: 13px;
                font-weight: 500;
            }
            
            /* Right section */
            .ays-poll-christmas-banner-right {
                display: flex;
                align-items: center;
                gap: 20px;
            }
            
            .ays-poll-christmas-banner-coupon-box {
                border: 2px dashed rgba(255, 255, 255, 0.4);
                padding: 8px 16px;
                border-radius: 6px;
                background: rgba(255, 255, 255, 0.1);
                cursor: pointer;
                transition: all 0.3s;
                display: flex;
                align-items: center;
                gap: 8px;
                backdrop-filter: blur(10px);
            }
            
            .ays-poll-christmas-banner-coupon-box:hover {
                background: rgba(255, 255, 255, 0.2);
                border-color: rgba(255, 255, 255, 0.6);
                transform: translateY(-1px);
            }
            
            .ays-poll-christmas-banner-coupon-text {
                font-size: 16px;
                font-weight: 700;
                letter-spacing: 1px;
                color: #fff;
                font-family: monospace;
            }
            
            .ays-poll-christmas-banner-copy-icon {
                opacity: 0.8;
                transition: opacity 0.3s;
            }
            
            .ays-poll-christmas-banner-coupon-box:hover .ays-poll-christmas-banner-copy-icon {
                opacity: 1;
            }
            
            #ays-poll-christmas-banner-main .ays-poll-christmas-banner-buy-now-btn {
                background-color: #fbe19f;
                color: hsl(0, 72%, 35%);
                padding: 10px 30px;
                border-radius: 9999px;
                font-size: 16px;
                font-weight: 600;
                font-family: "Outfit", sans-serif;
                border: none;
                cursor: pointer;
                transition: all 0.3s;
                text-decoration: none;
                display: flex;
                align-items: center;
                gap: 6px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15), 0 0 30px rgba(246, 201, 85, 0.2);
            }
            
            #ays-poll-christmas-banner-main .ays-poll-christmas-banner-buy-now-btn:hover {
                background-color: #f2d58c;
                transform: scale(1.05);
                box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2), 0 0 40px rgba(246, 201, 85, 0.3);
            }
            
            .ays-poll-christmas-banner-btn-arrow {
                display: inline-block;
                transition: transform 0.3s;
            }
            
            .ays-poll-christmas-banner-buy-now-btn:hover .ays-poll-christmas-banner-btn-arrow {
                transform: translateX(4px);
            }
            
            /* Notification */
            .ays-poll-christmas-banner-copy-notification {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: rgba(0, 0, 0, 0.8);
                color: #fff;
                padding: 12px 24px;
                border-radius: 8px;
                font-size: 14px;
                z-index: 10000;
                opacity: 0;
                transition: opacity 0.3s;
            }
            
            .ays-poll-christmas-banner-copy-notification.show {
                opacity: 1;
            }
            
            /* Dismiss button */
            #ays-poll-christmas-banner-main #ays-poll-christmas-banner-dismiss-content {
                display: flex;
                justify-content: center;
            }
            
            #ays-poll-christmas-banner-main #ays-poll-christmas-banner-dismiss-content .ays-button {
                margin: 0 !important;
                font-size: 13px;
                color: rgba(150, 147, 147, 0.69);
            }
            
            /* Responsive */
            @media (max-width: 1024px) {
                .ays-poll-christmas-banner-discount-text {
                    font-size: 40px;
                }
                .ays-poll-christmas-banner-content {
                    flex-wrap: wrap;
                }
            }
            
            @media (max-width: 768px) {
                #ays-poll-christmas-banner-main {
                    display: none !important;
                }
            }
            /* Christmas banner end */
        ';
        $content[] = '</style>';

        $content = implode( '', $content );

        echo $content;
        
    }

	// Black Friday
    public function ays_poll_black_friday_message(){
        
        $content = array();

        $ays_poll_cta_button_link = esc_url('https://ays-pro.com/wordpress/poll-maker?utm_source=dashboard&utm_medium=poll-free&utm_campaign=black-friday-sale-banner-' . POLL_MAKER_AYS_VERSION);

        $content[] = '<div id="ays-poll-dicount-black-friday-month-main" class="notice notice-success is-dismissible ays_poll_dicount_info">';
            $content[] = '<div id="ays-poll-dicount-black-friday-month" class="ays_poll_dicount_month">';
                $content[] = '<div class="ays-poll-dicount-black-friday-box">';
                    $content[] = '<div class="ays-poll-dicount-black-friday-wrap-box ays-poll-dicount-black-friday-wrap-box-80" style="width: 70%;">';
                        $content[] = '<div class="">';
                            $content[] = '<div class="ays-poll-dicount-black-friday-title-row" >' . __( 'Coupon Code', "poll-maker" ) .' ' . '</div>';
                            $content[] = '<div class="ays-poll-dicount-black-friday-title-row">';

                            $content[] = '
                                <span class="ays-poll-dicount-black-friday-banner-2025-coupon-wrapper">
                                    <span class="ays-poll-dicount-black-friday-banner-2025-coupon-box" onclick="aysPollHalloweenCopyToClipboard(\'FREE2PROBF\')" title="' . __( 'Click to copy', "poll-maker" ) . '">
                                        <span class="ays-poll-dicount-black-friday-banner-2025-coupon-text">FREE2PROBF</span>
                                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" class="ays-poll-dicount-black-friday-banner-2025-copy-icon">
                                            <path d="M13.5 2.5H6.5C5.67 2.5 5 3.17 5 4V10C5 10.83 5.67 11.5 6.5 11.5H13.5C14.33 11.5 15 10.83 15 10V4C15 3.17 14.33 2.5 13.5 2.5ZM13.5 10H6.5V4H13.5V10ZM2.5 6.5V12.5C2.5 13.33 3.17 14 4 14H10V12.5H4V6.5H2.5Z" fill="white"/>
                                        </svg>
                                    </span>
                                </span>';
                            $content[] = '</div> ';
                        $content[] = '</div>';

                    $content[] = '</div>';

                    $content[] = '<div class="ays-poll-dicount-black-friday-wrap-box ays-poll-dicount-black-friday-wrap-text-box">';
                        $content[] = '<div class="ays-poll-dicount-black-friday-text-row">' . '30% off' . '</div>';
                    $content[] = '</div>';

                    $content[] = '<div class="ays-poll-dicount-black-friday-wrap-box" style="width: 25%;">';
                        $content[] = '<div id="ays-poll-countdown-main-container">';
                            $content[] = '<div class="ays-poll-countdown-container">';
                                $content[] = '<div id="ays-poll-countdown" style="display: block;">';
                                    $content[] = '<ul>';
                                        $content[] = '<li><span id="ays-poll-countdown-days"></span>' . __( 'Days', "poll-maker" ) . '</li>';
                                        $content[] = '<li><span id="ays-poll-countdown-hours"></span>' . __( 'Hours', "poll-maker" ) . '</li>';
                                        $content[] = '<li><span id="ays-poll-countdown-minutes"></span>' . __( 'Minutes', "poll-maker" ) . '</li>';
                                        $content[] = '<li><span id="ays-poll-countdown-seconds"></span>' . __( 'Seconds', "poll-maker" ) . '</li>';
                                    $content[] = '</ul>';
                                $content[] = '</div>';
                                $content[] = '<div id="ays-poll-countdown-content" class="emoji" style="display: none;">';
                                    $content[] = '<span></span>';
                                    $content[] = '<span></span>';
                                    $content[] = '<span></span>';
                                    $content[] = '<span></span>';
                                $content[] = '</div>';
                            $content[] = '</div>';
                        $content[] = '</div>';
                    $content[] = '</div>';

                    $content[] = '<div class="ays-poll-dicount-black-friday-wrap-box" style="width: 25%;">';
                        $content[] = '<a href="'. $ays_poll_cta_button_link .'" class="ays-poll-dicount-black-friday-button-buy-now" target="_blank">' . __( 'Get Your Deal', "poll-maker" ) . '</a>';
                    $content[] = '</div>';
                $content[] = '</div>';
            $content[] = '</div>';

            $content[] = '<div style="position: absolute;right: 0;bottom: 1px;"  class="ays-poll-dismiss-buttons-container-for-form-black-friday">';
                $content[] = '<form action="" method="POST">';
                    $content[] = '<div id="ays-poll-dismiss-buttons-content">';
                        if( current_user_can( 'manage_options' ) ){
                            $content[] = '<button class="btn btn-link ays-button" name="ays_poll_sale_btn" style="height: 32px; margin-left: 0;padding-left: 0">Dismiss ad</button>';
                            $content[] = wp_nonce_field( POLL_MAKER_AYS_NAME . '-sale-banner' ,  POLL_MAKER_AYS_NAME . '-sale-banner' );
                        }
                    $content[] = '</div>';
                $content[] = '</form>';
            $content[] = '</div>';
        $content[] = '</div>';

        $content[] = '<script>';
        $content[] = "
                function aysPollHalloweenCopyToClipboard(text) {
                    // Create a temporary textarea element
                    var textarea = document.createElement('textarea');
                    textarea.value = text;
                    textarea.style.position = 'fixed';
                    textarea.style.opacity = '0';
                    document.body.appendChild(textarea);
                    
                    // Select and copy the text
                    textarea.select();
                    textarea.setSelectionRange(0, 99999); // For mobile devices
                    
                    try {
                        document.execCommand('copy');
                        aysPollHalloweenShowCopyNotification('Coupon code copied!');
                    } catch (err) {
                        console.error('Failed to copy text: ', err);
                    }
                    
                    // Remove the temporary textarea
                    document.body.removeChild(textarea);
                }

                function aysPollHalloweenShowCopyNotification(message) {
                    // Check if notification already exists
                    var existingNotification = document.querySelector('.ays-poll-discount-black-friday-banner-2025-copy-notification');
                    if (existingNotification) {
                        document.body.removeChild(existingNotification);
                    }
                    
                    // Create notification element
                    var notification = document.createElement('div');
                    notification.className = 'ays-poll-discount-black-friday-banner-2025-copy-notification';
                    notification.textContent = message;
                    document.body.appendChild(notification);
                    
                    // Show notification with animation
                    setTimeout(function() {
                        notification.classList.add('show');
                    }, 10);
                    
                    // Hide and remove notification after 2 seconds
                    setTimeout(function() {
                        notification.classList.remove('show');
                        setTimeout(function() {
                            if (notification.parentNode) {
                                document.body.removeChild(notification);
                            }
                        }, 300);
                    }, 2000);
                }";
        $content[] = '</script>';

        $content[] = '<style>';
        $content[] = '
            /* Black friday banner start */
            div#ays-poll-dicount-black-friday-month-main *{color:#fff}div#ays-poll-dicount-black-friday-month-main div#ays-poll-dicount-black-friday-month a.ays-poll-sale-banner-link:focus{outline:0;box-shadow:0}div#ays-poll-dicount-black-friday-month-main .btn-link{background-color:transparent;display:inline-block;font-weight:400;text-align:center;white-space:nowrap;vertical-align:middle;-webkit-user-select:none;-moz-user-select:none;-ms-user-select:none;user-select:none;border:1px solid transparent;padding:.375rem .75rem;font-size:12px;line-height:1.5;border-radius:.25rem;color:rgba(255,255,255,.6)}div#ays-poll-dicount-black-friday-month-main.ays_poll_dicount_info{background-image:linear-gradient(45deg,#1e101d,#c60af4);padding:unset;border-left:0}#ays-poll-dicount-black-friday-month-main .ays_poll_dicount_month{position:relative;background-image:url("'. esc_attr(POLL_MAKER_AYS_ADMIN_URL) .'/images/black-friday-plugins-background-image.webp");background-position:center right;background-repeat:no-repeat;background-size:100% 100%}#ays-poll-dicount-black-friday-month-main .ays_poll_dicount_month img{width:80px}#ays-poll-dicount-black-friday-month-main .ays-poll-sale-banner-link{display:flex;justify-content:center;align-items:center;width:200px}#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-button-sale{font-style:normal;font-weight:600;font-size:24px;text-align:center;color:#b2ff00;text-transform:uppercase}#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-wrap-box{font-size:14px;padding:12px;text-align:center;width:50%;white-space:nowrap}#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-wrap-box strong{font-size:17px;font-weight:700;letter-spacing:.8px}#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-wrap-color{color:#971821}#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-wrap-text-decoration{text-decoration:underline}#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-wrap-box.ays-buy-now-button-box{display:flex;justify-content:flex-end;align-items:center;width:30%}#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-wrap-box .ays-button,#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-wrap-box .ays-buy-now-button{align-items:center;font-weight:500}#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-wrap-box .ays-buy-now-button{background:#971821;border-color:#fff;display:flex;justify-content:center;align-items:center;padding:5px 15px;font-size:16px;border-radius:5px}#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-wrap-box .ays-buy-now-button:hover{background:#7d161d;border-color:#971821}#ays-poll-dicount-black-friday-month-main #ays-poll-dismiss-buttons-content{display:flex;justify-content:center}#ays-poll-dicount-black-friday-month-main #ays-poll-dismiss-buttons-content .ays-button{margin:0!important;font-size:13px;color:#969393b0}#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-wrap-opacity-box{width:19%}#ays-poll-dicount-black-friday-month-main .ays-buy-now-opacity-button{padding:40px 15px;display:flex;justify-content:center;align-items:center;opacity:0}#ays-poll-countdown-main-container .ays-poll-countdown-container{margin:0 auto;text-align:center}#ays-poll-countdown-main-container #ays-poll-countdown-headline{letter-spacing:.125rem;text-transform:uppercase;font-size:18px;font-weight:400;margin:0;padding:9px 0 4px;line-height:1.3}#ays-poll-countdown-main-container li,#ays-poll-countdown-main-container ul{margin:0;font-weight:600}#ays-poll-countdown-main-container li{display:inline-block;font-size:10px;list-style-type:none;padding:10px;text-transform:uppercase}#ays-poll-countdown-main-container li span{display:block;font-size:22px;min-height:33px}#ays-poll-countdown-main-container .emoji{display:none;padding:1rem}#ays-poll-countdown-main-container .emoji span{font-size:25px;padding:0 .5rem}#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-box{display:flex;justify-content:space-between;align-items:center;width:95%;margin:auto}#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-title-row{text-align:center;padding-right:50px;font-style:normal;font-weight:900;font-size:19px;color:#fff;}#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-button-buy-now{border:none;outline:0;padding:10px 20px;font-size:22px;text-transform:uppercase;font-weight:700;text-decoration:none;background:linear-gradient(180deg,#dd0bef 0,#82008d 100%);border-radius:16px}#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-text-row{text-transform:uppercase;text-shadow:-1.5px 0 #dd0bef,0 1.5px #dd0bef,1.5px 0 #dd0bef,0 -1.5px #dd0bef;font-weight:900;font-style:normal;font-size:40px;line-height:40px;color:#fff}#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-wrap-text-box{position:absolute;width:25%;top:10px;bottom:0;right:0;left:0;margin:0 auto}#ays-poll-countdown ul{padding:0}#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-banner-2025-coupon-box{border:2px dashed rgba(255,255,255,.4);padding:0 12px;border-radius:6px;background:rgba(255,255,255,.1);cursor:pointer;transition:.3s;display:flex;align-items:center;justify-content:center;gap:6px;backdrop-filter:blur(10px);width:fit-content;margin:0 auto}#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-banner-2025-coupon-box:hover{background:rgba(255,255,255,.2);border-color:rgba(255,255,255,.6);transform:translateY(-1px)}#ays-poll-dicount-black-friday-month-main .ays-poll-discount-black-friday-banner-2025-coupon-text{font-size:14px;font-weight:700;letter-spacing:1px;color:#fff;font-family:monospace}#ays-poll-dicount-black-friday-month-main .ays-poll-discount-black-friday-banner-2025-copy-icon{opacity:.8;transition:opacity .3s}#ays-poll-dicount-black-friday-month-main .ays-poll-discount-black-friday-banner-banner-2025-coupon-box:hover .ays-poll-discount-black-friday-banner-2025-copy-icon,.ays-poll-discount-black-friday-banner-2025-copy-notification.show{opacity:1}.ays-poll-discount-black-friday-banner-2025-copy-notification{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:rgba(0,0,0,.8);color:#fff;padding:12px 24px;border-radius:8px;font-size:14px;z-index:10000;opacity:0;transition:opacity .3s}@media screen and (max-width:1400px) and (min-width:1200px){div#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-title-row{font-size:15px}div#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-text-row{font-size:27px}div#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-box{width:100%}div#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-button-buy-now{font-size:13px}div#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-wrap-box-80{width:80%!important}}@media all and (max-width:1200px){div#ays-poll-dicount-black-friday-month-main .ays_poll_dicount_month{background:unset}div#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-text-row{font-size:30px}div#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-box{width:100%}div#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-button-buy-now{font-size:15px}div#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-wrap-box-80{width:80%!important}}@media all and (max-width:1200px) and (min-width:1150px){div#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-title-row{font-size:15px}div#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-button-buy-now{font-size:10px}}@media all and (max-width:1150px){div#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-wrap-box-80{width:80%!important}div#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-box{flex-direction:column}div#ays-poll-dicount-black-friday-month-main{padding-right:0}div#ays-poll-dicount-black-friday-month-main .ays_poll_dicount_month{display:flex;align-items:center;justify-content:space-between;align-content:center;flex-wrap:wrap;flex-direction:column;padding:10px 0}div#ays-poll-dicount-black-friday-month-main div.ays-poll-dicount-black-friday-wrap-box{width:100%!important;text-align:center}#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-title-row,div#ays-poll-dicount-black-friday-month-main #ays-poll-countdown-main-container ul{padding:0;font-size:13px}#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-button-sale,div#ays-poll-countdown-main-container li{font-size:15px}div#ays-poll-countdown-main-container li span{font-size:25px}div#ays-poll-dicount-black-friday-month-main div.ays-poll-dicount-black-friday-wrap-text-box{position:unset}#ays-poll-countdown-main-container #ays-poll-countdown-headline{font-size:15px;font-weight:600}#ays-poll-countdown-main-container ul{font-weight:500}#ays-poll-countdown-main-container li span{font-size:20px}#ays-poll-dicount-black-friday-month-main .ays-button{margin:0 auto!important}div#ays-poll-dicount-black-friday-month-main.ays_poll_dicount_info.notice{background-position:bottom right;background-repeat:no-repeat;background-size:contain;display:flex;justify-content:center;background-image:linear-gradient(45deg,#1e101d,#c60af4)}div#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-wrap-box.ays-buy-now-button-box{justify-content:center}div#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-wrap-box .ays-buy-now-button{font-size:14px;padding:5px 10px}div#ays-poll-dicount-black-friday-month-main .ays-poll-dicount-black-friday-button-buy-now{padding:10px 18px;font-size:15px}}@media all and (max-width:768px){#ays-poll-dicount-black-friday-month-main{display:none!important}}
            ';
        $content[] = '</style>';

        $content = implode( '', $content );

        echo $content;
    }

    // New Mega Bundle 2026
    public function ays_poll_new_mega_bundle_message_2026(){
        
	    $content = array();

	    $date = time() + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS);
	    $now_date = date('M d, Y H:i:s', $date);

	    $poll_banner_date = strtotime( $this->ays_poll_update_banner_time() );

	    $diff = $poll_banner_date - $date;

	    $style_attr = '';
	    if( $diff < 0 ){
	        $style_attr = 'style="display:none;"';
	    }

	    $poll_cta_button_link = esc_url( 'https://ays-pro.com/mega-bundle?utm_source=dashboard&utm_medium=poll-free&utm_campaign=mega-bundle-sale-banner-' . POLL_MAKER_AYS_VERSION );

	    $content[] = '<div id="ays-poll-new-mega-bundle-dicount-month-main" class="ays-poll-admin-notice notice notice-success is-dismissible ays_poll_dicount_info">';
	        $content[] = '<div id="ays-poll-dicount-month" class="ays_poll_dicount_month">';

	            $content[] = '<div class="ays-poll-dicount-wrap-box ays-poll-dicount-wrap-text-box">';
	                $content[] = '<div>';
	                    $content[] = '<div class="ays-poll-dicount-logo-box">';
	                        $content[] = '<a href="' . $poll_cta_button_link . '" target="_blank" class="ays-poll-sale-banner-link"><img src="' . POLL_MAKER_AYS_ADMIN_URL . '/images/mega_bundle_logo_box.png"></a>';

	                        $content[] = '<div>';
	                            $content[] = '<span class="ays-poll-new-mega-bundle-title">';
	                                $content[] = sprintf(
	                                /* translators: 1: opening link wrapper with <a> tag, 2: closing </a> tag */
	                                __( '%1$s Mega Bundle %2$s (Quiz + Survey + Poll)', 'poll-maker' ),
	                                '<span style="display:inline-block; margin-right:5px;"><a href="' . esc_url( $poll_cta_button_link ) . '" target="_blank" rel="noopener noreferrer" style="color:#ffffff !important; text-decoration: underline;">',
	                                '</a></span>'
	                            );
	                            $content[] = '</span>';
	                            $content[] = '</br>';
	                            $content[] = '<span class="ays-poll-new-mega-bundle-desc">';
	                                $content[] = __( "30 Day Money Back Guarantee", 'poll-maker' );
	                            $content[] = '</span>';
	                        $content[] = '</div>';

	                        $content[] = '<div class="ays-poll-new-mega-bundle-title-icon-row" style="display: inline-block;">';
	                            $content[] = '<img src="' . POLL_MAKER_AYS_ADMIN_URL . '/images/ays-poll-banner-50.svg" class="ays-poll-new-mega-bundle-mobile-image-display-none" style="width: 70px;">';
	                        $content[] = '</div>';

	                    $content[] = '</div>';

	                    $content[] = '<div class="ays-poll-new-mega-bundle-mobile-image-display-block display_none">';
	                        $content[] = '<img src="' . POLL_MAKER_AYS_ADMIN_URL . '/images/ays-poll-banner-50.svg" style="width: 70px;">';
	                    $content[] = '</div>';
	                $content[] = '</div>';

	                $content[] = '<div style="position: absolute;right: 10px;bottom: 1px;" class="ays-poll-dismiss-buttons-container-for-form">';

	                    $content[] = '<form action="" method="POST">';
	                        $content[] = '<div id="ays-poll-dismiss-buttons-content">';
	                        if( current_user_can( 'manage_options' ) ){
	                            $content[] = '<button class="btn btn-link ays-button" name="ays_poll_sale_btn" style="height: 32px; margin-left: 0;padding-left: 0">'. __( "Dismiss ad", 'poll-maker' ) .'</button>';
	                            $content[] = wp_nonce_field( POLL_MAKER_AYS_NAME . '-sale-banner' ,  POLL_MAKER_AYS_NAME . '-sale-banner' );
	                        }
	                        $content[] = '</div>';
	                    $content[] = '</form>';
	                    
	                $content[] = '</div>';

	            $content[] = '</div>';

	            $content[] = '<div class="ays-poll-dicount-wrap-box ays-poll-dicount-wrap-countdown-box">';

	                $content[] = '<div id="ays-poll-maker-countdown-main-container">';
	                    $content[] = '<div class="ays-poll-maker-countdown-container">';

	                        $content[] = '<div ' . $style_attr . ' id="ays-poll-countdown">';

	                            $content[] = '<ul>';

	                            $content[] = '<li><span id="ays-poll-countdown-days"></span></li>';
	                                $content[] = '<li><span id="ays-poll-countdown-hours"></span></li>';
	                                $content[] = '<li><span id="ays-poll-countdown-minutes"></span></li>';
	                                $content[] = '<li><span id="ays-poll-countdown-seconds"></span></li>';
	                            $content[] = '</ul>';
	                        $content[] = '</div>';

	                        $content[] = '<div id="ays-poll-countdown-content" class="emoji">';
	                        $content[] = '</div>';

	                    $content[] = '</div>';
	                $content[] = '</div>';
	                    
	            $content[] = '</div>';

	            $content[] = '<div class="ays-poll-dicount-wrap-box ays-poll-dicount-wrap-button-box">';
	                $content[] = '<a href="'. $poll_cta_button_link .'" class="button button-primary ays-button" id="ays-button-top-buy-now" target="_blank">' . __( 'Buy Now', 'poll-maker' ) . '</a>';
	                $content[] = '<span class="ays-poll-dicount-one-time-text">';
	                    $content[] = __( "One-time payment", 'poll-maker' );
	                $content[] = '</span>';
	            $content[] = '</div>';
	        $content[] = '</div>';
	    $content[] = '</div>';

	    // /* New Mega Bundle Banner Quiz | Start */
	    $content[] = '<style id="ays-poll-mega-bundle-styles-inline-css">';
	    $content[] = '
	    div#ays-poll-new-mega-bundle-dicount-month-main{border:0;background:#fff;border-radius:20px;box-shadow:unset;position:relative;z-index:1;min-height:80px}div#ays-poll-new-mega-bundle-dicount-month-main.ays_poll_dicount_info button{display:flex;align-items:center}div#ays-poll-new-mega-bundle-dicount-month-main div#ays-poll-dicount-month a.ays-poll-sale-banner-link:focus{outline:0;box-shadow:0}div#ays-poll-new-mega-bundle-dicount-month-main .btn-link{color:#007bff;background-color:transparent;display:inline-block;font-weight:400;text-align:center;white-space:nowrap;vertical-align:middle;-webkit-user-select:none;-moz-user-select:none;-ms-user-select:none;user-select:none;border:1px solid transparent;padding:.375rem .75rem;font-size:1rem;line-height:1.5;border-radius:.25rem}div#ays-poll-new-mega-bundle-dicount-month-main.ays_poll_dicount_info{background-image:url("'. POLL_MAKER_AYS_ADMIN_URL .'/images/ays-poll-banner-background-50.svg");background-position:center right;background-repeat:no-repeat;background-size:cover;background-color:#5551ff;padding:1px 38px 1px 12px}#ays-poll-new-mega-bundle-dicount-month-main .ays_poll_dicount_month{display:flex;align-items:center;justify-content:space-between;color:#fff}#ays-poll-new-mega-bundle-dicount-month-main .ays_poll_dicount_month img{width:60px}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-sale-banner-link{display:flex;justify-content:center;align-items:center;width:60px}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box{font-size:14px;padding:12px;text-align:center}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-text-box{text-align:left;width:auto;display:flex;justify-content:space-around;align-items:flex-start}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-countdown-box{width:30%;display:flex;justify-content:center;align-items:center}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-button-box{width:20%;display:flex;justify-content:center;align-items:center;flex-direction:column}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box .ays-poll-dicount-logo-box{display:flex;justify-content:flex-start;align-items:center;gap:20px}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-text-box .ays-poll-new-mega-bundle-title{color:#fdfdfd;font-size:19px;font-style:normal;font-weight:600;line-height:normal}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-text-box .ays-poll-new-mega-bundle-title-icon-row{display:inline-block}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-text-box .ays-poll-new-mega-bundle-desc{display:inline-block;color:#fff;font-size:15px;font-style:normal;font-weight:400;line-height:normal;margin-top:10px}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box strong{font-size:17px;font-weight:700;letter-spacing:.8px}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-color{color:#971821}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-text-decoration{text-decoration:underline}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box.ays-buy-now-button-box{display:flex;justify-content:flex-end;align-items:center;width:30%}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box .ays-button,#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box .ays-buy-now-button{align-items:center;font-weight:500}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box .ays-buy-now-button{background:#971821;border-color:#fff;display:flex;justify-content:center;align-items:center;padding:5px 15px;font-size:16px;border-radius:5px}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box .ays-buy-now-button:hover{background:#7d161d;border-color:#971821}#ays-poll-new-mega-bundle-dicount-month-main #ays-poll-dismiss-buttons-content{display:flex;justify-content:center}#ays-poll-new-mega-bundle-dicount-month-main #ays-poll-dismiss-buttons-content .ays-button{margin:0!important;font-size:13px;color:#fff}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-opacity-box{width:19%}#ays-poll-new-mega-bundle-dicount-month-main .ays-buy-now-opacity-button{padding:40px 15px;display:flex;justify-content:center;align-items:center;opacity:0}#ays-poll-maker-countdown-main-container .ays-poll-maker-countdown-container{margin:0 auto;text-align:center}#ays-poll-maker-countdown-main-container #ays-poll-countdown-headline{letter-spacing:.125rem;text-transform:uppercase;font-size:18px;font-weight:400;margin:0;padding:9px 0 4px;line-height:1.3}#ays-poll-maker-countdown-main-container li,#ays-poll-maker-countdown-main-container ul{margin:0}#ays-poll-maker-countdown-main-container li{display:inline-block;font-size:14px;list-style-type:none;padding:14px;text-transform:lowercase}#ays-poll-maker-countdown-main-container li span{display:flex;justify-content:center;align-items:center;font-size:22px;min-height:40px;min-width:40px;border-radius:4.273px;border:.534px solid #f4f4f4;background:#9896ed;color:#fff}#ays-poll-maker-countdown-main-container .emoji{display:none;padding:1rem}#ays-poll-maker-countdown-main-container .emoji span{font-size:30px;padding:0 .5rem}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box li{position:relative}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box li span:after{content:":";color:#fff;position:absolute;top:0;right:-5px;font-size:40px}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box li span#ays-poll-countdown-seconds:after{content:unset}#ays-poll-new-mega-bundle-dicount-month-main #ays-button-top-buy-now{display:flex;align-items:center;border-radius:6.409px;background:#f66123;padding:12px 32px;color:#fff;font-size:15px;font-style:normal;line-height:normal;margin:0!important}div#ays-poll-new-mega-bundle-dicount-month-main button.notice-dismiss:before{color:#fff;content:"\f00d";font-family:fontawesome;font-size:22px}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-new-mega-bundle-guaranteeicon{width:30px;margin-right:5px}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-one-time-text{color:#fff;font-size:12px;font-style:normal;font-weight:600;line-height:normal}@media all and (max-width:768px){div#ays-poll-new-mega-bundle-dicount-month-main.ays_poll_dicount_info.notice{display:none!important;background-position:bottom right;background-repeat:no-repeat;background-size:cover;border-radius:32px}div#ays-poll-new-mega-bundle-dicount-month-main{padding-right:0}div#ays-poll-new-mega-bundle-dicount-month-main .ays_poll_dicount_month{display:flex;align-items:center;justify-content:space-between;align-content:center;flex-wrap:wrap;flex-direction:column;padding:10px 0}div#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box{width:100%!important;text-align:center}#ays-poll-maker-countdown-main-container #ays-poll-countdown-headline{font-size:15px;font-weight:600}#ays-poll-maker-countdown-main-container ul{font-weight:500}div#ays-poll-maker-countdown-main-container li{padding:10px}div#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-new-mega-bundle-mobile-image-display-none{display:none!important}div#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-new-mega-bundle-mobile-image-display-block{display:block!important;margin-top:5px}div#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-text-box{width:100%!important;text-align:center;flex-direction:column;margin-top:20px;justify-content:center;align-items:center}div#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box li span:after{top:unset}div#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-countdown-box{width:100%;display:flex;justify-content:center;align-items:center}#ays-poll-new-mega-bundle-dicount-month-main .ays-button{margin:0 auto!important}#ays-poll-new-mega-bundle-dicount-month-main #ays-poll-dismiss-buttons-content .ays-button{padding-left:unset!important}div#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box.ays-buy-now-button-box{justify-content:center}div#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box .ays-buy-now-button{font-size:14px;padding:5px 10px}div#ays-poll-new-mega-bundle-dicount-month-main .ays-buy-now-opacity-button{display:none}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dismiss-buttons-container-for-form{position:static!important}.comparison .product img{width:70px}.ays-poll-features-wrap .comparison a.price-buy{padding:8px 5px;font-size:11px}}@media screen and (max-width:1350px) and (min-width:768px){div#ays-poll-new-mega-bundle-dicount-month-main.ays_poll_dicount_info.notice{background-position:bottom right;background-repeat:no-repeat;background-size:cover}div#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box strong{font-size:15px}#ays-poll-maker-countdown-main-container li{font-size:11px}div#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-opacity-box{display:none}}@media screen and (max-width:1680px) and (min-width:1551px){div#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-text-box{width:29%}div#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-countdown-box{width:30%}}@media screen and (max-width:1410px){#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-coupon-row{width:150px}}@media screen and (max-width:1550px) and (min-width:1400px){div#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-countdown-box{width:35%}}@media screen and (max-width:1400px) and (min-width:1250px){div#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-countdown-box{width:35%}div#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-text-box{width:40%}}@media screen and (max-width:1274px){#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-text-box .ays-poll-new-mega-bundle-title{font-size:15px}}@media screen and (max-width:1200px){#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-button-box{margin-bottom:16px}#ays-poll-maker-countdown-main-container ul{padding-left:0}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-coupon-row{width:120px;font-size:18px}#ays-poll-new-mega-bundle-dicount-month-main #ays-button-top-buy-now{padding:12px 20px}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box{font-size:12px}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-text-box .ays-poll-new-mega-bundle-desc{font-size:13px}}@media screen and (max-width:1076px) and (min-width:769px){#ays-poll-maker-countdown-main-container li{padding:10px}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-coupon-row{width:100px;font-size:16px}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-button-box{margin-bottom:16px}#ays-poll-new-mega-bundle-dicount-month-main #ays-button-top-buy-now{padding:12px 15px}#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box{font-size:11px;padding:12px 0}}@media screen and (max-width:1250px) and (min-width:769px){div#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-countdown-box{width:45%}div#ays-poll-new-mega-bundle-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-text-box{width:35%}}';
	    $content[] = '</style>';
	    // /* New Mega Bundle Banner Quiz | End */

	    $content = implode( '', $content );
	    echo ($content);        
    }

	// Halloween Bundle 2025
    public function ays_poll_new_halloween_bundle_message_2025(){
        
        $content = array();

        $date = time() + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS);
        $now_date = date('M d, Y H:i:s', $date);

        $start_date = strtotime('2025-09-08');
        $end_date = strtotime('2025-10-31');
        $diff_end = $end_date - $date;

        $style_attr = '';
        if( $diff_end < 0 ){
            $style_attr = 'style="display:none;"';
        }

        $ays_poll_cta_button_link = esc_url('https://ays-pro.com/halloween-bundle?utm_source=dashboard&utm_medium=poll-free&utm_campaign=poll-halloween-banner-' . POLL_MAKER_AYS_VERSION);

        $content[] = '
            <div id="ays-poll-halloween-banner-2025-main" class="ays-poll-halloween-banner-2025-main notice notice-success is-dismissible">
                <div class="ays-poll-halloween-banner-2025-content">
                    <div class="ays-poll-halloween-banner-2025-left">
                        <div class="ays-poll-halloween-banner-2025-text">
                            <h2 class="ays-poll-halloween-banner-2025-title">Boo! Grab Your <a href="'. $ays_poll_cta_button_link .'" class="" target="_blank">Halloween Deal</a> <br/> Before It Vanishes!</h2>
                            <p class="ays-poll-halloween-banner-2025-subtitle">Don’t get spooked by missing out!<br/> Get 50% off our exclusive Halloween Bundle (Survey + SCCP + Poll Maker + Popup Box) while the magic lasts!</p>
                        </div>
                    </div>

                    <div class="ays-poll-halloween-banner-2025-center">';

                    $content[] = '<div id="ays-poll-halloween-banner-2025-countdown" class="ays-poll-halloween-banner-2025-countdown" ' . $style_attr . '>';
                        $content[] = '<div class="ays-poll-halloween-banner-2025-countdown-timer">';
                            $content[] = '<div class="ays-poll-halloween-banner-2025-countdown-item">';
                                $content[] = '<div class="ays-poll-halloween-banner-2025-countdown-value" id="ays-poll-halloween-banner-2025-days">00</div>';
                                $content[] = '<div class="ays-poll-halloween-banner-2025-countdown-label">' . __('days', 'poll-maker') . '</div>';
                            $content[] = '</div>';
                            $content[] = '<div class="ays-poll-halloween-banner-2025-countdown-separator">:</div>';
                            $content[] = '<div class="ays-poll-halloween-banner-2025-countdown-item">';
                                $content[] = '<div class="ays-poll-halloween-banner-2025-countdown-value" id="ays-poll-halloween-banner-2025-hours">00</div>';
                                $content[] = '<div class="ays-poll-halloween-banner-2025-countdown-label">' . __('hours', 'poll-maker') . '</div>';
                            $content[] = '</div>';
                            $content[] = '<div class="ays-poll-halloween-banner-2025-countdown-separator">:</div>';
                            $content[] = '<div class="ays-poll-halloween-banner-2025-countdown-item">';
                                $content[] = '<div class="ays-poll-halloween-banner-2025-countdown-value" id="ays-poll-halloween-banner-2025-minutes">00</div>';
                                $content[] = '<div class="ays-poll-halloween-banner-2025-countdown-label">' . __('minutes', 'poll-maker') . '</div>';
                            $content[] = '</div>';
                            $content[] = '<div class="ays-poll-halloween-banner-2025-countdown-separator">:</div>';
                            $content[] = '<div class="ays-poll-halloween-banner-2025-countdown-item">';
                                $content[] = '<div class="ays-poll-halloween-banner-2025-countdown-value" id="ays-poll-halloween-banner-2025-seconds">00</div>';
                                $content[] = '<div class="ays-poll-halloween-banner-2025-countdown-label">' . __('seconds', 'poll-maker') . '</div>';
                            $content[] = '</div>';
                        $content[] = '</div>';
                    $content[] = '</div>';

                    $content[] = '</div>
                                            
                    <div class="ays-poll-halloween-banner-2025-right">
                        <div class="ays-poll-halloween-banner-2025-pumpkin">
                            <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <g clip-path="url(#clip0_177_40)">
                                <path d="M32.664 8.519C29.364 5.134 23.42 4.75 18 4.75C12.58 4.75 6.636 5.134 3.336 8.519C0.582 11.344 0 15.751 0 19.791C0 25.054 1.982 31.102 6.357 34.035C9.364 36.051 13.95 35.871 18 35.871C22.05 35.871 26.636 36.051 29.643 34.035C34.018 31.101 36 25.054 36 19.791C36 15.751 35.418 11.344 32.664 8.519Z" fill="#F4900C"/>
                                <path d="M20.783 5.44401C20.852 5.86401 20.561 6.20801 20.136 6.20801H15.685C15.259 6.20801 14.968 5.86401 15.038 5.44401L15.783 0.972008C15.853 0.551008 16.259 0.208008 16.685 0.208008H19.136C19.562 0.208008 19.968 0.552008 20.037 0.972008L20.783 5.44401Z" fill="#3F7123"/>
                                <path d="M20.6541 21.159L19.0561 18.563C18.7651 18.021 18.3831 17.75 17.9991 17.746C17.6161 17.75 17.2331 18.021 16.9421 18.563L15.3441 21.159C14.7571 22.252 16.2171 22.875 17.9981 22.875C19.7791 22.875 21.2411 22.251 20.6541 21.159ZM30.1621 24.351C30.1171 24.276 30.0361 24.23 29.9481 24.23H29.1071C29.0391 24.23 28.9731 24.258 28.9261 24.307L26.6951 26.641L23.9971 24.472C23.9461 24.431 23.8801 24.414 23.8121 24.419C23.7461 24.426 23.6851 24.46 23.6441 24.513L21.2361 27.575L18.1821 24.309C18.1691 24.295 18.1491 24.292 18.1341 24.281C18.1191 24.271 18.1091 24.254 18.0911 24.247C18.0851 24.245 18.0781 24.247 18.0721 24.245C18.0481 24.238 18.0251 24.24 18.0001 24.24C17.9751 24.24 17.9521 24.238 17.9281 24.246C17.9221 24.248 17.9151 24.245 17.9081 24.248C17.8901 24.255 17.8811 24.272 17.8651 24.282C17.8491 24.292 17.8301 24.295 17.8171 24.309L14.7641 27.575L12.3551 24.513C12.3141 24.46 12.2531 24.426 12.1871 24.419C12.1211 24.413 12.0541 24.431 12.0021 24.472L9.30411 26.641L7.07411 24.307C7.02711 24.258 6.96211 24.23 6.89311 24.23H6.05211C5.96511 24.23 5.88311 24.276 5.83811 24.351C5.79311 24.426 5.79011 24.519 5.83111 24.596L8.58511 29.815C8.61911 29.879 8.6781 29.925 8.7491 29.942C8.8201 29.959 8.8941 29.944 8.9521 29.902L10.9861 28.444L13.9901 32.077C14.0331 32.13 14.0961 32.162 14.1641 32.167L14.1831 32.168C14.2451 32.168 14.3041 32.146 14.3501 32.105L18.0001 28.836L21.6501 32.104C21.6961 32.145 21.7551 32.167 21.8171 32.167L21.8361 32.166C21.9041 32.161 21.9671 32.129 22.0101 32.076L25.0151 28.443L27.0491 29.901C27.1091 29.944 27.1821 29.961 27.2521 29.941C27.3221 29.924 27.3821 29.879 27.4151 29.815L30.1701 24.596C30.2101 24.519 30.2081 24.426 30.1621 24.351ZM27.9761 15.421C28.1051 17.548 27.1921 19.227 24.7711 19.374C22.3511 19.52 21.2421 17.963 21.1131 15.837C20.9841 13.711 22.3451 10.717 24.2401 10.603C26.1361 10.487 27.8481 13.294 27.9761 15.421ZM8.02411 15.421C7.89511 17.548 8.80811 19.227 11.2291 19.374C13.6491 19.52 14.7581 17.963 14.8871 15.837C15.0161 13.711 13.6551 10.717 11.7601 10.603C9.86511 10.489 8.15211 13.294 8.02411 15.421Z" fill="#642116"/>
                                </g>
                                <defs>
                                <clipPath id="clip0_177_40">
                                <rect width="36" height="36" fill="white"/>
                                </clipPath>
                                </defs>
                            </svg>
                        </div>



                        <div class="ays-poll-halloween-banner-2025-discount-section">
                            <div class="ays-poll-halloween-banner-2025-discount">50% OFF</div>
                            <div class="ays-poll-halloween-banner-2025-coupon-wrapper">
                                <div class="ays-poll-halloween-banner-2025-coupon-box" onclick="aysPollHalloweenCopyToClipboard(\'HALLOWEEN25\')" title="' . __( 'Click to copy', "poll-maker" ) . '">
                                    <span class="ays-poll-halloween-banner-2025-coupon-text">HALLOWEEN25</span>
                                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" class="ays-poll-halloween-banner-2025-copy-icon">
                                        <path d="M13.5 2.5H6.5C5.67 2.5 5 3.17 5 4V10C5 10.83 5.67 11.5 6.5 11.5H13.5C14.33 11.5 15 10.83 15 10V4C15 3.17 14.33 2.5 13.5 2.5ZM13.5 10H6.5V4H13.5V10ZM2.5 6.5V12.5C2.5 13.33 3.17 14 4 14H10V12.5H4V6.5H2.5Z" fill="white"/>
                                    </svg>
                                </div>
                            </div>
                            <a href="'. $ays_poll_cta_button_link .'" class="ays-poll-halloween-banner-2025-upgrade" target="_blank">Buy Now</a>
                        </div>';

                        if( current_user_can( 'manage_options' ) ){
                            $content[] = '<div id="ays-poll-dismiss-buttons-content">';
                                $content[] = '<form action="" method="POST" style="position: absolute; bottom: -5px; right: 0; color: #fff;">';
                                        $content[] = '<button class="btn btn-link ays-button" name="ays_poll_sale_btn" style="color: darkgrey; font-size: 11px;">'. __( "Dismiss ad", 'poll-maker' ) .'</button>';
                                        $content[] = wp_nonce_field( POLL_MAKER_AYS_NAME . '-sale-banner' ,  POLL_MAKER_AYS_NAME . '-sale-banner' );
                                $content[] = '</form>';
                            $content[] = '</div>';
                        }

                        $content[] = '
                    </div>
                </div>
            </div>';

        $content[] = '<style id="ays-poll-progress-banner-styles-inline-css">';
        $content[] = '
            .ays-poll-halloween-banner-2025-main {
                background: linear-gradient(135deg, #1A0F2E 100%, #2D1B4E 0%);
                background-image: url("' . esc_attr( POLL_MAKER_AYS_ADMIN_URL ) . '/images/halloween-banner-background-image-remove.png"), linear-gradient(135deg, #2D1B4E 0%, #1A0F2E 100%);
                background-position: left center, center;
                background-repeat: no-repeat, no-repeat;
                background-size: auto 100%, cover;
                padding: 20px 30px 20px 130px;
                border-radius: 12px;
                color: white;
                margin: 20px 0;
                border: 0;
                box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
                overflow: hidden;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-content {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 30px;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-left {
                display: flex;
                align-items: center;
                gap: 20px;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-center {
                display: flex;
                align-items: center;
                justify-content: center;
                flex: 1;
                max-width: 350px;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-right {
                display: flex;
                align-items: center;
                gap: 15px;
                flex-shrink: 0;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-icon {
                flex-shrink: 0;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-pumpkin svg {
                display: inline !important;
                border: none !important;
                box-shadow: none !important;
                height: 1em !important;
                width: 1em !important;
                margin: 0 0.07em !important;
                vertical-align: -0.1em !important;
                background: none !important;
                padding: 0 !important;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-orb {
                width: 80px;
                height: 80px;
                background: linear-gradient(135deg, #8B5CF6 0%, #6D28D9 100%);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: 0 0 30px rgba(139, 92, 246, 0.6);
                border: 3px solid rgba(168, 85, 247, 0.4);
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-question {
                font-size: 48px;
                font-weight: 700;
                color: #E9D5FF;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-bat {
                font-size: 20px;
                opacity: 0.8;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-bat-1 {
                margin-left: -100px;
                margin-top: -40px;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-bat-2 {
                margin-left: -110px;
                margin-top: 35px;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-sparkle {
                font-size: 12px;
                opacity: 0.7;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-sparkle-1 {
                margin-left: -95px;
                margin-top: -60px;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-sparkle-2 {
                margin-left: -70px;
                margin-top: -15px;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-sparkle-3 {
                margin-left: -120px;
                margin-top: 10px;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-text {
                flex: 1;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-title {
                font-size: 24px;
                font-weight: 700;
                margin: 0 0 8px 0;
                line-height: 1.2;
                color: #fff;
                text-transform: uppercase;
                letter-spacing: 1px;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-title a {
                color: #FB923C;
                text-decoration: underline;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-subtitle {
                font-size: 16px;
                margin: 0;
                opacity: 0.9;
                font-weight: 400;
                color: #E9D5FF;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-description {
                font-size: 14px;
                margin: 0;
                opacity: 0.85;
                line-height: 1.5;
                color: #D8B4FE;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-pumpkin {
                font-size: 64px;
                filter: drop-shadow(0 0 20px rgba(251, 146, 60, 0.8));
                flex-shrink: 0;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-discount-section {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 10px;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-discount {
                font-size: 36px;
                font-weight: 700;
                color: #FB923C;
                text-shadow: 0 0 20px rgba(251, 146, 60, 0.6);
                margin: 0;
                line-height: 1;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-coupon-wrapper {
            	display:none;
                margin-bottom: 5px;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-coupon-box {
                border: 2px dashed rgba(255, 255, 255, 0.4);
                padding: 6px 12px;
                border-radius: 6px;
                background: rgba(255, 255, 255, 0.1);
                cursor: pointer;
                transition: all 0.3s ease;
                display: flex;
                align-items: center;
                gap: 6px;
                backdrop-filter: blur(10px);
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-coupon-box:hover {
                background: rgba(255, 255, 255, 0.2);
                border-color: rgba(255, 255, 255, 0.6);
                transform: translateY(-1px);
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-coupon-text {
                font-size: 14px;
                font-weight: 700;
                letter-spacing: 1px;
                color: #fff;
                font-family: monospace;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-copy-icon {
                opacity: 0.8;
                transition: opacity 0.3s ease;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-coupon-box:hover .ays-poll-halloween-banner-2025-copy-icon {
                opacity: 1;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-upgrade {
                background: linear-gradient(135deg, #FB923C 0%, #F97316 100%);
                color: white;
                border: none;
                padding: 12px 28px;
                border-radius: 8px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                box-shadow: 0 4px 16px rgba(251, 146, 60, 0.5);
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                text-align: center;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-upgrade:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(251, 146, 60, 0.7);
                text-decoration: none;
                color: white;
            }

            .ays-poll-halloween-banner-2025-main .notice-dismiss:before {
                color: #fff;
            }

            .ays-poll-halloween-banner-2025-copy-notification {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: rgba(0, 0, 0, 0.8);
                color: white;
                padding: 12px 24px;
                border-radius: 8px;
                font-size: 14px;
                z-index: 10000;
                opacity: 0;
                transition: opacity 0.3s ease;
            }

            .ays-poll-halloween-banner-2025-copy-notification.show {
                opacity: 1;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-countdown-timer {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-countdown-item {
                background: rgba(255, 255, 255, 0.15);
                border-radius: 8px;
                padding: 8px 12px;
                min-width: 60px;
                backdrop-filter: blur(10px);
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-countdown-value {
                font-size: 24px;
                font-weight: 700;
                line-height: 1;
                margin-bottom: 4px;
                color: #fff;
                text-align: center;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-countdown-label {
                font-size: 11px;
                opacity: 0.8;
                text-transform: lowercase;
                text-align: center;
            }

            .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-countdown-separator {
                font-size: 24px;
                font-weight: 700;
                opacity: 0.6;
                margin: 0 4px;
            }

            @media (min-width: 1200px) {
                .ays-poll-halloween-banner-2025-main .wp-core-ui .notice.is-dismissible {
                    padding-right: 60px;
                }
            }

            @media (max-width: 1200px) {

                div.ays-poll-halloween-banner-2025-main {
                    padding: 20px 30px;
                }

                div.ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-pumpkin {
                    display: none;
                }

                .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-subtitle,
                .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-title {
                    text-align: center;
                }

                .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-content {
                    flex-wrap: wrap;
                    gap: 20px;
                }

                .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-left {
                    width: 100%;
                }

                .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-center {
                    width: 100%;
                    max-width: 100%;
                    text-align: center;
                }

                .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-right {
                    width: 100%;
                    justify-content: center;
                }
            }

            @media (max-width: 786px) {
                #ays-poll-halloween-banner-2025-main {
                    display: none !important;
                }
            }

            @media (max-width: 768px) {
                .ays-poll-halloween-banner-2025-main {
                    padding: 15px 20px;
                    margin: 15px 0;
                }

                .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-title {
                    font-size: 20px;
                }

                .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-subtitle {
                    font-size: 14px;
                }

                .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-description {
                    font-size: 13px;
                }

                .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-pumpkin {
                    font-size: 48px;
                }

                .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-discount {
                    font-size: 28px;
                }

                .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-upgrade {
                    padding: 10px 20px;
                    font-size: 14px;
                }
            }

            @media (max-width: 480px) {
                .ays-poll-halloween-banner-2025-main {
                    padding: 12px 15px;
                }

                .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-orb {
                    width: 60px;
                    height: 60px;
                }

                .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-question {
                    font-size: 36px;
                }

                .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-title {
                    font-size: 18px;
                }

                .ays-poll-halloween-banner-2025-main .ays-poll-halloween-banner-2025-coupon-text {
                    font-size: 12px;
                }
            }
        ';

        $content[] = '</style>';

        $content[] = '<script>';
        $content[] = "
                function aysPollHalloweenCopyToClipboard(text) {
                    // Create a temporary textarea element
                    var textarea = document.createElement('textarea');
                    textarea.value = text;
                    textarea.style.position = 'fixed';
                    textarea.style.opacity = '0';
                    document.body.appendChild(textarea);
                    
                    // Select and copy the text
                    textarea.select();
                    textarea.setSelectionRange(0, 99999); // For mobile devices
                    
                    try {
                        document.execCommand('copy');
                        aysPollHalloweenShowCopyNotification('Coupon code copied!');
                    } catch (err) {
                        console.error('Failed to copy text: ', err);
                    }
                    
                    // Remove the temporary textarea
                    document.body.removeChild(textarea);
                }

                function aysPollHalloweenShowCopyNotification(message) {
                    // Check if notification already exists
                    var existingNotification = document.querySelector('.ays-poll-halloween-banner-2025-copy-notification');
                    if (existingNotification) {
                        document.body.removeChild(existingNotification);
                    }
                    
                    // Create notification element
                    var notification = document.createElement('div');
                    notification.className = 'ays-poll-halloween-banner-2025-copy-notification';
                    notification.textContent = message;
                    document.body.appendChild(notification);
                    
                    // Show notification with animation
                    setTimeout(function() {
                        notification.classList.add('show');
                    }, 10);
                    
                    // Hide and remove notification after 2 seconds
                    setTimeout(function() {
                        notification.classList.remove('show');
                        setTimeout(function() {
                            if (notification.parentNode) {
                                document.body.removeChild(notification);
                            }
                        }, 300);
                    }, 2000);
                }

                (function() {
                    var endDate = new Date('". date('Y-m-d H:i:s', $end_date) ."').getTime();
                
                    function updateCountdown() {
                        var now = new Date().getTime();
                        var distance = endDate - now;
                        
                        if (distance < 0) {
                            clearInterval(updateCountdown);
                            document.getElementById('ays-poll-halloween-banner-2025-progress-banner-countdown').style.display = 'none';
                            return;
                        }
                        
                        var days = Math.floor(distance / (1000 * 60 * 60 * 24));
                        var hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                        var minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                        var seconds = Math.floor((distance % (1000 * 60)) / 1000);
                        
                        function padZero(num) {
                            return num < 10 ? '0' + num : num;
                        }
                        
                        document.getElementById('ays-poll-halloween-banner-2025-days').textContent = padZero(days);
                        document.getElementById('ays-poll-halloween-banner-2025-hours').textContent = padZero(hours);
                        document.getElementById('ays-poll-halloween-banner-2025-minutes').textContent = padZero(minutes);
                        document.getElementById('ays-poll-halloween-banner-2025-seconds').textContent = padZero(seconds);
                    }
                    
                    updateCountdown();
                    setInterval(updateCountdown, 1000);
                })()";
        $content[] = '</script>';

        $content = implode( '', $content );
        echo ($content);
    }    

    // Fox LMS Pro Banner
    public function ays_poll_discounted_licenses_banner_message(){
        
        $content = array();

        $date = time() + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS);
        $now_date = date('M d, Y H:i:s', $date);

        $start_date = strtotime('2025-09-21');
        $end_date = strtotime('2025-10-21');
        $diff_end = $end_date - $date;

        $style_attr = '';
        if( $diff_end < 0 ){
            $style_attr = 'style="display:none;"';
        }

        $total_licenses = 50;
        $progression_pattern = array(3, 2, 1, 4, 2, 3, 1, 2, 4, 3, 2, 1, 3, 2, 4, 1, 3, 2, 2, 3, 1, 2);
        $days_passed = floor(($date - $start_date) / (24 * 60 * 60));
        $used_licenses = 0;

        for ($i = 0; $i < min($days_passed, count($progression_pattern)); $i++) {
            $used_licenses += $progression_pattern[$i];
        }
        $used_licenses = min($used_licenses, $total_licenses);
        $remaining_licenses = $total_licenses - $used_licenses;
        $progress_percentage = ($used_licenses / $total_licenses) * 100;

        $ays_poll_cta_button_link = esc_url('https://ays-pro.com/wordpress/poll-maker?utm_source=dashboard&utm_medium=poll-free&utm_campaign=poll-maker-license-banner-' . POLL_MAKER_AYS_VERSION);

        $content[] = '<div id="ays-poll-progress-banner-main" class="ays-poll-progress-banner-main ays-poll-admin-notice notice notice-success is-dismissible" ' . $style_attr . '>';
            $content[] = '<div class="ays-poll-progress-banner-content">';
                $content[] = '<div class="ays-poll-progress-banner-left">';
                    $content[] = '<div class="ays-poll-progress-banner-icon">';
                        $content[] = '<svg width="48" height="48" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M1.33325 22.6668L11.9999 13.3335L33.3333 14.6668L34.6666 36.0002L25.3333 46.6668C25.3333 46.6668 25.3346 38.6682 17.3333 30.6668C9.33192 22.6655 1.33325 22.6668 1.33325 22.6668Z" fill="#A0041E"/>
                                        <path d="M1.29739 46.6665C1.29739 46.6665 1.24939 36.0278 5.27739 31.9998C9.30539 27.9718 20.0001 28.2492 20.0001 28.2492C20.0001 28.2492 19.9987 38.6665 15.9987 42.6665C11.9987 46.6665 1.29739 46.6665 1.29739 46.6665Z" fill="#FFAC33"/>
                                        <path d="M11.9986 41.3332C14.9441 41.3332 17.3319 38.9454 17.3319 35.9998C17.3319 33.0543 14.9441 30.6665 11.9986 30.6665C9.0531 30.6665 6.66528 33.0543 6.66528 35.9998C6.66528 38.9454 9.0531 41.3332 11.9986 41.3332Z" fill="#FFCC4D"/>
                                        <path d="M47.9986 0C47.9986 0 34.6653 0 18.6653 13.3333C10.6653 20 10.6653 32 13.3319 34.6667C15.9986 37.3333 27.9986 37.3333 34.6653 29.3333C47.9986 13.3333 47.9986 0 47.9986 0Z" fill="#55ACEE"/>
                                        <path d="M35.9987 6.6665C33.8347 6.6665 31.9814 7.96117 31.144 9.81317C31.8134 9.5105 32.5507 9.33317 33.332 9.33317C36.2774 9.33317 38.6654 11.7212 38.6654 14.6665C38.6654 15.4478 38.488 16.1852 38.1867 16.8532C40.0387 16.0172 41.332 14.1638 41.332 11.9998C41.332 9.0545 38.944 6.6665 35.9987 6.6665Z" fill="black"/>
                                        <path d="M10.6667 37.3332C10.6667 37.3332 10.6667 31.9998 12.0001 30.6665C13.3334 29.3332 29.3347 16.0012 30.6667 17.3332C31.9987 18.6652 18.6654 34.6665 17.3321 35.9998C15.9987 37.3332 10.6667 37.3332 10.6667 37.3332Z" fill="#A0041E"/>
                                        </svg>';
                    $content[] = '</div>';
                    $content[] = '<div class="ays-poll-progress-banner-text">';
                        $content[] = '<h2 class="ays-poll-progress-banner-title">' . sprintf( __('Get the Pro Version of %s Poll Maker%s – 20%% OFF', 'poll-maker'), '<a href="'. $ays_poll_cta_button_link .'" target="_blank">', '</a>' ) . '</h2>';
                        $content[] = '<p class="ays-poll-progress-banner-subtitle">' . __('Unlock advanced features + 30 day Money Back Guarantee', 'poll-maker') . '</p>';
                    $content[] = '</div>';
                $content[] = '</div>';
                
                $content[] = '<div class="ays-poll-progress-banner-center">';
                    $content[] = '<div class="ays-poll-progress-banner-coupon">';
                        $content[] = '<div class="ays-poll-progress-banner-coupon-box" onclick="pollCopyToClipboard(\'FREE2PRO20\')" title="' . __('Click to copy', 'poll-maker') . '">';
                            $content[] = '<span class="ays-poll-progress-banner-coupon-text">FREE2PRO20</span>';
                            $content[] = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" class="ays-poll-progress-banner-copy-icon">';
                                $content[] = '<path d="M13.5 2.5H6.5C5.67 2.5 5 3.17 5 4V10C5 10.83 5.67 11.5 6.5 11.5H13.5C14.33 11.5 15 10.83 15 10V4C15 3.17 14.33 2.5 13.5 2.5ZM13.5 10H6.5V4H13.5V10ZM2.5 6.5V12.5C2.5 13.33 3.17 14 4 14H10V12.5H4V6.5H2.5Z" fill="white"/>';
                            $content[] = '</svg>';
                        $content[] = '</div>';
                    $content[] = '</div>';
                    
                    $content[] = '<div class="ays-poll-progress-banner-progress">';
                        $content[] = '<p class="ays-poll-progress-banner-progress-text">' . __('Only', 'poll-maker') . ' <span id="remaining-licenses">' . $remaining_licenses . '</span> ' . __('of 50 discounted licenses left', 'poll-maker') . '</p>';
                        $content[] = '<div class="ays-poll-progress-banner-progress-bar">';
                            $content[] = '<div class="ays-poll-progress-banner-progress-fill" id="progress-fill" style="width: ' . $progress_percentage . '%;"></div>';
                        $content[] = '</div>';
                    $content[] = '</div>';
                $content[] = '</div>';
                
                $content[] = '<div class="ays-poll-progress-banner-right">';
                    $content[] = '<a href="'. $ays_poll_cta_button_link .'" class="ays-poll-progress-banner-upgrade" target="_blank">';
                    $content[] = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">';
                        $content[] = '<path d="M14.6392 6.956C14.5743 6.78222 14.4081 6.66667 14.2223 6.66667H8.85565L11.9512 0.648C12.0485 0.458667 11.9983 0.227111 11.8308 0.0955556C11.7499 0.0315556 11.6525 0 11.5556 0C11.4521 0 11.3485 0.0364444 11.2654 0.108L8.00009 2.928L1.48765 8.55244C1.3472 8.67378 1.29653 8.86978 1.36142 9.04356C1.42631 9.21733 1.59209 9.33333 1.77787 9.33333H7.14454L4.04898 15.352C3.95165 15.5413 4.00187 15.7729 4.16942 15.9044C4.25031 15.9684 4.34765 16 4.44453 16C4.54809 16 4.65165 15.9636 4.73476 15.892L8.00009 13.072L14.5125 7.44756C14.6534 7.32622 14.7036 7.13022 14.6392 6.956Z" fill="white"/>';
                    $content[] = '</svg>';
                     $content[] = ' ' . __('Upgrade Now', 'poll-maker');
                    $content[] = '</a>';
                $content[] = '</div>';
            $content[] = '</div>';
            
            if( current_user_can( 'manage_options' ) ){
            $content[] = '<div id="ays-poll-dismiss-buttons-content">';
                $content[] = '<form action="" method="POST" style="position: absolute; bottom: 0; right: 0; color: #fff;">';
                        $content[] = '<button class="btn btn-link ays-button" name="ays_poll_sale_btn" style="color: darkgrey; font-size: 11px;">'. __( "Dismiss ad", 'poll-maker' ) .'</button>';
                        $content[] = wp_nonce_field( POLL_MAKER_AYS_NAME . '-sale-banner' ,  POLL_MAKER_AYS_NAME . '-sale-banner' );
                $content[] = '</form>';
            $content[] = '</div>';
            }
        $content[] = '</div>';

        // Fox LMS Pro Banner Styles
        $content[] = '<style id="ays-poll-progress-banner-styles-inline-css">';
        $content[] = '
            .ays-poll-progress-banner-main {
                background: linear-gradient(135deg, #6344ED 0%, #8C2ABE 100%);
                padding: 20px 30px;
                border-radius: 16px;
                color: white;
                position: relative;
                margin: 20px 0;
                box-shadow: 0 8px 32px rgba(99, 68, 237, 0.3);
                border: 0;
            }

            .ays-poll-progress-banner-main .ays-poll-progress-banner-content {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 30px;
            }

            .ays-poll-progress-banner-main .ays-poll-progress-banner-left {
                display: flex;
                align-items: center;
                gap: 20px;
                flex: 1;
            }

            .ays-poll-progress-banner-main .ays-poll-progress-banner-center {
                display: flex;
                align-items: center;
                gap: 15px;
                flex: 1;
            }

            .ays-poll-progress-banner-main .ays-poll-progress-banner-right {
                display: flex;
                align-items: center;
                gap: 20px;
                flex-shrink: 0;
            }

            .ays-poll-progress-banner-main .ays-poll-progress-banner-icon {
                font-size: 32px;
                filter: drop-shadow(0 2px 8px rgba(0, 0, 0, 0.2));
            }

            .ays-poll-progress-banner-main .ays-poll-progress-banner-title {
                font-size: 21px;
                font-weight: 700;
                margin: 0 0 8px 0;
                line-height: 1.2;
                color: #fff;
            }

            .ays-poll-progress-banner-main .ays-poll-progress-banner-title a {
                text-decoration: underline;
                color: #fff;
            }

            .ays-poll-progress-banner-main .ays-poll-progress-banner-subtitle {
                font-size: 16px;
                margin: 0;
                opacity: 0.9;
                font-weight: 400;
            }

            .ays-poll-progress-banner-main .ays-poll-progress-banner-coupon {
                margin-bottom: 5px;
            }

            .ays-poll-progress-banner-main .ays-poll-progress-banner-coupon-box {
                border: 2px dotted rgba(255, 255, 255, 0.6);
                padding: 8px 16px;
                border-radius: 8px;
                background: rgba(255, 255, 255, 0.1);
                cursor: pointer;
                transition: all 0.3s ease;
                display: flex;
                align-items: center;
                gap: 8px;
                backdrop-filter: blur(10px);
            }

            .ays-poll-progress-banner-main .ays-poll-progress-banner-coupon-box:hover {
                background: rgba(255, 255, 255, 0.2);
                border-color: rgba(255, 255, 255, 0.8);
                transform: translateY(-1px);
            }

            .ays-poll-progress-banner-main .ays-poll-progress-banner-coupon-text {
                font-size: 16px;
                font-weight: 700;
                letter-spacing: 1px;
                color: #fff;
                font-family: monospace;
            }

            .ays-poll-progress-banner-main .ays-poll-progress-banner-copy-icon {
                opacity: 0.8;
                transition: opacity 0.3s ease;
            }

            .ays-poll-progress-banner-main .ays-poll-progress-banner-coupon-box:hover .ays-poll-progress-banner-copy-icon {
                opacity: 1;
            }

            .ays-poll-progress-banner-main .ays-poll-progress-banner-progress {
                text-align: center;
                width: 100%;
            }

            .ays-poll-progress-banner-main .ays-poll-progress-banner-progress-text {
                font-size: 14px;
                margin: 0 0 10px 0;
                opacity: 0.9;
            }

            .ays-poll-progress-banner-main .ays-poll-progress-banner-progress-bar {
                width: 300px;
                height: 10px;
                background: rgba(255, 255, 255, 0.2);
                border-radius: 4px;
                overflow: hidden;
                margin: 0 auto;
            }

            .ays-poll-progress-banner-main .ays-poll-progress-banner-progress-fill {
                height: 100%;
                background: linear-gradient(90deg, #4ADE80 0%, #22C55E 100%);
                border-radius: 4px;
                transition: width 0.8s ease;
                width: 70%;
            }

            .ays-poll-progress-banner-main .ays-poll-progress-banner-upgrade {
                background: linear-gradient(135deg, #F59E0B 0%, #F97316 100%);
                color: white;
                border: none;
                padding: 12px 24px;
                border-radius: 8px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                box-shadow: 0 4px 16px rgba(245, 158, 11, 0.4);
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 8px;
            }

            .ays-poll-progress-banner-main .ays-poll-progress-banner-upgrade:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(245, 158, 11, 0.6);
                text-decoration: none;
                color: white;
            }

            .ays-poll-progress-banner-main .notice-dismiss:before {
                color: #fff;
            }

            /* Copy notification */
            .ays-poll-copy-notification {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: rgba(0, 0, 0, 0.8);
                color: white;
                padding: 12px 24px;
                border-radius: 8px;
                font-size: 14px;
                z-index: 10000;
                opacity: 0;
                transition: opacity 0.3s ease;
            }

            .ays-poll-copy-notification.show {
                opacity: 1;
            }

            @media (max-width: 1400px) {
                .ays-poll-progress-banner-main .ays-poll-progress-banner-center {
                    flex-direction: column;
                }
            }

            @media (max-width: 1200px) {
                .ays-poll-progress-banner-main .ays-poll-progress-banner-content {
                    flex-direction: column;
                    gap: 20px;
                }

                .ays-poll-progress-banner-main .ays-poll-progress-banner-left {
                    width: 100%;
                    justify-content: center;
                    text-align: center;
                    flex-direction: column;
                }

                .ays-poll-progress-banner-main .ays-poll-progress-banner-center {
                    width: 100%;
                }

                .ays-poll-progress-banner-main .ays-poll-progress-banner-right {
                    width: 100%;
                    justify-content: center;
                }
            }

            @media (max-width: 768px) {
                #ays-poll-progress-banner-main {
                    display: none !important;
                }

                .ays-poll-progress-banner-main {
                    padding: 15px 20px;
                    margin: 15px 0;
                }
                
                .ays-poll-progress-banner-main .ays-poll-progress-banner-title {
                    font-size: 18px;
                }
                
                .ays-poll-progress-banner-main .ays-poll-progress-banner-subtitle {
                    font-size: 14px;
                }
                
                .ays-poll-progress-banner-main .ays-poll-progress-banner-progress-bar {
                    width: 100%;
                    max-width: 280px;
                }
                
                .ays-poll-progress-banner-main .ays-poll-progress-banner-upgrade {
                    padding: 10px 20px;
                    font-size: 14px;
                }
            }

            @media (max-width: 480px) {
                .ays-poll-progress-banner-main {
                    padding: 12px 15px;
                }
                
                .ays-poll-progress-banner-main .ays-poll-progress-banner-coupon-text {
                    font-size: 14px;
                }
                
                .ays-poll-progress-banner-main .ays-poll-progress-banner-progress-bar {
                    max-width: 250px;
                }
            }
        ';

        $content[] = '</style>';

        $content = implode( '', $content );
        echo ($content);
        
    }

	public function ays_poll_sale_message_poll_pro(){
		$content = array();

		$poll_cta_button_link = esc_url( 'https://ays-pro.com/mega-bundle?utm_source=dashboard&utm_medium=poll-free&utm_campaign=mega-bundle-sale-banner-' . POLL_MAKER_AYS_VERSION );

		$content[] = '<div id="ays-poll-dicount-month-main" class="ays-poll-admin-notice notice notice-success is-dismissible ays_poll_dicount_info">';
			$content[] = '<div id="ays-poll-dicount-month" class="ays_poll_dicount_month">';

				$content[] = '<div class="ays-poll-dicount-wrap-box ays-poll-dicount-wrap-text-box">';
					$content[] = '<div>';
						$content[] = '<span class="ays-poll-new-poll-pro-title">';

							$content[] = __( "<span><a href='". $poll_cta_button_link ."' target='_blank' style='color:#ffffff; text-decoration: underline;'>Mega Bundle</a> (Quiz + Survey + Poll)</span>", 'poll-maker' );
							
						$content[] = '</span>';
						$content[] = '</br>';
						$content[] = '<div class="ays-poll-new-poll-pro-mobile-image-display-block display_none">';
							$content[] = '<span class="ays-poll-sale-baner-mega-bundle-sale-text">50%</span>';
							$content[] = '<img src="' . esc_url(POLL_MAKER_AYS_ADMIN_URL) . '/images/line.webp" style="width: 70px;">';
						$content[] = '</div>';
						$content[] = '<span class="ays-poll-new-poll-pro-desc">';
							$content[] = '<img class="ays-poll-new-poll-pro-guaranteeicon" src="' . esc_url(POLL_MAKER_AYS_ADMIN_URL) . '/images/poll-maker-guaranteeicon.svg" style="width:30px;margin-right:5px">';
								$content[] =esc_html__( "30 Days Money Back Guarantee", "poll-maker" );
						$content[] = '</span>';
					$content[] = '</div>';
					$content[] = '<div style="display:flex;flex-wrap:wrap;width:min-content;">';
						$content[] = '<span class="ays-poll-sale-baner-mega-bundle-sale-text">50%</span>';
						$content[] = '<img src="' . esc_url(POLL_MAKER_AYS_ADMIN_URL) . '/images/line.webp" class="ays-poll-new-mega-bundle-mobile-image-display-none" style="width: 70px;">';
					$content[] = '</div>';
					$content[] = '<div style="position: absolute;right: 10px;bottom: 1px;" class="ays-poll-dismiss-buttons-container-for-form">';

						$content[] = '<form action="" method="POST">';
							$content[] = '<div id="ays-poll-dismiss-buttons-content">';
								if( current_user_can( 'manage_options' ) ){
									$content[] = '<button class="btn btn-link ays-button" name="ays_poll_sale_btn" style="height: 32px; margin-left: 0;padding-left: 0">Dismiss ad</button>';
									$content[] = wp_nonce_field( POLL_MAKER_AYS_NAME . '-sale-banner' ,  POLL_MAKER_AYS_NAME . '-sale-banner' );
								}
							$content[] = '</div>';
						$content[] = '</form>';
						
					$content[] = '</div>';
						
				$content[] = '</div>';

				$content[] = '<div class="ays-poll-dicount-wrap-box ays-poll-dicount-wrap-countdown-box">';

					$content[] = '<div id="ays-poll-maker-countdown-main-container">';
						$content[] = '<div class="ays-poll-maker-countdown-container">';

							$content[] = '<div id="ays-poll-countdown">';

									$content[] = '<ul>';
                                        $content[] = '<li><span id="ays-poll-countdown-days"></span>days</li>';
                                        $content[] = '<li><span id="ays-poll-countdown-hours"></span>Hours</li>';
                                        $content[] = '<li><span id="ays-poll-countdown-minutes"></span>Minutes</li>';
                                        $content[] = '<li><span id="ays-poll-countdown-seconds"></span>Seconds</li>';
                                    $content[] = '</ul>';

							$content[] = '</div>';

							$content[] = '<div id="ays-poll-countdown-content" class="emoji">';
								$content[] = '<span>🚀</span>';
								$content[] = '<span>⌛</span>';
								$content[] = '<span>🔥</span>';
								$content[] = '<span>💣</span>';
							$content[] = '</div>';

						$content[] = '</div>';
					$content[] = '</div>';

				$content[] = '</div>';

				$content[] = '<div class="ays-poll-dicount-wrap-box ays-poll-dicount-wrap-button-box">';

					$content[] = '<a href="'. $poll_cta_button_link .'" class="button button-primary ays-button" id="ays-button-top-buy-now" target="_blank">' . __( 'Buy Now', 'poll-maker' ) . '</a>';

					$content[] = '<span class="ays-poll-dicount-one-time-text">';
						$content[] =esc_html__( "One-time payment", "poll-maker" );
					$content[] = '</span>';
				$content[] = '</div>';
			$content[] = '</div>';
		$content[] = '</div>';
		$background_image = POLL_MAKER_AYS_ADMIN_URL . '/images/ays-poll-banner-background-50.svg';
		$close_banner_image = POLL_MAKER_AYS_ADMIN_URL . '/images/icons/ays-poll-close-banner-white.svg';

		$content[] = '<style id="ays_poll_sale_message_poll_pro-inline-css">';
		    $content[] = 'div#ays-poll-dicount-month-main{border:0;background:#fff;border-radius:20px;box-shadow:unset;position:relative;z-index:1;min-height:80px}.ays-poll-dicount-sale-name-discount-box,div#ays-poll-dicount-month-main.ays_poll_dicount_info button{display:flex;align-items:center}div#ays-poll-dicount-month-main div#ays-poll-dicount-month a.ays-poll-sale-banner-link:focus{outline:0;box-shadow:0}div#ays-poll-dicount-month-main .btn-link{color:#007bff;background-color:transparent;display:inline-block;font-weight:400;text-align:center;white-space:nowrap;vertical-align:middle;-webkit-user-select:none;-moz-user-select:none;-ms-user-select:none;user-select:none;border:1px solid transparent;padding:.375rem .75rem;font-size:1rem;line-height:1.5;border-radius:.25rem}div#ays-poll-dicount-month-main.ays_poll_dicount_info{background-image:url("'. $background_image . '");background-position:center right;background-repeat:no-repeat;background-color:#5551ff; background-size:cover}#ays-poll-dicount-month-main .ays_poll_dicount_month{display:flex;align-items:center;justify-content:space-between;color:#fff}#ays-poll-dicount-month-main .ays_poll_dicount_month img{width:80px}#ays-poll-dicount-month-main .ays-poll-sale-banner-link{display:flex;justify-content:center;align-items:center;width:200px}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box{font-size:14px;text-align:center;padding:12px}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-text-box{text-align:left;width:25%;display:flex;justify-content:space-around;align-items:flex-start}.ays-poll-dicount-sale-name-discount-box div{margin-left:10px}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-countdown-box{width:40%;display:flex;justify-content:center;align-items:center}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-button-box{width:20%;display:flex;justify-content:center;align-items:center;flex-direction:column}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-text-box .ays-poll-new-poll-pro-title{color:#fdfdfd;font-size:16.8px;font-style:normal;font-weight:600;line-height:normal}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-text-box .ays-poll-sale-baner-mega-bundle-sale-text{font-size:23px;font-weight:700;padding-left:5px;text-shadow:2px 1.3px 0 #f66123;-webkit-text-stroke-width:1px;-webkit-text-stroke-color:#4944FF;-moz-text-stroke-width:1px;-moz-text-stroke-color:#4944FF}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-text-box .ays-poll-new-poll-pro-desc{display:inline-block;color:#fff;font-size:15px;font-style:normal;font-weight:400;line-height:normal;margin-top:10px}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box strong{font-size:17px;font-weight:700;letter-spacing:.8px}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-color{color:#971821}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-text-decoration{text-decoration:underline}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box.ays-buy-now-button-box{display:flex;justify-content:flex-end;align-items:center;width:30%}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box .ays-button,#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box .ays-buy-now-button{align-items:center;font-weight:500}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box .ays-buy-now-button{background:#971821;border-color:#fff;display:flex;justify-content:center;align-items:center;padding:5px 15px;font-size:16px;border-radius:5px}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box .ays-buy-now-button:hover{background:#7d161d;border-color:#971821}#ays-poll-dicount-month-main #ays-poll-dismiss-buttons-content{display:flex;justify-content:center}#ays-poll-dicount-month-main #ays-poll-dismiss-buttons-content .ays-button{margin:0!important;font-size:13px;color:#fff}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-opacity-box{width:19%}#ays-poll-dicount-month-main .ays-buy-now-opacity-button{padding:40px 15px;display:flex;justify-content:center;align-items:center;opacity:0}#ays-poll-maker-countdown-main-container .ays-poll-maker-countdown-container{margin:0 auto;text-align:center}#ays-poll-maker-countdown-main-container #ays-poll-countdown-headline{letter-spacing:.125rem;text-transform:uppercase;font-size:18px;font-weight:400;margin:0;padding:9px 0 4px;line-height:1.3}#ays-poll-maker-countdown-main-container li,#ays-poll-maker-countdown-main-container ul{margin:0}#ays-poll-maker-countdown-main-container li{display:inline-block;font-size:14px;list-style-type:none;padding:14px;text-transform:lowercase}#ays-poll-maker-countdown-main-container li span{display:flex;justify-content:center;align-items:center;font-size:40px;min-height:62px;min-width:62px;border-radius:4.273px;border:.534px solid #f4f4f4;background:#9896ed}#ays-poll-maker-countdown-main-container .emoji{display:none;padding:1rem}#ays-poll-maker-countdown-main-container .emoji span{font-size:30px;padding:0 .5rem}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box li{position:relative}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box li span:after{content:":";color:#fff;position:absolute;top:10px;right:-5px;font-size:40px}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box li span#ays-poll-countdown-seconds:after{content:unset}#ays-poll-dicount-month-main #ays-button-top-buy-now{display:flex;align-items:center;border-radius:6.409px;background:#f66123;padding:12px 32px;color:#fff;font-size:12.818px;font-style:normal;font-weight:800;line-height:normal;margin:0!important}div#ays-poll-dicount-month-main button.notice-dismiss:before{color:#fff;content:"";background-image:url("'.$close_banner_image.'");font-size:22px;font-weight:700;font-family:sans-serif}#ays-poll-dicount-month-main .ays-poll-new-mega-bundle-guaranteeicon{width:30px;margin-right:5px}#ays-poll-dicount-month-main .ays-poll-dicount-one-time-text{color:#fff;font-size:12px;font-style:normal;font-weight:600;line-height:normal}@media all and (max-width:1024px){#ays-poll-dicount-month-main{display:none!important}}@media all and (max-width:768px){div#ays-poll-dicount-month-main{padding-right:0}div#ays-poll-dicount-month-main .ays_poll_dicount_month{display:flex;align-items:center;justify-content:space-between;align-content:center;flex-wrap:wrap;flex-direction:column;padding:10px 0}div#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box{width:100%!important;text-align:center}#ays-poll-maker-countdown-main-container #ays-poll-countdown-headline{font-size:15px;font-weight:600}#ays-poll-maker-countdown-main-container ul{font-weight:500}div#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-text-box{width:100%!important;text-align:center;flex-direction:column;margin-top:20px;justify-content:center;align-items:center}.ays-poll-dicount-sale-name-discount-box{display:block}.ays-poll-dicount-sale-name-discount-box div{margin-left:0}div#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box li span:after{top:unset}div#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-countdown-box{width:100%}#ays-poll-dicount-month-main .ays-button{margin:0 auto!important}div#ays-poll-dicount-month-main.ays_poll_dicount_info.notice{background-position:bottom right;background-repeat:no-repeat;background-size:cover}#ays-poll-dicount-month-main #ays-poll-dismiss-buttons-content .ays-button{padding-left:unset!important}div#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box.ays-buy-now-button-box{justify-content:center}div#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box .ays-buy-now-button{font-size:14px;padding:5px 10px}div#ays-poll-dicount-month-main .ays-buy-now-opacity-button{display:none}#ays-poll-dicount-month-main .ays-poll-dismiss-buttons-container-for-form{position:static!important}.comparison .product img{width:70px}.ays-poll-features-wrap .comparison a.price-buy{padding:8px 5px;font-size:11px}}@media screen and (max-width:1305px) and (min-width:768px){div#ays-poll-dicount-month-main.ays_poll_dicount_info.notice{background-position:bottom right;background-repeat:no-repeat;background-size:cover}div#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box strong{font-size:15px}#ays-poll-maker-countdown-main-container li{font-size:11px}div#ays-poll-dicount-month-main .ays-poll-dicount-wrap-opacity-box{display:none}}@media screen and (max-width:1680px) and (min-width:1551px){div#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-text-box{width:29%}div#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-countdown-box{width:30%}}@media screen and (max-width:1550px) and (min-width:1400px){div#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-text-box{width:31%}div#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-countdown-box{width:35%}}@media screen and (max-width:1274px){div#ays-poll-maker-countdown-main-container li span{font-size:25px;min-height:40px;min-width:40px}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-text-box .ays-poll-new-poll-pro-title{font-size:15px}}@media screen and (max-width:1200px){#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-button-box{margin-bottom:16px}#ays-poll-maker-countdown-main-container ul{padding-left:0}#ays-poll-dicount-month-main .ays-poll-coupon-row{width:120px;font-size:18px}#ays-poll-dicount-month-main #ays-button-top-buy-now{padding:12px 20px}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box{font-size:12px}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-text-box .ays-poll-new-mega-bundle-desc{font-size:13px}}@media screen and (max-width:1076px) and (min-width:769px){#ays-poll-maker-countdown-main-container li{padding:10px}#ays-poll-dicount-month-main .ays-poll-coupon-row{width:100px;font-size:16px}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-button-box{margin-bottom:16px}#ays-poll-dicount-month-main #ays-button-top-buy-now{padding:12px 15px}#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box{font-size:11px;padding:12px 0}}@media screen and (max-width:1250px) and (min-width:769px){div#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-countdown-box{width:45%}div#ays-poll-dicount-month-main .ays-poll-dicount-wrap-box.ays-poll-dicount-wrap-text-box{width:35%}div#ays-poll-maker-countdown-main-container li span{font-size:30px;min-height:50px;min-width:50px}}';
		$content[] = '</style>';

		$content = implode( '', $content );
		echo ( $content );
    }

	public function add_tabs() {
		$screen = get_current_screen();
	
		if ( ! $screen) {
			return;
		}
        
        $title   = esc_html__( 'General Information:', "poll-maker");
        $content_text = '<div>
							<span>'. esc_html__( "The WordPress Poll Maker plugin is here to help you quickly create advanced-level online polls and make your WordPress website more interactive. Use it to conduct elections, surveys and etc. Easily generate poll types like;", "poll-maker") .'</span>
						</div>
						<br>
        				<div>
							<span><strong>'. esc_html__( "Choosing", "poll-maker") .'</strong> – '. esc_html__( "create many options and let your users choose, or add their custom answers.", "poll-maker") .'</span>
						</div>
        				<div>
        					<span><strong>'. esc_html__( "Rating", "poll-maker") .'</strong> – '. esc_html__( "with this poll type, the visitors will be able to weigh via a 1-5 star rating system or emojis via the graphical interface.", "poll-maker") .'</span>
						</div>
        				<div>
        					<span><strong>'. esc_html__( "Voting", "poll-maker") .'</strong> – '. esc_html__( "make the participants evaluate your product by using like/dislike buttons or smiley/frown emojis.", "poll-maker") .'</span>
						</div>
        				<div>
        					<span><strong>'. esc_html__( "Versus", "poll-maker") .'</strong> – '. esc_html__( "Select two statements or images that are opposed to each other, and make your users choose the perfect one.", "poll-maker") .'</span>
						</div>
        				<div>
        					<span><strong>'. esc_html__( "Range", "poll-maker") .'</strong> – '. esc_html__( "the users will be able to choose the answer across the 0-100 scale.", "poll-maker") .'</span>
						</div>
        				<div>
        					<span><strong>'. esc_html__( "Text", "poll-maker") .'</strong> – '. esc_html__( "with this poll type the visitors should write down their own answers on the text boundaries.", "poll-maker") .'</span>
						</div>
        				<div>
        					<span><strong>'. esc_html__( "Dropdown", "poll-maker") .'</strong> – '. esc_html__( "the users will choose the multiple-choice answers from a list of answers appeared in a dropdown form.", "poll-maker") .'</span>
						</div>
						<br>
        				<div>
        					<span>'. esc_html__( "Increase engagement of your website with the integrated,  formatting, image, audio, video poll question types feature.", "poll-maker") .'</span>
						</div>';

        $sidebar_content = '<p><strong>'. esc_html__( 'For more information:', "poll-maker") .'</strong></p>' .
                            '<p>
                                <a href="https://www.youtube.com/watch?v=RDKZXFmG6Pc" target="_blank">' .esc_html__( 'YouTube video tutorials' , "poll-maker" ) . '</a>
                            </p>' .
                            '<p>
                                <a href="https://ays-pro.com/wordpress-poll-maker-user-manual" target="_blank">' .esc_html__( 'Documentation', "poll-maker" ) . '</a>
                            </p>' .
                            '<p>
                                <a href="https://ays-pro.com/wordpress/poll-maker" target="_blank">' .esc_html__( 'Poll Maker plugin pro version', "poll-maker" ) . '</a>
                            </p>' .
                            '<p>
                                <a href="https://poll-plugin.com/wordpress-poll-plugin-pro-demo/" target="_blank">' .esc_html__( 'Poll Maker plugin demo', "poll-maker" ) . '</a>
                            </p>';


        $content =  '<h2>' .esc_html__( 'Poll Maker Information', "poll-maker") . '</h2>';
		$content .= '<div>' . $content_text . '</div>';

        $help_tab_content = array(
            'id'      => 'poll_maker_help_tab',
            'title'   => $title,
            'content' => $content
        );
        
		$screen->add_help_tab($help_tab_content);

		$screen->set_help_sidebar($sidebar_content);
	}	   

	/**
     * Recursive sanitation for an array
     * 
     * @param $array
     *
     * @return mixed
     */
    public static function recursive_sanitize_text_field($array) {
        foreach ( $array as $key => &$value ) {
            if ( is_array( $value ) ) {
                $value = self::recursive_sanitize_text_field($value);
            } else {
                $value = sanitize_text_field( $value );
            }
		}
		return $array;
    }

    public static function get_max_id( $table ) {
        global $wpdb;
        $db_table = $wpdb->prefix . 'ayspoll_'.$table;;

        $sql = "SELECT MAX(id) FROM {$db_table}";

        $result = intval( $wpdb->get_var( $sql ) );

        return $result;
    }

    public function ays_poll_generate_message_vars_html( $poll_message_vars ) {
        $content = array();

        $content[] = '<div class="ays-poll-message-vars-box">';
            $content[] = '<div class="ays-poll-message-vars-icon">';
                $content[] = '<span class="ays-poll-message-vars-braces" aria-hidden="true">{ }</span>';
                $content[] = '<span>'. esc_html__( "Message Variables" , 'poll-maker') .'</span>';
                $content[] = '<span class="ays-poll-message-vars-chevron" aria-hidden="true"></span>';
            $content[] = '</div>';
            $content[] = '<div class="ays-poll-message-vars-data">';
                foreach($poll_message_vars as $var => $var_name){
                    $content[] = '<label class="ays-poll-message-vars-each-data-label">';                       
                        $content[] = '<div class="ays-poll-message-vars-each-data">';
                            $content[] = '<input type="hidden" class="ays-poll-message-vars-each-var" value="'. esc_attr( $var ) .'">';
                            $content[] = '<span>'. esc_html( $var_name ) .'</span>';
                        $content[] = '</div>';
                    $content[] = '</label>';
                }
            $content[] = '</div>';
        $content[] = '</div>';

        $content = implode( '', $content );

        return $content;
    }

    /**
     * Determine if the plugin/addon installations are allowed.
     *
     * @since 1.3.9
     *
     * @param string $type Should be `plugin` or `addon`.
     *
     * @return bool
     */
    public static function ays_poll_can_install( $type ) {

        return self::ays_poll_can_do( 'install', $type );
    }

    /**
     * Determine if the plugin/addon activations are allowed.
     *
     * @since 1.3.9
     *
     * @param string $type Should be `plugin` or `addon`.
     *
     * @return bool
     */
    public static function ays_poll_can_activate( $type ) {

        return self::ays_poll_can_do( 'activate', $type );
    }

    /**
     * Determine if the plugin/addon installations/activations are allowed.
     *
     * @since 1.3.9
     *
     * @param string $what Should be 'activate' or 'install'.
     * @param string $type Should be `plugin` or `addon`.
     *
     * @return bool
     */
    public static function ays_poll_can_do( $what, $type ) {

        if ( ! in_array( $what, array( 'install', 'activate' ), true ) ) {
            return false;
        }

        if ( ! in_array( $type, array( 'plugin', 'addon' ), true ) ) {
            return false;
        }

        $capability = $what . '_plugins';

        if ( ! current_user_can( $capability ) ) {
            return false;
        }

        // Determine whether file modifications are allowed and it is activation permissions checking.
        if ( $what === 'install' && ! wp_is_file_mod_allowed( 'ays_poll_can_install' ) ) {
            return false;
        }

        // All plugin checks are done.
        if ( $type === 'plugin' ) {
            return true;
        }
        return false;
    }

    /**
     * Activate plugin.
     *
     * @since 1.0.0
     * @since 1.3.9 Updated the permissions checking.
     */
    public function ays_poll_activate_plugin() {

        // Run a security check.
        check_ajax_referer( $this->plugin_name . '-install-plugin-nonce', sanitize_key( $_REQUEST['_ajax_nonce'] ) );

        // Check for permissions.
        if ( ! current_user_can( 'activate_plugins' ) ) {
            wp_send_json_error( esc_html__( 'Plugin activation is disabled for you on this site.', $this->plugin_name ) );
        }

        $type = 'addon';

        if ( isset( $_POST['plugin'] ) ) {

            if ( ! empty( $_POST['type'] ) ) {
                $type = sanitize_key( $_POST['type'] );
            }

            $plugin   = sanitize_text_field( wp_unslash( $_POST['plugin'] ) );
            $activate = activate_plugins( $plugin );

            if ( ! is_wp_error( $activate ) ) {
                if ( $type === 'plugin' ) {
                    wp_send_json_success( esc_html__( 'Plugin activated.', $this->plugin_name ) );
                } else {
                        ( esc_html__( 'Addon activated.', $this->plugin_name ) );
                }
            }
        }

        if ( $type === 'plugin' ) {
            wp_send_json_error( esc_html__( 'Could not activate the plugin. Please activate it on the Plugins page.', $this->plugin_name ) );
        }

        wp_send_json_error( esc_html__( 'Could not activate the addon. Please activate it on the Plugins page.', $this->plugin_name ) );
    }

    /**
     * Install addon.
     *
     * @since 1.0.0
     * @since 1.3.9 Updated the permissions checking.
     */
    public function ays_poll_install_plugin() {

        // Run a security check.
        check_ajax_referer( $this->plugin_name . '-install-plugin-nonce', sanitize_key( $_REQUEST['_ajax_nonce'] ) );

        $generic_error = esc_html__( 'There was an error while performing your request.', $this->plugin_name );
        $type          = ! empty( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';

        // Check if new installations are allowed.
        if ( ! self::ays_poll_can_install( $type ) ) {
            wp_send_json_error( $generic_error );
        }

        $error = $type === 'plugin'
            ? esc_html__( 'Could not install the plugin. Please download and install it manually.', $this->plugin_name )
            : "";

        $plugin_url = ! empty( $_POST['plugin'] ) ? esc_url_raw( wp_unslash( $_POST['plugin'] ) ) : '';

        if ( empty( $plugin_url ) ) {
            wp_send_json_error( $error );
        }

        // Prepare variables.
        $url = esc_url_raw(
            add_query_arg(
                [
                    'page' => 'poll-maker-ays-featured-plugins',
                ],
                admin_url( 'admin.php' )
            )
        );

        ob_start();
        $creds = request_filesystem_credentials( $url, '', false, false, null );

        // Hide the filesystem credentials form.
        ob_end_clean();

        // Check for file system permissions.
        if ( $creds === false ) {
            wp_send_json_error( $error );
        }
        
        if ( ! WP_Filesystem( $creds ) ) {
            wp_send_json_error( $error );
        }

        /*
         * We do not need any extra credentials if we have gotten this far, so let's install the plugin.
         */
        require_once POLL_MAKER_AYS_DIR . 'includes/admin/class-poll-maker-upgrader.php';
        require_once POLL_MAKER_AYS_DIR . 'includes/admin/class-poll-maker-install-skin.php';
        require_once POLL_MAKER_AYS_DIR . 'includes/admin/class-poll-maker-skin.php';


        // Do not allow WordPress to search/download translations, as this will break JS output.
        remove_action( 'upgrader_process_complete', array( 'Language_Pack_Upgrader', 'async_upgrade' ), 20 );

        // Create the plugin upgrader with our custom skin.
        $installer = new PollMaker\Helpers\PollMakerPluginSilentUpgrader( new Poll_Maker_Install_Skin() );

        // Error check.
        if ( ! method_exists( $installer, 'install' ) ) {
            wp_send_json_error( $error );
        }

        $installer->install( $plugin_url );

        // Flush the cache and return the newly installed plugin basename.
        wp_cache_flush();

        $plugin_basename = $installer->plugin_info();

        if ( empty( $plugin_basename ) ) {
            wp_send_json_error( $error );
        }

        $result = array(
            'msg'          => $generic_error,
            'is_activated' => false,
            'basename'     => $plugin_basename,
        );

        // Check for permissions.
        if ( ! current_user_can( 'activate_plugins' ) ) {
            $result['msg'] = $type === 'plugin' ? esc_html__( 'Plugin installed.', $this->plugin_name ) : "";

            wp_send_json_success( $result );
        }

        // Activate the plugin silently.
        $activated = activate_plugin( $plugin_basename );
        remove_action( 'activated_plugin', array( 'gallery_p_gallery_activation_redirect_method', 'ays_sccp_activation_redirect_method' ), 100 );

        if ( ! is_wp_error( $activated ) ) {

            $result['is_activated'] = true;
            $result['msg']          = $type === 'plugin' ? esc_html__( 'Plugin installed and activated.', $this->plugin_name ) : esc_html__( 'Addon installed and activated.', $this->plugin_name );

            wp_send_json_success( $result );
        }

        // Fallback error just in case.
        wp_send_json_error( $result );
    }

    /**
     * List of AM plugins that we propose to install.
     *
     * @since 1.3.9
     *
     * @return array
     */
    protected function poll_get_am_plugins() {
        if ( !isset( $_SESSION ) ) {
            session_start();
        }

        $images_url = POLL_MAKER_AYS_ADMIN_URL . '/images/icons/';

        $plugin_slug = array(
        	'fox-lms',
            'quiz-maker',
            'survey-maker',            
            'ays-popup-box',
            'gallery-photo-gallery',
            'secure-copy-content-protection',
            'personal-dictionary',
            'chart-builder',
            'easy-form',
        );

        $plugin_url_arr = array();
        foreach ($plugin_slug as $key => $slug) {
            if ( isset( $_SESSION['ays_poll_our_product_links'] ) && !empty( $_SESSION['ays_poll_our_product_links'] ) 
                && isset( $_SESSION['ays_poll_our_product_links'][$slug] ) && !empty( $_SESSION['ays_poll_our_product_links'][$slug] ) ) {
                $plugin_url = (isset( $_SESSION['ays_poll_our_product_links'][$slug] ) && $_SESSION['ays_poll_our_product_links'][$slug] != "") ? esc_url( $_SESSION['ays_poll_our_product_links'][$slug] ) : "";
            } else {
                $latest_version = $this->ays_poll_get_latest_plugin_version($slug);
                $plugin_url = 'https://downloads.wordpress.org/plugin/'. $slug .'.zip';
                if ( $latest_version != '' ) {
                    $plugin_url = 'https://downloads.wordpress.org/plugin/'. $slug .'.'. $latest_version .'.zip';
                    $_SESSION['ays_poll_our_product_links'][$slug] = $plugin_url;
                }
            }

            $plugin_url_arr[$slug] = $plugin_url;
        }

        $plugins_array = array(
        	'fox-lms/fox-lms.php'        => array(
                'icon'        => $images_url . 'icon-fox-lms-128x128.png',
                'name'        => __( 'Fox LMS', 'poll-maker' ),
                'desc'        => __( 'Build and manage online courses directly on your WordPress site.', 'poll-maker' ),
                'desc_hidden' => __( 'With the FoxLMS plugin, you can create, sell, and organize courses, lessons, and quizzes, transforming your website into a dynamic e-learning platform.', 'poll-maker' ),
                'wporg'       => 'https://wordpress.org/plugins/fox-lms/',
                'buy_now'     => 'https://foxlms.com/pricing/?utm_source=dashboard&utm_medium=poll-free&utm_campaign=fox-lms-our-products-page',
                'url'         => $plugin_url_arr['fox-lms'],
            ),
           	'quiz-maker/quiz-maker.php'        => array(
                'icon'        => $images_url . 'icon-quiz-128x128.png',
                'name'        => __( 'Quiz Maker', 'poll-maker' ),
                'desc'        => __( 'With our Quiz Maker plugin it’s easy to make a quiz in a short time.', 'poll-maker' ),
                'desc_hidden' => __( 'You to add images to your quiz, order unlimited questions. Also you can style your quiz to satisfy your visitors.', 'poll-maker' ),
                'wporg'       => 'https://wordpress.org/plugins/quiz-maker/',
                'buy_now'     => 'https://ays-pro.com/wordpress/quiz-maker/',
                'url'         => $plugin_url_arr['quiz-maker'],
            ),
            'survey-maker/survey-maker.php'        => array(
                'icon'        => $images_url . 'icon-survey-128x128.png',
                'name'        => __( 'Survey Maker', 'poll-maker' ),
                'desc'        => __( 'Make amazing online surveys and get real-time feedback quickly and easily.', 'poll-maker' ),
                'desc_hidden' => __( 'Learn what your website visitors want, need, and expect with the help of Survey Maker. Build surveys without limiting your needs.', 'poll-maker' ),
                'wporg'       => 'https://wordpress.org/plugins/survey-maker/',
                'buy_now'     => 'https://ays-pro.com/wordpress/survey-maker',
                'url'         => $plugin_url_arr['survey-maker'],
            ),            
            'ays-popup-box/ays-pb.php'        => array(
                'icon'        => $images_url . 'icon-popup-128x128.png',
                'name'        => __( 'Popup Box', 'poll-maker' ),
                'desc'        => __( 'Popup everything you want! Create informative and promotional popups all in one plugin.', 'poll-maker' ),
                'desc_hidden' => __( 'Attract your visitors and convert them into email subscribers and paying customers.', 'poll-maker' ),
                'wporg'       => 'https://wordpress.org/plugins/ays-popup-box/',
                'buy_now'     => 'https://ays-pro.com/wordpress/popup-box/',
                'url'         => $plugin_url_arr['ays-popup-box'],
            ),
            'gallery-photo-gallery/gallery-photo-gallery.php'        => array(
                'icon'        => $images_url . 'icon-gallery-128x128.png',
                'name'        => __( 'Gallery Photo Gallery', 'poll-maker' ),
                'desc'        => __( 'Create unlimited galleries and include unlimited images in those galleries.', 'poll-maker' ),
                'desc_hidden' => __( 'Represent images in an attractive way. Attract people with your own single and multiple free galleries from your photo library.', 'poll-maker' ),
                'wporg'       => 'https://wordpress.org/plugins/gallery-photo-gallery/',
                'buy_now'     => 'https://ays-pro.com/wordpress/photo-gallery/',
                'url'         => $plugin_url_arr['gallery-photo-gallery'],
            ),
            'secure-copy-content-protection/secure-copy-content-protection.php'        => array(
                'icon'        => $images_url . 'icon-sccp-128x128.png',
                'name'        => __( 'Secure Copy Content Protection', 'poll-maker' ),
                'desc'        => __( 'Disable the right click, copy paste, content selection and copy shortcut keys on your website.', 'poll-maker' ),
                'desc_hidden' => __( 'Protect web content from being plagiarized. Prevent plagiarism from your website with this easy to use plugin.', 'poll-maker' ),
                'wporg'       => 'https://wordpress.org/plugins/secure-copy-content-protection/',
                'buy_now'     => 'https://ays-pro.com/wordpress/secure-copy-content-protection/',
                'url'         => $plugin_url_arr['secure-copy-content-protection'],
            ),
            'personal-dictionary/personal-dictionary.php'        => array(
                'icon'        => $images_url . 'pd-logo-128x128.png',
                'name'        => __( 'Personal Dictionary', 'poll-maker' ),
                'desc'        => __( 'Allow your students to create personal dictionary, study and memorize the words.', 'poll-maker' ),
                'desc_hidden' => __( 'Allow your users to create their own digital dictionaries and learn new words and terms as fastest as possible.', 'poll-maker' ),
                'wporg'       => 'https://wordpress.org/plugins/personal-dictionary/',
                'buy_now'     => 'https://ays-pro.com/wordpress/personal-dictionary/',
                'url'         => $plugin_url_arr['personal-dictionary'],
            ),
            'chart-builder/chart-builder.php'        => array(
                'icon'        => $images_url . 'chartify-150x150.png',
                'name'        => __( 'Chart Builder', 'poll-maker' ),
                'desc'        => __( 'Chart Builder plugin allows you to create beautiful charts', 'poll-maker' ),
                'desc_hidden' => __( ' and graphs easily and quickly.', 'poll-maker' ),
                'wporg'       => 'https://wordpress.org/plugins/chart-builder/',
                'buy_now'     => 'https://ays-pro.com/wordpress/chart-builder/',
                'url'         => $plugin_url_arr['chart-builder'],
            ),
            'easy-form/easy-form.php'        => array(
                'icon'        => $images_url . 'easyform-150x150.png',
                'name'        => __( 'Easy Form', 'poll-maker' ),
                'desc'        => __( 'Choose the best WordPress form builder plugin. ', 'poll-maker' ),
                'desc_hidden' => __( 'Create contact forms, payment forms, surveys, and many more custom forms. Build forms easily with us.', 'poll-maker' ),
                'wporg'       => 'https://wordpress.org/plugins/easy-form/',
                'buy_now'     => 'https://ays-pro.com/wordpress/easy-form',
                'url'         => $plugin_url_arr['easy-form'],
            ),
        );

        return $plugins_array;
    }

    protected function ays_poll_get_latest_plugin_version( $slug ){

        if ( is_null( $slug ) || empty($slug) ) {
            return "";
        }

        $version_latest = "";

        if ( ! function_exists( 'plugins_api' ) ) {
              require_once( ABSPATH . 'wp-admin/includes/plugin-install.php' );
        }

        // set the arguments to get latest info from repository via API ##
        $args = array(
            'slug' => $slug,
            'fields' => array(
                'version' => true,
            )
        );

        /** Prepare our query */
        $call_api = plugins_api( 'plugin_information', $args );

        /** Check for Errors & Display the results */
        if ( is_wp_error( $call_api ) ) {
            $api_error = $call_api->get_error_message();
        } else {

            //echo $call_api; // everything ##
            if ( ! empty( $call_api->version ) ) {
                $version_latest = $call_api->version;
            }
        }

        return $version_latest;
    }

    /**
     * Get AM plugin data to display in the Addons section of About tab.
     *
     * @since 6.4.0.4
     *
     * @param string $plugin      Plugin slug.
     * @param array  $details     Plugin details.
     * @param array  $all_plugins List of all plugins.
     *
     * @return array
     */
    protected function poll_get_plugin_data( $plugin, $details, $all_plugins ) {

        $have_pro = ( ! empty( $details['pro'] ) && ! empty( $details['pro']['plug'] ) );
        $show_pro = false;

        $plugin_data = array();

        if ( $have_pro ) {
            if ( array_key_exists( $plugin, $all_plugins ) ) {
                if ( is_plugin_active( $plugin ) ) {
                    $show_pro = true;
                }
            }
            if ( array_key_exists( $details['pro']['plug'], $all_plugins ) ) {
                $show_pro = true;
            }
            if ( $show_pro ) {
                $plugin  = $details['pro']['plug'];
                $details = $details['pro'];
            }
        }

        if ( array_key_exists( $plugin, $all_plugins ) ) {
            if ( is_plugin_active( $plugin ) ) {
                // Status text/status.
                $plugin_data['status_class'] = 'status-active';
                $plugin_data['status_text']  = esc_html__( 'Active', 'poll-maker' );
                // Button text/status.
                $plugin_data['action_class'] = $plugin_data['status_class'] . ' ays-poll-card__btn-info disabled';
                $plugin_data['action_text']  = esc_html__( 'Activated', 'poll-maker' );
                $plugin_data['plugin_src']   = esc_attr( $plugin );
            } else {
                // Status text/status.
                $plugin_data['status_class'] = 'status-installed';
                $plugin_data['status_text']  = esc_html__( 'Inactive', 'poll-maker' );
                // Button text/status.
                $plugin_data['action_class'] = $plugin_data['status_class'] . ' ays-poll-card__btn-info';
                $plugin_data['action_text']  = esc_html__( 'Activate', 'poll-maker' );
                $plugin_data['plugin_src']   = esc_attr( $plugin );
            }
        } else {
            // Doesn't exist, install.
            // Status text/status.
            $plugin_data['status_class'] = 'status-missing';

            if ( isset( $details['act'] ) && 'go-to-url' === $details['act'] ) {
                $plugin_data['status_class'] = 'status-go-to-url';
            }
            $plugin_data['status_text'] = esc_html__( 'Not Installed', 'poll-maker' );
            // Button text/status.
            $plugin_data['action_class'] = $plugin_data['status_class'] . ' ays-poll-card__btn-info';
            $plugin_data['action_text']  = esc_html__( 'Install Plugin', 'poll-maker' );
            $plugin_data['plugin_src']   = esc_url( $details['url'] );
        }

        $plugin_data['details'] = $details;

        return $plugin_data;
    }

    /**
     * Display the Addons section of About tab.
     *
     * @since 1.3.9
     */
    public function poll_output_about_addons() {

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins          = get_plugins();
        $am_plugins           = $this->poll_get_am_plugins();
        $can_install_plugins  = self::ays_poll_can_install( 'plugin' );
        $can_activate_plugins = self::ays_poll_can_activate( 'plugin' );

        $content = '';
        $content.= '<div class="ays-poll-cards-block">';
        foreach ( $am_plugins as $plugin => $details ){

            $plugin_data = $this->poll_get_plugin_data( $plugin, $details, $all_plugins );
            $plugin_ready_to_activate = $can_activate_plugins
                && isset( $plugin_data['status_class'] )
                && $plugin_data['status_class'] === 'status-installed';
            $plugin_not_activated     = ! isset( $plugin_data['status_class'] )
                || $plugin_data['status_class'] !== 'status-active';

            $plugin_action_class = ( isset( $plugin_data['action_class'] ) && esc_attr( $plugin_data['action_class'] ) != "" ) ? esc_attr( $plugin_data['action_class'] ) : "";

            $plugin_action_class_disbaled = "";
            if ( strpos($plugin_action_class, 'status-active') !== false ) {
                $plugin_action_class_disbaled = "disbaled='true'";
            }

            $content .= '
                <div class="ays-poll-card">
                    <div class="ays-poll-card__content flexible">
                        <div class="ays-poll-card__content-img-box">
                            <img class="ays-poll-card__img" src="'. esc_url( $plugin_data['details']['icon'] ) .'" alt="'. esc_attr( $plugin_data['details']['name'] ) .'">
                        </div>
                        <div class="ays-poll-card__text-block">
                            <h5 class="ays-poll-card__title">'. esc_html( $plugin_data['details']['name'] ) .'</h5>
                            <p class="ays-poll-card__text">'. wp_kses_post( $plugin_data['details']['desc'] ) .'
                                <span class="ays-poll-card__text-hidden">
                                    '. wp_kses_post( $plugin_data['details']['desc_hidden'] ) .'
                                </span>
                            </p>
                        </div>
                    </div>
                    <div class="ays-poll-card__footer">';
                        if ( $can_install_plugins || $plugin_ready_to_activate || ! $details['wporg'] ) {
                            $content .= '<button class="'. esc_attr( $plugin_data['action_class'] ) .'" data-plugin="'. esc_attr( $plugin_data['plugin_src'] ) .'" data-type="plugin" '. $plugin_action_class_disbaled .'>
                                '. wp_kses_post( $plugin_data['action_text'] ) .'
                            </button>';
                        }
                        elseif ( $plugin_not_activated ) {
                            $content .= '<a href="'. esc_url( $details['wporg'] ) .'" target="_blank" rel="noopener noreferrer">
                                '. esc_html_e( 'WordPress.org', $this->plugin_name ) .'
                                <span aria-hidden="true" class="dashicons dashicons-external"></span>
                            </a>';
                        }
            $content .='
                        <a target="_blank" href="'. esc_url( $plugin_data['details']['buy_now'] ) .'" class="ays-poll-card__btn-primary">'. __('Buy Now', 'poll-maker') .'</a>
                    </div>
                </div>';
        }
        $install_plugin_nonce = wp_create_nonce( $this->plugin_name . '-install-plugin-nonce' );
        $content .= '<input type="hidden" id="ays_poll_ajax_install_plugin_nonce" name="ays_poll_ajax_install_plugin_nonce" value="'. $install_plugin_nonce .'">';
        $content .= '</div>';

        echo $content;
    }

    public function ays_poll_update_banner_time(){

        $date = time() + ( 3 * 24 * 60 * 60 ) + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS);
        // $date = time() + ( 60 ) + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS); // for testing | 1 min
        $next_3_days = date('M d, Y H:i:s', $date);

        $ays_poll_banner_time = get_option('ays_poll_banner_time');

        if ( !$ays_poll_banner_time || is_null( $ays_poll_banner_time ) ) {
            update_option('ays_poll_banner_time', $next_3_days ); 
        }

        $get_ays_poll_banner_time = get_option('ays_poll_banner_time');

        $val = 60*60*24*0.5; // half day
        // $val = 60; // for testing | 1 min

        $current_date = current_time( 'mysql' );
        $date_diff = strtotime($current_date) - intval(strtotime($get_ays_poll_banner_time));

        $days_diff = $date_diff / $val;
        if(intval($days_diff) > 0 ){
            update_option('ays_poll_banner_time', $next_3_days);
			$get_ays_poll_banner_time = get_option('ays_poll_banner_time');
        }

        return $get_ays_poll_banner_time;
    }

	public static function ays_poll_check_if_current_image_exists($image_url) {
		global $wpdb;

        $res = true;
        if( !isset($image_url) ){
            $res = false;
        }

        if ( isset($image_url) && !empty( $image_url ) ) {

            $re = '/-\d+[Xx]\d+\./';
            $subst = '.';

            $image_url = preg_replace($re, $subst, $image_url, 1);

            $attachment = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid='%s';", $image_url ));
            if ( is_null( $attachment ) || empty( $attachment ) ) {
                $res = false;
            }
        }

        return $res;
	}

	public function ays_poll_maker_quick_start() {
		global $wpdb;
		error_reporting(0);

		// Run a security check.
        check_ajax_referer( 'poll-maker-ajax-quick-poll-nonce', sanitize_key( $_REQUEST['_ajax_nonce'] ) );

		// Check for permissions.
        if ( !Poll_Maker_Data::check_user_capability() ) {
            ob_end_clean();
            $ob_get_clean = ob_get_clean();
            echo json_encode(array(
                'status' => false,
                'poll_id' => 0
            ));
            wp_die();
        }

		$polls_table = esc_sql($wpdb->prefix . "ayspoll_polls");
		$answers_table = esc_sql($wpdb->prefix . "ayspoll_answers");

		$title = stripslashes( sanitize_text_field($_REQUEST['ays-poll-title']) );
		$question = wp_kses_post( $_REQUEST['ays_poll_question'] );

		$answers = self::recursive_sanitize_text_field($_REQUEST['ays-poll-answers']);
		
		$allow_multivote = isset($_REQUEST['allow_multivote_switch']) && $_REQUEST['allow_multivote_switch'] == 'on' ? "on" : "off";
		$allow_not_vote = isset($_REQUEST['allow-not-vote']) && 'on' == $_REQUEST['allow-not-vote'] ? 1 : 0;
		$show_author = isset($_REQUEST['quick-poll-show_poll_author']) && 1 == $_REQUEST['quick-poll-show_poll_author'] ? 1 : 0;
		$show_title = isset($_REQUEST['quick-poll-show-title']) && $_REQUEST['quick-poll-show-title'] == 'off' ? 0 : 1;
		$show_creation_date = isset($_REQUEST['quick-poll-show-creation-date']) && $_REQUEST['quick-poll-show-creation-date'] == 'on' ? 1 : 0;
		$hide_results = isset($_REQUEST['quick-poll-hide-results']) && 1 == $_REQUEST['quick-poll-hide-results'] ? 1 : 0;
		$randomize_answers = isset($_REQUEST['quick-poll-randomize-answers']) && $_REQUEST['quick-poll-randomize-answers'] == 'on' ? 'on' : 'off';
		$enable_restart_button = isset($_REQUEST['quick-poll-enable-restart-button']) && $_REQUEST['quick-poll-enable-restart-button'] == 'on' ? 1 : 0;
		$res_rgba = isset($_REQUEST['quick-poll-res-rgba']) && $_REQUEST['quick-poll-res-rgba'] == 'on' ? 'on' : 'off';

		if	($allow_multivote === 'on') {
			$multivote_min_count = sanitize_text_field($_REQUEST['quick-poll-multivote-min-count']);
			$multivote_max_count = sanitize_text_field($_REQUEST['quick-poll-multivote-max-count']);
		} else {
			$multivote_min_count = 1;
			$multivote_max_count = 1;
		}
		
		$create_date = current_time( 'mysql' );

        $user_id = get_current_user_id();
		$user = get_userdata($user_id);
		$author = array(
			'id' => $user->ID,
			'name' => $user->data->display_name
		);
		$options = json_encode(
			array(
			"poll_version" 					=> POLL_MAKER_AYS_VERSION,
			"poll_enable_copy_protection" 	=> "off",
			"poll_question_text_to_speech" 	=> "off",
			"main_color" 					=> "#0C6291",
			"text_color" 					=> "#0C6291",
			"icon_color" 					=> "#0C6291",
			"bg_color" 						=> "#FBFEF9",
			"answer_bg_color" 				=> "#FBFEF9",
			"answer_hover_color" 			=> "#0C6291",
			"answer_border_side" 			=> "all_sides",
			"title_bg_color" 				=> "",
			"icon_size" 					=> 24,
			"width" 						=> 600,
			"width_for_mobile" 				=> 0,
			"btn_text" 						=> "Vote",
			"see_res_btn_text" 				=> "See Results",
			"border_style" 					=> "ridge",
			"border_radius" 				=> "0",
			"border_width" 					=> "1",
			"box_shadow_color" 				=> "#000000",
			"enable_box_shadow" 			=> "off",
			"enable_answer_style" 			=> "on",
			"bg_image" 						=> false,
			"info_form" 					=> 0,
			"fields" 						=> "apm-name,apm-email,apm_phone",
			"required_fields" 				=> "apm-name,apm-email,apm_phone",
			"info_form_title" 				=> "<h5>Please fill out the form:<\/h5>\n",
			"hide_results" 					=> $hide_results,
			"hide_result_message" 			=> 0,
			"hide_results_text" 			=> "<p>Thanks for your answer!<\/p>\n",
			"result_message"				=> "",
			"allow_not_vote"				=> $allow_not_vote,
			"show_social"					=> 0,
			"poll_social_buttons_heading"	=> "",
			"poll_show_social_ln"			=> "on",
			"poll_show_social_fb"			=> "on",
			"poll_show_social_tr" 			=> "on",
			"poll_show_social_vk" 			=> "off",
			"enable_social_links" 			=> "off",
			"poll_social_links_heading" 	=> "",
			"social_links" => array(
				"linkedin_link" 	=> "",
				"facebook_link" 	=> "",
				"twitter_link" 		=> "",
				"vkontakte_link" 	=> "",
				"youtube_link" 		=> "",
				"tiktok_link" 		=> "",
				"instagram_link" 	=> ""
			),
			"load_effect" 					=> "load_gif",
			"load_gif" 						=> "plg_pro1",
			"custom_load" 					=> false,
			"limit_users" 					=> 0,
			"limit_users_method" 			=> "ip",
			"limitation_message" 			=> "<p>You have already voted<\/p>\n",
			"redirect_url" 					=> false,
			"redirection_delay" 			=> 0,
			"user_role" 					=> "",
			"enable_restriction_pass" 		=> 0,
			"restriction_pass_message" 		=> "<p>You don\\'t have permissions for passing the poll<\/p>\n",
			"enable_logged_users" 			=> 0,
			"enable_logged_users_message"   => "<p>You must sign in for passing the poll<\/p>\n",
			"notify_email_on" 				=> 0,
			"notify_email" 					=> "",
			"published" 					=> 1,
			"enable_pass_count" 			=> "on",
			"result_sort_type" 				=> "none",
			"create_date" 					=> $create_date,
			"redirect_users" 				=> 0,
			"redirect_after_vote_url" 		=> false,
			"redirect_after_vote_delay" 	=> 0,
			"activeInterval" 				=> "2022-09-17",
			"deactiveInterval" 				=> "2022-09-17",
			"activeIntervalSec" 			=> "",
			"deactiveIntervalSec" 			=> "",
			"active_date_message" 			=> "<p>The poll has expired!<\/p>\n",
			"active_date_message_soon" 		=> "<p style=\\\"text-align =>  center;\\\">The poll will be available soon!<\/p>\n",
			"vote_reason" 					=> 0,
			"show_chart_type" 				=> "google_bar_chart",
			"active_date_check" 			=> "",
			"enable_restart_button" 		=> $enable_restart_button,
			"enable_vote_btn" 				=> 1,
			"show_votes_count" 				=> 1,
			"attempts_count" 				=> "1",
			"poll_main_url" 				=> '',
			"show_create_date" 				=> $show_creation_date,
			"show_author" 					=> $show_author,
			"author" 						=> $author,
			"show_res_percent" 				=> 1,
			"show_result_btn_schedule"	 	=> 0,
			"ays_poll_show_timer" 			=> 0,
			"show_bottom_timer" 			=> 0,
			"ays_show_timer_type" 			=> "countdown",
			"show_login_form" 				=> "off",
			"poll_allow_answer" 			=> 0,
			"poll_allow_answer_require" 	=> 1,
			"versus_icon_type" 				=> "default",
			"versus_icon_position" 			=> "center",
			"versus_answers_label" 			=> 0,
			"result_in_rgba" 				=> $res_rgba,
			"enable_mailchimp" 				=> "off",
			"enable_background_gradient" 	=> "off",
			"background_gradient_color_1" 	=> "#103251",
			"background_gradient_color_2" 	=> "#607593",
			"poll_gradient_direction" 		=> "vertical",
			"redirect_after_submit" 		=> 0,
			"mailchimp_list" 				=> "",
			"poll_direction" 				=> "ltr",
			"poll_allow_multivote" 			=> $allow_multivote,
			"multivote_answer_min_count" 	=> $multivote_min_count,
			"poll_allow_multivote_count" 	=> $multivote_max_count,
			"monitor_list" => "",
			"enable_monitor" => "off",
			"slack_conversation" => "",
			"enable_slack" => "off",
			"active_camp_list" => "",
			"active_camp_automation" => "",
			"enable_active_camp" => "off",
			"enable_zapier" => "off",
			"randomize_answers" => $randomize_answers,
			"enable_asnwers_sound" => "off",
			"enable_password" => "off",
			"password_poll" => "",
			"poll_password_message" => "Please enter password",
			"poll_enable_password_visibility" => "off",
			"background_size" => "cover",
			"disable_answer_hover" => 0,
			"custom_class" => "",
			"enable_poll_title_text_shadow" => "off",
			"poll_title_text_shadow" => "rgba(255,255,255,0)",
			"poll_title_text_shadow_x_offset" => 2,
			"poll_title_text_shadow_y_offset" => 2,
			"poll_title_text_shadow_z_offset" => 0,
			"poll_bg_image_position" => "center center",
			"poll_bg_img_in_finish_page" => "off",
			"ays_add_post_for_poll" => "off",
			"show_answer_message" => "off",
			"show_answers_caption" => "on",
			"answers_grid_column_mobile" => "on",
			"enable_vote_limitation" => "off",
			"vote_limitation" => "",
			"limitation_time_period" => "minute",
			"enable_tackers_count" => "off",
			"tackers_count" => "",
			"ays_enable_mail_user" => "off",
			"vote_notification_email_msg" => "",
			"poll_answer_icon_check" => "off",
			"answers_icon" => "radio",
			"buttons_size" => "medium",
			"buttons_font_size" => "17",
			"poll_buttons_mobile_font_size" => "17",
			"buttons_left_right_padding" => "20",
			"buttons_top_bottom_padding" => "10",
			"buttons_border_radius" => "3",
			"redirect_after_submit_drpdwn" => 0,
			"user_add_answer_dropdown" => 0,
			"enable_google_sheets" => "off",
			"spreadsheet_id" => "",
			"enable_view_more_button" => "off",
			"poll_view_more_button_count" => 0,
			"poll_min_height" => "",
			"answer_sort_type" => "default",
			"answer_font_size" => "16",
			"poll_answer_font_size_mobile" => "16",
			"show_passed_users" => "off",
			"logo_image" => "",
			"allow_collect_user_info" => "off",
			"poll_send_mail_type" => "custom",
			"poll_sendgrid_email_from" => "",
			"poll_sendgrid_email_name" => "",
			"poll_sendgrid_template_id" => "",
			"limit_country" => "AD",
			"show_votes_before_voting" => "off",
			"show_votes_before_voting_by" => "by_count",
			"fake_votes" => "off",
			"dont_show_poll_cont" => "off",
			"see_result_button" => "on",
			"see_result_radio" => "ays_see_result_button",
			"loader_font_size" => "",
			"show_answers_numbering" => "none",
			"effect_message" => "",
			"enable_mad_mimi" => "off",
			"mad_mimi_list" => "",
			"poll_show_passed_users_count" => 3,
			"question_font_size" => 16,
			"question_font_size_mobile" => 16,
			"poll_question_image_height" => "",
			"poll_mobile_max_width" => "",
			"poll_title_font_size" => "18",
			"poll_title_font_size_mobile" => "20",
			"poll_title_alignment" => "center",
			"poll_title_alignment_mobile" => "center",
			"poll_enable_answer_image_after_voting" => "off",
			"poll_text_type_length_enable" => "off",
			"poll_text_type_limit_type" => "characters",
			"poll_text_type_limit_length" => "",
			"poll_text_type_limit_message" => "off",
			"poll_text_type_placeholder" => "Your answer",
			"poll_text_type_width" => "",
			"poll_text_type_width_type" => "percent",
			"poll_answer_padding" => 10,
			"poll_answer_margin" => 10,
			"answers_border" => "on",
			"answers_border_width" => 1,
			"answers_border_style" => "solid",
			"answers_border_color" => "#444",
			"poll_answer_enable_box_shadow" => "off",
			"answers_box_shadow_color" => "#000",
			"poll_answer_box_shadow_x_offset" => 0,
			"poll_answer_box_shadow_y_offset" => 0,"poll_answer_box_shadow_z_offset" => 10,
			"poll_answer_image_height" => 150,
			"poll_answer_image_height_for_mobile" => "150",
			"poll_answer_image_border_radius" => 0,
			"ans_img_caption_style" => "outside",
			"ans_img_caption_position" => "bottom",
			"answers_font_size" => 15,
			"poll_answer_object_fit" => "cover",
			"answers_grid_column" => 2,
			"poll_answer_border_radius" => 0,
			"enable_getResponse" => "off",
			"getResponse_list" => "",
			"enable_mailerLite" => "off",
			"mailerLite_group_id" => "",
			"enable_convertKit" => "off",
			"poll_convertKit_form_id" => "",
			"enable_mailpoet" => "off",
			"mailpoet_list" => "",
			"poll_logo_url" => "",
			"poll_enable_logo_url" => "off",
			"poll_logo_url_new_tab" => "off",
			"poll_send_mail_to_site_admin" => "on",
			"poll_email_configuration_from_email" => "",
			"poll_email_configuration_from_name" => "",
			"poll_email_configuration_from_subject" => "",
			"poll_email_configuration_replyto_email" => "",
			"poll_email_configuration_replyto_name" => "",
			"display_fields_labels" => "off",
			"autofill_user_data" => "off",
			"poll_create_author" => 1
		));

		$wpdb->insert($polls_table, array(
			"title" => $title,
			"question" => $question,
			"type" => "choosing",
			"view_type" => "list",
			"categories" => ",1,",
			"show_title" => $show_title,
			"styles" => $options,
			"theme_id" => 1,
		));

		$poll_id = $wpdb->insert_id;

		foreach ($answers as $answer_key => $answer) {
			$wpdb->insert($answers_table, array(
				"poll_id" => $poll_id,
				"answer" => $answer,
				"votes" => 0,
				"ordering" => (intval($answer_key) + 1),
				"user_added" => 0,
				"show_user_added" => 1,
			));
		}

		$post_type_args = array(
            'poll_id'       => $poll_id,
            'author_id'     => !empty($user->ID) ? $user->ID : get_current_user_id(),
            'poll_title'    => $poll_title,
        );
        
        $custom_post_id = Poll_Maker_Custom_Post_Type::ays_poll_add_custom_post($post_type_args);

		$preview_url = "#";
        if(!empty($custom_post_id)){
            $custom_post_url = array(
                'post_type' => 'ays-poll-maker',
                'p'         => $custom_post_id,
                'preview'   => 'true',
            );
            $custom_post_url_ready = http_build_query($custom_post_url);
            $preview_url = get_home_url();
            $preview_url .= '/?' . $custom_post_url_ready;
        }

		echo json_encode(array(
            'status' => true,
            'poll_id' => $poll_id,
            'preview_url' => $preview_url,
        ));
        wp_die();
	}

	public function ays_poll_black_friady_popup_box(){
        	
        if(!empty($_REQUEST['page']) && sanitize_text_field( $_REQUEST['page'] ) != $this->plugin_name . "-dashboard"){
            if(false !== strpos( sanitize_text_field( $_REQUEST['page'] ), $this->plugin_name)){

                $flag = true;

                if( isset($_COOKIE['aysPollBlackFridayPopupCount']) && intval($_COOKIE['aysPollBlackFridayPopupCount']) >= 2 ){
                    $flag = false;
                }

                $ays_poll_cta_button_link = esc_url('https://ays-pro.com/mega-bundle?utm_source=dashboard&utm_medium=poll-free&utm_campaign=mega-bundle-popup-black-friday-sale-' . POLL_MAKER_AYS_VERSION);

                if( $flag ){
                ?>
                <div class="ays-poll-black-friday-popup-overlay" style="opacity: 0; visibility: hidden; display: none;">
                  <div class="ays-poll-black-friday-popup-dialog">
                    <div class="ays-poll-black-friday-popup-content">
                      <div class="ays-poll-black-friday-popup-background-pattern">
                        <div class="ays-poll-black-friday-popup-pattern-row">
                          <div class="ays-poll-black-friday-popup-pattern-text">SALE SALE SALE</div>
                          <div class="ays-poll-black-friday-popup-pattern-text">SALE SALE SALE</div>
                        </div>
                        <div class="ays-poll-black-friday-popup-pattern-row">
                          <div class="ays-poll-black-friday-popup-pattern-text">SALE SALE SALE</div>
                          <div class="ays-poll-black-friday-popup-pattern-text">SALE SALE SALE</div>
                        </div>
                        <div class="ays-poll-black-friday-popup-pattern-row">
                          <div class="ays-poll-black-friday-popup-pattern-text">SALE SALE SALE</div>
                          <div class="ays-poll-black-friday-popup-pattern-text">SALE SALE SALE</div>
                        </div>
                        <div class="ays-poll-black-friday-popup-pattern-row">
                          <div class="ays-poll-black-friday-popup-pattern-text">SALE SALE SALE</div>
                          <div class="ays-poll-black-friday-popup-pattern-text">SALE SALE SALE</div>
                        </div>
                      </div>
                      
                      <button class="ays-poll-black-friday-popup-close" aria-label="Close">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                          <path d="M18 6 6 18"></path>
                          <path d="m6 6 12 12"></path>
                        </svg>
                      </button>
                      
                      <div class="ays-poll-black-friday-popup-badge">
                        <div class="ays-poll-black-friday-popup-badge-content">
                          <div class="ays-poll-black-friday-popup-badge-text-sm"><?php echo esc_html__( 'Up to', 'poll-maker' ); ?></div>
                          <div class="ays-poll-black-friday-popup-badge-text-lg">50%</div>
                          <div class="ays-poll-black-friday-popup-badge-text-md"><?php echo esc_html__( 'OFF', 'poll-maker' ); ?></div>
                        </div>
                      </div>
                      
                      <div class="ays-poll-black-friday-popup-main-content">
                        <div class="ays-poll-black-friday-popup-hashtag"><?php echo esc_html__( '#BLACKFRIDAY', 'poll-maker' ); ?></div>
                        <h1 class="ays-poll-black-friday-popup-title-mega"><?php echo esc_html__( 'MEGA', 'poll-maker' ); ?></h1>
                        <h1 class="ays-poll-black-friday-popup-title-bundle"><?php echo esc_html__( 'BUNDLE', 'poll-maker' ); ?></h1>
                        <div class="ays-poll-black-friday-popup-offer-label">
                          <h2 class="ays-poll-black-friday-popup-offer-text"><?php echo esc_html__( 'BLACK FRIDAY OFFER', 'poll-maker' ); ?></h2>
                        </div>
                        <p class="ays-poll-black-friday-popup-description"><?php echo esc_html__( 'Get our exclusive plugins in one bundle', 'poll-maker' ); ?></p>
                        <a href="<?php echo esc_url($ays_poll_cta_button_link); ?>" target="_blank" class="ays-poll-black-friday-popup-cta-btn"><?php echo esc_html__( 'Get Mega Bundle', 'poll-maker' ); ?></a>
                      </div>
                    </div>
                  </div>
                </div>
                <script type="text/javascript">
                    (function() {
                      var overlay = document.querySelector('.ays-poll-black-friday-popup-overlay');
                      var closeBtn = document.querySelector('.ays-poll-black-friday-popup-close');
                      var learnMoreBtn = document.querySelector('.ays-poll-black-friday-popup-learn-more');
                      var ctaBtn = document.querySelector('.ays-poll-black-friday-popup-cta-btn');

                      // Cookie helper functions
                      function setCookie(name, value, days) {
                        var expires = "";
                        if (days) {
                          var date = new Date();
                          date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                          expires = "; expires=" + date.toUTCString();
                        }
                        document.cookie = name + "=" + (value || "") + expires + "; path=/";
                      }

                      function getCookie(name) {
                        var nameEQ = name + "=";
                        var ca = document.cookie.split(';');
                        for (var i = 0; i < ca.length; i++) {
                          var c = ca[i];
                          while (c.charAt(0) == ' ') c = c.substring(1, c.length);
                          if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length, c.length);
                        }
                        return null;
                      }

                      // Get current show count from cookie
                      var showCount = parseInt(getCookie('aysPollBlackFridayPopupCount') || '0', 10);
                      var maxShows = 2;

                      // Show popup function
                      function showPopup() {
                        if (overlay && showCount < maxShows) {
                          overlay.classList.add('ays-poll-black-friday-popup-active');
                          showCount++;
                          // Update cookie with new count (expires in 30 days)
                          setCookie('aysPollBlackFridayPopupCount', showCount.toString(), 30);
                        }
                      }

                      // Close popup function
                      function closePopup(e) {
                        if (e) {
                          e.preventDefault();
                          e.stopPropagation();
                        }
                        if (overlay) {
                          overlay.classList.remove('ays-poll-black-friday-popup-active');
                        }
                      }

                      // Determine timing based on show count
                      if (showCount === 0) {
                        // First time - show after 30 seconds
                        setTimeout(function() {
                          showPopup();
                        }, 30000);
                      } else if (showCount === 1) {
                        // Second time - show after 200 seconds
                        setTimeout(function() {
                          showPopup();
                        }, 200000);
                      }
                      // If showCount >= 2, don't show popup at all

                      // Close button
                      if (closeBtn) {
                        closeBtn.addEventListener('click', function(e) {
                          closePopup(e);
                        });
                      }

                      // Learn more button
                      if (learnMoreBtn) {
                        learnMoreBtn.addEventListener('click', function(e) {
                          closePopup(e);
                        });
                      }

                      // CTA button (optional - if you want it to close popup too)
                      if (ctaBtn) {
                        ctaBtn.addEventListener('click', function(e) {
                          // You can add redirect logic here if needed
                          // window.location.href = 'your-url';
                        });
                      }

                      // Close on overlay click
                      if (overlay) {
                        overlay.addEventListener('click', function(e) {
                          if (e.target === overlay) {
                            closePopup(e);
                          }
                        });
                      }

                      // Close on Escape key
                      document.addEventListener('keydown', function(e) {
                        if (e.key === 'Escape' && overlay && overlay.classList.contains('ays-poll-black-friday-popup-active')) {
                          closePopup();
                        }
                      });
                    })();
                </script>
                <style>
                    .ays-poll-black-friday-popup-overlay{position:fixed;top:0;left:0;right:0;bottom:0;z-index:9999;background-color:rgba(0,0,0,.8);display:flex;align-items:center;justify-content:center;opacity:0;visibility:hidden;transition:opacity .2s,visibility .2s}.ays-poll-black-friday-popup-overlay.ays-poll-black-friday-popup-active{display:flex!important;opacity:1!important;visibility:visible!important}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-dialog{position:relative;max-width:470px;width:100%;border-radius:8px;overflow:hidden;background:0 0;box-shadow:0 25px 50px -12px rgba(0,0,0,.25);transform:scale(.95);transition:transform .2s}.ays-poll-black-friday-popup-overlay.ays-poll-black-friday-popup-active .ays-poll-black-friday-popup-dialog{transform:scale(1)}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-content{position:relative;width:470px;height:410px;background:linear-gradient(to right bottom,#c056f5,#f042f0,#7d7de8);overflow:hidden}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-background-pattern{position:absolute;top:0;left:0;right:0;bottom:0;opacity:.07;pointer-events:none;transform:rotate(-12deg) translateY(32px);overflow:hidden}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-pattern-row{display:flex;gap:16px;margin-bottom:16px}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-pattern-text{color:#fff;font-weight:900;font-size:96px;white-space:nowrap;line-height:1}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-close{position:absolute;top:16px;right:16px;z-index:9999;background:0 0;border:none;color:rgba(255,255,255,.8);cursor:pointer;padding:4px;transition:color .2s;line-height:0}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-close:hover,.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-learn-more:hover{color:#fff}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-badge{position:absolute;top:32px;right:32px;width:96px;height:96px;background-color:#d4fc79;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 25px 50px -12px rgba(0,0,0,.25);animation:3s ease-in-out infinite ays-poll-black-friday-popup-float}@keyframes ays-poll-black-friday-popup-float{0%,100%{transform:translateY(0)}50%{transform:translateY(-10px)}}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-badge-content{text-align:center}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-badge-text-sm{color:#1a1a1a;font-weight:900;font-size:24px;line-height:1}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-badge-text-lg{color:#1a1a1a;font-weight:900;font-size:30px;line-height:1;margin-top:4px}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-badge-text-md{color:#1a1a1a;font-weight:900;font-size:20px;line-height:1}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-main-content{position:relative;z-index:10;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:0 48px;text-align:center}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-hashtag{color:rgba(255,255,255,.9);font-weight:700;font-size:14px;margin-bottom:16px;letter-spacing:.1em}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-title-mega{color:#fff;font-weight:900;font-size:60px;line-height:1;margin:0 0 12px;text-shadow:0 4px 6px rgba(0,0,0,.1)}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-title-bundle{color:#fff;font-weight:900;font-size:60px;line-height:1;margin:0 0 24px;text-shadow:0 4px 6px rgba(0,0,0,.1)}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-offer-label{background-color:#000;padding:12px 32px;margin-bottom:24px;display:inline-block}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-offer-text{color:#fff;font-weight:700;font-size:20px;letter-spacing:.05em;margin:0}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-description{color:rgba(255,255,255,.95);font-size:18px;font-weight:500;margin:0 0 32px!important}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-cta-btn{display:inline-flex;align-items:center;justify-content:center;height:48px;background-color:#fff;color:#a855f7;font-size:18px;font-weight:700;border:none;border-radius:24px;padding:0 40px;cursor:pointer;box-shadow:0 20px 25px -5px rgba(0,0,0,.1);transition:.2s;text-decoration:none}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-cta-btn:hover{background-color:rgba(255,255,255,.9);box-shadow:0 25px 50px -12px rgba(0,0,0,.25);transform:scale(1.05)}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-learn-more{background:0 0;border:none;color:rgba(255,255,255,.9);font-size:14px;text-decoration:underline;text-underline-offset:4px;cursor:pointer;padding:8px;margin-top:16px;transition:color .2s}@media (max-width:768px){.ays-poll-black-friday-popup-overlay{display:none!important}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-content{width:90vw;max-width:400px;height:380px}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-main-content{padding:0 32px}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-badge{width:80px;height:80px;top:24px;right:24px}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-badge-text-sm{font-size:20px}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-badge-text-lg{font-size:26px}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-badge-text-md,.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-offer-text{font-size:18px}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-title-bundle,.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-title-mega{font-size:48px}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-description{font-size:16px}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-pattern-text{font-size:72px}}@media (max-width:480px){.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-content{width:95vw;max-width:340px;height:360px}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-main-content{padding:0 24px}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-badge{width:70px;height:70px;top:20px;right:20px}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-badge-text-sm,.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-offer-text{font-size:16px}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-badge-text-lg{font-size:22px}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-badge-text-md{font-size:14px}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-hashtag{font-size:12px;margin-bottom:12px}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-title-bundle,.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-title-mega{font-size:40px;margin-bottom:8px}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-offer-label{padding:10px 24px;margin-bottom:20px}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-description{font-size:15px;margin-bottom:24px}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-cta-btn{font-size:16px;height:44px;padding:0 32px}.ays-poll-black-friday-popup-overlay .ays-poll-black-friday-popup-pattern-text{font-size:60px}}
                </style>
                <?php
                }
            }
        }
    }

}