###############################################################################
# Stage 1: Compile the Rust FFI shared library (Takumi → PNG renderer)
###############################################################################
FROM rust:1.94-bookworm AS rust-builder

WORKDIR /build
COPY wp-content/plugins/wp-og-takumi/takumi-og-ffi/ .

# Copy fonts into the build context so Rust tests can find them
COPY wp-content/plugins/wp-og-takumi/fonts/ /build/../fonts/

# Run Rust tests first — gate the build on correctness
RUN cargo test --release 2>&1

# Build the shared library
RUN cargo build --release \
    && cp target/release/libwp_og_takumi_ffi.so /build/libwp_og_takumi.so

###############################################################################
# Stage 2: WordPress with PHP FFI + the compiled .so
###############################################################################
FROM wordpress:6-php8.4-apache

# Install PHP FFI extension
RUN apt-get update \
    && apt-get install -y --no-install-recommends libffi-dev \
    && docker-php-ext-install ffi \
    && rm -rf /var/lib/apt/lists/*

# Copy the compiled shared library from the builder stage
COPY --from=rust-builder /build/libwp_og_takumi.so /usr/local/lib/libwp_og_takumi.so
RUN ldconfig

# Also copy into the plugin's lib/ directory (so PHP FFI can find it by path)
RUN mkdir -p /var/www/html/wp-content/plugins/wp-og-takumi/lib
COPY --from=rust-builder /build/libwp_og_takumi.so /var/www/html/wp-content/plugins/wp-og-takumi/lib/libwp_og_takumi.so

# Enable FFI in PHP
RUN echo "ffi.enable=true" > /usr/local/etc/php/conf.d/ffi.ini
