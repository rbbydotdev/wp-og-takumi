<?php

defined('ABSPATH') || exit;

class Hannies_OG_Endpoint {

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    public static function registerRoutes(): void {
        // Public: serve cached OG images
        register_rest_route('hannies/v1', '/og-image/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleRequest'],
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [
                    'validate_callback' => fn($val) => is_numeric($val) && (int)$val > 0,
                    'sanitize_callback' => fn($val) => (int)$val,
                ],
            ],
        ]);

        // Admin-only: live preview from template HTML
        register_rest_route('hannies/v1', '/og-preview', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handlePreview'],
            'permission_callback' => fn() => current_user_can('manage_options'),
            'args'                => [
                'template' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'wp_kses_post',
                ],
                'post_id' => [
                    'type'              => 'integer',
                    'default'           => 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    public static function handleRequest(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $post_id = (int)$request->get_param('id');

        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            return new \WP_Error(
                'og_not_found',
                'Post not found',
                ['status' => 404]
            );
        }

        try {
            $renderer = new Hannies_OG_Renderer();
            $cache_path = $renderer->renderCached($post_id);

            $image_data = file_get_contents($cache_path);
            if ($image_data === false) {
                throw new \RuntimeException('Failed to read cached image');
            }

            header('Content-Type: image/png');
            header('Content-Length: ' . strlen($image_data));
            header('Cache-Control: public, max-age=86400');
            header('X-OG-Cache: hit');
            echo $image_data;
            exit;
        } catch (\Throwable $e) {
            return new \WP_Error(
                'og_render_error',
                'Failed to generate OG image: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Live preview: render a template string to PNG and return it.
     * Optionally substitutes variables from a real post (post_id).
     */
    public static function handlePreview(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $template_html = $request->get_param('template');
        $post_id = (int)$request->get_param('post_id');

        try {
            $engine = new Hannies_OG_Template_Engine();

            // Substitute variables from a real post, or use sample data
            if ($post_id > 0) {
                $variables = $engine->getVariables($post_id);
            } else {
                $variables = [
                    'title'           => 'Sample Post Title',
                    'excerpt'         => 'This is a preview of your OG image template with sample content.',
                    'featured_image'  => '',
                    'author'          => 'Admin',
                    'date'            => wp_date('F j, Y'),
                    'post_type_label' => 'Post',
                    'site_name'       => get_bloginfo('name'),
                    'categories'      => 'Travel, Adventure',
                    'price'           => '$199',
                    'duration'        => '3 days',
                    'location'        => 'Bangkok',
                    'difficulty'      => 'Moderate',
                ];
            }

            $html = $engine->substitute($template_html, $variables);
            $nodeTree = $engine->toNodeTree($html);
            $json = json_encode($nodeTree, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            $renderer = new Hannies_OG_Renderer();
            $png_bytes = $renderer->render($json);

            header('Content-Type: image/png');
            header('Content-Length: ' . strlen($png_bytes));
            header('Cache-Control: no-cache');
            echo $png_bytes;
            exit;
        } catch (\Throwable $e) {
            return new \WP_Error(
                'og_preview_error',
                'Preview failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }
}
