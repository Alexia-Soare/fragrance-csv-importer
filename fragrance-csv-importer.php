<?php
/**
 * Plugin Name: Fragrance CSV Importer
 * Description: Import WooCommerce products from a CSV file (standard WooCommerce export column format). Skips duplicate SKUs and groups different ml sizes of the same fragrance into one variable product with a Size dropdown.
 * Version:     1.1.0
 * Author:      Fragrance101
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'FCI_PLUGIN_FILE', __FILE__ );

/**
 * Register the admin page under the WooCommerce menu.
 */
add_action( 'admin_menu', function () {
	add_submenu_page(
		'woocommerce',
		__( 'CSV Product Importer', 'fragrance-csv-importer' ),
		__( 'CSV Importer', 'fragrance-csv-importer' ),
		'manage_woocommerce',
		'fragrance-csv-importer',
		'fci_render_admin_page'
	);
} );

/**
 * Render the importer admin page and handle the upload.
 */
function fci_render_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to import products.', 'fragrance-csv-importer' ) );
	}

	$results = null;

	if ( isset( $_POST['fci_import'] ) ) {
		check_admin_referer( 'fci_import_action', 'fci_import_nonce' );

		if ( empty( $_FILES['fci_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['fci_csv']['tmp_name'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Please choose a CSV file to upload.', 'fragrance-csv-importer' ) . '</p></div>';
		} else {
			$download_images = ! empty( $_POST['fci_download_images'] );
			$dry_run         = ! empty( $_POST['fci_dry_run'] );
			$results         = fci_import_csv( $_FILES['fci_csv']['tmp_name'], $download_images, $dry_run );
		}
	}

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'CSV Product Importer', 'fragrance-csv-importer' ); ?></h1>
		<p><?php esc_html_e( 'Upload a WooCommerce-format CSV. Duplicate SKUs are skipped. Rows that share a fragrance but differ only in ml size are merged into one variable product with a Size dropdown.', 'fragrance-csv-importer' ); ?></p>

		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'fci_import_action', 'fci_import_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="fci_csv"><?php esc_html_e( 'CSV file', 'fragrance-csv-importer' ); ?></label></th>
					<td><input type="file" name="fci_csv" id="fci_csv" accept=".csv,text/csv" required></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options', 'fragrance-csv-importer' ); ?></th>
					<td>
						<label><input type="checkbox" name="fci_download_images" value="1" checked> <?php esc_html_e( 'Download images from URLs into the media library', 'fragrance-csv-importer' ); ?></label><br>
						<label><input type="checkbox" name="fci_dry_run" value="1"> <?php esc_html_e( 'Dry run (parse and report, but do not save anything)', 'fragrance-csv-importer' ); ?></label>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Import products', 'fragrance-csv-importer' ), 'primary', 'fci_import' ); ?>
		</form>

		<?php if ( is_array( $results ) ) : ?>
			<h2><?php esc_html_e( 'Import results', 'fragrance-csv-importer' ); ?></h2>
			<p>
				<strong><?php echo esc_html( sprintf( '%d created, %d updated, %d skipped, %d errors', $results['created'], $results['updated'], $results['skipped'], $results['errors'] ) ); ?></strong>
				<?php if ( ! empty( $results['dry_run'] ) ) : ?>
					&mdash; <em><?php esc_html_e( 'dry run, nothing was saved', 'fragrance-csv-importer' ); ?></em>
				<?php endif; ?>
			</p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Row', 'fragrance-csv-importer' ); ?></th>
					<th><?php esc_html_e( 'SKU', 'fragrance-csv-importer' ); ?></th>
					<th><?php esc_html_e( 'Name', 'fragrance-csv-importer' ); ?></th>
					<th><?php esc_html_e( 'Status', 'fragrance-csv-importer' ); ?></th>
					<th><?php esc_html_e( 'Message', 'fragrance-csv-importer' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $results['log'] as $line ) : ?>
					<tr>
						<td><?php echo esc_html( $line['row'] ); ?></td>
						<td><?php echo esc_html( $line['sku'] ); ?></td>
						<td><?php echo esc_html( $line['name'] ); ?></td>
						<td><?php echo esc_html( $line['status'] ); ?></td>
						<td><?php echo esc_html( $line['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Parse a CSV file, deduplicate by SKU, group ml-size variants, and import.
 *
 * @param string $path            Path to the uploaded CSV file.
 * @param bool   $download_images Whether to sideload image URLs.
 * @param bool   $dry_run         If true, nothing is saved.
 * @return array Summary with counts and a per-row log.
 */
function fci_import_csv( $path, $download_images = true, $dry_run = false ) {
	$summary = array(
		'created' => 0,
		'updated' => 0,
		'skipped' => 0,
		'errors'  => 0,
		'dry_run' => $dry_run,
		'log'     => array(),
	);

	$handle = fopen( $path, 'r' );
	if ( false === $handle ) {
		$summary['log'][] = fci_log_line( 0, '', '', 'error', 'Could not open the uploaded file.' );
		$summary['errors']++;
		return $summary;
	}

	$header = fgetcsv( $handle );
	if ( ! $header ) {
		fclose( $handle );
		$summary['log'][] = fci_log_line( 0, '', '', 'error', 'CSV appears to be empty.' );
		$summary['errors']++;
		return $summary;
	}

	// Strip a UTF-8 BOM from the first header cell if present.
	if ( isset( $header[0] ) ) {
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
	}

	$columns = array();
	foreach ( $header as $index => $name ) {
		$columns[ strtolower( trim( $name ) ) ] = $index;
	}

	// Prevent two imports from running at once (concurrent runs race on the
	// unique-SKU check and create duplicate products). Auto-expires in 15 min
	// so a crashed import doesn't lock things out permanently.
	if ( get_transient( 'fci_import_lock' ) ) {
		fclose( $handle );
		$summary['errors']++;
		$summary['log'][] = fci_log_line( 0, '', '', 'error', 'Another import is already running (or one crashed in the last 15 minutes). Wait for it to finish and try again — do not submit twice.' );
		return $summary;
	}
	set_transient( 'fci_import_lock', 1, 15 * MINUTE_IN_SECONDS );

	// --- Pass 1: read rows, skip duplicate SKUs, group by base name. ---
	$seen_skus  = array();   // sku (lowercased) => row number first seen
	$groups     = array();   // group key => array of records
	$order      = array();   // preserve first-seen group order
	$row_number = 1;         // Header is row 1.

	while ( ( $data = fgetcsv( $handle ) ) !== false ) {
		$row_number++;

		// Skip fully blank lines.
		if ( count( array_filter( $data, static function ( $v ) { return '' !== trim( (string) $v ); } ) ) === 0 ) {
			continue;
		}

		$record = array( '_row' => $row_number );
		foreach ( $columns as $col => $idx ) {
			$record[ $col ] = isset( $data[ $idx ] ) ? trim( (string) $data[ $idx ] ) : '';
		}

		$sku  = fci_val( $record, 'sku' );
		$name = fci_val( $record, 'name' );

		if ( '' === $sku ) {
			$summary['skipped']++;
			$summary['log'][] = fci_log_line( $row_number, '', $name, 'skipped', 'No SKU in row.' );
			continue;
		}

		// Deduplicate identical SKUs.
		$sku_key = strtolower( $sku );
		if ( isset( $seen_skus[ $sku_key ] ) ) {
			$summary['skipped']++;
			$summary['log'][] = fci_log_line( $row_number, $sku, $name, 'skipped', 'Duplicate SKU (first seen on row ' . $seen_skus[ $sku_key ] . ').' );
			continue;
		}
		$seen_skus[ $sku_key ] = $row_number;

		// Detect the ml size. Variants of one fragrance share a base name (the
		// name with the ml removed), but each size has its own SKU whose numeric
		// segment differs (DIO-FRE-0127-75 vs DIO-FRE-0128-50), so we must group
		// by base NAME, not by SKU.
		$size = fci_parse_size( $name );
		if ( '' !== $size ) {
			$record['_size']      = $size;
			$record['_base_name'] = fci_strip_size( $name );
			$group_key            = 'name:' . strtolower( $record['_base_name'] );
		} else {
			$record['_size']      = '';
			$record['_base_name'] = $name;
			$group_key            = 'sku:' . strtolower( $sku ); // Standalone simple product.
		}
		if ( ! isset( $groups[ $group_key ] ) ) {
			$groups[ $group_key ] = array();
			$order[]              = $group_key;
		}
		$groups[ $group_key ][] = $record;
	}
	fclose( $handle );

	// --- Pass 2: import each group as one product. ---
	foreach ( $order as $group_key ) {
		$records = $groups[ $group_key ];

		// Within a group, keep the first record per distinct size (drop repeats).
		$by_size  = array();
		$has_size = false;
		foreach ( $records as $rec ) {
			if ( '' === $rec['_size'] ) {
				continue;
			}
			$has_size = true;
			$size_key = strtolower( $rec['_size'] );
			if ( ! isset( $by_size[ $size_key ] ) ) {
				$by_size[ $size_key ] = $rec;
			} else {
				$summary['skipped']++;
				$summary['log'][] = fci_log_line( $rec['_row'], fci_val( $rec, 'sku' ), fci_val( $rec, 'name' ), 'skipped', 'Duplicate size "' . $rec['_size'] . '" for this fragrance.' );
			}
		}
		$variant_records = array_values( $by_size );

		try {
			if ( $has_size && count( $variant_records ) >= 2 ) {
				$result = fci_import_variable( $variant_records, $download_images, $dry_run );
			} else {
				// Only one size (or none): a plain simple product.
				$single = $has_size ? $variant_records[0] : $records[0];
				$result = fci_import_simple( $single, $download_images, $dry_run );
			}
			$summary[ 'created' === $result['status'] ? 'created' : 'updated' ]++;
			$summary['log'][] = fci_log_line( $records[0]['_row'], $result['sku'], $result['name'], $result['status'], $result['message'] );
		} catch ( Exception $e ) {
			$summary['errors']++;
			$summary['log'][] = fci_log_line( $records[0]['_row'], fci_val( $records[0], 'sku' ), fci_val( $records[0], 'name' ), 'error', $e->getMessage() );
		}
	}

	delete_transient( 'fci_import_lock' );
	return $summary;
}

/**
 * Build a log-line array for the results table.
 */
function fci_log_line( $row, $sku, $name, $status, $message ) {
	return array(
		'row'     => $row,
		'sku'     => $sku,
		'name'    => $name,
		'status'  => $status,
		'message' => $message,
	);
}

/**
 * Read a column value from a record by (case-insensitive) column name.
 */
function fci_val( $record, $key ) {
	$key = strtolower( $key );
	return isset( $record[ $key ] ) ? $record[ $key ] : '';
}

/**
 * Extract the ml size from a product name, e.g. "… EDP 50ml" => "50ml".
 */
function fci_parse_size( $name ) {
	if ( preg_match( '/(\d+(?:\.\d+)?)\s*ml\b/i', $name, $m ) ) {
		return $m[1] . 'ml';
	}
	return '';
}

/**
 * Remove the ml size token from a product name so variants share a base name.
 */
function fci_strip_size( $name ) {
	$name = preg_replace( '/\b\d+(?:\.\d+)?\s*ml\b/i', '', $name );
	return trim( preg_replace( '/\s{2,}/', ' ', $name ) );
}

/**
 * Import a single row as a simple product (matched/updated by SKU).
 *
 * @return array{status:string,sku:string,name:string,message:string}
 * @throws Exception On save failure.
 */
function fci_import_simple( $record, $download_images, $dry_run ) {
	$sku  = fci_val( $record, 'sku' );
	$name = fci_val( $record, 'name' );

	$existing_id = wc_get_product_id_by_sku( $sku );
	$is_update   = (bool) $existing_id;

	if ( $dry_run ) {
		return array(
			'status'  => $is_update ? 'updated' : 'created',
			'sku'     => $sku,
			'name'    => $name,
			'message' => $is_update ? 'Would update simple product #' . $existing_id : 'Would create simple product.',
		);
	}

	$product = $existing_id ? wc_get_product( $existing_id ) : null;
	if ( ! $product || ! $product->is_type( 'simple' ) ) {
		$product = new WC_Product_Simple();
	}

	$product->set_sku( $sku );
	fci_apply_shared_fields( $product, $record, $name );

	// Pricing.
	$regular = fci_val( $record, 'regular price' );
	if ( '' !== $regular ) {
		$product->set_regular_price( wc_format_decimal( $regular ) );
	}
	$sale = fci_val( $record, 'sale price' );
	$product->set_sale_price( '' !== $sale ? wc_format_decimal( $sale ) : '' );

	fci_apply_stock_and_dimensions( $product, $record );

	$product_id = $product->save();
	if ( ! $product_id ) {
		throw new Exception( 'Failed to save product.' );
	}

	$message  = ( $is_update ? 'Updated simple product #' : 'Created simple product #' ) . $product_id;
	$message .= fci_finalize_product( $product_id, $record, $download_images );

	return array(
		'status'  => $is_update ? 'updated' : 'created',
		'sku'     => $sku,
		'name'    => $name,
		'message' => $message,
	);
}

/**
 * Import a group of ml variants as one variable product with a Size dropdown.
 *
 * @param array $records Variant records (one per size).
 * @return array{status:string,sku:string,name:string,message:string}
 * @throws Exception On save failure.
 */
function fci_import_variable( $records, $download_images, $dry_run ) {
	$first     = $records[0];
	$base_name = $first['_base_name'];

	// Order variants by numeric ml, ascending.
	usort( $records, static function ( $a, $b ) {
		return (float) $a['_size'] <=> (float) $b['_size'];
	} );

	$sizes = array();
	foreach ( $records as $rec ) {
		$sizes[] = $rec['_size'];
	}

	// The parent has no SKU of its own (each size's SKU differs), so match an
	// existing variable product by looking up any of its variation SKUs.
	$existing_id = 0;
	foreach ( $records as $rec ) {
		$vsku = fci_val( $rec, 'sku' );
		$vid  = '' !== $vsku ? wc_get_product_id_by_sku( $vsku ) : 0;
		if ( $vid ) {
			$vp = wc_get_product( $vid );
			if ( $vp && $vp->is_type( 'variation' ) ) {
				$existing_id = $vp->get_parent_id();
				break;
			}
		}
	}
	$is_update = (bool) $existing_id;

	if ( $dry_run ) {
		return array(
			'status'  => $is_update ? 'updated' : 'created',
			'sku'     => '',
			'name'    => $base_name,
			'message' => ( $is_update ? 'Would update variable product #' . $existing_id : 'Would create variable product' ) . ' with sizes: ' . implode( ', ', $sizes ),
		);
	}

	$product = $existing_id ? wc_get_product( $existing_id ) : null;
	if ( ! $product || ! $product->is_type( 'variable' ) ) {
		$product = new WC_Product_Variable();
	}

	fci_apply_shared_fields( $product, $first, $base_name );

	// The "Size" attribute drives the front-end dropdown.
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'Size' );
	$attribute->set_options( $sizes );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	$product->set_attributes( array( $attribute ) );

	$product_id = $product->save();
	if ( ! $product_id ) {
		throw new Exception( 'Failed to save variable product.' );
	}

	// Create/update one variation per size (matched by variation SKU).
	foreach ( $records as $rec ) {
		$var_sku   = fci_val( $rec, 'sku' );
		$var_exist = $var_sku ? wc_get_product_id_by_sku( $var_sku ) : 0;
		$variation = $var_exist ? wc_get_product( $var_exist ) : null;
		if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
			$variation = new WC_Product_Variation();
		}

		$variation->set_parent_id( $product_id );
		$variation->set_attributes( array( 'size' => $rec['_size'] ) );
		if ( '' !== $var_sku ) {
			$variation->set_sku( $var_sku );
		}

		$regular = fci_val( $rec, 'regular price' );
		if ( '' !== $regular ) {
			$variation->set_regular_price( wc_format_decimal( $regular ) );
		}
		$sale = fci_val( $rec, 'sale price' );
		$variation->set_sale_price( '' !== $sale ? wc_format_decimal( $sale ) : '' );

		fci_apply_stock_and_dimensions( $variation, $rec );
		$variation->save();
	}

	// Recalculate parent price range, stock status, etc.
	WC_Product_Variable::sync( $product_id );

	$message  = ( $is_update ? 'Updated variable product #' : 'Created variable product #' ) . $product_id;
	$message .= ' with ' . count( $records ) . ' sizes (' . implode( ', ', $sizes ) . ')';
	$message .= fci_finalize_product( $product_id, $first, $download_images );

	return array(
		'status'  => $is_update ? 'updated' : 'created',
		'sku'     => '',
		'name'    => $base_name,
		'message' => $message,
	);
}

