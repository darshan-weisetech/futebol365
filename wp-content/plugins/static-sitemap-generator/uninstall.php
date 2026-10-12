<?php
/**
 * Uninstall: remove plugin options. Generated .xml files are intentionally left on disk
 * (delete them manually if no longer needed).
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ssg_settings' );
delete_option( 'ssg_registry' );
delete_option( 'ssg_dir_writable' );
