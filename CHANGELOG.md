# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/), and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

Phase 3a — the authenticated publisher dashboard (dataset CRUD/lifecycle).

### Added
- Server-side **publish proxy**: `PublishClient` now performs the full dataset
  write set (list/create/update/publish/retract/delete) against the Terraviz
  publish API, attaching the stored Cloudflare Access service token in PHP so
  it never reaches the browser.
- Same-origin REST proxy `terraviz/v1/publisher/datasets*`, gated by the
  Phase-2 capability tiers (draft tier for create/edit, publish tier for
  publish/retract/delete) plus a credential-configured check. The dataset body
  is reduced to an allowlist of known fields before it is forwarded; the node
  remains the authoritative validator.
- A **wp-admin publisher dashboard** (a React `@wordpress/element` app): list
  datasets by lifecycle state, create/edit drafts with inline field-validation
  errors, and publish/retract/delete — all proxied server-side. Actions are
  attributed to the shared `service` identity (upstream limitation), surfaced
  in the UI.

## [Unreleased — Phase 2]

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
