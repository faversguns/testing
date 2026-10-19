<?php
require __DIR__ . '/run.php';
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, ...$args) { $GLOBALS['options'][$key] = $value; }
function wp_safe_remote_get($url, $args) {
    check(strpos($url, 'https://www.touredge.com/') === 0 && !isset($args['sslverify']), 'remote source fixed to Tour Edge with default TLS verification');
    return $GLOBALS['responses'][parse_url($url, PHP_URL_PATH)] ?? array('code' => 404, 'body' => '');
}
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
$fixture = array('title' => 'Exotics Driver fixture (not a real catalog product)', 'handle' => 'test-driver', 'images' => array(array('src' => 'https://cdn.shopify.com/s/files/1/0000/1111/2222/files/test.jpg?v=1')),
    'variants' => array(array('id' => 12345, 'title' => 'Right / 10.5 / Stiff', 'available' => true, 'price' => '123.45', 'sku' => 'TEST-1')));
$rows = favers_touredge_normalize_catalog(array($fixture), 'test-date');
check(count($rows) === 1 && $rows[0]['price'] === '123.45' && $rows[0]['stock'] === '0', 'exact fixture price and no invented inventory');
check($rows[0]['image_url'] === 'https://www.touredge.com/cdn/shop/files/test.jpg?v=1', 'first-party photo URL');
$csv_rows = parse("sku,name,price,stock,description,image_url\nOPTIONAL,Fixture iron,123.45,0,Fixture description,https://www.touredge.com/cdn/shop/files/test.jpg\n");
check($csv_rows[0]['image_url'] === 'https://www.touredge.com/cdn/shop/files/test.jpg', 'optional CSV image source retained');
$rejected = false;
try { parse("sku,name,price,stock,description,image_url\nBAD,Fixture iron,123.45,0,Fixture description,https://127.0.0.1/private.jpg\n"); } catch (RuntimeException $e) { $rejected = true; }
check($rejected, 'untrusted CSV image host rejected');
$unavailable = $fixture; $unavailable['variants'][0]['available'] = false;
$cover = $fixture; $cover['title'] = 'Unrelated Driver Headcover';
check(count(favers_touredge_normalize_catalog(array($fixture, $unavailable, $cover), 'test')) === 1, 'unavailable configurations and accessories excluded');
$bad = $fixture; $bad['variants'][0]['price'] = '0.00';
$rejected = false;
try { favers_touredge_normalize_catalog(array($bad), 'test'); } catch (RuntimeException $e) { $rejected = true; }
check($rejected, 'zero price rejected');
check(count(favers_touredge_normalize_catalog(array($fixture, $fixture), 'test')) === 1, 'identical variant aliases deduplicated');
$GLOBALS['responses'] = array('/cart.js' => array('code' => 200, 'body' => '{"currency":"USD"}'), '/products.json' => array('code' => 200, 'body' => json_encode(array('products' => array($fixture)))));
favers_touredge_fetch_catalog();
$sources = favers_touredge_catalog_sources();
check(isset($sources['live-0']) && $sources['live-0']['rows'][0]['sku'] === 'TE-12345', 'verified response stored as selectable snapshot');
$snapshot = get_option('favers_touredge_catalog');
$GLOBALS['responses']['/cart.js']['body'] = '{"currency":"EUR"}';
$rejected = false;
try { favers_touredge_fetch_catalog(); } catch (RuntimeException $e) { $rejected = true; }
check($rejected && $snapshot === get_option('favers_touredge_catalog'), 'currency failure preserves previous snapshot');
$GLOBALS['responses']['/cart.js']['body'] = '{"currency":"USD"}';
$GLOBALS['responses']['/products.json']['body'] = '<html>Not a public feed</html>';
$rejected = false;
try { favers_touredge_fetch_catalog(); } catch (RuntimeException $e) { $rejected = true; }
check($rejected && $snapshot === get_option('favers_touredge_catalog'), 'incompatible response preserves previous snapshot');
$GLOBALS['responses']['/products.json']['body'] = json_encode(array('products' => array_fill(0, 250, $fixture)));
$rejected = false;
try { favers_touredge_fetch_catalog(); } catch (RuntimeException $e) { $rejected = true; }
check($rejected, 'potentially incomplete catalog rejected');
$original_page = $GLOBALS['page'];
($GLOBALS['touredge_activation'])();
check($GLOBALS['page'] === $original_page, 'activation preserves existing page');
$GLOBALS['page'] = null;
($GLOBALS['touredge_activation'])();
check($GLOBALS['page']['post_name'] === 'touredge' && strpos($GLOBALS['page']['post_content'], '[favers_touredge_shop]') !== false, 'activation creates correct page and shortcode');
require __DIR__ . '/../../favers-cobra/favers-cobra.php';
check(function_exists('favers_cobra_storefront') && function_exists('favers_touredge_storefront'), 'Cobra and Tour Edge plugins load together without function collisions');
echo "PASS: $checks total catalog, isolation and regression checks (isolated doubles).\n";
$before_data = $GLOBALS['products'][1]->data;
$before_meta = $GLOBALS['products'][1]->meta;
$result = favers_touredge_import(array(array('sku' => 'ABC', 'name' => 'Changed', 'price' => '1.00', 'stock' => '99', 'description' => 'Changed')), false, true, true);
check(strpos($result, 'left unchanged') !== false && $before_data === $GLOBALS['products'][1]->data && $before_meta === $GLOBALS['products'][1]->meta, 'create-only skips existing SKU without updating price stock or metadata');
$all = array(); $conditions = array();
foreach (favers_touredge_catalog_sources() as $key => $source) {
    if (strpos($key, 'exotics-') !== 0) { continue; }
    check(count($source['rows']) <= 200, 'bundled batch within importer limit');
    foreach ($source['rows'] as $row) {
        check(!isset($all[$row['sku']]), 'no duplicate variant across bundles');
        $all[$row['sku']] = $row;
        check($row['stock'] === '0' && favers_touredge_valid_photo_url($row['image_url']), 'bundle has no fictitious local stock and has supported photo URL');
    }
}
check(count($all) === 713, 'complete 713-configuration snapshot parses');
check(favers_touredge_product_type('Exotics LS Fairway') === 'fairways' && favers_touredge_product_type('Exotics Staff Bag') === 'accessories', 'classification uses model title rather than misleading vendor product_type');
echo "PASS: $checks total bundled-catalog checks.\n";
