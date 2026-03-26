=== PDF Product Catalogs for WooCommerce ===
Contributors: MatthiasReinholz
Requires Plugins: woocommerce
Requires at least: 6.8
Requires PHP: 8.0
Tested up to: 6.8
Version: 0.0.1
Stable tag: 0.0.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Generate secure, branded PDF product catalogs from WooCommerce product data.

== Description ==

PDF Product Catalogs for WooCommerce adds a dedicated catalog-generation page under `Products > PDF Product Catalogs` in wp-admin.

After activation, go to `wp-admin > Products > PDF Product Catalogs`. The plugin does not add a top-level admin menu, so this submenu page is where you configure defaults, create catalogs, and access the catalog history.

It is built for stores that need:

- standard PDF catalogs based on live WooCommerce data
- client-specific catalogs for sales or B2B communication
- private catalog files that should not be publicly accessible
- a reusable catalog history with admin-only downloads
- one automatically refreshed “main” standard catalog

The plugin guides users through a modal wizard for catalog creation. Standard catalogs skip client-only questions. Client-specific catalogs can include:

- client name in the catalog title
- prices including or excluding tax
- an optional discount percentage applied to displayed prices

Generated PDFs can include:

- store name in the header
- configurable header and footer text
- product images
- product titles
- SKU
- GTIN when available
- selected product attribute columns
- WooCommerce-formatted prices with the correct currency symbol and number format
- out-of-stock labels
- clickable product titles for published, in-stock products

Variable products are rendered as grouped rows in the main table, with one row per visible variation.

== Features ==

- Adds a submenu under WooCommerce Products
- No top-level admin menu
- Requires WooCommerce and fails safely if WooCommerce is missing
- Asynchronous PDF generation
- Standard and client-specific generation flows
- Historical list with download and delete actions
- Optional daily automatic refresh for one highlighted standard catalog
- Automatic refresh only regenerates when relevant catalog data changed
- Encrypted private file storage
- Admin-only downloads with nonce and capability checks
- Batched product loading and smaller embedded images for improved reliability on large catalogs

== Security And Storage ==

Catalog files are not stored as plain public PDFs.

Instead, the plugin:

- stores generated catalog files inside a private uploads subtree
- encrypts stored files at rest
- decrypts them only when an authorized admin downloads them
- serves downloads through authenticated WordPress handlers
- adds `noindex` and `nosniff` response headers on download

Historical files can be deleted again by authorized admins from the history list.

== Automatic Standard Catalog ==

When the daily automatic catalog option is enabled, the plugin:

- schedules a recurring daily refresh check
- immediately queues one refresh check when the setting is turned on
- only generates a new standard catalog when relevant settings or product data changed
- highlights the latest completed automatic standard catalog at the top of the history list

== Large Catalog Reliability ==

The plugin is designed to behave more safely on larger stores.

To reduce memory pressure and failed generation jobs, it:

- loads products in batches
- prefers smaller local thumbnail assets instead of original full-size images
- skips oversized image files that would destabilize PDF rendering
- generates catalogs asynchronously instead of tying work to a normal page request

== Installation ==

1. Upload `pdf-product-catalogs-for-woocommerce.zip` in WordPress.
2. Activate the plugin.
3. Ensure WooCommerce is active.
4. Open `wp-admin > Products > PDF Product Catalogs`.
5. Configure the catalog defaults.
6. Generate your first standard or client-specific catalog.

== Frequently Asked Questions ==

= Does this plugin create public catalog URLs? =

No. Catalog downloads are intended for authorized admin users only.

= What happens if WooCommerce is not installed? =

The plugin stays inert and shows a dependency notice instead of breaking the site.

= Can I keep one always-current default catalog? =

Yes. Enable the daily automatic catalog option in the plugin settings. The plugin will maintain one standard catalog and refresh it only when relevant data changed.

= Can I delete old catalog files? =

Yes. Completed or failed catalog entries can be removed from the history list by authorized admin users.

= Does this work for variable products? =

Yes. Variable products are shown as grouped rows, with one row per visible variation.

= What happens if WooCommerce is deactivated after catalogs exist? =

The plugin stays inert and does not display its admin page while WooCommerce is inactive. Existing catalog files and history remain intact. Once WooCommerce is reactivated, everything is available again as before.

= What happens to my data when I uninstall this plugin? =

When the plugin is deleted through the WordPress admin, all plugin data is removed. This includes the catalog history table, plugin settings, stored encryption keys, and all generated catalog files. Deactivating the plugin does not remove data.

== Changelog ==

= 0.0.1 =
* Initial public release.