/**
 * Apply fields shared by simple products and variable-product parents.
 *
 * @param WC_Product $product Product being built.
 * @param array      $record  Source record.
 * @param string     $name    Product name to set.
 */
function fci_apply_shared_fields( $product, $record, $name ) {
	if ( '' !== $name ) {
		$product->set_name( $name );
	}

	$published = fci_val( $record, 'published' );
	$product->set_status( ( '1' === $published || '' === $published ) ? 'publish' : 'draft' );

	$product->set_featured( '1' === fci_val( $record, 'is featured?' ) );

	$visibility = strtolower( fci_val( $record, 'visibility in catalog' ) );
	$product->set_catalog_visibility( in_array( $visibility, array( 'visible', 'catalog', 'search', 'hidden' ), true ) ? $visibility : 'visible' );

	if ( '' !== fci_val( $record, 'short description' ) ) {
		$product->set_short_description( fci_val( $record, 'short description' ) );
	}
	if ( '' !== fci_val( $record, 'description' ) ) {
		$product->set_description( fci_val( $record, 'description' ) );
	}

	$category_ids = fci_resolve_terms( fci_val( $record, 'categories' ), 'product_cat' );
	if ( $category_ids ) {
		$product->set_category_ids( $category_ids );
	}
	$tag_ids = fci_resolve_terms( fci_val( $record, 'tags' ), 'product_tag' );
	if ( $tag_ids ) {
		$product->set_tag_ids( $tag_ids );
	}
}

