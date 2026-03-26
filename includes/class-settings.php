<?php

namespace PdfProductCatalogsForWooCommerce;

use WC_Product_Attribute;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {
	public const OPTION_NAME = 'ppcfw_settings';
	public const SECRET_OPTION_NAME = 'ppcfw_storage_secret';

	public static function defaults(): array {
		return array(
			'header_text'          => '',
			'footer_text'          => '',
			'include_out_of_stock' => false,
			'excluded_category_ids' => array(),
			'attribute_columns'    => array(),
		);
	}

	public static function maybe_initialize(): void {
		if ( null === get_option( self::OPTION_NAME, null ) ) {
			add_option( self::OPTION_NAME, self::defaults(), '', false );
		}
	}

	public static function register(): void {
		register_setting(
			'ppcfw_settings',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function get_all(): array {
		$settings = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return wp_parse_args( $settings, self::defaults() );
	}

	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();

		$sanitized = array(
			'header_text'           => isset( $input['header_text'] ) ? wp_kses_post( (string) $input['header_text'] ) : $defaults['header_text'],
			'footer_text'           => isset( $input['footer_text'] ) ? wp_kses_post( (string) $input['footer_text'] ) : $defaults['footer_text'],
			'include_out_of_stock'  => ! empty( $input['include_out_of_stock'] ),
			'excluded_category_ids' => array(),
			'attribute_columns'     => array(),
		);

		$valid_category_ids = array();
		if ( isset( $input['excluded_category_ids'] ) && is_array( $input['excluded_category_ids'] ) ) {
			foreach ( $input['excluded_category_ids'] as $term_id ) {
				$term_id = absint( $term_id );
				if ( $term_id > 0 ) {
					$valid_category_ids[] = $term_id;
				}
			}
		}
		$sanitized['excluded_category_ids'] = array_values( array_unique( $valid_category_ids ) );

		$attribute_options = self::get_attribute_column_options();
		if ( isset( $input['attribute_columns'] ) && is_array( $input['attribute_columns'] ) ) {
			foreach ( $input['attribute_columns'] as $taxonomy ) {
				$taxonomy = sanitize_text_field( (string) $taxonomy );
				if ( isset( $attribute_options[ $taxonomy ] ) ) {
					$sanitized['attribute_columns'][] = $taxonomy;
				}
			}
		}
		$sanitized['attribute_columns'] = array_slice( array_values( array_unique( $sanitized['attribute_columns'] ) ), 0, 3 );

		return $sanitized;
	}

	/**
	 * @return array<string,string>
	 */
	public static function get_attribute_column_options(): array {
		if ( ! Plugin::is_woocommerce_available() || ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return array();
		}

		$options     = array();
		$taxonomies  = wc_get_attribute_taxonomies();

		if ( ! is_array( $taxonomies ) ) {
			return array();
		}

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! is_object( $taxonomy ) || empty( $taxonomy->attribute_name ) ) {
				continue;
			}

			$taxonomy_name         = wc_attribute_taxonomy_name( (string) $taxonomy->attribute_name );
			$options[ $taxonomy_name ] = (string) $taxonomy->attribute_label;
		}

		return $options;
	}

	/**
	 * @return array<int,WP_Term>
	 */
	public static function get_category_options(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$terms,
				static fn ( $term ): bool => $term instanceof WP_Term
			)
		);
	}
}
