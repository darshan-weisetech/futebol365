<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Additional hooks for "Permalink Manager Pro"
 */
class Permalink_Manager_Pro_License {

	public $update_checker;

	public function __construct() {
		define( 'PERMALINK_MANAGER_PRO', true );
		$plugin_name = preg_replace( '/(.*)\/([^\/]+\/[^\/]+.php)$/', '$2', PERMALINK_MANAGER_FILE );

		// Permalink Manager Pro Alerts
		add_filter( 'permalink_manager_alerts', array( $this, 'pro_alerts' ), 9, 3 );

		// Check for updates
		add_action( 'plugins_loaded', array( $this, 'check_for_updates' ), 10 );
		add_action( 'admin_init', array( $this, 'reload_license_key' ), 10 );
		add_action( 'wp_ajax_pm_get_exp_date', array( $this, 'get_expiration_date' ), 9 );

		// Display License info on "Plugins" page
		add_action( "after_plugin_row_{$plugin_name}", array( $this, 'license_info_bar' ), 10, 2 );
	}

	/**
	 * Get the license key from the database, constant defined in wp-config.php file, or $_POST variable
	 *
	 * @param string $load_from_db If set to true, the function will load the license key from the database, even if it's defined in wp-config.php.
	 *
	 * @return string The license key.
	 */
	public static function get_license_key( $load_from_db = false ) {
		$permalink_manager_options = get_option( 'permalink-manager', array() );

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		// Key defined in wp-config.php
		if ( ( defined( 'PMP_LICENCE_KEY' ) || defined( 'PMP_LICENSE_KEY' ) ) && empty( $load_from_db ) ) {
			$license_key = defined( 'PMP_LICENCE_KEY' ) ? PMP_LICENCE_KEY : PMP_LICENSE_KEY;
		} // Network licence key (multisite)
		else if ( is_multisite() ) {
			$site_licence_key = get_site_option( 'permalink-manager-licence-key' );

			// A. Move the license key to site options
			if ( ! empty( $site_licence_key ) && ! is_array( $site_licence_key ) ) {
				$new_license_key = $site_licence_key;
			} // B. Save the new license key in the plugin settings
			else if ( ! empty( $_POST['licence']['licence_key'] ) ) {
				$new_license_key = sanitize_text_field( $_POST['licence']['licence_key'] );
			}

			if ( ! empty( $new_license_key ) ) {
				$site_licence_key = array(
					'licence_key' => sanitize_text_field( $new_license_key )
				);

				update_site_option( 'permalink-manager-licence-key', $site_licence_key );
			}

			$license_key = ( ! empty( $site_licence_key['licence_key'] ) ) ? $site_licence_key['licence_key'] : '';
		} // Single website license key
		else if ( ! empty( $_POST['licence']['licence_key'] ) ) {
			$license_key = sanitize_text_field( $_POST['licence']['licence_key'] );
		} else {
			$license_key = ( ! empty( $permalink_manager_options['licence']['licence_key'] ) ) ? $permalink_manager_options['licence']['licence_key'] : "";
		}

		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		return preg_replace( "/[^a-zA-Z0-9-]/", "", $license_key );
	}

	/**
	 * Check if the saved license key matches the "developer" format
	 *
	 * @param $license_key
	 *
	 * @return bool
	 */
	public static function is_dev_license_key( $license_key ) {
		return preg_match( '/^([A-G0-9]{4,8})-([A-G0-9]{4,8})$/', $license_key ) ? true : false;
	}

	/**
	 * Load the update checker class and create an instance of it
	 */
	public function check_for_updates() {
		$license_key = self::get_license_key();

		// Load Plugin Update Checker by YahnisElsts
		require_once PERMALINK_MANAGER_DIR . '/includes/vendor/plugin-update-checker/plugin-update-checker.php';

		$this->update_checker = Puc_v4_Factory::buildUpdateChecker( "https://updates.permalinkmanager.pro/?action=get_metadata&slug=permalink-manager-pro&licence_key={$license_key}", PERMALINK_MANAGER_FILE, "permalink-manager-pro" );

		add_filter( 'puc_request_info_result-permalink-manager-pro', array( $this, 'update_pro_info' ), 99, 2 );
	}

