# Terraviz WordPress plugin — implementation plan

The plugin-local roadmap and status tracker. It is the **active source of
truth for what this plugin does and will do**; the *rationale* (the
read/publish "seam", the auth analysis, the non-goals reasoning) lives in the
upstream master plan and is cited here rather than duplicated:

> **Authoritative rationale:** Terraviz repo →
> `docs/WORDPRESS_INTEGRATION_PLAN.md`.
> ⚠️ It originated on branch **`claude/terraviz-wordpress-plugin-plan-hh9iql`
> (PR #272)** and may not be merged to `main` yet — read that branch if it is
> missing on `main`. See [`../CLAUDE.md`](../CLAUDE.md) for the full pointer
> table to the upstream contracts and docs.

**Legend:** ✅ shipped · 🔜 next · ⏳ later phase · ❌ non-goal

---

## The shape of the thing

A **host-side adapter**, not a reimplementation. It embeds the existing
Terraviz web app by iframe and reads Terraviz's public, versioned HTTP+JSON
APIs. Two cleanly separated halves (upstream plan §1):

- **Read / embed** — zero credentials, public API, iframe by origin.
  **This is Phase 1.** Blast radius on a leak: none.
- **Publish** — one shared node-scoped credential, **server-side only**,
  every write proxied through PHP; a Terraviz token must never reach the
  browser (upstream §5, Goal 3). Phases 3+.

Design goals that gate every decision (upstream "Design goals"): meet
publishers in `wp-admin`; depend on **published contracts, not shared
source**; secrets never reach the browser; **degrade gracefully** for
crawlers / no-JS / screen readers.

---

## Phase 1 — zero-credential embed plugin ✅ (this repo, PR #1)

The demo that makes the case: a WordPress author drops a live, indexable,
accessible dataset / tour / catalog into any page or post, pointed at any
Terraviz node, with **no credentials anywhere**.

| Item | Status | Where |
|---|---|---|
| Plugin scaffold: header, `readme.txt` (WP.org), GPLv2, `@wordpress/scripts` build, `wp-env`, PHPUnit, CI smoke test | ✅ | `terraviz.php`, `.github/workflows/ci.yml` |
| Settings page: node origin (default canonical, overridable), default embed options, telemetry posture, "test connection" probe — **no token field** | ✅ | `src/Settings.php` |
| **Dataset / Tour / Catalog** blocks: SSR fallback (title, abstract, thumbnail, tags, canonical link) → lazy iframe, a11y | ✅ | `blocks/*`, `src/Blocks.php`, `src/Embed/Renderer.php` |
| `[terraviz]` shortcode sharing one renderer with the blocks | ✅ | `src/Shortcode.php` |
| oEmbed / paste-a-URL auto-embed | ✅ | `src/Oembed.php` |
| Catalog + dataset caching in transients | ✅ | `src/Api/Catalog.php` |
| PHP wire types generated from the served `/schema/v1` JSON Schema | ✅ | `bin/generate-contracts.php`, `src/Contract/*` |
| Human-readable **slug / legacyId** accepted, resolved to canonical id server-side | ✅ | `src/Embed/Renderer.php` (`canonical_selector`) |

### Phase 1 follow-ups (near-term, separate PRs)

- ✅ **Editor typeahead picker** — search datasets/tours by **title** in the
  block sidebar (`ComboboxControl`), store the id invisibly, with a "Copy
  shortcode" button for Classic authors. Backed by a **same-origin REST
  endpoint** (`GET /wp-json/terraviz/v1/search`, `edit_posts`-gated) over the
  transient-cached catalog — the browser never makes a cross-origin call (keeps
  the "no surprise outbound calls" posture, sidesteps CORS) and the endpoint
  reads only the site-configured node, so it is not an SSRF vector.
  `src/Rest/SearchController.php` + `blocks/shared/edit.js`.
- **Optional extra blocks** (upstream §3.3): a **right-now hero**
  (`GET /api/v1/featured-hero`) and a **related rail**
  (`GET /api/v1/datasets/:id/related`).
- **Analytics reconciliation** (upstream Integration J): document that the
  embed carries the node's own telemetry; keep the consent-friendly
  lazy-load default; don't double-count.

---

## Phase 2 — WP account mapping ⏳ (plugin repo, no Terraviz auth yet)

Map WP roles/capabilities to intended Terraviz publish capabilities — the
in-WP half of Integration F (upstream §5):

| WP capability | Plugin-granted intent |
|---|---|
| `administrator` / custom `manage_terraviz` | configure node/token; full publish |
| `editor` (`edit_others_posts`) | create/edit/publish datasets & tours |
| `author` (`publish_posts`) | draft; request publish |
| `contributor` / lower | embed blocks only |

Also: an **inert, encrypted credential slot** in settings + a **read-only**
authenticated probe (`GET /api/v1/publish/me`) to validate a service token
*before* any writes. No catalog mutation yet.

---

## Phase 3 — authenticated publisher dashboard ⏳ (plugin repo + service token)

- **Server-side publish proxy** (Goal 3): every `/api/v1/publish/**` call goes
  through PHP, which attaches a Cloudflare Access **service token**
  (`Cf-Access-Client-Id` / `-Secret`); the token stays server-side and never
  reaches the browser.
- Dataset **list + create / edit / publish / retract** screens in `wp-admin`.
- **Asset upload**: the two-step presigned-R2 flow (init → browser PUTs bytes
  directly to the presigned URL → complete), proxied so only the short-lived
  presigned URL reaches the browser.

Known limitation (upstream §5 Option 1): actions are attributed to the shared
`service` identity, not the individual WP user.

---

## Phase 4 — post / blog bridge ⏳ (plugin repo)

- A **"cite Terraviz data" block** — a specialization of the Phase-1 blocks —
  to drop a live dataset/tour/related-rail into a normal WP post.
- **Optional one-way** WP-post → Terraviz-blog-*stub* sync for in-globe
  discovery (`POST /api/v1/publish/blog`): a short markdown summary + a
  canonical link back to the WP post, carrying dataset/tour grounding. WP stays
  the source of truth.

---

## Phase 5 — per-user auth ⏳ (conditional, mostly main-repo)

Only if a real deployment needs per-user attribution: an OIDC / bearer
`authProvider` in the **main repo** (upstream §5 Option 2), coordinated with
Terraviz's own Phase-4 auth work. **Not built speculatively.**

---

## Non-goals ❌

Carried from upstream §9 — do not build these:

- Reimplementing the globe, catalog, or docent in PHP. The plugin embeds and
  calls; it does not port.
- **Two-way blog body sync** / WP as master of Terraviz `blog_posts` content.
  One-way WP→Terraviz stubs only.
- Speculative per-user OIDC auth before a deployment needs it.
- Shipping the service token to the browser, ever.
- Running the Terraviz SPA bundle inside WordPress's own origin (embeds target
  a Terraviz origin by iframe).
- A `frame-ancestors` lockdown by default (embedding stays open unless a node
  operator opts to restrict it — upstream §4).
- Hosting-specific adapters (VIP vs. Pantheon vs. self-hosted). The plugin is
  standard PHP coded to the strictest (VIP) review rules; hosting specifics are
  the site operator's concern.

---

## Contracts & drift

The plugin depends on Terraviz's **published contracts**, never its source:

- **Embed-URL grammar** `v1` — `docs/EMBED_URL_GRAMMAR.md` upstream; composed
  by `src/Embed/UrlBuilder.php`.
- **Wire JSON Schema** `v1` — served at `https://<node>/schema/v1/*.schema.json`;
  PHP types generated into `src/Contract/*`.

`tests/smoke/` hits the live canonical node and asserts a block still renders —
a contract-drift tripwire. On an upstream contract change, regenerate
(`composer run gen:contracts`) and update `UrlBuilder`/blocks to match. See
[`../CLAUDE.md`](../CLAUDE.md) for the full read-API surface and doc pointers.
