<?php
defined('ABSPATH') || exit;

function favers_cobra_is_special_order($product) {
    return $product && $product->get_meta('_favers_cobra_source') === 'supplier_csv'
        && $product->get_meta('_favers_cobra_special_order') === 'yes'
        && $product->get_stock_status() === 'onbackorder';
}

add_filter('woocommerce_get_availability_text', function ($text, $product) {
    return favers_cobra_is_special_order($product) ? 'Special order — not held in stock. Contact us for current delivery timing.' : $text;
}, 10, 2);

add_action('woocommerce_after_shop_loop_item_title', function () {
    global $product;
    if (favers_cobra_is_special_order($product)) {
        echo '<p class="fc-special-order">Special order &middot; delivery timing on request</p>';
    }
}, 15);

add_filter('woocommerce_cart_item_backorder_notification', function ($html, $product_id) {
    return favers_cobra_is_special_order(wc_get_product($product_id))
        ? '<p class="backorder_notification">Special order — not held in stock. Contact us for current delivery timing.</p>' : $html;
}, 10, 2);

// Preserve the label in order records, including order emails and account views.
add_action('woocommerce_checkout_create_order_line_item', function ($item, $key, $values) {
    if (isset($values['data']) && favers_cobra_is_special_order($values['data'])) {
        $item->add_meta_data('Availability', 'Special order — delivery timing on request', true);
    }
}, 10, 3);
