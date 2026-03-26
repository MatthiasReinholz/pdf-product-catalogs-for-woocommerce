<?php

namespace PdfProductCatalogsForWooCommerce;

use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Catalog_Generator {
	private const PRODUCT_BATCH_SIZE = 100;
	private const MAX_EMBEDDED_IMAGE_BYTES = 1048576;

	/**
	 * @var array<int,string>
	 */
	private static array $image_data_uri_cache = array();

	/**
	 * @param array<string,mixed> $request
	 * @return array{ok:bool,record_id?:int,error?:string}
	 */
	public static function queue( array $request ): array {
		Settings::maybe_initialize();
		Storage::ensure_storage_dir();

		$settings = Settings::get_all();
		$now_gmt  = gmdate( 'Y-m-d H:i:s' );

		$record_id = Catalog_Repository::insert(
			array(
				'status'                => 'queued',
				'client_name'           => isset( $request['client_name'] ) ? (string) $request['client_name'] : '',
				'is_client_specific'    => 'client-specific' === ( $request['catalog_type'] ?? 'standard' ) ? 1 : 0,
				'tax_mode'              => isset( $request['tax_mode'] ) ? (string) $request['tax_mode'] : 'including',
				'discount_percent'      => isset( $request['discount_percent'] ) ? (float) $request['discount_percent'] : 0,
				'is_automatic'          => ! empty( $request['is_automatic'] ) ? 1 : 0,
				'catalog_key'           => isset( $request['catalog_key'] ) ? sanitize_key( (string) $request['catalog_key'] ) : '',
				'source_signature'      => isset( $request['source_signature'] ) ? sanitize_text_field( (string) $request['source_signature'] ) : '',
				'include_out_of_stock'  => ! empty( $settings['include_out_of_stock'] ) ? 1 : 0,
				'excluded_category_ids' => isset( $settings['excluded_category_ids'] ) ? (array) $settings['excluded_category_ids'] : array(),
				'attribute_columns'     => isset( $settings['attribute_columns'] ) ? (array) $settings['attribute_columns'] : array(),
				'settings_snapshot'     => $settings,
				'created_by_user_id'    => isset( $request['created_by_user_id'] ) ? absint( $request['created_by_user_id'] ) : get_current_user_id(),
				'created_at_gmt'        => $now_gmt,
				'updated_at_gmt'        => $now_gmt,
			)
		);

		if ( $record_id < 1 ) {
			return array(
				'ok'    => false,
				'error' => 'insert-failed',
			);
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				Plugin::ASYNC_ACTION,
				array( 'record_id' => $record_id ),
				Plugin::ASYNC_GROUP
			);
		} else {
			self::process_record( $record_id );
		}

		return array(
			'ok'        => true,
			'record_id' => $record_id,
		);
	}

	public static function maybe_queue_daily_standard_catalog(): void {
		if ( ! Plugin::is_woocommerce_available() ) {
			return;
		}

		$settings = Settings::get_all();
		if ( empty( $settings['enable_daily_automatic_catalog'] ) ) {
			return;
		}

		$catalog_key = Catalog_Repository::AUTO_STANDARD_KEY;
		if ( Catalog_Repository::has_pending_by_catalog_key( $catalog_key ) ) {
			return;
		}

		$signature = self::build_standard_source_signature( $settings );
		$current   = Catalog_Repository::get_latest_completed_by_catalog_key( $catalog_key );

		if ( is_array( $current ) && isset( $current['source_signature'] ) && (string) $current['source_signature'] === $signature ) {
			return;
		}

		self::queue(
			array(
				'catalog_type'      => 'standard',
				'client_name'       => '',
				'tax_mode'          => 'including',
				'discount_percent'  => 0,
				'is_automatic'      => true,
				'catalog_key'       => $catalog_key,
				'source_signature'  => $signature,
				'created_by_user_id' => 0,
			)
		);
	}

	public static function process_record_action( int $record_id ): void {
		self::process_record( $record_id );
	}

	public static function get_status_label( string $status ): string {
		switch ( $status ) {
			case 'queued':
				return __( 'Queued', 'pdf-product-catalogs-for-woocommerce' );
			case 'processing':
				return __( 'Generating', 'pdf-product-catalogs-for-woocommerce' );
			case 'completed':
				return __( 'Completed', 'pdf-product-catalogs-for-woocommerce' );
			case 'failed':
				return __( 'Failed', 'pdf-product-catalogs-for-woocommerce' );
			default:
				return __( 'Unknown', 'pdf-product-catalogs-for-woocommerce' );
		}
	}

	private static function process_record( int $record_id ): void {
		self::prepare_runtime();

		$record = Catalog_Repository::get( $record_id );
		if ( null === $record ) {
			return;
		}

		Catalog_Repository::update(
			$record_id,
			array(
				'status'        => 'processing',
				'error_message' => '',
			)
		);

		$html_path     = '';
		$pdf_path      = '';
		$product_count = 0;

		try {
			$html_result   = self::build_document_html_file( $record );
			$html_path     = isset( $html_result['path'] ) ? (string) $html_result['path'] : '';
			$product_count = isset( $html_result['product_count'] ) ? (int) $html_result['product_count'] : 0;
			$file_name     = Storage::build_file_name( (string) $record['client_name'], (string) $record['created_at_gmt'] );
			$result        = Pdf_Renderer::render_html_file( $html_path );

			if ( empty( $result['ok'] ) || empty( $result['path'] ) || ! is_string( $result['path'] ) ) {
				throw new \RuntimeException( isset( $result['error'] ) ? (string) $result['error'] : 'render-failed' );
			}

			$pdf_path = $result['path'];
			$stored   = Storage::store_pdf_file( $record_id, $pdf_path, $file_name );
			if ( empty( $stored['ok'] ) || empty( $stored['path'] ) || ! is_string( $stored['path'] ) ) {
				throw new \RuntimeException( isset( $stored['error'] ) ? (string) $stored['error'] : 'storage-failed' );
			}

			Catalog_Repository::update(
				$record_id,
					array(
						'status'             => 'completed',
						'file_relative_path' => Storage::relative_path_from_absolute( $stored['path'] ),
						'file_name'          => $file_name,
						'product_count'      => $product_count,
						'completed_at_gmt'   => gmdate( 'Y-m-d H:i:s' ),
						'error_message'      => '',
					)
				);
		} catch ( \Throwable $throwable ) {
			Catalog_Repository::update(
				$record_id,
				array(
					'status'        => 'failed',
					'error_message' => wp_strip_all_tags( $throwable->getMessage() ),
				)
			);
		} finally {
			if ( '' !== $html_path && file_exists( $html_path ) ) {
				wp_delete_file( $html_path );
			}

			if ( '' !== $pdf_path && file_exists( $pdf_path ) ) {
				wp_delete_file( $pdf_path );
			}
		}
	}

	/**
	 * @param array<string,mixed> $record
	 * @return array{path:string,product_count:int}
	 */
	private static function build_document_html_file( array $record ): array {
		$settings_snapshot   = isset( $record['settings_snapshot'] ) && is_array( $record['settings_snapshot'] ) ? $record['settings_snapshot'] : Settings::defaults();
		$attribute_columns   = isset( $record['attribute_columns'] ) && is_array( $record['attribute_columns'] ) ? $record['attribute_columns'] : array();
		$excluded_categories = isset( $record['excluded_category_ids'] ) && is_array( $record['excluded_category_ids'] ) ? array_map( 'absint', $record['excluded_category_ids'] ) : array();
		$include_out_of_stock = ! empty( $record['include_out_of_stock'] );
		$tax_mode            = isset( $record['tax_mode'] ) && 'excluding' === $record['tax_mode'] ? 'excluding' : 'including';
		$discount_percent    = isset( $record['discount_percent'] ) ? (float) $record['discount_percent'] : 0.0;
		$product_count       = self::count_products( $include_out_of_stock, $excluded_categories );
		$document            = self::build_document_context(
			$record,
			$settings_snapshot,
			$attribute_columns,
			$excluded_categories,
			$tax_mode,
			$discount_percent,
			$product_count
		);

		$html_path = self::create_temp_html_path();
		$handle    = fopen( $html_path, 'wb' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'html-open-failed' );
		}

		try {
			self::write_html_chunk(
				$handle,
				self::render_template(
					'catalog-start.php',
					array(
						'document_data' => $document,
					)
				)
			);

			self::walk_products(
				$include_out_of_stock,
				$excluded_categories,
				static function ( WC_Product $product ) use ( $handle, $document, $attribute_columns, $tax_mode, $discount_percent ): void {
					$row = self::build_product_row( $product, $attribute_columns, $tax_mode, $discount_percent );
					self::write_html_chunk(
						$handle,
						self::render_template(
							'catalog-row-group.php',
							array(
								'document_data' => $document,
								'row'           => $row,
							)
						)
					);
				}
			);

			self::write_html_chunk( $handle, self::render_template( 'catalog-end.php', array() ) );
		} catch ( \Throwable $throwable ) {
			fclose( $handle );
			wp_delete_file( $html_path );
			throw $throwable;
		}

		fclose( $handle );

		return array(
			'path'          => $html_path,
			'product_count' => $product_count,
		);
	}

	/**
	 * @param array<string,mixed> $record
	 * @param array<string,mixed> $settings_snapshot
	 * @param array<int,string>   $attribute_columns
	 * @param array<int,int>      $excluded_categories
	 * @return array<string,mixed>
	 */
	private static function build_document_context( array $record, array $settings_snapshot, array $attribute_columns, array $excluded_categories, string $tax_mode, float $discount_percent, int $product_count ): array {
		$attribute_labels  = array();
		$attribute_options = Settings::get_attribute_column_options();

		foreach ( $attribute_columns as $taxonomy ) {
			$attribute_labels[] = array(
				'taxonomy' => $taxonomy,
				'label'    => $attribute_options[ $taxonomy ] ?? $taxonomy,
			);
		}

		$generated_timestamp = strtotime( (string) $record['created_at_gmt'] . ' GMT' );
		$generated_timestamp = false !== $generated_timestamp ? $generated_timestamp : time();
		$client_name         = isset( $record['client_name'] ) ? trim( (string) $record['client_name'] ) : '';
		$title               = '' !== $client_name
			? sprintf(
				/* translators: %s client name */
				__( 'Product Catalog for %s', 'pdf-product-catalogs-for-woocommerce' ),
				$client_name
			)
			: __( 'Product Catalog', 'pdf-product-catalogs-for-woocommerce' );
		$scope_labels        = array();

		if ( ! empty( $excluded_categories ) ) {
			$scope_labels[] = sprintf(
				/* translators: %d category count */
				_n( '%d category excluded', '%d categories excluded', count( $excluded_categories ), 'pdf-product-catalogs-for-woocommerce' ),
				count( $excluded_categories )
			);
		}

		return array(
			'site_title'        => (string) get_option( 'blogname', '' ),
			'title'             => $title,
			'header_text'       => isset( $settings_snapshot['header_text'] ) ? (string) $settings_snapshot['header_text'] : '',
			'footer_text'       => isset( $settings_snapshot['footer_text'] ) ? (string) $settings_snapshot['footer_text'] : '',
			'generated_label'   => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $generated_timestamp ),
			'price_basis_label' => 'excluding' === $tax_mode
				? __( 'Prices shown excluding tax', 'pdf-product-catalogs-for-woocommerce' )
				: __( 'Prices shown including tax', 'pdf-product-catalogs-for-woocommerce' ),
			'discount_label'    => $discount_percent > 0
				? sprintf(
					/* translators: %s discount percentage */
					__( 'Client discount applied: %s%%', 'pdf-product-catalogs-for-woocommerce' ),
					number_format_i18n( $discount_percent, 2 )
				)
				: '',
			'scope_labels'      => $scope_labels,
			'attribute_columns' => $attribute_labels,
			'product_count'     => $product_count,
		);
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private static function build_standard_source_signature( array $settings ): string {
		$include_out_of_stock = ! empty( $settings['include_out_of_stock'] );
		$excluded_categories  = isset( $settings['excluded_category_ids'] ) && is_array( $settings['excluded_category_ids'] )
			? array_map( 'absint', $settings['excluded_category_ids'] )
			: array();
		$attribute_columns    = isset( $settings['attribute_columns'] ) && is_array( $settings['attribute_columns'] )
			? array_values( array_map( 'strval', $settings['attribute_columns'] ) )
			: array();
		$attribute_options    = Settings::get_attribute_column_options();

		$settings_fingerprint = array(
			'header_text'           => isset( $settings['header_text'] ) ? (string) $settings['header_text'] : '',
			'footer_text'           => isset( $settings['footer_text'] ) ? (string) $settings['footer_text'] : '',
			'include_out_of_stock'  => $include_out_of_stock,
			'excluded_category_ids' => $excluded_categories,
			'attribute_columns'     => $attribute_columns,
			'attribute_labels'      => array_map(
				static fn ( string $taxonomy ): string => (string) ( $attribute_options[ $taxonomy ] ?? $taxonomy ),
				$attribute_columns
			),
			'site_title'            => (string) get_option( 'blogname', '' ),
			'currency'              => array(
				'code'         => get_woocommerce_currency(),
				'position'     => get_option( 'woocommerce_currency_pos', 'left' ),
				'decimal_sep'  => wc_get_price_decimal_separator(),
				'thousand_sep' => wc_get_price_thousand_separator(),
				'decimals'     => wc_get_price_decimals(),
			),
			'tax_mode'              => 'including',
		);

		$hash = hash_init( 'sha1' );
		hash_update(
			$hash,
			(string) wp_json_encode(
				array(
					'settings' => $settings_fingerprint,
				)
			)
		);

		self::walk_products(
			$include_out_of_stock,
			$excluded_categories,
			static function ( WC_Product $product ) use ( &$hash, $attribute_columns ): void {
				hash_update(
					$hash,
					"\n" . (string) wp_json_encode(
						self::build_product_signature_payload( $product, $attribute_columns, 'including', 0.0 )
					)
				);
			}
		);

		return hash_final( $hash );
	}

	private static function count_products( bool $include_out_of_stock, array $excluded_categories ): int {
		return self::walk_products(
			$include_out_of_stock,
			$excluded_categories,
			static function ( WC_Product $unused_product ): void {
				unset( $unused_product );
			}
		);
	}

	/**
	 * @param array<int,int> $excluded_categories
	 */
	private static function walk_products( bool $include_out_of_stock, array $excluded_categories, callable $callback ): int {
		$processed = 0;
		$last_id   = 0;

		do {
			$product_ids = self::query_product_batch_ids_after( $last_id );
			if ( empty( $product_ids ) ) {
				break;
			}

			foreach ( $product_ids as $product_id ) {
				$last_id = max( $last_id, $product_id );

				$product = wc_get_product( $product_id );
				if ( ! $product instanceof WC_Product ) {
					continue;
				}

				if ( ! in_array( $product->get_type(), array( 'simple', 'variable', 'external' ), true ) ) {
					continue;
				}

				if ( ! in_array( $product->get_catalog_visibility(), array( 'visible', 'catalog' ), true ) ) {
					continue;
				}

				if ( ! $include_out_of_stock && ! $product->is_in_stock() ) {
					continue;
				}

				if ( self::product_has_excluded_category( $product, $excluded_categories ) ) {
					continue;
				}

				++$processed;
				$callback( $product );
			}

			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		} while ( count( $product_ids ) === self::PRODUCT_BATCH_SIZE );

		return $processed;
	}

	/**
	 * @return array<int,int>
	 */
	private static function query_product_batch_ids_after( int $last_id ): array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return array();
		}

			$product_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
					'product',
				'publish',
				max( 0, $last_id ),
				self::PRODUCT_BATCH_SIZE
			)
		);

		if ( ! is_array( $product_ids ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', $product_ids ) ) );
	}

	/**
	 * @param array<int,string> $attribute_columns
	 * @return array<string,mixed>
	 */
	private static function build_product_row( WC_Product $product, array $attribute_columns, string $tax_mode, float $discount_percent ): array {
		$attributes = array();
		foreach ( $attribute_columns as $taxonomy ) {
			$attributes[] = self::get_attribute_value( $product, $taxonomy );
		}

		$variant_rows = array();
		$price        = self::get_price_label( $product, $tax_mode, $discount_percent );

		if ( $product instanceof WC_Product_Variable ) {
			$variant_rows = self::build_variation_rows( $product, $attribute_columns, $tax_mode, $discount_percent );
			if ( ! empty( $variant_rows ) ) {
				$price = '';
			}
		}

		return array(
			'image_data_uri'  => self::get_image_data_uri( $product ),
			'name'            => $product->get_name(),
			'sku'             => (string) $product->get_sku(),
			'gtin'            => (string) $product->get_global_unique_id(),
			'price'           => $price,
			'product_url'     => self::get_public_product_url( $product ),
			'attributes'      => $attributes,
			'is_out_of_stock' => ! $product->is_in_stock(),
			'variant_rows'    => $variant_rows,
		);
	}

	/**
	 * @param array<int,string> $attribute_columns
	 * @return array<int,array<string,mixed>>
	 */
	private static function build_variation_rows( WC_Product_Variable $product, array $attribute_columns, string $tax_mode, float $discount_percent ): array {
		$variation_rows = array();

		foreach ( $product->get_visible_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}

			$attributes = array();
			foreach ( $attribute_columns as $taxonomy ) {
				$attributes[] = self::get_attribute_value( $variation, $taxonomy );
			}

			$variation_rows[] = array(
				'image_data_uri'  => self::get_image_data_uri( $variation ),
				'name'            => $variation->get_name(),
				'sku'             => (string) $variation->get_sku(),
				'gtin'            => (string) $variation->get_global_unique_id(),
				'attributes'      => $attributes,
				'price'           => self::get_price_label( $variation, $tax_mode, $discount_percent ),
				'product_url'     => self::get_public_product_url( $variation ),
				'is_out_of_stock' => ! $variation->is_in_stock(),
			);
		}

		return $variation_rows;
	}

	private static function get_attribute_value( WC_Product $product, string $taxonomy ): string {
		$value = trim( wp_strip_all_tags( (string) $product->get_attribute( $taxonomy ) ) );
		if ( '' !== $value ) {
			return $value;
		}

		if ( $product instanceof WC_Product_Variation ) {
			$parent_id = $product->get_parent_id();
			if ( $parent_id > 0 ) {
				$parent = wc_get_product( $parent_id );
				if ( $parent instanceof WC_Product ) {
					return trim( wp_strip_all_tags( (string) $parent->get_attribute( $taxonomy ) ) );
				}
			}
		}

		return '';
	}

	/**
	 * @param array<int,string> $attribute_columns
	 * @return array<string,mixed>
	 */
	private static function build_product_signature_payload( WC_Product $product, array $attribute_columns, string $tax_mode, float $discount_percent ): array {
		$attributes = array();
		foreach ( $attribute_columns as $taxonomy ) {
			$attributes[] = self::get_attribute_value( $product, $taxonomy );
		}

		$payload = array(
			'id'              => $product->get_id(),
			'type'            => $product->get_type(),
			'name'            => $product->get_name(),
			'sku'             => (string) $product->get_sku(),
			'gtin'            => (string) $product->get_global_unique_id(),
			'modified'        => get_post_modified_time( 'c', true, $product->get_id() ),
			'price_label'     => self::get_price_label( $product, $tax_mode, $discount_percent ),
			'stock'           => $product->get_stock_status(),
			'visibility'      => $product->get_catalog_visibility(),
			'public_url'      => self::get_public_product_url( $product ),
			'attributes'      => $attributes,
			'image_signature' => self::get_image_signature( $product ),
		);

		if ( $product instanceof WC_Product_Variable ) {
			$children = array();

			foreach ( $product->get_visible_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation instanceof WC_Product_Variation ) {
					continue;
				}

				$children[] = array(
					'id'              => $variation->get_id(),
					'name'            => $variation->get_name(),
					'sku'             => (string) $variation->get_sku(),
					'gtin'            => (string) $variation->get_global_unique_id(),
					'modified'        => get_post_modified_time( 'c', true, $variation->get_id() ),
					'price_label'     => self::get_price_label( $variation, $tax_mode, $discount_percent ),
					'stock'           => $variation->get_stock_status(),
					'public_url'      => self::get_public_product_url( $variation ),
					'attrs'           => array_map(
						static fn ( string $taxonomy ): string => self::get_attribute_value( $variation, $taxonomy ),
						$attribute_columns
					),
					'image_signature' => self::get_image_signature( $variation ),
				);
			}

			$payload['children']    = $children;
			$payload['price_label'] = empty( $children ) ? $payload['price_label'] : '';
		}

		return $payload;
	}

	/**
	 * @param array<int,int> $excluded_categories
	 */
	private static function product_has_excluded_category( WC_Product $product, array $excluded_categories ): bool {
		if ( empty( $excluded_categories ) ) {
			return false;
		}

		$product_categories = wc_get_product_term_ids( $product->get_id(), 'product_cat' );
		if ( empty( $product_categories ) ) {
			return false;
		}

		return ! empty( array_intersect( $excluded_categories, array_map( 'absint', $product_categories ) ) );
	}

	private static function get_price_label( WC_Product $product, string $tax_mode, float $discount_percent ): string {
		if ( $product instanceof WC_Product_Variable ) {
			$min = (float) $product->get_variation_price( 'min', false );
			$max = (float) $product->get_variation_price( 'max', false );

			$min = self::apply_tax_mode( $product, $min, $tax_mode );
			$max = self::apply_tax_mode( $product, $max, $tax_mode );
			$min = self::apply_discount( $min, $discount_percent );
			$max = self::apply_discount( $max, $discount_percent );

			if ( abs( $min - $max ) < 0.0001 ) {
				return wp_strip_all_tags( wc_price( $min ) );
			}

			return wp_strip_all_tags( wc_format_price_range( wc_price( $min ), wc_price( $max ) ) );
		}

		$price = $product->get_price();
		if ( '' === $price ) {
			return __( 'See store', 'pdf-product-catalogs-for-woocommerce' );
		}

		$display_price = self::apply_tax_mode( $product, (float) $price, $tax_mode );
		$display_price = self::apply_discount( $display_price, $discount_percent );

		return wp_strip_all_tags( wc_price( $display_price ) );
	}

	private static function apply_tax_mode( WC_Product $product, float $price, string $tax_mode ): float {
		if ( 'excluding' === $tax_mode ) {
			return (float) wc_get_price_excluding_tax(
				$product,
				array(
					'price' => $price,
					'qty'   => 1,
				)
			);
		}

		return (float) wc_get_price_including_tax(
			$product,
			array(
				'price' => $price,
				'qty'   => 1,
			)
		);
	}

	private static function apply_discount( float $price, float $discount_percent ): float {
		if ( $discount_percent <= 0 ) {
			return $price;
		}

		return max( 0, $price * ( 1 - ( $discount_percent / 100 ) ) );
	}

	private static function get_public_product_url( WC_Product $product ): string {
		if ( 'publish' !== $product->get_status() || ! $product->is_in_stock() ) {
			return '';
		}

		if ( ! in_array( $product->get_catalog_visibility(), array( 'visible', 'catalog', 'search' ), true ) ) {
			return '';
		}

		$url = get_permalink( $product->get_id() );

		return is_string( $url ) ? $url : '';
	}

	private static function get_image_data_uri( WC_Product $product ): string {
		$image_id = $product->get_image_id();
		if ( $image_id < 1 ) {
			return '';
		}

		if ( isset( self::$image_data_uri_cache[ $image_id ] ) ) {
			return self::$image_data_uri_cache[ $image_id ];
		}

		$path = self::resolve_attachment_path( $image_id );
		if ( '' === $path || ! file_exists( $path ) ) {
			self::$image_data_uri_cache[ $image_id ] = '';
			return '';
		}

		$file_size = filesize( $path );
		if ( false !== $file_size && $file_size > self::MAX_EMBEDDED_IMAGE_BYTES ) {
			self::$image_data_uri_cache[ $image_id ] = '';
			return '';
		}

		$mime = wp_check_filetype( $path );
		$type = isset( $mime['type'] ) ? (string) $mime['type'] : '';
		if ( '' === $type ) {
			self::$image_data_uri_cache[ $image_id ] = '';
			return '';
		}

		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			self::$image_data_uri_cache[ $image_id ] = '';
			return '';
		}

		self::$image_data_uri_cache[ $image_id ] = 'data:' . $type . ';base64,' . base64_encode( $contents );

		return self::$image_data_uri_cache[ $image_id ];
	}

	private static function get_image_signature( WC_Product $product ): string {
		$image_id = $product->get_image_id();
		if ( $image_id < 1 ) {
			return '';
		}

		$path = self::resolve_attachment_path( $image_id );
		if ( '' === $path || ! file_exists( $path ) ) {
			return (string) $image_id;
		}

		$mtime = filemtime( $path );

		return implode(
			':',
			array(
				(string) $image_id,
				basename( $path ),
				false !== $mtime ? (string) $mtime : '',
			)
		);
	}

	private static function resolve_attachment_path( int $image_id ): string {
		$original_path = get_attached_file( $image_id );
		if ( ! is_string( $original_path ) || '' === $original_path ) {
			return '';
		}

		$metadata = wp_get_attachment_metadata( $image_id );
		if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return $original_path;
		}

		$directory  = trailingslashit( dirname( $original_path ) );
		$candidates = array();
		$preferred  = array( 'woocommerce_thumbnail', 'thumbnail', 'medium', 'woocommerce_single' );

		foreach ( $preferred as $size_name ) {
			if ( empty( $metadata['sizes'][ $size_name ]['file'] ) ) {
				continue;
			}

			$candidate = $directory . $metadata['sizes'][ $size_name ]['file'];
			if ( file_exists( $candidate ) ) {
				$candidates[] = $candidate;
			}
		}

		if ( empty( $candidates ) ) {
			foreach ( $metadata['sizes'] as $size_data ) {
				if ( empty( $size_data['file'] ) || ! is_string( $size_data['file'] ) ) {
					continue;
				}

				$candidate = $directory . $size_data['file'];
				if ( file_exists( $candidate ) ) {
					$candidates[] = $candidate;
				}
			}
		}

		if ( empty( $candidates ) ) {
			return $original_path;
		}

		usort(
			$candidates,
			static function ( string $left, string $right ): int {
				$left_size  = filesize( $left );
				$right_size = filesize( $right );

				return (int) ( $left_size <=> $right_size );
			}
		);

		return $candidates[0];
	}

	/**
	 * @param array<string,mixed> $variables
	 */
	private static function render_template( string $template_name, array $variables ): string {
		$template_path = PDF_PRODUCT_CATALOGS_FOR_WOOCOMMERCE_DIR . 'templates/' . $template_name;
		if ( ! file_exists( $template_path ) ) {
			throw new \RuntimeException( 'missing-template:' . $template_name );
		}

		extract( $variables, EXTR_SKIP );

		ob_start();
		include $template_path;

		return (string) ob_get_clean();
	}

	/**
	 * @param resource $handle
	 */
	private static function write_html_chunk( $handle, string $html ): void {
		if ( false === fwrite( $handle, $html ) ) {
			throw new \RuntimeException( 'html-write-failed' );
		}
	}

	private static function create_temp_html_path(): string {
		$tmp_base = defined( 'WP_TEMP_DIR' ) && is_string( WP_TEMP_DIR ) && '' !== trim( WP_TEMP_DIR )
			? (string) WP_TEMP_DIR
			: (string) sys_get_temp_dir();
		$tmp_dir  = trailingslashit( rtrim( $tmp_base, '/' ) ) . 'pdf-product-catalogs-for-woocommerce';

		if ( ! is_dir( $tmp_dir ) ) {
			wp_mkdir_p( $tmp_dir );
		}

		$temp_path = tempnam( $tmp_dir, 'catalog-html-' );
		if ( false === $temp_path ) {
			throw new \RuntimeException( 'html-tempnam-failed' );
		}

		$html_path = $temp_path . '.html';
		if ( ! @rename( $temp_path, $html_path ) ) {
			wp_delete_file( $temp_path );
			throw new \RuntimeException( 'html-tempfile-rename-failed' );
		}

		return $html_path;
	}

	private static function prepare_runtime(): void {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 0 );
		} elseif ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
