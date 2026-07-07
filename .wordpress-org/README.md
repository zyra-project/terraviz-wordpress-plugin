# WordPress.org plugin assets

This directory holds the assets shown on the plugin's **WordPress.org listing**
page — *not* files that ship inside the installable plugin ZIP (it is excluded
in `.distignore`). It mirrors the 10up convention: on a WP.org SVN deploy these
files go into the plugin's SVN **`assets/`** folder, e.g. via
[`10up/action-wordpress-plugin-asset-update`](https://github.com/10up/action-wordpress-plugin-asset-update).

## Screenshots

`screenshot-1.png`, `screenshot-2.png`, … correspond, in order, to the numbered
entries in `readme.txt`'s `== Screenshots ==` section. They are **generated** by
the Playwright suite (`tests/e2e/`), not hand-made — run the `Screenshots`
workflow with `update_baselines = true` to (re)create and commit them. Current
mapping:

1. Dataset block
2. Tour block
3. Catalog block
4. Hero block
5. Related block
6. Publisher dashboard (wp-admin)
7. Settings screen

## Other listing assets (add when publishing to WP.org)

Drop these here too — they are picked up by the same asset deploy:

- `banner-772x250.png` / `banner-1544x500.png` — listing header banner.
- `icon-128x128.png` / `icon-256x256.png` (or `icon.svg`) — plugin icon.
