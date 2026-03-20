# WP OG Takumi

Dynamic Open Graph image generation for WordPress using [Takumi](https://github.com/kane50613/takumi) (Rust) via PHP FFI.

Write your OG image templates in HTML with Tailwind CSS classes. Takumi handles flexbox layout, text rendering, gradients, and outputs a PNG. No headless browser, no external API, no ImageMagick -- native speed, in-process.

![OG Image Example](https://github.com/user-attachments/assets/placeholder)

## How it works

```
Template (HTML + tw attributes + {{variables}})
    |
    v
PHP template engine
    |-- resolves template (per-post > per-type > global > file)
    |-- substitutes {{title}}, {{excerpt}}, etc. from WordPress
    |-- parses HTML into a JSON node tree
    |
    v
Rust FFI (Takumi)
    |-- deserializes JSON into Takumi Node tree
    |-- Takumi does flexbox layout + Tailwind styling + text shaping
    |-- outputs PNG bytes (1200x630)
    |
    v
Cached PNG served via REST endpoint
    GET /wp-json/wp-og-takumi/v1/og-image/{post_id}
```

On the frontend, `<meta property="og:image">` tags are injected into `wp_head` on singular pages, pointing at the REST endpoint.

## Template format

Templates are HTML with `tw` (Tailwind CSS) attributes. Takumi supports the full Tailwind utility set -- flexbox, spacing, typography, colors, gradients, opacity, etc.

```html
<div tw="w-[1200px] h-[630px] flex items-center justify-center"
     style="background: linear-gradient(135deg, #C4653A, #1B6B6D)">
  <div tw="flex flex-col items-center text-white p-16 text-center">
    <span tw="text-lg uppercase tracking-widest text-white/70 mb-4">{{post_type_label}}</span>
    <h1 tw="text-6xl font-bold text-white leading-tight">{{title}}</h1>
    <p tw="text-2xl text-white/80 mt-6">{{excerpt}}</p>
    <div tw="flex items-center mt-8 gap-4">
      <span tw="text-lg font-semibold text-white">{{site_name}}</span>
      <span tw="text-lg text-white/70">{{date}}</span>
    </div>
  </div>
</div>
```

This is the same syntax Takumi uses natively. The PHP side just parses the HTML into a JSON node tree and passes it through -- Takumi handles all layout and rendering.

### Available variables

| Variable | Source | Scope |
|---|---|---|
| `{{title}}` | Post title | All |
| `{{excerpt}}` | Excerpt (trimmed 160 chars) | All |
| `{{author}}` | Author display name | All |
| `{{date}}` | Formatted publish date | All |
| `{{post_type_label}}` | "Post", "Page", etc. | All |
| `{{site_name}}` | Blog name | All |
| `{{featured_image}}` | Featured image file path | All |
| `{{categories}}` | Comma-separated | Posts |

Add your own variables by editing `getVariables()` in `includes/class-og-template-engine.php`.

### Template cascade

Templates resolve in this order (first match wins):

1. Per-post meta (`_og_template` post meta, set via the editor meta box)
2. Per-post-type option (Settings > OG Images, one tab per post type)
3. Global default option (Settings > OG Images, "Global Default" tab)
4. File template (`templates/{post_type}.html`, then `templates/default.html`)

## Requirements

- Docker (for building the Rust shared library)
- WordPress 6+ with PHP 8.4+
- That's it. No local Rust toolchain needed.

## Setup (step by step)

### 1. Copy the plugin into your WordPress project

```bash
cp -r wp-og-takumi/ /path/to/your/wp-content/plugins/wp-og-takumi/
```

### 2. Download fonts

The plugin needs TTF font files for text rendering. A download script is included:

```bash
cd wp-content/plugins/wp-og-takumi/fonts
sh ../scripts/download-fonts.sh
```

This downloads static TTFs from Google Fonts (Playfair Display + Source Sans 3). To use different fonts, edit the script or drop your own `.ttf` files into `fonts/`.

### 3. Add the Dockerfile to your project root

Copy `docker/Dockerfile` to your project root (or adapt it into your existing Dockerfile):

```dockerfile
###############################################################################
# Stage 1: Compile the Rust shared library
###############################################################################
FROM rust:1.94-bookworm AS rust-builder

WORKDIR /build
COPY wp-content/plugins/wp-og-takumi/takumi-og-ffi/ .
COPY wp-content/plugins/wp-og-takumi/fonts/ /build/../fonts/

RUN cargo test --release
RUN cargo build --release \
    && cp target/release/libwp_og_takumi_ffi.so /build/libwp_og_takumi.so

###############################################################################
# Stage 2: WordPress with PHP FFI
###############################################################################
FROM wordpress:6-php8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libffi-dev \
    && docker-php-ext-install ffi \
    && rm -rf /var/lib/apt/lists/*

COPY --from=rust-builder /build/libwp_og_takumi.so /usr/local/lib/libwp_og_takumi.so
RUN ldconfig

RUN mkdir -p /var/www/html/wp-content/plugins/wp-og-takumi/lib
COPY --from=rust-builder /build/libwp_og_takumi.so \
     /var/www/html/wp-content/plugins/wp-og-takumi/lib/libwp_og_takumi.so

RUN echo "ffi.enable=true" > /usr/local/etc/php/conf.d/ffi.ini
```

### 4. Update docker-compose.yml

```yaml
services:
  wordpress:
    build: .  # instead of image: wordpress:...
    volumes:
      - ./wp-content/plugins/wp-og-takumi:/var/www/html/wp-content/plugins/wp-og-takumi
```

### 5. Build and start

```bash
docker compose build
docker compose up -d
```

The Docker build compiles the Rust library, runs its tests, and produces a WordPress image with the `.so` baked in. No Rust toolchain needed on your machine.

### 6. Activate the plugin

Go to WP Admin > Plugins > Activate "WP OG Takumi".

### 7. (Optional) Build the admin JS

If you want the CodeMirror 6 template editor in wp-admin:

```bash
cd wp-content/plugins/wp-og-takumi
npm install
npm run build
```

Without this, the plugin still works -- you just edit templates as raw HTML in textareas instead of a syntax-highlighted editor.

## Admin UI

**Settings > OG Images** -- tabbed interface with a CodeMirror 6 editor per post type:

- **Format** button -- prettifies the template HTML
- **Reset to Default** -- restores the file-based template
- **Insert Image** -- opens WordPress media library
- **Preview** -- renders the template to a real PNG and displays it inline

**Per-post meta box** (on any post/page edit screen):

- Check "Use custom OG template" to override the default for this specific post
- Same editor + toolbar
- Preview uses the actual post's data (title, excerpt, etc.)

## Adapting for your site

### Custom variables

Edit `getVariables()` in `includes/class-og-template-engine.php` to add variables for your custom post types:

```php
if ($post->post_type === 'product') {
    $vars['price'] = get_post_meta($post_id, '_price', true);
    $vars['sku'] = get_post_meta($post_id, '_sku', true);
}
```

Then use `{{price}}` and `{{sku}}` in your templates.

### Custom templates

Add a file at `templates/{post_type}.html` for any post type. The plugin picks it up automatically via the cascade.

### Custom fonts

Drop `.ttf` files into `fonts/`. The Rust renderer loads everything in that directory. Use the font family name in your templates:

```html
<h1 tw="text-6xl font-bold" style="font-family: 'My Custom Font'">{{title}}</h1>
```

Or if your theme uses the WordPress Customizer for font selection (via `get_theme_mod('og_takumi_font_heading')`), the plugin reads those settings automatically and downloads the Google Fonts TTFs on demand.

## Testing

Four test layers, run with `make`:

```bash
make test-php           # PHPUnit (host, no Docker needed)
make test-rust          # Rust unit tests (host, needs Rust)
make test-ffi           # FFI smoke test (Docker)
make test-integration   # Full WordPress integration (Docker)
make test               # All of the above
```

## How the Rust FFI works

The plugin uses PHP's built-in FFI extension to call a Rust shared library directly -- no subprocess, no HTTP, no CLI. The Rust side is a thin wrapper around [Takumi](https://github.com/kane50613/takumi):

```
PHP                          Rust (.so)
 |                            |
 |  og_render(json, fonts)    |
 |--------------------------->|
 |                            |  1. Deserialize JSON into Takumi Node tree
 |                            |  2. Parse tw="" strings into TailwindValues
 |                            |  3. Load .ttf fonts from directory
 |                            |  4. Takumi: flexbox layout + render
 |                            |  5. Encode to PNG bytes
 |  <-- PNG bytes             |
 |                            |
 |  og_free(ptr, len)         |
 |--------------------------->|  Free the PNG buffer
```

The JSON format maps 1:1 to Takumi's Node API:

```json
{
  "type": "container",
  "tw": "w-[1200px] h-[630px] flex items-center justify-center",
  "style": "background: linear-gradient(135deg, #C4653A, #1B6B6D)",
  "children": [
    {
      "type": "text",
      "content": "Hello World",
      "tw": "text-6xl font-bold text-white"
    }
  ]
}
```

Three node types: `container` (layout), `text` (text content), `image` (embedded image).

The Rust FFI crate is ~180 lines. The C header exposes three functions:

```c
uint8_t *og_render(const char *json_ptr, size_t json_len,
                   const char *font_dir_ptr, size_t font_dir_len,
                   size_t *out_len);
void og_free(uint8_t *ptr, size_t len);
const char *og_last_error(void);
```

## File structure

```
wp-og-takumi.php                Plugin bootstrap
includes/
  class-og-template-engine.php  Template cascade, variable extraction, HTML -> JSON
  class-og-renderer.php         PHP FFI bridge to Rust, PNG caching
  class-og-meta.php             wp_head OG/Twitter meta tags
  class-og-endpoint.php         REST: GET og-image/{id} + POST og-preview
  class-og-admin.php            Settings page + per-post meta box
src/
  og-admin.ts                   CodeMirror 6 editor + preview (built with @wordpress/scripts)
templates/
  default.html                  Fallback template
  tour.html                     Tour-specific (price, duration, location)
  post.html                     Blog posts (categories, author)
  page.html                     Pages (minimal)
  destination.html              Destination-specific
  guide.html                    Travel guide (author, date)
takumi-og-ffi/
  Cargo.toml                   Rust crate: takumi 1.0.0-beta.7, cdylib
  src/lib.rs                   FFI: JSON -> Takumi Node tree -> PNG
lib/
  takumi_og.h                  C header for PHP FFI
fonts/                         Static TTFs (downloaded via scripts/download-fonts.sh)
tests/                         PHPUnit, FFI smoke test, integration test
docker/
  Dockerfile                   Multi-stage: Rust builder + WordPress + PHP FFI
Makefile                       Build, test, font download commands
```

## License

MIT
