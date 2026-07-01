<?php
/**
 * Import pipeline: read the CSV, deduplicate, group ml variants, and dispatch
 * each group to the product writers.
 *
 * @package Fragrance_CSV_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parse a CSV file, deduplicate by SKU, group ml-size variants, and import.
 *
 * @param string $file_path       Path to the uploaded CSV file.
 * @param bool   $download_images Whether to sideload image URLs.
 * @param bool   $dry_run         When true, parse and report but save nothing.
 * @return array Summary with per-status counts and a per-row log.
 */
function fci_import_csv( $file_path, $download_images = true, $dry_run = false ) {
	$summary = array(
		'created' => 0,
		'updated' => 0,
		'skipped' => 0,
		'error'   => 0,
		'dry_run' => $dry_run,
		'log'     => array(),
	);

	if ( get_transient( FCI_LOCK_KEY ) ) {
		fci_record( $summary, fci_result( 'error', 0, '', '', __( 'Another import is already running (or one crashed in the last 15 minutes). Wait for it to finish and try again — do not submit twice.', FCI_TEXT_DOMAIN ) ) );
		return $summary;
	}

	set_transient( FCI_LOCK_KEY, 1, FCI_LOCK_TTL );

	try {
		$rows = fci_read_csv( $file_path, $summary );
		if ( null !== $rows ) {
			foreach ( fci_group_rows( $rows, $summary ) as $group ) {
				fci_import_group( $group, $download_images, $dry_run, $summary );
			}
		}
	} finally {
		delete_transient( FCI_LOCK_KEY );
	}

	return $summary;
}

/**
 * Read every data row from the CSV into annotated row arrays.
 *
 * @param string $file_path Path to the CSV file.
 * @param array  $summary   Summary, passed by reference to log fatal read errors.
 * @return array[]|null Rows, or null when the file could not be read.
 */
function fci_read_csv( $file_path, array &$summary ) {
	$handle = fopen( $file_path, 'r' );
	if ( false === $handle ) {
		fci_record( $summary, fci_result( 'error', 0, '', '', __( 'Could not open the uploaded file.', FCI_TEXT_DOMAIN ) ) );
		return null;
	}

	try {
		$columns = fci_read_header( $handle );
		if ( null === $columns ) {
			fci_record( $summary, fci_result( 'error', 0, '', '', __( 'The CSV file appears to be empty.', FCI_TEXT_DOMAIN ) ) );
			return null;
		}
		return fci_read_data_rows( $handle, $columns );
	} finally {
		fclose( $handle );
	}
}

/**
 * Read the header row and map lower-cased column names to their positions.
 *
 * @param resource $handle Open file handle positioned at the start.
 * @return array<string,int>|null Column map, or null when the file is empty.
 */
function fci_read_header( $handle ) {
	$header = fgetcsv( $handle );
	if ( ! $header || array( null ) === $header ) {
		return null;
	}

	// Strip a UTF-8 byte-order mark from the first header cell if present.
	$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );

	$columns = array();
	foreach ( $header as $position => $name ) {
		$columns[ strtolower( trim( (string) $name ) ) ] = $position;
	}

	return $columns;
}

/**
 * Read the remaining rows, skipping blank lines and annotating each with its
 * ml size and base name.
 *
 * @param resource          $handle  Open file handle positioned after the header.
 * @param array<string,int> $columns Column map from fci_read_header().
 * @return array[] Annotated rows.
 */
function fci_read_data_rows( $handle, array $columns ) {
	$rows        = array();
	$line_number = 1; // The header occupies line 1.

	while ( ( $cells = fgetcsv( $handle ) ) !== false ) {
		$line_number++;

		if ( fci_is_blank_line( $cells ) ) {
			continue;
		}

		$row = array( '_line' => $line_number );
		foreach ( $columns as $column => $position ) {
			$row[ $column ] = isset( $cells[ $position ] ) ? trim( (string) $cells[ $position ] ) : '';
		}

		$rows[] = fci_annotate_row( $row );
	}

	return $rows;
}

/**
 * Add derived `_size` and `_base_name` fields to a row.
 *
 * @param array $row Raw row (column => value).
 * @return array Row with derived fields.
 */
