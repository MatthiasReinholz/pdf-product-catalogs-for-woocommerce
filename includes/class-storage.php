<?php

namespace PdfProductCatalogsForWooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Storage {
	private const STORAGE_EXTENSION         = '.ppcfw3bin';
	private const CURRENT_ENCRYPTION_MAGIC  = 'PPCFW3';
	private const SECRETSTREAM_CHUNK_BYTES  = 1048576;
	private const SECRETSTREAM_HEADER_BYTES = 24;
	private const SECRETSTREAM_KEY_BYTES    = 32;
	private const SECRETSTREAM_ABYTES       = 17;

	/**
	 * @return array{basedir:string,dir:string}
	 */
	public static function get_storage_info(): array {
		$upload  = wp_upload_dir( null, false );
		$basedir = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';
		$secret  = self::get_secret();

		if ( '' === $basedir || '' === $secret ) {
			return array(
				'basedir' => $basedir,
				'dir'     => '',
			);
		}

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

	public static function ensure_key(): void {
		$key = get_option( Settings::KEY_OPTION_NAME, '' );
		if ( is_string( $key ) && '' !== trim( $key ) ) {
			return;
		}

		try {
			$encoded = base64_encode( random_bytes( self::SECRETSTREAM_KEY_BYTES ) );
		} catch ( \Throwable $throwable ) {
			$encoded = '';
		}

		if ( '' !== $encoded ) {
			add_option( Settings::KEY_OPTION_NAME, $encoded, '', false );
		}
	}

	public static function ensure_storage_dir(): void {
		self::ensure_secret();
		self::ensure_key();

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

	public static function build_storage_file_name( int $record_id, string $download_name ): string {
		$download_name = sanitize_file_name( $download_name );
		$download_name = preg_replace( '/\.pdf$/i', '', $download_name );
		$download_name = is_string( $download_name ) ? $download_name : 'product-catalog';

		if ( '' === $download_name ) {
			$download_name = 'product-catalog';
		}

		$download_name = substr( $download_name, 0, 80 );

		try {
			$suffix = bin2hex( random_bytes( 6 ) );
		} catch ( \Throwable $throwable ) {
			$suffix = wp_generate_password( 12, false, false );
		}

		return sprintf(
			'%s-%d-%s%s',
			$download_name,
			max( 1, $record_id ),
			sanitize_file_name( $suffix ),
			self::STORAGE_EXTENSION
		);
	}

	public static function absolute_path_for_file_name( string $file_name ): string {
		self::ensure_storage_dir();

		$storage = self::get_storage_info();
		return trailingslashit( $storage['dir'] ) . sanitize_file_name( $file_name );
	}

	public static function relative_path_from_absolute( string $absolute_path ): string {
		$upload  = wp_upload_dir( null, false );
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

	public static function is_encrypted_storage_path( string $relative_path ): bool {
		return str_ends_with( strtolower( $relative_path ), self::STORAGE_EXTENSION );
	}

	public static function stream_pdf_file( string $absolute_path, string $download_name ): void {
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: DENY' );
		header( 'Referrer-Policy: no-referrer' );
		header(
			sprintf(
				'Content-Disposition: attachment; filename="%1$s"; filename*=UTF-8\'\'%2$s',
				str_replace( '"', '', basename( $download_name ) ),
				rawurlencode( basename( $download_name ) )
			)
		);

		$content_length = self::get_decrypted_pdf_length( $absolute_path );
		if ( $content_length > 0 ) {
			header( 'Content-Length: ' . (string) $content_length );
		}

		if ( ! self::stream_decrypted_pdf( $absolute_path ) ) {
			wp_die( esc_html__( 'Failed to read the catalog file.', 'pdf-product-catalogs-for-woocommerce' ) );
		}

		exit;
	}

	public static function delete_private_file( string $absolute_path ): bool {
		if ( '' === $absolute_path || ! file_exists( $absolute_path ) ) {
			return true;
		}

		if ( ! self::is_path_in_storage_dir( $absolute_path ) ) {
			return false;
		}

		return wp_delete_file( $absolute_path );
	}

	/**
	 * @return array{ok:bool,path?:string,error?:string}
	 */
	public static function store_pdf_file( int $record_id, string $source_path, string $download_name ): array {
		if ( '' === $source_path || ! file_exists( $source_path ) ) {
			return array(
				'ok'    => false,
				'error' => 'missing-pdf-file',
			);
		}

		self::ensure_storage_dir();

		$storage_file_name = self::build_storage_file_name( $record_id, $download_name );
		$absolute_path     = self::absolute_path_for_file_name( $storage_file_name );

		if ( ! self::encrypt_pdf_file_to_file( $source_path, $absolute_path ) ) {
			return array(
				'ok'    => false,
				'error' => 'encryption-failed',
			);
		}

		return array(
			'ok'   => true,
			'path' => $absolute_path,
		);
	}

	private static function get_secret(): string {
		$secret = get_option( Settings::SECRET_OPTION_NAME, '' );
		$secret = is_string( $secret ) ? trim( $secret ) : '';

		return $secret;
	}

	private static function get_current_encryption_key(): string {
		$key = get_option( Settings::KEY_OPTION_NAME, '' );
		$key = is_string( $key ) ? trim( $key ) : '';
		if ( '' === $key ) {
			return '';
		}

		$decoded = base64_decode( $key, true );

		return is_string( $decoded ) ? $decoded : '';
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

	private static function get_current_format_content_length( string $absolute_path ): int {
		$size = filesize( $absolute_path );
		if ( false === $size ) {
			return 0;
		}

		$payload_bytes = $size - strlen( self::CURRENT_ENCRYPTION_MAGIC ) - self::SECRETSTREAM_HEADER_BYTES;
		if ( $payload_bytes <= 0 ) {
			return 0;
		}

		$chunk_size     = self::SECRETSTREAM_CHUNK_BYTES + self::SECRETSTREAM_ABYTES;
		$full_chunks    = intdiv( $payload_bytes, $chunk_size );
		$remainder      = $payload_bytes - ( $full_chunks * $chunk_size );
		$content_length = $full_chunks * self::SECRETSTREAM_CHUNK_BYTES;

		if ( $remainder > 0 ) {
			$content_length += max( 0, $remainder - self::SECRETSTREAM_ABYTES );
		}

		return $content_length;
	}

	private static function get_decrypted_pdf_length( string $absolute_path ): int {
		$handle = fopen( $absolute_path, 'rb' );
		if ( false === $handle ) {
			return 0;
		}

		$magic = fread( $handle, strlen( self::CURRENT_ENCRYPTION_MAGIC ) );
		fclose( $handle );

		if ( self::CURRENT_ENCRYPTION_MAGIC === $magic ) {
			return self::get_current_format_content_length( $absolute_path );
		}

		return 0;
	}

	private static function stream_decrypted_pdf( string $absolute_path ): bool {
		$handle = fopen( $absolute_path, 'rb' );
		if ( false === $handle ) {
			return false;
		}

		$magic = fread( $handle, strlen( self::CURRENT_ENCRYPTION_MAGIC ) );
		fclose( $handle );

		if ( self::CURRENT_ENCRYPTION_MAGIC === $magic ) {
			return self::stream_current_format_pdf( $absolute_path );
		}

		return false;
	}

	private static function stream_current_format_pdf( string $absolute_path ): bool {
		if ( ! function_exists( 'sodium_crypto_secretstream_xchacha20poly1305_init_pull' ) ) {
			return false;
		}

		$key = self::get_current_encryption_key();
		if ( strlen( $key ) !== self::SECRETSTREAM_KEY_BYTES ) {
			return false;
		}

		$handle = fopen( $absolute_path, 'rb' );
		if ( false === $handle ) {
			return false;
		}

		$magic  = fread( $handle, strlen( self::CURRENT_ENCRYPTION_MAGIC ) );
		$header = fread( $handle, self::SECRETSTREAM_HEADER_BYTES );

		if ( self::CURRENT_ENCRYPTION_MAGIC !== $magic || ! is_string( $header ) || strlen( $header ) !== self::SECRETSTREAM_HEADER_BYTES ) {
			fclose( $handle );
			return false;
		}

		try {
			$state = sodium_crypto_secretstream_xchacha20poly1305_init_pull( $header, $key );

			while ( ! feof( $handle ) ) {
				$encrypted_chunk = fread( $handle, self::SECRETSTREAM_CHUNK_BYTES + self::SECRETSTREAM_ABYTES );
				if ( false === $encrypted_chunk || '' === $encrypted_chunk ) {
					continue;
				}

				$decrypted = sodium_crypto_secretstream_xchacha20poly1305_pull( $state, $encrypted_chunk );
				if ( false === $decrypted || ! is_array( $decrypted ) || ! isset( $decrypted[0] ) || ! is_string( $decrypted[0] ) ) {
					fclose( $handle );
					return false;
				}

				echo $decrypted[0]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

				if ( isset( $decrypted[1] ) && SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $decrypted[1] ) {
					break;
				}
			}
		} catch ( \Throwable $throwable ) {
			fclose( $handle );
			return false;
		}

		fclose( $handle );

		return true;
	}

	private static function encrypt_pdf_file_to_file( string $source_path, string $absolute_path ): bool {
		if ( ! function_exists( 'sodium_crypto_secretstream_xchacha20poly1305_init_push' ) ) {
			return false;
		}

		$key = self::get_current_encryption_key();
		if ( strlen( $key ) !== self::SECRETSTREAM_KEY_BYTES ) {
			return false;
		}

		$directory = dirname( $absolute_path );
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return false;
		}

		$source_handle = fopen( $source_path, 'rb' );
		if ( false === $source_handle ) {
			return false;
		}

		$target_handle = fopen( $absolute_path, 'wb' );
		if ( false === $target_handle ) {
			fclose( $source_handle );
			return false;
		}

		try {
			list( $state, $header ) = sodium_crypto_secretstream_xchacha20poly1305_init_push( $key );
			if ( false === fwrite( $target_handle, self::CURRENT_ENCRYPTION_MAGIC . $header ) ) {
				fclose( $source_handle );
				fclose( $target_handle );
				wp_delete_file( $absolute_path );
				return false;
			}

			while ( ! feof( $source_handle ) ) {
				$chunk = fread( $source_handle, self::SECRETSTREAM_CHUNK_BYTES );
				if ( false === $chunk ) {
					fclose( $source_handle );
					fclose( $target_handle );
					wp_delete_file( $absolute_path );
					return false;
				}

				if ( '' === $chunk ) {
					continue;
				}

				$tag = feof( $source_handle )
					? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
					: SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
				$encrypted_chunk = sodium_crypto_secretstream_xchacha20poly1305_push( $state, $chunk, '', $tag );

				if ( false === fwrite( $target_handle, $encrypted_chunk ) ) {
					fclose( $source_handle );
					fclose( $target_handle );
					wp_delete_file( $absolute_path );
					return false;
				}
			}
		} catch ( \Throwable $throwable ) {
			fclose( $source_handle );
			fclose( $target_handle );
			wp_delete_file( $absolute_path );
			return false;
		}

		fclose( $source_handle );
		fclose( $target_handle );

		return true;
	}
}
