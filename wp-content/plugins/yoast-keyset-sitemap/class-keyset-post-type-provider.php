<?php
/**
 * Keyset (seek) pagination replacement for Yoast's post type sitemap provider.
 *
 * Subclasses the Yoast provider so all the URL/image/exclusion logic
 * (get_url, get_first_links, get_excluded_posts, get_sql_where_clause) is
 * inherited unchanged. Only the paging strategy is replaced.
 *
 * @package Yoast_Keyset_Sitemap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YKS_Post_Type_Sitemap_Provider extends WPSEO_Post_Type_Sitemap_Provider {

	/** @var array Per-request memo of post type counts. */
	private $count_cache = array();

	/* -------------------------------------------------------------------
	 * Accessors so the main plugin class can reach protected internals.
	 * ---------------------------------------------------------------- */

	public function public_post_type_count( $post_type ) {
		return $this->get_post_type_count( $post_type );
	}

	/**
	 * Fetch the single row sitting $offset rows after $cursor. Used only to
	 * locate page boundaries; $offset is always max_entries-1 (a bounded hop of
	 * one page), never a deep offset.
	 *
	 * @param string     $post_type Post type.
	 * @param array|null $cursor    array{m,id} to seek from, or null for the start.
	 * @param int        $offset    Rows to skip.
	 * @return object|null Row with post_modified, post_modified_gmt, ID.
	 */
	public function public_boundary_row( $post_type, $cursor, $offset ) {
		global $wpdb;

		$args  = array();
		$range = $this->range_sql( $cursor, null, $args );

		$sql = "
			SELECT {$wpdb->posts}.post_modified, {$wpdb->posts}.post_modified_gmt, {$wpdb->posts}.ID
			FROM {$wpdb->posts}
			{$this->join_filter( $post_type )}
			{$this->get_sql_where_clause( $post_type )}
				{$this->where_filter( $post_type )}
				{$range}
			ORDER BY {$wpdb->posts}.post_modified ASC, {$wpdb->posts}.ID ASC
			LIMIT 1 OFFSET %d
		";

		$args[] = (int) $offset;

		return $wpdb->get_row( $wpdb->prepare( $sql, $args ) );
	}

	/* -------------------------------------------------------------------
	 * Filter parts (cached exactly like the parent does)
	 * ---------------------------------------------------------------- */

	private function join_filter( $post_type ) {
		static $cache = array();
		if ( ! isset( $cache[ $post_type ] ) ) {
			$cache[ $post_type ] = apply_filters( 'wpseo_posts_join', false, $post_type );
		}
		return $cache[ $post_type ];
	}

	private function where_filter( $post_type ) {
		static $cache = array();
		if ( ! isset( $cache[ $post_type ] ) ) {
			$cache[ $post_type ] = apply_filters( 'wpseo_posts_where', false, $post_type );
		}
		return $cache[ $post_type ];
	}

	/**
	 * Build the keyset range predicate.
	 *
	 * Lower bound is EXCLUSIVE, upper bound INCLUSIVE, so page N is
	 * ( cursor[N], cursor[N+1] ] and consecutive pages tile without gap or
	 * overlap. The same exclusive-lower form is reused to continue between
	 * chunks inside a page, which is what removes OFFSET entirely.
	 *
	 * @param array|null $after  array{m,id} exclusive lower bound.
	 * @param array|null $upto   array{m,id} inclusive upper bound.
	 * @param array      $args   Collected prepare() args, appended to by reference.
	 * @return string SQL fragment.
	 */
	private function range_sql( $after, $upto, array &$args ) {
		global $wpdb;
		$sql = '';

		if ( $after ) {
			$sql   .= " AND ( {$wpdb->posts}.post_modified > %s"
				. " OR ( {$wpdb->posts}.post_modified = %s AND {$wpdb->posts}.ID > %d ) )";
			$args[] = $after['m'];
			$args[] = $after['m'];
			$args[] = (int) $after['id'];
		}

		if ( $upto ) {
			$sql   .= " AND ( {$wpdb->posts}.post_modified < %s"
				. " OR ( {$wpdb->posts}.post_modified = %s AND {$wpdb->posts}.ID <= %d ) )";
			$args[] = $upto['m'];
			$args[] = $upto['m'];
			$args[] = (int) $upto['id'];
		}

		return $sql;
	}

	/**
	 * Cache the COUNT(*): the parent runs it on every sitemap request, and
	 * get_index_links() runs one per post type.
	 */
	protected function get_post_type_count( $post_type ) {
		if ( ! isset( $this->count_cache[ $post_type ] ) ) {
			$this->count_cache[ $post_type ] = parent::get_post_type_count( $post_type );
		}
		return $this->count_cache[ $post_type ];
	}

	/* -------------------------------------------------------------------
	 * Fetching
	 * ---------------------------------------------------------------- */

	/**
	 * Retrieve one chunk of posts within a key range.
	 *
	 * Mirrors the parent's late-row-lookup shape (filter on the covering index
	 * in a subquery, then join back for the columns), but adds an outer ORDER BY
	 * -- the join does not preserve the subquery order, and we must know the
	 * true last row to continue the next chunk from it.
	 *
	 * @param string     $post_type Post type.
	 * @param array|null $after     Exclusive lower bound.
	 * @param array|null $upto      Inclusive upper bound.
	 * @param int        $limit     Chunk size.
	 * @return WP_Post[]
	 */
	protected function get_posts_range( $post_type, $after, $upto, $limit ) {
		global $wpdb;

		$args  = array();
		$range = $this->range_sql( $after, $upto, $args );

		// post_modified is added to the parent's column list: it is the cursor
		// key and we need it to continue from the last row of this chunk.
		$sql = "
			SELECT l.ID, l.post_title, l.post_content, l.post_name, l.post_parent,
			       l.post_author, l.post_status, l.post_modified, l.post_modified_gmt,
			       l.post_date, l.post_date_gmt
			FROM (
				SELECT {$wpdb->posts}.ID
				FROM {$wpdb->posts}
				{$this->join_filter( $post_type )}
				{$this->get_sql_where_clause( $post_type )}
					{$this->where_filter( $post_type )}
					{$range}
				ORDER BY {$wpdb->posts}.post_modified ASC, {$wpdb->posts}.ID ASC
				LIMIT %d
			) o
			JOIN {$wpdb->posts} l ON l.ID = o.ID
			ORDER BY l.post_modified ASC, l.ID ASC
		";

		$args[] = (int) $limit;

		$posts = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

		$post_ids = array();
		foreach ( $posts as $i => $post ) {
			$post->post_type = $post_type;
			$sanitized       = sanitize_post( $post, 'raw' );
			$posts[ $i ]     = new WP_Post( $sanitized );
			$post_ids[]      = $sanitized->ID;
		}

		update_meta_cache( 'post', $post_ids );

		return $posts;
	}

	/* -------------------------------------------------------------------
	 * Paging
	 * ---------------------------------------------------------------- */

	/**
	 * Build one sitemap page using the stored boundaries.
	 *
	 * @param string $type         Post type.
	 * @param int    $max_entries  Entries per page.
	 * @param int    $current_page 1-based page number.
	 * @return array
	 * @throws OutOfBoundsException When an invalid page is requested.
	 */
	public function get_sitemap_links( $type, $max_entries, $current_page ) {
		$post_type = $type;
		$links     = array();

		if ( ! $this->is_valid_post_type( $post_type ) ) {
			throw new OutOfBoundsException( 'Invalid sitemap page requested' );
		}

		$plugin = Yoast_Keyset_Sitemap::instance();
		$plugin->maybe_build( $post_type, $max_entries );

		$pages = $plugin->page_count( $post_type );

		if ( $current_page === 1 ) {
			$links = array_merge( $links, $this->get_first_links( $post_type ) );
		}

		if ( $plugin->total_count( $post_type ) === 0 ) {
			return $links;
		}

		if ( $current_page < 1 || $current_page > $pages ) {
			throw new OutOfBoundsException( 'Invalid sitemap page requested' );
		}

		$cursors = $plugin->cursors( $post_type );

		// Page N spans ( cursor[N], cursor[N+1] ]. Page 1 has no lower bound;
		// the last page has no upper bound -- which is what guarantees posts
		// that drifted past the final boundary still get emitted.
		$after = isset( $cursors[ $current_page ] ) ? $cursors[ $current_page ] : null;
		$upto  = isset( $cursors[ $current_page + 1 ] ) ? $cursors[ $current_page + 1 ] : null;

		$posts_to_exclude = $this->get_excluded_posts( $type );

		$chunk = Yoast_Keyset_Sitemap::CHUNK;

		while ( true ) {
			$posts = $this->get_posts_range( $post_type, $after, $upto, $chunk );

			if ( empty( $posts ) ) {
				break;
			}

			foreach ( $posts as $post ) {

				if ( in_array( $post->ID, $posts_to_exclude, true ) ) {
					continue;
				}

				if ( WPSEO_Meta::get_value( 'meta-robots-noindex', $post->ID ) === '1' ) {
					continue;
				}

				$url = $this->get_url( $post );

				if ( ! isset( $url['loc'] ) ) {
					continue;
				}

				/**
				 * Filter URL entry before it gets added to the sitemap.
				 *
				 * @param array  $url  Array of URL parts.
				 * @param string $type URL type.
				 * @param object $post Data object for the URL.
				 */
				$url = apply_filters( 'wpseo_sitemap_entry', $url, 'post', $post );

				if ( ! empty( $url ) ) {
					$links[] = $url;
				}
			}

			$last = end( $posts );

			// Chunk continues from the last row -- no OFFSET anywhere.
			$after = array(
				'm'  => $last->post_modified,
				'id' => (int) $last->ID,
			);

			unset( $post, $url );

			if ( count( $posts ) < $chunk ) {
				break;
			}
		}

		return $links;
	}

	/**
	 * Build the sitemap index from the cursor table rather than
	 * ceil( COUNT(*) / max_entries ).
	 *
	 * This keeps the index and the pages in agreement: both derive their page
	 * count from the same boundary snapshot, so the index can never advertise a
	 * page the provider would 404, or omit one that has content.
	 *
	 * It also drops Yoast's get_all_dates() query, which pins
	 * USE INDEX(type_status_date) while ordering by post_modified_gmt and
	 * therefore filesorts the whole post type.
	 *
	 * @param int $max_entries Entries per page.
	 * @return array
	 */
	public function get_index_links( $max_entries ) {
		$plugin     = Yoast_Keyset_Sitemap::instance();
		$post_types = WPSEO_Post_Type::get_accessible_post_types();
		$post_types = array_filter( $post_types, array( $this, 'is_valid_post_type' ) );

		$last_modified_times = WPSEO_Sitemaps::get_last_modified_gmt( $post_types, true );
		$index               = array();

		foreach ( $post_types as $post_type ) {

			$plugin->maybe_build( $post_type, $max_entries );

			$total = $plugin->total_count( $post_type );
			if ( $total === 0 ) {
				continue;
			}

			$pages   = max( 1, $plugin->page_count( $post_type ) );
			$cursors = $plugin->cursors( $post_type );

			for ( $page = 1; $page <= $pages; $page++ ) {

				// Yoast's lastmod for page N is the post_modified_gmt of that
				// page's LAST row -- which is exactly cursor[N+1]. The final
				// page has no boundary, so it uses the post type's max.
				if ( isset( $cursors[ $page + 1 ] ) ) {
					$date = $cursors[ $page + 1 ]['gmt'];
				} elseif ( ! empty( $last_modified_times[ $post_type ] ) ) {
					$date = $last_modified_times[ $post_type ];
				} else {
					$date = false;
				}

				$index[] = array(
					'loc'     => WPSEO_Sitemaps_Router::get_base_url(
						$post_type . '-sitemap' . ( ( $page === 1 ) ? '' : $page ) . '.xml'
					),
					'lastmod' => $date,
				);
			}
		}

		return $index;
	}
}
