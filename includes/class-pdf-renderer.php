<?php

namespace PdfProductCatalogsForWooCommerce;

use Dompdf\Dompdf;
use Dompdf\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once PDF_PRODUCT_CATALOGS_FOR_WOOCOMMERCE_DIR . 'vendor/dompdf-autoload.php';
pdf_product_catalogs_for_woocommerce_register_vendor_autoloader();

final class Pdf_Renderer {
	/**
	 * @param array<string,mixed> $document
	 */
	public static function render_to_path( array $document, string $output_path ): array {
		if ( '' === $output_path ) {
			return array(
				'ok'    => false,
				'error' => 'missing-output-path',
			);
		}

		$directory = dirname( $output_path );
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return array(
				'ok'    => false,
				'error' => 'mkdir-failed',
			);
		}

		$tmp_base = defined( 'WP_TEMP_DIR' ) && is_string( WP_TEMP_DIR ) && '' !== trim( WP_TEMP_DIR )
			? (string) WP_TEMP_DIR
			: (string) sys_get_temp_dir();

		$tmp_dir = trailingslashit( rtrim( $tmp_base, '/' ) ) . 'pdf-product-catalogs-for-woocommerce';
		if ( ! is_dir( $tmp_dir ) ) {
			wp_mkdir_p( $tmp_dir );
		}

		$font_dir = trailingslashit( $tmp_dir ) . 'fonts';
		if ( ! is_dir( $font_dir ) ) {
			wp_mkdir_p( $font_dir );
		}

		$uploads     = wp_upload_dir( null, false );
		$uploads_dir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		$plugin_root = realpath( PDF_PRODUCT_CATALOGS_FOR_WOOCOMMERCE_DIR );
		$dompdf_root = realpath( PDF_PRODUCT_CATALOGS_FOR_WOOCOMMERCE_DIR . 'vendor/dompdf/dompdf' );

		ob_start();
		$document_data = $document;
		include PDF_PRODUCT_CATALOGS_FOR_WOOCOMMERCE_DIR . 'templates/catalog.php';
		$html = (string) ob_get_clean();

		$options = new Options();
		$options->setIsRemoteEnabled( false );
		$options->setIsHtml5ParserEnabled( true );
		$options->setIsFontSubsettingEnabled( false );
		$options->setDefaultFont( 'dejavu sans' );
		$options->setTempDir( $tmp_dir );
		$options->setFontCache( $font_dir );
		$options->setFontDir( $font_dir );

		if ( is_string( $dompdf_root ) && '' !== $dompdf_root ) {
			$options->setRootDir( $dompdf_root );
		}

		$chroot = array_filter(
			array(
				$plugin_root,
				$tmp_dir,
				$uploads_dir,
			),
			static fn ( $path ): bool => is_string( $path ) && '' !== $path
		);

		if ( ! empty( $chroot ) ) {
			$options->setChroot( $chroot );
		}

		$dompdf = new Dompdf( $options );
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();

		$pdf_binary = $dompdf->output();
		if ( ! is_string( $pdf_binary ) || '' === $pdf_binary ) {
			return array(
				'ok'    => false,
				'error' => 'render-empty',
			);
		}

		if ( false === file_put_contents( $output_path, $pdf_binary ) ) {
			return array(
				'ok'    => false,
				'error' => 'write-failed',
			);
		}

		return array(
			'ok'   => true,
			'path' => $output_path,
		);
	}
}
