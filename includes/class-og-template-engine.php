<?php

defined('ABSPATH') || exit;

class WP_OG_Takumi_Template_Engine {

    /**
     * Resolve the template string for a given post, following the cascade:
     * 1. Per-post meta override
     * 2. Per-post-type option
     * 3. Global default option
     * 4. Hardcoded file template
     */
    public function resolveTemplate(int $post_id): string {
        $per_post = get_post_meta($post_id, '_og_template', true);
        if (is_string($per_post) && trim($per_post) !== '') {
            return $per_post;
        }

        $post_type = get_post_type($post_id);

        $per_type = get_option("wp_og_takumi_template_{$post_type}", '');
        if (is_string($per_type) && trim($per_type) !== '') {
            return $per_type;
        }

        $global = get_option('wp_og_takumi_default_template', '');
        if (is_string($global) && trim($global) !== '') {
            return $global;
        }

        $type_file = WP_OG_TAKUMI_PATH . "templates/{$post_type}.html";
        if (file_exists($type_file)) {
            return file_get_contents($type_file);
        }

        $default_file = WP_OG_TAKUMI_PATH . 'templates/default.html';
        if (file_exists($default_file)) {
            return file_get_contents($default_file);
        }

        return '';
    }

    /**
     * Extract template variables from a post.
     */
    public function getVariables(int $post_id): array {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }

        $post_type_obj = get_post_type_object($post->post_type);

        $vars = [
            'title'           => html_entity_decode(get_the_title($post_id), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'excerpt'         => $this->getExcerpt($post),
            'featured_image'  => $this->getFeaturedImagePath($post_id),
            'author'          => get_the_author_meta('display_name', $post->post_author),
            'date'            => get_the_date('', $post_id),
            'post_type_label' => $post_type_obj ? $post_type_obj->labels->singular_name : ucfirst($post->post_type),
            'site_name'       => html_entity_decode(get_bloginfo('name'), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ];

        if ($post->post_type === 'post') {
            $cats = get_the_category($post_id);
            $vars['categories'] = implode(', ', array_map(fn($c) => $c->name, $cats ?: []));
        }

        if ($post->post_type === 'tour') {
            $price = get_post_meta($post_id, '_tour_price_adult', true);
            $vars['price'] = $price ? '$' . number_format((float)$price, 0) : '';

            $duration_value = get_post_meta($post_id, '_tour_duration_value', true);
            $duration_unit = get_post_meta($post_id, '_tour_duration_unit', true);
            $vars['duration'] = $duration_value ? "{$duration_value} {$duration_unit}" : '';

            $vars['location'] = get_post_meta($post_id, '_tour_location', true) ?: '';

            $difficulty_terms = get_the_terms($post_id, 'difficulty');
            $vars['difficulty'] = ($difficulty_terms && !is_wp_error($difficulty_terms))
                ? $difficulty_terms[0]->name
                : '';
        }

        return $vars;
    }

    /**
     * Substitute {{variable}} placeholders in a template string.
     * Values are NOT HTML-escaped here — the node tree carries raw text,
     * and the Rust renderer handles display.
     */
    public function substitute(string $template, array $variables): string {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function ($matches) use ($variables) {
            $key = $matches[1];
            if (!array_key_exists($key, $variables)) {
                return '';
            }
            return (string)$variables[$key];
        }, $template);
    }

    /**
     * Parse an HTML template (after variable substitution) into a Takumi JSON node tree.
     *
     * The template uses div/span/h1/p/img elements with `tw` and `style` attributes.
     * This method converts that HTML into a JSON structure that the Rust FFI expects:
     *   { "type": "container"|"text"|"image", "tw": "...", "style": "...", "children": [...] }
     *
     * Takumi handles ALL layout (flexbox, spacing, sizing) and styling (colors, fonts,
     * opacity, gradients) natively from the tw/style values. No manual parsing needed.
     */
    public function toNodeTree(string $html): array {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        $doc = new \DOMDocument();
        $wrapped = '<?xml encoding="UTF-8"><div id="__og_root">' . $html . '</div>';
        libxml_use_internal_errors(true);
        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);
        libxml_clear_errors();

        $root = $doc->getElementById('__og_root');
        if (!$root) {
            return [];
        }

