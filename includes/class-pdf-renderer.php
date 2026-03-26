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
		public static function render_html_file( string $html_path ): array {
		if ( '' === $html_path || ! file_exists( $html_path ) ) {
			return array(
				'ok'    => false,
				'error' => 'missing-html-file',
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

			$options = new Options();
			$options->setIsRemoteEnabled( false );
			$options->setIsHtml5ParserEnabled( true );
			$options->setIsFontSubsettingEnabled( true );
			$options->setDefaultFont( 'Inter' );
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
			$dompdf->loadHtmlFile( $html_path, 'UTF-8' );
			$dompdf->setPaper( 'A4', 'portrait' );
			$dompdf->render();

			$pdf_binary = $dompdf->output();
			if ( ! is_string( $pdf_binary ) || '' === $pdf_binary ) {
			return array(
				'ok'    => false,
					'error' => 'render-empty',
				);
			}

			$pdf_path = self::create_temp_pdf_path( $tmp_dir );
			if ( false === file_put_contents( $pdf_path, $pdf_binary ) ) {
				wp_delete_file( $pdf_path );
				return array(
					'ok'    => false,
					'error' => 'render-write-failed',
				);
			}

			unset( $pdf_binary, $dompdf );
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}

			return array(
				'ok'   => true,
				'path' => $pdf_path,
			);
		}

		private static function create_temp_pdf_path( string $tmp_dir ): string {
			$temp_path = tempnam( $tmp_dir, 'catalog-pdf-' );
			if ( false === $temp_path ) {
				throw new \RuntimeException( 'pdf-tempnam-failed' );
			}

			$pdf_path = $temp_path . '.pdf';
			if ( ! @rename( $temp_path, $pdf_path ) ) {
				wp_delete_file( $temp_path );
				throw new \RuntimeException( 'pdf-tempfile-rename-failed' );
			}

			return $pdf_path;
		}
	}
