<?php
/**
 * Plugin Name: PDF Product Catalogs for WooCommerce
 * Plugin URI: https://github.com/MatthiasReinholz/pdf-product-catalogs-for-woocommerce
 * Description: Generate secure, client-specific PDF product catalogs from WooCommerce product data.
 * Version: 0.1.0
 * Author: Matthias Reinholz
 * Author URI: https://github.com/MatthiasReinholz
 * Text Domain: pdf-product-catalogs-for-woocommerce
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires at least: 6.8
 * Requires PHP: 8.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PDF_PRODUCT_CATALOGS_FOR_WOOCOMMERCE_VERSION', '0.1.0' );
define( 'PDF_PRODUCT_CATALOGS_FOR_WOOCOMMERCE_SLUG', 'pdf-product-catalogs-for-woocommerce' );
define( 'PDF_PRODUCT_CATALOGS_FOR_WOOCOMMERCE_FILE', __FILE__ );
define( 'PDF_PRODUCT_CATALOGS_FOR_WOOCOMMERCE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PDF_PRODUCT_CATALOGS_FOR_WOOCOMMERCE_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'PdfProductCatalogsForWooCommerce\\';
		$length = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class, $length ) ) {
			return;
		}

		$relative = substr( $class, $length );
		$relative = str_replace( '_', '-', $relative );
		$relative = preg_replace( '/([a-z])([A-Z])/', '$1-$2', $relative );
		$relative = str_replace( '\\', '/', strtolower( (string) $relative ) );

		$path = PDF_PRODUCT_CATALOGS_FOR_WOOCOMMERCE_DIR . 'includes/class-' . $relative . '.php';

		if ( is_string( $path ) && file_exists( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook(
	__FILE__,
	static function ( $network_wide ): void {
		\PdfProductCatalogsForWooCommerce\Activator::activate( (bool) $network_wide );
	}
);

register_deactivation_hook(
	__FILE__,
	static function ( $network_wide ): void {
		\PdfProductCatalogsForWooCommerce\Deactivator::deactivate( (bool) $network_wide );
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		load_plugin_textdomain(
			'pdf-product-catalogs-for-woocommerce',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);

		\PdfProductCatalogsForWooCommerce\Plugin::instance()->init();
	}
);
