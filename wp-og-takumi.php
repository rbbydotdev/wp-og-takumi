<?php
/**
 * Plugin Name: WP OG Takumi
 * Description: Dynamic Open Graph image generation for all post types using resvg rendering.
 * Version: 1.0.0
 * Author: Your Site Name
 * Text Domain: wp-og-takumi
 * Requires PHP: 8.4
 */

defined('ABSPATH') || exit;

define('WP_OG_TAKUMI_PATH', plugin_dir_path(__FILE__));
define('WP_OG_TAKUMI_URL', plugin_dir_url(__FILE__));
define('WP_OG_TAKUMI_VERSION', '1.0.0');

require_once WP_OG_TAKUMI_PATH . 'includes/class-og-template-engine.php';
require_once WP_OG_TAKUMI_PATH . 'includes/class-og-renderer.php';
require_once WP_OG_TAKUMI_PATH . 'includes/class-og-meta.php';
require_once WP_OG_TAKUMI_PATH . 'includes/class-og-endpoint.php';

if (is_admin()) {
    require_once WP_OG_TAKUMI_PATH . 'includes/class-og-admin.php';
}

add_action('init', function () {
    WP_OG_Takumi_Meta::init();
    WP_OG_Takumi_Endpoint::init();

    if (is_admin()) {
        WP_OG_Takumi_Admin::init();
    }
});
