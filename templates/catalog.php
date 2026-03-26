<?php
/**
 * @var array<string,mixed> $document_data
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rows              = isset( $document_data['rows'] ) && is_array( $document_data['rows'] ) ? $document_data['rows'] : array();
$attribute_columns = isset( $document_data['attribute_columns'] ) && is_array( $document_data['attribute_columns'] ) ? $document_data['attribute_columns'] : array();
$scope_labels      = isset( $document_data['scope_labels'] ) && is_array( $document_data['scope_labels'] ) ? $document_data['scope_labels'] : array();
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8" />
	<title><?php echo esc_html( (string) ( $document_data['title'] ?? '' ) ); ?></title>
	<style>
		@page {
			margin: 120px 36px 92px;
		}

		body {
			font-family: DejaVu Sans, sans-serif;
			font-size: 10px;
			color: #1f2933;
			line-height: 1.45;
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
			bottom: -70px;
			height: 48px;
			padding: 0 36px;
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

		.ppcfw-table th,
		.ppcfw-table td {
			border-bottom: 1px solid #d9e2ec;
			padding: 8px 6px;
			vertical-align: top;
			word-wrap: break-word;
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
			width: 54px;
		}

		.ppcfw-table__price {
			width: 90px;
			text-align: right;
			font-weight: 700;
			color: #102a43;
		}

		.ppcfw-product-image {
			width: 46px;
			height: 46px;
			border: 1px solid #d9e2ec;
			border-radius: 6px;
			object-fit: contain;
			background: #fff;
		}

		.ppcfw-product-name {
			font-weight: 700;
			color: #102a43;
			margin-bottom: 4px;
		}

		.ppcfw-product-meta {
			font-size: 9px;
			color: #52606d;
		}

		.ppcfw-link-icon {
			display: inline-block;
			margin-left: 4px;
			font-size: 9px;
			color: #0b6e4f;
			text-decoration: none;
		}
	</style>
</head>
<body>
	<div class="ppcfw-header">
		<div class="ppcfw-header__eyebrow"><?php echo esc_html__( 'WooCommerce Product Catalog', 'pdf-product-catalogs-for-woocommerce' ); ?></div>
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
			<li><strong><?php echo esc_html__( 'Discount', 'pdf-product-catalogs-for-woocommerce' ); ?>:</strong> <?php echo esc_html( (string) ( $document_data['discount_label'] ?? '' ) ); ?></li>
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
		<thead>
			<tr>
				<th class="ppcfw-table__image"><?php echo esc_html__( 'Image', 'pdf-product-catalogs-for-woocommerce' ); ?></th>
				<th><?php echo esc_html__( 'Product', 'pdf-product-catalogs-for-woocommerce' ); ?></th>
				<?php foreach ( $attribute_columns as $column ) : ?>
					<th><?php echo esc_html( (string) ( $column['label'] ?? '' ) ); ?></th>
				<?php endforeach; ?>
				<th class="ppcfw-table__price"><?php echo esc_html__( 'Price', 'pdf-product-catalogs-for-woocommerce' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td class="ppcfw-table__image">
						<?php if ( ! empty( $row['image_data_uri'] ) ) : ?>
							<img class="ppcfw-product-image" src="<?php echo esc_attr( (string) $row['image_data_uri'] ); ?>" alt="" />
						<?php endif; ?>
					</td>
					<td>
						<div class="ppcfw-product-name">
							<?php echo esc_html( (string) ( $row['name'] ?? '' ) ); ?>
							<?php if ( ! empty( $row['product_url'] ) ) : ?>
								<a class="ppcfw-link-icon" href="<?php echo esc_url( (string) $row['product_url'] ); ?>">&#8599;</a>
							<?php endif; ?>
						</div>
						<div class="ppcfw-product-meta">
							<div><strong><?php echo esc_html__( 'SKU', 'pdf-product-catalogs-for-woocommerce' ); ?>:</strong> <?php echo esc_html( (string) ( $row['sku'] ?? '' ) ); ?></div>
							<div><strong><?php echo esc_html__( 'GTIN', 'pdf-product-catalogs-for-woocommerce' ); ?>:</strong> <?php echo esc_html( (string) ( $row['gtin'] ?? '' ) ); ?></div>
						</div>
					</td>
					<?php foreach ( (array) ( $row['attributes'] ?? array() ) as $attribute_value ) : ?>
						<td><?php echo esc_html( (string) $attribute_value ); ?></td>
					<?php endforeach; ?>
					<td class="ppcfw-table__price"><?php echo esc_html( (string) ( $row['price'] ?? '' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</body>
</html>