	/**
	 * Check if the license key was changed and if so, delete the cached license data and get the new license data from the server
	 */
	public function reload_license_key() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_POST['licence']['licence_key'] ) || ( ! empty( $_REQUEST['action'] ) && $_REQUEST['action'] == 'pm_get_exp_date' ) || ( ! empty( $_REQUEST['puc_slug'] ) && $_REQUEST['puc_slug'] == 'permalink-manager-pro' ) ) {
			delete_site_transient( 'permalink_manager_active' );
			$this->update_checker->requestInfo();
		} // Sync the license data saved in DB after license key was set in wp-config.php file
		else if ( defined( 'PMP_LICENCE_KEY' ) || defined( 'PMP_LICENSE_KEY' ) ) {
			$db_license_key = self::get_license_key( true );
			$license_key    = self::get_license_key();

			if ( ! empty( $db_license_key ) && ! empty( $license_key ) && $db_license_key !== $license_key ) {
				delete_site_transient( 'permalink_manager_active' );
				$this->update_checker->requestInfo();
			}
		}
	}

	/**
	 * Check if the cached license key needs to be changed
	 *
	 * @param stdClass $raw The raw response from the server.
	 * @param array $result The response object from the API call.
	 *
	 * @return stdClass The plugin info
	 */
	public function update_pro_info( $raw, $result ) {
		$license_key              = self::get_license_key();
		$permalink_manager_active = ( empty( $_POST['licence']['licence_key'] ) ) ? get_site_transient( 'permalink_manager_active' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		// A. Do not do anything - the license info was saved before
		if ( ! empty( $license_key ) && ( $permalink_manager_active == $license_key ) ) {
			return $raw;
		} // B. The license info was not removed or not downloaded before
		else if ( empty( $permalink_manager_active ) && is_array( $result ) && ! empty( $result['body'] ) && ! empty( $license_key ) ) {
			$plugin_info = json_decode( $result['body'] );

			if ( is_object( $plugin_info ) && isset( $plugin_info->version ) ) {
				$exp_date = ( ! empty( $plugin_info->expiration_date ) && strlen( $plugin_info->expiration_date ) > 6 ) ? strtotime( $plugin_info->expiration_date ) : '-';
				$websites = ( ! empty( $plugin_info->websites ) ) ? $plugin_info->websites : '';

				$license_info = array(
					'licence_key'     => $license_key,
					'expiration_date' => $exp_date,
					'websites'        => $websites
				);

				if ( is_multisite() ) {
					update_site_option( 'permalink-manager-licence-key', $license_info );
				} else {
					Permalink_Manager_Actions::save_settings( 'licence', $license_info, false );
				}

				set_site_transient( 'permalink_manager_active', $license_key, 12 * HOUR_IN_SECONDS );
			}
		}

		return $raw;
	}

	/**
	 * Get license expiration date
	 *
	 * @param bool $basic_check
	 * @param bool $empty_if_valid
	 * @param bool $update_available
	 *
	 * @return int|string
	 */
	public static function get_expiration_date( $basic_check = false, $empty_if_valid = false, $update_available = true ) {
		global $permalink_manager_options;

		// Get expiration info & the licence key
		if ( is_multisite() ) {
			$site_licence_key = get_site_option( 'permalink-manager-licence-key' );

			$exp_date    = ( ! empty( $site_licence_key['expiration_date'] ) ) ? $site_licence_key['expiration_date'] : false;
			$license_key = ( ! empty( $site_licence_key['licence_key'] ) ) ? $site_licence_key['licence_key'] : "";
			$websites    = ( ! empty( $site_licence_key['websites'] ) ) ? $site_licence_key['websites'] : "";
		} else {
			$exp_date    = ( ! empty( $permalink_manager_options['licence']['expiration_date'] ) ) ? $permalink_manager_options['licence']['expiration_date'] : false;
			$license_key = ( ! empty( $permalink_manager_options['licence']['licence_key'] ) ) ? $permalink_manager_options['licence']['licence_key'] : "";
			$websites    = ( ! empty( $permalink_manager_options['licence']['websites'] ) ) ? $permalink_manager_options['licence']['websites'] : "";
		}

		$license_info_page = ( ! empty( $license_key ) ) ? self::get_license_info_link( $license_key ) : "#";

		// There is no license key defined
		if ( empty( $license_key ) ) {
			$settings_page_url = Permalink_Manager_Admin_Functions::get_admin_url( "&section=settings" );
			// translators: %s is the URL to the settings page where users can manage their license keys.
			$expiration_info = sprintf( __( 'Please paste the license key to access all Permalink Manager Pro updates & features <a href="%s" target="_blank">on this page</a>.', 'permalink-manager' ), $settings_page_url );
			$expired         = 3;
		} // License key is invalid
		else if ( $exp_date == '-' || ! preg_match( '/^([A-Z0-9]{5,8}-){1}[A-Z0-9]{5,8}((-[A-Z0-9]{5,8}){2})?$/i', $license_key ) ) {
			$expiration_info = __( 'Your Permalink Manager Pro license key is invalid!', 'permalink-manager' );
			$expired         = 3;
		} else {
			// Key expired
			if ( ! empty( $exp_date ) && $exp_date < time() ) {
				// translators: %s is the URL to the Permalink Manager page where users can manage their license keys.
				$expiration_info = sprintf( __( '<strong>Your Permalink Manager Pro license has expired.</strong><br />Please renew your license key using <a href="%s" target="_blank">this link</a> to restore access to plugin updates.', 'permalink-manager' ), $license_info_page );
				$expired         = 2;
			} // License key is abused
			else if ( ! empty( $exp_date ) && ! empty( $websites ) && $update_available === false ) {
				$expiration_info = sprintf( __( 'Your Permalink Manager Pro license is already in use on another website and cannot be used to request automatic update for this domain.', 'permalink-manager' ), $license_info_page ) . " ";
				// translators: %s is the URL to the Permalink Manager page where users can manage their license keys.
				$expiration_info .= sprintf( __( 'For further information, visit the <a href="%s" target="_blank"> License info</a> page.', 'permalink-manager' ), $license_info_page );
				$expired         = 3;
			} // Valid lifetime license key
			else if ( gmdate( "Y", intval( $exp_date ) ) > gmdate( 'Y', strtotime( "+10 years" ) ) ) {
				$expiration_info = __( 'You own a lifetime license key.', 'permalink-manager' );
				$expired         = 0;
			} // License key is valid
			else if ( $exp_date ) {
				// License key will expire in less than month & do not display the alert if the developer license key is used
				if ( $exp_date - MONTH_IN_SECONDS < time() && ! preg_match( '/^([A-G0-9]{4,8})-([A-G0-9]{4,8})$/', $license_key ) ) {
					// translators: %s is the Permalink Manager's license key expiry date.
					$expiration_info = sprintf( __( 'Your Permalink Manager Pro license key will expire on <strong>%s</strong>. Please renew it to maintain access to plugin updates and technical support!', 'permalink-manager' ), wp_date( get_option( 'date_format' ), $exp_date ) ) . " ";
					// translators: %s is the URL to the Permalink Manager page where users can manage their license keys.
					$expiration_info .= sprintf( __( 'For further information, visit the <a href="%s" target="_blank"> License info</a> page.', 'permalink-manager' ), $license_info_page );
					$expired         = 1;
				} // License key can be used
				else {
					// Translators: %1$s is the expiration date, %2$s is the URL to the license info page.
					$expiration_info = sprintf( __( 'Your license key is valid until %1$s.<br />To prolong it please go to <a href="%2$s" target="_blank">this page</a> for more information.', 'permalink-manager' ), gmdate( get_option( 'date_format' ), $exp_date ), $license_info_page );
					$expired         = 0;
				}
			} // Expiration data could not be downloaded
			else {
				$expiration_info = __( 'Expiration date could not be downloaded at this moment. Please try again in a few minutes.', 'permalink-manager' );
				$expired         = 0;
			}
		}

		// Do not return any text alert
		if ( $basic_check || ( $empty_if_valid && $expired == 0 ) ) {
			return $expired;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_REQUEST['action'] ) && $_REQUEST['action'] == 'pm_get_exp_date' ) {
			echo wp_kses_post( $expiration_info );
			die();
		} else {
			return $expiration_info;
		}
	}

	/**
	 * Get the URL to the license info page on the plugin's website
	 *
	 * @param $license_key
	 *
	 * @return string
	 */
	static function get_license_info_link( $license_key ) {
		$license_key  = ( self::is_dev_license_key( $license_key ) ) ? '' : $license_key;
		$license_link = ( ! empty( $license_key ) ) ? sprintf( "https://permalinkmanager.pro/license-info/%s/", trim( $license_key ) ) : "https://permalinkmanager.pro/license-info/";

		return esc_url( $license_link );
	}

	/**
	 * Display license status in the "Plugins" table
	 *
	 * @param string $plugin_file
	 * @param array $plugin_data
	 */
	function license_info_bar( $plugin_file, $plugin_data ) {
		global $wp_list_table;

		$column_count = ( ! empty( $wp_list_table ) ) ? $wp_list_table->get_column_count() : 3;
		// $update_available = (empty($plugin_data['package']) && !empty($plugin_data['update'])) ? false : true;

		$exp_info_text = self::get_expiration_date( false, true, false );
		$exp_info_code = self::get_expiration_date( true, true, false );

		if ( ! empty( $exp_info_text ) && $exp_info_code >= 1 ) {
			printf( '<tr class="plugin-update-tr permalink-manager-pro_license-info active" data-slug="%s" data-plugin="%s"><td colspan="%d" class="plugin-update colspanchange plugin_license_info_row">', esc_attr( $plugin_data['slug'] ), esc_attr( $plugin_file ), esc_attr( $column_count ) );
			printf( '<div class="update-message notice inline notice-error notice-alt">%s</div>', wp_kses_post( wpautop( $exp_info_text ) ) );
			printf( '</td></tr>' );
		}
	}

	/**
	 * Display license nag inside the URI Editor
	 *
	 */
	public static function license_info_uri_editor() {
		$exp_info_text = self::get_expiration_date( false, true, false );
		$exp_info_code = self::get_expiration_date( true, true, false );

		if ( ! empty( $exp_info_text ) && $exp_info_code == 2 ) {
			return sprintf( '<div class="notice inline notice-error notice-alt">%s</div>', wp_kses_post( wpautop( $exp_info_text ) ) );
		}

		return '';
	}

	/**
	 * Hide "Buy Permalink Manager Pro" alert
	 *
	 * @param array $alerts
	 *
	 * @return array
	 */
	function pro_alerts( $alerts = array() ) {
		// Check expiration date
		$exp_info_text = self::get_expiration_date( false, true, false );
		$exp_info_code = self::get_expiration_date( true, true, false );

		if ( ! empty( $exp_info_text ) && $exp_info_code >= 2 ) {
			$alerts['licence_key'] = array( 'txt' => $exp_info_text, 'type' => 'notice-error', 'plugin_only' => true, 'dismissed_time' => DAY_IN_SECONDS * 3 );
		}

		return $alerts;
	}
}
