<?php
/**
 * Plugin Name: Favers Cobra Importer
 * Description: Import an authorized Cobra supplier CSV into WooCommerce and create a Cobra catalog page.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 */
defined('ABSPATH') || exit;
require_once __DIR__ . '/includes/csv.php';

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Cobra Import', 'Cobra Import', 'manage_woocommerce', 'favers-cobra', 'favers_cobra_admin');
});

function favers_cobra_admin() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    echo '<div class="wrap"><h1>Favers Cobra Import</h1>';
    if (!class_exists('WooCommerce')) {
        echo '<p>Install and activate WooCommerce first.</p></div>';
        return;
    }
    if (isset($_POST['favers_cobra_import'])) {
        check_admin_referer('favers_cobra_import');
        $locked = false;
        try {
            // Atomic option insertion prevents concurrent imports and SKU races.
            $locked = add_option('favers_cobra_import_lock', time(), '', false);
            if (!$locked) {
                throw new RuntimeException('Another import is running. If a request crashed, an administrator can remove the favers_cobra_import_lock option after verifying no import is active.');
            }
            $file = isset($_FILES['cobra_csv']) ? $_FILES['cobra_csv'] : array();
            if (!isset($file['error'], $file['tmp_name'], $file['size']) || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 1048576 || !is_uploaded_file($file['tmp_name'])) {
                throw new RuntimeException('Upload a CSV file no larger than 1 MB.');
            }
            $stream = fopen($file['tmp_name'], 'rb');
            if (!$stream) {
                throw new RuntimeException('Cannot read the upload.');
            }
            try {
                $rows = favers_cobra_parse_csv($stream);
            } finally {
                fclose($stream);
            }
            $preview = !isset($_POST['apply_import']);
            $result = favers_cobra_import($rows, $preview);
            echo '<div class="notice notice-success"><p>' . esc_html($result) . '</p></div>';
        } catch (Throwable $error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error->getMessage()) . '</p></div>';
        } finally {
            if ($locked) {
                delete_option('favers_cobra_import_lock');
            }
        }
    }
    echo '<p>Use an authorized supplier CSV with headers <code>sku,name,price,stock,description</code>. Prices must be in your WooCommerce store currency (' . esc_html(get_woocommerce_currency()) . ') and follow your store tax-entry settings. Maximum 200 rows / 1 MB. This importer supports simple physical products only.</p>';
    echo '<p>Preview validates the complete file without saving products. Apply creates new products as drafts. Existing importer-owned simple products retain their publication status. Reimports update title, description, regular price and stock, clear sale prices, and add the Cobra category. Other products are never overwritten. Rows absent from a later CSV are left unchanged.</p>';
    echo '<p>Each supplier SKU receives a <code>cobra-</code> prefix in WooCommerce. Review imported products, shipping, taxes, images and resale terms before publishing. No live price or inventory synchronization is provided.</p>';
    echo '<form method="post" enctype="multipart/form-data">';
    wp_nonce_field('favers_cobra_import');
    echo '<input type="hidden" name="favers_cobra_import" value="1"><input type="file" name="cobra_csv" accept=".csv,text/csv" required><p><label><input type="checkbox" name="apply_import" value="1"> Apply import (leave unchecked to preview)</label></p>';
    submit_button('Validate / Import');
    echo '</form></div>';
}

function favers_cobra_import($rows, $preview) {
    $plan = array();
    foreach ($rows as $row) {
        $sku = 'cobra-' . $row['sku'];
        $id = wc_get_product_id_by_sku($sku);
        $product = $id ? wc_get_product($id) : null;
        if ($id && (!$product || !$product->is_type('simple') || $product->get_meta('_favers_cobra_source') !== 'supplier_csv')) {
            throw new RuntimeException('SKU conflict for ' . $sku . '. No products were changed.');
        }
        $plan[] = array($row, $product);
    }
    if ($preview) {
        return 'Preview passed for ' . count($plan) . ' products. No changes saved. Upload again with Apply import checked to save.';
    }
    $term = term_exists('cobra', 'product_cat');
    if (!$term) {
        $term = wp_insert_term('Cobra', 'product_cat', array('slug' => 'cobra'));
    }
    if (is_wp_error($term)) {
        throw new RuntimeException('Could not create Cobra category: ' . $term->get_error_message());
    }
    $category_id = (int) (is_array($term) ? $term['term_id'] : $term);
    $saved = 0;
    foreach ($plan as $entry) {
        list($row, $product) = $entry;
        try {
            if (!$product) {
                $product = new WC_Product_Simple();
                $product->set_status('draft');
                $product->set_sku('cobra-' . $row['sku']);
            }
            $product->set_name(sanitize_text_field($row['name']));
            $product->set_description(sanitize_textarea_field($row['description']));
            $product->set_regular_price($row['price']);
            $product->set_sale_price('');
            $product->set_date_on_sale_from(null);
            $product->set_date_on_sale_to(null);
            $product->set_price($row['price']);
            $product->set_manage_stock(true);
            $product->set_backorders('no');
            $product->set_stock_quantity((int) $row['stock']);
            $product->set_stock_status((int) $row['stock'] > 0 ? 'instock' : 'outofstock');
            $product->set_category_ids(array_unique(array_merge($product->get_category_ids(), array($category_id))));
            $product->update_meta_data('_favers_cobra_source', 'supplier_csv');
            $product->save();
            ++$saved;
        } catch (Throwable $error) {
            throw new RuntimeException('Import stopped after ' . $saved . ' saved products at SKU ' . $row['sku'] . ': ' . $error->getMessage() . ' Correct the issue and reimport; saved products will be updated by SKU.');
        }
    }
    $page = get_page_by_path('cobra');
    if (!$page && current_user_can('publish_pages')) {
        $page_id = wp_insert_post(array(
            'post_title' => 'Cobra', 'post_name' => 'cobra', 'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => '<!-- wp:shortcode -->[products category="cobra" paginate="true" limit="12"]<!-- /wp:shortcode -->',
        ), true);
        if (is_wp_error($page_id)) {
            return $saved . ' products saved. Page creation failed; create /cobra manually with [products category="cobra" paginate="true" limit="12"].';
        }
        return $saved . ' products saved. Cobra catalog page created; new products are drafts pending your review.';
    }
    return $saved . ' products saved. Existing /cobra content was preserved (or page-publishing permission is missing). Add [products category="cobra" paginate="true" limit="12"] to your Cobra page. New products remain drafts.';
}
