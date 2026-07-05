# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/), and the project adheres to
[Semantic Versioning](https://semver.org/).

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
- PHPUnit unit tests and a CI smoke test that hits the canonical node's public
  API and asserts a block renders.

Depends on the Terraviz embed-URL grammar `v1` and wire schema `v1`.
