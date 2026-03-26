<?php

namespace PdfProductCatalogsForWooCommerce;

use WC_Product;
use WC_Product_Variable;
use WP_Query;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Catalog_Generator {
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
				'status'               => 'queued',
				'client_name'          => isset( $request['client_name'] ) ? (string) $request['client_name'] : '',
				'is_client_specific'   => 'client-specific' === ( $request['catalog_type'] ?? 'standard' ) ? 1 : 0,
				'tax_mode'             => isset( $request['tax_mode'] ) ? (string) $request['tax_mode'] : 'including',
				'discount_percent'     => isset( $request['discount_percent'] ) ? (float) $request['discount_percent'] : 0,
				'include_out_of_stock' => ! empty( $settings['include_out_of_stock'] ) ? 1 : 0,
				'excluded_category_ids' => isset( $settings['excluded_category_ids'] ) ? (array) $settings['excluded_category_ids'] : array(),
				'attribute_columns'    => isset( $settings['attribute_columns'] ) ? (array) $settings['attribute_columns'] : array(),
				'settings_snapshot'    => $settings,
				'created_by_user_id'   => get_current_user_id(),
				'created_at_gmt'       => $now_gmt,
				'updated_at_gmt'       => $now_gmt,
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

		try {
			$document  = self::build_document( $record );
			$file_name = Storage::build_file_name( (string) $record['client_name'], (string) $record['created_at_gmt'] );
			$file_path = Storage::absolute_path_for_file_name( $file_name );
			$result    = Pdf_Renderer::render_to_path( $document, $file_path );

			if ( empty( $result['ok'] ) || empty( $result['path'] ) ) {
				throw new \RuntimeException( isset( $result['error'] ) ? (string) $result['error'] : 'render-failed' );
			}

			Catalog_Repository::update(
				$record_id,
				array(
					'status'             => 'completed',
					'file_relative_path' => Storage::relative_path_from_absolute( (string) $result['path'] ),
					'file_name'          => $file_name,
					'product_count'      => isset( $document['product_count'] ) ? (int) $document['product_count'] : 0,
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
		}
	}

	/**
	 * @param array<string,mixed> $record
	 * @return array<string,mixed>
	 */
	private static function build_document( array $record ): array {
		$settings_snapshot = isset( $record['settings_snapshot'] ) && is_array( $record['settings_snapshot'] )
			? $record['settings_snapshot']
			: Settings::defaults();
		$attribute_columns = isset( $record['attribute_columns'] ) && is_array( $record['attribute_columns'] )
			? $record['attribute_columns']
			: array();
		$excluded_categories = isset( $record['excluded_category_ids'] ) && is_array( $record['excluded_category_ids'] )
			? array_map( 'absint', $record['excluded_category_ids'] )
			: array();
		$include_out_of_stock = ! empty( $record['include_out_of_stock'] );
		$tax_mode = isset( $record['tax_mode'] ) && 'excluding' === $record['tax_mode'] ? 'excluding' : 'including';
		$discount_percent = isset( $record['discount_percent'] ) ? (float) $record['discount_percent'] : 0.0;

		$products = self::query_products( $include_out_of_stock, $excluded_categories );
		$rows     = array();

		foreach ( $products as $product ) {
			$rows[] = self::build_product_row( $product, $attribute_columns, $tax_mode, $discount_percent );
		}

		$attribute_labels = array();
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

		$scope_labels = array(
			$include_out_of_stock
				? __( 'Includes out-of-stock products', 'pdf-product-catalogs-for-woocommerce' )
				: __( 'In-stock products only', 'pdf-product-catalogs-for-woocommerce' ),
		);

		if ( ! empty( $excluded_categories ) ) {
			$scope_labels[] = sprintf(
				/* translators: %d category count */
				_n( '%d category excluded', '%d categories excluded', count( $excluded_categories ), 'pdf-product-catalogs-for-woocommerce' ),
				count( $excluded_categories )
			);
		}

		return array(
			'title'              => $title,
			'header_text'        => isset( $settings_snapshot['header_text'] ) ? (string) $settings_snapshot['header_text'] : '',
			'footer_text'        => isset( $settings_snapshot['footer_text'] ) ? (string) $settings_snapshot['footer_text'] : '',
			'generated_label'    => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $generated_timestamp ),
			'price_basis_label'  => 'excluding' === $tax_mode
				? __( 'Prices shown excluding tax', 'pdf-product-catalogs-for-woocommerce' )
				: __( 'Prices shown including tax', 'pdf-product-catalogs-for-woocommerce' ),
			'discount_label'     => $discount_percent > 0
				? sprintf(
					/* translators: %s discount percentage */
					__( 'Client discount applied: %s%%', 'pdf-product-catalogs-for-woocommerce' ),
					number_format_i18n( $discount_percent, 2 )
				)
				: __( 'No client discount applied', 'pdf-product-catalogs-for-woocommerce' ),
			'scope_labels'       => $scope_labels,
			'attribute_columns'  => $attribute_labels,
			'rows'               => $rows,
			'product_count'      => count( $rows ),
		);
	}

	/**
	 * @param array<int,int> $excluded_categories
	 * @return array<int,WC_Product>
	 */
	private static function query_products( bool $include_out_of_stock, array $excluded_categories ): array {
		$args = array(
			'status' => 'publish',
			'limit'  => -1,
			'return' => 'objects',
			'type'   => array( 'simple', 'variable', 'external' ),
			'orderby' => 'title',
			'order'  => 'ASC',
		);

		$products = wc_get_products( $args );
		if ( ! is_array( $products ) ) {
			return array();
		}

		$filtered = array();

		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
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

			$filtered[] = $product;
		}

		usort(
			$filtered,
			static function ( WC_Product $left, WC_Product $right ): int {
				return strcasecmp( $left->get_name(), $right->get_name() );
			}
		);

		return $filtered;
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

	/**
	 * @param array<int,string> $attribute_columns
	 * @return array<string,mixed>
	 */
	private static function build_product_row( WC_Product $product, array $attribute_columns, string $tax_mode, float $discount_percent ): array {
		$attributes = array();
		foreach ( $attribute_columns as $taxonomy ) {
			$attributes[] = self::get_attribute_value( $product, $taxonomy );
		}

		return array(
			'image_data_uri' => self::get_image_data_uri( $product ),
			'name'           => $product->get_name(),
			'sku'            => (string) $product->get_sku(),
			'gtin'           => (string) $product->get_global_unique_id(),
			'price'          => self::get_price_label( $product, $tax_mode, $discount_percent ),
			'product_url'    => self::get_public_product_url( $product ),
			'attributes'     => $attributes,
		);
	}

	private static function get_attribute_value( WC_Product $product, string $taxonomy ): string {
		$attributes = $product->get_attributes();
		if ( empty( $attributes ) ) {
			return '';
		}

		foreach ( $attributes as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}

			if ( $attribute->get_name() !== $taxonomy ) {
				continue;
			}

			if ( $attribute->is_taxonomy() ) {
				$values = wc_get_product_terms( $product->get_id(), $taxonomy, array( 'fields' => 'names' ) );
				return is_array( $values ) ? implode( ', ', array_map( 'strval', $values ) ) : '';
			}

			$options = $attribute->get_options();
			return is_array( $options ) ? implode( ', ', array_map( 'strval', $options ) ) : '';
		}

		return '';
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

		$path = get_attached_file( $image_id );
		if ( ! is_string( $path ) || '' === $path || ! file_exists( $path ) ) {
			return '';
		}

		$mime = wp_check_filetype( $path );
		$type = isset( $mime['type'] ) ? (string) $mime['type'] : '';
		if ( '' === $type ) {
			return '';
		}

		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return '';
		}

		return 'data:' . $type . ';base64,' . base64_encode( $contents );
	}
}
