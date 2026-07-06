# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/), and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.4.0] - 2026-07-06

Phase 4 — post/blog bridge (one-way WP → Terraviz discovery).

### Added
- Opt a published WordPress post into "Show this post in Terraviz" from a
  block-editor document panel (publish-tier users). On save, the plugin syncs a
  Terraviz blog **stub** — a short markdown summary plus a canonical link back
  to the WP post — carrying the datasets/tours cited by the Terraviz blocks in
  the post. WordPress stays the source of truth; there is no two-way body sync.
- The sync owns idempotency (the blog API has none): it stores the Terraviz
  post id in WP post meta and updates in place on re-sync, recreating only if
  the stub was removed upstream. Remote calls are deferred to a near-immediate
  cron event so saving a post never blocks on the network. Turning the toggle
  off, unpublishing, or deleting the post takes the stub down.

## [0.3.0] - 2026-07-06

Phases 2–3 — WordPress account mapping and the authenticated publisher dashboard.

### Added
- **Publisher dashboard** (wp-admin, a React `@wordpress/element` app): list
  datasets by lifecycle state, create/edit drafts with inline field-validation
  errors, and publish/retract/delete — all proxied server-side. Actions are
  attributed to the shared `service` identity (upstream limitation), surfaced
  in the UI.
- Server-side **publish proxy**: `PublishClient` performs the full dataset write
  set (list/create/update/publish/retract/delete) against the Terraviz publish
  API, attaching the stored Cloudflare Access service token in PHP so it never
  reaches the browser. Same-origin REST proxy `terraviz/v1/publisher/datasets*`,
  gated by capability tiers (draft tier for create/edit drafts; publish tier for
  publish/retract/delete and for editing an already-published dataset) plus a
  credential-configured check, with an allowlisted dataset body; the node stays
  the authoritative validator.
- **Asset upload** through the presigned-R2 flow: the browser hashes the file
  (`sha256`), the plugin proxies `init` to mint a short-lived presigned `PUT`,
  the browser uploads the bytes **directly** to storage, and the plugin proxies
  `complete` (the node re-verifies the digest and sets the dataset's ref). The
  service token never touches the upload; video transcodes asynchronously
  (HTTP 202). REST `POST /publisher/datasets/:id/asset` and
  `.../asset/:upload_id/complete`. The direct byte upload is cross-origin, so the
  node's storage bucket must allow the WordPress site's origin (CORS).
- Custom `manage_terraviz` capability, granted to administrators on activation
  and cleaned up on deactivation/uninstall, so a site owner can delegate
  Terraviz configuration without full `manage_options`.
- WP-role → publish-intent mapping (`Support/Capabilities.php`): local
  authorization — WordPress, not Terraviz, gates who may act through the plugin.
- Encrypted **service-token** slot in settings: a Cloudflare Access
  `Cf-Access-Client-Id` + `Cf-Access-Client-Secret` pair. The client id is
  stored in the clear; the secret is encrypted at rest (libsodium `secretbox`,
  OpenSSL AES-256-GCM fallback) and never returned to the browser. Read-only
  "Verify credential" probe (`GET /api/v1/publish/me`).
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

## [0.1.0] - 2026-07-06

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
