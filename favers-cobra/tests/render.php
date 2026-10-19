<?php
/** Standalone render fixture, not shipped in the installation ZIP. */
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function add_filter(...$args) {}
function remove_action(...$args) {}
function remove_filter(...$args) {}
function sanitize_text_field($s) { return strip_tags($s); }
function wp_unslash($s) { return stripslashes($s); }
function esc_url($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function get_permalink() { return '/preview.html'; }
function plugins_url($path, $file) { return '../favers-cobra/assets/' . basename($path); }
function home_url($p) { return '/'; }
function wc_get_cart_url() { return '/cart/'; }
function add_query_arg($k, $v, $url) { return $url . '?' . urlencode($k) . '=' . urlencode($v); }
function get_option($key) { return '/%postname%/'; }
function checked($a, $b) { if ($a === $b) { echo 'checked'; } }
function selected($a, $b) { if ($a === $b) { echo 'selected'; } }
require __DIR__ . '/../includes/storefront.php';
$html = favers_cobra_storefront();
if (strpos($html, 'The collection is coming together.') === false) { throw new RuntimeException('Missing WooCommerce fallback'); }
if (strpos($html, '<h2 id="fc-heading">') === false) { throw new RuntimeException('Missing storefront'); }
echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Favers Cobra storefront design preview</title><link rel="stylesheet" href="../favers-cobra/assets/storefront.css"><style>body{margin:0;background:#e8e9e1;padding:24px} @media(max-width:560px){body{padding:0}}</style></head><body>';
echo $html;
echo '</body></html>';
