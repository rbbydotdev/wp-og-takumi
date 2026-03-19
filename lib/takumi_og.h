/* hannies-og-ffi C header */

#ifndef HANNIES_OG_FFI_H
#define HANNIES_OG_FFI_H

#include <stddef.h>
#include <stdint.h>

/**
 * Render a JSON node tree to PNG bytes using Takumi.
 *
 * json_ptr     - Pointer to a UTF-8 JSON string describing the node tree
 * json_len     - Length of the JSON string in bytes
 * font_dir_ptr - Pointer to a UTF-8 font directory path (or NULL)
 * font_dir_len - Length of the font directory path (or 0)
 * out_len      - Pointer to a size_t that will receive the output PNG length
 *
 * Returns pointer to the output PNG buffer, or NULL on error.
 * The caller must free the buffer with og_free().
 */
uint8_t *og_render(const char *json_ptr, size_t json_len,
                   const char *font_dir_ptr, size_t font_dir_len,
                   size_t *out_len);

/**
 * Free a buffer previously returned by og_render().
 */
void og_free(uint8_t *ptr, size_t len);

/**
 * Get the last error message.
 * Returns pointer to a null-terminated C string, or NULL if no error.
 * Valid until the next call to og_render().
 * The caller must NOT free the returned pointer.
 */
const char *og_last_error(void);

#endif /* HANNIES_OG_FFI_H */
