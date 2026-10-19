# Favers Tour Edge Exotics Shop — 1.1.0

Standalone WordPress/WooCommerce plugin for `favers.shop/touredge`. Requires WordPress 6.5+, PHP 7.4+ and WooCommerce. Runs alongside the Cobra plugin with separate shortcodes, styles, categories, SKU prefixes, metadata, options and locks.

## Complete public catalog snapshot

Checked October 19, 2026: **713 unique available Exotics configurations** from 326 parent products across two public Tour Edge catalog pages. Includes **632 new**, **53 certified pre-owned**, and **28 demo offers**, in separate import groups. Removed 329 duplicate aliases by manufacturer variant ID; conflicting prices fail export instead of silently selecting one.

Scope: publicly available variants whose model title contains **Exotics**, including older models, individual irons, sets, drivers, fairways, hybrids, utility irons, wedges, putters, golf balls, weights, headcovers, bags, gloves and other accessories where listed. Other Tour Edge families such as Hot Launch are excluded. Unavailable variants are excluded. A public retail available flag does not establish dealer supply or a fulfillment arrangement. New, pre-owned and demo condition remains explicit in the source title and description; no extra warranty or fulfillment promise is inferred.

Each exact configuration is a separate simple WooCommerce product. Public USD prices are a snapshot, not dealer costs or an automatic price/inventory feed. The source manifest `data/exotics-sources.json` records variant IDs, manufacturer SKUs, exact source URLs, aliases, prices, condition, model and timestamp. No user-provided store exports are bundled or published.

## Install and display

1. Back up WordPress and test on Bluehost staging first. Install/configure WooCommerce, USD store currency, tax treatment, shipping, Cart/Checkout pages and a payment gateway.
2. Upload **favers-touredge-1.1.0.zip** under **Plugins → Add New → Upload Plugin**. Choose **Replace current with uploaded** if an older version is installed. Activate. Keep the Cobra plugin active for your Cobra shop.
3. Activation creates a published **Tour Edge** page, slug **touredge**, if that page does not exist and your account can publish pages. Otherwise create/edit that page and add a Shortcode block containing `[favers_touredge_shop]`. You can also put this shortcode on your existing `/tour-edge-exotics/` page. Existing custom page content is preserved.
4. A full-width page template gives more room. Clear Bluehost's cache after updating. Theme headers/footers remain active.

The storefront includes mobile layouts, club-type browsing, product-title search, price sorting, cart links and an informative empty state. Imported subcategories distinguish drivers, fairways, hybrids, irons, wedges, putters, utility irons, balls and accessories. Existing products from older plugin versions need reimporting with Create missing unchecked to receive those type categories.

## Import the available products

Open **WooCommerce → Tour Edge Import → Product source**. No fetch or CSV download is necessary for the included snapshot.

- Choose the four **Exotics NEW** batches, one at a time: **200, 200, 200, 32**.
- Preview each batch, then check **Apply import** and submit again.
- **Create missing variant SKUs only** is selected by default. It skips existing matching SKUs, leaving them unchanged. New products are drafts for review.
- **Special order** is selected by default. Zero local stock becomes WooCommerce **Allow, but notify customer**, with a special-order label. Confirm supply and delivery timing before publishing; no order is sent to Tour Edge automatically.
- Import the separate **CERTIFIED PRE-OWNED** (53) and **DEMO OFFERS** (28) batches only if you intend and are able to sell those conditions.
- Review descriptions, exact handedness/loft/shaft/set composition, taxes, shipping and images, then publish selected products. Test checkout in gateway test mode before taking orders. Cart/Checkout Blocks may present availability differently from classic templates and need staging validation.

SKU `TE-<variant ID>` becomes `touredge-TE-<variant ID>`. Your older manually named products may describe the same models without exact variant IDs/specifications; those cannot be safely merged automatically. Review them before publishing new configuration listings to avoid duplicate storefront offerings.

Uncheck Create missing only when you intend a reimport to update existing importer-owned simple products: title, description, regular price, stock, special-order settings, image source metadata and categories. Reimports clear sale pricing, preserve existing publication status and leave unrelated products alone. Absent products are unchanged; disable ordering manually when your supplier can no longer fulfill them. Writes are not transactional; reported partial imports can be retried by SKU.

## Photos

Every bundled row includes a manufacturer photo URL. After import, click **Add missing product photos** and keep the page open. It processes one product per request and reuses shared attachments in the Media Library. Existing featured images, prices, stock and publication status are preserved. Stop/resume and repeat runs are supported.

URLs use Tour Edge's first-party `/cdn/shop/files/` or `/cdn/shop/products/` paths. Images are model/variant catalog photos, not guaranteed photographs of each individual shaft or club configuration. Review them before publishing and use imagery under your applicable supplier/media terms. Bluehost needs outbound HTTPS to `www.touredge.com`, writable uploads and a working image editor. Requests retain WordPress safe-HTTP, TLS and image-type checks, with bounded download time/size. Media upload and WooCommerce management capabilities plus a valid nonce are required.

## Refresh or supply your own data

**Fetch Tour Edge catalog** traverses up to three public pages with repeated-page detection and a time budget. It verifies USD currency and normalizes all available Exotics types. Failed/incomplete traversal preserves the previous snapshot. It creates selectable batches, not products. This is a manual snapshot refresh; there is no scheduled synchronization.

Alternatively upload a UTF-8 supplier CSV with headers `sku,name,price,stock,description`; optional columns are `image_url,product_type`. Maximum 200 rows / 1 MB. Price: positive decimal without a currency symbol, in store currency; stock: nonnegative integer. Quote fields containing commas. Product types: drivers, fairways, hybrids, irons, wedges, putters, utilities, balls, accessories, other. Image URLs must use the supported Tour Edge first-party paths, or add your own product photos manually in WordPress.

Photo batches and imports share `favers_touredge_import_lock`. If a PHP request crashes, an administrator can remove that option after confirming no job is running. Deactivation deletes no products, pages or attachments.

## Development and validation

Run `php favers-touredge/tests/admin.php` for isolated importer, snapshot, admin, coexistence and bundle checks; `php favers-touredge/tests/photos.php` for isolated media tests. Doubles do not establish live WordPress or checkout readiness.

For design checks, generate `dist/touredge-preview.html` with `php favers-touredge/tests/render.php`, serve the repository on port 8000, then run `node favers-touredge/tests/browser.cjs` with Playwright and Chromium available. `tests/browser-photos.cjs` checks automatic photo batches, failure handling and stopping with mocked AJAX. Source snapshot regeneration: `python3 favers-touredge/tools/export-exotics.py`; review output before packaging. Package the plugin directory excluding tests/tools; the ZIP must contain the `favers-touredge/` root directory.
