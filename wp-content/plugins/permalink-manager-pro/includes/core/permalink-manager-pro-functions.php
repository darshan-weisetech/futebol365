<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Additional hooks for "Permalink Manager Pro"
 */
class Permalink_Manager_Pro_Functions {

	public function __construct() {
		// Stop words
		add_filter( 'permalink_manager_filter_default_post_uri', array( $this, 'remove_stop_words' ), 9, 3 );
		add_filter( 'permalink_manager_filter_default_term_uri', array( $this, 'remove_stop_words' ), 9, 3 );

		// Custom fields in permalinks
		add_filter( 'permalink_manager_filter_default_post_uri', array( $this, 'replace_custom_field_tags' ), 9, 5 );
		add_filter( 'permalink_manager_filter_default_term_uri', array( $this, 'replace_custom_field_tags' ), 9, 5 );

		// Save redirects
		add_action( 'permalink_manager_updated_post_uri', array( $this, 'save_redirects' ), 9, 5 );
		add_action( 'permalink_manager_updated_term_uri', array( $this, 'save_redirects' ), 9, 5 );
	}

	/**
	 * Get the list of languages where stop words are defined
	 */
	static function load_stop_words_languages() {
		return array(
			'ar' => __( 'Arabic', 'permalink-manager' ),
			'zh' => __( 'Chinese', 'permalink-manager' ),
			'da' => __( 'Danish', 'permalink-manager' ),
			'nl' => __( 'Dutch', 'permalink-manager' ),
			'en' => __( 'English', 'permalink-manager' ),
			'fi' => __( 'Finnish', 'permalink-manager' ),
			'fr' => __( 'French', 'permalink-manager' ),
			'de' => __( 'German', 'permalink-manager' ),
			'he' => __( 'Hebrew', 'permalink-manager' ),
			'hi' => __( 'Hindi', 'permalink-manager' ),
			'it' => __( 'Italian', 'permalink-manager' ),
			'ja' => __( 'Japanese', 'permalink-manager' ),
			'ko' => __( 'Korean', 'permalink-manager' ),
			'no' => __( 'Norwegian', 'permalink-manager' ),
			'fa' => __( 'Persian', 'permalink-manager' ),
			'pl' => __( 'Polish', 'permalink-manager' ),
			'pt' => __( 'Portuguese', 'permalink-manager' ),
			'ru' => __( 'Russian', 'permalink-manager' ),
			'es' => __( 'Spanish', 'permalink-manager' ),
			'sv' => __( 'Swedish', 'permalink-manager' ),
			'tr' => __( 'Turkish', 'permalink-manager' )
		);
	}

	/**
	 * Load stop words for specific language (using ISO code)
	 *
	 * @param string $iso
	 *
	 * @return array
	 */
	static function load_stop_words( $iso = '' ) {
		$json_dir = PERMALINK_MANAGER_DIR . "/includes/vendor/stopwords-json/dist/{$iso}.json";
		$json_a   = array();

		if ( file_exists( $json_dir ) ) {
			$string = file_get_contents( $json_dir );
			$json_a = json_decode( $string, true );
		}

		return $json_a;
	}

	/**
	 * Remove the stop words from the default custom permalinks
	 *
	 * @param string $slug The slug that is being generated.
	 * @param stdClass $object The object that the slug is being generated for.
	 * @param string $name The name of the filter.
	 *
	 * @return string The slug.
	 */
	public function remove_stop_words( $slug, $object, $name ) {
		global $permalink_manager_options;

		if ( ! empty( $permalink_manager_options['stop-words']['stop-words-enable'] ) && ! empty( $permalink_manager_options['stop-words']['stop-words-list'] ) ) {
			$stop_words = explode( ",", strtolower( stripslashes( $permalink_manager_options['stop-words']['stop-words-list'] ) ) );

			foreach ( $stop_words as $stop_word ) {
				$stop_word = trim( $stop_word );
				$slug      = preg_replace( "/([\/-]|^)({$stop_word})([\/-]|$)/", '$1$3', $slug );
			}

			// Clear the slug
			$slug = preg_replace( "/(-+)/", "-", trim( $slug, "-" ) );
			$slug = preg_replace( "/(-\/-)|(\/-)|(-\/)/", "/", $slug );
		}

		return $slug;
	}

