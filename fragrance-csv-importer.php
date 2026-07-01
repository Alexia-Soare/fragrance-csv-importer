<?php
/**
 * Plugin Name: Fragrance CSV Importer
 * Description: Import WooCommerce products from a CSV file (standard WooCommerce export column format). Skips duplicate SKUs and groups different ml sizes of the same fragrance into one variable product with a Size dropdown.
 * Version:     1.3.0
 * Author:      Fragrance101
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 *
 * This file is a thin bootstrap: it defines configuration and loads the modules
 * under includes/. The code is split by responsibility:
 *   - includes/admin-page.php      Menu item, upload form, results table.
 *   - includes/import-pipeline.php  Read CSV, deduplicate, group ml variants.
 *   - includes/product-writers.php  Create/update simple & variable products.
 *   - includes/field-mappers.php    Copy row values onto WC_Product setters.
 *   - includes/helpers.php          Small shared utilities.
 *
 * @package Fragrance_CSV_Importer
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
 * Module loading
 * ---------------------------------------------------------------------------
 */

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/field-mappers.php';
require_once __DIR__ . '/includes/product-writers.php';
require_once __DIR__ . '/includes/import-pipeline.php';
require_once __DIR__ . '/includes/admin-page.php';
