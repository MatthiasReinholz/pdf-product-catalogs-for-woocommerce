<?php

namespace PdfProductCatalogsForWooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Storage {
	/**
	 * @return array{basedir:string,dir:string}
	 */
	public static function get_storage_info(): array {
		$upload  = wp_upload_dir( null, false );
		$basedir = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';
		$secret  = self::get_secret();

		return array(
			'basedir' => $basedir,
			'dir'     => trailingslashit( $basedir ) . 'pdf-product-catalogs/private-' . sanitize_file_name( $secret ) . '/catalogs',
		);
	}

	public static function ensure_secret(): void {
		$secret = get_option( Settings::SECRET_OPTION_NAME, '' );
		if ( is_string( $secret ) && '' !== $secret ) {
			return;
		}

		add_option( Settings::SECRET_OPTION_NAME, wp_generate_password( 24, false, false ), '', false );
	}

	public static function ensure_storage_dir(): void {
		self::ensure_secret();

		$storage = self::get_storage_info();
		if ( '' === $storage['dir'] ) {
			return;
		}

		$dirs = array(
			trailingslashit( $storage['basedir'] ) . 'pdf-product-catalogs',
			dirname( $storage['dir'] ),
			$storage['dir'],
		);

		foreach ( $dirs as $dir ) {
			if ( '' === $dir ) {
				continue;
			}

			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			self::write_protection_files( $dir );
		}
	}

	public static function build_file_name( string $client_name, string $created_at_gmt ): string {
		$timestamp = strtotime( $created_at_gmt . ' GMT' );
		$timestamp = false !== $timestamp ? $timestamp : time();

		$parts = array( 'product-catalog' );
		if ( '' !== $client_name ) {
			$parts[] = sanitize_title( $client_name );
		}
		$parts[] = gmdate( 'Ymd-His', $timestamp );

		return implode( '-', array_filter( $parts ) ) . '.pdf';
	}

	public static function absolute_path_for_file_name( string $file_name ): string {
		self::ensure_storage_dir();

		$storage = self::get_storage_info();
		return trailingslashit( $storage['dir'] ) . sanitize_file_name( $file_name );
	}

	public static function relative_path_from_absolute( string $absolute_path ): string {
		$upload = wp_upload_dir( null, false );
		$basedir = isset( $upload['basedir'] ) ? trailingslashit( (string) $upload['basedir'] ) : '';

		if ( '' === $basedir || 0 !== strpos( $absolute_path, $basedir ) ) {
			return '';
		}

		return ltrim( substr( $absolute_path, strlen( $basedir ) ), '/' );
	}

	public static function absolute_path_from_relative( string $relative_path ): string {
		$relative_path = ltrim( $relative_path, '/' );
		$upload        = wp_upload_dir( null, false );
		$basedir       = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';

		if ( '' === $basedir || '' === $relative_path ) {
			return '';
		}

		return trailingslashit( $basedir ) . $relative_path;
	}

	public static function is_path_in_storage_dir( string $path ): bool {
		$storage_dir = realpath( self::get_storage_info()['dir'] );
		$real_path   = realpath( $path );

		if ( false === $storage_dir || false === $real_path ) {
			return false;
		}

		return 0 === strpos( $real_path, trailingslashit( $storage_dir ) );
	}

	public static function stream_pdf_file( string $absolute_path, string $download_name ): void {
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( basename( $download_name ) ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $absolute_path ) );

		$handle = fopen( $absolute_path, 'rb' );
		if ( false === $handle ) {
			wp_die( esc_html__( 'Failed to read the catalog file.', 'pdf-product-catalogs-for-woocommerce' ) );
		}

		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, 1024 * 1024 );
			if ( false === $chunk ) {
				break;
			}

			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		fclose( $handle );
		exit;
	}

	private static function get_secret(): string {
		$secret = get_option( Settings::SECRET_OPTION_NAME, '' );
		$secret = is_string( $secret ) ? trim( $secret ) : '';

		if ( '' === $secret ) {
			$secret = substr( wp_hash( (string) home_url( '/' ) ), 0, 16 );
		}

		return $secret;
	}

	private static function write_protection_files( string $directory ): void {
		$directory = rtrim( $directory, '/' );

		if ( '' === $directory ) {
			return;
		}

		$index_path = trailingslashit( $directory ) . 'index.php';
		if ( ! file_exists( $index_path ) ) {
			file_put_contents( $index_path, "<?php\n// Silence is golden.\n" );
		}

		$htaccess_path = trailingslashit( $directory ) . '.htaccess';
		if ( ! file_exists( $htaccess_path ) ) {
			file_put_contents(
				$htaccess_path,
				"Options -Indexes\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n<FilesMatch \"\\.pdf$\">\n  Header set X-Robots-Tag \"noindex, nofollow\"\n</FilesMatch>\n"
			);
		}

		$web_config_path = trailingslashit( $directory ) . 'web.config';
		if ( ! file_exists( $web_config_path ) ) {
			file_put_contents(
				$web_config_path,
				"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <directoryBrowse enabled=\"false\" />\n    <security>\n      <authorization>\n        <add accessType=\"Deny\" users=\"*\" />\n      </authorization>\n    </security>\n  </system.webServer>\n</configuration>\n"
			);
		}
	}
}