	/**
	 * Replace custom field tags in the default custom permalinks
	 *
	 * @param string $default_uri
	 * @param string $native_slug
	 * @param WP_Post|WP_Term $element
	 * @param string $slug
	 * @param string $native_uri
	 *
	 * @return string
	 */
	function replace_custom_field_tags( $default_uri, $native_slug, $element, $slug, $native_uri ) {
		// Do not affect native URIs
		if ( $native_uri ) {
			return $default_uri;
		}

		preg_match_all( "/%__(.[^\%]+)%/", $default_uri, $custom_fields );

		if ( ! empty( $custom_fields[1] ) ) {
			foreach ( $custom_fields[1] as $i => $custom_field ) {
				// Reset custom field value
				$custom_field_value = "";

				// Check additional arguments (__custom_field.argument_value)
				if ( strpos( $custom_field, '.' ) !== false ) {
					$custom_field_split = preg_split( '/[\.]/', $custom_field );

					if ( ! empty( $custom_field_split[1] ) ) {
						$custom_field_arg = $custom_field_split[1];
						$custom_field     = $custom_field_split[0];
					}
				}

				// 1. Use WooCommerce fields (SKU)
				if ( class_exists( 'WooCommerce' ) && strtolower( $custom_field ) == 'sku' && ! empty( $element->ID ) ) {
					$product = wc_get_product( $element->ID );

					$custom_field_value = ( is_a( $product, 'WC_Product' ) ) ? $product->get_sku() : '';
				} // 2. Try to get value using ACF API
				else if ( function_exists( 'get_field_object' ) ) {
					$acf_element_id = ( ! empty( $element->ID ) ) ? $element->ID : "{$element->taxonomy}_{$element->term_id}";
					$field_object   = get_field_object( $custom_field, $acf_element_id );

					// A. Relationship field
					if ( ! empty( $field_object['type'] ) && ( in_array( $field_object['type'], array( 'relationship', 'post_object', 'taxonomy' ) ) ) && ! empty( $field_object['value'] ) ) {
						$rel_elements = $field_object['value'];

						// B1. Terms
						if ( $field_object['type'] == 'taxonomy' ) {
							if ( ( is_array( $rel_elements ) ) ) {
								if ( is_numeric( $rel_elements[0] ) && ! empty( $field_object['taxonomy'] ) ) {
									$rel_elements = get_terms( array( 'include' => $rel_elements, 'taxonomy' => $field_object['taxonomy'], 'hide_empty' => false, 'orderby' => 'term_id', 'order' => 'DESC' ) );
								}

								// Get the lowest term
								if ( ! is_wp_error( $rel_elements ) && ! empty( $rel_elements ) && is_object( $rel_elements[0] ) ) {
									$rel_term = Permalink_Manager_Helper_Functions::get_lowest_element( $rel_elements[0], $rel_elements );
								}
							}
							if ( ! empty( $rel_elements->term_id ) ) {
								$rel_term = $rel_elements;
							} else if ( ! empty( $rel_elements ) && is_numeric( $rel_elements ) ) {
								$rel_term = get_term( $rel_elements, $field_object['taxonomy'] );
							}

							// Get the replacement slug
							if ( ! empty( $rel_term->term_id ) ) {
								if ( ! empty( $custom_field_arg ) && is_numeric( $custom_field_arg ) ) {
									$custom_field_value = Permalink_Manager_Helper_Functions::force_custom_slugs( $rel_term->slug, $rel_term, false, $custom_field_arg );
								} else if ( ! empty( $custom_field_arg ) && $custom_field_arg == 'id' ) {
									$custom_field_value = $rel_term->term_id;
								} else if ( ! empty( $custom_field_arg ) && $custom_field_arg == 'full_slug' ) {
									$custom_field_value = Permalink_Manager_Helper_Functions::get_term_full_slug( $rel_term, $rel_elements );
								} else if ( ! empty( $custom_field_arg ) && $custom_field_arg == 'custom_permalink' ) {
									$custom_field_value = Permalink_Manager_URI_Functions::get_single_uri( $rel_term );
								} else {
									$custom_field_value = Permalink_Manager_Helper_Functions::force_custom_slugs( $rel_term->slug, $rel_term );
								}
							}
						} // B2. Posts
						else {
							if ( ( is_array( $rel_elements ) ) ) {
								if ( is_numeric( $rel_elements[0] ) ) {
									$rel_elements = get_posts( array( 'include' => $rel_elements, 'post_type' => 'any', 'orderby' => 'post__in' ) );
								}

								// Get lowest element
								if ( ! is_wp_error( $rel_elements ) && ! empty( $rel_elements ) && is_object( $rel_elements[0] ) ) {
									$rel_post = Permalink_Manager_Helper_Functions::get_lowest_element( $rel_elements[0], $rel_elements );
								}
							} else if ( ! empty( $rel_elements->ID ) ) {
								$rel_post = $rel_elements;
							}

							if ( ! empty( $rel_post->ID ) ) {
								$rel_post_object = $rel_post;
							} else if ( is_numeric( $rel_elements ) ) {
								$rel_post_object = get_post( $rel_elements );
							}

							// Get the replacement slug
							if ( ! empty( $rel_post_object->ID ) ) {
								if ( ! empty( $custom_field_arg ) && is_numeric( $custom_field_arg ) ) {
									$custom_field_value = Permalink_Manager_Helper_Functions::force_custom_slugs( $rel_post_object->post_name, $rel_post_object, false, $custom_field_arg );
								} else if ( ! empty( $custom_field_arg ) && $custom_field_arg == 'id' ) {
									$custom_field_value = $rel_post_object->ID;
								} else if ( ! empty( $custom_field_arg ) && $custom_field_arg == 'full_slug' ) {
									$custom_field_value = Permalink_Manager_Helper_Functions::get_post_full_slug( $rel_post_object );
								} else if ( ! empty( $custom_field_arg ) && $custom_field_arg == 'custom_permalink' ) {
									$custom_field_value = Permalink_Manager_URI_Functions::get_single_uri( $rel_post_object );
								} else {
									$custom_field_value = Permalink_Manager_Helper_Functions::force_custom_slugs( $rel_post_object->post_name, $rel_post_object );
								}
							}
						}
					} // C. Text field
					else {
						$custom_field_value = ( ! empty( $field_object['value'] ) ) ? $field_object['value'] : "";
						$custom_field_value = ( ! empty( $custom_field_value['value'] ) ) ? $custom_field_value['value'] : $custom_field_value;
					}
				}

				// 3. Use native method
				if ( empty( $custom_field_value ) ) {
					if ( ! empty( $element->ID ) ) {
						$custom_field_value = get_post_meta( $element->ID, $custom_field, true );

						// Toolset
						if ( empty( $custom_field_value ) && ( defined( 'TYPES_VERSION' ) || defined( 'WPCF_VERSION' ) ) ) {
							$custom_field_value = get_post_meta( $element->ID, "wpcf-{$custom_field}", true );
						}
					} else if ( ! empty( $element->term_id ) ) {
						$custom_field_value = get_term_meta( $element->term_id, $custom_field, true );
					} else {
						$custom_field_value = "";
					}
				}

				// Allow to filter the custom field value
				$custom_field_value = apply_filters( 'permalink_manager_custom_field_value', $custom_field_value, $custom_field, $element );

				// Make sure that custom field is a string
				if ( ! empty( $custom_field_value ) && is_string( $custom_field_value ) ) {
					// Do not sanitize the custom field if 'no-sanitize' argument is added (e.g. %__custom-field-name.no-sanitize%)
					if ( empty( $custom_field_arg ) || strpos( $custom_field_arg, 'no-sanitize' ) === false ) {
						$custom_field_value = Permalink_Manager_Helper_Functions::sanitize_title( $custom_field_value );
					}

					$default_uri = str_replace( $custom_fields[0][ $i ], $custom_field_value, $default_uri );
				}
			}
		}

		return $default_uri;
	}


