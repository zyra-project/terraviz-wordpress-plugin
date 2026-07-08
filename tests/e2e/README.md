# Screenshot / visual-regression suite (Playwright)

Drives a real WordPress (via `wp-env`) with the plugin active and captures
every block front end plus the wp-admin dashboard views. Runs in CI as the
[`Screenshots`](../../.github/workflows/screenshots.yml) workflow — kept out of
the core `ci.yml` gate so a screenshot flake never blocks a merge.

The blocks are shot at two viewports (Playwright projects): `frontend`
(desktop, 1280px) and `frontend-mobile` (phone, 390px) — the visitor-facing
surface, where the responsive SSR fallback has to reflow. Mobile shots are
suffixed `-mobile` (e.g. `block-catalog-mobile.png`) and skip the WordPress.org
listing images. The wp-admin views (`admin` project) are desktop-only — that
surface's responsiveness is WordPress core's, not the plugin's.

## What it produces

Each captured shot fans out to up to three places (see `helpers/shots.js`):

| Output | Path | Purpose |
|---|---|---|
| **Gallery** | `tests/e2e/.artifacts/gallery/*.png` | "here's how everything looks" — uploaded as a CI artifact (git-ignored) |
| **Baseline** | `tests/e2e/__snapshots__/*.png` | committed; `toHaveScreenshot` diffs against it and fails on unexpected change |
| **WordPress.org** | `.wordpress-org/screenshot-N.png` | committed; the plugin-listing images (see [`.wordpress-org/README.md`](../../.wordpress-org/README.md)) |
| **Visual report** | `tests/e2e/.artifacts/visual-report.{md,json}` + `gallery/*.diff.png` | per-scene diff summary posted as a PR comment (see below) |

## Visual report — the PR comment

After the capture, `visual-report.js` (`npm run visual-report`) diffs each fresh
gallery actual against its committed baseline with `pixelmatch` and renders a
**"Visual report" comment** on the PR: a per-scene, per-viewport table of what
changed (percent + changed-pixel count), which scenes are new/uncaptured, and a
red-highlighted
`<name>.diff.png` overlay dropped into the gallery for every changed scene. It
runs even when the `toHaveScreenshot` gate failed (`snap()` writes the gallery
actual *before* asserting) and never fails a build itself — it's a **visual-review
aid**, deliberately stricter than the gate (report threshold `0.001` vs the gate's
`0.02`) so subtle changes still surface. Override with
`--threshold`/`VISUAL_REPORT_THRESHOLD`.

The comment is posted **fork-safely** by a second workflow: `screenshots.yml`
(running untrusted PR code, read-only) uploads a data-only `visual-report`
artifact, and `screenshots-comment.yml` — triggered by `workflow_run`, checking
out no PR code — downloads it and upserts one sticky comment with
`pull-requests: write`. So the write token never meets PR code.

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
npm run visual-report             # diff gallery vs baselines → visual-report.{md,json}
```

`global-setup.js` seeds one page per embed surface (via WP-CLI in the `cli`
container) and logs the admin in; the specs read `.artifacts/pages.json` to know
which page to visit.

## Bootstrapping / refreshing baselines

Baselines are PNGs generated on the CI **Linux** runner — reproduce that
environment when regenerating, or the fonts/anti-aliasing will differ from what
CI compares against. The `Screenshots` **capture** job is read-only (it runs
untrusted PR code, and the protected default branch can't be pushed to directly),
so baselines land through a PR:

- **Alongside a UI change (the common case):** on your feature branch, run
  `npm run screenshots:update` locally (needs Docker) to regenerate the
  `__snapshots__/` baselines and `.wordpress-org/` images, and commit the diff
  in the same PR. The PR's own `Screenshots` run then validates them. If your
  local OS renders differently from CI, use the CI path below instead.
- **From CI (no local Docker, or the initial bootstrap):** run the `Screenshots`
  workflow manually with **`update_baselines = true`**. It captures with
  `--update-snapshots`, and a separate **`open-baseline-pr`** job — gated to
  `workflow_dispatch` and given its own `contents: write` / `pull-requests: write`
  token, so the read-only capture path is never affected — commits the
  regenerated baselines and **opens a PR automatically**. (The same run also
  uploads a `refreshed-baselines` artifact if you'd rather commit them by hand.)
  Because the auto-PR is opened by `GITHUB_TOKEN`, its own checks may not
  re-trigger; push an empty commit or re-run them if branch protection requires
  it.

Either way the committed baselines are what `toHaveScreenshot` diffs against;
review the images in the PR as you would any change.
