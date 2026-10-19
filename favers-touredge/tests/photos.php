<?php
require __DIR__ . '/run.php';
$GLOBALS['attachments'] = array();
$GLOBALS['downloads'] = 0;
function get_posts($args) {
    if ($args['post_type'] === 'product') { return array_keys($GLOBALS['products']); }
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
$GLOBALS['products'][1]->update_meta_data('_favers_touredge_photo_url', 'https://www.touredge.com/cdn/shop/files/fixture.jpg?v=1');
$catalog = favers_touredge_photo_catalog();
check(count($catalog) === 1, 'CSV image metadata becomes photo work without a bundled catalog');
foreach ($catalog as $photo) {
    check(favers_touredge_valid_photo_url($photo['url']), 'bundled image uses trusted Tour Edge image path');
}
foreach (array('http://www.touredgegolf.com/cdn/shop/files/a.jpg', 'https://evil.example/a.jpg', 'https://www.touredgegolf.com.evil.example/a.jpg', 'https://www.touredgegolf.com:8443/cdn/shop/files/a.jpg', 'https://user@www.touredgegolf.com/cdn/shop/files/a.jpg', 'https://www.touredgegolf.com/products/driver') as $bad) {
    check(!favers_touredge_valid_photo_url($bad), 'unsupported image host or protocol rejected');
}
$source = reset($catalog);
$p = $GLOBALS['products'][1];
$before = $p->data;
$map = array($p->sku => $source);
$result = favers_touredge_photo_batch($map);
check($result['updated'] === 1 && !$result['errors'] && $result['remaining'] === 0, 'missing image attached');
$after = $p->data; unset($after['image_id']);
check($after === $before, 'photo update preserves price stock and publication state');
check(!isset($GLOBALS['filters']['http_request_args']), 'HTTP filter removed after download');
$result = favers_touredge_photo_batch($map);
check($result['updated'] === 0 && $GLOBALS['downloads'] === 1, 'existing image preserved on repeat run');
$other = new WC_Product_Simple(); $other->set_sku('touredge-SECOND');
$other->update_meta_data('_favers_touredge_source', 'supplier_csv'); $other->save();
$result = favers_touredge_photo_batch(array($other->sku => $source));
check($result['updated'] === 1 && $GLOBALS['downloads'] === 1 && $other->get_image_id() === $p->get_image_id(), 'shared image reused without another download');
$other->data['image_id'] = 9999;
favers_touredge_photo_batch(array($other->sku => $source));
check($other->get_image_id() === 9999, 'custom featured image preserved');
$other->data['image_id'] = 0; $other->meta = array();
$result = favers_touredge_photo_batch(array($other->sku => $source));
check($result['updated'] === 0, 'unowned product untouched');
$other->update_meta_data('_favers_touredge_source', 'supplier_csv');
$GLOBALS['fail_download'] = true;
$source['url'] .= '&photo-test=1';
$result = favers_touredge_photo_batch(array($other->sku => $source));
check($result['updated'] === 0 && count($result['errors']) === 1 && $result['remaining'] === 1, 'failed download reports retryable error without image writes');
check(!isset($GLOBALS['filters']['http_request_args']), 'HTTP filter removed on failure');
echo "PASS: $checks total regression and image checks (isolated media doubles).\n";
