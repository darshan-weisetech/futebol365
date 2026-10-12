<?php
/**
 * Plugin Name:       Static Sitemap Generator
 * Description:       Static XML sitemaps that mirror Yoast SEO (post types, taxonomies, authors, noindex rules) but are pre-generated as real files. Files are bucketed by ID range, so creating a post only rewrites the latest file and updating one only rewrites the file that contains it. Output goes to /sitemap/ in the web root.
 * Version:           2.0.0
 * Requires PHP:      7.4
 * Requires Plugins:  wordpress-seo
 * Author:            Weisetech
 * License:           GPL-2.0-or-later
 * Text Domain:       ssg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SSG_VERSION', '2.0.0' );
define( 'SSG_FILE', __FILE__ );
define( 'SSG_PATH', plugin_dir_path( __FILE__ ) );

/**
 * URLs per sub-sitemap = IDs per bucket. Must be <= 50000 (sitemap protocol limit).
 * Yoast paginates at 1000; set this to 1000 to mirror that page size. Larger values mean
 * far fewer files on a multi-million-post site (recommended for 3M+).
 */
if ( ! defined( 'SSG_CHUNK' ) ) {
	define( 'SSG_CHUNK', 1000 );
}

/** Rows fetched per DB query during a full (CLI) build. Bounds peak memory. */
if ( ! defined( 'SSG_WINDOW' ) ) {
	define( 'SSG_WINDOW', 5000 );
}

/** Output directory. Default: a /sitemap/ folder in the web root (created on demand). */
if ( ! defined( 'SSG_DIR' ) ) {
	define( 'SSG_DIR', trailingslashit( ABSPATH ) . 'sitemap/' );
}

/** Public URL base that maps to SSG_DIR. Auto-derived when SSG_DIR is inside ABSPATH. */
if ( ! defined( 'SSG_URL' ) ) {
	$ssg_dir  = wp_normalize_path( trailingslashit( SSG_DIR ) );
	$ssg_root = wp_normalize_path( trailingslashit( ABSPATH ) );
	if ( 0 === strpos( $ssg_dir, $ssg_root ) ) {
		$ssg_rel = substr( $ssg_dir, strlen( $ssg_root ) );
		define( 'SSG_URL', home_url( '/' . $ssg_rel ) );
	} else {
		define( 'SSG_URL', home_url( '/sitemap/' ) );
	}
	unset( $ssg_dir, $ssg_root, $ssg_rel );
}

require_once SSG_PATH . 'includes/class-ssg-generator.php';
require_once SSG_PATH . 'includes/class-ssg-hooks.php';
require_once SSG_PATH . 'includes/class-ssg-admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once SSG_PATH . 'includes/class-ssg-cli.php';
	WP_CLI::add_command( 'staticsitemap', 'SSG_CLI' );
}

function ssg() {
	static $generator = null;
	if ( null === $generator ) {
		$generator = new SSG_Generator();
	}
	return $generator;
}

add_action(
	'plugins_loaded',
	function () {
		$gen = ssg();

		// Hard dependency on Yoast SEO. Without it (or its indexable table), do not
		// register generation hooks; the admin screen explains what is missing.
		$ready = $gen->yoast_ready();

		if ( $ready ) {
			new SSG_Hooks( $gen );
		}
		if ( is_admin() ) {
			new SSG_Admin( $gen );
		}
	}
);

register_activation_hook(
	__FILE__,
	function () {
		$dir = trailingslashit( SSG_DIR );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		update_option( 'ssg_dir_writable', is_writable( $dir ) ? 1 : 0, false );
	}
);
