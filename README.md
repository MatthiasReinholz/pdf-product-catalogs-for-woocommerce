# PDF Product Catalogs for WooCommerce

Standalone WordPress plugin repository for secure PDF catalog generation from WooCommerce product data.

## Scope

- Adds `Products > PDF Product Catalogs` in wp-admin.
- Generates standard or client-specific product catalog PDFs.
- Stores generated files in protected per-site uploads storage.
- Tracks generation history in a dedicated custom database table.
- Packages and releases the plugin through `wp-plugin-base`.

## Admin Flow

1. Open `Products > PDF Product Catalogs`.
2. Configure the default PDF header/footer, excluded categories, out-of-stock behavior, and attribute columns.
3. Launch the modal wizard to create a standard or client-specific catalog.
4. Wait for the queued generation to finish.
5. Download the latest or historical PDF from the history table.

## Storage Model

- Global plugin settings are stored in the `ppcfw_settings` option.
- The storage secret lives in `ppcfw_storage_secret`.
- Catalog history records live in the custom table `${prefix}ppcfw_catalogs`.
- Generated PDFs are stored under a protected uploads subtree and served only through authenticated admin download handlers.

## Development Workflow

- Sync foundation-managed files: `bash .wp-plugin-base/scripts/update/sync_child_repo.sh`
- Build a release zip: `bash .wp-plugin-base/scripts/ci/build_zip.sh`
- Lint PHP: `bash .wp-plugin-base/scripts/ci/lint_php.sh`
- Lint JavaScript: `bash .wp-plugin-base/scripts/ci/lint_js.sh`

## Release Model

This repository is a `wp-plugin-base` child project pinned to foundation version `v1.2.1`.

GitHub Releases are the primary distribution mechanism. The WordPress app should consume the release ZIP through its `wp-core-base` managed `github-release` dependency flow once the first release has been published.
