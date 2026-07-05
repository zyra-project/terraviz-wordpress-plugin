# CLAUDE.md — working conventions for this repo

Guidance for Claude (and humans) working on the Terraviz WordPress plugin.
Keep this short and current; it records the non-obvious conventions, not the
whole design (that lives in `README.md` and the Terraviz repo's
`docs/WORDPRESS_INTEGRATION_PLAN.md`).

## What this plugin is

A **host-side adapter**, not a reimplementation of Terraviz. It embeds the
existing Terraviz web app via iframe and reads Terraviz's public, versioned
HTTP+JSON APIs. Do **not** port the globe, catalog, or docent into PHP.

- Depend on Terraviz's **published contracts** — the embed-URL grammar and the
  `/schema/v1` JSON Schema — never on Terraviz's TypeScript source.
- **Self-contained, no phone-home.** The only outbound calls are to the
  configured node's public read API (for the SSR fallback), cached in
  transients. No bundled external assets; nothing proxied through the WP site.
- Phase 1 is **zero-credential** (public read/embed only). No token field, no
  publish path — those are later, gated phases. See the integration plan.

## Terraviz upstream repo & the contracts we depend on

The plugin lives in its **own** repo, deliberately separate from the Terraviz
monorepo (`github.com/zyra-project/terraviz`). It depends on Terraviz's
**published, versioned contracts**, not its TypeScript source. When working on
anything that touches the wire format or embed URLs, read the upstream docs
first.

**Bring the upstream repo into a session** (read-only, to consult the docs):

```bash
# via the session's add_repo tool, then:
git clone --depth 1 https://github.com/zyra-project/terraviz /workspace/terraviz
```

