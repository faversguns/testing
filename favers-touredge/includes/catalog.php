<?php
defined('ABSPATH') || exit;

function favers_touredge_catalog_sources() {
    $sources = array();
    $path = __DIR__ . '/../data/catalogs.json';
    $bundled = is_readable($path) ? json_decode(file_get_contents($path), true) : array();
    foreach (is_array($bundled) ? $bundled : array() as $key => $entry) {
        if (isset($entry['file'], $entry['label']) && preg_match('/^[a-zA-Z0-9._-]+\.csv$/D', $entry['file'])) {
            $stream = fopen(__DIR__ . '/../data/' . $entry['file'], 'rb');
            if (!$stream) {
                throw new RuntimeException('A bundled catalog file is missing. Reinstall the full ZIP.');
            }
            try { $rows = favers_touredge_parse_csv($stream); } finally { fclose($stream); }
            $sources[$key] = array('rows' => $rows, 'label' => $entry['label'] . ' — USD ' . substr($entry['fetched_at'], 0, 10));
        }
    }
    $stored = get_option('favers_touredge_catalog', array());
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
        if (!preg_match('/\bexotics\b/i', $title)) {
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
            $price = $variant['price'] ?? '';
            if (!is_string($price) || !preg_match('/^(0|[1-9][0-9]{0,6})(\.[0-9]{1,2})?$/D', $price) || (float) $price <= 0) {
                throw new RuntimeException('Invalid or duplicate price/configuration in Tour Edge catalog. No snapshot saved.');
            }
            if (isset($seen[$id])) {
                if ($seen[$id] !== $price) { throw new RuntimeException('Conflicting prices for the same Tour Edge variant.'); }
                continue;
            }
            $seen[$id] = $price;
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
            if (is_string($image) && preg_match('~^https://cdn\.shopify\.com/s/files/(?:[^/]+/){4}(files|products)/(.+)$~', $image, $match)) {
                $image = 'https://www.touredge.com/cdn/shop/' . $match[1] . '/' . $match[2];
            }
            if (!is_string($image) || !favers_touredge_valid_photo_url($image)) {
                $image = '';
            }
            $reference = 'https://www.touredge.com/products/' . $handle . '?variant=' . $id;
            $rows[] = array('sku' => 'TE-' . $id, 'name' => $name, 'price' => $price, 'stock' => '0',
                'description' => $title . '. Configuration: ' . $variant_title . '. Manufacturer SKU: ' . sanitize_text_field((string) ($variant['sku'] ?? '')) . '. Special order; not held in stock. Contact Favers for delivery timing. Public Tour Edge USD price checked ' . $date . '. Product reference: ' . $reference,
                'image_url' => $image, 'product_type' => favers_touredge_product_type($title));
            if (count($rows) > 10000) {
                throw new RuntimeException('Catalog exceeds 10000 configurations. Use reviewed CSV batches.');
            }
        }
    }
    if (!$rows) {
        throw new RuntimeException('No available Exotics configurations found. Upload your supplier CSV instead.');
    }
    return $rows;
}

function favers_touredge_fetch_catalog() {
    $cart = favers_touredge_json('/cart.js');
    if (($cart['currency'] ?? '') !== 'USD') {
        throw new RuntimeException('Could not establish USD currency. No prices saved; upload a supplier CSV in your store currency instead.');
    }
    $products = array(); $pages = array(); $complete = false; $started = microtime(true);
    for ($page = 1; $page <= 3; ++$page) {
        if (microtime(true) - $started > 16) { break; }
        $data = favers_touredge_json('/products.json?limit=250&page=' . $page);
        if (!isset($data['products']) || !is_array($data['products'])) { throw new RuntimeException('Public catalog format is unavailable.'); }
        $signature = md5(json_encode(array_column($data['products'], 'id')));
        if (isset($pages[$signature])) { throw new RuntimeException('Repeated catalog page. No partial snapshot saved.'); }
        $pages[$signature] = true;
        $products = array_merge($products, $data['products']);
        if (count($data['products']) < 250) { $complete = true; break; }
    }
    if (!$complete) { throw new RuntimeException('Catalog exceeds the safe request/page limit. Use bundled or supplier CSV batches; no partial snapshot saved.'); }
    $date = gmdate('Y-m-d\TH:i:s\Z');
    $rows = favers_touredge_normalize_catalog($products, $date);
    $snapshot = array('currency' => 'USD', 'fetched_at' => $date, 'rows' => $rows);
    update_option('favers_touredge_catalog', $snapshot, false);
    if (get_option('favers_touredge_catalog') !== $snapshot) {
        throw new RuntimeException('Catalog could not be saved. No import was performed.');
    }
    return count($rows) . ' configurations fetched. Choose a snapshot batch below and preview before importing. Products have not been created yet.';
}

function favers_touredge_product_type($title) {
    $title = strtolower($title);
    foreach (array('headcover', 'weights', 'weight kit', 'accessor', 'glove', 'bag', 'umbrella', 'hat') as $word) {
        if (strpos($title, $word) !== false) { return 'accessories'; }
    }
    foreach (array('fairway' => 'fairways', 'hybrid' => 'hybrids', 'utility' => 'utilities', 'driver' => 'drivers', 'iron' => 'irons', 'wedge' => 'wedges', 'putter' => 'putters', 'ball' => 'balls') as $word => $type) {
        if (strpos($title, $word) !== false) { return $type; }
    }
    return 'other';
}
