<?php
/**
 * Plugin Name: Hannies OG Images
 * Description: Dynamic Open Graph image generation for all post types using resvg rendering.
 * Version: 1.0.0
 * Author: Hannies Travels
 * Text Domain: hannies-og
 * Requires PHP: 8.4
 */

defined('ABSPATH') || exit;

define('HANNIES_OG_PATH', plugin_dir_path(__FILE__));
define('HANNIES_OG_URL', plugin_dir_url(__FILE__));
define('HANNIES_OG_VERSION', '1.0.0');

require_once HANNIES_OG_PATH . 'includes/class-og-template-engine.php';
require_once HANNIES_OG_PATH . 'includes/class-og-renderer.php';
require_once HANNIES_OG_PATH . 'includes/class-og-meta.php';
require_once HANNIES_OG_PATH . 'includes/class-og-endpoint.php';

if (is_admin()) {
    require_once HANNIES_OG_PATH . 'includes/class-og-admin.php';
}

add_action('init', function () {
    Hannies_OG_Meta::init();
    Hannies_OG_Endpoint::init();

    if (is_admin()) {
        Hannies_OG_Admin::init();
    }
});
