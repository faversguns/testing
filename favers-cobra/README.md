# Favers Cobra Importer

WordPress 6.5+, PHP 7.4+, and WooCommerce required. Independent integration; not endorsed by Cobra. No public-site scraping, supplier account, resale permission, product imagery or live inventory feed is included.

## Bluehost installation

1. Back up WordPress and test on Bluehost staging first.
2. Install and configure WooCommerce, your currency, tax treatment, shipping and payment gateway.
3. Upload `dist/favers-cobra.zip` through **Plugins → Add New → Upload Plugin**, then activate.
4. Obtain your authorized Cobra supplier catalog. Convert it to UTF-8 CSV with exactly `sku,name,price,stock,description` headers. See `example.csv` for format only; it is not real product data. Prices use a decimal point, no currency sign; stock is a nonnegative integer. Quote descriptions containing commas. Maximum 200 products per file, 1 MB. Split larger catalogs. Variations (shaft, loft, handedness), images and automatic synchronization are not supported by this version.
5. Open **WooCommerce → Cobra Import**. Upload and preview first, then upload again with **Apply import** checked.
6. Review draft products, add authorized images, shipping weights/dimensions and any other required attributes, then publish. Existing importer-owned products retain their status when updated. Ensure stock is refreshed from your supplier before accepting orders.
7. The first applied import creates a published `/cobra` catalog page if that path is unused and your account can publish pages. It uses `[products category="cobra" paginate="true" limit="12"]`. Existing pages are never overwritten; add that shortcode yourself. Set WordPress permalinks as needed for `/cobra`. Products appear only once published and visible in the catalog.
8. Test add-to-cart and checkout in your payment gateway's test mode before going live. Configure actual fulfillment with your supplier separately.

## Update behavior

Supplier SKU `ABC` maps to WooCommerce SKU `cobra-ABC`. Importer ownership metadata prevents overwriting unrelated products. Preflight validates all rows and detects SKU conflicts before writes. Reimporting updates title, description, regular price, stock and category membership; removes sale pricing and disables backorders. Products absent from the file are unchanged, not removed or marked unavailable. New products start as drafts. Existing published products receive changes immediately: preview on staging first.

Writes are not a database transaction. A runtime error can leave an explicitly reported partial import; fix the issue and reimport. Imports are serialized by an option lock. After a crashed PHP request, an administrator may need to remove the `favers_cobra_import_lock` option with WP-CLI (`wp option delete favers_cobra_import_lock`), after confirming no import is running. The plugin never deletes products or pages when deactivated/uninstalled.

## Development

Run `php tests/run.php` for parser and importer regression tests. Package from the repository root with `zip -r dist/favers-cobra.zip favers-cobra -x '*/tests/*'`. No dependencies or build step are required. Full WordPress/WooCommerce integration and Bluehost checkout verification must be performed on staging; isolated tests do not establish production readiness.
