PLUGIN_DIR := $(dir $(abspath $(lastword $(MAKEFILE_LIST))))
PROJECT_ROOT := $(abspath $(PLUGIN_DIR)/../../..)
DOCKER_COMPOSE := docker compose -f $(PROJECT_ROOT)/docker-compose.yml

.PHONY: all test test-php test-rust test-ffi test-integration build fonts clean help

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

all: fonts build test ## Full pipeline: fonts, build, all tests

# ─── Fonts ───────────────────────────────────────────────────────────────────

fonts: fonts/PlayfairDisplay-Regular.ttf ## Download bundled fonts from Google Fonts

fonts/PlayfairDisplay-Regular.ttf:
	@echo "==> Downloading fonts..."
	@mkdir -p fonts
	@cd fonts && sh ../scripts/download-fonts.sh

# ─── Build ───────────────────────────────────────────────────────────────────

build: ## Build Docker image (compiles Rust .so + enables PHP FFI)
	@echo "==> Building Docker image (Rust compile + WordPress + FFI)..."
	$(DOCKER_COMPOSE) build wordpress

# ─── Tests ───────────────────────────────────────────────────────────────────

test: test-php test-rust test-ffi test-integration ## Run all tests

test-php: ## Run PHPUnit tests (host, no Docker needed)
	@echo "==> Running PHP unit tests..."
	@cd $(PLUGIN_DIR) && vendor/bin/phpunit

test-rust: ## Run Rust unit tests (host, needs Rust toolchain)
	@echo "==> Running Rust unit tests..."
	@cd $(PLUGIN_DIR)/takumi-og-ffi && cargo test --release

test-rust-docker: ## Run Rust tests inside Docker (no local Rust needed)
	@echo "==> Running Rust tests in Docker..."
	docker run --rm -v $(PLUGIN_DIR)/takumi-og-ffi:/build -w /build rust:1.90-bookworm cargo test --release

test-ffi: build ## FFI smoke test: PHP loads .so and renders SVG → PNG inside Docker
	@echo "==> Running FFI smoke test in Docker..."
	$(DOCKER_COMPOSE) exec -T wordpress php /var/www/html/wp-content/plugins/hannies-og/tests/ffi-smoke-test.php

test-integration: build ## Integration tests: REST endpoint + OG meta tags inside Docker
	@echo "==> Running integration tests..."
	$(DOCKER_COMPOSE) exec -T wordpress php /var/www/html/wp-content/plugins/hannies-og/tests/integration-test.php

# ─── Utilities ───────────────────────────────────────────────────────────────

clean: ## Remove build artifacts and caches
	@rm -rf takumi-og-ffi/target .phpunit.cache
	@echo "Cleaned."

up: build ## Start all Docker services
	$(DOCKER_COMPOSE) up -d

down: ## Stop Docker services
	$(DOCKER_COMPOSE) down
