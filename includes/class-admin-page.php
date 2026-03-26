<?php

namespace PdfProductCatalogsForWooCommerce;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin_Page {
	private const PAGE_SLUG = 'pdf-product-catalogs-for-woocommerce';
	private const STATUS_NONCE_ACTION = 'ppcfw_catalog_statuses';

	public static function register_menu(): void {
		if ( ! Plugin::is_woocommerce_available() ) {
			return;
		}

		add_submenu_page(
			'edit.php?post_type=product',
			esc_html__( 'PDF Product Catalogs', 'pdf-product-catalogs-for-woocommerce' ),
			esc_html__( 'PDF Product Catalogs', 'pdf-product-catalogs-for-woocommerce' ),
			Plugin::CAPABILITY,
			self::PAGE_SLUG,
			array( self::class, 'render_page' )
		);
	}

	public static function render_dependency_notice(): void {
		if ( ! is_admin() || Plugin::is_woocommerce_available() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'PDF Product Catalogs for WooCommerce requires WooCommerce to be installed and active.', 'pdf-product-catalogs-for-woocommerce' );
		echo '</p></div>';
	}

	public static function add_plugin_action_links( array $links ): array {
		if ( Plugin::is_woocommerce_available() ) {
			$url = add_query_arg(
				array(
					'post_type' => 'product',
					'page'      => self::PAGE_SLUG,
				),
				admin_url( 'edit.php' )
			);

			array_unshift(
				$links,
				'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'pdf-product-catalogs-for-woocommerce' ) . '</a>'
			);
		}

		return $links;
	}

