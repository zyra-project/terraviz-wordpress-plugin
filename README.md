# Terraviz — WordPress plugin

Embed live [Terraviz](https://terraviz.zyra-project.org) "Science On a Sphere"
globes — a single dataset, a tour, or the full catalog — into WordPress pages
and posts.

This is a **host-side adapter**, not a reimplementation of the globe. It embeds
the existing Terraviz web app via an iframe and reads Terraviz's public,
versioned HTTP APIs. Phase 1 (this release) is **zero-credential**: the
embed/read path is entirely public.

> **Roadmap & status:** [`docs/IMPLEMENTATION_PLAN.md`](docs/IMPLEMENTATION_PLAN.md)
> — the plugin-local phased plan (what's shipped, what's next). The design
> *rationale* (the read/publish "seam", the auth model, non-goals) lives in the
> Terraviz repo's `docs/WORDPRESS_INTEGRATION_PLAN.md`, which the local plan
> cites. This plugin implements **Phase 1**.

## What's here (Phase 1)

- **Gutenberg blocks** — `Dataset`, `Tour`, `Catalog`. Each server-renders an
  accessible, indexable fallback (title, abstract, thumbnail, canonical link)
  from the public read API, then progressively enhances to a lazy globe iframe.
- **`[terraviz]` shortcode** and **automatic URL embeds** — sharing one PHP
  renderer with the blocks.
- **Settings screen** — node origin (default the canonical node), default embed
  options, loading/telemetry posture. No credential field.
- **Transient caching** of catalog + dataset reads.
- **Generated PHP wire types** from Terraviz's published `/schema/v1` JSON
  Schema (`bin/generate-contracts.php`) — no hand-copied field names.

## Contracts this plugin depends on

The plugin depends on Terraviz's **published contracts**, never its source:

- The **embed-URL grammar** (`docs/EMBED_URL_GRAMMAR.md`, grammar `v1`), composed
  by `src/Embed/UrlBuilder.php`.
- The **wire JSON Schema** served at `https://<node>/schema/v1/*.schema.json`,
  from which `src/Contract/*` is generated.

A CI smoke test (`tests/smoke/`) hits the canonical node's public API and
asserts a block still renders — catching contract drift within a day.

## Development

```bash
# JS (blocks)
npm install
npm run build          # compile blocks to build/
npm run start          # watch mode

# PHP tooling
composer install
composer run lint       # PHPCS (WordPress + VIP-Go)
composer run gen:contracts   # regenerate src/Contract/* from the served schema

# Local WordPress
npm run env:start       # wp-env at http://localhost:8888

# Tests
composer run test       # PHPUnit (needs the WP test suite; see bin/install-wp-tests.sh)
```

Requires WordPress ≥ 6.1 and PHP ≥ 7.4. Licensed GPLv2-or-later.

## Not in this phase

The wp-admin publisher dashboard, asset upload, the service-token proxy,
per-user auth, and the blog bridge are **later, separately gated phases**.
Two-way blog sync is an explicit non-goal. See
[`docs/IMPLEMENTATION_PLAN.md`](docs/IMPLEMENTATION_PLAN.md) for the full
phased roadmap and the near-term Phase-1 follow-ups (the editor typeahead
picker, hero/related blocks).