	/**
	 * Save the custom, external redirects for specific post or term
	 *
	 * @param int|string $element_id The ID of the post (e.g. 123) or term (tax-456).
	 * @param string $new_uri The new custom permalink
	 * @param string $old_uri The previous custom permalink
	 * @param string $native_uri The native permalink
	 * @param string $default_uri The default custom permalink
	 */
	public function save_redirects( $element_id, $new_uri, $old_uri, $native_uri = '', $default_uri = '' ) {
		global $permalink_manager_options, $permalink_manager_redirects;

		// Do not trigger if "Extra redirects" option is turned off
		if ( empty( $permalink_manager_options['general']['redirect'] ) || empty( $permalink_manager_options['general']['extra_redirects'] ) ) {
			return;
		}

		// Terms IDs should be prepended with prefix
		$element_id = ( current_filter() == 'permalink_manager_updated_term_uri' ) ? "tax-{$element_id}" : $element_id;

		// Make sure that $permalink_manager_redirects variable is an array
		$permalink_manager_redirects = ( is_array( $permalink_manager_redirects ) ) ? $permalink_manager_redirects : array();

		// 1A. Post/term is saved or updated
		if ( isset( $_POST['permalink-manager-redirects'] ) && is_array( $_POST['permalink-manager-redirects'] ) ) {
			$permalink_manager_redirects[ $element_id ] = array_filter( $_POST['permalink-manager-redirects'] );
			$redirects_updated                          = true;
		} // 1B. All redirects are removed
		else if ( isset( $_POST['permalink-manager-redirects'] ) ) {
			$permalink_manager_redirects[ $element_id ] = array();
			$redirects_updated                          = true;
		}

		// 1C. No longer needed
		if ( isset( $_POST['permalink-manager-redirects'] ) ) {
			unset( $_POST['permalink-manager-redirects'] );
		}

		// 2. Custom URI is updated
		if ( get_option( 'page_on_front' ) !== $element_id && ! empty( $permalink_manager_options['general']['setup_redirects'] ) && ( $new_uri !== $old_uri ) ) {
			// Make sure that the array with redirects exists
			$permalink_manager_redirects[ $element_id ] = ( ! empty( $permalink_manager_redirects[ $element_id ] ) ) ? $permalink_manager_redirects[ $element_id ] : array();

			// Append the old custom URI
			$permalink_manager_redirects[ $element_id ][] = $old_uri;
			$redirects_updated                            = true;
		}

		// 3. Save the custom redirects
		if ( ! empty( $redirects_updated ) && is_array( $permalink_manager_redirects[ $element_id ] ) ) {
			// Remove empty redirects
			$permalink_manager_redirects[ $element_id ] = array_filter( $permalink_manager_redirects[ $element_id ] );

			// Sanitize the array with redirects
			foreach ( $permalink_manager_redirects[ $element_id ] as $i => $redirect ) {
				$redirect                                         = rawurldecode( $redirect );
				$redirect                                         = Permalink_Manager_Helper_Functions::sanitize_title( $redirect, true );
				$permalink_manager_redirects[ $element_id ][ $i ] = $redirect;
			}

			// Reset the keys
			$permalink_manager_redirects[ $element_id ] = array_values( $permalink_manager_redirects[ $element_id ] );

			// Remove the duplicates
			$permalink_manager_redirects[ $element_id ] = array_unique( $permalink_manager_redirects[ $element_id ] );

			Permalink_Manager_Actions::clear_single_element_duplicated_redirect( $element_id, false, $new_uri );

			// Remove empty subarray
			$permalink_manager_redirects = array_filter( $permalink_manager_redirects );

			update_option( 'permalink-manager-redirects', $permalink_manager_redirects );
		}

		// 4. Save the external redirect
		if ( isset( $_POST['permalink-manager-external-redirect'] ) ) {
			self::save_external_redirect( $_POST['permalink-manager-external-redirect'], $element_id );
		}
	}

