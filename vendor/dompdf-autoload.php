<?php

/**
 * Minimal PSR-4 autoloader for PDF Product Catalogs for WooCommerce bundled Dompdf dependencies.
 *
 * We intentionally do NOT bundle a Composer autoloader to avoid collisions with
 * other plugins that may ship their own Composer-generated autoloaders.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'pdf_product_catalogs_for_woocommerce_register_vendor_autoloader' ) ) {
	/**
	 * Registers the plugin vendor autoloader once.
	 */
	function pdf_product_catalogs_for_woocommerce_register_vendor_autoloader(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		$root = __DIR__;

		$prefixes = array(
			'Dompdf\\'      => array(
				$root . '/dompdf/dompdf/src/',
				$root . '/dompdf/dompdf/lib/',
			),
			'FontLib\\'     => array( $root . '/dompdf/php-font-lib/src/FontLib/' ),
			'Svg\\'         => array( $root . '/dompdf/php-svg-lib/src/Svg/' ),
			'Masterminds\\' => array( $root . '/masterminds/html5/src/' ),
			'Sabberworm\\CSS\\' => array( $root . '/sabberworm/php-css-parser/src/' ),
		);

		spl_autoload_register(
			static function ( string $class ) use ( $prefixes ): void {
				foreach ( $prefixes as $prefix => $dirs ) {
					if ( 0 !== strpos( $class, $prefix ) ) {
						continue;
					}

					$relative = substr( $class, strlen( $prefix ) );
					$relative = str_replace( '\\', '/', $relative );

					foreach ( $dirs as $dir ) {
						$file = $dir . $relative . '.php';
						if ( file_exists( $file ) ) {
							require_once $file;
							return;
						}
					}
				}
			},
			true,
			true
		);
	}
}
