<?php
/**
 * Field mappers: copy row values onto a WC_Product via its setters. Shared by
 * both the simple and variable writers.
 *
 * @package Fragrance_CSV_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Apply the catalog fields shared by simple products and variable-product parents.
 *
 * @param WC_Product $product Product being built.
 * @param array      $row     Source row.
 * @param string     $name    Product name to set.
 */
function fci_apply_catalog_fields( $product, array $row, $name ) {
	if ( '' !== $name ) {
		$product->set_name( $name );
	}

	$published = fci_cell( $row, 'published' );
	$product->set_status( ( '1' === $published || '' === $published ) ? 'publish' : 'draft' );

	$product->set_featured( '1' === fci_cell( $row, 'is featured?' ) );

	$visibility = strtolower( fci_cell( $row, 'visibility in catalog' ) );
	$product->set_catalog_visibility( in_array( $visibility, FCI_VISIBILITIES, true ) ? $visibility : 'visible' );

	$short_description = fci_cell( $row, 'short description' );
	if ( '' !== $short_description ) {
		$product->set_short_description( $short_description );
	}

	$description = fci_cell( $row, 'description' );
	if ( '' !== $description ) {
		$product->set_description( $description );
	}

	$category_ids = fci_resolve_terms( fci_cell( $row, 'categories' ), 'product_cat' );
	if ( $category_ids ) {
		$product->set_category_ids( $category_ids );
	}

	$tag_ids = fci_resolve_terms( fci_cell( $row, 'tags' ), 'product_tag' );
	if ( $tag_ids ) {
		$product->set_tag_ids( $tag_ids );
	}
}

/**
 * Apply regular and sale prices. Shared by products and variations.
 *
 * @param WC_Product $product Product or variation.
 * @param array      $row     Source row.
 */
function fci_apply_pricing( $product, array $row ) {
	$regular_price = fci_cell( $row, 'regular price' );
	if ( '' !== $regular_price ) {
		$product->set_regular_price( wc_format_decimal( $regular_price ) );
	}

	$sale_price = fci_cell( $row, 'sale price' );
	$product->set_sale_price( '' !== $sale_price ? wc_format_decimal( $sale_price ) : '' );
}

/**
 * Apply stock and shipping dimensions. Shared by products and variations.
 *
 * @param WC_Product $product Product or variation.
 * @param array      $row     Source row.
 */
function fci_apply_stock_and_dimensions( $product, array $row ) {
	$stock = fci_cell( $row, 'stock' );
	if ( '' !== $stock ) {
		$product->set_manage_stock( true );
		$product->set_stock_quantity( (float) $stock );
	}

	$product->set_stock_status( '0' === fci_cell( $row, 'in stock?' ) ? 'outofstock' : 'instock' );

	// Map each dimension column to its WC_Product setter.
	$dimension_setters = array(
		'weight (kg)' => 'set_weight',
		'length (cm)' => 'set_length',
		'width (cm)'  => 'set_width',
		'height (cm)' => 'set_height',
	);
	foreach ( $dimension_setters as $column => $setter ) {
		$value = fci_cell( $row, $column );
		if ( '' !== $value ) {
			$product->{$setter}( wc_format_decimal( $value ) );
		}
	}
}

/**
 * Assign brands and images once a product has an ID.
 *
 * @param int   $product_id      Saved product ID.
 * @param array $row             Source row.
 * @param bool  $download_images Whether to sideload image URLs.
 * @return string Human-readable note for the log (may be empty).
 */
function fci_assign_brands_and_images( $product_id, array $row, $download_images ) {
	// Brands use the native product_brand taxonomy (WooCommerce 9.6+).
	$brands = fci_cell( $row, 'brands' );
	if ( '' !== $brands && taxonomy_exists( FCI_BRAND_TAXONOMY ) ) {
		$brand_ids = fci_resolve_terms( $brands, FCI_BRAND_TAXONOMY );
		if ( $brand_ids ) {
			wp_set_object_terms( $product_id, $brand_ids, FCI_BRAND_TAXONOMY );
		}
	}

	$images = fci_cell( $row, 'images' );
	if ( '' === $images || ! $download_images ) {
		return '';
	}

	$note = fci_sideload_images( $product_id, $images );
	return '' !== $note ? " ({$note})" : '';
}
