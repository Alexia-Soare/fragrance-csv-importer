<?php
/**
 * Plugin Name: Fragrance CSV Importer
 * Description: Import WooCommerce products from a CSV file (standard WooCommerce export column format). Skips duplicate SKUs and groups different ml sizes of the same fragrance into one variable product with a Size dropdown.
 * Version:     1.2.0
 * Author:      Fragrance101
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 *
 * The code reads top-to-bottom as a pipeline:
 *   admin page  ->  fci_import_csv()  ->  read + group rows  ->  write products.
 * Each section below is marked with a banner so it is easy to jump to.
 */

defined( 'ABSPATH' ) || exit;

/*
 * ---------------------------------------------------------------------------
 * Configuration
 * ---------------------------------------------------------------------------
 */

const FCI_MENU_SLUG      = 'fragrance-csv-importer';
const FCI_CAPABILITY     = 'manage_woocommerce';
const FCI_NONCE_ACTION   = 'fci_import';
const FCI_TEXT_DOMAIN    = 'fragrance-csv-importer';
const FCI_BRAND_TAXONOMY = 'product_brand';

/** Transient that serialises imports so two runs can't race and duplicate products. */
const FCI_LOCK_KEY = 'fci_import_lock';
const FCI_LOCK_TTL = 900; // 15 minutes, in seconds.

/** Matches the ml size inside a product name, e.g. "… EDP 50ml" => "50ml". */
const FCI_SIZE_PATTERN = '/(\d+(?:\.\d+)?)\s*ml\b/i';

/** Catalog-visibility values WooCommerce accepts. */
const FCI_VISIBILITIES = array( 'visible', 'catalog', 'search', 'hidden' );

/*
 * ---------------------------------------------------------------------------
 * Admin page
 * ---------------------------------------------------------------------------
 */

add_action( 'admin_menu', static function () {
	add_submenu_page(
		'woocommerce',
		__( 'CSV Product Importer', FCI_TEXT_DOMAIN ),
		__( 'CSV Importer', FCI_TEXT_DOMAIN ),
		FCI_CAPABILITY,
		FCI_MENU_SLUG,
		'fci_render_admin_page'
	);
} );

/**
 * Render the importer screen and run an import when the form is submitted.
 */
function fci_render_admin_page() {
	if ( ! current_user_can( FCI_CAPABILITY ) ) {
		wp_die( esc_html__( 'You do not have permission to import products.', FCI_TEXT_DOMAIN ) );
	}

	$summary = fci_handle_form_submission();

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'CSV Product Importer', FCI_TEXT_DOMAIN ); ?></h1>
		<p><?php esc_html_e( 'Upload a WooCommerce-format CSV. Duplicate SKUs are skipped. Rows that share a fragrance but differ only in ml size are merged into one variable product with a Size dropdown.', FCI_TEXT_DOMAIN ); ?></p>

		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( FCI_NONCE_ACTION ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="fci_csv"><?php esc_html_e( 'CSV file', FCI_TEXT_DOMAIN ); ?></label></th>
					<td><input type="file" name="fci_csv" id="fci_csv" accept=".csv,text/csv" required></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options', FCI_TEXT_DOMAIN ); ?></th>
					<td>
						<label><input type="checkbox" name="fci_download_images" value="1" checked> <?php esc_html_e( 'Download images from URLs into the media library', FCI_TEXT_DOMAIN ); ?></label><br>
						<label><input type="checkbox" name="fci_dry_run" value="1"> <?php esc_html_e( 'Dry run (parse and report, but do not save anything)', FCI_TEXT_DOMAIN ); ?></label>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Import products', FCI_TEXT_DOMAIN ), 'primary', 'fci_import' ); ?>
		</form>

		<?php if ( is_array( $summary ) ) { fci_render_summary( $summary ); } ?>
	</div>
	<?php
}

/**
 * Validate the POST request and start the import.
 *
 * @return array|null Import summary, or null when there was nothing to import.
 */
function fci_handle_form_submission() {
	if ( ! isset( $_POST['fci_import'] ) ) {
		return null;
	}

	check_admin_referer( FCI_NONCE_ACTION );

	$uploaded_file = isset( $_FILES['fci_csv']['tmp_name'] ) ? $_FILES['fci_csv']['tmp_name'] : '';
	if ( '' === $uploaded_file || ! is_uploaded_file( $uploaded_file ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Please choose a CSV file to upload.', FCI_TEXT_DOMAIN ) . '</p></div>';
		return null;
	}

	return fci_import_csv(
		$uploaded_file,
		isset( $_POST['fci_download_images'] ),
		isset( $_POST['fci_dry_run'] )
	);
}

/**
 * Render the results table for a finished import.
 *
 * @param array $summary Summary returned by fci_import_csv().
 */
function fci_render_summary( array $summary ) {
	?>
	<h2><?php esc_html_e( 'Import results', FCI_TEXT_DOMAIN ); ?></h2>
	<p>
		<strong>
			<?php
			echo esc_html( sprintf(
				/* translators: 1: created, 2: updated, 3: skipped, 4: errors. */
				__( '%1$d created, %2$d updated, %3$d skipped, %4$d errors', FCI_TEXT_DOMAIN ),
				$summary['created'],
				$summary['updated'],
				$summary['skipped'],
				$summary['error']
			) );
			?>
		</strong>
		<?php if ( $summary['dry_run'] ) : ?>
			&mdash; <em><?php esc_html_e( 'dry run, nothing was saved', FCI_TEXT_DOMAIN ); ?></em>
		<?php endif; ?>
	</p>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Line', FCI_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'SKU', FCI_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Name', FCI_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Status', FCI_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Message', FCI_TEXT_DOMAIN ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $summary['log'] as $entry ) : ?>
				<tr>
					<td><?php echo esc_html( $entry['line'] ); ?></td>
					<td><?php echo esc_html( $entry['sku'] ); ?></td>
					<td><?php echo esc_html( $entry['name'] ); ?></td>
					<td><?php echo esc_html( $entry['status'] ); ?></td>
					<td><?php echo esc_html( $entry['message'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/*
 * ---------------------------------------------------------------------------
 * Import pipeline
 * ---------------------------------------------------------------------------
 */

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

/*
 * ---------------------------------------------------------------------------
 * Product writers
 * ---------------------------------------------------------------------------
 */

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

/*
 * ---------------------------------------------------------------------------
 * Field mappers (row -> WC_Product setters)
 * ---------------------------------------------------------------------------
 */

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

/*
 * ---------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------------
 */

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