/**
 * Apply stock + shipping dimensions to a product or variation.
 *
 * @param WC_Product $product Product or variation.
 * @param array      $record  Source record.
 */
function fci_apply_stock_and_dimensions( $product, $record ) {
	$stock = fci_val( $record, 'stock' );
	if ( '' !== $stock ) {
		$product->set_manage_stock( true );
		$product->set_stock_quantity( (float) $stock );
	}
	$in_stock = fci_val( $record, 'in stock?' );
	$product->set_stock_status( ( '0' === $in_stock ) ? 'outofstock' : 'instock' );

	if ( '' !== fci_val( $record, 'weight (kg)' ) ) {
		$product->set_weight( wc_format_decimal( fci_val( $record, 'weight (kg)' ) ) );
	}
	if ( '' !== fci_val( $record, 'length (cm)' ) ) {
		$product->set_length( wc_format_decimal( fci_val( $record, 'length (cm)' ) ) );
	}
	if ( '' !== fci_val( $record, 'width (cm)' ) ) {
		$product->set_width( wc_format_decimal( fci_val( $record, 'width (cm)' ) ) );
	}
	if ( '' !== fci_val( $record, 'height (cm)' ) ) {
		$product->set_height( wc_format_decimal( fci_val( $record, 'height (cm)' ) ) );
	}
}

