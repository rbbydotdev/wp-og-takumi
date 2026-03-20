use std::borrow::Cow;
use std::collections::HashMap;
use std::os::raw::c_char;
use std::ptr;
use std::sync::Mutex;

use base64::Engine;
use serde::Deserialize;
use takumi::{
    GlobalContext,
    layout::{Viewport, node::Node, style::Style},
    rendering::{ImageOutputFormat, RenderOptions, render, write_image},
    resources::font::FontResource,
};

static LAST_ERROR: Mutex<Option<String>> = Mutex::new(None);

fn set_error(msg: String) {
    if let Ok(mut err) = LAST_ERROR.lock() {
        *err = Some(msg);
    }
}

// ---------------------------------------------------------------------------
// Inline CSS string -> Style conversion
// ---------------------------------------------------------------------------

/// Parse an inline CSS string like "background: linear-gradient(...); color: red"
/// into a Takumi `Style` by converting to a JSON map and deserializing.
fn parse_inline_style(css: &str) -> Style {
    let mut map: HashMap<&str, &str> = HashMap::new();

    for decl in css.split(';') {
        let decl = decl.trim();
        if decl.is_empty() {
            continue;
        }
        // Split only on the first colon to preserve colons in values
        if let Some(colon_pos) = decl.find(':') {
            let key = decl[..colon_pos].trim();
            let value = decl[colon_pos + 1..].trim();
            if !key.is_empty() && !value.is_empty() {
                map.insert(key, value);
            }
        }
    }

    if map.is_empty() {
        return Style::default();
    }

    // Serialize to JSON and deserialize as Style
    match serde_json::to_value(&map).and_then(|v| serde_json::from_value(v)) {
        Ok(style) => style,
        Err(_) => Style::default(),
    }
}

// ---------------------------------------------------------------------------
// JSON node tree deserialization
// ---------------------------------------------------------------------------

#[derive(Deserialize)]
#[serde(tag = "type", rename_all = "lowercase")]
enum NodeDef {
    Container {
        #[serde(default)]
        tw: Option<String>,
        #[serde(default)]
        style: Option<String>,
        #[serde(default)]
        children: Vec<NodeDef>,
    },
    Text {
        content: String,
        #[serde(default)]
        tw: Option<String>,
        #[serde(default)]
        style: Option<String>,
    },
    Image {
        src: String,
        #[serde(default)]
        tw: Option<String>,
        #[serde(default)]
        style: Option<String>,
    },
}

fn apply_tw_and_style(
    node: Node,
    tw: Option<String>,
    style: Option<String>,
) -> Result<Node, String> {
    let mut node = node;
    if let Some(tw_str) = tw {
        node = node.with_tw(
            tw_str.parse().map_err(|e: String| format!("tw parse error: {e}"))?
        );
    }
    if let Some(style_str) = style {
        node = node.with_style(parse_inline_style(&style_str));
    }
    Ok(node)
}

/// Resolve an image src: if it's a local file path, read bytes and convert to data URI.
/// If it's already a URL or data URI, pass through unchanged.
fn resolve_image_src(src: &str) -> Result<String, String> {
    // Already a data URI or http(s) URL — pass through
    if src.starts_with("data:") || src.starts_with("http://") || src.starts_with("https://") {
        return Ok(src.to_string());
    }

    // Treat as local file path
    let path = std::path::Path::new(src);
    if !path.exists() {
        return Err(format!("Image file not found: {src}"));
    }

    let data = std::fs::read(path)
        .map_err(|e| format!("Failed to read image {src}: {e}"))?;

    // Detect MIME type from magic bytes
    let mime = match &data[..4.min(data.len())] {
        [0x89, 0x50, 0x4E, 0x47] => "image/png",
        [0xFF, 0xD8, ..] => "image/jpeg",
        [0x47, 0x49, 0x46, ..] => "image/gif",
        [0x52, 0x49, 0x46, 0x46] => "image/webp",
        _ => "application/octet-stream",
    };

    let b64 = base64::engine::general_purpose::STANDARD.encode(&data);
    Ok(format!("data:{mime};base64,{b64}"))
}

