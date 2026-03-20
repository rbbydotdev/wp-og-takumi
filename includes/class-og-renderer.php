<?php

defined('ABSPATH') || exit;

class WP_OG_Takumi_Renderer {

    private static ?\FFI $ffi = null;

    private WP_OG_Takumi_Template_Engine $engine;

    public function __construct(?WP_OG_Takumi_Template_Engine $engine = null) {
        $this->engine = $engine ?? new WP_OG_Takumi_Template_Engine();
    }

    private function ffi(): \FFI {
        if (self::$ffi === null) {
            $header = file_get_contents(WP_OG_TAKUMI_PATH . 'lib/takumi_og.h');

            $candidates = [
                WP_OG_TAKUMI_PATH . 'lib/libwp_og_takumi.so',
                '/usr/local/lib/libwp_og_takumi.so',
                WP_OG_TAKUMI_PATH . 'lib/libwp_og_takumi.dylib',
                WP_OG_TAKUMI_PATH . 'lib/libwp_og_takumi_ffi.so',
                WP_OG_TAKUMI_PATH . 'lib/libwp_og_takumi_ffi.dylib',
            ];

            $lib = null;
            foreach ($candidates as $path) {
                if (file_exists($path)) {
                    $lib = $path;
                    break;
                }
            }

            if ($lib === null) {
                throw new \RuntimeException(
                    'wp-og-takumi shared library not found. Run: docker compose build'
                );
            }

            self::$ffi = \FFI::cdef($header, $lib);
        }

        return self::$ffi;
    }

    /**
     * Render a JSON node tree to PNG image bytes via the Takumi FFI.
     *
     * @param string $json JSON node tree string
     * @return string Raw PNG image bytes
     */
    public function render(string $json): string {
        $ffi = $this->ffi();

        $this->ensureThemeFonts();
        $fontDir = WP_OG_TAKUMI_PATH . 'fonts';

        $jsonLen = strlen($json);
        $jsonPtr = \FFI::new('char[' . ($jsonLen + 1) . ']');
        \FFI::memcpy($jsonPtr, $json, $jsonLen);

        $fontDirLen = strlen($fontDir);
        $fontDirPtr = \FFI::new('char[' . ($fontDirLen + 1) . ']');
        \FFI::memcpy($fontDirPtr, $fontDir, $fontDirLen);

        $outLen = \FFI::new('size_t');
        $outPtr = $ffi->og_render($jsonPtr, $jsonLen, $fontDirPtr, $fontDirLen, \FFI::addr($outLen));

        if ($outPtr === null) {
            $error = $ffi->og_last_error();
            $msg = is_string($error) ? $error : ($error !== null ? \FFI::string($error) : 'unknown error');
            throw new \RuntimeException('OG render failed: ' . $msg);
        }

        $imageBytes = \FFI::string($outPtr, $outLen->cdata);
        $ffi->og_free($outPtr, $outLen->cdata);

        return $imageBytes;
    }

    /**
     * Render a post's OG image, using cache when available.
     *
     * @return string File path to the cached PNG
     */
    public function renderCached(int $post_id): string {
        $cache_dir = wp_upload_dir()['basedir'] . '/og-images';
        $cache_path = $cache_dir . "/{$post_id}.png";

        if (file_exists($cache_path)) {
            return $cache_path;
        }

        if (!is_dir($cache_dir)) {
            wp_mkdir_p($cache_dir);
        }

        $template = $this->engine->resolveTemplate($post_id);
        $variables = $this->engine->getVariables($post_id);
        $html = $this->engine->substitute($template, $variables);
        $nodeTree = $this->engine->toNodeTree($html);

        $json = json_encode($nodeTree, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $imageBytes = $this->render($json);

        $written = file_put_contents($cache_path, $imageBytes);
        if ($written === false) {
            throw new \RuntimeException("Failed to write OG image cache to {$cache_path} — check directory permissions");
        }

        return $cache_path;
    }

    /**
     * Invalidate cached OG image for a post.
     */
    public static function invalidateCache(int $post_id): void {
        $cache_dir = wp_upload_dir()['basedir'] . '/og-images';
        $cache_path = $cache_dir . "/{$post_id}.png";

        if (file_exists($cache_path)) {
            unlink($cache_path);
        }
    }

    /**
     * Ensure the current theme fonts are downloaded as TTF files.
     */
    private function ensureThemeFonts(): void {
        $fonts = $this->engine->getThemeFonts();
        $fontsDir = WP_OG_TAKUMI_PATH . 'fonts';

        if (!is_dir($fontsDir)) {
            wp_mkdir_p($fontsDir);
        }

        foreach ($fonts as $role => $family) {
            $safeName = preg_replace('/[^a-zA-Z0-9]/', '', $family);
            $ttfPath = $fontsDir . '/' . $safeName . '-Regular.ttf';

            if (file_exists($ttfPath)) {
                continue;
            }

            $this->downloadGoogleFont($family, $fontsDir);
        }
    }

    private function downloadGoogleFont(string $family, string $dir): void {
        $url = 'https://fonts.googleapis.com/css2?family='
            . urlencode($family)
            . ':wght@400;600;700&display=swap';

        $response = wp_remote_get($url, [
            'timeout'    => 15,
            'user-agent' => 'Mozilla/4.0',
        ]);

        if (is_wp_error($response)) {
            return;
        }

        $css = wp_remote_retrieve_body($response);

        $safeName = preg_replace('/[^a-zA-Z0-9]/', '', $family);
        $weightLabels = ['400' => 'Regular', '600' => 'SemiBold', '700' => 'Bold'];

        preg_match_all('/@font-face\s*\{([^}]+)\}/s', $css, $faceBlocks);

        foreach ($faceBlocks[1] as $block) {
            if (!preg_match('/font-weight:\s*(\d+)/', $block, $wm)) {
                continue;
            }
            if (!preg_match('/src:\s*url\(([^)]+)\)/', $block, $um)) {
                continue;
            }

            $weight = $wm[1];
            $fontUrl = $um[1];
            $label = $weightLabels[$weight] ?? "w{$weight}";
            $outPath = $dir . '/' . $safeName . '-' . $label . '.ttf';

            if (file_exists($outPath)) {
                continue;
            }

            $fontResp = wp_remote_get($fontUrl, ['timeout' => 15]);
            if (!is_wp_error($fontResp)) {
                $fontData = wp_remote_retrieve_body($fontResp);
                if (strlen($fontData) > 1000) {
                    file_put_contents($outPath, $fontData);
                }
            }
        }
    }
}

// Cache invalidation on post save
add_action('save_post', function (int $post_id) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    WP_OG_Takumi_Renderer::invalidateCache($post_id);
});
