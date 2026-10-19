# Favers Tour Edge Shop

A standalone WordPress/WooCommerce plugin for `favers.shop/touredge`. Runs alongside the Favers Cobra plugin, with separate shortcodes, styles, category, SKU prefix, metadata, locks, options and scripts. Requires WordPress 6.5+, PHP 7.4+ and WooCommerce.

## Install and display

1. Back up WordPress and test on Bluehost staging first.
2. Upload `dist/favers-touredge-1.0.0.zip` in **Plugins → Add New → Upload Plugin** and activate it. Keep the Cobra plugin active for your existing Cobra shop.
3. Activation creates a published page titled **Tour Edge** with slug **touredge** and shortcode `[favers_touredge_shop]`, if no page exists there and your account can publish pages. If the page is missing, create it manually with that shortcode. A successful product import can also create this page if the path is free. Existing custom page content is never overwritten.
4. Choose a full-width page template where available, and clear Bluehost's cache. The design includes a blue-and-white introduction, driver/iron browsing, product-title search, price sorting, cart access and an empty state. Theme headers and footers remain active.

## Import real products and prices

Open **WooCommerce → Tour Edge Import**. **Fetch Tour Edge catalog** attempts to read the public JSON catalog and USD currency from `https://www.touredge.com/`. It validates both before saving any snapshot. This adapter requires compatible public catalog endpoints; website access and compatibility have not yet been verified from the cloud environment because its network policy blocks Tour Edge. No Tour Edge products, prices or photos are bundled or fabricated.

If fetching succeeds on Bluehost, choose a dated snapshot batch in **Product source**, preview it, then select **Apply import** to create draft products. Snapshot batches have at most 200 configurations. The store must use USD for fetched prices. Only publicly available standard configurations with driver/iron product titles are included; headcovers, custom configurations and other club types are excluded. Different configurations are separate simple products. Single irons retain their exact configuration and price; review set composition carefully. The fetch fails rather than silently truncating a catalog of 250 or more parent products. Use supplier CSVs if the public data is unavailable, too large, or incompatible.

Alternatively upload an authorized UTF-8 supplier CSV with the exact headers `sku,name,price,stock,description` and optional `image_url`. Prices must be positive, use a decimal point and be in your store currency; stock must be a nonnegative integer. Quote fields containing commas. Maximum 200 rows / 1 MB. No sample data represents actual inventory. Existing Cobra products are never updated by this importer.

Special orders are selected by default: zero local stock becomes WooCommerce **Allow, but notify customer**, with a special-order label. Confirm supplier fulfillment and communicate delivery timing before publishing. Uncheck Special order to block purchases at zero stock. The plugin sends no fulfillment request and supplies no dealer account or shipping promise.

Review draft products, configure taxes, shipping, imagery, club specifications and payment gateways, then publish. Category must be **Tour Edge** (slug `touredge`) and catalog visibility must allow shop display. Test checkout in your gateway's test mode before taking orders. Cart/Checkout Blocks may present availability differently from classic templates and need staging validation.

## Photos and updates

Fetched photos, when supported, use Tour Edge's first-party `/cdn/shop/files/` or `/cdn/shop/products/` URLs. CSVs may provide the same URLs in `image_url`. Click **Add missing product photos** and keep the page open. Downloads run one product per request; shared image attachments are reused. Existing photos, stock, prices and publication status are preserved. Stop/resume is safe. If the image service or URL format is incompatible, use **Products → Edit → Product image** to upload your authorized supplier photos.

The WordPress host needs outbound HTTPS to `www.touredge.com`, a writable Media Library and a working image editor. Downloads retain WordPress safe-HTTP, TLS and file-type validation and are bounded by time and size. Using manufacturer photos remains subject to your supplier/media terms.

SKU `ABC` maps to `touredge-ABC`; fetched variant IDs use `touredge-TE-<id>`. Only importer-owned simple products can be updated. Reimports change product title, description, regular price, stock, special-order settings and category, clear sale pricing, and retain existing publication status. Products absent from later snapshots remain unchanged: manually disable ordering for items your supplier can no longer fulfill. No scheduled price or inventory synchronization runs. Writes are not transactional; reported partial imports can be retried by SKU.

Photo batches and imports share `favers_touredge_import_lock`. If a PHP request crashes and leaves the lock behind, an administrator can remove that option after confirming no job is running. Deactivation does not delete products, pages, options or attachments.

## Validation

Run `php favers-touredge/tests/run.php` from the repository root for isolated importer and storefront regression checks. Tests use WordPress/WooCommerce doubles and do not establish live WordPress, remote-catalog or checkout readiness. Standalone design preview: generate `dist/touredge-preview.html` with `php favers-touredge/tests/render.php`, serve the repository on port 8000, then run `node favers-touredge/tests/browser.cjs` with Playwright and Chromium available. `tests/browser-photos.cjs` verifies photo batch UI behavior with mocked AJAX responses.
