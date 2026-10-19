# Favers Cobra Importer

## Version 1.2: current-price snapshots and special orders

The import form now has a **Special order** checkbox, selected by default for this store's requested workflow. When checked, imported products use WooCommerce backorders **Allow, but notify customer**. A zero-stock product is saved as `onbackorder`, not falsely marked as local inventory. Catalog and product availability messages say **Special order**, and the classic cart notification explains it is not held in stock. The classic checkout also stores this label in the order line. Test WooCommerce Cart/Checkout Blocks and your theme on staging: they may present availability differently. No fulfillment request is sent to Cobra and no delivery promise is invented.

Upload the 1.2.0 ZIP using **Replace current with uploaded**. In **WooCommerce → Cobra Import**, choose **Included Cobra drivers** (111 configurations) or **Included Cobra iron sets** (195 configurations) under **Product source**; no CSV download is needed. Preview, then submit with **Apply import** and **Special order** checked. Repeat for the other catalog. The included prices require store currency USD. Alternatively choose **Upload my own CSV**. New products stay drafts: review your fulfillment arrangements, shipping, tax settings, photos and specifications, then publish. With **Special order** unchecked, zero stock prevents purchases. Reimporting changes this setting only for rows in that file.

The dated CSVs use public Cobra US retail prices, in **USD**, observed at the timestamp recorded in `dist/cobra-price-sources-YYYY-MM-DD.json`. They are price snapshots, not dealer prices or live availability feeds. Set your WooCommerce store currency to USD before using these prices. They are appropriate only if these are the retail prices you intend to charge; supplier cost, tax treatment and margins are separate. Verify fulfillment with your supplier before publishing. The public site's `available` flag does not establish availability to your dealer account.

Each fully identified hand/loft/shaft/set configuration is a separate simple product with a stable variant-based SKU. Custom-shaft variants and retail-unavailable configurations are excluded. Iron files contain sets, not single irons; this avoids applying a one-iron price to a full set. Titles and descriptions record the configuration and manufacturer SKU. No marketing descriptions or manufacturer images are copied. Tour-length products represented only by custom-shaft entries are excluded. These are selected standard configurations, not a claim to reproduce every Cobra product.

To refresh the price snapshot, run `python3 favers-cobra/tools/export-cobra.py` from the repository root. It verifies USD currency and HTTPS, validates prices, splits at 200 rows, and writes CSVs and a source manifest under `dist/`. Review changes before reimporting: updates to already published imported products apply immediately. Variants absent from a newer snapshot remain unchanged in WooCommerce; manually unpublish or disable ordering for configurations your supplier can no longer fulfill.

## Version 1.1: drivers and irons storefront

The plugin now includes a responsive green-and-cream Cobra shop with original club illustrations, collection search, driver/iron filters, price sorting, a buying guide, cart access and helpful empty states. Products, prices, images and stock come from WooCommerce; no sample products or invented offers appear on the live page. The illustration is decorative, not an actual product photograph. Filters use product-title words `driver` and `iron`, so name your products accordingly. Search also matches product titles. Handedness, shaft and loft filters are not included because the supplier CSV does not provide those fields.

To update the installed plugin: upload the new ZIP in **Plugins → Add New → Upload Plugin**, then choose **Replace current with uploaded**. Existing products remain in place. Back up and test on staging first.

Edit **Pages → Cobra** and add one **Shortcode block** containing `[favers_cobra_shop]`. Use a full-width page template if your theme offers one, and disable the theme's page title on this page if you want to avoid a second Cobra heading. The plugin automatically renders the new design for an empty `/cobra` page or the exact original generated products shortcode. It does not overwrite custom page content. Clear Bluehost/WordPress caches after updating. The theme's existing header and footer remain active.

If there is no Cobra page yet, create and publish one with slug `cobra` and the shortcode above; you do not have to import products to use the design. An applied import can also create the page. New imported products remain drafts until reviewed and published. The collection shows WooCommerce's standard product cards and checkout links. Configure your WooCommerce Cart and Checkout pages. For a useful catalog, add actual authorized product photographs and complete club specifications to each product.

WordPress 6.5+, PHP 7.4+, and WooCommerce required. Independent integration; not endorsed by Cobra. No public-site scraping, supplier account, resale permission, product imagery or live inventory feed is included.

## Bluehost installation

1. Back up WordPress and test on Bluehost staging first.
2. Install and configure WooCommerce, your currency, tax treatment, shipping and payment gateway.
3. Upload `dist/favers-cobra.zip` through **Plugins → Add New → Upload Plugin**, then activate.
4. Obtain your authorized Cobra supplier catalog. Convert it to UTF-8 CSV with exactly `sku,name,price,stock,description` headers. See `example.csv` for format only; it is not real product data. Prices use a decimal point, no currency sign; stock is a nonnegative integer. Quote descriptions containing commas. Maximum 200 products per file, 1 MB. Split larger catalogs. Variations (shaft, loft, handedness), images and automatic synchronization are not supported by this version.
5. Open **WooCommerce → Cobra Import**. Upload and preview first, then upload again with **Apply import** checked.
6. Review draft products, add authorized images, shipping weights/dimensions and any other required attributes, then publish. Existing importer-owned products retain their status when updated. Ensure stock is refreshed from your supplier before accepting orders.
7. The first applied import creates a published `/cobra` catalog page if that path is unused and your account can publish pages. It uses `[favers_cobra_shop]`. Existing pages are never overwritten; add that shortcode yourself. Set WordPress permalinks as needed for `/cobra`. Products appear only once published and visible in the catalog.
8. Test add-to-cart and checkout in your payment gateway's test mode before going live. Configure actual fulfillment with your supplier separately.

## Update behavior

Supplier SKU `ABC` maps to WooCommerce SKU `cobra-ABC`. Importer ownership metadata prevents overwriting unrelated products. Preflight validates all rows and detects SKU conflicts before writes. Reimporting updates title, description, regular price, stock and category membership; removes sale pricing and sets backorder behavior from the Special order checkbox. Products absent from the file are unchanged, not removed or marked unavailable. New products start as drafts. Existing published products receive changes immediately: preview on staging first.

Writes are not a database transaction. A runtime error can leave an explicitly reported partial import; fix the issue and reimport. Imports are serialized by an option lock. After a crashed PHP request, an administrator may need to remove the `favers_cobra_import_lock` option with WP-CLI (`wp option delete favers_cobra_import_lock`), after confirming no import is running. The plugin never deletes products or pages when deactivated/uninstalled.

## Development

Run `php tests/run.php` for parser and importer regression tests, or `php tests/admin.php` for those plus built-in catalog selection, currency checks and import-lock tests. Package from the repository root with `zip -r dist/favers-cobra.zip favers-cobra -x '*/tests/*' '*/tools/*'`. No dependencies or build step are required. Full WordPress/WooCommerce integration and Bluehost checkout verification must be performed on staging; isolated tests do not establish production readiness.

For standalone design checks, from the repository root run `php favers-cobra/tests/render.php > dist/preview.html`, serve the root with Python on port 8000, and run `node favers-cobra/tests/browser.cjs` with Playwright available. Set `CHROMIUM_PATH` if Chromium is not at `/usr/bin/chromium`. The fixture intentionally uses a non-live empty catalog and cannot verify real WooCommerce queries or checkout. It verifies desktop/mobile overflow, illustration loading, cart navigation target, and filter form submission, and writes screenshots into `dist/`.
