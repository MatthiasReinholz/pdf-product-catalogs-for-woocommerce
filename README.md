# PDF Product Catalogs for WooCommerce

Standalone WordPress plugin repository for generating secure PDF product catalogs from WooCommerce data.

## What This Plugin Does

This plugin adds a catalog-generation workspace under `Products > PDF Product Catalogs` in WooCommerce admin.

It is designed for stores that need downloadable product catalogs for internal sales work, B2B communication, client-specific quoting support, or regularly refreshed “current catalog” PDFs without exposing those files publicly on the web.

The plugin can generate:

- standard product catalogs for general use
- client-specific product catalogs with a client name in the title
- catalogs with prices including tax
- catalogs with prices excluding tax
- catalogs with an optional client-specific discount applied

It also keeps a private history of previously generated catalogs so authorized admin users can download or delete older versions later.

## Who This Is For

This plugin is useful when you need one or more of the following:

- a printable or shareable product catalog based on live WooCommerce data
- a repeatable internal workflow for sales teams
- client-specific catalog snapshots that should not be public
- one always-current “main” catalog that refreshes automatically when product data changes
- stronger storage controls than putting static PDFs into the public uploads directory

## Main Features

- Adds a submenu under WooCommerce Products called `PDF Product Catalogs`
- Keeps the plugin out of the top-level admin navigation
- Fails safely when WooCommerce is not active
- Declares WooCommerce as a required plugin dependency
- Generates catalogs asynchronously so admins do not have to wait on a full page load
- Supports standard and client-specific generation flows
- Skips client-specific wizard steps when a standard catalog is being generated
- Stores generation history in a dedicated custom database table
- Lets authorized admins download or delete historical catalogs
- Supports an optional daily automatic refresh for one standard “main” catalog
- Regenerates that automatic catalog only when relevant product data or catalog settings changed
- Stores generated files as encrypted private blobs instead of directly downloadable public PDFs
- Migrates legacy plaintext stored files forward in background batches
- Uses batched product loading and resized image sources to stay more reliable on large catalogs

## What The PDF Contains

The generated PDF is built from WooCommerce product data and current plugin settings.

By default, the catalog can include:

- site title in the PDF header
- configurable header and footer text
- generation date
- pricing mode label
- optional client discount label
- product image
- product title
- SKU
- GTIN when available
- selected product attribute columns
- price formatted with WooCommerce currency and number settings
- out-of-stock labels on affected products
- clickable product titles for published, in-stock products

Variable products are rendered as grouped rows in the main table, with one row per visible variation.

## Admin Workflow

### 1. Configure Defaults

On the plugin page, admins can define:

- PDF header text
- PDF footer text
- whether out-of-stock products should be included by default
- excluded product categories
- up to three product attributes to show as dedicated columns
- whether the daily automatic standard catalog should be enabled

### 2. Generate A Catalog

The modal wizard guides the user through the generation flow:

- choose standard catalog or client-specific catalog
- if client-specific, enter the client name
- choose whether prices should be shown including or excluding tax
- if client-specific, optionally enter a discount percentage

Generation is queued and processed asynchronously.

### 3. Use The History List

The history list shows recent catalogs and their status. From there, authorized admin users can:

- download completed catalogs
- identify the highlighted automatic standard catalog
- remove historical catalogs that are no longer needed

## Automatic Standard Catalog

The plugin can keep one “main” standard catalog up to date automatically.

When enabled:

- a recurring daily Action Scheduler job is maintained
- an immediate refresh check is queued when the setting is turned on
- a new standard catalog is only generated if relevant source data changed
- the latest completed automatic standard catalog is highlighted in the history list

The refresh signature considers catalog-relevant settings and rendered product data so the automatic catalog does not stay stale after meaningful changes.

## Security Model

This plugin is intentionally designed for private admin-only catalog access.

### Access Control

- Catalog generation requires the `manage_woocommerce` capability
- Catalog downloads go through authenticated `admin_post` handlers
- Download and delete actions require WordPress nonces
- Catalog files are never presented as public static URLs in the admin UI

### File Storage

- Generated files are stored inside a private per-site uploads subtree
- Stored catalog files are encrypted at rest
- Files are only decrypted at download time for authorized admin users
- Protection files are written for Apache and IIS environments
- Because files are encrypted at rest, direct web access does not expose readable PDFs even if the web server serves the directory

### Search And Indexing

- Private catalog files are not linked publicly
- Download responses send `X-Robots-Tag: noindex, nofollow`
- Download responses send `X-Content-Type-Options: nosniff`

## Reliability For Large Catalogs

Large catalogs are one of the main operational risks for PDF generation. This plugin reduces that risk by:

- loading products in batches instead of loading the full catalog into memory in one WooCommerce query
- embedding smaller local derivative images instead of original full-size uploads when possible
- skipping oversized image files instead of letting them destabilize rendering
- processing generation asynchronously instead of blocking a normal admin page request
- keeping the generated file as an immutable historical snapshot once complete

This makes the plugin substantially safer for large stores, although PDF generation time will still naturally grow with catalog size.

## Requirements And Boundaries

### Requirements

- WordPress 6.8+
- PHP 8.0+
- WooCommerce installed and active

### Boundaries

- This is a regular plugin, not an MU plugin
- It does not add a top-level admin menu
- It does not provide public or customer-facing catalog links
- It does not expose a sharing portal or expiring external links
- It currently generates PDF output only

## Data Storage

- Global settings: `ppcfw_settings`
- Storage secret: `ppcfw_storage_secret`
- Storage encryption key: `ppcfw_storage_key`
- Catalog history table: `${prefix}ppcfw_catalogs`

The history table stores generation metadata such as status, client details, price mode, discount, settings snapshot, file location, product count, timestamps, and automatic-catalog signature data.

When the plugin is deleted through the WordPress admin, all plugin data is removed: the catalog history table, plugin settings, stored encryption keys, and all generated catalog files. Deactivating the plugin does not remove data.

## WooCommerce Dependency

If WooCommerce is deactivated while the plugin is installed, the plugin stays inert and does not display its admin page. Existing catalog files and history remain intact and become available again once WooCommerce is reactivated.

## Development Workflow

- Sync foundation-managed files: `bash .wp-plugin-base/scripts/update/sync_child_repo.sh`
- Build a release zip: `bash .wp-plugin-base/scripts/ci/build_zip.sh`
- Lint PHP: `bash .wp-plugin-base/scripts/ci/lint_php.sh`
- Lint JavaScript: `bash .wp-plugin-base/scripts/ci/lint_js.sh`

## Release Model

This repository is a `wp-plugin-base` child project pinned to foundation version `v1.2.1`.

GitHub Releases are the primary distribution mechanism. The WordPress app consumes the release ZIP through the `wp-core-base` managed `github-release` dependency flow.