/**
 * Assign brands and images after a product has an ID. Returns a note string.
 *
 * @param int   $product_id      Saved product ID.
 * @param array $record          Source record.
 * @param bool  $download_images Whether to sideload image URLs.
 * @return string Human-readable note (may be empty).
 */
function fci_finalize_product( $product_id, $record, $download_images ) {
	$note = '';

	// Brands (native product_brand taxonomy in WC 9.6+).
	$brands = fci_val( $record, 'brands' );
	if ( '' !== $brands && taxonomy_exists( 'product_brand' ) ) {
		$brand_ids = fci_resolve_terms( $brands, 'product_brand' );
		if ( $brand_ids ) {
			wp_set_object_terms( $product_id, $brand_ids, 'product_brand' );
		}
	}

	// Images.
	$images = fci_val( $record, 'images' );
	if ( '' !== $images && $download_images ) {
		$img_result = fci_attach_images( $product_id, $images );
		if ( $img_result ) {
			$note = ' (' . $img_result . ')';
		}
	}

	return $note;
}

/**
 * Resolve a comma-separated list of term names to term IDs, creating any that
 * don't exist. Supports "Parent > Child" hierarchy for categories.
 *
 * @param string $raw      Comma-separated term names.
 * @param string $taxonomy Taxonomy slug.
 * @return int[] Term IDs.
 */
