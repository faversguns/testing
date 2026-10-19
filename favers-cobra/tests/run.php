<?php
// Isolated regression tests. These doubles do not replace staging validation.
define('ABSPATH', __DIR__);
function add_action(...$args) {}
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
check(strpos($GLOBALS['page']['post_content'], '[products category="cobra"') !== false, 'catalog page shortcode');
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
echo "PASS: $checks regression checks (isolated WordPress/WooCommerce doubles).\n";
