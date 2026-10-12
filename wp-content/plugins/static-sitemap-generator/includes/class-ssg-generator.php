<?php
/**
 * Streaming writer for one sub-sitemap file (temp file + atomic rename, optional gzip).
 */
class SSG_Bucket_Writer {

	private $handle;
	private $tmp;
	private $final;
	private $gzip;
	private $count = 0;

	public function __construct( $final_path, $gzip = false ) {
		$this->final = $final_path;
		$this->gzip  = (bool) $gzip;
		$this->tmp   = $final_path . '.tmp-' . getmypid() . '-' . uniqid( '', true );

		$this->handle = $this->gzip ? gzopen( $this->tmp, 'wb9' ) : fopen( $this->tmp, 'wb' );

		$this->w( '<?xml version="1.0" encoding="UTF-8"?>' . "\n" );
		$this->w( '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" );
	}

	private function w( $str ) {
		if ( $this->gzip ) {
			gzwrite( $this->handle, $str );
		} else {
			fwrite( $this->handle, $str );
		}
	}

	private function esc( $value ) {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	public function add_url( $loc, $lastmod = '' ) {
		$entry = '<url><loc>' . $this->esc( $loc ) . '</loc>';
		if ( $lastmod ) {
			$entry .= '<lastmod>' . $this->esc( $lastmod ) . '</lastmod>';
		}
		$entry .= '</url>' . "\n";
		$this->w( $entry );
		$this->count++;
	}

	public function close() {
		$this->w( '</urlset>' . "\n" );
		if ( $this->gzip ) {
			gzclose( $this->handle );
		} else {
			fclose( $this->handle );
		}
		@rename( $this->tmp, $this->final );
		return $this->count;
	}
}

/**
 * Sitemap generation engine, sourced from Yoast SEO's indexable table.
 *
 * Bucketing:  bucket(id) = floor( (id - 1) / SSG_CHUNK )
 *   - Stable for the life of an ID, so an update only ever touches one file.
 *
 * File names mirror Yoast: {type}-sitemap.xml, {type}-sitemap2.xml, {taxonomy}-sitemap.xml,
 * author-sitemap.xml. Index: sitemap_index.xml. (Page number = bucket+1; first page omits it.)
 *
 * Inclusion mirrors Yoast: published + Yoast-indexable. Per-object noindex/public comes from
 * the indexable table; global per-type / per-taxonomy / author noindex comes from wpseo_titles.
 */
class SSG_Generator {

	private $registry = null;
	private $tax_meta = null;

	/* --------------------------------------------------------------------- */
	/* Yoast helpers                                                          */
	/* --------------------------------------------------------------------- */

	public function yoast_ready() {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
	}

	/** Yoast postmeta key for a post's noindex override. '1' = noindex; '2'/''/absent = index. */
	const POST_NOINDEX_META = '_yoast_wpseo_meta-robots-noindex';

	/** Yoast usermeta key for an author's noindex override. 'on' = noindex. */
	const AUTHOR_NOINDEX_META = 'wpseo_noindex_author';

	private function wpseo_titles() {
		$opt = get_option( 'wpseo_titles', array() );
		return is_array( $opt ) ? $opt : array();
	}

	/** Per-term noindex lives in a single option, not a meta table. */
	private function wpseo_taxonomy_meta() {
		if ( null === $this->tax_meta ) {
			$opt            = get_option( 'wpseo_taxonomy_meta', array() );
			$this->tax_meta = is_array( $opt ) ? $opt : array();
		}
		return $this->tax_meta;
	}

	private function term_noindexed( $taxonomy, $term_id ) {
		$meta = $this->wpseo_taxonomy_meta();
		if ( empty( $meta[ $taxonomy ][ $term_id ]['wpseo_noindex'] ) ) {
			return false;
		}
		return 'noindex' === $meta[ $taxonomy ][ $term_id ]['wpseo_noindex'];
	}

	private function type_noindexed( $post_type ) {
		$t = $this->wpseo_titles();
		return ! empty( $t[ 'noindex-' . $post_type ] );
	}

	private function tax_noindexed( $taxonomy ) {
		$t = $this->wpseo_titles();
		return ! empty( $t[ 'noindex-tax-' . $taxonomy ] );
	}

	private function author_disabled() {
		$t = $this->wpseo_titles();
		return ! empty( $t['disable-author'] ) || ! empty( $t['noindex-author-wpseo'] );
	}

	/* --------------------------------------------------------------------- */
	/* Config                                                                 */
	/* --------------------------------------------------------------------- */

	public function dir() {
		return trailingslashit( SSG_DIR );
	}

	public function url() {
		return trailingslashit( SSG_URL );
	}

	private function ensure_dir() {
		$d = $this->dir();
		if ( ! is_dir( $d ) ) {
			wp_mkdir_p( $d );
		}
	}

	public function settings() {
		$defaults = array(
			'post_types'   => array(),
			'taxonomies'   => array(),
			'gzip'         => 0,
			'disable_core' => 1,
		);
		$opt = get_option( 'ssg_settings', array() );
		return wp_parse_args( is_array( $opt ) ? $opt : array(), $defaults );
	}

	public function gzip_enabled() {
		$s = $this->settings();
		return ! empty( $s['gzip'] );
	}

	public function post_types() {
		$s = $this->settings();
		if ( ! empty( $s['post_types'] ) ) {
			$types = $s['post_types'];
		} else {
			$types = get_post_types( array( 'public' => true ), 'names' );
			unset( $types['attachment'] );
			$types = array_values( $types );
		}
		return (array) apply_filters( 'ssg_post_types', $types );
	}

	public function taxonomies() {
		$s = $this->settings();
		if ( ! empty( $s['taxonomies'] ) ) {
			$taxes = $s['taxonomies'];
		} else {
			$taxes = get_taxonomies( array( 'public' => true ), 'names' );
			$taxes = array_values( $taxes );
		}
		return (array) apply_filters( 'ssg_taxonomies', $taxes );
	}

	public function authors_enabled() {
		return ! $this->author_disabled();
	}

	public function bucket_for_id( $id ) {
		return (int) intdiv( max( 1, (int) $id ) - 1, SSG_CHUNK );
	}

	private function sanitize_slug( $slug ) {
		return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $slug ) );
	}