function fci_resolve_terms( $raw, $taxonomy ) {
	$raw = trim( $raw );
	if ( '' === $raw ) {
		return array();
	}

	$names = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
	$ids   = array();

	foreach ( $names as $name ) {
		// Handle "Parent > Child" hierarchies for hierarchical taxonomies.
		$parts  = array_map( 'trim', explode( '>', $name ) );
		$parent = 0;

		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}
			$term = get_term_by( 'name', $part, $taxonomy );
			if ( $term && ( ! is_taxonomy_hierarchical( $taxonomy ) || (int) $term->parent === (int) $parent ) ) {
				$term_id = (int) $term->term_id;
			} else {
				$created = wp_insert_term( $part, $taxonomy, array( 'parent' => $parent ) );
				if ( is_wp_error( $created ) ) {
					// Term may exist already; try to fetch it.
					$existing = get_term_by( 'name', $part, $taxonomy );
					if ( ! $existing ) {
						continue 2;
					}
					$term_id = (int) $existing->term_id;
				} else {
					$term_id = (int) $created['term_id'];
				}
			}
			$parent = $term_id;
		}

		if ( ! empty( $term_id ) ) {
			$ids[] = $term_id;
		}
	}

	return array_values( array_unique( $ids ) );
}

/**
 * Sideload one or more image URLs and attach them to a product.
 * The first image becomes the featured image; the rest become the gallery.
 *
 * @param int    $product_id Product ID.
 * @param string $raw        Comma-separated image URLs.
 * @return string Human-readable result note.
 */
function fci_attach_images( $product_id, $raw ) {
	$urls = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
	if ( ! $urls ) {
		return '';
	}

	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$attachment_ids = array();
	$failures       = 0;

	foreach ( $urls as $url ) {
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			$failures++;
			continue;
		}
		$attachment_id = media_sideload_image( $url, $product_id, null, 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			$failures++;
			continue;
		}
		$attachment_ids[] = (int) $attachment_id;
	}

	if ( ! $attachment_ids ) {
		return $failures ? sprintf( '%d image(s) failed', $failures ) : '';
	}

	$featured = array_shift( $attachment_ids );
	set_post_thumbnail( $product_id, $featured );

	if ( $attachment_ids ) {
		$product = wc_get_product( $product_id );
		if ( $product ) {
			$product->set_gallery_image_ids( $attachment_ids );
			$product->save();
		}
	}

	$note = sprintf( '%d image(s) attached', count( $attachment_ids ) + 1 );
	if ( $failures ) {
		$note .= sprintf( ', %d failed', $failures );
	}
	return $note;
}
