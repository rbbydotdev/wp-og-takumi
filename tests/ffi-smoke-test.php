<?php
/**
 * FFI Smoke Test — runs inside Docker.
 *
 * Verifies: PHP FFI loads libwp_og_takumi.so → renders JSON node tree → valid PNG bytes.
 * Run: docker compose exec wordpress php wp-content/plugins/wp-og-takumi/tests/ffi-smoke-test.php
 */

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

echo "=== FFI Smoke Test ===\n\n";

// --- Test 1: FFI extension is loaded ---
assert_test('FFI extension loaded', extension_loaded('ffi'));
if (!extension_loaded('ffi')) {
    echo "\nFATAL: FFI extension not available.\n";
    exit(1);
}

// --- Test 2: Header file exists ---
$header_path = __DIR__ . '/../lib/takumi_og.h';
assert_test('C header exists', file_exists($header_path));

// --- Test 3: Shared library exists ---
$lib_candidates = [
    __DIR__ . '/../lib/libwp_og_takumi.so',
    '/usr/local/lib/libwp_og_takumi.so',
];
$lib_path = null;
foreach ($lib_candidates as $path) {
    if (file_exists($path)) {
        $lib_path = $path;
        break;
    }
}
assert_test('Shared library exists', $lib_path !== null);
if (!$lib_path) {
    echo "\nFATAL: Shared library not found.\n";
    exit(1);
}

// --- Test 4: FFI loads without error ---
$ffi = null;
$load_error = null;
try {
    $ffi = FFI::cdef(file_get_contents($header_path), $lib_path);
} catch (\Throwable $e) {
    $load_error = $e->getMessage();
}
assert_test('FFI::cdef() succeeds', $ffi !== null, $load_error ?? '');
if (!$ffi) {
    echo "\nFATAL: FFI load failed.\n";
    exit(1);
}

// --- Test 5: Render a simple node tree → PNG ---
$json = json_encode([
    'type' => 'container',
    'tw' => 'w-[200px] h-[100px] flex items-center justify-center bg-[#FF7F50]',
    'children' => [
        ['type' => 'text', 'content' => 'Hello', 'tw' => 'text-xl text-white'],
    ],
]);

$fontDir = __DIR__ . '/../fonts';

$jsonLen = strlen($json);
$jsonPtr = FFI::new("char[" . ($jsonLen + 1) . "]");
FFI::memcpy($jsonPtr, $json, $jsonLen);

$fontDirLen = strlen($fontDir);
$fontDirPtr = FFI::new("char[" . ($fontDirLen + 1) . "]");
FFI::memcpy($fontDirPtr, $fontDir, $fontDirLen);

$outLen = FFI::new('size_t');
$outPtr = $ffi->og_render($jsonPtr, $jsonLen, $fontDirPtr, $fontDirLen, FFI::addr($outLen));

assert_test('og_render() returns non-null', $outPtr !== null);
assert_test('Output length > 0', $outLen->cdata > 0, "got: {$outLen->cdata}");

if ($outPtr !== null && $outLen->cdata > 0) {
    $png = FFI::string($outPtr, $outLen->cdata);
    $ffi->og_free($outPtr, $outLen->cdata);

    $magic = substr($png, 0, 4);
    assert_test('Output is valid PNG (magic bytes)', $magic === "\x89PNG", 'got: ' . bin2hex($magic));
    assert_test('PNG size reasonable (>100 bytes)', strlen($png) > 100, 'got: ' . strlen($png) . ' bytes');
}

// --- Test 6: Render a full OG-sized image (1200x630) ---
$og_json = json_encode([
    'type' => 'container',
    'tw' => 'w-[1200px] h-[630px] flex items-center justify-center',
    'style' => 'background: linear-gradient(135deg, #C4653A, #1B6B6D)',
    'children' => [
        [
            'type' => 'container',
            'tw' => 'flex flex-col items-center text-center p-16',
            'children' => [
                ['type' => 'text', 'content' => 'TOUR', 'tw' => 'text-lg tracking-widest text-white mb-4'],
                ['type' => 'text', 'content' => 'Bangkok Explorer', 'tw' => 'text-6xl font-bold text-white'],
                ['type' => 'text', 'content' => 'Discover Bangkok', 'tw' => 'text-2xl text-white mt-6'],
                ['type' => 'text', 'content' => "Your Site Name", 'tw' => 'text-lg font-semibold text-white mt-8'],
            ],
        ],
    ],
]);

$jsonLen2 = strlen($og_json);
$jsonPtr2 = FFI::new("char[" . ($jsonLen2 + 1) . "]");
FFI::memcpy($jsonPtr2, $og_json, $jsonLen2);

$outLen2 = FFI::new('size_t');
$outPtr2 = $ffi->og_render($jsonPtr2, $jsonLen2, $fontDirPtr, $fontDirLen, FFI::addr($outLen2));

assert_test('OG-sized render succeeds', $outPtr2 !== null);

if ($outPtr2 !== null && $outLen2->cdata > 0) {
    $og_png = FFI::string($outPtr2, $outLen2->cdata);
    $ffi->og_free($outPtr2, $outLen2->cdata);

    assert_test('OG PNG > 1KB', strlen($og_png) > 1000, 'got: ' . strlen($og_png) . ' bytes');

    $tmp = tempnam(sys_get_temp_dir(), 'og_test_');
    file_put_contents($tmp, $og_png);
    $info = @getimagesize($tmp);
    unlink($tmp);

    assert_test('OG image dimensions 1200x630', $info !== false && $info[0] === 1200 && $info[1] === 630,
        $info ? "{$info[0]}x{$info[1]}" : 'getimagesize failed');
}

// --- Test 7: Error handling for invalid input ---
$bad = "not json at all {{{";
$badLen = strlen($bad);
$badPtr = FFI::new("char[" . ($badLen + 1) . "]");
FFI::memcpy($badPtr, $bad, $badLen);

$badOutLen = FFI::new('size_t');
$badOutPtr = $ffi->og_render($badPtr, $badLen, null, 0, FFI::addr($badOutLen));
assert_test('Invalid JSON returns null', $badOutPtr === null);

$errResult = $ffi->og_last_error();
$errMsg = is_string($errResult) ? $errResult : ($errResult !== null ? FFI::string($errResult) : '');
assert_test('og_last_error() returns message', strlen($errMsg) > 0, "got: '{$errMsg}'");

// --- Summary ---
echo "\n=== Results: {$tests_pass}/{$tests_run} passed ===\n";
exit($tests_pass === $tests_run ? 0 : 1);
