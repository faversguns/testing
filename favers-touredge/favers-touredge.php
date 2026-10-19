<?php
/**
 * Plugin Name: Favers Tour Edge Importer
 * Description: A drivers and irons storefront with an authorized supplier CSV importer for WooCommerce.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 */
defined('ABSPATH') || exit;
require_once __DIR__ . '/includes/csv.php';
require_once __DIR__ . '/includes/storefront.php';
require_once __DIR__ . '/includes/special-order.php';
require_once __DIR__ . '/includes/photos.php';
require_once __DIR__ . '/includes/catalog.php';

register_activation_hook(__FILE__, function () {
    if (current_user_can('publish_pages') && !get_page_by_path('touredge')) {
        wp_insert_post(array('post_title' => 'Tour Edge', 'post_name' => 'touredge', 'post_type' => 'page',
            'post_status' => 'publish', 'post_content' => '<!-- wp:shortcode -->[favers_touredge_shop]<!-- /wp:shortcode -->'), true);
    }
});

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Tour Edge Import', 'Tour Edge Import', 'manage_woocommerce', 'favers-touredge', 'favers_touredge_admin');
});

function favers_touredge_admin() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    echo '<div class="wrap"><h1>Favers Tour Edge Import</h1>';
    echo '<p>Storefront: add a Shortcode block containing <code>[favers_touredge_shop]</code> to your Tour Edge page. Empty /touredge pages and the original generated catalog automatically use the new design. Custom page content is preserved.</p>';
    if (!class_exists('WooCommerce')) {
        echo '<p>Install and activate WooCommerce first.</p></div>';
        return;
    }
    if (isset($_POST['favers_touredge_fetch'])) {
        check_admin_referer('favers_touredge_fetch');
        try {
            $message = favers_touredge_fetch_catalog();
            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        } catch (Throwable $error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error->getMessage()) . '</p></div>';
        }
    }
    echo '<h2>Tour Edge product source</h2><p>Fetch a current public USD price snapshot directly from www.touredge.com if its public catalog is available. This reads the manufacturer catalog only; it does not publish products or confirm supplier fulfillment. If the site does not expose compatible data, upload an authorized supplier CSV below.</p><form method="post">';
    wp_nonce_field('favers_touredge_fetch');
    echo '<input type="hidden" name="favers_touredge_fetch" value="1">';
    submit_button('Fetch Tour Edge catalog');
    echo '</form><h2>Preview and import</h2>';
    if (isset($_POST['favers_touredge_import'])) {
        check_admin_referer('favers_touredge_import');
        $locked = false;
        try {
            // Atomic option insertion prevents concurrent imports and SKU races.
            $locked = add_option('favers_touredge_import_lock', time(), '', false);
            if (!$locked) {
                throw new RuntimeException('Another import is running. If a request crashed, an administrator can remove the favers_touredge_import_lock option after verifying no import is active.');
            }
            $catalogs = favers_touredge_catalog_sources();
            $source = isset($_POST['touredge_source']) && is_string($_POST['touredge_source']) ? $_POST['touredge_source'] : '';
            if ($source !== '') {
                if (!isset($catalogs[$source])) {
                    throw new RuntimeException('Unknown catalog selection.');
                }
                if (get_woocommerce_currency() !== 'USD') {
                    throw new RuntimeException('Included Tour Edge prices are in USD. Set your store currency to USD or upload your own converted price CSV.');
                }
                $rows = $catalogs[$source]['rows'];
            } else {
                $file = isset($_FILES['touredge_csv']) ? $_FILES['touredge_csv'] : array();
                if (!isset($file['error'], $file['tmp_name'], $file['size']) || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 1048576 || !is_uploaded_file($file['tmp_name'])) {
                    throw new RuntimeException('Fetch and select a catalog snapshot, or upload a CSV file no larger than 1 MB.');
                }
                $stream = fopen($file['tmp_name'], 'rb');
            }
            if ($source === '') {
                if (!$stream) {
                    throw new RuntimeException('Cannot read the upload.');
                }
                try {
                    $rows = favers_touredge_parse_csv($stream);
                } finally {
                    fclose($stream);
                }
            }
            $preview = !isset($_POST['apply_import']);
            $result = favers_touredge_import($rows, $preview, isset($_POST['special_order']));
            echo '<div class="notice notice-success"><p>' . esc_html($result) . '</p></div>';
        } catch (Throwable $error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error->getMessage()) . '</p></div>';
        } finally {
            if ($locked) {
                delete_option('favers_touredge_import_lock');
            }
        }
    }
    echo '<p>Use an authorized supplier CSV with headers <code>sku,name,price,stock,description</code>. Prices must be in your WooCommerce store currency (' . esc_html(get_woocommerce_currency()) . ') and follow your store tax-entry settings. Optional image_url column supported for Tour Edge first-party photos. Maximum 200 rows / 1 MB. This importer supports simple physical products only.</p>';
    echo '<p>Preview validates the complete file without saving products. Apply creates new products as drafts. Existing importer-owned simple products retain their publication status. Reimports update title, description, regular price and stock, clear sale prices, and add the Tour Edge category. Other products are never overwritten. Rows absent from a later CSV are left unchanged.</p>';
    echo '<p>Each supplier SKU receives a <code>touredge-</code> prefix in WooCommerce. Review imported products, shipping, taxes, images and resale terms before publishing. No live price or inventory synchronization is provided.</p>';
    echo '<form method="post" enctype="multipart/form-data">';
    wp_nonce_field('favers_touredge_import');
    echo '<input type="hidden" name="favers_touredge_import" value="1">';
    $source = isset($_POST['touredge_source']) && is_string($_POST['touredge_source']) ? $_POST['touredge_source'] : '';
    echo '<p><label>Product source <select name="touredge_source">';
    $choices = array('' => 'Upload my own CSV');
    foreach (favers_touredge_catalog_sources() as $key => $catalog) {
        $choices[$key] = $catalog['label'];
    }
    foreach ($choices as $value => $label) {
        echo '<option value="' . esc_attr($value) . '" ' . ($source === $value ? 'selected' : '') . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label></p><p>Fetched catalogs use public Tour Edge USD retail price snapshots, not live prices or confirmed dealer inventory. Each exact club configuration is a separate product. Confirm supply before publishing.</p><p><label>Or upload a CSV <input type="file" name="touredge_csv" accept=".csv,text/csv"></label></p><p><label><input type="checkbox" name="apply_import" value="1"> Apply import (leave unchecked to preview)</label></p>';
    $special_order = !isset($_POST['favers_touredge_import']) || isset($_POST['special_order']);
    echo '<p><label><input type="checkbox" name="special_order" value="1" ' . ($special_order ? 'checked' : '') . '> Special order: accept orders at zero stock and notify shoppers</label></p><p>Use zero stock for items you do not hold. Confirm supplier fulfillment and communicate lead times before publishing. Uncheck Special order to prevent checkout at zero stock. This choice applies to every row in this import; it does not update products absent from the CSV.</p>';
    submit_button('Validate / Import');
    echo '</form>';
    echo '<hr><h2>Product photos</h2><p>Add the manufacturer catalog photo to imported Tour Edge products that have no featured image. Existing images, prices, stock, special-order settings and publication status are preserved. Shared photos are downloaded once to your Media Library and reused across club configurations.</p><p>This runs in small batches while this page stays open. You can stop and resume safely. Applies to imported products with supported manufacturer image URLs. For CSV imports, supply the optional image_url column or add photos manually.</p>';
    echo '<p><button type="button" class="button button-primary" id="fte-add-photos">Add missing product photos</button> <button type="button" class="button" id="fte-stop-photos" disabled>Stop</button></p><p id="fte-photo-status" role="status" aria-live="polite"></p>';
    echo '</div>';
}