> ⚠️ **Branch caveat (check whether still true).** The Phase-0 enablers this
> plugin needs — the `?embed=1` minimal-chrome mode, `docs/EMBED_URL_GRAMMAR.md`,
> and the `public/schema/v1/*` JSON Schemas — originated on branch
> **`claude/terraviz-wordpress-plugin-plan-hh9iql` (PR #272)**, which may not be
> merged to `main` yet. If those files are missing on `main`, read that branch.

### The two contracts, and where they're documented

| Contract | Upstream doc | Served / consumed at |
|---|---|---|
| **Embed-URL grammar** (`v1`) — the `/?dataset=…&embed=1&…` shapes the blocks compose | `docs/EMBED_URL_GRAMMAR.md` | composed by `src/Embed/UrlBuilder.php`; targets a node **origin** (default `https://terraviz.zyra-project.org`) |
| **Wire JSON Schema** (`v1`) — `WireDataset`, catalog envelope, node discovery | `docs/protocol/README.md` + `docs/protocol/CHANGELOG.md`; schemas in `public/schema/v1/{dataset,catalog,well-known}.schema.json` | served live at `https://<node>/schema/v1/<file>`; PHP types generated from it into `src/Contract/*` |
| **Field semantics** for the dataset shape | `docs/CATALOG_DATA_MODEL.md` | reference only (schema has no prose descriptions) |

The plugin targets grammar `v1` and schema `v1` — see the `TERRAVIZ_EMBED_GRAMMAR`
and `TERRAVIZ_SCHEMA_VERSION` constants in `terraviz.php`.

### Wider design context (read when scoping later phases)

- `docs/WORDPRESS_INTEGRATION_PLAN.md` — **the master plan**. The read/publish
  "seam", the phased plan (§8), non-goals (§9), and the auth question the
  publish phase inherits. This plugin implements **Phase 1**.
- `docs/architecture/federation-scoping.md` — partner-tier framing + the
  service-token/OIDC auth question (§8 decision 4) the publish phase inherits.
- `docs/CATALOG_PUBLISHING_TOOLS.md` — the publish API + CLI, the model for the
  future Phase-3 publisher dashboard.
- `MISSION.md` — why Terraviz exists (publishers reach an audience without
  giving up their data/branding/control). `CLAUDE.md` — Terraviz's own conventions.

### Public read API (unauthenticated) — what the SSR fallback + pickers use

Base is the configured node origin. Consumed via `src/Api/Client.php` /
`Catalog.php` (cached in transients):

```
GET /api/v1/catalog                  full catalog envelope
GET /api/v1/datasets/:id             one dataset (full WireDataset)
GET /api/v1/datasets/:id/related     "more like this"
GET /api/v1/datasets/:id/events      "in the news"
GET /api/v1/search?q=                semantic search (pickers)
GET /api/v1/featured, /featured-hero curated
GET /api/v1/blog, /blog/:slug        published posts
GET /.well-known/terraviz.json       node discovery doc (used by the settings "Test connection" probe)
```

**Contract drift** between this repo and upstream is caught by `tests/smoke/`,
which hits the live canonical node and asserts a block still renders. If the
grammar or wire schema changes upstream, regenerate `src/Contract/*`
(`composer run gen:contracts`) and update `UrlBuilder`/blocks to match.

## Commits — DCO sign-off is required (CI gate)

Every commit **must** carry a `Signed-off-by:` trailer matching the commit
author, or the DCO check fails. Commit with `-s`:

```bash
git commit -s -m "…"
```

If you forget on a branch, add it to every commit at once:

```bash
git rebase --signoff <base>   # e.g. git rebase --signoff main
```

Also end commit messages with the `Co-Authored-By:` and `Claude-Session:`
trailers (session convention). Never put a model identifier in commits.

## PHP — coding standards & compatibility

- **Standard:** `WordPress` (via `phpcs.xml.dist`). Run `composer run lint`
  (`phpcs`) and `composer run lint:fix` (`phpcbf`). CI must be **0 errors**;
  warnings don't fail (`ignore_warnings_on_exit`).
- **PSR-4 autoloading** (`ClassName.php`), not WP's `class-*.php` — the
  `WordPress.Files.FileName` sniff is intentionally excluded. `src/` is the
  autoload root (namespace `Terraviz\`); there is **no runtime Composer
  dependency** (a hand-rolled autoloader lives in `terraviz.php`).
- **Supported PHP: 7.4+.** `composer.json` pins `config.platform.php` to
  7.4.33 so the committed `composer.lock` resolves 7.4-compatible dev deps
  even when you run Composer on a newer PHP. After changing dependencies,
  regenerate with `composer update` (still on the 7.4 platform pin) and commit
  the lock — do **not** regenerate it on a newer PHP without the pin, or CI's
  7.4/8.2 jobs will fail at `composer install`.
- `bin/` (the CLI schema generator) and `tests/` are scoped out of some
  runtime sniffs in `phpcs.xml.dist`; keep that scoping if you add files there.

## Generated wire types — don't hand-edit

`src/Contract/{WireDataset,CatalogResponse,WellKnownDoc}.php` are **generated**
from the served JSON Schema by `bin/generate-contracts.php`
(`composer run gen:contracts`). Regenerate rather than editing field lists by
hand; they're excluded from PHPCS.

## Blocks (JS) — build & lint

- Sources live in `blocks/`; build with `npm run build`
  (`wp-scripts build --webpack-src-dir=blocks` → `build/`). `build/` is
  git-ignored; CI builds it and the PHP blocks register from it (falling back
  to `blocks/` source metadata when `build/` is absent).
- Blocks are **dynamic / server-rendered**: `save: () => null`, and their
  `render_callback` delegates to the shared `Renderer` (the same code the
  `[terraviz]` shortcode uses). Keep one render path.
- `npm run lint:js` must pass; `npm run format` applies the wp-scripts
  prettier config.

## Tests

- `composer run test` (PHPUnit) needs the WP test suite. CI installs it via
  `bin/install-wp-tests.sh` (which needs **Subversion** — CI installs it).
- `tests/unit/` runs offline using a canned `JsonReader` (`FakeReader`), so no
  network. `tests/smoke/` hits the **live canonical node's public API** and
  asserts a block still renders — a contract-drift tripwire; it self-skips when
  the node is unreachable.

## CI (`.github/workflows/ci.yml`)

Jobs: build blocks → PHPCS (`lint-php`) → PHPUnit on PHP 7.4 and 8.2. All must
pass. The DCO check runs separately (see above).
