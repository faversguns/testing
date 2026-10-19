<?php
require __DIR__ . '/catalog.php';
class WooCommerce {}
function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function get_woocommerce_currency() { return 'USD'; }
function add_option($key, $value, ...$args) {
    if (isset($GLOBALS['options'][$key])) { return false; }
    $GLOBALS['options'][$key] = $value; return true;
}
function delete_option($key) { unset($GLOBALS['options'][$key]); }
function check_admin_referer($name) {}
function wp_nonce_field($name) {}
function submit_button($label) { echo '<button>' . esc_html($label) . '</button>'; }
$_POST = array('favers_touredge_import' => '1', 'touredge_source' => 'live-0', 'special_order' => '1');
ob_start(); favers_touredge_admin(); $html = ob_get_clean();
check(strpos($html, 'Preview passed for 1 products') !== false, 'snapshot source previews without file upload');
check(strpos($html, 'fte-add-photos') !== false, 'photo runner is available after imports');
check(!isset($GLOBALS['options']['favers_touredge_import_lock']), 'preview releases Tour Edge lock');
check(strpos($html, '[favers_touredge_shop]') !== false, 'admin shows correct page shortcode');
echo "PASS: $checks total admin, catalog and regression checks (isolated doubles).\n";
