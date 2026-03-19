<?php

defined('ABSPATH') || exit;

class Hannies_OG_Admin {

    private static array $supported_post_types = ['post', 'page', 'tour', 'destination', 'guide'];

    public static function init(): void {
        add_action('admin_menu', [self::class, 'addSettingsPage']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('add_meta_boxes', [self::class, 'addMetaBox']);
        add_action('save_post', [self::class, 'saveMetaBox']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    public static function addSettingsPage(): void {
        add_options_page(
            'OG Images',
            'OG Images',
            'manage_options',
            'hannies-og',
            [self::class, 'renderSettingsPage']
        );
    }

    public static function registerSettings(): void {
        register_setting('hannies_og_settings', 'hannies_og_default_template', [
            'type'              => 'string',
            'sanitize_callback' => 'wp_kses_post',
        ]);

        foreach (self::$supported_post_types as $type) {
            register_setting('hannies_og_settings', "hannies_og_template_{$type}", [
                'type'              => 'string',
                'sanitize_callback' => 'wp_kses_post',
            ]);
        }
    }

    public static function renderSettingsPage(): void {
        $current_tab = sanitize_key($_GET['tab'] ?? 'default');
        $tabs = ['default' => 'Global Default'];

        foreach (self::$supported_post_types as $type) {
            $obj = get_post_type_object($type);
            $tabs[$type] = $obj ? $obj->labels->singular_name : ucfirst($type);
        }

        $option_key = $current_tab === 'default'
            ? 'hannies_og_default_template'
            : "hannies_og_template_{$current_tab}";

        $template_value = get_option($option_key, '');

        // Load file-based default for the current tab (for "Reset to default")
        $file_default = self::getFileTemplate($current_tab === 'default' ? 'default' : $current_tab);

        // If no saved value, show the file default in the editor
        if (empty(trim($template_value)) && !empty($file_default)) {
            $template_value = $file_default;
        }

        $variables = self::getVariableReference();
        ?>
        <div class="wrap">
            <h1>OG Image Templates</h1>

            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $slug => $label) : ?>
                    <a href="<?php echo esc_url(add_query_arg(['page' => 'hannies-og', 'tab' => $slug], admin_url('options-general.php'))); ?>"
                       class="nav-tab <?php echo $current_tab === $slug ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <form method="post" action="options.php">
                <?php settings_fields('hannies_og_settings'); ?>

                <div id="hannies-og-editor-wrap" style="margin-top: 20px;">
                    <textarea id="hannies-og-template" name="<?php echo esc_attr($option_key); ?>"
                              style="display:none;"><?php echo esc_textarea($template_value); ?></textarea>
                    <div id="hannies-og-cm-editor"></div>
                </div>

                <div style="margin-top: 12px; display: flex; gap: 8px; align-items: center;">
                    <button type="button" id="hannies-og-format-btn" class="button">Format</button>
                    <button type="button" id="hannies-og-reset-btn" class="button">Reset to Default</button>
                    <button type="button" id="hannies-og-media-btn" class="button">Insert Image</button>
                    <span style="flex: 1;"></span>
                    <button type="button" id="hannies-og-preview-btn" class="button button-secondary">Preview</button>
                </div>

                <div id="hannies-og-preview" style="margin-top: 15px; display: none;">
                    <img id="hannies-og-preview-img" style="max-width: 600px; border: 1px solid #ddd; border-radius: 4px;" />
                </div>

                <h3>Available Variables</h3>
                <table class="widefat" style="max-width: 600px;">
                    <thead><tr><th>Variable</th><th>Description</th><th>Scope</th></tr></thead>
                    <tbody>
                        <?php foreach ($variables as $var) : ?>
                            <tr>
                                <td><code>{{<?php echo esc_html($var['name']); ?>}}</code></td>
                                <td><?php echo esc_html($var['description']); ?></td>
                                <td><?php echo esc_html($var['scope']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public static function addMetaBox(): void {
        foreach (self::$supported_post_types as $type) {
            add_meta_box(
                'hannies_og_template',
                'OG Image Template',
                [self::class, 'renderMetaBox'],
                $type,
                'normal',
                'low'
            );
        }
    }

    public static function renderMetaBox(\WP_Post $post): void {
        wp_nonce_field('hannies_og_meta', 'hannies_og_nonce');

        $custom_template = get_post_meta($post->ID, '_og_template', true);
        $use_custom = !empty($custom_template);
        ?>
        <p>
            <label>
                <input type="checkbox" id="hannies-og-use-custom" name="hannies_og_use_custom"
                       value="1" <?php checked($use_custom); ?> />
                Use custom OG template for this post
            </label>
        </p>
        <div id="hannies-og-post-editor-wrap" style="<?php echo $use_custom ? '' : 'display:none;'; ?>">
            <textarea id="hannies-og-post-template" name="hannies_og_post_template"
                      style="display:none;"><?php echo esc_textarea($custom_template); ?></textarea>
            <div id="hannies-og-post-cm-editor"></div>
            <div style="margin-top: 10px; display: flex; gap: 8px; align-items: center;">
                <button type="button" id="hannies-og-post-format-btn" class="button">Format</button>
                <button type="button" id="hannies-og-post-reset-btn" class="button">Reset to Default</button>
                <button type="button" id="hannies-og-post-media-btn" class="button">Insert Image</button>
                <span style="flex: 1;"></span>
                <button type="button" id="hannies-og-post-preview-btn" class="button button-secondary">Preview</button>
            </div>
            <div id="hannies-og-post-preview" style="margin-top: 10px; display: none;">
                <img id="hannies-og-post-preview-img" style="max-width: 100%; border: 1px solid #ddd; border-radius: 4px;" />
            </div>
        </div>
        <?php
    }

    public static function saveMetaBox(int $post_id): void {
        if (!isset($_POST['hannies_og_nonce']) || !wp_verify_nonce($_POST['hannies_og_nonce'], 'hannies_og_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (!empty($_POST['hannies_og_use_custom']) && !empty($_POST['hannies_og_post_template'])) {
            update_post_meta($post_id, '_og_template', wp_kses_post($_POST['hannies_og_post_template']));
        } else {
            delete_post_meta($post_id, '_og_template');
        }
    }

    public static function enqueueAssets(string $hook): void {
        $is_settings = $hook === 'settings_page_hannies-og';
        $is_post_edit = in_array($hook, ['post.php', 'post-new.php'], true);

        if (!$is_settings && !$is_post_edit) {
            return;
        }

        $asset_file = HANNIES_OG_PATH . 'build/og-admin.asset.php';
        if (!file_exists($asset_file)) {
            return;
        }

        $asset = require $asset_file;

        wp_enqueue_script(
            'hannies-og-admin',
            HANNIES_OG_URL . 'build/og-admin.js',
            $asset['dependencies'] ?? [],
            $asset['version'] ?? HANNIES_OG_VERSION,
            true
        );

        $css_file = HANNIES_OG_PATH . 'build/og-admin.css';
        if (file_exists($css_file)) {
            wp_enqueue_style(
                'hannies-og-admin',
                HANNIES_OG_URL . 'build/og-admin.css',
                [],
                $asset['version'] ?? HANNIES_OG_VERSION
            );
        }

        // Minimal inline styles for the editor wrapper (CM6 injects its own styles via JS)
        wp_add_inline_style('wp-admin', '
            #hannies-og-cm-editor,
            #hannies-og-post-cm-editor {
                border: 1px solid #3c434a;
                border-radius: 4px;
                overflow: hidden;
            }
            #hannies-og-cm-editor .cm-editor,
            #hannies-og-post-cm-editor .cm-editor {
                min-height: 280px;
            }
        ');

        // Enqueue WP media library for image picker
        wp_enqueue_media();

        // Determine default template to pass to JS
        $default_template = '';
        $post_id = 0;
        $context = 'settings';

        if ($is_settings) {
            $tab = sanitize_key($_GET['tab'] ?? 'default');
            $default_template = self::getFileTemplate($tab === 'default' ? 'default' : $tab);
        } elseif ($is_post_edit) {
            global $post;
            $context = 'post';
            if ($post) {
                $post_id = $post->ID;
                $default_template = self::getFileTemplate($post->post_type);
            }
        }

        wp_localize_script('hannies-og-admin', 'hanniesOg', [
            'restUrl'         => rest_url('hannies/v1/'),
            'nonce'           => wp_create_nonce('wp_rest'),
            'defaultTemplate' => $default_template,
            'postId'          => $post_id,
            'context'         => $context,
        ]);
    }

    /**
     * Load the file-based template for a post type (or 'default').
     */
    private static function getFileTemplate(string $type): string {
        $file = HANNIES_OG_PATH . "templates/{$type}.html";
        if (file_exists($file)) {
            return file_get_contents($file);
        }
        $default = HANNIES_OG_PATH . 'templates/default.html';
        if (file_exists($default)) {
            return file_get_contents($default);
        }
        return '';
    }

    private static function getVariableReference(): array {
        return [
            ['name' => 'title', 'description' => 'Post title', 'scope' => 'All'],
            ['name' => 'excerpt', 'description' => 'Excerpt (trimmed to 160 chars)', 'scope' => 'All'],
            ['name' => 'featured_image', 'description' => 'Featured image file path', 'scope' => 'All'],
            ['name' => 'author', 'description' => 'Author display name', 'scope' => 'All'],
            ['name' => 'date', 'description' => 'Formatted publish date', 'scope' => 'All'],
            ['name' => 'post_type_label', 'description' => '"Tour", "Page", etc.', 'scope' => 'All'],
            ['name' => 'site_name', 'description' => 'Blog name', 'scope' => 'All'],
            ['name' => 'categories', 'description' => 'Comma-separated categories', 'scope' => 'Posts'],
            ['name' => 'price', 'description' => 'Adult tour price', 'scope' => 'Tours'],
            ['name' => 'duration', 'description' => 'Duration value + unit', 'scope' => 'Tours'],
            ['name' => 'location', 'description' => 'Tour location', 'scope' => 'Tours'],
            ['name' => 'difficulty', 'description' => 'Difficulty taxonomy term', 'scope' => 'Tours'],
        ];
    }
}