fn build_node(def: NodeDef) -> Result<Node, String> {
    match def {
        NodeDef::Container { tw, style, children } => {
            let child_nodes: Result<Vec<Node>, String> =
                children.into_iter().map(build_node).collect();
            let node = Node::container(child_nodes?);
            apply_tw_and_style(node, tw, style)
        }
        NodeDef::Text { content, tw, style } => {
            let node = Node::text(content);
            apply_tw_and_style(node, tw, style)
        }
        NodeDef::Image { src, tw, style } => {
            let image_src = resolve_image_src(&src)?;
            let node = Node::image(image_src.as_str());
            apply_tw_and_style(node, tw, style)
        }
    }
}

// ---------------------------------------------------------------------------
// Core render function
// ---------------------------------------------------------------------------

fn render_node_tree(json_str: &str, font_dir: Option<&str>) -> Result<Vec<u8>, String> {
    if json_str.is_empty() {
        return Err("Empty input".into());
    }

    let node_def: NodeDef =
        serde_json::from_str(json_str).map_err(|e| format!("JSON parse error: {e}"))?;

    let root = build_node(node_def)?;

    // Build context and load fonts
    let mut context = GlobalContext::default();
    if let Some(dir) = font_dir {
        let font_path = std::path::Path::new(dir);
        if font_path.is_dir() {
            if let Ok(entries) = std::fs::read_dir(font_path) {
                for entry in entries.flatten() {
                    let path = entry.path();
                    match path.extension().and_then(|e| e.to_str()) {
                        Some("ttf" | "otf" | "woff" | "woff2") => {
                            if let Ok(data) = std::fs::read(&path) {
                                let _ = context
                                    .font_context_mut()
                                    .load_and_store(FontResource::new(data));
                            }
                        }
                        _ => {}
                    }
                }
            }
        }
    }

    let options = RenderOptions::builder()
        .viewport(Viewport::new((1200, 630)))
        .global(&context)
        .node(root)
        .build();

    let image = render(options).map_err(|e| format!("Render error: {e}"))?;

    let mut png_buf: Vec<u8> = Vec::new();
    write_image(Cow::Owned(image), &mut png_buf, ImageOutputFormat::Png, None)
        .map_err(|e| format!("PNG encode error: {e}"))?;

    if png_buf.len() < 8 {
        return Err("Rendered PNG is too small".into());
    }

    Ok(png_buf)
}

// ---------------------------------------------------------------------------
// FFI exports
// ---------------------------------------------------------------------------

/// Render a JSON node tree to PNG bytes.
///
/// # Safety
/// `json_ptr` must point to valid UTF-8 of at least `json_len` bytes.
/// `out_len` must point to a valid, writable `size_t`.
/// `font_dir_ptr` may be null (no custom fonts loaded).
#[no_mangle]
pub unsafe extern "C" fn og_render(
    json_ptr: *const c_char,
    json_len: usize,
    font_dir_ptr: *const c_char,
    font_dir_len: usize,
    out_len: *mut usize,
) -> *mut u8 {
    if json_ptr.is_null() || out_len.is_null() {
        set_error("Null pointer argument".into());
        return ptr::null_mut();
    }

    let json_slice = unsafe { std::slice::from_raw_parts(json_ptr as *const u8, json_len) };
    let json_str = match std::str::from_utf8(json_slice) {
        Ok(s) => s,
        Err(e) => {
            set_error(format!("Invalid UTF-8 input: {e}"));
            return ptr::null_mut();
        }
    };

    let font_dir = if !font_dir_ptr.is_null() && font_dir_len > 0 {
        let slice = unsafe { std::slice::from_raw_parts(font_dir_ptr as *const u8, font_dir_len) };
        std::str::from_utf8(slice).ok().map(|s| s.to_string())
    } else {
        None
    };

    match render_node_tree(json_str, font_dir.as_deref()) {
        Ok(bytes) => {
            let len = bytes.len();
            let boxed = bytes.into_boxed_slice();
            let ptr = Box::into_raw(boxed) as *mut u8;
            unsafe { *out_len = len };
            ptr
        }
        Err(e) => {
            set_error(e);
            ptr::null_mut()
        }
    }
}

/// Free a buffer previously returned by `og_render`.
///
/// # Safety
/// `ptr` must have been returned by `og_render`, `len` must match `out_len`.
#[no_mangle]
pub unsafe extern "C" fn og_free(ptr: *mut u8, len: usize) {
    if !ptr.is_null() && len > 0 {
        let _ = unsafe { Box::from_raw(std::slice::from_raw_parts_mut(ptr, len)) };
    }
}

