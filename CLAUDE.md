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