	/**
	 * Save external redirect
	 *
	 * @param string $url The full URL saved as external redirect
	 * @param string|int $element_id The ID of the post (e.g. 123) or term (tax-456).
	 */
	public static function save_external_redirect( $url, $element_id ) {
		global $permalink_manager_external_redirects;

		$url = filter_var( $url, FILTER_SANITIZE_URL );

		if ( ( empty( $url ) || filter_var( $url, FILTER_VALIDATE_URL ) === false ) && ! empty( $permalink_manager_external_redirects[ $element_id ] ) && isset( $_POST['permalink-manager-external-redirect'] ) ) {
			unset( $permalink_manager_external_redirects[ $element_id ] );
		} else {
			$permalink_manager_external_redirects[ $element_id ] = esc_url( $url );
		}

		update_option( 'permalink-manager-external-redirects', $permalink_manager_external_redirects );
	}

	/**
	 * Add a new tab to the WooCommerce coupon edit page
	 *
	 * @param array $tabs The tabs array.
	 *
	 * @return array The tabs array with the new tab added.
	 */

	public static function woocommerce_coupon_tabs( $tabs = array() ) {
		$tabs['coupon-url'] = array(
			'label'  => __( 'Coupon Link', 'permalink-manager' ),
			'target' => 'permalink-manager-coupon-url',
			'class'  => 'permalink-manager-coupon-url',
		);

		return $tabs;
	}

