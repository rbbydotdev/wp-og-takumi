<?php

defined('ABSPATH') || exit;

class Hannies_OG_Meta {

    public static function init(): void {
        add_action('wp_head', [self::class, 'outputMetaTags'], 1);
    }

    public static function outputMetaTags(): void {
        if (!is_singular()) {
            return;
        }

        $post_id = get_queried_object_id();
        if (!$post_id) {
            return;
        }

        $image_url = rest_url("hannies/v1/og-image/{$post_id}");
        $width = 1200;
        $height = 630;

        echo '<meta property="og:image" content="' . esc_url($image_url) . '" />' . "\n";
        echo '<meta property="og:image:width" content="' . esc_attr($width) . '" />' . "\n";
        echo '<meta property="og:image:height" content="' . esc_attr($height) . '" />' . "\n";
        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        echo '<meta name="twitter:image" content="' . esc_url($image_url) . '" />' . "\n";
    }
}