function favers_touredge_import($rows, $preview, $special_order = false) {
    $plan = array();
    foreach ($rows as $row) {
        $sku = 'touredge-' . $row['sku'];
        $id = wc_get_product_id_by_sku($sku);
        $product = $id ? wc_get_product($id) : null;
        if ($id && (!$product || !$product->is_type('simple') || $product->get_meta('_favers_touredge_source') !== 'supplier_csv')) {
            throw new RuntimeException('SKU conflict for ' . $sku . '. No products were changed.');
        }
        $plan[] = array($row, $product);
    }
    if ($preview) {
        return 'Preview passed for ' . count($plan) . ' products. ' . ($special_order ? 'Special orders enabled: zero-stock products will accept backorders.' : 'Special orders disabled: zero-stock products cannot be purchased.') . ' No changes saved. Upload again with Apply import checked to save.';
    }
    $term = term_exists('touredge', 'product_cat');
    if (!$term) {
        $term = wp_insert_term('Tour Edge', 'product_cat', array('slug' => 'touredge'));
    }
    if (is_wp_error($term)) {
        throw new RuntimeException('Could not create Tour Edge category: ' . $term->get_error_message());
    }
    $category_id = (int) (is_array($term) ? $term['term_id'] : $term);
    $saved = 0;
    foreach ($plan as $entry) {
        list($row, $product) = $entry;
        try {
            if (!$product) {
                $product = new WC_Product_Simple();
                $product->set_status('draft');
                $product->set_sku('touredge-' . $row['sku']);
            }
            $product->set_name(sanitize_text_field($row['name']));
            $product->set_description(sanitize_textarea_field($row['description']));
            $product->set_regular_price($row['price']);
            $product->set_sale_price('');
            $product->set_date_on_sale_from(null);
            $product->set_date_on_sale_to(null);
            $product->set_price($row['price']);
            $product->set_manage_stock(true);
            $product->set_backorders($special_order ? 'notify' : 'no');
            $product->set_stock_quantity((int) $row['stock']);
            $product->set_stock_status((int) $row['stock'] > 0 ? 'instock' : ($special_order ? 'onbackorder' : 'outofstock'));
            $product->update_meta_data('_favers_touredge_special_order', $special_order ? 'yes' : 'no');
            $product->set_category_ids(array_unique(array_merge($product->get_category_ids(), array($category_id))));
            $product->update_meta_data('_favers_touredge_source', 'supplier_csv');
            if (array_key_exists('image_url', $row)) {
                $product->update_meta_data('_favers_touredge_photo_url', $row['image_url']);
            }
            $product->save();
            ++$saved;
        } catch (Throwable $error) {
            throw new RuntimeException('Import stopped after ' . $saved . ' saved products at SKU ' . $row['sku'] . ': ' . $error->getMessage() . ' Correct the issue and reimport; saved products will be updated by SKU.');
        }
    }
    $page = get_page_by_path('touredge');
    if (!$page && current_user_can('publish_pages')) {
        $page_id = wp_insert_post(array(
            'post_title' => 'Tour Edge', 'post_name' => 'touredge', 'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => '<!-- wp:shortcode -->[favers_touredge_shop]<!-- /wp:shortcode -->',
        ), true);
        if (is_wp_error($page_id)) {
            return $saved . ' products saved. Page creation failed; create /touredge manually with [favers_touredge_shop].';
        }
        return $saved . ' products saved. Tour Edge catalog page created; new products are drafts pending your review.';
    }
    return $saved . ' products saved. Existing /touredge content was preserved (or page-publishing permission is missing). Add [favers_touredge_shop] to your Tour Edge page. New products remain drafts.';
}