	public static function enqueue_assets( string $hook_suffix ): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			return;
		}

		if ( 'product_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$style_path = PDF_PRODUCT_CATALOGS_FOR_WOOCOMMERCE_DIR . 'assets/admin.css';
		$script_path = PDF_PRODUCT_CATALOGS_FOR_WOOCOMMERCE_DIR . 'assets/admin.js';

		wp_enqueue_style(
			'ppcfw-admin',
			PDF_PRODUCT_CATALOGS_FOR_WOOCOMMERCE_URL . 'assets/admin.css',
			array(),
			file_exists( $style_path ) ? (string) filemtime( $style_path ) : PDF_PRODUCT_CATALOGS_FOR_WOOCOMMERCE_VERSION
		);

		wp_enqueue_script(
			'ppcfw-admin',
			PDF_PRODUCT_CATALOGS_FOR_WOOCOMMERCE_URL . 'assets/admin.js',
			array(),
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : PDF_PRODUCT_CATALOGS_FOR_WOOCOMMERCE_VERSION,
			true
		);

		$records = Catalog_Repository::get_recent( 20 );
		$pending = array();

		foreach ( $records as $record ) {
			if ( isset( $record['id'], $record['status'] ) && in_array( $record['status'], array( 'queued', 'processing' ), true ) ) {
				$pending[] = (int) $record['id'];
			}
		}

		wp_localize_script(
			'ppcfw-admin',
			'ppcfwAdmin',
			array(
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'statusNonce'       => wp_create_nonce( self::STATUS_NONCE_ACTION ),
				'pendingRecordIds'  => array_values( array_unique( $pending ) ),
				'strings'           => array(
					'clientNameRequired' => __( 'Client name is required for a client-specific catalog.', 'pdf-product-catalogs-for-woocommerce' ),
				),
			)
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'pdf-product-catalogs-for-woocommerce' ) );
		}

		$settings          = Settings::get_all();
		$attribute_options = Settings::get_attribute_column_options();
		$category_options  = Settings::get_category_options();
		$records           = Catalog_Repository::get_recent( 20 );

		echo '<div class="wrap ppcfw-admin-wrap">';
		echo '<h1>' . esc_html__( 'PDF Product Catalogs', 'pdf-product-catalogs-for-woocommerce' ) . '</h1>';
		echo '<p>' . esc_html__( 'Generate branded, client-ready product catalog PDFs and securely download historic versions from this page.', 'pdf-product-catalogs-for-woocommerce' ) . '</p>';

		self::render_flash_notice();
		self::render_generation_panel();
		self::render_settings_form( $settings, $attribute_options, $category_options );
		self::render_history_table( $records );
		self::render_generate_modal();
		echo '</div>';
	}

	public static function handle_generate(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'pdf-product-catalogs-for-woocommerce' ) );
		}

		check_admin_referer( 'ppcfw_generate_catalog' );

		if ( ! Plugin::is_woocommerce_available() ) {
			self::redirect_with_notice( 'missing-woocommerce' );
		}

		$catalog_type = isset( $_POST['catalog_type'] ) ? sanitize_key( wp_unslash( $_POST['catalog_type'] ) ) : 'standard';
		$client_name  = isset( $_POST['client_name'] ) ? sanitize_text_field( wp_unslash( $_POST['client_name'] ) ) : '';
		$tax_mode     = isset( $_POST['tax_mode'] ) ? sanitize_key( wp_unslash( $_POST['tax_mode'] ) ) : 'including';
		$discount     = isset( $_POST['discount_percent'] ) ? (float) wp_unslash( $_POST['discount_percent'] ) : 0.0;

		if ( ! in_array( $catalog_type, array( 'standard', 'client-specific' ), true ) ) {
			$catalog_type = 'standard';
		}

		if ( ! in_array( $tax_mode, array( 'including', 'excluding' ), true ) ) {
			$tax_mode = 'including';
		}

		$discount = max( 0.0, min( 100.0, round( $discount, 2 ) ) );

		if ( 'client-specific' === $catalog_type && '' === $client_name ) {
			self::redirect_with_notice( 'missing-client-name' );
		}

		$result = Catalog_Generator::queue(
			array(
				'catalog_type'     => $catalog_type,
				'client_name'      => $client_name,
				'tax_mode'         => $tax_mode,
				'discount_percent' => $discount,
			)
		);

		if ( empty( $result['ok'] ) || empty( $result['record_id'] ) ) {
			self::redirect_with_notice( 'generation-failed' );
		}

		self::redirect_with_notice( 'queued', (int) $result['record_id'] );
	}

	public static function handle_download(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'pdf-product-catalogs-for-woocommerce' ) );
		}

		$record_id = isset( $_GET['record_id'] ) ? absint( $_GET['record_id'] ) : 0;
		check_admin_referer( 'ppcfw_download_catalog:' . $record_id );

		$record = Catalog_Repository::get( $record_id );
		if ( empty( $record ) || ! is_array( $record ) ) {
			wp_die( esc_html__( 'Catalog record not found.', 'pdf-product-catalogs-for-woocommerce' ) );
		}

		$relative_path = isset( $record['file_relative_path'] ) ? (string) $record['file_relative_path'] : '';
		$file_name     = isset( $record['file_name'] ) ? (string) $record['file_name'] : '';
		$absolute_path = Storage::absolute_path_from_relative( $relative_path );

		if ( '' === $absolute_path || ! file_exists( $absolute_path ) || ! Storage::is_path_in_storage_dir( $absolute_path ) ) {
			wp_die( esc_html__( 'Catalog file not found.', 'pdf-product-catalogs-for-woocommerce' ) );
		}

		Storage::stream_pdf_file( $absolute_path, $file_name );
	}

	public static function handle_status_poll(): void {
		check_ajax_referer( self::STATUS_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission denied.', 'pdf-product-catalogs-for-woocommerce' ),
				),
				403
			);
		}

		$ids = isset( $_POST['record_ids'] ) && is_array( $_POST['record_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['record_ids'] ) ) : array();
		$ids = array_values( array_filter( array_unique( $ids ) ) );

		if ( empty( $ids ) ) {
			wp_send_json_success( array( 'records' => array() ) );
		}

		$records = Catalog_Repository::get_by_ids( $ids );
		$data    = array();

		foreach ( $records as $record ) {
			$record_id = isset( $record['id'] ) ? (int) $record['id'] : 0;
			if ( $record_id < 1 ) {
				continue;
			}

			$download_url = '';
			if ( isset( $record['status'] ) && 'completed' === $record['status'] ) {
				$download_url = self::get_download_url( $record_id );
			}

			$data[] = array(
				'id'           => $record_id,
				'status'       => (string) $record['status'],
				'statusLabel'  => Catalog_Generator::get_status_label( (string) $record['status'] ),
				'downloadUrl'  => $download_url,
				'productCount' => isset( $record['product_count'] ) ? (int) $record['product_count'] : 0,
				'errorMessage' => isset( $record['error_message'] ) ? (string) $record['error_message'] : '',
			);
		}

		wp_send_json_success( array( 'records' => $data ) );
	}

	private static function render_flash_notice(): void {
		$code = isset( $_GET['ppcfw_notice'] ) ? sanitize_key( wp_unslash( $_GET['ppcfw_notice'] ) ) : '';

		if ( '' === $code ) {
			return;
		}

		$class   = 'notice-info';
		$message = '';

		switch ( $code ) {
			case 'queued':
				$class   = 'notice-success';
				$message = __( 'Catalog generation has been queued. The history table will update automatically.', 'pdf-product-catalogs-for-woocommerce' );
				break;
			case 'missing-client-name':
				$class   = 'notice-error';
				$message = __( 'Client name is required for a client-specific catalog.', 'pdf-product-catalogs-for-woocommerce' );
				break;
			case 'missing-woocommerce':
				$class   = 'notice-error';
				$message = __( 'WooCommerce must be active before a product catalog can be generated.', 'pdf-product-catalogs-for-woocommerce' );
				break;
			case 'generation-failed':
				$class   = 'notice-error';
				$message = __( 'The catalog could not be queued for generation.', 'pdf-product-catalogs-for-woocommerce' );
				break;
		}

		if ( '' === $message ) {
			return;
		}

		echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	private static function render_generation_panel(): void {
		echo '<div class="ppcfw-panel">';
		echo '<div class="ppcfw-panel__header">';
		echo '<div>';
		echo '<h2>' . esc_html__( 'Generate a new catalog', 'pdf-product-catalogs-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Create a standard or client-specific catalog without leaving the Products area.', 'pdf-product-catalogs-for-woocommerce' ) . '</p>';
		echo '</div>';
		echo '<button type="button" class="button button-primary ppcfw-open-modal">' . esc_html__( 'New PDF Catalog', 'pdf-product-catalogs-for-woocommerce' ) . '</button>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * @param array<string,mixed>        $settings
	 * @param array<string,string>       $attribute_options
	 * @param array<int,\WP_Term> $category_options
	 */
	private static function render_settings_form( array $settings, array $attribute_options, array $category_options ): void {
		echo '<div class="ppcfw-panel">';
		echo '<h2>' . esc_html__( 'Catalog settings', 'pdf-product-catalogs-for-woocommerce' ) . '</h2>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'ppcfw_settings' );

		echo '<table class="form-table" role="presentation">';

		echo '<tr>';
		echo '<th scope="row"><label for="ppcfw-header-text">' . esc_html__( 'PDF header text', 'pdf-product-catalogs-for-woocommerce' ) . '</label></th>';
		echo '<td>';
		echo '<textarea id="ppcfw-header-text" class="large-text" rows="4" name="' . esc_attr( Settings::OPTION_NAME ) . '[header_text]">' . esc_textarea( (string) $settings['header_text'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Shown in the branded header area of the PDF.', 'pdf-product-catalogs-for-woocommerce' ) . '</p>';
		echo '</td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row"><label for="ppcfw-footer-text">' . esc_html__( 'PDF footer text', 'pdf-product-catalogs-for-woocommerce' ) . '</label></th>';
		echo '<td>';
		echo '<textarea id="ppcfw-footer-text" class="large-text" rows="3" name="' . esc_attr( Settings::OPTION_NAME ) . '[footer_text]">' . esc_textarea( (string) $settings['footer_text'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Rendered in the fixed footer area on each PDF page.', 'pdf-product-catalogs-for-woocommerce' ) . '</p>';
		echo '</td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Default stock scope', 'pdf-product-catalogs-for-woocommerce' ) . '</th>';
		echo '<td>';
		echo '<label><input type="checkbox" name="' . esc_attr( Settings::OPTION_NAME ) . '[include_out_of_stock]" value="1" ' . checked( ! empty( $settings['include_out_of_stock'] ), true, false ) . ' /> ';
		echo esc_html__( 'Include out-of-stock products in new catalogs by default.', 'pdf-product-catalogs-for-woocommerce' ) . '</label>';
		echo '</td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row"><label for="ppcfw-excluded-categories">' . esc_html__( 'Excluded product categories', 'pdf-product-catalogs-for-woocommerce' ) . '</label></th>';
		echo '<td>';
		echo '<select id="ppcfw-excluded-categories" name="' . esc_attr( Settings::OPTION_NAME ) . '[excluded_category_ids][]" multiple="multiple" size="8" class="ppcfw-multi-select">';
		$selected_categories = isset( $settings['excluded_category_ids'] ) && is_array( $settings['excluded_category_ids'] ) ? array_map( 'absint', $settings['excluded_category_ids'] ) : array();
		foreach ( $category_options as $term ) {
			echo '<option value="' . esc_attr( (string) $term->term_id ) . '" ' . selected( in_array( $term->term_id, $selected_categories, true ), true, false ) . '>' . esc_html( $term->name ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Products in these categories are excluded from every generated catalog.', 'pdf-product-catalogs-for-woocommerce' ) . '</p>';
		echo '</td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row"><label for="ppcfw-attribute-columns">' . esc_html__( 'Relevant attribute columns', 'pdf-product-catalogs-for-woocommerce' ) . '</label></th>';
		echo '<td>';
		echo '<select id="ppcfw-attribute-columns" name="' . esc_attr( Settings::OPTION_NAME ) . '[attribute_columns][]" multiple="multiple" size="6" class="ppcfw-multi-select">';
		$selected_columns = isset( $settings['attribute_columns'] ) && is_array( $settings['attribute_columns'] ) ? array_map( 'strval', $settings['attribute_columns'] ) : array();
		foreach ( $attribute_options as $taxonomy => $label ) {
			echo '<option value="' . esc_attr( $taxonomy ) . '" ' . selected( in_array( $taxonomy, $selected_columns, true ), true, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Choose up to three WooCommerce attributes that should be shown as extra columns in the catalog table.', 'pdf-product-catalogs-for-woocommerce' ) . '</p>';
		echo '</td>';
		echo '</tr>';

		echo '</table>';

		submit_button();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * @param array<int,array<string,mixed>> $records
	 */
	private static function render_history_table( array $records ): void {
		echo '<div class="ppcfw-panel">';
		echo '<h2>' . esc_html__( 'Generated catalog history', 'pdf-product-catalogs-for-woocommerce' ) . '</h2>';

		if ( empty( $records ) ) {
			echo '<p>' . esc_html__( 'No product catalogs have been generated yet.', 'pdf-product-catalogs-for-woocommerce' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped ppcfw-history-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Catalog', 'pdf-product-catalogs-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Generated', 'pdf-product-catalogs-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Pricing', 'pdf-product-catalogs-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Products', 'pdf-product-catalogs-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'pdf-product-catalogs-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'pdf-product-catalogs-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $records as $record ) {
			$record_id = (int) $record['id'];
			$status    = (string) $record['status'];
			$file_name = isset( $record['file_name'] ) ? (string) $record['file_name'] : '';
			$title     = self::build_catalog_title( $record );
			$pricing   = self::build_pricing_summary( $record );
			$products  = isset( $record['product_count'] ) ? (int) $record['product_count'] : 0;
			$generated = self::format_datetime( isset( $record['created_at_gmt'] ) ? (string) $record['created_at_gmt'] : '' );

			echo '<tr data-record-id="' . esc_attr( (string) $record_id ) . '" data-record-status="' . esc_attr( $status ) . '">';
			echo '<td>';
			echo '<strong>' . esc_html( $title ) . '</strong>';
			if ( '' !== $file_name ) {
				echo '<div class="description">' . esc_html( $file_name ) . '</div>';
			}
			echo '</td>';
			echo '<td>' . esc_html( $generated ) . '</td>';
			echo '<td>' . esc_html( $pricing ) . '</td>';
			echo '<td class="ppcfw-product-count">' . esc_html( (string) $products ) . '</td>';
			echo '<td class="ppcfw-status-cell">';
			echo '<span class="ppcfw-status ppcfw-status--' . esc_attr( $status ) . '">' . esc_html( Catalog_Generator::get_status_label( $status ) ) . '</span>';
			if ( ! empty( $record['error_message'] ) ) {
				echo '<div class="description">' . esc_html( (string) $record['error_message'] ) . '</div>';
			}
			echo '</td>';
			echo '<td class="ppcfw-actions-cell">';
			if ( 'completed' === $status ) {
				echo '<a class="button button-secondary" href="' . esc_url( self::get_download_url( $record_id ) ) . '">' . esc_html__( 'Download PDF', 'pdf-product-catalogs-for-woocommerce' ) . '</a>';
			} else {
				echo '<span class="description">' . esc_html__( 'Waiting for generation', 'pdf-product-catalogs-for-woocommerce' ) . '</span>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	private static function render_generate_modal(): void {
		echo '<div class="ppcfw-modal" hidden>';
		echo '<div class="ppcfw-modal__backdrop ppcfw-close-modal"></div>';
		echo '<div class="ppcfw-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="ppcfw-modal-title">';
		echo '<div class="ppcfw-modal__header">';
		echo '<h2 id="ppcfw-modal-title">' . esc_html__( 'Create PDF catalog', 'pdf-product-catalogs-for-woocommerce' ) . '</h2>';
		echo '<button type="button" class="button-link ppcfw-close-modal" aria-label="' . esc_attr__( 'Close', 'pdf-product-catalogs-for-woocommerce' ) . '">×</button>';
		echo '</div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ppcfw-wizard-form">';
		echo '<input type="hidden" name="action" value="ppcfw_generate_catalog" />';
		wp_nonce_field( 'ppcfw_generate_catalog' );

		echo '<div class="ppcfw-wizard-step is-active" data-step="1">';
		echo '<h3>' . esc_html__( 'Step 1 of 4: Catalog type', 'pdf-product-catalogs-for-woocommerce' ) . '</h3>';
		echo '<label class="ppcfw-choice"><input type="radio" name="catalog_type" value="standard" checked="checked" /> <span><strong>' . esc_html__( 'Standard catalog', 'pdf-product-catalogs-for-woocommerce' ) . '</strong><br />' . esc_html__( 'Create a general product catalog without client-specific branding.', 'pdf-product-catalogs-for-woocommerce' ) . '</span></label>';
		echo '<label class="ppcfw-choice"><input type="radio" name="catalog_type" value="client-specific" /> <span><strong>' . esc_html__( 'Client-specific catalog', 'pdf-product-catalogs-for-woocommerce' ) . '</strong><br />' . esc_html__( 'Add a client name to the catalog heading and history entry.', 'pdf-product-catalogs-for-woocommerce' ) . '</span></label>';
		echo '</div>';

		echo '<div class="ppcfw-wizard-step" data-step="2">';
		echo '<h3>' . esc_html__( 'Step 2 of 4: Client details', 'pdf-product-catalogs-for-woocommerce' ) . '</h3>';
		echo '<label for="ppcfw-client-name">' . esc_html__( 'Client name', 'pdf-product-catalogs-for-woocommerce' ) . '</label>';
		echo '<input type="text" id="ppcfw-client-name" name="client_name" class="regular-text" placeholder="' . esc_attr__( 'Example Trading AG', 'pdf-product-catalogs-for-woocommerce' ) . '" />';
		echo '<p class="description">' . esc_html__( 'Required only when you choose a client-specific catalog.', 'pdf-product-catalogs-for-woocommerce' ) . '</p>';
		echo '</div>';

		echo '<div class="ppcfw-wizard-step" data-step="3">';
		echo '<h3>' . esc_html__( 'Step 3 of 4: Price display', 'pdf-product-catalogs-for-woocommerce' ) . '</h3>';
		echo '<label class="ppcfw-choice"><input type="radio" name="tax_mode" value="including" checked="checked" /> <span>' . esc_html__( 'Show prices including tax', 'pdf-product-catalogs-for-woocommerce' ) . '</span></label>';
		echo '<label class="ppcfw-choice"><input type="radio" name="tax_mode" value="excluding" /> <span>' . esc_html__( 'Show prices excluding tax', 'pdf-product-catalogs-for-woocommerce' ) . '</span></label>';
		echo '</div>';

		echo '<div class="ppcfw-wizard-step" data-step="4">';
		echo '<h3>' . esc_html__( 'Step 4 of 4: Discount', 'pdf-product-catalogs-for-woocommerce' ) . '</h3>';
		echo '<label for="ppcfw-discount-percent">' . esc_html__( 'Client discount percent', 'pdf-product-catalogs-for-woocommerce' ) . '</label>';
		echo '<input type="number" id="ppcfw-discount-percent" name="discount_percent" class="small-text" min="0" max="100" step="0.01" value="0" />';
		echo '<p class="description">' . esc_html__( 'Use 0 for no discount. The discount is applied to the displayed catalog prices only for this generated PDF.', 'pdf-product-catalogs-for-woocommerce' ) . '</p>';
		echo '</div>';

		echo '<div class="ppcfw-modal__footer">';
		echo '<button type="button" class="button button-secondary ppcfw-step-back" disabled="disabled">' . esc_html__( 'Back', 'pdf-product-catalogs-for-woocommerce' ) . '</button>';
		echo '<button type="button" class="button button-primary ppcfw-step-next">' . esc_html__( 'Next', 'pdf-product-catalogs-for-woocommerce' ) . '</button>';
		echo '<button type="submit" class="button button-primary ppcfw-submit-catalog" hidden>' . esc_html__( 'Generate PDF Catalog', 'pdf-product-catalogs-for-woocommerce' ) . '</button>';
		echo '</div>';

		echo '</form>';
		echo '</div>';
		echo '</div>';
	}

	private static function redirect_with_notice( string $notice, int $record_id = 0 ): void {
		$args = array(
			'post_type'     => 'product',
			'page'          => self::PAGE_SLUG,
			'ppcfw_notice'  => $notice,
		);

		if ( $record_id > 0 ) {
			$args['record_id'] = $record_id;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );
		exit;
	}

	private static function get_download_url( int $record_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'    => 'ppcfw_download_catalog',
					'record_id' => $record_id,
				),
				admin_url( 'admin-post.php' )
			),
			'ppcfw_download_catalog:' . $record_id
		);
	}

	/**
	 * @param array<string,mixed> $record
	 */
	private static function build_catalog_title( array $record ): string {
		$client_name = isset( $record['client_name'] ) ? trim( (string) $record['client_name'] ) : '';

		if ( '' !== $client_name ) {
			return sprintf(
				/* translators: %s client name */
				__( 'Product catalog for %s', 'pdf-product-catalogs-for-woocommerce' ),
				$client_name
			);
		}

		return __( 'Standard product catalog', 'pdf-product-catalogs-for-woocommerce' );
	}

	/**
	 * @param array<string,mixed> $record
	 */
	private static function build_pricing_summary( array $record ): string {
		$tax_mode = isset( $record['tax_mode'] ) && 'excluding' === $record['tax_mode']
			? __( 'Excluding tax', 'pdf-product-catalogs-for-woocommerce' )
			: __( 'Including tax', 'pdf-product-catalogs-for-woocommerce' );

		$discount = isset( $record['discount_percent'] ) ? (float) $record['discount_percent'] : 0.0;
		if ( $discount > 0 ) {
			return sprintf(
				/* translators: 1: tax mode, 2: discount percentage */
				__( '%1$s, %2$s%% discount', 'pdf-product-catalogs-for-woocommerce' ),
				$tax_mode,
				number_format_i18n( $discount, 2 )
			);
		}

		return $tax_mode;
	}

	private static function format_datetime( string $datetime_gmt ): string {
		if ( '' === $datetime_gmt ) {
			return '';
		}

		$timestamp = strtotime( $datetime_gmt . ' GMT' );
		if ( false === $timestamp ) {
			return $datetime_gmt;
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
