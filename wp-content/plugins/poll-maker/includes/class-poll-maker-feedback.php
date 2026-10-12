<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Poll_Maker_Feedback {

	/**
	 * API feedback URL.
	 *
	 * Holds the URL of the feedback API.
	 *
	 * @access private
	 * @static
	 *
	 * @var string API feedback URL.
	 */
	private static $api_feedback_url = 'https://poll-plugin.com/poll-maker/feedback/';

	/**
	 * @since 1.0.0
	 * @access public
	 */
	public function __construct() {
		add_action( 'current_screen', function () {
			if ( ! $this->is_plugins_screen() ) {
				return;
			}

			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_feedback_dialog_scripts' ) );
		} );

		// Ajax.
		add_action( 'wp_ajax_ays_poll_deactivate_feedback', array( $this, 'ays_poll_deactivate_feedback' ) );
		add_action( 'wp_ajax_nopriv_ays_poll_deactivate_feedback', array( $this, 'ays_poll_deactivate_feedback' ) );
	}

	/**
	 * Get module name.
	 *
	 * Retrieve the module name.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return string Module name.
	 */
	public function get_name() {
		return 'feedback';
	}

	/**
	 * Enqueue feedback dialog scripts.
	 *
	 * Registers the feedback dialog scripts and enqueues them.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function enqueue_feedback_dialog_scripts() {
		add_action( 'admin_footer', array( $this, 'print_deactivate_feedback_dialog' ) );
	}

	/**
	 * Print deactivate feedback dialog.
	 *
	 * Display a dialog box to ask the user why he deactivated Poll Maker.
	 *
	 * Fired by `admin_footer` filter.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function print_deactivate_feedback_dialog() {
		$deactivate_reasons = array(
			'no_longer_needed' => array(
				'title' => esc_html__( 'I no longer need the plugin', 'poll-maker' ),
				'input_placeholder' => '',
			),
			'found_a_better_plugin' => array(
				'title' => esc_html__( 'I found a better alternative', 'poll-maker' ),
				'input_placeholder' => esc_html__( 'Other', 'poll-maker' ),
				'sub_reason' => array(
					'forminator_forms' 	=> esc_html__( 'Forminator Forms', 'poll-maker' ),
					'wp_polls' 			=> esc_html__( 'WP-Polls', 'poll-maker' ),
					'formidable_forms' 	=> esc_html__( 'Formidable Forms', 'poll-maker' ),
					'ts_poll' 			=> esc_html__( 'TS Poll', 'poll-maker' ),
					'yop_poll' 		    => esc_html__( 'YOP Poll', 'poll-maker' ),
				),
			),
			'couldnt_get_the_plugin_to_work' => array(
				'title' => esc_html__( "The plugin didn’t work as expected", 'poll-maker' ),
				'input_placeholder' => '',
			),
			'missing_features' => array(
				'title' => esc_html__( 'Missing essential features', 'poll-maker' ),
				'input_placeholder' => esc_html__( 'Please share which features', 'poll-maker' ),
			),
			'temporary_deactivation' => array(
				'title' => esc_html__( "I only needed it temporarily", 'poll-maker' ),
				'input_placeholder' => '',
			),
			'plugin_or_theme_conflict' => array(
				'title' => esc_html__( "Conflicts with other plugins or themes", 'poll-maker' ),
				// 'input_placeholder' => esc_html__( 'Please share which plugin or theme', 'poll-maker' ),
				'input_placeholder' => '',
				'alert' => sprintf( __("Contact our %s support team %s to find and fix the issue.", 'poll-maker'),
                                    "<a href='https://ays-pro.com/contact' target='_blank'>",
                                    "</a>"
                                ),
			),
			'poll_pro' => array(
				'title' => esc_html__( 'I’m using the premium version now', 'poll-maker' ),
				'input_placeholder' => '',
				// 'alert' => esc_html__( "Wait! Don't deactivate Poll Maker. You have to activate both Poll Maker and Poll Maker Pro in order for the plugin to work.", 'poll-maker' ),
			),
			'other' => array(
				'title' => esc_html__( 'Other', 'poll-maker' ),
				'input_placeholder' => esc_html__( 'Please share the reason', 'poll-maker' ),
			),
		);

		$poll_deactivate_feedback_nonce = wp_create_nonce( 'ays_poll_deactivate_feedback_nonce' );

		?>
		<div class="ays-poll-dialog-widget ays-poll-dialog-lightbox-widget ays-poll-dialog-type-buttons ays-poll-dialog-type-lightbox" id="ays-poll-deactivate-feedback-modal" aria-modal="true" role="document" tabindex="0" style="display: none;">
		    <div class="ays-poll-dialog-widget-content ays-poll-dialog-lightbox-widget-content">
		        <div class="ays-poll-dialog-header ays-poll-dialog-lightbox-header">
		            <div id="ays-poll-deactivate-feedback-dialog-header">
						<img class="ays-poll-dialog-logo" src="<?php echo esc_url( POLL_MAKER_AYS_ADMIN_URL . '/images/icons/icon-poll-128x128.png' ); ?>" alt="<?php echo esc_attr( __( "Poll Maker", 'poll-maker' ) ); ?>" title="<?php echo esc_attr( __( "Poll Maker", 'poll-maker' ) ); ?>" width="20" height="20"/>
						<span id="ays-poll-deactivate-feedback-dialog-header-title"><?php echo esc_html__( 'Quick Feedback', 'poll-maker' ); ?></span>
					</div>
		        </div>
		        <div class="ays-poll-dialog-message ays-poll-dialog-lightbox-message">
					<form id="ays-poll-deactivate-feedback-dialog-form" method="post">
						<input type="hidden" id="ays_poll_deactivate_feedback_nonce" name="ays_poll_deactivate_feedback_nonce" value="<?php echo esc_attr($poll_deactivate_feedback_nonce) ; ?>">
						<input type="hidden" name="action" value="ays_poll_deactivate_feedback" />

						<div id="ays-poll-deactivate-feedback-dialog-form-caption"><?php echo esc_html__( 'If you have a moment, please share why you are deactivating Poll Maker:', 'poll-maker' ); ?></div>
						<div id="ays-poll-deactivate-feedback-dialog-form-body">
							<?php foreach ( $deactivate_reasons as $reason_key => $reason ) : ?>
								<div class="ays-poll-deactivate-feedback-dialog-input-wrapper">
									<input id="ays-poll-deactivate-feedback-<?php echo esc_attr( $reason_key ); ?>" class="ays-poll-deactivate-feedback-dialog-input" type="radio" name="ays_poll_reason_key" value="<?php echo esc_attr( $reason_key ); ?>" />
									<label for="ays-poll-deactivate-feedback-<?php echo esc_attr( $reason_key ); ?>" class="ays-poll-deactivate-feedback-dialog-label"><?php echo esc_html( $reason['title'] ); ?>
									<?php if ( ! empty( $reason['input_placeholder'] ) && empty( $reason['sub_reason'] ) ) : ?>
										<input class="ays-poll-feedback-text" type="text" name="ays_poll_reason_<?php echo esc_attr( $reason_key ); ?>" placeholder="<?php echo esc_attr( $reason['input_placeholder'] ); ?>" />
									<?php endif; ?>
									<?php if ( ! empty( $reason['alert'] ) ) : ?>
										<div class="ays-poll-feedback-text ays-poll-feedback-text-color"><?php echo wp_kses_post( $reason['alert'] ); ?></div>
									<?php endif; ?>
									<?php if ( ! empty( $reason['sub_reason'] ) && is_array($reason['sub_reason']) ) : ?>
										<div class="ays-poll-deactivate-feedback-sub-dialog-input-wrapper">
										<?php foreach ( $reason['sub_reason'] as $sub_reason_key => $sub_reason ) : ?>
											<div class="ays-poll-deactivate-feedback-dialog-input-wrapper">
												<input id="ays-poll-deactivate-feedback-sub-<?php echo esc_attr( $sub_reason_key ); ?>" class="ays-poll-deactivate-feedback-dialog-input" type="radio" name="ays_poll_sub_reason_key" value="<?php echo esc_attr( $sub_reason_key ); ?>" />
												<label for="ays-poll-deactivate-feedback-sub-<?php echo esc_attr( $sub_reason_key ); ?>" class="ays-poll-deactivate-feedback-dialog-label"><?php echo esc_html( $sub_reason ); ?>
												</label>
											</div>
										<?php endforeach; ?>
										</div>
										<?php if ( ! empty( $reason['input_placeholder'] ) ) : ?>
											<input class="ays-poll-feedback-text" type="text" name="ays_poll_reason_<?php echo esc_attr( $reason_key ); ?>" placeholder="<?php echo esc_attr( $reason['input_placeholder'] ); ?>" />
										<?php endif; ?>
									<?php endif; ?>
									</label>
								</div>
							<?php endforeach; ?>
						</div>
					</form>
		        </div>
		        <div class="ays-poll-dialog-buttons-wrapper ays-poll-dialog-lightbox-buttons-wrapper">
		            <button class="ays-poll-dialog-button ays-poll-dialog-skip ays-poll-dialog-lightbox-skip" data-type="skip"><?php echo esc_html__( 'Skip &amp; Deactivate', 'poll-maker' ); ?></button>
		            <button class="ays-poll-dialog-button ays-poll-dialog-submit ays-poll-dialog-lightbox-submit" data-type="submit"><?php echo esc_html__( 'Submit &amp; Deactivate', 'poll-maker' ); ?></button>
		        </div>
    		</div>
		</div>
		<?php
	}

	/**
	 * Ajax Poll Maker deactivate feedback.
	 *
	 * Send the user feedback when Poll Maker is deactivated.
	 *
	 * Fired by `wp_ajax_ays_poll_deactivate_feedback` action.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function ays_poll_deactivate_feedback() {

		if ( empty($_REQUEST['ays_poll_deactivate_feedback_nonce']) ) {
			wp_send_json_error();
		}

		// Run a security check.
        check_ajax_referer( 'ays_poll_deactivate_feedback_nonce', sanitize_key( $_REQUEST['_ajax_nonce'] ) );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		if (empty($_REQUEST['action']) || (isset($_REQUEST['action']) && $_REQUEST['action'] != 'ays_poll_deactivate_feedback')) {
			wp_send_json_error( 'Action error' );
		}

		$reason_key = !empty($_REQUEST['ays_poll_reason_key']) ? sanitize_text_field($_REQUEST['ays_poll_reason_key']) : "";
		$sub_reason_key = !empty($_REQUEST['ays_poll_sub_reason_key']) ? sanitize_text_field($_REQUEST['ays_poll_sub_reason_key']) : "";
		$reason_text = !empty($_REQUEST["ays_poll_reason_{$reason_key}"]) ? sanitize_text_field($_REQUEST["ays_poll_reason_{$reason_key}"]) : "";
		$type = !empty($_REQUEST["type"]) ? sanitize_text_field($_REQUEST["type"]) : "";

		self::send_feedback( $reason_key, $sub_reason_key, $reason_text, $type );

		wp_send_json_success();
	}

	/**
	 * @since 1.0.0
	 * @access private
	 */
	private function is_plugins_screen() {
		return in_array( get_current_screen()->id, array( 'plugins', 'plugins-network' ) );
	}

	/**
	 * Send Feedback.
	 *
	 * Fires a request to Poll Maker server with the feedback data.
	 *
	 * @since 1.0.0
	 * @access public
	 * @static
	 *
	 * @param string $feedback_key  Feedback key.
	 * @param string $feedback_text Feedback text.
	 *
	 * @return array The response of the request.
	 */
	public static function send_feedback( $feedback_key, $sub_feedback_key, $feedback_text, $type ) {
		return wp_remote_post( self::$api_feedback_url, array(
			'timeout' => 30,
			'body' => wp_json_encode(array(
				'type' 				=> 'poll-maker',
				'version' 			=> POLL_MAKER_AYS_VERSION,
				'site_lang' 		=> get_bloginfo( 'language' ),
				'button' 			=> $type,
				'feedback_key' 		=> $feedback_key,
				'sub_feedback_key' 	=> $sub_feedback_key,
				'feedback' 			=> $feedback_text,
			)),
		) );
	}
}
new Poll_Maker_Feedback();
