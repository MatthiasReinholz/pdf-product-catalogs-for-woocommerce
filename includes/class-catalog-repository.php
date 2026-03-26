<?php

namespace PdfProductCatalogsForWooCommerce;

use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Catalog_Repository {
	private const SCHEMA_VERSION     = '1.1.0';
	private const SCHEMA_OPTION_NAME = 'ppcfw_catalogs_schema_version';

	/**
	 * @var array<int,string>
	 */
	private const JSON_COLUMNS = array(
		'excluded_category_ids',
		'attribute_columns',
		'settings_snapshot',
	);

	public const AUTO_STANDARD_KEY = 'auto-standard';

	public static function table_name(): string {
		global $wpdb;

		if ( ! $wpdb instanceof wpdb ) {
			return '';
		}

		return $wpdb->prefix . 'ppcfw_catalogs';
	}

	public static function maybe_create_table(): void {
		global $wpdb;

		if ( ! $wpdb instanceof wpdb ) {
			return;
		}

		$table_name = self::table_name();
		if ( '' === $table_name ) {
			return;
		}

		$current_version = get_option( self::SCHEMA_OPTION_NAME, '' );
		$table_exists    = self::table_exists( $table_name );

		if ( $table_exists && self::SCHEMA_VERSION === $current_version ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			status varchar(20) NOT NULL DEFAULT 'queued',
			client_name varchar(191) NOT NULL DEFAULT '',
			is_client_specific tinyint(1) unsigned NOT NULL DEFAULT 0,
			tax_mode varchar(20) NOT NULL DEFAULT 'including',
			discount_percent decimal(6,2) NOT NULL DEFAULT 0.00,
			is_automatic tinyint(1) unsigned NOT NULL DEFAULT 0,
			catalog_key varchar(64) NOT NULL DEFAULT '',
			source_signature varchar(64) NOT NULL DEFAULT '',
			include_out_of_stock tinyint(1) unsigned NOT NULL DEFAULT 0,
			excluded_category_ids longtext NULL,
			attribute_columns longtext NULL,
			settings_snapshot longtext NULL,
			file_relative_path varchar(255) NOT NULL DEFAULT '',
			file_name varchar(255) NOT NULL DEFAULT '',
			product_count int(10) unsigned NOT NULL DEFAULT 0,
			created_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			completed_at_gmt datetime NULL DEFAULT NULL,
			error_message text NULL,
			PRIMARY KEY  (id),
			KEY status_created (status, created_at_gmt),
			KEY created_at (created_at_gmt),
			KEY catalog_key_status_created (catalog_key, status, created_at_gmt)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::SCHEMA_OPTION_NAME, self::SCHEMA_VERSION, false );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function insert( array $data ): int {
		global $wpdb;

		if ( ! $wpdb instanceof wpdb ) {
			return 0;
		}

		$row = self::encode_json_columns(
			wp_parse_args(
				$data,
				array(
					'status'               => 'queued',
					'client_name'          => '',
					'is_client_specific'   => 0,
					'tax_mode'             => 'including',
					'discount_percent'     => 0,
					'is_automatic'         => 0,
					'catalog_key'          => '',
					'source_signature'     => '',
					'include_out_of_stock' => 0,
					'excluded_category_ids' => array(),
					'attribute_columns'    => array(),
					'settings_snapshot'    => array(),
					'file_relative_path'   => '',
					'file_name'            => '',
					'product_count'        => 0,
					'created_by_user_id'   => get_current_user_id(),
					'created_at_gmt'       => gmdate( 'Y-m-d H:i:s' ),
					'updated_at_gmt'       => gmdate( 'Y-m-d H:i:s' ),
					'completed_at_gmt'     => null,
					'error_message'        => '',
				)
			)
		);

		$result = $wpdb->insert( self::table_name(), $row );

		if ( false === $result ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function get( int $record_id ): ?array {
		global $wpdb;

		if ( $record_id < 1 || ! $wpdb instanceof wpdb ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $record_id ),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return self::normalize_row( $row );
	}

	/**
	 * @param array<int,int> $record_ids
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_by_ids( array $record_ids ): array {
		global $wpdb;

		if ( empty( $record_ids ) || ! $wpdb instanceof wpdb ) {
			return array();
		}

		$record_ids = array_values( array_filter( array_map( 'absint', $record_ids ) ) );
		if ( empty( $record_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $record_ids ), '%d' ) );
		$query        = $wpdb->prepare(
			'SELECT * FROM ' . self::table_name() . " WHERE id IN ({$placeholders}) ORDER BY created_at_gmt DESC",
			$record_ids
		);
		$rows         = $wpdb->get_results( $query, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( self::class, 'normalize_row' ), $rows );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_recent( int $limit = 20 ): array {
		global $wpdb;

		if ( ! $wpdb instanceof wpdb ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' ORDER BY created_at_gmt DESC LIMIT %d',
				max( 1, $limit )
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( self::class, 'normalize_row' ), $rows );
	}

	/**
	 * @param array<string,mixed> $fields
	 */
	public static function update( int $record_id, array $fields ): bool {
		global $wpdb;

		if ( $record_id < 1 || empty( $fields ) || ! $wpdb instanceof wpdb ) {
			return false;
		}

		$fields['updated_at_gmt'] = gmdate( 'Y-m-d H:i:s' );
		$fields                   = self::encode_json_columns( $fields );
		$result                   = $wpdb->update( self::table_name(), $fields, array( 'id' => $record_id ) );

		return false !== $result;
	}

	public static function delete( int $record_id ): bool {
		global $wpdb;

		if ( $record_id < 1 || ! $wpdb instanceof wpdb ) {
			return false;
		}

		$result = $wpdb->delete( self::table_name(), array( 'id' => $record_id ), array( '%d' ) );

		return false !== $result;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function get_latest_completed_by_catalog_key( string $catalog_key ): ?array {
		global $wpdb;

		$catalog_key = sanitize_key( $catalog_key );
		if ( '' === $catalog_key || ! $wpdb instanceof wpdb ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE catalog_key = %s AND status = %s ORDER BY created_at_gmt DESC LIMIT 1',
				$catalog_key,
				'completed'
			),
			ARRAY_A
		);

		return is_array( $row ) ? self::normalize_row( $row ) : null;
	}

	public static function has_pending_by_catalog_key( string $catalog_key ): bool {
		global $wpdb;

		$catalog_key = sanitize_key( $catalog_key );
		if ( '' === $catalog_key || ! $wpdb instanceof wpdb ) {
			return false;
		}

		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE catalog_key = %s AND status IN (%s, %s)',
				$catalog_key,
				'queued',
				'processing'
			)
		);

		return (int) $count > 0;
	}

	public static function has_legacy_storage_records(): bool {
		global $wpdb;

		if ( ! $wpdb instanceof wpdb ) {
			return false;
		}

		$count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE status = %s AND file_relative_path <> %s AND file_relative_path NOT LIKE %s',
					'completed',
					'',
					'%' . $wpdb->esc_like( '.ppcfw3bin' )
				)
			);

		return (int) $count > 0;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_legacy_storage_records( int $limit = 10 ): array {
		global $wpdb;

		if ( ! $wpdb instanceof wpdb ) {
			return array();
		}

		$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . self::table_name() . ' WHERE status = %s AND file_relative_path <> %s AND file_relative_path NOT LIKE %s ORDER BY created_at_gmt DESC LIMIT %d',
					'completed',
					'',
					'%' . $wpdb->esc_like( '.ppcfw3bin' ),
					max( 1, $limit )
				),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( self::class, 'normalize_row' ), $rows );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private static function normalize_row( array $row ): array {
		foreach ( self::JSON_COLUMNS as $column ) {
			if ( isset( $row[ $column ] ) && is_string( $row[ $column ] ) && '' !== $row[ $column ] ) {
				$decoded = json_decode( $row[ $column ], true );
				$row[ $column ] = is_array( $decoded ) ? $decoded : array();
			} elseif ( ! isset( $row[ $column ] ) || null === $row[ $column ] || '' === $row[ $column ] ) {
				$row[ $column ] = array();
			}
		}

		$row['id']                   = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$row['is_client_specific']   = ! empty( $row['is_client_specific'] );
		$row['is_automatic']         = ! empty( $row['is_automatic'] );
		$row['include_out_of_stock'] = ! empty( $row['include_out_of_stock'] );
		$row['discount_percent']     = isset( $row['discount_percent'] ) ? (float) $row['discount_percent'] : 0.0;
		$row['product_count']        = isset( $row['product_count'] ) ? (int) $row['product_count'] : 0;
		$row['created_by_user_id']   = isset( $row['created_by_user_id'] ) ? (int) $row['created_by_user_id'] : 0;

		return $row;
	}

	/**
	 * @param array<string,mixed> $fields
	 * @return array<string,mixed>
	 */
	private static function encode_json_columns( array $fields ): array {
		foreach ( self::JSON_COLUMNS as $column ) {
			if ( array_key_exists( $column, $fields ) && is_array( $fields[ $column ] ) ) {
				$fields[ $column ] = wp_json_encode( $fields[ $column ] );
			}
		}

		return $fields;
	}

	private static function table_exists( string $table_name ): bool {
		global $wpdb;

		if ( ! $wpdb instanceof wpdb || '' === $table_name ) {
			return false;
		}

		$found_table_name = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		return is_string( $found_table_name ) && $found_table_name === $table_name;
	}
}
