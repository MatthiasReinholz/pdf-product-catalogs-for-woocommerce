<?php

namespace PdfProductCatalogsForWooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	public const CAPABILITY = 'manage_woocommerce';
	public const ASYNC_ACTION = 'ppcfw_generate_catalog';
	public const ASYNC_GROUP = 'pdf-product-catalogs-for-woocommerce';

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function init(): void {
		add_action( 'admin_init', array( Settings::class, 'register' ) );
		add_action( 'admin_menu', array( Admin_Page::class, 'register_menu' ) );
		add_action( 'admin_notices', array( Admin_Page::class, 'render_dependency_notice' ) );
		add_action( 'admin_enqueue_scripts', array( Admin_Page::class, 'enqueue_assets' ) );

		add_action( 'admin_post_ppcfw_generate_catalog', array( Admin_Page::class, 'handle_generate' ) );
		add_action( 'admin_post_ppcfw_download_catalog', array( Admin_Page::class, 'handle_download' ) );
		add_action( 'wp_ajax_ppcfw_catalog_statuses', array( Admin_Page::class, 'handle_status_poll' ) );
		add_action( self::ASYNC_ACTION, array( Catalog_Generator::class, 'process_record_action' ), 10, 1 );

		add_filter(
			'plugin_action_links_' . plugin_basename( PDF_PRODUCT_CATALOGS_FOR_WOOCOMMERCE_FILE ),
			array( Admin_Page::class, 'add_plugin_action_links' )
		);
	}

	public static function is_woocommerce_available(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_products' ) && function_exists( 'wc_price' );
	}
}
