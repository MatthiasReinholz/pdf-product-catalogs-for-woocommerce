<?php

namespace PdfProductCatalogsForWooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Activator {
	public static function activate( bool $network_wide ): void {
		if ( is_multisite() && $network_wide ) {
			wp_die(
				esc_html__( 'PDF Product Catalogs for WooCommerce cannot be network-activated. Activate it per site instead.', 'pdf-product-catalogs-for-woocommerce' ),
				esc_html__( 'Activation Error', 'pdf-product-catalogs-for-woocommerce' ),
				array( 'back_link' => true )
			);
		}

			Settings::maybe_initialize();
			Catalog_Repository::maybe_create_table();
			Storage::ensure_secret();
			Storage::ensure_storage_dir();
			Plugin::sync_auto_refresh_schedule();
		}
	}
