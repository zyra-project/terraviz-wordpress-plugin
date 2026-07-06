# Releasing the Terraviz WordPress plugin

The maintainer runbook for cutting a version and (later) publishing to
WordPress.org. For the "how do I just get an installable ZIP" path, see the
**Building an installable ZIP** section in [`../README.md`](../README.md).

## What produces what

| Surface | Trigger | Output |
|---|---|---|
| `terraviz-plugin-zip` CI artifact | every push / PR / manual run of **CI** (`.github/workflows/ci.yml`) | run-scoped `dist/terraviz-<version>.zip` for immediate testing (expires ~90 days) |
| GitHub Release asset | push a `vX.Y.Z` tag → **Release** workflow (`.github/workflows/release.yml`) | a permanent, versioned `terraviz-<version>.zip` attached to a GitHub Release |

Both call the same `bin/build-zip.sh`, so the artifact and the release asset are
byte-for-byte the same packaging path.

## The version lives in several files — keep them in lock-step

`bin/check-version.sh` (run in CI and as a precondition of packaging) fails the
build if any of these disagree:

1. `terraviz.php` — the `Version:` plugin header
2. `terraviz.php` — the `TERRAVIZ_VERSION` constant
3. `readme.txt` — the `Stable tag:` line (this is what actually publishes a
   version on WordPress.org)
4. `package.json` — the `version` field
5. `blocks/*/block.json` — the `version` field in each of the five blocks

When bumping, change **all** of them to the same value. There is intentionally no
auto-bump script yet; do it by hand and let the guard catch a miss.

While here, keep `readme.txt`'s `Tested up to:` current with the latest
WordPress you've verified against, and update `CHANGELOG.md`.

## Cutting a release

```bash
# 1. Bump the five version locations above to X.Y.Z, update the changelog.
# 2. Sanity-check locally.
npm run check:version
npm run package          # builds blocks + writes dist/terraviz-X.Y.Z.zip
# 3. Commit (signed off — DCO), then tag and push.
git commit -s -am "Release vX.Y.Z"
git tag vX.Y.Z
git push && git push --tags
```

The Release workflow then:

- re-runs `check-version.sh` **and** asserts the tag (minus its leading `v`)
  matches the plugin version — a mismatched tag fails the run,
- builds the blocks and packages the ZIP,
- creates a GitHub Release for the tag with auto-generated notes and the ZIP
  attached.

Tag format is `vX.Y.Z` (semver, `v`-prefixed). `workflow_dispatch` runs the same
build without creating a Release — useful for a dry run.

## Future: publishing to WordPress.org (not yet wired up)

The plugin is packaged WordPress.org-ready (`readme.txt` is in WP.org format, the
slug is `terraviz`, `.distignore` governs what ships), but the automated SVN
deploy is **deliberately not enabled** yet. To switch it on when the plugin has
been submitted and approved:

1. **Approval first.** WordPress.org must approve the plugin and provision its
   SVN repo under the slug `terraviz`. This is a manual submission — it cannot be
   automated.
2. **Secrets.** Add `SVN_USERNAME` / `SVN_PASSWORD` (a WordPress.org account with
   commit rights) as repository secrets.
3. **Deploy step.** Add a `deploy` job to `release.yml` (gated on the `v*` tag)
   using [`10up/action-wordpress-plugin-deploy`](https://github.com/10up/action-wordpress-plugin-deploy)
   with `SLUG: terraviz`. It reuses **this repo's `.distignore`** to decide what
   to commit to SVN `trunk`, and it needs `build/` present — so it must run
   `npm run build` first, exactly like `build-zip.sh`.
4. **Store assets.** Create a `.wordpress-org/` directory for the directory
   listing's icon, banner, and screenshots, and point the action's `ASSETS_DIR`
   at it. Note this is **distinct** from the plugin's runtime `assets/` (the
   CSS/JS that ships inside the ZIP) — don't conflate the two.
5. On WordPress.org the published version is whatever `readme.txt`'s
   `Stable tag:` points at — which the version guard already protects.

Until then, distribution is via the GitHub Release ZIP.
