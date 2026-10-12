<?php
/**
 * WP-CLI interface.
 *
 *   wp staticsitemap generate --all
 *   wp staticsitemap generate --type=post,page
 *   wp staticsitemap generate --taxonomy=category,post_tag
 *   wp staticsitemap generate --author
 *   wp staticsitemap index
 *   wp staticsitemap bucket --type=post --id=1234567
 *   wp staticsitemap status
 */
class SSG_CLI {

	private function require_yoast() {
		if ( ! ssg()->yoast_ready() ) {
			WP_CLI::error( 'Yoast SEO is required and was not detected.' );
		}
	}

	/**
	 * Build static sitemaps.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Rebuild every configured post type, taxonomy and the author sitemap, then the index.
	 *
	 * [--type=<post_types>]
	 * : Comma-separated post types.
	 *
	 * [--taxonomy=<taxonomies>]
	 * : Comma-separated taxonomies.
	 *
	 * [--author]
	 * : Rebuild the author sitemap.
	 *
	 * [--no-index]
	 * : Skip rebuilding the index (run `index` once after several partial runs).
	 */
	public function generate( $args, $assoc ) {
		$this->require_yoast();
		$gen = ssg();

		$all    = isset( $assoc['all'] );
		$types  = isset( $assoc['type'] ) ? array_filter( array_map( 'trim', explode( ',', $assoc['type'] ) ) ) : array();
		$taxes  = isset( $assoc['taxonomy'] ) ? array_filter( array_map( 'trim', explode( ',', $assoc['taxonomy'] ) ) ) : array();
		$author = isset( $assoc['author'] );

		if ( $all ) {
			$types  = $gen->post_types();
			$taxes  = $gen->taxonomies();
			$author = true;
		}

		if ( empty( $types ) && empty( $taxes ) && ! $author ) {
			WP_CLI::error( 'Nothing to do. Pass --all, --type=<...>, --taxonomy=<...> or --author.' );
		}

		$start = microtime( true );

		foreach ( $types as $type ) {
			WP_CLI::log( "Post type: {$type}" );
			$gen->generate_post_type(
				$type,
				function ( $last_id ) {
					WP_CLI::log( "  ...up to ID {$last_id}" );
				}
			);
			WP_CLI::success( "Finished post type: {$type}" );
		}

		foreach ( $taxes as $tax ) {
			WP_CLI::log( "Taxonomy: {$tax}" );
			$gen->generate_taxonomy( $tax );
			WP_CLI::success( "Finished taxonomy: {$tax}" );
		}

		if ( $author ) {
			WP_CLI::log( 'Authors' );
			$gen->generate_authors();
			WP_CLI::success( 'Finished authors' );
		}

		if ( ! isset( $assoc['no-index'] ) ) {
			$gen->build_index();
			WP_CLI::log( 'Index: ' . $gen->index_url() );
		}

		WP_CLI::success( 'Done in ' . round( microtime( true ) - $start, 1 ) . 's.' );
	}

	/**
	 * Rebuild only the sitemap index from the registry.
	 */
	public function index( $args, $assoc ) {
		$this->require_yoast();
		ssg()->build_index();
		WP_CLI::success( 'Index rebuilt: ' . ssg()->index_url() );
	}

	/**
	 * Regenerate the single post bucket containing an ID (debugging).
	 *
	 * ## OPTIONS
	 *
	 * --type=<post_type>
	 * : The post type.
	 *
	 * --id=<post_id>
	 * : Any post ID inside the target bucket.
	 */
	public function bucket( $args, $assoc ) {
		$this->require_yoast();
		$gen  = ssg();
		$type = isset( $assoc['type'] ) ? $assoc['type'] : '';
		$id   = isset( $assoc['id'] ) ? (int) $assoc['id'] : 0;
		if ( ! $type || ! $id ) {
			WP_CLI::error( 'Both --type and --id are required.' );
		}
		$bucket = $gen->bucket_for_id( $id );
		$count  = $gen->regenerate_post_bucket( $type, $bucket );
		$gen->build_index();
		WP_CLI::success( "Regenerated {$type} bucket {$bucket} ({$count} URLs)." );
	}

	/**
	 * Show what has been generated.
	 */
	public function status( $args, $assoc ) {
		$gen      = ssg();
		$registry = $gen->get_registry();

		$rows  = array();
		$total = 0;
		foreach ( array( 'post', 'tax', 'author' ) as $kind ) {
			if ( empty( $registry[ $kind ] ) ) {
				continue;
			}
			foreach ( $registry[ $kind ] as $object => $buckets ) {
				$urls   = array_sum( wp_list_pluck( $buckets, 'count' ) );
				$total += $urls;
				$rows[] = array(
					'kind'   => $kind,
					'object' => $object,
					'files'  => count( $buckets ),
					'urls'   => $urls,
				);
			}
		}

		WP_CLI::log( 'Yoast ready : ' . ( $gen->yoast_ready() ? 'yes' : 'NO' ) );
		WP_CLI::log( 'Output dir  : ' . $gen->dir() );
		WP_CLI::log( 'Index URL   : ' . $gen->index_url() );
		WP_CLI::log( 'Chunk size  : ' . SSG_CHUNK );
		WP_CLI::log( 'Total URLs  : ' . number_format_i18n( $total ) );
		if ( $rows ) {
			WP_CLI\Utils\format_items( 'table', $rows, array( 'kind', 'object', 'files', 'urls' ) );
		}
	}
}
