<?php
/**
 * @var array<string,mixed> $document_data
 * @var array<string,mixed> $row
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$has_variant_rows = ! empty( $row['variant_rows'] ) && is_array( $row['variant_rows'] );
?>
<tbody class="ppcfw-table__group">
	<tr class="<?php echo esc_attr( $has_variant_rows ? 'ppcfw-table__row--variable' : '' ); ?>">
		<td class="ppcfw-table__image">
			<?php if ( ! empty( $row['image_data_uri'] ) ) : ?>
				<img class="ppcfw-product-image" src="<?php echo esc_attr( (string) $row['image_data_uri'] ); ?>" alt="" />
			<?php endif; ?>
		</td>
		<td class="ppcfw-table__product">
			<div class="ppcfw-product-name">
				<?php if ( ! empty( $row['product_url'] ) ) : ?>
					<a class="ppcfw-product-link" href="<?php echo esc_url( (string) $row['product_url'] ); ?>"><?php echo esc_html( (string) ( $row['name'] ?? '' ) ); ?></a>
				<?php else : ?>
					<?php echo esc_html( (string) ( $row['name'] ?? '' ) ); ?>
				<?php endif; ?>
				<?php if ( ! empty( $row['is_out_of_stock'] ) ) : ?>
					<span class="ppcfw-stock-badge"><?php echo esc_html__( 'Out of stock', 'pdf-product-catalogs-for-woocommerce' ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( ! $has_variant_rows && ( ! empty( $row['sku'] ) || ! empty( $row['gtin'] ) ) ) : ?>
				<div class="ppcfw-product-meta">
					<?php if ( ! empty( $row['sku'] ) ) : ?>
						<div><strong><?php echo esc_html__( 'SKU', 'pdf-product-catalogs-for-woocommerce' ); ?>:</strong> <?php echo esc_html( (string) $row['sku'] ); ?></div>
					<?php endif; ?>
					<?php if ( ! empty( $row['gtin'] ) ) : ?>
						<div><strong><?php echo esc_html__( 'GTIN', 'pdf-product-catalogs-for-woocommerce' ); ?>:</strong> <?php echo esc_html( (string) $row['gtin'] ); ?></div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</td>
		<?php foreach ( (array) ( $row['attributes'] ?? array() ) as $attribute_value ) : ?>
			<td class="ppcfw-table__attribute"><?php echo esc_html( (string) $attribute_value ); ?></td>
		<?php endforeach; ?>
		<td class="ppcfw-table__price"><?php echo esc_html( (string) ( $row['price'] ?? '' ) ); ?></td>
	</tr>
	<?php if ( $has_variant_rows ) : ?>
		<?php foreach ( $row['variant_rows'] as $variant_row ) : ?>
			<tr class="ppcfw-table__row--variant ppcfw-table__row--variable">
				<td class="ppcfw-table__image">
					<?php if ( ! empty( $variant_row['image_data_uri'] ) ) : ?>
						<img class="ppcfw-product-image ppcfw-product-image--variant" src="<?php echo esc_attr( (string) $variant_row['image_data_uri'] ); ?>" alt="" />
					<?php endif; ?>
				</td>
				<td class="ppcfw-table__product">
					<div class="ppcfw-product-name">
						<?php if ( ! empty( $variant_row['product_url'] ) ) : ?>
							<a class="ppcfw-product-link" href="<?php echo esc_url( (string) $variant_row['product_url'] ); ?>"><?php echo esc_html( (string) ( $variant_row['name'] ?? '' ) ); ?></a>
						<?php else : ?>
							<?php echo esc_html( (string) ( $variant_row['name'] ?? '' ) ); ?>
						<?php endif; ?>
						<?php if ( ! empty( $variant_row['is_out_of_stock'] ) ) : ?>
							<span class="ppcfw-stock-badge"><?php echo esc_html__( 'Out of stock', 'pdf-product-catalogs-for-woocommerce' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="ppcfw-product-meta">
						<div><strong><?php echo esc_html__( 'SKU', 'pdf-product-catalogs-for-woocommerce' ); ?>:</strong> <?php echo esc_html( (string) ( $variant_row['sku'] ?? '' ) ); ?></div>
						<?php if ( ! empty( $variant_row['gtin'] ) ) : ?>
							<div><strong><?php echo esc_html__( 'GTIN', 'pdf-product-catalogs-for-woocommerce' ); ?>:</strong> <?php echo esc_html( (string) $variant_row['gtin'] ); ?></div>
						<?php endif; ?>
					</div>
				</td>
				<?php foreach ( (array) ( $variant_row['attributes'] ?? array() ) as $attribute_value ) : ?>
					<td class="ppcfw-table__attribute"><?php echo esc_html( (string) $attribute_value ); ?></td>
				<?php endforeach; ?>
				<td class="ppcfw-table__price"><?php echo esc_html( (string) ( $variant_row['price'] ?? '' ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	<?php endif; ?>
</tbody>
