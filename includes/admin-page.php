<?php
/**
 * Admin page: registers the menu item and renders the upload form and results.
 *
 * @package Fragrance_CSV_Importer
 */

defined( 'ABSPATH' ) || exit;

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
