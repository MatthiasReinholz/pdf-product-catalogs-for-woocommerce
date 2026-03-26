<?php

namespace PdfProductCatalogsForWooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Storage {
	private const STORAGE_EXTENSION         = '.ppcfw3bin';
	private const LEGACY_STORAGE_EXTENSION  = '.ppcfwbin';
	private const CURRENT_ENCRYPTION_MAGIC  = 'PPCFW3';
	private const LEGACY_ENCRYPTION_MAGIC   = 'PPCFW1';
	private const SECRETSTREAM_CHUNK_BYTES  = 1048576;
	private const SECRETSTREAM_HEADER_BYTES = 24;
	private const SECRETSTREAM_KEY_BYTES    = 32;
	private const SECRETSTREAM_ABYTES       = 17;
	private const LEGACY_NONCE_BYTES        = 12;
	private const LEGACY_TAG_BYTES          = 16;

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

	/**
	 * @return array{ok:bool,path?:string,error?:string}
	 */
	public static function store_pdf_binary( int $record_id, string $pdf_binary, string $download_name ): array {
		if ( '' === $pdf_binary ) {
			return array(
				'ok'    => false,
				'error' => 'empty-pdf-binary',
			);
		}

		self::ensure_storage_dir();

		$storage_file_name = self::build_storage_file_name( $record_id, $download_name );
		$absolute_path     = self::absolute_path_for_file_name( $storage_file_name );

		if ( ! self::encrypt_pdf_binary_to_file( $pdf_binary, $absolute_path ) ) {
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
		$relative_path = strtolower( $relative_path );

		return str_ends_with( $relative_path, self::STORAGE_EXTENSION ) || str_ends_with( $relative_path, self::LEGACY_STORAGE_EXTENSION );
	}

	public static function has_unencrypted_storage_files(): bool {
		return Catalog_Repository::has_legacy_storage_records();
	}

	public static function maybe_schedule_legacy_file_migration(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			static $ran_fallback_batch = false;
			if ( ! $ran_fallback_batch && self::has_unencrypted_storage_files() ) {
				$ran_fallback_batch = true;
				self::migrate_legacy_storage_batch();
			}

			return;
		}

		if ( ! self::has_unencrypted_storage_files() ) {
			return;
		}

		if ( false !== as_next_scheduled_action( Plugin::STORAGE_MIGRATION_HOOK, array(), Plugin::ASYNC_GROUP ) ) {
			return;
		}

		as_enqueue_async_action( Plugin::STORAGE_MIGRATION_HOOK, array(), Plugin::ASYNC_GROUP );
	}

	public static function migrate_legacy_storage_batch(): void {
		$records = Catalog_Repository::get_legacy_storage_records( 10 );

		foreach ( $records as $record ) {
			self::migrate_legacy_record( $record );
		}

		if ( self::has_unencrypted_storage_files() && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( Plugin::STORAGE_MIGRATION_HOOK, array(), Plugin::ASYNC_GROUP );
		}
	}

	public static function stream_pdf_file( string $absolute_path, string $download_name ): void {
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );
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

	private static function get_secret(): string {
		$secret = get_option( Settings::SECRET_OPTION_NAME, '' );
		$secret = is_string( $secret ) ? trim( $secret ) : '';

		if ( '' === $secret ) {
			$secret = substr( wp_hash( (string) home_url( '/' ) ), 0, 16 );
		}

		return $secret;
	}

	private static function get_current_encryption_key(): string {
		self::ensure_key();

		$key = get_option( Settings::KEY_OPTION_NAME, '' );
		$key = is_string( $key ) ? trim( $key ) : '';
		if ( '' === $key ) {
			return '';
		}

		$decoded = base64_decode( $key, true );

		return is_string( $decoded ) ? $decoded : '';
	}

	private static function encrypt_pdf_binary_to_file( string $pdf_binary, string $absolute_path ): bool {
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

		$handle = fopen( $absolute_path, 'wb' );
		if ( false === $handle ) {
			return false;
		}

		try {
			list( $state, $header ) = sodium_crypto_secretstream_xchacha20poly1305_init_push( $key );
			if ( false === fwrite( $handle, self::CURRENT_ENCRYPTION_MAGIC . $header ) ) {
				fclose( $handle );
				wp_delete_file( $absolute_path );
				return false;
			}

			$offset = 0;
			$length = strlen( $pdf_binary );

			do {
				$chunk = substr( $pdf_binary, $offset, self::SECRETSTREAM_CHUNK_BYTES );
				$offset += strlen( $chunk );

				$tag = $offset >= $length
					? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
					: SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
				$encrypted_chunk = sodium_crypto_secretstream_xchacha20poly1305_push( $state, $chunk, '', $tag );

				if ( false === fwrite( $handle, $encrypted_chunk ) ) {
					fclose( $handle );
					wp_delete_file( $absolute_path );
					return false;
				}
			} while ( $offset < $length );
		} catch ( \Throwable $throwable ) {
			fclose( $handle );
			wp_delete_file( $absolute_path );
			return false;
		}

		fclose( $handle );

		return true;
	}

