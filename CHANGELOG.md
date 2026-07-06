# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/), and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

Phase 2 — WordPress account mapping (still no catalog writes).

### Added
- Custom `manage_terraviz` capability, granted to administrators on activation
  and cleaned up on deactivation/uninstall, so a site owner can delegate
  Terraviz configuration without full `manage_options`.
- WP-role → publish-intent mapping (`Support/Capabilities.php`), shown
  read-only on the settings screen. This is local authorization: a future
  publish path proxies every call under one shared Terraviz `service` identity,
  so WordPress — not Terraviz — gates who may act through the plugin.
- Inert, encrypted **service-token** slot in settings: a Cloudflare Access
  `Cf-Access-Client-Id` + `Cf-Access-Client-Secret` pair. The client id is
  stored in the clear; the secret is encrypted at rest (libsodium `secretbox`,
  OpenSSL AES-256-GCM fallback) and never returned to the browser.
- Read-only "Verify credential" probe (`GET /api/v1/publish/me`) that validates
  the stored token — reporting the recognised role/status — without mutating
  any Terraviz content. No publish path exists yet.
- Distributable plugin packaging: `bin/build-zip.sh` produces an installable
  `terraviz` ZIP (bundling the compiled blocks, stripping dev files via
  `.distignore`), surfaced as a downloadable CI artifact on every run and as a
  versioned asset on tagged GitHub Releases (`.github/workflows/release.yml`).
  A `bin/check-version.sh` guard fails the build if the version disagrees across
  its sources. See `docs/RELEASING.md`.

### Fixed
- Plugin activation/deactivation no longer fatals when a caller (e.g. WP-CLI)
  invokes the `activate_terraviz` / `deactivate_terraviz` hook with `null`
  instead of a boolean — the lifecycle callbacks now accept a nullable flag.

## [0.1.0] - Unreleased

Phase 1 — the zero-credential embed plugin.

### Added
- Dataset, Tour, and Catalog Gutenberg blocks, each with a server-side
  rendered, accessible, indexable fallback and a lazily-loaded globe iframe.
- `[terraviz]` shortcode and automatic Terraviz URL embeds, sharing one PHP
  renderer with the blocks.
- Settings screen: node origin (default canonical), default embed options,
  loading/telemetry posture, and a public "test connection" probe. No
  credential field.
- Catalog and dataset caching in WordPress transients.
- PHP wire-contract value objects generated from Terraviz's published
  `/schema/v1` JSON Schema via `bin/generate-contracts.php`.
- Block-editor typeahead picker: search datasets/tours by title (backed by a
  same-origin, `edit_posts`-gated REST endpoint over the cached catalog), store
  the id automatically, with a "Copy shortcode" button for Classic authors.
- Right-Now Hero block: embeds the node's curated featured dataset
  (`/api/v1/featured-hero`), updated automatically.
- Related Datasets block: a "more like this" card rail from
  `/api/v1/datasets/:id/related`. Both also available as `[terraviz hero]` and
  `[terraviz related="…"]` shortcodes.
- PHPUnit unit tests and a CI smoke test that hits the canonical node's public
  API and asserts a block renders.

Depends on the Terraviz embed-URL grammar `v1` and wire schema `v1`.
