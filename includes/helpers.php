<?php
/**
 * Helpers: small, reusable utilities used across the importer — CSV cell access,
 * size parsing, term resolution, image sideloading, and result bookkeeping.
 *
 * @package Fragrance_CSV_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Determine whether a raw CSV line is entirely empty.
 *
 * @param array $cells Cells returned by fgetcsv().
 * @return bool
 */
function fci_is_blank_line( array $cells ) {
	foreach ( $cells as $cell ) {
		if ( '' !== trim( (string) $cell ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Read a cell from a row by (case-insensitive) column name.
 *
 * @param array  $row    Row array.
 * @param string $column Column name.
 * @return string Trimmed value, or '' when the column is absent.
 */
function fci_cell( array $row, $column ) {
	$column = strtolower( $column );
	return isset( $row[ $column ] ) ? $row[ $column ] : '';
}

/**
 * Extract the ml size from a product name, e.g. "… EDP 50ml" => "50ml".
 *
 * @param string $name Product name.
 * @return string Size such as "50ml", or '' when none is found.
 */
function fci_parse_size( $name ) {
	if ( preg_match( FCI_SIZE_PATTERN, $name, $matches ) ) {
		return $matches[1] . 'ml';
	}
	return '';
}

/**
 * Remove the ml size token from a product name so variants share a base name.
 *
 * @param string $name Product name.
 * @return string Name without the size, collapsed whitespace.
 */
function fci_strip_size( $name ) {
	$without_size = preg_replace( FCI_SIZE_PATTERN, '', $name );
	return trim( preg_replace( '/\s{2,}/', ' ', $without_size ) );
}

/**
 * Resolve a comma-separated list of term names to term IDs, creating any that
 * don't exist. Supports "Parent > Child" hierarchy for hierarchical taxonomies.
 *
 * @param string $raw_list Comma-separated term names.
 * @param string $taxonomy Taxonomy slug.
 * @return int[] Term IDs.
 */
function fci_resolve_terms( $raw_list, $taxonomy ) {
	$names = array_filter( array_map( 'trim', explode( ',', trim( $raw_list ) ) ) );
	if ( ! $names ) {
		return array();
	}

	$ids = array();
	foreach ( $names as $name ) {
		$term_id = fci_resolve_hierarchical_term( $name, $taxonomy );
		if ( $term_id > 0 ) {
			$ids[] = $term_id;
		}
	}

	return array_values( array_unique( $ids ) );
}

/**
 * Resolve one term name, walking a "Parent > Child" path and creating missing
 * levels as it goes.
 *
 * @param string $name     Term name, optionally "Parent > Child".
 * @param string $taxonomy Taxonomy slug.
 * @return int Deepest term ID, or 0 on failure.
 */
function fci_resolve_hierarchical_term( $name, $taxonomy ) {
	$parent_id = 0;

	foreach ( array_map( 'trim', explode( '>', $name ) ) as $segment ) {
		if ( '' === $segment ) {
			continue;
		}
		$parent_id = fci_find_or_create_term( $segment, $taxonomy, $parent_id );
		if ( 0 === $parent_id ) {
			return 0;
		}
	}

	return $parent_id;
}

/**
 * Find a term by name under a given parent, creating it when absent.
 *
 * @param string $name      Term name.
 * @param string $taxonomy  Taxonomy slug.
 * @param int    $parent_id Parent term ID (0 for top level).
 * @return int Term ID, or 0 on failure.
 */
function fci_find_or_create_term( $name, $taxonomy, $parent_id ) {
	$existing = get_term_by( 'name', $name, $taxonomy );
	if ( $existing && ( ! is_taxonomy_hierarchical( $taxonomy ) || (int) $existing->parent === (int) $parent_id ) ) {
		return (int) $existing->term_id;
	}

	$created = wp_insert_term( $name, $taxonomy, array( 'parent' => $parent_id ) );
	if ( ! is_wp_error( $created ) ) {
		return (int) $created['term_id'];
	}

	// Creation failed, most likely because the term already exists.
	$fallback = get_term_by( 'name', $name, $taxonomy );
	return $fallback ? (int) $fallback->term_id : 0;
}

/**
 * Sideload image URLs and attach them to a product. The first becomes the
 * featured image; the rest form the gallery.
 *
 * @param int    $product_id Product ID.
 * @param string $raw_urls   Comma-separated image URLs.
 * @return string Human-readable result note.
 */
function fci_sideload_images( $product_id, $raw_urls ) {
	$urls = array_filter( array_map( 'trim', explode( ',', $raw_urls ) ) );
	if ( ! $urls ) {
		return '';
	}

	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$attachment_ids = array();
	$failures       = 0;

	foreach ( $urls as $url ) {
		$attachment_id = fci_sideload_single_image( $url, $product_id );
		if ( $attachment_id > 0 ) {
			$attachment_ids[] = $attachment_id;
		} else {
			$failures++;
		}
	}

	if ( ! $attachment_ids ) {
		return $failures ? sprintf( '%d image(s) failed', $failures ) : '';
	}

	fci_assign_product_images( $product_id, $attachment_ids );

	$note = sprintf( '%d image(s) attached', count( $attachment_ids ) );
	if ( $failures ) {
		$note .= sprintf( ', %d failed', $failures );
	}

	return $note;
}

/**
 * Sideload a single image URL.
 *
 * @param string $url        Image URL.
 * @param int    $product_id Product to attach the media to.
 * @return int Attachment ID, or 0 on failure.
 */
function fci_sideload_single_image( $url, $product_id ) {
	if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
		return 0;
	}

	$attachment_id = media_sideload_image( $url, $product_id, null, 'id' );
	return is_wp_error( $attachment_id ) ? 0 : (int) $attachment_id;
}

/**
 * Set the featured image and gallery from a list of attachment IDs.
 *
 * @param int   $product_id     Product ID.
 * @param int[] $attachment_ids Attachment IDs; the first is used as featured.
 */
function fci_assign_product_images( $product_id, array $attachment_ids ) {
	$featured_id = array_shift( $attachment_ids );
	set_post_thumbnail( $product_id, $featured_id );

	if ( ! $attachment_ids ) {
		return;
	}

	$product = wc_get_product( $product_id );
	if ( $product ) {
		$product->set_gallery_image_ids( $attachment_ids );
		$product->save();
	}
}

/**
 * Build one result record for the summary log.
 *
 * @param string $status  One of: created, updated, skipped, error.
 * @param int    $line    CSV line number.
 * @param string $sku     Product SKU (empty for variable parents).
 * @param string $name    Product name.
 * @param string $message Human-readable message.
 * @return array
 */
function fci_result( $status, $line, $sku, $name, $message ) {
	return array(
		'status'  => $status,
		'line'    => $line,
		'sku'     => $sku,
		'name'    => $name,
		'message' => $message,
	);
}

/**
 * Append a result to the summary and increment the matching status counter.
 *
 * @param array $summary Summary, passed by reference.
 * @param array $result  Result record from fci_result().
 */
function fci_record( array &$summary, array $result ) {
	$summary['log'][] = $result;

	$status = $result['status'];
	if ( isset( $summary[ $status ] ) ) {
		$summary[ $status ]++;
	}
}