	/**
	 * Add a new panel to the WooCommerce coupon edit page
	 */
	public static function woocommerce_coupon_panel() {
		global $post;

		$custom_uri = Permalink_Manager_URI_Functions::get_single_uri( $post->ID, false, true, false );

		$html = "<div id=\"permalink-manager-coupon-url\" class=\"panel woocommerce_options_panel custom_uri_container permalink-manager\">";

		// URI field
		ob_start();
		wp_nonce_field( 'permalink-manager-coupon-uri-box', 'permalink-manager-nonce' );

		woocommerce_wp_text_input( array(
			'id'                => 'custom_uri',
			'label'             => __( 'Coupon URI', 'permalink-manager' ),
			'description'       => '<span class="duplicated_uri_alert"></span>' . __( 'The URIs are case-insensitive, e.g. <strong>BLACKFRIDAY</strong> and <strong>blackfriday</strong> are equivalent.', 'permalink-manager' ),
			'value'             => $custom_uri,
			'custom_attributes' => array( 'data-element-id' => $post->ID ),
			//'desc_tip' => true
		) );

		$html .= ob_get_contents();
		ob_end_clean();

		// URI preview
		$is_hidden = ( ! empty( $custom_uri ) ) ? "" : "hidden";
		$html      .= sprintf( '<p class="form-field coupon-full-url %s">', $is_hidden );
		$html      .= sprintf( '<label>%s</label>', __( "Coupon Full URL", "permalink-manager" ) );
		$html      .= sprintf( '<span class="description"><code>%s/<span>%s</span></code></span>', trim( get_option( 'home' ), "/" ), $custom_uri );
		$html      .= "</p>";

		$html .= "</div>";

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html;
	}

	/**
	 * Save the custom permalink for specific coupon
	 *
	 * @param int $post_id
	 * @param WC_Coupon $coupon
	 */
	public static function woocommerce_save_coupon_uri( $post_id, $coupon ) {
		// Verify nonce at first
		if ( ! isset( $_POST['permalink-manager-nonce'] ) || ! wp_verify_nonce( $_POST['permalink-manager-nonce'], 'permalink-manager-coupon-uri-box' ) ) {
			return;
		}

		// Do not do anything if post is auto-saved
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$old_uri = Permalink_Manager_URI_Functions::get_single_uri( $post_id, false, true, false );
		$new_uri = ( ! empty( $_POST['custom_uri'] ) ) ? $_POST['custom_uri'] : "";

		if ( $old_uri !== $new_uri ) {
			Permalink_Manager_URI_Functions::save_single_uri( $post_id, $new_uri, false, true );
		}
	}

	/**
	 * Check the URL contains a coupon code, and if it does, it adds the coupon code to the cart and redirects to the cart page
	 *
	 * @param array $query The query array.
	 *
	 * @return array The query array.
	 */
	public static function woocommerce_detect_coupon_code( $query ) {
		global $woocommerce, $pm_query;

		// Check if custom URI with coupon URL is requested
		if ( ! empty( $query['shop_coupon'] ) && ! empty( $pm_query['id'] ) ) {
			// Check if cart/shop page is set & redirect to it
			$shop_page_id = wc_get_page_id( 'shop' );
			$cart_page_id = wc_get_page_id( 'cart' );


			if ( ! empty( $cart_page_id ) && WC()->cart->get_cart_contents_count() > 0 ) {
				$redirect_page = $cart_page_id;
			} else if ( ! empty( $shop_page_id ) ) {
				$redirect_page = $shop_page_id;
			}

			$coupon_code = get_the_title( $pm_query['id'] );

			// Set-up session
			if ( ! WC()->session->has_session() ) {
				WC()->session->set_customer_session_cookie( true );
			}

			// Add the discount code
			if ( ! WC()->cart->has_discount( $coupon_code ) ) {
				$woocommerce->cart->add_discount( sanitize_text_field( $coupon_code ) );
			}

			// Do redirect
			if ( ! empty( $redirect_page ) ) {
				wp_safe_redirect( get_permalink( $redirect_page ) );
				exit();
			}

		}

		return $query;
	}

}