	private static function read_pdf_binary( string $absolute_path ) {
		if ( ! file_exists( $absolute_path ) ) {
			return false;
		}

		$contents = file_get_contents( $absolute_path );
		if ( ! is_string( $contents ) || '' === $contents ) {
			return false;
		}

		return self::decrypt_pdf_binary( $contents );
	}

	private static function decrypt_pdf_binary( string $contents ) {
		if ( str_starts_with( $contents, '%PDF-' ) ) {
			return $contents;
		}

		if ( str_starts_with( $contents, self::CURRENT_ENCRYPTION_MAGIC ) ) {
			return false;
		}

		if ( ! str_starts_with( $contents, self::LEGACY_ENCRYPTION_MAGIC ) || ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}

		$offset = strlen( self::LEGACY_ENCRYPTION_MAGIC );
		$nonce  = substr( $contents, $offset, self::LEGACY_NONCE_BYTES );
		$tag    = substr( $contents, $offset + self::LEGACY_NONCE_BYTES, self::LEGACY_TAG_BYTES );
		$data   = substr( $contents, $offset + self::LEGACY_NONCE_BYTES + self::LEGACY_TAG_BYTES );

		if ( ! is_string( $nonce ) || strlen( $nonce ) !== self::LEGACY_NONCE_BYTES || ! is_string( $tag ) || strlen( $tag ) !== self::LEGACY_TAG_BYTES || ! is_string( $data ) || '' === $data ) {
			return false;
		}

		foreach ( self::get_legacy_decryption_keys() as $key ) {
			$plaintext = openssl_decrypt(
				$data,
				'aes-256-gcm',
				$key,
				OPENSSL_RAW_DATA,
				$nonce,
				$tag
			);

			if ( is_string( $plaintext ) && '' !== $plaintext ) {
				return $plaintext;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $record
	 */
	private static function migrate_legacy_record( array $record ): void {
		$record_id     = isset( $record['id'] ) ? (int) $record['id'] : 0;
		$relative_path = isset( $record['file_relative_path'] ) ? (string) $record['file_relative_path'] : '';
		$download_name = isset( $record['file_name'] ) ? (string) $record['file_name'] : '';
		$absolute_path = self::absolute_path_from_relative( $relative_path );

		if ( $record_id < 1 || '' === $relative_path || '' === $absolute_path || ! self::is_path_in_storage_dir( $absolute_path ) || self::is_encrypted_storage_path( $relative_path ) ) {
			return;
		}

		$pdf_binary = self::read_pdf_binary( $absolute_path );
		if ( false === $pdf_binary ) {
			return;
		}

		if ( '' === $download_name ) {
			$download_name = basename( $relative_path );
		}

		$stored = self::store_pdf_binary( $record_id, $pdf_binary, $download_name );
		if ( empty( $stored['ok'] ) || empty( $stored['path'] ) ) {
			return;
		}

		$new_absolute_path = (string) $stored['path'];
		Catalog_Repository::update(
			$record_id,
			array(
				'file_relative_path' => self::relative_path_from_absolute( $new_absolute_path ),
			)
		);

		if ( $new_absolute_path !== $absolute_path ) {
			self::delete_private_file( $absolute_path );
		}
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

	/**
	 * @return array<int,string>
	 */
	private static function get_legacy_decryption_keys(): array {
		$keys = array();

		$current_key = self::get_current_encryption_key();
		if ( '' !== $current_key ) {
			$keys[] = $current_key;
		}

		$legacy_key = hash( 'sha256', self::get_secret() . '|' . wp_salt( 'auth' ), true );
		if ( '' !== $legacy_key ) {
			$keys[] = $legacy_key;
		}

		return array_values(
			array_unique(
				array_filter(
					$keys,
					static fn ( $key ): bool => is_string( $key ) && '' !== $key
				)
			)
		);
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

		$pdf_binary = self::read_pdf_binary( $absolute_path );

		return is_string( $pdf_binary ) ? strlen( $pdf_binary ) : 0;
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

		$pdf_binary = self::read_pdf_binary( $absolute_path );
		if ( ! is_string( $pdf_binary ) || '' === $pdf_binary ) {
			return false;
		}

		echo $pdf_binary; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		return true;
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
}