function fci_annotate_row( array $row ) {
	$name = fci_cell( $row, 'name' );
	$size = fci_parse_size( $name );

	$row['_size']      = $size;
	$row['_base_name'] = '' !== $size ? fci_strip_size( $name ) : $name;

	return $row;
}

/**
 * Skip rows without a SKU and duplicate SKUs, then group the survivors so that
 * ml variants of one fragrance land together.
 *
 * Variants share a base name but each size has its own SKU whose numeric segment
 * differs (e.g. DIO-FRE-0127-75 vs DIO-FRE-0128-50), so sized rows are grouped by
 * base NAME. Size-less rows stand alone under their own SKU.
 *
 * @param array[] $rows    Annotated rows.
 * @param array   $summary Summary, passed by reference to log skipped rows.
 * @return array[][] Groups of rows, keyed by group key.
 */
function fci_group_rows( array $rows, array &$summary ) {
	$groups            = array();
	$first_line_by_sku = array();

	foreach ( $rows as $row ) {
		$sku  = fci_cell( $row, 'sku' );
		$name = fci_cell( $row, 'name' );

		if ( '' === $sku ) {
			fci_record( $summary, fci_result( 'skipped', $row['_line'], '', $name, __( 'The row has no SKU.', FCI_TEXT_DOMAIN ) ) );
			continue;
		}

		$sku_key = strtolower( $sku );
		if ( isset( $first_line_by_sku[ $sku_key ] ) ) {
			$message = sprintf(
				/* translators: %d: line number where the SKU first appeared. */
				__( 'Duplicate SKU (first seen on line %d).', FCI_TEXT_DOMAIN ),
				$first_line_by_sku[ $sku_key ]
			);
			fci_record( $summary, fci_result( 'skipped', $row['_line'], $sku, $name, $message ) );
			continue;
		}
		$first_line_by_sku[ $sku_key ] = $row['_line'];

		$groups[ fci_group_key( $row ) ][] = $row;
	}

	return $groups;
}

/**
 * Build the grouping key for a row (see fci_group_rows() for the rationale).
 *
 * @param array $row Annotated row.
 * @return string
 */
function fci_group_key( array $row ) {
	return '' !== $row['_size']
		? 'name:' . strtolower( $row['_base_name'] )
		: 'sku:' . strtolower( fci_cell( $row, 'sku' ) );
}

/**
 * Import one group as either a variable product (2+ sizes) or a simple product.
 *
 * @param array[] $group           Rows sharing a group key.
 * @param bool    $download_images Whether to sideload image URLs.
 * @param bool    $dry_run         When true, save nothing.
 * @param array   $summary         Summary, passed by reference.
 */
function fci_import_group( array $group, $download_images, $dry_run, array &$summary ) {
	$variants = fci_unique_sizes( $group, $summary );

	try {
		if ( count( $variants ) >= 2 ) {
			$result = fci_write_variable_product( $variants, $download_images, $dry_run );
		} else {
			// A single size, or a size-less product, becomes a simple product.
			$result = fci_write_simple_product( $group[0], $download_images, $dry_run );
		}
	} catch ( Exception $e ) {
		$first  = $group[0];
		$result = fci_result( 'error', $first['_line'], fci_cell( $first, 'sku' ), fci_cell( $first, 'name' ), $e->getMessage() );
	}

	fci_record( $summary, $result );
}

/**
 * Keep the first row per distinct size and log the rest as skipped duplicates.
 *
 * @param array[] $group   Rows sharing a group key.
 * @param array   $summary Summary, passed by reference.
 * @return array[] Rows that carry a size, one per size.
 */
function fci_unique_sizes( array $group, array &$summary ) {
	$by_size = array();

	foreach ( $group as $row ) {
		if ( '' === $row['_size'] ) {
			continue;
		}

		$size_key = strtolower( $row['_size'] );
		if ( isset( $by_size[ $size_key ] ) ) {
			$message = sprintf(
				/* translators: %s: the ml size, e.g. "50ml". */
				__( 'Duplicate size "%s" for this fragrance.', FCI_TEXT_DOMAIN ),
				$row['_size']
			);
			fci_record( $summary, fci_result( 'skipped', $row['_line'], fci_cell( $row, 'sku' ), fci_cell( $row, 'name' ), $message ) );
			continue;
		}
		$by_size[ $size_key ] = $row;
	}

	return array_values( $by_size );
}
