<?php
/**
 * Product writers: turn grouped rows into WooCommerce simple or variable products.
 *
 * @package Fragrance_CSV_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create or update a simple product from a single row (matched by SKU).
 *
 * @param array $row             Annotated row.
 * @param bool  $download_images Whether to sideload image URLs.
 * @param bool  $dry_run         When true, save nothing.
 * @return array Result record.
 * @throws Exception When the product cannot be saved.
 */
function fci_write_simple_product( array $row, $download_images, $dry_run ) {
	$sku  = fci_cell( $row, 'sku' );
	$name = fci_cell( $row, 'name' );

	$existing_id = wc_get_product_id_by_sku( $sku );
	$is_update   = $existing_id > 0;

	if ( $dry_run ) {
		$message = $is_update ? "Would update simple product #{$existing_id}." : 'Would create a simple product.';
		return fci_result( $is_update ? 'updated' : 'created', $row['_line'], $sku, $name, $message );
	}

	$product = $existing_id ? wc_get_product( $existing_id ) : null;
	if ( ! $product || ! $product->is_type( 'simple' ) ) {
		$product = new WC_Product_Simple();
	}

	$product->set_sku( $sku );
	fci_apply_catalog_fields( $product, $row, $name );
	fci_apply_pricing( $product, $row );
	fci_apply_stock_and_dimensions( $product, $row );

	$product_id = $product->save();
	if ( ! $product_id ) {
		throw new Exception( 'The product could not be saved.' );
	}

	$note    = fci_assign_brands_and_images( $product_id, $row, $download_images );
	$message = sprintf( '%s simple product #%d%s', $is_update ? 'Updated' : 'Created', $product_id, $note );

	return fci_result( $is_update ? 'updated' : 'created', $row['_line'], $sku, $name, $message );
}

/**
 * Create or update one variable product with a Size dropdown from its variants.
 *
 * @param array[] $variants        One row per distinct size.
 * @param bool    $download_images Whether to sideload image URLs.
 * @param bool    $dry_run         When true, save nothing.
 * @return array Result record.
 * @throws Exception When the product cannot be saved.
 */
function fci_write_variable_product( array $variants, $download_images, $dry_run ) {
	usort( $variants, static function ( $a, $b ) {
		return (float) $a['_size'] <=> (float) $b['_size'];
	} );

	$first     = $variants[0];
	$base_name = $first['_base_name'];
	$line      = $first['_line'];
	$sizes     = wp_list_pluck( $variants, '_size' );

	// The parent has no SKU of its own (each size's SKU differs), so match an
	// existing variable product via any of its variation SKUs.
	$existing_id = fci_find_variable_parent_id( $variants );
	$is_update   = $existing_id > 0;

	if ( $dry_run ) {
		$message = sprintf(
			'%s with sizes: %s',
			$is_update ? "Would update variable product #{$existing_id}" : 'Would create a variable product',
			implode( ', ', $sizes )
		);
		return fci_result( $is_update ? 'updated' : 'created', $line, '', $base_name, $message );
	}

	$product = $existing_id ? wc_get_product( $existing_id ) : null;
	if ( ! $product || ! $product->is_type( 'variable' ) ) {
		$product = new WC_Product_Variable();
	}

	fci_apply_catalog_fields( $product, $first, $base_name );
	$product->set_attributes( array( fci_build_size_attribute( $sizes ) ) );

	$product_id = $product->save();
	if ( ! $product_id ) {
		throw new Exception( 'The variable product could not be saved.' );
	}

	foreach ( $variants as $variant ) {
		fci_write_variation( $product_id, $variant );
	}

	// Recalculate the parent's price range, stock status, etc.
	WC_Product_Variable::sync( $product_id );

	$note    = fci_assign_brands_and_images( $product_id, $first, $download_images );
	$message = sprintf(
		'%s variable product #%d with %d sizes (%s)%s',
		$is_update ? 'Updated' : 'Created',
		$product_id,
		count( $variants ),
		implode( ', ', $sizes ),
		$note
	);

	return fci_result( $is_update ? 'updated' : 'created', $line, '', $base_name, $message );
}

/**
 * Find an existing variable parent by looking up any of its variation SKUs.
 *
 * @param array[] $variants Variant rows.
 * @return int Parent product ID, or 0 when none exists yet.
 */
function fci_find_variable_parent_id( array $variants ) {
	foreach ( $variants as $variant ) {
		$variation_id = wc_get_product_id_by_sku( fci_cell( $variant, 'sku' ) );
		if ( ! $variation_id ) {
			continue;
		}

		$variation = wc_get_product( $variation_id );
		if ( $variation && $variation->is_type( 'variation' ) ) {
			return $variation->get_parent_id();
		}
	}

	return 0;
}

/**
 * Build the "Size" attribute that drives the front-end dropdown.
 *
 * @param string[] $sizes Size options, e.g. array( '50ml', '100ml' ).
 * @return WC_Product_Attribute
 */
function fci_build_size_attribute( array $sizes ) {
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'Size' );
	$attribute->set_options( $sizes );
	$attribute->set_visible( true );
	$attribute->set_variation( true );

	return $attribute;
}

/**
 * Create or update a single variation of a variable product (matched by SKU).
 *
 * @param int   $parent_id Parent product ID.
 * @param array $variant   Variant row.
 */
function fci_write_variation( $parent_id, array $variant ) {
	$sku         = fci_cell( $variant, 'sku' );
	$existing_id = $sku ? wc_get_product_id_by_sku( $sku ) : 0;

	$variation = $existing_id ? wc_get_product( $existing_id ) : null;
	if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
		$variation = new WC_Product_Variation();
	}

	$variation->set_parent_id( $parent_id );
	$variation->set_attributes( array( 'size' => $variant['_size'] ) );
	if ( '' !== $sku ) {
		$variation->set_sku( $sku );
	}

	fci_apply_pricing( $variation, $variant );
	fci_apply_stock_and_dimensions( $variation, $variant );

	$variation->save();
}
