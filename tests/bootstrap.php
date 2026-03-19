<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Brain\Monkey setup
\Brain\Monkey\setUp();

// Stub ABSPATH and plugin constants
if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
}
if (!defined('HANNIES_OG_PATH')) {
    define('HANNIES_OG_PATH', dirname(__DIR__) . '/');
}
if (!defined('HANNIES_OG_URL')) {
    define('HANNIES_OG_URL', 'http://localhost:8080/wp-content/plugins/hannies-og/');
}
if (!defined('HANNIES_OG_VERSION')) {
    define('HANNIES_OG_VERSION', '1.0.0');
}

// Load the template engine (pure PHP, no WP deps needed for parsing)
require_once HANNIES_OG_PATH . 'includes/class-og-template-engine.php';
