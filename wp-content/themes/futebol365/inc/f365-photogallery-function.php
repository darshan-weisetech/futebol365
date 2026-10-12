<?php
/**
 * Photo Gallery — SQL helpers for /multimedia/fotogalerias/
 *
 * Mirrors Django:
 *   multimedia.urls  → multimedia/fotogalerias/  → common.views.read_cached_list
 *   model            → multimedia.PhotoGallery   (table: {prefix}multimedia_photogallery)
 *
 * PhotoGallery columns used here:
 *   id, title, image, datetime, views, votes, highlight, is_published
 *   FK: competition_id → competition_competition
 *
 * Depends on the shared cache helper in f365-livegame-function.php
 * (f365_livegame_cached, F365_LIVEGAME_CACHE_TTL).
 *
 * @package Futebol365
 */

if ( ! function_exists( 'f365_photogallery_media_url' ) ) {

	/**
	 * Base URL for Django media files (images stored in multimedia_photogallery.image).
	 *
	 * Django stores relative paths like "images/fotogalerias/2021/photo.jpg".
	 * The full URL is:  <media_root_url> + "/" + <relative_path>.
	 *
	 * Set the constant F365_MEDIA_URL in wp-config.php to override:
	 *   define( 'F365_MEDIA_URL', 'https://media.futebol365.pt' );
	 *
	 * Falls back to a WordPress option "f365_media_url", then to WP_CONTENT_URL/media.
	 */
	function f365_photogallery_media_url() {
		if ( defined( 'F365_MEDIA_URL' ) ) {
			return rtrim( F365_MEDIA_URL, '/' );
		}
		$opt = get_option( 'f365_media_url' );
		if ( $opt ) {
			return rtrim( $opt, '/' );
		}
		return rtrim( home_url( '/media' ), '/' );
	}

	/**
	 * Optional local filesystem root for imported Django media.
	 *
	 * Django checks settings.MEDIA_ROOT before returning the image. In WordPress
	 * we can only do the same when the media files exist on this server.
	 *
	 * Set either:
	 *   define( 'F365_MEDIA_ROOT', '/absolute/path/to/media' );
	 * or WP option:
	 *   f365_media_root
	 *
	 * @return string
	 */
	function f365_photogallery_media_root() {
		if ( defined( 'F365_MEDIA_ROOT' ) ) {
			return rtrim( F365_MEDIA_ROOT, '/\\' );
		}

		$opt = get_option( 'f365_media_root' );
		if ( $opt ) {
			return rtrim( $opt, '/\\' );
		}

		return '';
	}

	/**
	 * Check if a Django media-relative image exists locally.
	 * If F365_MEDIA_ROOT is not configured, return true for non-empty paths
	 * because the file may live on the remote media domain.
	 *
	 * @param string $image Relative image path.
	 * @return bool
	 */
	function f365_photogallery_image_exists( $image ) {
		$image = trim( (string) $image );
		if ( '' === $image || preg_match( '#^https?://#i', $image ) ) {
			return '' !== $image;
		}

		$media_root = f365_photogallery_media_root();
		if ( '' === $media_root ) {
			return true;
		}

		$image = preg_replace( '#^/?media/#', '', $image );
		return file_exists( $media_root . DIRECTORY_SEPARATOR . ltrim( $image, '/\\' ) );
	}

	/**
	 * Build the full URL for a gallery cover image stored in multimedia_photogallery.image.
	 * Mirrors Django PhotoGallery.get_image():
	 * use image when present, otherwise images/photos/no-image.jpg.
	 *
	 * @param string $image  The relative path stored in the DB, e.g. "images/foo.jpg".
	 * @return string
	 */
	function f365_photogallery_image_url( $image ) {
		$image = trim( (string) $image );

		if ( '' === $image || ! f365_photogallery_image_exists( $image ) ) {
			$image = 'images/photos/no-image.jpg';
		}

		if ( preg_match( '#^https?://#i', $image ) ) {
			return $image;
		}

		$image = str_replace( '\\', '/', $image );
		$image = preg_replace( '#^/?media/#', '', $image );
		$image = preg_replace( '#^/?fotografias/?#', 'images/photos/', $image );
		$image = ltrim( $image, '/' );

		if ( 0 !== strpos( $image, 'images/photos/' ) ) {
			$image = 'images/photos/' . $image;
		}

		return f365_photogallery_media_url() . '/' . ltrim( $image, '/' );
	}

	// ─── Constants ───────────────────────────────────────────────────────────
	if ( ! defined( 'F365_PG_PER_PAGE' ) ) {
		define( 'F365_PG_PER_PAGE', 20 );   // items per page — mirrors Django items_per_page
	}
	if ( ! defined( 'F365_PG_MAX_ENTRIES' ) ) {
		define( 'F365_PG_MAX_ENTRIES', 400 ); // mirrors Django max_entries
	}
	// Days window for "mais vistas" — mirrors Django's most_viewed_days_datetime (45 days).
	if ( ! defined( 'F365_PG_MOST_VIEWED_DAYS' ) ) {
		define( 'F365_PG_MOST_VIEWED_DAYS', 45 );
	}


}
