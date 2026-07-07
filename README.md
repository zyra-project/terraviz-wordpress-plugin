# Terraviz — WordPress plugin

Embed live [Terraviz](https://terraviz.zyra-project.org) "Science On a Sphere"
globes — a single dataset, a tour, or the full catalog — into WordPress pages
and posts, and **optionally** publish and manage Terraviz datasets from
wp-admin.

This is a **host-side adapter**, not a reimplementation of the globe. It embeds
the existing Terraviz web app via an iframe and talks only to Terraviz's public,
versioned HTTP APIs. **Embedding is entirely public and needs no account.**
Publishing is an optional, administrator-configured capability that
authenticates to a Terraviz node with a service token kept **server-side** — it
never reaches the browser.

> **Roadmap & status:** [`docs/IMPLEMENTATION_PLAN.md`](docs/IMPLEMENTATION_PLAN.md)
> — the plugin-local phased plan (what's shipped, what's next). The design
> *rationale* (the read/publish "seam", the auth model, non-goals) lives in the
> Terraviz repo's `docs/WORDPRESS_INTEGRATION_PLAN.md`, which the local plan
> cites. Phases 1–4 are shipped; see the plan and [`CHANGELOG.md`](CHANGELOG.md).

## What's here

### Embed — public, no credentials (Phase 1)

- **Gutenberg blocks** — `Dataset`, `Tour`, `Catalog`, a **Right-Now Hero** (the
  node's curated featured dataset) and a **Related Datasets** rail. Each
  server-renders an accessible, indexable fallback (title, abstract, thumbnail,
  tags, canonical link) from the public read API, then progressively enhances to
  a lazy globe iframe (or a card rail for Related). Once a globe loads, a
  **fullscreen toggle** appears in its corner (progressive enhancement; hidden
  where the browser disallows element fullscreen).
- **`[terraviz]` shortcode** and **automatic URL embeds** (oEmbed) — sharing one
  PHP renderer with the blocks. `[terraviz hero]` and `[terraviz related="…"]`
  cover the extra blocks.
- **Editor typeahead picker** — search datasets/tours by title in the block
  sidebar, backed by a same-origin, `edit_posts`-gated REST endpoint over the
  cached catalog (the browser makes no cross-origin call), with a "Copy
  shortcode" button for Classic authors.
- **Settings screen** (**Settings → Terraviz**) — node origin (default the
  canonical node, overridable per block), default embed options, loading /
  telemetry posture, and a public "test connection" probe.
- **Transient caching** of catalog + dataset reads (the cached catalog is
  compressed so it survives object caches).
- **Generated PHP wire types** from Terraviz's published `/schema/v1` JSON
  Schema (`bin/generate-contracts.php`) — no hand-copied field names.

### Publish — optional, requires a service token (Phases 2–4)

- **Publisher dashboard** (wp-admin, a React `@wordpress/element` app) — list
  datasets by lifecycle state, create/edit drafts with inline validation, and
  publish / retract / delete, plus an **events curation review queue** and
  **feed connector** management.
- **Server-side publish proxy** — every write is proxied through PHP, which
  attaches a stored Cloudflare Access service token (client id + secret, the
  secret encrypted at rest). The token never reaches the browser. A same-origin
  REST proxy (`terraviz/v1/publisher/*`) gates each call by WordPress
  capability tier.
- **WordPress-native authorization** — a WP-role → publish-intent map (authors
  draft, editors publish, admins configure), enforced locally. A custom
  `manage_terraviz` capability lets an admin delegate node/credential config.
  Because every write shares one node-scoped `service` identity, Terraviz
  attributes actions to that identity, not the individual user — the dashboard
  says so.
- **Asset upload** — the browser hashes the file (`sha256`), the plugin proxies
  an `init` to mint a short-lived presigned URL, the bytes upload **directly**
  from the browser to the node's R2 storage, and the plugin proxies `complete`.
  Video transcodes asynchronously (HTTP 202).
- **"Show this post in Terraviz"** — an opt-in editor panel that syncs a
  **one-way** Terraviz blog stub (a short markdown summary + a canonical link
  back to the WP post, carrying the datasets/tours the post's blocks cite).
  WordPress stays the source of truth; two-way body sync is a non-goal.

## Contracts this plugin depends on

The plugin depends on Terraviz's **published contracts**, never its source:

- The **embed-URL grammar** (`docs/EMBED_URL_GRAMMAR.md`, grammar `v1`), composed
  by `src/Embed/UrlBuilder.php`.
- The **wire JSON Schema** served at `https://<node>/schema/v1/*.schema.json`,
  from which `src/Contract/*` is generated.

The publish/blog APIs are internal publisher APIs, not part of the versioned
`/schema/v1` contracts. A CI smoke test (`tests/smoke/`) hits the canonical
node's public API and asserts a block still renders — catching contract drift
within a day.

## Development

```bash
# JS (blocks + dashboard)
npm install
npm run build          # compile blocks to build/
npm run start          # watch mode

# PHP tooling
composer install
composer run lint       # PHPCS (WordPress standard)
composer run gen:contracts   # regenerate src/Contract/* from the served schema

# Local WordPress
npm run env:start       # wp-env at http://localhost:8888

# Tests
composer run test       # PHPUnit (needs the WP test suite; see bin/install-wp-tests.sh)

# Screenshots / visual regression (Playwright + wp-env; needs Docker)
npm run screenshots         # capture block + dashboard shots, diff vs baselines
npm run screenshots:update  # regenerate baselines + WordPress.org listing images
```

The screenshot suite (`tests/e2e/`) drives a real WordPress with offline
fixtures and captures every block and admin view — producing a gallery
artifact, committed visual-regression baselines, and the numbered
`.wordpress-org/screenshot-N.png` images for the WordPress.org listing. It runs
in CI as the [`Screenshots`](.github/workflows/screenshots.yml) workflow (kept
out of the core gate). See [`tests/e2e/README.md`](tests/e2e/README.md).

Requires WordPress ≥ 6.1 and PHP ≥ 7.4. Licensed GPLv2-or-later.

## Building an installable ZIP

To produce a plugin ZIP you can upload to a WordPress site:

```bash
npm ci
npm run package         # = npm run build && bash bin/build-zip.sh
# → dist/terraviz-<version>.zip
```

The archive's inner folder is `terraviz` (the plugin slug), it bundles the
compiled blocks from `build/`, and it strips dev files (`tests/`, `bin/`,
`docs/`, config, node_modules, …) per [`.distignore`](.distignore).

Install it via **WP Admin → Plugins → Add New → Upload Plugin**, choose the ZIP,
then **Activate**.

You don't have to build it yourself: every CI run publishes the same ZIP as a
downloadable **`terraviz-plugin-zip`** artifact (Actions → the run → Artifacts).

### Cutting a release

Tagged releases publish a versioned ZIP to the repo's GitHub Releases:

1. Bump the version everywhere (`bin/check-version.sh` enforces they agree — see
   [`docs/RELEASING.md`](docs/RELEASING.md) for the full checklist).
2. `git tag vX.Y.Z && git push --tags`.

The `release` workflow builds the ZIP and attaches it to a new Release. See
[`docs/RELEASING.md`](docs/RELEASING.md) for the maintainer runbook (and the
future WordPress.org publishing path).

## Later phases & non-goals

Per-user (OIDC / bearer) attribution is a **later, conditional** phase — built
only if a real deployment needs it. Explicit **non-goals**: reimplementing the
globe/catalog/docent in PHP, two-way blog body sync, and ever shipping the
service token to the browser. See
[`docs/IMPLEMENTATION_PLAN.md`](docs/IMPLEMENTATION_PLAN.md) for the full list.
