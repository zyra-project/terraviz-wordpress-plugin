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
| **Dataset / Tour / Catalog / Hero / Related** blocks: SSR fallback (title, abstract, thumbnail, tags, canonical link) → lazy iframe (or card rail for Related), a11y | ✅ | `blocks/*`, `src/Blocks.php`, `src/Embed/Renderer.php` |
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
- ✅ **Optional extra blocks** (upstream §3.3): a **right-now hero**
  (`GET /api/v1/featured-hero`, rendered as a dataset embed) and a **related
  rail** (`GET /api/v1/datasets/:id/related`, an SSR card rail). Both also
  available via the `[terraviz hero]` / `[terraviz related="…"]` shortcodes.
- ✅ **Analytics reconciliation** (upstream Integration J): the embed carries
  the node's own telemetry (documented on the settings screen + readme); the
  consent-friendly lazy-load default is exposed via the "Globe loading" /
  click-to-load-poster settings; the plugin adds no telemetry of its own.

---

## Phase 2 — WP account mapping ✅ (plugin repo, no catalog writes yet)

Map WP roles/capabilities to intended Terraviz publish capabilities — the
in-WP half of Integration F (upstream §5). Because every future publish call is
proxied under **one** shared `service` identity (upstream §5 Option 1;
Terraviz sees the same publisher no matter which WP user acted), this mapping is
**local authorization only** — WordPress, not Terraviz, gates who may act
through the plugin.

| WP capability | Plugin-granted intent |
|---|---|
| `administrator` / custom `manage_terraviz` | configure node/token; full publish |
| `editor` (`edit_others_posts`) | create/edit/publish datasets & tours |
| `author` (`publish_posts`) | draft; request publish |
| `contributor` / lower | embed blocks only |

| Item | Status | Where |
|---|---|---|
| Custom `manage_terraviz` capability — granted to `administrator` on activation, revoked on deactivate/uninstall | ✅ | `src/Support/Capabilities.php`, `src/Plugin.php`, `uninstall.php` |
| WP-role → publish-intent map (pure, testable), shown read-only on the settings screen | ✅ | `src/Support/Capabilities.php`, `src/Settings.php` |
| **Inert, encrypted credential slot** — a Cloudflare Access **service-token pair** (`Cf-Access-Client-Id` + `Cf-Access-Client-Secret`); client id stored plainly, secret encrypted at rest (libsodium `secretbox`, OpenSSL AES-256-GCM fallback), never sent to the browser | ✅ | `src/Support/Credential.php`, `src/Support/Crypto.php` |
| **Read-only** authenticated probe (`GET /api/v1/publish/me`) to validate the token before any writes exist; typed error envelopes mapped to operator guidance | ✅ | `src/Api/PublishClient.php`, `src/Settings.php` |

**No catalog mutation.** The credential slot is inert — its only consumer is
the `me` probe. Verified upstream contract: `me` returns
`{ id, email, display_name, affiliation, role, is_admin, status, created_at }`,
and a service token provisions as role `service` (privileged for content,
**not** admin / user-management). See
`functions/api/v1/publish/{me,_middleware}.ts` and `_lib/access-auth.ts`
upstream.

---

## Phase 3 — authenticated publisher dashboard (plugin repo + service token)

Sliced into 3a (the proxy + dataset CRUD/lifecycle spine) and 3b (asset
upload), so the security-critical proxy lands and is reviewed before the
complex upload flow is layered on.

### Phase 3a — publish proxy + dataset CRUD/lifecycle ✅

| Item | Status | Where |
|---|---|---|
| **Server-side publish proxy** (Goal 3): the stored service token is attached in PHP and Cloudflare's edge exchanges it for a JWT — the token never reaches the browser | ✅ | `src/Api/PublishClient.php` |
| Same-origin REST proxy `terraviz/v1/publisher/datasets*`, gated by the Phase-2 capability tiers (draft vs publish) + a credential-configured check; dataset body passed through a strict field allowlist | ✅ | `src/Rest/PublisherController.php` |
| Dataset **list / create / edit / publish / retract / delete** dashboard (a React `@wordpress/element` app) with inline field-validation errors | ✅ | `src/Admin/Dashboard.php`, `blocks/admin/*`, `webpack.config.js` |
| `manage_terraviz` → dashboard visibility; the REST layer re-checks `can_draft` / `can_publish` per call | ✅ | `src/Support/Capabilities.php` |

Verified upstream contracts: dataset lifecycle is derived from
`published_at`/`retracted_at` (no status column) and driven by separate
`POST .../publish` and `.../retract` routes; create needs only `title` +
`format`; publish additionally requires `slug, visibility, data_ref` and a
license. The publish routes need only the Access headers + a JSON body (no
CSRF/anti-forgery handshake to replicate). See
`functions/api/v1/publish/datasets*.ts` upstream.

### Phase 3b — asset upload 🔜

- **Asset upload**: the two-step presigned-R2 flow (init `POST .../asset` →
  browser PUTs bytes directly to the presigned URL with a client-computed
  `sha256:` digest → `POST .../asset/:upload_id/complete`), proxied so only the
  short-lived presigned URL reaches the browser. Video returns `202` and
  transcodes asynchronously.

Known limitation (upstream §5 Option 1): actions are attributed to the shared
`service` identity, not the individual WP user. The dashboard surfaces this.

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
