<?php
require __DIR__ . '/run.php';
class WooCommerce {}
function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function get_woocommerce_currency() { return $GLOBALS['currency']; }
function add_option(...$args) {
    if (!empty($GLOBALS['import_lock'])) { return false; }
    $GLOBALS['import_lock'] = true;
    return true;
}
function delete_option($key) { $GLOBALS['import_lock'] = false; }
function check_admin_referer($name) {}
function wp_nonce_field($name) {}
function submit_button($label) { echo '<button>' . esc_html($label) . '</button>'; }
function admin_render($source) {
    $_POST = array('favers_cobra_import' => '1', 'cobra_source' => $source, 'special_order' => '1');
    ob_start();
    try { favers_cobra_admin(); return ob_get_contents(); } finally { ob_end_clean(); }
}
$GLOBALS['currency'] = 'USD';
$count = count($GLOBALS['products']);
check(strpos(admin_render('drivers'), 'Preview passed for 111 products') !== false, 'built-in drivers preview without file upload');
check(strpos(admin_render('irons'), 'Preview passed for 195 products') !== false, 'built-in iron sets preview without file upload');
check(count($GLOBALS['products']) === $count && !$GLOBALS['import_lock'], 'catalog preview writes no products and releases lock');
check(strpos(admin_render('../../README.md'), 'Unknown catalog selection') !== false, 'catalog path traversal rejected');
$GLOBALS['currency'] = 'GBP';
check(strpos(admin_render('drivers'), 'Included Cobra prices are in USD') !== false, 'foreign store currency rejected');
$GLOBALS['currency'] = 'USD';
$GLOBALS['import_lock'] = true;
check(strpos(admin_render('drivers'), 'Another import is running') !== false && $GLOBALS['import_lock'], 'concurrent import protected');
$GLOBALS['import_lock'] = false;
echo "PASS: $checks total regression and admin checks (isolated doubles).\n";