	private function filename( $kind, $object, $bucket ) {
		$suffix = ( 0 === (int) $bucket ) ? '' : (string) ( (int) $bucket + 1 );
		if ( 'author' === $kind ) {
			$name = 'author-sitemap' . $suffix . '.xml';
		} else {
			$name = $this->sanitize_slug( $object ) . '-sitemap' . $suffix . '.xml';
		}
		if ( $this->gzip_enabled() ) {
			$name .= '.gz';
		}
		return $name;
	}

	private function file_path( $kind, $object, $bucket ) {
		return $this->dir() . $this->filename( $kind, $object, $bucket );
	}

	private function file_url( $kind, $object, $bucket ) {
		return $this->url() . $this->filename( $kind, $object, $bucket );
	}

	public function index_url() {
		return $this->url() . 'sitemap_index.xml';
	}

	private function w3c( $datetime ) {
		if ( empty( $datetime ) || '0000-00-00 00:00:00' === $datetime ) {
			return '';
		}
		$ts = strtotime( $datetime . ' GMT' );
		return $ts ? gmdate( 'c', $ts ) : '';
	}

	/* --------------------------------------------------------------------- */
	/* Registry                                                               */
	/* --------------------------------------------------------------------- */

	private function load_registry() {
		if ( null === $this->registry ) {
			$reg = get_option( 'ssg_registry', array() );
			if ( ! is_array( $reg ) ) {
				$reg = array();
			}
			$this->registry = wp_parse_args( $reg, array( 'post' => array(), 'tax' => array(), 'author' => array() ) );
		}
		return $this->registry;
	}

	private function persist_registry() {
		update_option( 'ssg_registry', $this->registry, false );
	}

	private function set_registry_entry( $kind, $object, $bucket, $count, $lastmod ) {
		$this->load_registry();
		if ( ! isset( $this->registry[ $kind ][ $object ] ) ) {
			$this->registry[ $kind ][ $object ] = array();
		}
		$this->registry[ $kind ][ $object ][ $bucket ] = array(
			'count'   => (int) $count,
			'lastmod' => $lastmod,
		);
	}

	private function remove_registry_entry( $kind, $object, $bucket ) {
		$this->load_registry();
		unset( $this->registry[ $kind ][ $object ][ $bucket ] );
		if ( empty( $this->registry[ $kind ][ $object ] ) ) {
			unset( $this->registry[ $kind ][ $object ] );
		}
	}

	public function get_registry() {
		return $this->load_registry();
	}

