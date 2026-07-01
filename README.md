# Fragrance CSV Importer

A WordPress plugin that imports WooCommerce products from a CSV file (standard
WooCommerce export column format).

## Features

- **Admin UI** under **WooCommerce → CSV Importer** to upload a CSV and run the import.
- **Matches by SKU** — existing products are updated, new ones created (safe to re-run).
- **Skips duplicate SKUs** within the file.
- **Groups ml sizes into one variable product** with a **Size** dropdown. Variants
  of the same fragrance are detected by their base name (name with the ml removed),
  so different per-size SKUs still merge correctly.
- **Auto-creates** missing categories, tags, and native WooCommerce brands
  (`product_brand`). Categories support `Parent > Child` hierarchy.
- **Sideloads images** from URLs into the media library (first = featured, rest = gallery).
- **Dry-run mode** to preview results without saving.
- **Concurrency lock** to prevent two imports running at once (which can create duplicates).

## CSV columns

`Type, SKU, Name, Published, Is featured?, Visibility in catalog, Short description,
Description, In stock?, Stock, Weight (kg), Length (cm), Width (cm), Height (cm),
Regular price, Sale price, Categories, Tags, Brands, Images`

Column names are matched case-insensitively from the header row.

## Usage

1. Copy the `fragrance-csv-importer` folder into `wp-content/plugins/`.
2. Activate **Fragrance CSV Importer** in **Plugins**.
3. Go to **WooCommerce → CSV Importer**, upload your CSV, and import
   (tick **Dry run** first to preview).

## Requirements

- WordPress with WooCommerce 6.0+ (brands require WooCommerce 9.6+).
- PHP 7.4+.
