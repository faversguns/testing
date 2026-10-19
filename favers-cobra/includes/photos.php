<?php
defined('ABSPATH') || exit;

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'woocommerce_page_favers-cobra') {
        return;
    }
    wp_enqueue_script('favers-cobra-photos', plugins_url('../assets/photos.js', __FILE__), array(), '1.3.0', true);
    wp_localize_script('favers-cobra-photos', 'faversCobraPhotos', array(
        'url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('favers_cobra_photos'),
    ));
});

function favers_cobra_photo_catalog() {
    $path = __DIR__ . '/../data/product-images.json';
    if (!is_readable($path)) {
        throw new RuntimeException('Product image catalog is missing. Reinstall the full plugin ZIP.');
    }
    $catalog = json_decode(file_get_contents($path), true);
    if (!is_array($catalog) || !$catalog) {
        throw new RuntimeException('Product image catalog could not be read.');
    }
    return $catalog;
}

function favers_cobra_valid_photo_url($url) {
    $parts = parse_url($url);
    return $parts && isset($parts['scheme'], $parts['host'], $parts['path'])
        && $parts['scheme'] === 'https' && $parts['host'] === 'www.cobragolf.com'
        && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
        && (strpos($parts['path'], '/cdn/shop/files/') === 0 || strpos($parts['path'], '/cdn/shop/products/') === 0);
}

function favers_cobra_photo_attachment($source) {
    if (!isset($source['url']) || !favers_cobra_valid_photo_url($source['url'])) {
        throw new RuntimeException('Image source is not a supported Cobra catalog URL.');
    }
    $url = $source['url'];
    $cached = get_posts(array('post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids',
        'numberposts' => 1, 'meta_key' => '_favers_cobra_image_source', 'meta_value' => $url));
    if ($cached && wp_attachment_is_image($cached[0])) {
        return (int) $cached[0];
    }
    if (!function_exists('media_sideload_image')) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    // Bound request duration/size and disallow redirects; WordPress retains safe
    // HTTP checks, TLS verification and file-type validation during sideloading.
    $bounds = function ($args, $request_url) use ($url) {
        if ($request_url === $url) {
            $args['timeout'] = 12;
            $args['redirection'] = 0;
            $args['limit_response_size'] = 10 * 1024 * 1024;
        }
        return $args;
    };
    add_filter('http_request_args', $bounds, 10, 2);
    try {
        $attachment = media_sideload_image($url, 0, sanitize_text_field($source['alt'] ?? 'Cobra product photo'), 'id');
    } finally {
        remove_filter('http_request_args', $bounds, 10);
    }
    if (is_wp_error($attachment)) {
        throw new RuntimeException('Photo download failed: ' . $attachment->get_error_message());
    }
    update_post_meta($attachment, '_favers_cobra_image_source', $url);
    update_post_meta($attachment, '_wp_attachment_image_alt', sanitize_text_field($source['alt'] ?? 'Cobra product photo'));
    return (int) $attachment;
}

function favers_cobra_photo_batch($catalog, $limit = 1) {
    $pending = array();
    foreach ($catalog as $sku => $source) {
        $id = wc_get_product_id_by_sku($sku);
        $product = $id ? wc_get_product($id) : null;
        if ($product && $product->get_meta('_favers_cobra_source') === 'supplier_csv' && !$product->get_image_id('edit')) {
            $pending[] = array($product, $source);
        }
    }
    $updated = 0;
    $errors = array();
    foreach (array_slice($pending, 0, max(1, min(10, $limit))) as $entry) {
        list($product, $source) = $entry;
        try {
            $attachment = favers_cobra_photo_attachment($source);
            // Reload before saving to preserve edits made since batch discovery.
            $product = wc_get_product($product->get_id());
            if (!$product || $product->get_image_id('edit') || $product->get_meta('_favers_cobra_source') !== 'supplier_csv') {
                continue;
            }
            $product->set_image_id($attachment);
            $product->save();
            ++$updated;
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
            break; // Stop on an error; the UI offers a retry instead of looping.
        }
    }
    return array('updated' => $updated, 'remaining' => max(0, count($pending) - $updated), 'errors' => $errors);
}

add_action('wp_ajax_favers_cobra_photos', function () {
    check_ajax_referer('favers_cobra_photos', 'nonce');
    if (!current_user_can('manage_woocommerce') || !current_user_can('upload_files') || !class_exists('WooCommerce')) {
        wp_send_json_error(array('message' => 'WooCommerce management and media upload permissions are required.'), 403);
        return;
    }
    $locked = false;
    try {
        // Imports and photo batches share the same atomic lock.
        $locked = add_option('favers_cobra_import_lock', time(), '', false);
        if (!$locked) {
            throw new RuntimeException('Another import or photo batch is running. Wait for it to finish, then try again.');
        }
        $result = favers_cobra_photo_batch(favers_cobra_photo_catalog());
    } catch (Throwable $error) {
        $result = array('updated' => 0, 'remaining' => 0, 'errors' => array($error->getMessage()));
    } finally {
        if ($locked) {
            delete_option('favers_cobra_import_lock');
        }
    }
    wp_send_json_success($result);
});