        // The root wrapper has one child: the actual template root element
        foreach ($root->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                return $this->convertNode($child);
            }
        }

        return [];
    }

    /**
     * Convert a DOM element into a Takumi node definition (array).
     */
    private function convertNode(\DOMNode $node): array {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = $node->textContent;
            if (trim($text) === '') {
                return [];
            }
            return ['type' => 'text', 'content' => $text];
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return [];
        }

        /** @var \DOMElement $node */
        $tag = strtolower($node->tagName);

        // Image element
        if ($tag === 'img') {
            $src = $node->getAttribute('src') ?: '';
            $src = $this->resolveImageSrc($src);
            $result = ['type' => 'image', 'src' => $src];
            $tw = $node->getAttribute('tw');
            if ($tw !== '') {
                $result['tw'] = $this->resolveTwUrls($tw);
            }
            $style = $node->getAttribute('style');
            if ($style !== '') {
                $result['style'] = $style;
            }
            return $result;
        }

        // Text-bearing elements: if the element has only text content (no child elements), treat as text node
        $hasChildElements = false;
        $textContent = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $hasChildElements = true;
                break;
            }
            if ($child->nodeType === XML_TEXT_NODE) {
                $textContent .= $child->textContent;
            }
        }

        if (!$hasChildElements && trim($textContent) !== '' && in_array($tag, ['h1','h2','h3','h4','h5','h6','span','p','a','strong','em','label'], true)) {
            $result = ['type' => 'text', 'content' => $textContent];
            $tw = $node->getAttribute('tw');
            if ($tw !== '') {
                $result['tw'] = $this->resolveTwUrls($tw);
            }
            $style = $node->getAttribute('style');
            if ($style !== '') {
                $result['style'] = $style;
            }
            return $result;
        }

        // Container element (div, or text element with children)
        $children = [];
        foreach ($node->childNodes as $child) {
            $converted = $this->convertNode($child);
            if (!empty($converted)) {
                $children[] = $converted;
            }
        }

        $result = ['type' => 'container'];
        $tw = $node->getAttribute('tw');
        if ($tw !== '') {
            $result['tw'] = $tw;
        }
        $style = $node->getAttribute('style');
        if ($style !== '') {
            $result['style'] = $style;
        }
        if (!empty($children)) {
            $result['children'] = $children;
        }

        return $result;
    }

    /**
     * Get the current theme fonts from the Customizer (or defaults).
     */
    public function getThemeFonts(): array {
        $heading = function_exists('get_theme_mod')
            ? get_theme_mod('og_takumi_font_heading', 'Playfair Display')
            : 'Playfair Display';
        $body = function_exists('get_theme_mod')
            ? get_theme_mod('og_takumi_font_body', 'Source Sans 3')
            : 'Source Sans 3';

        return ['heading' => $heading, 'body' => $body];
    }

    /**
     * Resolve any URLs in a tw attribute string (e.g. bg-[url(...)]) to local paths.
     */
    private function resolveTwUrls(string $tw): string {
        return preg_replace_callback('/bg-\[url\(([^)]+)\)\]/', function ($m) {
            $resolved = $this->resolveImageSrc($m[1]);
            return 'bg-[url(' . $resolved . ')]';
        }, $tw);
    }

    /**
     * Resolve an image src to a local file path.
     * Converts WordPress upload URLs to absolute filesystem paths.
     */
    private function resolveImageSrc(string $src): string {
        if ($src === '' || str_starts_with($src, '/')) {
            return $src; // Already a local path or empty
        }

        if (!function_exists('wp_upload_dir')) {
            return $src;
        }

        // Convert WordPress upload URL to local path
        // e.g. http://localhost:8080/wp-content/uploads/2026/03/photo.jpg
        //   -> /var/www/html/wp-content/uploads/2026/03/photo.jpg
        $upload = wp_upload_dir();
        $upload_url = $upload['baseurl']; // e.g. http://localhost:8080/wp-content/uploads
        $upload_dir = $upload['basedir']; // e.g. /var/www/html/wp-content/uploads

        if (str_starts_with($src, $upload_url)) {
            $relative = substr($src, strlen($upload_url));
            $local = $upload_dir . $relative;
            if (file_exists($local)) {
                return $local;
            }
        }

        // Try matching just the /wp-content/uploads/ part for any host
        if (preg_match('#/wp-content/uploads/(.+)$#', $src, $m)) {
            $local = $upload_dir . '/' . $m[1];
            if (file_exists($local)) {
                return $local;
            }
        }

        return $src;
    }

    private function getExcerpt(\WP_Post $post): string {
        $excerpt = $post->post_excerpt;
        if (empty($excerpt)) {
            $excerpt = wp_strip_all_tags($post->post_content);
        }
        if (mb_strlen($excerpt) > 160) {
            $excerpt = mb_substr($excerpt, 0, 157) . '...';
        }
        return $excerpt;
    }

    private function getFeaturedImagePath(int $post_id): string {
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if (!$thumbnail_id) {
            return '';
        }
        $path = get_attached_file($thumbnail_id);
        return $path ?: '';
    }
}
