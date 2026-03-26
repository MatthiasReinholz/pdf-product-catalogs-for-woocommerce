<?php
/**
 * @var array<string,mixed> $document_data
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$attribute_columns    = isset( $document_data['attribute_columns'] ) && is_array( $document_data['attribute_columns'] ) ? $document_data['attribute_columns'] : array();
$scope_labels         = isset( $document_data['scope_labels'] ) && is_array( $document_data['scope_labels'] ) ? $document_data['scope_labels'] : array();
$font_dir             = str_replace( '\\', '/', trailingslashit( PDF_PRODUCT_CATALOGS_FOR_WOOCOMMERCE_DIR . 'assets/fonts' ) );
$image_column_width   = 6;
$price_column_width   = 9;
$product_column_width = 61;
$attribute_widths     = array();

foreach ( $attribute_columns as $index => $column ) {
	$label = strtolower( trim( (string) ( $column['label'] ?? '' ) ) );
	if ( 0 === $index && '' !== $label ) {
		$attribute_widths[] = 16;
		continue;
	}

	$attribute_widths[] = 8;
}
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8" />
	<title><?php echo esc_html( (string) ( $document_data['title'] ?? '' ) ); ?></title>
	<style>
		@page {
			margin: 120px 32px 108px;
		}

		body {
			font-family: Inter, "DejaVu Sans", sans-serif;
			font-size: 10px;
			color: #1f2933;
			line-height: 1.45;
			margin: 0;
			padding: 0;
		}

		@font-face {
			font-family: Inter;
			font-style: normal;
			font-weight: 400;
			src: url("<?php echo esc_attr( $font_dir . 'Inter-Regular.ttf' ); ?>") format("truetype");
		}

		@font-face {
			font-family: Inter;
			font-style: italic;
			font-weight: 400;
			src: url("<?php echo esc_attr( $font_dir . 'Inter-Italic.ttf' ); ?>") format("truetype");
		}

		@font-face {
			font-family: Inter;
			font-style: normal;
			font-weight: 600;
			src: url("<?php echo esc_attr( $font_dir . 'Inter-SemiBold.ttf' ); ?>") format("truetype");
		}

		@font-face {
			font-family: Inter;
			font-style: normal;
			font-weight: 700;
			src: url("<?php echo esc_attr( $font_dir . 'Inter-Bold.ttf' ); ?>") format("truetype");
		}

		.ppcfw-header,
		.ppcfw-footer {
			position: fixed;
			left: 0;
			right: 0;
			color: #52606d;
		}

		.ppcfw-header {
			top: -92px;
			height: 74px;
			padding: 0 36px;
			border-bottom: 1px solid #d9e2ec;
		}

		.ppcfw-footer {
			bottom: -82px;
			height: 60px;
			padding: 0 32px;
			border-top: 1px solid #d9e2ec;
			font-size: 9px;
		}

		.ppcfw-header__eyebrow {
			font-size: 9px;
			text-transform: uppercase;
			letter-spacing: 0.08em;
			color: #829ab1;
			margin-bottom: 6px;
		}

		.ppcfw-header__title {
			font-size: 22px;
			font-weight: 700;
			color: #102a43;
			margin: 0 0 6px;
		}

		.ppcfw-header__text {
			font-size: 10px;
		}

		.ppcfw-footer__page::after {
			content: "Page " counter(page);
			float: right;
		}

		.ppcfw-summary {
			background: #f8fbff;
			border: 1px solid #d9e2ec;
			border-radius: 8px;
			padding: 18px 20px;
			margin-bottom: 22px;
			page-break-inside: avoid;
		}

		.ppcfw-summary__meta {
			margin: 0;
			padding: 0;
			list-style: none;
		}

		.ppcfw-summary__meta li {
			margin: 0 0 6px;
		}

		.ppcfw-tags {
			margin-top: 10px;
		}

		.ppcfw-tag {
			display: inline-block;
			margin: 0 8px 8px 0;
			padding: 4px 8px;
			background: #d9e2ec;
			border-radius: 999px;
			font-size: 9px;
			color: #243b53;
		}

		.ppcfw-table {
			width: 100%;
			border-collapse: collapse;
			table-layout: fixed;
		}

		.ppcfw-table thead {
			display: table-header-group;
		}

		.ppcfw-table__group {
			page-break-inside: avoid;
			break-inside: avoid;
		}

		.ppcfw-table tr,
		.ppcfw-table td,
		.ppcfw-table th {
			page-break-inside: avoid;
			break-inside: avoid;
		}

		.ppcfw-table th,
		.ppcfw-table td {
			border: 1px solid #dde7f0;
			padding: 8px 6px;
			vertical-align: top;
			word-wrap: break-word;
			overflow-wrap: anywhere;
			word-break: break-word;
		}

		.ppcfw-table th {
			text-transform: uppercase;
			font-size: 8px;
			letter-spacing: 0.06em;
			text-align: left;
			color: #486581;
			background: #f0f4f8;
		}

		.ppcfw-table__image {
			width: <?php echo esc_html( $image_column_width ); ?>%;
			min-width: <?php echo esc_html( $image_column_width ); ?>%;
			max-width: <?php echo esc_html( $image_column_width ); ?>%;
			padding-left: 2px;
			padding-right: 2px;
		}

		.ppcfw-table__price {
			width: <?php echo esc_html( $price_column_width ); ?>%;
			min-width: <?php echo esc_html( $price_column_width ); ?>%;
			max-width: <?php echo esc_html( $price_column_width ); ?>%;
			text-align: right;
			font-weight: 700;
			color: #102a43;
			white-space: normal;
			overflow-wrap: anywhere;
			word-break: break-word;
		}

		.ppcfw-table__attribute {
			white-space: normal;
			overflow-wrap: anywhere;
			word-break: break-word;
		}

		.ppcfw-table__product {
			width: <?php echo esc_html( $product_column_width ); ?>%;
			min-width: <?php echo esc_html( $product_column_width ); ?>%;
			max-width: <?php echo esc_html( $product_column_width ); ?>%;
		}

		.ppcfw-product-image {
			width: 24px;
			height: 24px;
			border: 1px solid #d9e2ec;
			border-radius: 4px;
			object-fit: contain;
			background: #fff;
		}

		.ppcfw-product-image--variant {
			width: 12px;
			height: 12px;
			border-radius: 3px;
		}

		.ppcfw-product-name {
			font-weight: 700;
			color: #102a43;
			margin-bottom: 4px;
		}

		.ppcfw-product-link {
			color: inherit;
			text-decoration: none;
		}

		.ppcfw-stock-badge {
			display: inline-block;
			margin-left: 6px;
			padding: 2px 6px;
			border: 1px solid #f3c7c7;
			border-radius: 999px;
			background: #fff1f2;
			color: #9f1239;
			font-size: 8px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.04em;
		}

		.ppcfw-product-meta {
			font-size: 9px;
			color: #52606d;
		}

		.ppcfw-table__row--variable td {
			background: #edf2f7;
		}

		.ppcfw-table__row--variant .ppcfw-product-name {
			font-weight: 600;
		}
	</style>
</head>
<body>
	<div class="ppcfw-header">
		<div class="ppcfw-header__eyebrow"><?php echo esc_html( (string) ( $document_data['site_title'] ?? '' ) ); ?></div>
		<div class="ppcfw-header__title"><?php echo esc_html( (string) ( $document_data['title'] ?? '' ) ); ?></div>
		<div class="ppcfw-header__text"><?php echo wp_kses_post( (string) ( $document_data['header_text'] ?? '' ) ); ?></div>
	</div>

	<div class="ppcfw-footer">
		<div><?php echo wp_kses_post( (string) ( $document_data['footer_text'] ?? '' ) ); ?></div>
		<div class="ppcfw-footer__page"></div>
	</div>

	<section class="ppcfw-summary">
		<ul class="ppcfw-summary__meta">
			<li><strong><?php echo esc_html__( 'Generated', 'pdf-product-catalogs-for-woocommerce' ); ?>:</strong> <?php echo esc_html( (string) ( $document_data['generated_label'] ?? '' ) ); ?></li>
			<li><strong><?php echo esc_html__( 'Price basis', 'pdf-product-catalogs-for-woocommerce' ); ?>:</strong> <?php echo esc_html( (string) ( $document_data['price_basis_label'] ?? '' ) ); ?></li>
			<?php if ( ! empty( $document_data['discount_label'] ) ) : ?>
				<li><strong><?php echo esc_html__( 'Discount', 'pdf-product-catalogs-for-woocommerce' ); ?>:</strong> <?php echo esc_html( (string) $document_data['discount_label'] ); ?></li>
			<?php endif; ?>
			<li><strong><?php echo esc_html__( 'Products', 'pdf-product-catalogs-for-woocommerce' ); ?>:</strong> <?php echo esc_html( (string) ( $document_data['product_count'] ?? 0 ) ); ?></li>
		</ul>

		<?php if ( ! empty( $scope_labels ) ) : ?>
			<div class="ppcfw-tags">
				<?php foreach ( $scope_labels as $label ) : ?>
					<span class="ppcfw-tag"><?php echo esc_html( (string) $label ); ?></span>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>

	<table class="ppcfw-table">
		<colgroup>
			<col style="width: <?php echo esc_attr( $image_column_width ); ?>%;" />
			<col style="width: <?php echo esc_attr( $product_column_width ); ?>%;" />
			<?php foreach ( $attribute_columns as $index => $column ) : ?>
				<col style="width: <?php echo esc_attr( (string) ( $attribute_widths[ $index ] ?? 8 ) ); ?>%;" />
			<?php endforeach; ?>
			<col style="width: <?php echo esc_attr( $price_column_width ); ?>%;" />
		</colgroup>
		<thead>
			<tr>
				<th class="ppcfw-table__image"><?php echo esc_html__( 'Image', 'pdf-product-catalogs-for-woocommerce' ); ?></th>
				<th class="ppcfw-table__product"><?php echo esc_html__( 'Product', 'pdf-product-catalogs-for-woocommerce' ); ?></th>
				<?php foreach ( $attribute_columns as $index => $column ) : ?>
					<th class="ppcfw-table__attribute" style="width: <?php echo esc_attr( (string) ( $attribute_widths[ $index ] ?? 8 ) ); ?>%;"><?php echo esc_html( (string) ( $column['label'] ?? '' ) ); ?></th>
				<?php endforeach; ?>
				<th class="ppcfw-table__price"><?php echo esc_html__( 'Price', 'pdf-product-catalogs-for-woocommerce' ); ?></th>
			</tr>
		</thead>
