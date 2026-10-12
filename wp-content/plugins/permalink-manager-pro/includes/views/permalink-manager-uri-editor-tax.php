<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Use WP_List_Table to display the "Bulk URI Editor" for post items
 */
class Permalink_Manager_Tax_Uri_Editor_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct( array(
			'singular' => 'slug',
			'plural'   => 'slugs'
		) );
	}

	/**
	 * Get the HTML output with the whole WP_List_Table
	 *
	 * @return string
	 */
	public function display_admin_section() {
		$output = "<form id=\"permalinks-post-types-table\" class=\"slugs-table\" method=\"post\">";
		$output .= wp_nonce_field( 'permalink-manager', 'uri_editor_nonce' );
		$output .= Permalink_Manager_UI_Elements::generate_option_field( 'pm_session_id', array( 'value' => uniqid(), 'type' => 'hidden' ) );
		$output .= Permalink_Manager_UI_Elements::section_type_field( 'taxonomies' );

		// Bypass
		ob_start();

		$this->prepare_items();
		$this->display();
		$output .= ob_get_contents();

		ob_end_clean();

		$output .= "</form>";

		return $output;
	}

	/**
	 * Return an array of classes to be used in the HTML table
	 *
	 * @return array
	 */
	function get_table_classes() {
		return array( 'widefat', 'striped', $this->_args['plural'] );
	}

	/**
	 * Add columns to the table
	 *
	 * @return array
	 */
	public function get_columns() {
		return apply_filters( 'permalink_manager_uri_editor_columns', array(
			'item_title' => __( 'Term title', 'permalink-manager' ),
			'item_uri'   => __( 'Custom permalink', 'permalink-manager' ),
			'count'      => __( 'Count', 'permalink-manager' ),
		) );
	}

	/**
	 * Sortable columns
	 */
	public function get_sortable_columns() {
		return array(
			'item_title' => array( 'name', false )
		);
	}

	/**
	 * Data inside the columns
	 */
	public function column_default( $item, $column_name ) {
		global $permalink_manager_options;

		$uri = Permalink_Manager_URI_Functions_Tax::get_term_uri( $item['term_id'], true );
		$uri = ( ! empty( $permalink_manager_options['general']['decode_uris'] ) ) ? urldecode( $uri ) : $uri;

		$field_args_base = array( 'type' => 'text', 'value' => $uri, 'without_label' => true, 'input_class' => 'custom_uri', 'extra_atts' => "data-element-id=\"tax-{$item['term_id']}\"" );
		$term            = get_term( $item['term_id'] );
		$permalink       = get_term_link( intval( $item['term_id'] ), $item['taxonomy'] );

		$output = apply_filters( 'permalink_manager_uri_editor_column_content', '', $column_name, $term );
		if ( ! empty( $output ) ) {
			return $output;
		}

		switch ( $column_name ) {

			case 'item_title':
				$term_title = sanitize_text_field( $item['name'] );

				$output = esc_html( $term_title );
				$output .= '<div class="extra-info small">';
				$output .= sprintf( "<span><strong>%s:</strong> %s</span>", __( "Slug", "permalink-manager" ), urldecode( $item['slug'] ) );
				$output .= apply_filters( 'permalink_manager_uri_editor_extra_info', '', $column_name, $term );
				$output .= '</div>';

				$output .= '<div class="row-actions">';
				$output .= sprintf( '<span class="edit"><a href="%1$s" title="%2$s">%2$s</a> | </span>', esc_url( get_edit_tag_link( $item['term_id'], $item['taxonomy'] ) ), __( 'Edit', 'permalink-manager' ) );
				$output .= sprintf( '<span class="view"><a target="_blank" href="%1$s" title="%2$s %3$s" rel="permalink">%2$s</a> | </span>', esc_attr( $permalink ), __( 'View', 'permalink-manager' ), esc_html( $term_title ) );
				$output .= sprintf( '<span class="id">#%s</span>', esc_html( $item['term_id'] ) );
				$output .= '</div>';

				return $output;

			case 'item_uri':
				// Get auto-update settings
				$auto_update_val = get_term_meta( $item['term_id'], "auto_update_uri", true );
				$auto_update_uri = ( ! empty( $auto_update_val ) ) ? $auto_update_val : $permalink_manager_options["general"]["auto_update_uris"];

				if ( $auto_update_uri == 1 ) {
					$field_args_base['readonly']       = true;
					$field_args_base['append_content'] = sprintf( '<p class="small uri_locked">%s %s</p>', '<span class="dashicons dashicons-lock"></span>', __( 'The above permalink will be automatically updated and is locked for editing.', 'permalink-manager' ) );
				} else if ( $auto_update_uri == 2 ) {
					$field_args_base['disabled']       = true;
					$field_args_base['append_content'] = sprintf( '<p class="small uri_locked">%s %s</p>', '<span class="dashicons dashicons-lock"></span>', __( 'URI Editor disabled due to "Permalink update" setting.', 'permalink-manager' ) );
				}

				$output .= '<div class="custom_uri_container">';
				$output .= Permalink_Manager_UI_Elements::generate_option_field( "uri[tax-{$item['term_id']}]", $field_args_base );
				$output .= "<span class=\"duplicated_uri_alert\"></span>";
				$output .= sprintf( "<a class=\"small post_permalink\" href=\"%s\" target=\"_blank\"><span class=\"dashicons dashicons-admin-links\"></span> %s</a>", $permalink, urldecode( $permalink ) );
				$output .= '</div>';

				return $output;

			case 'count':
				$all_terms_link = get_edit_term_link( $term, $item['taxonomy'] );
				$term_count     = ( ! empty( $term->count ) ) ? $term->count : 0;

				return sprintf( '<a href="%s">%s</a>', $all_terms_link, $term_count );

			default:
				return $item[ $column_name ];
		}
	}

	/**
	 * The button that allows to save updated slugs
	 */
	function extra_tablenav( $which ) {
		if ( $which == "top" ) {
			$button_text = __( 'Save all the permalinks below', 'permalink-manager' );
			$button_name = 'update_all_slugs[top]';
		} else {
			$button_text = __( 'Save all the permalinks above', 'permalink-manager' );
			$button_name = 'update_all_slugs[bottom]';
		}

		$html = '<div class="alignleft actions">';
		$html .= get_submit_button( $button_text, 'primary alignleft', $button_name, false, array( 'id' => 'doaction', 'value' => 'update_all_slugs' ) );
		$html .= '</div>';

		if ( $which == 'top' ) {
			$extra_fields = apply_filters( 'permalink_manager_uri_editor_extra_fields', '', 'taxonomies' );

			if ( $extra_fields ) {
				$html .= $extra_fields;

				$html .= '<div class="alignleft">';
				$html .= get_submit_button( __( "Filter", "permalink-manager" ), 'button', false, false, array( 'id' => 'filter-button', 'name' => 'filter-button' ) );
				$html .= "</div>";
			}

			$html .= '<div class="alignright">';
			$html .= $this->search_box( __( 'Search', 'permalink-manager' ), 'search-input' );
			$html .= '</div>';
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html;
	}

	/**
	 * Search box
	 */
	public function search_box( $text = '', $input_id = '' ) {
		$search_query = ( ! empty( $_REQUEST['s'] ) ) ? esc_attr( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) ) : ""; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only WP_List_Table search value; no state change.

		$output = "<p class=\"search-box\">";
		$output .= "<label class=\"screen-reader-text\" for=\"{$input_id}\">{$text}:</label>";
		$output .= Permalink_Manager_UI_Elements::generate_option_field( 's', array( 'value' => $search_query, 'type' => 'search' ) );
		$output .= get_submit_button( $text, 'button', false, false, array( 'id' => 'search-submit', 'name' => 'search-submit' ) );
		$output .= "</p>";

		return $output;
	}

	/**
	 * Prepare the items for the table to process
	 */
	public function prepare_items() {
		global $wpdb, $permalink_manager_admin_tax;

		$columns      = $this->get_columns();
		$hidden       = $this->get_hidden_columns();
		$sortable     = $this->get_sortable_columns();
		$current_page = $this->get_pagenum();

		// Get query variables
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only WP_List_Table search value; no state change.
		$taxonomies   = sprintf( "'%s'", $permalink_manager_admin_tax );
		$search_query = ( ! empty( $_REQUEST['s'] ) ) ? esc_sql( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) ) : "";

		// SQL query parameters
		$order   = ( isset( $_REQUEST['order'] ) && in_array( $_REQUEST['order'], array( 'asc', 'desc' ) ) ) ? sanitize_sql_orderby( wp_unslash( $_REQUEST['order'] ) ) : 'desc';
		$orderby = ( isset( $_REQUEST['orderby'] ) ) ? sanitize_sql_orderby( wp_unslash( $_REQUEST['orderby'] ) ) : 't.term_id';
		// phpcs:enable

		// Grab terms from database
		$sql_parts['start'] = "SELECT t.*, tt.taxonomy FROM {$wpdb->terms} AS t INNER JOIN {$wpdb->term_taxonomy} AS tt ON (tt.term_id = t.term_id) ";
		if ( $search_query ) {
			$sql_parts['where'] = "WHERE (LOWER(t.name) LIKE LOWER('%{$search_query}%') ";

			// Search in array with custom URIs
			$found = Permalink_Manager_URI_Functions::find_uri( $search_query, false, 'taxonomies' );
			if ( $found ) {
				$sql_parts['where'] .= sprintf( "OR t.term_id IN (%s) ", implode( ',', $found ) );
			}
			$sql_parts['where'] .= ") AND tt.taxonomy IN ({$taxonomies}) ";
		} else {
			$sql_parts['where'] = "WHERE tt.taxonomy IN ({$taxonomies}) ";
		}

		// Do not display excluded terms in Bulk URI Editor
		$excluded_terms = Permalink_Manager_Helper_Functions::get_excluded_term_ids();
		if ( ! empty( $excluded_terms ) ) {
			$sql_parts['where'] .= sprintf( "AND t.term_id NOT IN (%s) ", Permalink_Manager_Helper_Functions::prepare_array_for_sql_in( $excluded_terms ) );
		}

		$sql_parts['end'] = "ORDER BY {$orderby} {$order}";

		list( $all_items, $total_items, $per_page ) = Permalink_Manager_URI_Editor::prepare_sql_query( $sql_parts, $current_page, true );

		$this->set_pagination_args( array(
			'total_items' => $total_items,
			'per_page'    => $per_page
		) );

		$this->_column_headers = array( $columns, $hidden, $sortable );
		$this->items           = $all_items;
	}

	/**
	 * Define hidden columns
	 *
	 * @return array
	 */
	public function get_hidden_columns() {
		return array( 'post_date_gmt' );
	}

	/**
	 * Sort the data
	 *
	 * @param mixed $a
	 * @param mixed $b
	 *
	 * @return int
	 */
	private function sort_data( $a, $b ) {
		// Set defaults
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only WP_List_Table sort column; no state change.
		$orderby = ( ! empty( $_GET['orderby'] ) ) ? sanitize_sql_orderby( wp_unslash( $_GET['orderby'] ) ) : 'name';
		$order   = ( ! empty( $_GET['order'] ) ) ? sanitize_sql_orderby( wp_unslash( $_GET['order'] ) ) : 'asc';
		$result  = strnatcasecmp( $a[ $orderby ], $b[ $orderby ] );
		// phpcs:enable

		return ( $order === 'asc' ) ? $result : - $result;
	}

}