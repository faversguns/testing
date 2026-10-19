<?php
// Isolated regression tests. These doubles do not replace staging validation.
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function add_filter($name, $callback, ...$args) { $GLOBALS['filters'][$name] = $callback; }
function remove_filter($name, $callback) { unset($GLOBALS['filters'][$name]); }
function remove_action(...$args) {}
function sanitize_text_field($s) { return strip_tags($s); }
function sanitize_textarea_field($s) { return strip_tags($s); }
function wc_get_product_id_by_sku($sku) {
    foreach ($GLOBALS['products'] as $id => $product) {
        if (strtolower($product->sku) === strtolower($sku)) { return $id; }
    }
    return 0;
}
function wc_get_product($id) { return $GLOBALS['products'][$id]; }
function term_exists(...$args) { return array('term_id' => 7); }
function is_wp_error($v) { return false; }
function get_page_by_path($path) { return $GLOBALS['page']; }
function current_user_can($cap) { return true; }
function wp_insert_post($post, $error) { $GLOBALS['page'] = $post; return 42; }
class WC_Product_Simple {
    public $sku = '';
    public $data = array();
    public $meta = array();
    public $id;
    public $type = 'simple';
    function is_type($type) { return $this->type === $type; }
    function get_meta($key) { return $this->meta[$key] ?? ''; }
    function get_stock_status() { return $this->data['stock_status'] ?? ''; }
    function get_id() { return $this->id; }
    function get_image_id(...$args) { return $this->data['image_id'] ?? 0; }
    function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    function get_category_ids() { return $this->data['category_ids'] ?? array(); }
    function set_sku($sku) { $this->sku = $sku; }
    function __call($name, $args) { $this->data[substr($name, 4)] = $args[0]; }
    function save() {
        if (!$this->id) { $this->id = count($GLOBALS['products']) + 1; }
        $GLOBALS['products'][$this->id] = $this;
        return $this->id;
    }
}
require __DIR__ . '/../favers-cobra.php';
$GLOBALS['products'] = array();
$GLOBALS['page'] = null;
$checks = 0;
function check($condition, $message) {
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    ++$checks;
}
function parse($csv) {
    $s = fopen('php://temp', 'r+'); fwrite($s, $csv); rewind($s);
    try { return favers_cobra_parse_csv($s); } finally { fclose($s); }
}
$header = "sku,name,price,stock,description\n";
$rows = parse("\xEF\xBB\xBF" . $header . "ABC,Driver,299.99,2,\"Description, quoted\"\n");
check($rows[0]['description'] === 'Description, quoted', 'BOM and quoted CSV');
foreach (array(
    '', "sku,name\n", $header . "A,Name,-1,2,Desc\n",
    $header . "A,Name,1.001,2,Desc\n", $header . "A,Name,0,2,Desc\n",
    $header . "A,Name,1,1.5,Desc\n", $header . "A,Name,1,-2,Desc\n",
    $header . "A,,1,2,Desc\n", $header . "../A,Name,1,2,Desc\n",
    $header . "A,Name,1,2,Desc\na,Name,1,2,Desc\n",
    $header . "A,Name,1,2\n", "sku,name,price,stock,description,name\n",
    $header . implode('', array_map(function ($i) { return "A$i,Name,1,2,Desc\n"; }, range(1, 201))),
) as $invalid) {
    $rejected = false;
    try { parse($invalid); } catch (RuntimeException $e) { $rejected = true; }
    check($rejected, 'invalid CSV rejected');
}
favers_cobra_import($rows, true);
check(count($GLOBALS['products']) === 0 && $GLOBALS['page'] === null, 'preview has no writes');
favers_cobra_import($rows, false);
$p = $GLOBALS['products'][1];
check($p->sku === 'cobra-ABC' && $p->data['status'] === 'draft', 'SKU prefix and draft default');
check($p->data['regular_price'] === '299.99' && $p->data['stock_quantity'] === 2, 'price and stock');
check(strpos($GLOBALS['page']['post_content'], '[favers_cobra_shop]') !== false, 'catalog page shortcode');
$existing_page = $GLOBALS['page'];
$p->data['status'] = 'publish';
$p->data['category_ids'][] = 99;
$rows[0]['stock'] = '0'; $rows[0]['price'] = '250.00';
favers_cobra_import($rows, false);
check(count($GLOBALS['products']) === 1, 'reimport does not duplicate');
check($p->data['status'] === 'publish' && $p->data['stock_status'] === 'outofstock', 'status preserved and zero stock unavailable');
check($p->data['regular_price'] === '250.00' && $p->data['sale_price'] === '', 'price updated and sale cleared');
check(in_array(99, $p->data['category_ids'], true), 'unrelated category preserved');
check($existing_page === $GLOBALS['page'], 'existing page preserved');
$p->meta = array();
$before = $p->data;
$rejected = false;
try { favers_cobra_import($rows, false); } catch (RuntimeException $e) { $rejected = true; }
check($rejected && $before === $p->data, 'unowned product protected');
$p->meta['_favers_cobra_source'] = 'supplier_csv'; $p->type = 'variable';
$rejected = false;
try { favers_cobra_import($rows, false); } catch (RuntimeException $e) { $rejected = true; }
check($rejected, 'variable products protected');
$p->type = 'simple';
$rows[0]['stock'] = '0';
favers_cobra_import($rows, false, true);
check($p->data['backorders'] === 'notify' && $p->data['stock_status'] === 'onbackorder', 'special order purchasable at zero local stock');
check(favers_cobra_is_special_order($p), 'special-order badge eligible');
check(strpos(($GLOBALS['filters']['woocommerce_get_availability_text'])('In stock', $p), 'Special order') !== false, 'availability explains special order');
check(strpos(($GLOBALS['filters']['woocommerce_cart_item_backorder_notification'])('Default', $p->id), 'Special order') !== false, 'cart notification explains special order');
$rows[0]['stock'] = '3';
favers_cobra_import($rows, false, true);
check($p->data['stock_status'] === 'instock' && !favers_cobra_is_special_order($p), 'in-stock imports remain in stock');
$rows[0]['stock'] = '0';
favers_cobra_import($rows, false, false);
check($p->data['backorders'] === 'no' && $p->data['stock_status'] === 'outofstock' && !favers_cobra_is_special_order($p), 'special orders can be disabled by reimport');
$before = $p->data;
$result = favers_cobra_import($rows, true, true);
check($before === $p->data && strpos($result, 'Special orders enabled') !== false, 'preview communicates special orders without writes');
function wp_unslash($s) { return stripslashes($s); }
$options = favers_cobra_shop_options(array('cobra_type' => 'irons', 'cobra_sort' => 'price-low', 'cobra_search' => 'Forged'));
check($options === array('type' => 'irons', 'sort' => 'price-low', 'search' => 'Forged'), 'valid storefront options');
$options = favers_cobra_shop_options(array('cobra_type' => array('drivers'), 'cobra_sort' => 'invalid', 'cobra_search' => array('x')));
check($options === array('type' => 'all', 'sort' => 'newest', 'search' => ''), 'malformed query parameters handled');
check(strlen(favers_cobra_shop_options(array('cobra_search' => str_repeat('a', 200)))['search']) === 100, 'bounded search');
function is_admin() { return false; }
function is_page($slug) { return true; }
function in_the_loop() { return true; }
function is_main_query() { return true; }
check(favers_cobra_default_content('') === '[favers_cobra_shop]', 'empty Cobra page gains storefront');
check(favers_cobra_default_content('<!-- wp:shortcode -->[products category="cobra" paginate="true" limit="12"]<!-- /wp:shortcode -->') === '[favers_cobra_shop]', 'original catalog upgrades');
check(favers_cobra_default_content('<p>My custom content</p>') === '<p>My custom content</p>', 'custom content preserved');
function do_shortcode($shortcode) {
    $GLOBALS['last_shortcode'] = $shortcode;
    $GLOBALS['last_query'] = ($GLOBALS['filters']['woocommerce_shortcode_products_query'])(array());
    return '<div>Rendered product loop</div>';
}
favers_cobra_products(array('type' => 'drivers', 'sort' => 'price-low', 'search' => 'DARKSPEED'));
check($GLOBALS['last_query']['s'] === 'driver DARKSPEED', 'driver and text search combined');
check($GLOBALS['last_query']['search_columns'] === array('post_title'), 'search restricted to titles');
check(strpos($GLOBALS['last_shortcode'], 'category="cobra"') !== false && strpos($GLOBALS['last_shortcode'], 'orderby="price" order="ASC"') !== false, 'Cobra-only scope and ascending price sort');
check(!isset($GLOBALS['filters']['woocommerce_shortcode_products_query']), 'query filter removed after rendering');
favers_cobra_products(array('type' => 'irons', 'sort' => 'price-high', 'search' => ''));
check($GLOBALS['last_query']['s'] === 'iron' && strpos($GLOBALS['last_shortcode'], 'order="DESC"') !== false, 'iron filter and descending sort');
favers_cobra_products(array('type' => 'all', 'sort' => 'newest', 'search' => ''));
check(!isset($GLOBALS['last_query']['s']) && strpos($GLOBALS['last_shortcode'], 'orderby="date"') !== false, 'unfiltered collection and newest sort');
echo "PASS: $checks regression checks (isolated WordPress/WooCommerce doubles).\n";
