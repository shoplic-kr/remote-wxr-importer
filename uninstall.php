<?php
/**
 * Remote WXR Importer uninstall handler.
 *
 * The plugin persists no options. Imported content is user data and must remain
 * intact when the plugin is removed.
 *
 * @package Remote_WXR_Importer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$rwi_temporary_directory = trailingslashit( sys_get_temp_dir() );
$rwi_temporary_prefix    = 'rwi-' . substr( md5( home_url( '/' ) ), 0, 10 ) . '-';
$rwi_temporary_files     = glob( $rwi_temporary_directory . $rwi_temporary_prefix . '*' );

if ( is_array( $rwi_temporary_files ) ) {
	foreach ( $rwi_temporary_files as $rwi_temporary_file ) {
		$is_plugin_temp = is_file( $rwi_temporary_file )
			&& 0 === strpos( wp_basename( $rwi_temporary_file ), $rwi_temporary_prefix );

		if ( $is_plugin_temp ) {
			@unlink( $rwi_temporary_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