/// Get the last error message (null-terminated C string, or NULL).
/// Valid until the next call to `og_render`. Do not free.
///
/// # Safety
/// Returns an internal pointer -- do not free or write to it.
#[no_mangle]
pub unsafe extern "C" fn og_last_error() -> *const c_char {
    thread_local! {
        static ERROR_BUF: std::cell::RefCell<Option<std::ffi::CString>> =
            const { std::cell::RefCell::new(None) };
    }

    let msg = LAST_ERROR.lock().ok().and_then(|mut e| e.take());

    ERROR_BUF.with(|buf| {
        let cstr = msg.and_then(|m| std::ffi::CString::new(m).ok());
        let ptr = cstr.as_ref().map_or(ptr::null(), |c| c.as_ptr());
        *buf.borrow_mut() = cstr;
        ptr
    })
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

#[cfg(test)]
mod tests {
    use super::*;

    fn fonts_dir() -> String {
        let manifest = std::env::var("CARGO_MANIFEST_DIR").unwrap();
        format!("{manifest}/../fonts")
    }

    #[test]
    fn test_render_simple() {
        let json = r#"{
            "type": "container",
            "tw": "w-[200px] h-[100px] flex items-center justify-center bg-[#FF7F50]",
            "children": [
                { "type": "text", "content": "Hello", "tw": "text-xl text-white" }
            ]
        }"#;

        let result = render_node_tree(json, Some(&fonts_dir()));
        assert!(result.is_ok(), "Render failed: {:?}", result.err());

        let png = result.unwrap();
        assert_eq!(&png[0..4], &[0x89, 0x50, 0x4E, 0x47], "Not valid PNG");
        assert!(png.len() > 100, "PNG too small: {} bytes", png.len());
    }

    #[test]
    fn test_render_og_sized() {
        let json = r#"{
            "type": "container",
            "tw": "w-[1200px] h-[630px] flex items-center justify-center",
            "style": "background: linear-gradient(135deg, #C4653A, #1B6B6D)",
            "children": [
                {
                    "type": "container",
                    "tw": "flex flex-col items-center text-center p-16",
                    "children": [
                        { "type": "text", "content": "TOUR", "tw": "text-lg tracking-widest text-white mb-4" },
                        { "type": "text", "content": "Bangkok Explorer", "tw": "text-6xl font-bold text-white" },
                        { "type": "text", "content": "Discover Bangkok", "tw": "text-2xl text-white mt-6" }
                    ]
                }
            ]
        }"#;

        let result = render_node_tree(json, Some(&fonts_dir()));
        assert!(result.is_ok(), "OG render failed: {:?}", result.err());

        let png = result.unwrap();
        assert_eq!(&png[0..4], &[0x89, 0x50, 0x4E, 0x47]);
        assert!(png.len() > 1000, "OG PNG too small: {} bytes", png.len());
    }

    #[test]
    fn test_ffi_roundtrip() {
        let json = r#"{
            "type": "container",
            "tw": "w-[100px] h-[50px] bg-blue-500"
        }"#;
        let font_dir = fonts_dir();

        unsafe {
            let mut out_len: usize = 0;
            let ptr = og_render(
                json.as_ptr() as *const c_char,
                json.len(),
                font_dir.as_ptr() as *const c_char,
                font_dir.len(),
                &mut out_len as *mut usize,
            );

            assert!(!ptr.is_null(), "FFI render returned null");
            assert!(out_len > 0, "FFI output length is zero");

            // Check PNG magic
            let slice = std::slice::from_raw_parts(ptr, out_len);
            assert_eq!(&slice[0..4], &[0x89, 0x50, 0x4E, 0x47]);

            og_free(ptr, out_len);
        }
    }

    #[test]
    fn test_ffi_error_handling() {
        unsafe {
            let bad = "not json at all {{{";
            let mut out_len: usize = 0;
            let ptr = og_render(
                bad.as_ptr() as *const c_char,
                bad.len(),
                ptr::null(),
                0,
                &mut out_len as *mut usize,
            );

            assert!(ptr.is_null(), "Expected null for invalid JSON");

            let err = og_last_error();
            assert!(!err.is_null(), "Expected error message");
            let msg = std::ffi::CStr::from_ptr(err).to_str().unwrap();
            assert!(!msg.is_empty(), "Error message is empty");
        }
    }

    #[test]
    fn test_render_empty_errors() {
        let result = render_node_tree("", None);
        assert!(result.is_err());
    }

    #[test]
    fn test_render_invalid_errors() {
        let result = render_node_tree("<not-json>garbage</not-json>", None);
        assert!(result.is_err());
    }
}
