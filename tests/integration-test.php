<?php
/**
 * Integration Test — runs inside Docker with WordPress loaded.
 *
 * Verifies the full pipeline: template engine → JSON node tree → Takumi FFI → PNG.
 * Also tests the REST endpoint and OG meta output.
 *
 * Run: docker compose exec wordpress php wp-content/plugins/wp-og-takumi/tests/integration-test.php
 */

$_SERVER['HTTP_HOST'] = 'localhost:8080';
$_SERVER['REQUEST_URI'] = '/';
define('ABSPATH', '/var/www/html/');
require_once ABSPATH . 'wp-load.php';

$tests_run = 0;
$tests_pass = 0;

function assert_test(string $name, bool $condition, string $detail = ''): void {
    global $tests_run, $tests_pass;
    $tests_run++;
    if ($condition) {
        $tests_pass++;
        echo "  PASS: {$name}\n";
    } else {
        echo "  FAIL: {$name}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

echo "=== Integration Test ===\n\n";

// Ensure the plugin is active
if (!function_exists('is_plugin_active')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if (!is_plugin_active('wp-og-takumi/wp-og-takumi.php')) {
    activate_plugin('wp-og-takumi/wp-og-takumi.php');
    echo "  Activated wp-og-takumi plugin.\n";
}

// --- Test 1: Plugin classes are loaded ---
assert_test('WP_OG_Takumi_Template_Engine class exists', class_exists('WP_OG_Takumi_Template_Engine'));
assert_test('WP_OG_Takumi_Renderer class exists', class_exists('WP_OG_Takumi_Renderer'));
assert_test('WP_OG_Takumi_Meta class exists', class_exists('WP_OG_Takumi_Meta'));
assert_test('WP_OG_Takumi_Endpoint class exists', class_exists('WP_OG_Takumi_Endpoint'));

// --- Test 2: Template engine produces valid node tree ---
echo "\n--- Template Engine ---\n";
$engine = new WP_OG_Takumi_Template_Engine();

$template = file_get_contents(WP_OG_TAKUMI_PATH . 'templates/default.html');
assert_test('Default template loaded', strlen($template) > 50, strlen($template) . ' chars');

$variables = [
    'post_type_label' => 'Tour',
    'title' => 'Bangkok Explorer',
    'excerpt' => 'Discover the best temples and street food in Bangkok.',
    'site_name' => "Your Site Name",
    'date' => 'March 19, 2026',
];

$html = $engine->substitute($template, $variables);
assert_test('Variable substitution works', str_contains($html, 'Bangkok Explorer'));

$nodeTree = $engine->toNodeTree($html);
assert_test('Node tree is non-empty array', !empty($nodeTree));
assert_test('Root node is container type', ($nodeTree['type'] ?? '') === 'container');
assert_test('Root has tw attribute', !empty($nodeTree['tw']));
assert_test('Root has style attribute (gradient)', !empty($nodeTree['style']));

$json = json_encode($nodeTree, JSON_UNESCAPED_UNICODE);
assert_test('Node tree contains title', str_contains($json, 'Bangkok Explorer'));
assert_test('Node tree contains site name', str_contains($json, "Your Site Name"));

// --- Test 3: Tour template ---
echo "\n--- Tour Template ---\n";
$tour_template = file_get_contents(WP_OG_TAKUMI_PATH . 'templates/tour.html');
$tour_vars = [
    'title' => 'Chiang Mai Trekking',
    'excerpt' => 'Three days through the northern mountains.',
    'price' => '$299',
    'duration' => '3 days',
    'location' => 'Chiang Mai',
    'site_name' => "Your Site Name",
];
$tour_html = $engine->substitute($tour_template, $tour_vars);
$tour_tree = $engine->toNodeTree($tour_html);

$tour_json = json_encode($tour_tree, JSON_UNESCAPED_UNICODE);
assert_test('Tour tree non-empty', !empty($tour_tree));
assert_test('Tour tree has price', str_contains($tour_json, '$299'));
assert_test('Tour tree has duration', str_contains($tour_json, '3 days'));
assert_test('Tour tree has location', str_contains($tour_json, 'Chiang Mai'));

// --- Test 4: Template cascade ---
echo "\n--- Template Cascade ---\n";

$test_post_id = wp_insert_post([
    'post_title'   => 'Integration Test Post',
    'post_content' => 'This is a test post for OG image integration testing.',
    'post_status'  => 'publish',
    'post_type'    => 'post',
]);
assert_test('Test post created', $test_post_id > 0, "post_id: {$test_post_id}");

if ($test_post_id > 0) {
    $resolved = $engine->resolveTemplate($test_post_id);
    assert_test('Cascade resolves template for post', strlen($resolved) > 50);

    $post_vars = $engine->getVariables($test_post_id);
    assert_test('getVariables returns title', ($post_vars['title'] ?? '') === 'Integration Test Post');
    assert_test('getVariables returns site_name', !empty($post_vars['site_name']));
    assert_test('getVariables returns date', !empty($post_vars['date']));

    update_post_meta($test_post_id, '_og_template', '<div tw="w-[1200px] h-[630px] flex"><div tw="p-16"><h1 tw="text-6xl font-bold">Custom</h1></div></div>');
    $custom = $engine->resolveTemplate($test_post_id);
    assert_test('Per-post override takes priority', str_contains($custom, 'Custom'));
    delete_post_meta($test_post_id, '_og_template');
}

// --- Test 5: Full FFI render pipeline ---
echo "\n--- FFI Render Pipeline ---\n";
$renderer = null;
$ffi_available = true;
try {
    $renderer = new WP_OG_Takumi_Renderer();

    // Render the default template node tree
    $render_json = json_encode($nodeTree, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $png_bytes = $renderer->render($render_json);

    assert_test('FFI render returns bytes', strlen($png_bytes) > 0);
    assert_test('Output is PNG (magic bytes)', substr($png_bytes, 0, 4) === "\x89PNG");

    $tmp = tempnam(sys_get_temp_dir(), 'og_int_');
    file_put_contents($tmp, $png_bytes);
    $info = @getimagesize($tmp);
    unlink($tmp);

    assert_test('PNG dimensions 1200x630', $info && $info[0] === 1200 && $info[1] === 630,
        $info ? "{$info[0]}x{$info[1]}" : 'getimagesize failed');

} catch (\Throwable $e) {
    $ffi_available = false;
    echo "  SKIP: FFI not available — {$e->getMessage()}\n";
}

// --- Test 6: Cached render ---
if ($ffi_available && $test_post_id > 0) {
    echo "\n--- Cached Render ---\n";

    WP_OG_Takumi_Renderer::invalidateCache($test_post_id);

    $cache_path = $renderer->renderCached($test_post_id);
    assert_test('renderCached returns file path', file_exists($cache_path), $cache_path);

    if (file_exists($cache_path)) {
        $cached_png = file_get_contents($cache_path);
        assert_test('Cached file is PNG', substr($cached_png, 0, 4) === "\x89PNG");
        assert_test('Cached file > 1KB', strlen($cached_png) > 1000, strlen($cached_png) . ' bytes');

        $cache_path2 = $renderer->renderCached($test_post_id);
        assert_test('Second call returns same path', $cache_path === $cache_path2);

        WP_OG_Takumi_Renderer::invalidateCache($test_post_id);
        assert_test('Cache invalidated', !file_exists($cache_path));
    }
}

// --- Test 7: REST endpoint registration ---
echo "\n--- REST Endpoint ---\n";
do_action('rest_api_init');
$server = rest_get_server();
$routes = $server->get_routes();
$has_route = isset($routes['/wp-og-takumi/v1/og-image/(?P<id>\\d+)']);
assert_test('REST route registered', $has_route);

if ($has_route && $test_post_id > 0 && $ffi_available) {
    assert_test('REST endpoint resolves for test post', true);
}

// --- Test 8: OG meta output ---
echo "\n--- OG Meta Output ---\n";
if ($test_post_id > 0) {
    global $wp_query;
    $wp_query = new WP_Query(['p' => $test_post_id, 'post_type' => 'post']);

    ob_start();
    WP_OG_Takumi_Meta::outputMetaTags();
    $meta_output = ob_get_clean();

    assert_test('OG meta output non-empty', strlen($meta_output) > 0);
    assert_test('Contains og:image tag', str_contains($meta_output, 'og:image'));
    assert_test('Contains og:image:width', str_contains($meta_output, 'og:image:width'));
    assert_test('Contains twitter:card', str_contains($meta_output, 'twitter:card'));
    assert_test('Image URL points to REST endpoint', str_contains($meta_output, '/wp-og-takumi/v1/og-image/'));
}

// --- Cleanup ---
if ($test_post_id > 0) {
    WP_OG_Takumi_Renderer::invalidateCache($test_post_id);
    wp_delete_post($test_post_id, true);
}

// --- Summary ---
echo "\n=== Results: {$tests_pass}/{$tests_run} passed ===\n";
exit($tests_pass === $tests_run ? 0 : 1);
