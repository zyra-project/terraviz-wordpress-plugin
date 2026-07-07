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
canned JSON in `mu-plugins/fixtures/` and serves placeholder SVG thumbnails same-origin, so
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

Baselines are PNGs generated on the CI **Linux** runner — reproduce that
environment when regenerating, or the fonts/anti-aliasing will differ from what
CI compares against. The `Screenshots` workflow is **read-only** and never
commits (the protected default branch can't be pushed to directly, and the job
runs untrusted PR code), so baselines land through a PR:

- **Alongside a UI change (the common case):** on your feature branch, run
  `npm run screenshots:update` locally (needs Docker) to regenerate the
  `__snapshots__/` baselines and `.wordpress-org/` images, and commit the diff
  in the same PR. The PR's own `Screenshots` run then validates them. If your
  local OS renders differently from CI, use the artifact path below instead.
- **From CI (no local Docker, or the initial bootstrap):** run the `Screenshots`
  workflow manually with **`update_baselines = true`**. It captures with
  `--update-snapshots` and uploads a **`refreshed-baselines`** artifact
  (`tests/e2e/__snapshots__/` + `.wordpress-org/screenshot-N.png`). Download it,
  drop the files into the repo, and open a PR.

Either way the committed baselines are what `toHaveScreenshot` diffs against;
review the images in the PR as you would any change.
