<?php
defined('ABSPATH') || exit;

function favers_touredge_catalog_sources() {
    $stored = get_option('favers_touredge_catalog', array());
    $sources = array();
    if (!is_array($stored) || empty($stored['rows']) || ($stored['currency'] ?? '') !== 'USD') {
        return $sources;
    }
    foreach (array_chunk($stored['rows'], 200) as $index => $rows) {
        $sources['live-' . $index] = array('rows' => $rows, 'label' => 'Tour Edge snapshot ' . ($stored['fetched_at'] ?? '') . ' — batch ' . ($index + 1) . ' (' . count($rows) . ' configurations)');
    }
    return $sources;
}

/** Public endpoint only; errors never become fabricated products or prices. */
function favers_touredge_json($path) {
    $response = wp_safe_remote_get('https://www.touredge.com' . $path, array(
        'timeout' => 8, 'redirection' => 2, 'limit_response_size' => 8 * 1024 * 1024,
        'headers' => array('Accept' => 'application/json'),
    ));
    if (is_wp_error($response)) {
        throw new RuntimeException('Tour Edge request failed: ' . $response->get_error_message());
    }
    $body = wp_remote_retrieve_body($response);
    if (wp_remote_retrieve_response_code($response) !== 200 || strlen($body) >= 8 * 1024 * 1024) {
        throw new RuntimeException('Tour Edge did not provide a usable public JSON catalog. Upload your supplier CSV instead.');
    }
    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new RuntimeException('Tour Edge returned a web page instead of catalog data. Upload your supplier CSV instead.');
    }
    return $json;
}

function favers_touredge_normalize_catalog($products, $date) {
    $rows = array();
    $seen = array();
    foreach ($products as $product) {
        if (!is_array($product) || !is_string($product['title'] ?? null) || !is_array($product['variants'] ?? null)) {
            throw new RuntimeException('Unexpected public catalog format. No snapshot saved.');
        }
        $title = isset($product['title']) && is_string($product['title']) ? sanitize_text_field($product['title']) : '';
        if (!preg_match('/\b(driver|irons?)\b/i', $title) || preg_match('/headcover|head cover|head only|replacement|wrench|weight kit/i', $title)) {
            continue;
        }
        foreach ($product['variants'] ?? array() as $variant) {
            if (!is_array($variant)) {
                throw new RuntimeException('Unexpected product configuration format.');
            }
            $id = (string) ($variant['id'] ?? '');
            if (!preg_match('/^[0-9]{1,25}$/D', $id) || ($variant['available'] ?? false) !== true) {
                continue;
            }
            $variant_title = is_string($variant['title'] ?? null) ? sanitize_text_field($variant['title']) : '';
            if (stripos($variant_title, 'custom') !== false) {
                continue;
            }
            $price = $variant['price'] ?? '';
            if (!is_string($price) || !preg_match('/^(0|[1-9][0-9]{0,6})(\.[0-9]{1,2})?$/D', $price) || (float) $price <= 0 || isset($seen[$id])) {
                throw new RuntimeException('Invalid or duplicate price/configuration in Tour Edge catalog. No snapshot saved.');
            }
            $seen[$id] = true;
            $name = $title . ($variant_title && $variant_title !== 'Default Title' ? ' — ' . $variant_title : '');
            if (strlen($name) > 200) {
                throw new RuntimeException('Product title exceeds the importer limit. Use a reviewed supplier CSV for this catalog.');
            }
            $handle = $product['handle'] ?? '';
            if (!is_string($handle) || !preg_match('/^[a-zA-Z0-9_-]+$/D', $handle)) {
                throw new RuntimeException('Unexpected product reference in catalog.');
            }
            $image = $variant['featured_image']['src'] ?? ($product['images'][0]['src'] ?? '');
            // Shopify's supported first-party image path. Retain no third-party URLs.
            if (is_string($image) && preg_match('~^https://cdn\.shopify\.com/s/files/[^/]+/[^/]+/[^/]+/(files|products)/(.+)$~', $image, $match)) {
                $image = 'https://www.touredge.com/cdn/shop/' . $match[1] . '/' . $match[2];
            }
            if (!is_string($image) || !favers_touredge_valid_photo_url($image)) {
                $image = '';
            }
            $reference = 'https://www.touredge.com/products/' . $handle . '?variant=' . $id;
            $rows[] = array('sku' => 'TE-' . $id, 'name' => $name, 'price' => $price, 'stock' => '0',
                'description' => $title . '. Configuration: ' . $variant_title . '. Manufacturer SKU: ' . sanitize_text_field((string) ($variant['sku'] ?? '')) . '. Special order; not held in stock. Contact Favers for delivery timing. Public Tour Edge USD price checked ' . $date . '. Product reference: ' . $reference,
                'image_url' => $image);
            if (count($rows) > 1000) {
                throw new RuntimeException('Catalog exceeds 1000 configurations. Use reviewed CSV batches.');
            }
        }
    }
    if (!$rows) {
        throw new RuntimeException('No available standard driver or iron configurations found. Upload your supplier CSV instead.');
    }
    return $rows;
}

function favers_touredge_fetch_catalog() {
    $cart = favers_touredge_json('/cart.js');
    if (($cart['currency'] ?? '') !== 'USD') {
        throw new RuntimeException('Could not establish USD currency. No prices saved; upload a supplier CSV in your store currency instead.');
    }
    $data = favers_touredge_json('/products.json?limit=250');
    if (!isset($data['products']) || !is_array($data['products']) || count($data['products']) >= 250) {
        throw new RuntimeException('The public catalog is unavailable or needs pagination. No partial snapshot saved. Use supplier CSV batches.');
    }
    $date = gmdate('Y-m-d\TH:i:s\Z');
    $rows = favers_touredge_normalize_catalog($data['products'], $date);
    $snapshot = array('currency' => 'USD', 'fetched_at' => $date, 'rows' => $rows);
    update_option('favers_touredge_catalog', $snapshot, false);
    if (get_option('favers_touredge_catalog') !== $snapshot) {
        throw new RuntimeException('Catalog could not be saved. No import was performed.');
    }
    return count($rows) . ' configurations fetched. Choose a snapshot batch below and preview before importing. Products have not been created yet.';
}
