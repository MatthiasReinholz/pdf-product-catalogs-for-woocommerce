<?php

namespace PdfProductCatalogsForWooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

	final class Plugin {
		public const CAPABILITY = 'manage_woocommerce';
		public const ASYNC_ACTION = 'ppcfw_generate_catalog';
		public const ASYNC_GROUP = 'pdf-product-catalogs-for-woocommerce';
		public const AUTO_REFRESH_HOOK = 'ppcfw_daily_auto_refresh';

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

		public function init(): void {
			Settings::maybe_initialize();
			Catalog_Repository::maybe_create_table();
			Storage::ensure_storage_dir();

			add_action( 'init', array( self::class, 'sync_auto_refresh_schedule' ) );
			add_action( 'action_scheduler_init', array( self::class, 'sync_auto_refresh_schedule' ) );
			add_action( 'admin_init', array( Settings::class, 'register' ) );
			add_action( 'update_option_' . Settings::OPTION_NAME, array( self::class, 'handle_settings_updated' ), 10, 2 );
		add_action( 'admin_menu', array( Admin_Page::class, 'register_menu' ) );
		add_action( 'admin_notices', array( Admin_Page::class, 'render_dependency_notice' ) );
		add_action( 'admin_enqueue_scripts', array( Admin_Page::class, 'enqueue_assets' ) );

		add_action( 'admin_post_ppcfw_generate_catalog', array( Admin_Page::class, 'handle_generate' ) );
		add_action( 'admin_post_ppcfw_download_catalog', array( Admin_Page::class, 'handle_download' ) );
			add_action( 'admin_post_ppcfw_delete_catalog', array( Admin_Page::class, 'handle_delete' ) );
			add_action( 'wp_ajax_ppcfw_catalog_statuses', array( Admin_Page::class, 'handle_status_poll' ) );
			add_action( self::ASYNC_ACTION, array( Catalog_Generator::class, 'process_record_action' ), 10, 1 );
			add_action( self::AUTO_REFRESH_HOOK, array( Catalog_Generator::class, 'maybe_queue_daily_standard_catalog' ) );

			add_filter(
			'plugin_action_links_' . plugin_basename( PDF_PRODUCT_CATALOGS_FOR_WOOCOMMERCE_FILE ),
			array( Admin_Page::class, 'add_plugin_action_links' )
		);
	}

	public static function is_woocommerce_available(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_products' ) && function_exists( 'wc_price' );
	}

	public static function sync_auto_refresh_schedule(): void {
		$settings = Settings::get_all();
		$enabled  = ! empty( $settings['enable_daily_automatic_catalog'] );
		self::apply_auto_refresh_schedule( $enabled, false );
	}

	/**
	 * @param mixed $old_value
	 * @param mixed $value
	 */
	public static function handle_settings_updated( $old_value, $value ): void {
		$old_value = is_array( $old_value ) ? $old_value : array();
		$value     = is_array( $value ) ? $value : array();

		$was_enabled = ! empty( $old_value['enable_daily_automatic_catalog'] );
		$is_enabled  = ! empty( $value['enable_daily_automatic_catalog'] );

		self::apply_auto_refresh_schedule( $is_enabled, ! $was_enabled && $is_enabled );
	}

	private static function apply_auto_refresh_schedule( bool $enabled, bool $force_immediate ): void {
		if ( function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action( self::AUTO_REFRESH_HOOK, array(), self::ASYNC_GROUP );

			if ( $enabled ) {
				if ( false === $next ) {
					as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::AUTO_REFRESH_HOOK, array(), self::ASYNC_GROUP );
				}

				if ( $force_immediate || false === $next ) {
					as_enqueue_async_action( self::AUTO_REFRESH_HOOK, array(), self::ASYNC_GROUP );
				}
			} elseif ( false !== $next ) {
				as_unschedule_all_actions( self::AUTO_REFRESH_HOOK, array(), self::ASYNC_GROUP );
			}

			return;
		}

		$next = wp_next_scheduled( self::AUTO_REFRESH_HOOK );

		if ( $enabled && false === $next ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::AUTO_REFRESH_HOOK );
		}

		if ( $enabled && ( $force_immediate || false === $next ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::AUTO_REFRESH_HOOK );
		}

		if ( ! $enabled && false !== $next ) {
			wp_clear_scheduled_hook( self::AUTO_REFRESH_HOOK );
		}
	}
}
