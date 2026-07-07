# Screenshot / visual-regression suite (Playwright)

Drives a real WordPress (via `wp-env`) with the plugin active and captures
every block front end plus the wp-admin dashboard views. Runs in CI as the
[`Screenshots`](../../.github/workflows/screenshots.yml) workflow — kept out of
the core `ci.yml` gate so a screenshot flake never blocks a merge.

## What it produces

Each captured shot fans out to up to three places (see `helpers/shots.js`):

| Output | Path | Purpose |
|---|---|---|
| **Gallery** | `tests/e2e/.artifacts/gallery/*.png` | "here's how everything looks" — uploaded as a CI artifact (git-ignored) |
| **Baseline** | `tests/e2e/__snapshots__/*.png` | committed; `toHaveScreenshot` diffs against it and fails on unexpected change |
| **WordPress.org** | `.wordpress-org/screenshot-N.png` | committed; the plugin-listing images (see [`.wordpress-org/README.md`](../../.wordpress-org/README.md)) |

## Determinism — no live node, no network

The suite never touches the live Terraviz node. `mu-plugins/terraviz-e2e-fixtures.php`
(inert unless `TERRAVIZ_E2E` is set) short-circuits the public read API with the
canned JSON in `fixtures/` and serves placeholder SVG thumbnails same-origin, so
every run is byte-stable and offline. The click-to-load poster is the default,
so the heavy cross-origin globe iframe never boots during a shot — the capture
is the server-rendered fallback (title, abstract, thumbnail, tags, link).

## Run it locally

Requires Docker (for `wp-env`).

```bash
npm ci
npm run build

# Enable the offline fixtures for wp-env (git-ignored; the CI workflow writes
# the same file). Merges over .wp-env.json.
cat > .wp-env.override.json <<'JSON'
{
  "mappings": { "wp-content/mu-plugins": "./tests/e2e/mu-plugins" },
  "config":   { "TERRAVIZ_E2E": true }
}
JSON

npx wp-env start --update
npx playwright install chromium   # first time only

npm run screenshots               # capture + compare against baselines
npm run screenshots:update        # regenerate baselines + listing images
```

`global-setup.js` seeds one page per embed surface (via WP-CLI in the `cli`
container) and logs the admin in; the specs read `.artifacts/pages.json` to know
which page to visit.

## Bootstrapping / refreshing baselines

Baselines are PNGs generated on the CI **Linux** runner, so they must be created
there, not on a developer's macOS/Windows machine (fonts/AA differ). Run the
`Screenshots` workflow manually with **`update_baselines = true`** — it captures
with `--update-snapshots` and commits the refreshed `__snapshots__/` baselines
and `.wordpress-org/` listing images back to the branch. Do this once to seed
them, then again whenever an intentional UI change moves the pixels.
