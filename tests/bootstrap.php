<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Brain\Monkey setup
\Brain\Monkey\setUp();

// Stub ABSPATH and plugin constants
if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
}
if (!defined('WP_OG_TAKUMI_PATH')) {
    define('WP_OG_TAKUMI_PATH', dirname(__DIR__) . '/');
}
if (!defined('WP_OG_TAKUMI_URL')) {
    define('WP_OG_TAKUMI_URL', 'http://localhost:8080/wp-content/plugins/wp-og-takumi/');
}
if (!defined('WP_OG_TAKUMI_VERSION')) {
    define('WP_OG_TAKUMI_VERSION', '1.0.0');
}

// Load the template engine (pure PHP, no WP deps needed for parsing)
require_once WP_OG_TAKUMI_PATH . 'includes/class-og-template-engine.php';