	/* --------------------------------------------------------------------- */
	/* Locking / memory                                                       */
	/* --------------------------------------------------------------------- */

	private function with_lock( callable $cb ) {
		global $wpdb;
		$key = substr( 'ssg_' . md5( DB_NAME . $wpdb->prefix ), 0, 60 );
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $key, 10 ) );
		try {
			return $cb();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
		}
	}

	private function free_memory() {
		global $wpdb, $wp_object_cache;
		$wpdb->queries = array();
		if ( is_object( $wp_object_cache ) ) {
			if ( property_exists( $wp_object_cache, 'cache' ) ) {
				$wp_object_cache->cache = array();
			}
			if ( method_exists( $wp_object_cache, 'flush_runtime' ) ) {
				$wp_object_cache->flush_runtime();
			}
		}
	}

	/* --------------------------------------------------------------------- */
	/* URL resolution (built from the row via core, no per-row DB hit)        */
	/* --------------------------------------------------------------------- */

	private function post_link( $row ) {
		// Passing a WP_Post means get_permalink() does not query for the post again.
		$l = get_permalink( new WP_Post( $row ) );
		return $l ? $l : '';
	}

	private function term_link( $row, $taxonomy ) {
		$l = get_term_link( new WP_Term( $row ), $taxonomy );
		return ( $l && ! is_wp_error( $l ) ) ? $l : '';
	}

	private function author_link( $row ) {
		$nicename = isset( $row->user_nicename ) ? $row->user_nicename : '';
		return get_author_posts_url( (int) $row->ID, $nicename );
	}

	/* --------------------------------------------------------------------- */
	/* Single-bucket regeneration (incremental)                               */
	/* --------------------------------------------------------------------- */

	public function regenerate_post_bucket( $post_type, $bucket ) {
		global $wpdb;

		if ( $this->type_noindexed( $post_type ) ) {
			$this->delete_bucket( 'post', $post_type, $bucket );
			return 0;
		}

		$this->ensure_dir();
		$lo = $bucket * SSG_CHUNK + 1;
		$hi = ( $bucket + 1 ) * SSG_CHUNK;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_author, p.post_date, p.post_date_gmt, p.post_name, p.post_type, p.post_status, p.post_parent, p.post_modified, p.post_modified_gmt, p.post_password, p.post_title, p.guid
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} nm ON nm.post_id = p.ID AND nm.meta_key = %s
				 WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_password = ''
				   AND ( nm.meta_value IS NULL OR nm.meta_value <> '1' )
				   AND p.ID BETWEEN %d AND %d
				 ORDER BY p.ID ASC",
				self::POST_NOINDEX_META,
				$post_type,
				$lo,
				$hi
			)
		);

		if ( empty( $rows ) ) {
			$this->delete_bucket( 'post', $post_type, $bucket );
			return 0;
		}

		$writer  = new SSG_Bucket_Writer( $this->file_path( 'post', $post_type, $bucket ), $this->gzip_enabled() );
		$lastmod = '';
		foreach ( $rows as $row ) {
			$loc = $this->post_link( $row );
			if ( ! $loc ) {
				continue;
			}
			$writer->add_url( $loc, $this->w3c( $row->post_modified_gmt ) );
			if ( $row->post_modified_gmt > $lastmod ) {
				$lastmod = $row->post_modified_gmt;
			}
		}
		$count = $writer->close();
		$this->set_registry_entry( 'post', $post_type, $bucket, $count, $this->w3c( $lastmod ) );
		return $count;
	}

	public function regenerate_term_bucket( $taxonomy, $bucket ) {
		global $wpdb;

		if ( $this->tax_noindexed( $taxonomy ) ) {
			$this->delete_bucket( 'tax', $taxonomy, $bucket );
			return 0;
		}

		$this->ensure_dir();
		$lo = $bucket * SSG_CHUNK + 1;
		$hi = ( $bucket + 1 ) * SSG_CHUNK;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, t.name, t.slug, t.term_group, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count
				 FROM {$wpdb->term_taxonomy} tt
				 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				 WHERE tt.taxonomy = %s AND tt.count > 0
				   AND tt.term_id BETWEEN %d AND %d
				 ORDER BY tt.term_id ASC",
				$taxonomy,
				$lo,
				$hi
			)
		);

		if ( empty( $rows ) ) {
			$this->delete_bucket( 'tax', $taxonomy, $bucket );
			return 0;
		}

		$writer = new SSG_Bucket_Writer( $this->file_path( 'tax', $taxonomy, $bucket ), $this->gzip_enabled() );
		foreach ( $rows as $row ) {
			if ( $this->term_noindexed( $taxonomy, (int) $row->term_id ) ) {
				continue;
			}
			$loc = $this->term_link( $row, $taxonomy );
			if ( ! $loc ) {
				continue;
			}
			$writer->add_url( $loc );
		}
		$count = $writer->close();
		$this->set_registry_entry( 'tax', $taxonomy, $bucket, $count, '' );
		return $count;
	}

	public function regenerate_author_bucket( $bucket ) {
		global $wpdb;

		if ( $this->author_disabled() ) {
			$this->delete_bucket( 'author', 'author', $bucket );
			return 0;
		}

		$this->ensure_dir();
		$lo = $bucket * SSG_CHUNK + 1;
		$hi = ( $bucket + 1 ) * SSG_CHUNK;

		$types        = $this->post_types();
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		$sql = "SELECT u.ID, u.user_nicename
				FROM {$wpdb->users} u
				LEFT JOIN {$wpdb->usermeta} nm ON nm.user_id = u.ID AND nm.meta_key = %s
				WHERE ( nm.meta_value IS NULL OR nm.meta_value <> 'on' )
				  AND u.ID BETWEEN %d AND %d
				  AND EXISTS (
				      SELECT 1 FROM {$wpdb->posts} p
				      WHERE p.post_author = u.ID
				        AND p.post_status = 'publish'
				        AND p.post_type IN ( {$placeholders} )
				      LIMIT 1
				  )
				ORDER BY u.ID ASC";

		$params = array_merge( array( self::AUTHOR_NOINDEX_META, $lo, $hi ), $types );
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		if ( empty( $rows ) ) {
			$this->delete_bucket( 'author', 'author', $bucket );
			return 0;
		}

		$writer = new SSG_Bucket_Writer( $this->file_path( 'author', 'author', $bucket ), $this->gzip_enabled() );
		foreach ( $rows as $row ) {
			$loc = $this->author_link( $row );
			if ( ! $loc ) {
				continue;
			}
			$writer->add_url( $loc );
		}
		$count = $writer->close();
		$this->set_registry_entry( 'author', 'author', $bucket, $count, '' );
		return $count;
	}

	private function delete_bucket( $kind, $object, $bucket ) {
		$path = $this->file_path( $kind, $object, $bucket );
		if ( file_exists( $path ) ) {
			@unlink( $path );
		}
		$alt = ( '.gz' === substr( $path, -3 ) ) ? substr( $path, 0, -3 ) : $path . '.gz';
		if ( file_exists( $alt ) ) {
			@unlink( $alt );
		}
		$this->remove_registry_entry( $kind, $object, $bucket );
	}

	/* --------------------------------------------------------------------- */
	/* Incremental flush (shutdown handler)                                   */
	/* --------------------------------------------------------------------- */

	public function flush_dirty( array $post_buckets, array $tax_buckets, array $author_buckets ) {
		if ( empty( $post_buckets ) && empty( $tax_buckets ) && empty( $author_buckets ) ) {
			return;
		}

		$this->with_lock(
			function () use ( $post_buckets, $tax_buckets, $author_buckets ) {
				$this->registry = null;
				$this->load_registry();

				foreach ( $post_buckets as $post_type => $buckets ) {
					foreach ( array_keys( $buckets ) as $bucket ) {
						$this->regenerate_post_bucket( $post_type, (int) $bucket );
					}
				}
				foreach ( $tax_buckets as $taxonomy => $buckets ) {
					foreach ( array_keys( $buckets ) as $bucket ) {
						$this->regenerate_term_bucket( $taxonomy, (int) $bucket );
					}
				}
				foreach ( array_keys( $author_buckets ) as $bucket ) {
					$this->regenerate_author_bucket( (int) $bucket );
				}

				$this->persist_registry();
				$this->build_index();
			}
		);
	}

	/* --------------------------------------------------------------------- */
	/* Full builds (CLI), streamed and bucket-aware                           */
	/* --------------------------------------------------------------------- */

	public function generate_post_type( $post_type, $progress = null ) {
		global $wpdb;
		$this->load_registry();

		if ( $this->type_noindexed( $post_type ) ) {
			$this->prune_stale( 'post', $post_type, array() );
			$this->persist_registry();
			return;
		}

		$this->ensure_dir();

		$last_id = 0;
		$cur     = null;
		$writer  = null;
		$lastmod = '';
		$done    = array();

		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_author, p.post_date, p.post_date_gmt, p.post_name, p.post_type, p.post_status, p.post_parent, p.post_modified, p.post_modified_gmt, p.post_password, p.post_title, p.guid
					 FROM {$wpdb->posts} p
					 LEFT JOIN {$wpdb->postmeta} nm ON nm.post_id = p.ID AND nm.meta_key = %s
					 WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_password = ''
					   AND ( nm.meta_value IS NULL OR nm.meta_value <> '1' )
					   AND p.ID > %d
					 ORDER BY p.ID ASC
					 LIMIT %d",
					self::POST_NOINDEX_META,
					$post_type,
					$last_id,
					SSG_WINDOW
				)
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_id = (int) $row->ID;
				$bucket  = $this->bucket_for_id( $row->ID );

				if ( $bucket !== $cur ) {
					if ( $writer ) {
						$n = $writer->close();
						$this->set_registry_entry( 'post', $post_type, $cur, $n, $this->w3c( $lastmod ) );
						$done[ $cur ] = true;
					}
					$cur     = $bucket;
					$lastmod = '';
					$writer  = new SSG_Bucket_Writer( $this->file_path( 'post', $post_type, $bucket ), $this->gzip_enabled() );
				}

				$loc = $this->post_link( $row );
				if ( $loc ) {
					$writer->add_url( $loc, $this->w3c( $row->post_modified_gmt ) );
					if ( $row->post_modified_gmt > $lastmod ) {
						$lastmod = $row->post_modified_gmt;
					}
				}
			}

			if ( $progress ) {
				$progress( $last_id );
			}
			$this->free_memory();

			if ( count( $rows ) < SSG_WINDOW ) {
				break;
			}
		}

		if ( $writer ) {
			$n = $writer->close();
			$this->set_registry_entry( 'post', $post_type, $cur, $n, $this->w3c( $lastmod ) );
			$done[ $cur ] = true;
		}

		$this->prune_stale( 'post', $post_type, $done );
		$this->persist_registry();
	}

	public function generate_taxonomy( $taxonomy, $progress = null ) {
		global $wpdb;
		$this->load_registry();

		if ( $this->tax_noindexed( $taxonomy ) ) {
			$this->prune_stale( 'tax', $taxonomy, array() );
			$this->persist_registry();
			return;
		}

		$this->ensure_dir();

		$last_id = 0;
		$cur     = null;
		$writer  = null;
		$done    = array();

		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT t.term_id, t.name, t.slug, t.term_group, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count
					 FROM {$wpdb->term_taxonomy} tt
					 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
					 WHERE tt.taxonomy = %s AND tt.count > 0
					   AND tt.term_id > %d
					 ORDER BY tt.term_id ASC
					 LIMIT %d",
					$taxonomy,
					$last_id,
					SSG_WINDOW
				)
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_id = (int) $row->term_id;
				$bucket  = $this->bucket_for_id( $row->term_id );

				if ( $bucket !== $cur ) {
					if ( $writer ) {
						$n = $writer->close();
						$this->set_registry_entry( 'tax', $taxonomy, $cur, $n, '' );
						$done[ $cur ] = true;
					}
					$cur    = $bucket;
					$writer = new SSG_Bucket_Writer( $this->file_path( 'tax', $taxonomy, $bucket ), $this->gzip_enabled() );
				}

				if ( $this->term_noindexed( $taxonomy, (int) $row->term_id ) ) {
					continue;
				}
				$loc = $this->term_link( $row, $taxonomy );
				if ( $loc ) {
					$writer->add_url( $loc );
				}
			}

			if ( $progress ) {
				$progress( $last_id );
			}
			$this->free_memory();

			if ( count( $rows ) < SSG_WINDOW ) {
				break;
			}
		}

		if ( $writer ) {
			$n = $writer->close();
			$this->set_registry_entry( 'tax', $taxonomy, $cur, $n, '' );
			$done[ $cur ] = true;
		}

		$this->prune_stale( 'tax', $taxonomy, $done );
		$this->persist_registry();
	}

	public function generate_authors( $progress = null ) {
		global $wpdb;
		$this->load_registry();

		if ( $this->author_disabled() ) {
			$this->prune_stale( 'author', 'author', array() );
			$this->persist_registry();
			return;
		}

		$this->ensure_dir();
		$types        = $this->post_types();
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		$last_id = 0;
		$cur     = null;
		$writer  = null;
		$done    = array();

		while ( true ) {
			$sql = "SELECT u.ID, u.user_nicename
					FROM {$wpdb->users} u
					LEFT JOIN {$wpdb->usermeta} nm ON nm.user_id = u.ID AND nm.meta_key = %s
					WHERE ( nm.meta_value IS NULL OR nm.meta_value <> 'on' )
					  AND u.ID > %d
					  AND EXISTS (
					      SELECT 1 FROM {$wpdb->posts} p
					      WHERE p.post_author = u.ID
					        AND p.post_status = 'publish'
					        AND p.post_type IN ( {$placeholders} )
					      LIMIT 1
					  )
					ORDER BY u.ID ASC
					LIMIT %d";

			$params = array_merge( array( self::AUTHOR_NOINDEX_META, $last_id ), $types, array( SSG_WINDOW ) );
			$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_id = (int) $row->ID;
				$bucket  = $this->bucket_for_id( $row->ID );

				if ( $bucket !== $cur ) {
					if ( $writer ) {
						$n = $writer->close();
						$this->set_registry_entry( 'author', 'author', $cur, $n, '' );
						$done[ $cur ] = true;
					}
					$cur    = $bucket;
					$writer = new SSG_Bucket_Writer( $this->file_path( 'author', 'author', $bucket ), $this->gzip_enabled() );
				}

				$loc = $this->author_link( $row );
				if ( $loc ) {
					$writer->add_url( $loc );
				}
			}

			if ( $progress ) {
				$progress( $last_id );
			}
			$this->free_memory();

			if ( count( $rows ) < SSG_WINDOW ) {
				break;
			}
		}

		if ( $writer ) {
			$n = $writer->close();
			$this->set_registry_entry( 'author', 'author', $cur, $n, '' );
			$done[ $cur ] = true;
		}

		$this->prune_stale( 'author', 'author', $done );
		$this->persist_registry();
	}

	private function prune_stale( $kind, $object, array $produced ) {
		$this->load_registry();
		if ( empty( $this->registry[ $kind ][ $object ] ) ) {
			return;
		}
		foreach ( array_keys( $this->registry[ $kind ][ $object ] ) as $bucket ) {
			if ( ! isset( $produced[ $bucket ] ) ) {
				$this->delete_bucket( $kind, $object, (int) $bucket );
			}
		}
	}

	public function generate_all( $progress = null ) {
		foreach ( $this->post_types() as $type ) {
			$this->generate_post_type( $type, $progress );
		}
		foreach ( $this->taxonomies() as $tax ) {
			$this->generate_taxonomy( $tax, $progress );
		}
		$this->generate_authors( $progress );
		$this->build_index();
	}

	/* --------------------------------------------------------------------- */
	/* Index (sitemap_index.xml)                                              */
	/* --------------------------------------------------------------------- */

	public function build_index() {
		$this->load_registry();
		$this->ensure_dir();

		$path = $this->dir() . 'sitemap_index.xml';
		$tmp  = $path . '.tmp-' . getmypid() . '-' . uniqid( '', true );
		$fh   = fopen( $tmp, 'wb' );

		fwrite( $fh, '<?xml version="1.0" encoding="UTF-8"?>' . "\n" );
		fwrite( $fh, '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" );

		foreach ( array( 'post', 'tax', 'author' ) as $kind ) {
			if ( empty( $this->registry[ $kind ] ) ) {
				continue;
			}
			foreach ( $this->registry[ $kind ] as $object => $buckets ) {
				foreach ( $buckets as $bucket => $meta ) {
					if ( empty( $meta['count'] ) ) {
						continue;
					}
					$loc     = htmlspecialchars( $this->file_url( $kind, $object, $bucket ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
					$lastmod = ! empty( $meta['lastmod'] ) ? '<lastmod>' . htmlspecialchars( $meta['lastmod'], ENT_QUOTES | ENT_XML1, 'UTF-8' ) . '</lastmod>' : '';
					fwrite( $fh, '<sitemap><loc>' . $loc . '</loc>' . $lastmod . '</sitemap>' . "\n" );
				}
			}
		}

		fwrite( $fh, '</sitemapindex>' . "\n" );
		fclose( $fh );
		@rename( $tmp, $path );
	}
}
