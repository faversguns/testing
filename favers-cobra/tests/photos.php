<?php
require __DIR__ . '/run.php';
$GLOBALS['attachments'] = array();
$GLOBALS['downloads'] = 0;
function get_posts($args) {
    foreach ($GLOBALS['attachments'] as $id => $meta) {
        if (($meta[$args['meta_key']] ?? '') === $args['meta_value']) { return array($id); }
    }
    return array();
}
function wp_attachment_is_image($id) { return isset($GLOBALS['attachments'][$id]); }
function update_post_meta($id, $key, $value) { $GLOBALS['attachments'][$id][$key] = $value; }
function media_sideload_image($url, $parent, $title, $type) {
    if (!empty($GLOBALS['fail_download'])) { throw new RuntimeException('Simulated CDN failure'); }
    $args = ($GLOBALS['filters']['http_request_args'])(array('sslverify' => true), $url);
    check($args['timeout'] === 12 && $args['redirection'] === 0 && $args['sslverify'], 'bounded download keeps TLS verification');
    ++$GLOBALS['downloads'];
    $id = 1000 + $GLOBALS['downloads'];
    $GLOBALS['attachments'][$id] = array();
    return $id;
}
$catalog = favers_cobra_photo_catalog();
check(count($catalog) === 306, 'all bundled configurations have photos');
foreach ($catalog as $photo) {
    check(favers_cobra_valid_photo_url($photo['url']), 'bundled image uses trusted Cobra image path');
}
foreach (array('http://www.cobragolf.com/cdn/shop/files/a.jpg', 'https://evil.example/a.jpg', 'https://www.cobragolf.com.evil.example/a.jpg', 'https://www.cobragolf.com:8443/cdn/shop/files/a.jpg', 'https://user@www.cobragolf.com/cdn/shop/files/a.jpg', 'https://www.cobragolf.com/products/driver') as $bad) {
    check(!favers_cobra_valid_photo_url($bad), 'unsupported image host or protocol rejected');
}
$source = reset($catalog);
$p = $GLOBALS['products'][1];
$before = $p->data;
$map = array($p->sku => $source);
$result = favers_cobra_photo_batch($map);
check($result['updated'] === 1 && !$result['errors'] && $result['remaining'] === 0, 'missing image attached');
$after = $p->data; unset($after['image_id']);
check($after === $before, 'photo update preserves price stock and publication state');
check(!isset($GLOBALS['filters']['http_request_args']), 'HTTP filter removed after download');
$result = favers_cobra_photo_batch($map);
check($result['updated'] === 0 && $GLOBALS['downloads'] === 1, 'existing image preserved on repeat run');
$other = new WC_Product_Simple(); $other->set_sku('cobra-SECOND');
$other->update_meta_data('_favers_cobra_source', 'supplier_csv'); $other->save();
$result = favers_cobra_photo_batch(array($other->sku => $source));
check($result['updated'] === 1 && $GLOBALS['downloads'] === 1 && $other->get_image_id() === $p->get_image_id(), 'shared image reused without another download');
$other->data['image_id'] = 9999;
favers_cobra_photo_batch(array($other->sku => $source));
check($other->get_image_id() === 9999, 'custom featured image preserved');
$other->data['image_id'] = 0; $other->meta = array();
$result = favers_cobra_photo_batch(array($other->sku => $source));
check($result['updated'] === 0, 'unowned product untouched');
$other->update_meta_data('_favers_cobra_source', 'supplier_csv');
$GLOBALS['fail_download'] = true;
$source['url'] .= '&photo-test=1';
$result = favers_cobra_photo_batch(array($other->sku => $source));
check($result['updated'] === 0 && count($result['errors']) === 1 && $result['remaining'] === 1, 'failed download reports retryable error without image writes');
check(!isset($GLOBALS['filters']['http_request_args']), 'HTTP filter removed on failure');
echo "PASS: $checks total regression and image checks (isolated media doubles).\n";
