<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * @param string $directory
 */
function ppcfw_remove_directory_tree( string $directory ): void {
	if ( '' === $directory || ! is_dir( $directory ) ) {
		return;
	}

	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $files as $file ) {
		$path = $file->getRealPath();
		if ( ! is_string( $path ) || '' === $path ) {
			continue;
		}

		if ( $file->isDir() ) {
			@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			continue;
		}

		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	@rmdir( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

$upload_dir = wp_upload_dir( null, false );
$basedir    = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';

if ( '' !== $basedir ) {
	$plugin_storage_root = trailingslashit( $basedir ) . 'pdf-product-catalogs';
	ppcfw_remove_directory_tree( $plugin_storage_root );
}

delete_option( 'ppcfw_settings' );
delete_option( 'ppcfw_storage_secret' );
delete_option( 'ppcfw_storage_key' );
delete_option( 'ppcfw_catalogs_schema_version' );

$table_name = $wpdb->prefix . 'ppcfw_catalogs';
$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'ppcfw_generate_catalog', array(), 'pdf-product-catalogs-for-woocommerce' );
		as_unschedule_all_actions( 'ppcfw_daily_auto_refresh', array(), 'pdf-product-catalogs-for-woocommerce' );
	}

	wp_clear_scheduled_hook( 'ppcfw_daily_auto_refresh' );
