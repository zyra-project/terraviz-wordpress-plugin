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
| Dashboard menu visible to the draft tier (`publish_posts`); the REST layer re-checks the precise tier (`can_draft` / `can_publish`) per call | ✅ | `src/Support/Capabilities.php`, `src/Admin/Dashboard.php` |

Verified upstream contracts: dataset lifecycle is derived from
`published_at`/`retracted_at` (no status column) and driven by separate
`POST .../publish` and `.../retract` routes; create needs only `title` +
`format`; publish additionally requires `slug, visibility, data_ref` and a
license. The publish routes need only the Access headers + a JSON body (no
CSRF/anti-forgery handshake to replicate). See
`functions/api/v1/publish/datasets*.ts` upstream.

### Phase 3b — asset upload ✅

| Item | Status | Where |
|---|---|---|
| Proxy `init` (`POST .../asset`) + `complete` (`POST .../asset/:upload_id/complete`) methods | ✅ | `src/Api/PublishClient.php` |
| REST `POST /publisher/datasets/:id/asset` + `.../complete`, under the same published-lifecycle gate as edit, with an init-body allowlist (`kind`/`mime`/`size`/`content_digest`) | ✅ | `src/Rest/PublisherController.php` |
| Upload UI: client-side `sha256` (`crypto.subtle`) → `init` → **direct** `PUT` to the presigned R2 URL → `complete`; reflects the `202` video-transcoding state and refreshes the dataset | ✅ | `blocks/admin/{AssetUploader,upload}.js`, `blocks/admin/DatasetForm.js` |

Only the short-lived presigned URL reaches the browser; the service token stays
server-side. Video returns `202` and transcodes asynchronously.

> **Operator note:** the byte `PUT` is a cross-origin request from the WordPress
> site to the node's R2 bucket, so the bucket's CORS policy must allow the WP
> site's origin. This is inherent to the "presigned, token-stays-server-side"
> design (streaming multi-GB video through PHP isn't viable). A failed PUT
> surfaces a message pointing at the CORS requirement.
>
> **Known limit:** the browser hashes the whole file via `crypto.subtle.digest`
> (whole-file, in memory), so extremely large videos may be slow or
> memory-bound; a streaming/chunked hash is a later enhancement.

Known limitation (upstream §5 Option 1): actions are attributed to the shared
`service` identity, not the individual WP user. The dashboard surfaces this.

---

## Phase 4 — post / blog bridge ✅ (plugin repo)

- **Cite Terraviz data in posts** — the existing Phase-1 blocks already embed a
  live dataset/tour/related-rail into any post, and the sync reads them as the
  citation source (no separate block needed).
- **One-way** WP-post → Terraviz-blog-*stub* sync for in-globe discovery. WP
  stays the source of truth; two-way body sync is a non-goal.

| Item | Status | Where |
|---|---|---|
| Blog proxy methods (`create`/`update`/`get`/`publish`·`unpublish` action) | ✅ | `src/Api/PublishClient.php` |
| Sync engine: opt-in post meta; `wp_after_insert_post` decides sync/unsync (gated on `can_publish` + credential) and defers to a near-immediate cron event; create→publish / `PUT`-update / unpublish with **id-in-post-meta idempotency** (`404` → recreate) | ✅ | `src/Blog/Sync.php` |
| Grounding: `datasetIds`/`tourId` scanned from the post's Terraviz blocks (`parse_blocks`), resolved to canonical ids | ✅ | `src/Blog/Sync.php` |
| Body: markdown **summary + canonical link back** to the WP post (allowlist-safe) | ✅ | `src/Blog/Sync.php` |
| Editor "Show this post in Terraviz" document panel (publish-tier only), with synced status/link | ✅ | `src/Blog/PostPanel.php`, `blocks/post-panel/*` |

Verified blog wire contract (upstream `main`): body is
`{ title, bodyMd, summary?, datasetIds?, tourId?, eventId? }`; create is
draft-on-create then `POST …/blog/:id {action:'publish'}`; **no server dedup**
(the plugin owns idempotency via the returned `post.id`); grounding is
write-permissive, read-strict.

> **Caveat:** the blog API is an **internal** publisher API — **not** part of the
> versioned `/schema/v1` contracts and absent from the protocol docs. It is less
> stable than the read/embed path; guard with `tests/smoke/`.

---

## Phase 5 — newsroom curation: events + feeds ✅ (plugin repo)

The "incoming news → curated event → globe front page" pipeline the mockup calls
the **Newsroom**. Feed connectors ingest current events; a curator reviews each
proposed event and confirms the datasets it pairs with. Both slices ship in the
publisher dashboard today (built after Phase 4; the plan doc previously stopped
at the blog bridge).

| Item | Status | Where |
|---|---|---|
| **Event review queue** — list by bucket (`proposed`/`approved`/`rejected`/`expired`/`all`), approve/reject, accept/reject suggested dataset links, add datasets, bounded field edits (date/location/image) | ✅ | `blocks/admin/{EventList,EventReview}.js`, `PublisherController::{list,review}_event`, `PublishClient::{list_events,review_event}` |
| **Feed connectors** — list/create/update/delete RSS + EONET sources, enable/pause toggle, dry-run **preview** (writes nothing) | ✅ | `blocks/admin/{FeedList,FeedForm}.js`, `PublisherController` feeds routes, `PublishClient` feeds methods |
| Tier gating: event review is **publish-tier**; feed management is **configure-tier** (the node restricts every feed endpoint, reads included, to admin/service callers) | ✅ | `PublisherController::require_{publish,configure}` |

Verified upstream contracts: events are born `proposed` and the caller submits a
single review POST (`{ event?, addDatasetIds?, links?, edits? }`); feeds are a
patch-over-POST connector CRUD with an immutable `kind`. Both are **internal**
publisher APIs — guard with `tests/smoke/`.

---

## Phase 6 — publisher-dashboard parity with the app mockup 🔜 (plugin repo)

The Terraviz app's own **Publisher Portal** mockup
([UI/UX review deck](https://docs.google.com/presentation/d/15yxZJrtdjtUy5PhG1pdnq8SpPfMXkv5dLgQaRjeHla8/edit))
is a grouped left-sidebar IA — **Overview · Catalog · Newsroom · Insights ·
Settings** — over a much wider surface than the plugin's current flat
`Datasets | Events | Feeds` tab strip. This phase brings the wp-admin dashboard
toward that shape. **Feasibility is confirmed**, not speculative: reading
upstream `functions/api/v1/publish/**`, every mockup area is backed by an
existing endpoint (`analytics`, `feedback`, `media/youtube-channels`,
`events/[id]/tour`, `tours*`, `blog`, `featured-hero`, `node-profile`,
`publishers`, `workflows`). The only work is the plugin's own proxy → REST → UI
slices; the read/embed **no-credential** posture and the shared-`service`
identity model (Phase 2) are unchanged.

> **Method (unchanged):** each new area is a thin `PublishClient` method + a
> capability-gated `terraviz/v1/publisher/*` route + a dashboard view, against a
> contract already located upstream. Internal publisher APIs stay guarded by
> `tests/smoke/`.

### Milestone A — sidebar shell + Overview home ✅ (PR #30)

| Item | Status | Where |
|---|---|---|
| Replace the flat tab strip with the grouped sidebar (Overview / Catalog / Newsroom / Insights / Settings); sections gate on existing caps, unbuilt items render a "coming soon" slot so the IA is complete from day one | ✅ | `blocks/admin/{App,Sidebar}.js` |
| **Overview** landing page built from data the plugin already has — "Needs you" cards (events awaiting review, missing credential/expiring hero), at-a-glance counts (draft/published/retracted, event-queue depth) | ✅ | `blocks/admin/Overview.js` |

No new PHP/REST — pure JS re-arrangement over proven endpoints, **zero upstream
risk**. "Recent activity" / "Latest feedback" fill in once Feedback (Milestone
C) lands.

> **Datasets screen restyled to the mockup (JS-only):** the list now matches the
> Publisher Portal Datasets scene — subtitle, status count tiles that double as
> filters, and a table with a thumbnail preview, inline status badge, format, and
> last-updated, with the lifecycle row actions rendered as uniform understated
> links (`edit / publish / retract / delete`). The parent fetches the whole
> catalog once and filters client-side so the tiles show true totals. All fields
> come from the existing `publisher/datasets` list (`format`, `updated_at`,
> `thumbnail_url`, `published_at`/`retracted_at`). Where: `blocks/admin/{App,
> DatasetList}.js`.

> **Follow-up:** the Overview counts currently reuse the paginated
> `listDatasets()` (all pages) purely to tally draft/published/retracted. A
> dedicated `publisher/summary` proxy route returning counts + queue depth (or a
> cached summary with manual refresh) would avoid the full paginated walk on
> large catalogs — a small later optimization, deliberately kept out of the
> client-only Milestone A.

### Milestone B — finish the already-built areas 🔜

Cheap, high-value adds on **verified** contracts:

| Item | Status | Upstream contract |
|---|---|---|
| **Right-now hero** management (set dataset + start/end + optional headline; clear), with the catalog-card preview | ✅ | `PUT/DELETE featured-hero.ts` (`{errors:[…]}` envelope) |
| **Media-channels** sub-tab on the Feeds screen (list builtin + custom YouTube channels, add by URL) | ✅ | `GET/POST media/youtube-channels.ts` → `{channels:[{channelId,channelName,builtin}]}` / `201 {channel}` |
| **Generate tour** button on the event-review screen (publish-tier) | ✅ | `POST events/[id]/tour.ts` → `201` tour draft |
| In-dashboard **Blog list** view (drafts/published, edit/view) — complements the existing WP→Terraviz post sync | 🔜 | `GET blog.ts?status=` |

> **Right-now hero (shipped):** a `Newsroom → Right now` view (publish-tier)
> reading the current pin via the public `GET /api/v1/featured-hero` and
> setting/clearing it through the proxy (`PUT`/`DELETE
> /api/v1/publish/featured-hero`). The write body is `{ dataset_id, window:{
> start, end }, headline? }` (window mandatory upstream); the `DELETE` returns
> `204`, so `PublishClient::send()` now accepts a bodyless 2xx as success. Where:
> `PublishClient::{get,set,clear}_featured_hero`,
> `PublisherController` (`HERO_BASE` routes + `normalize_hero_body`),
> `blocks/admin/RightNow.js`.

> **Media channels + Generate tour (shipped):** the Feeds screen gained a
> `News feeds | Media channels` sub-tab strip (`blocks/admin/SubTabs.js`); the
> **Media channels** view (`blocks/admin/MediaChannels.js`, configure-tier) lists
> built-in + custom YouTube channels and adds/removes custom ones by URL
> (`PublishClient::list_media_channels` / `create_media_channel` /
> `delete_media_channel`, `MEDIA_BASE` routes + `normalize_media_channel_body`).
> The **Generate tour** button on the
> event-review screen (`blocks/admin/EventReview.js`, publish-tier) creates an
> editable tour draft and links the curator into the node's tour author
> (`?tourEdit=<id>`); `PublishClient::generate_event_tour` +
> `POST events/{id}/tour` route.
>
> **Suggested-media pane on Event review (shipped).** The event-review screen
> gained a **Suggested media** pane (`blocks/admin/MediaSuggest.js`, pure builders
> in `mediaSources.js`) offering the app's full 5-source engine of story-image +
> video candidates for an event: **NASA Worldview** snapshot, **USGS ShakeMap**
> (earthquakes), **NHC forecast cone** (named storms), **Wikimedia Commons**
> nearby PD/CC0 photos, and **agency-YouTube** cards, plus **upload your own
> photo**. A pick fills the review's `imageUrl` / `videoEmbedUrl` fields (saved
> through the existing review submit); the event image then flows downstream to
> the derived blog post and generated tours automatically. `youtube-search` +
> `nhc-storms` go through the node proxy; Commons + USGS are keyless CORS APIs
> fetched client-side (the plugin's PHP never calls a third party). Publish-tier:
> `PublishClient::{search_youtube_media,list_nhc_storms,set_event_image}`,
> `PublisherController` (`YT_SEARCH_BASE`/`NHC_BASE` + `events/{id}/image` routes,
> `normalize_event_image_body` — length preflight + magic-byte image check before
> forwarding). See the Blog plan §9.
>
> **Blog — reframed around WordPress posts (shipped).** Rather than a node-side
> editor, blog authoring stays in WordPress: the dashboard Blog list surfaces the
> node's posts with **View** (node `/blog/:slug`) and **Edit in WordPress**
> (reverse-mapped via the existing `Sync::ID_META` post meta) (slice 1, PR #33);
> a **Create WordPress post** action seeds a WP draft from a node-authored post as
> real Gutenberg + Terraviz embed blocks, carrying the node post's cover/media
> across, so Terraviz can "drive the initial content" and the existing WP→node
> sync carries the markdown back with a link home (slice 2, PR #34); and a
> **"Terraviz-grounded post" block pattern** (+ Terraviz pattern category) gives
> authors starting a fresh post the same grounding scaffold — a lead paragraph,
> an "Explore the data" heading, a live dataset embed, and a nudge to the opt-in
> panel (slice 3, `src/Blog/Patterns.php`). See the Blog plan.

### Milestone C — new capability areas ⏳

Each its own proxy → REST → UI slice, in likely value order. Contracts already
located upstream:

| Area | Upstream contract | Note |
|---|---|---|
| **Analytics** | `analytics.ts`, `analytics-export.ts` | sessions / view time / platform-OS mix / top countries + CSV |
| **Feedback** | `feedback.ts` | Orbit AI ratings + general feedback |
| **Tours CRUD** | `tours.ts`, `tours/[id]*.ts`, `tours/draft.ts` | full lifecycle (embed block already exists for read) |
| **Import** | (bulk manifest) | CSV/JSON → drafts; remote-node + CLI paths are out of plugin scope |
| **Workflows** | `workflows.ts`, `workflows/[id].ts`, `workflows/due.ts` | scheduled refresh pipelines |
| **Node profile** | `node-profile.ts`, `node-profile/logo.ts` | org identity + logo |
| **Team** | `publishers.ts` | ⚠️ **read-only / deferred** — clashes with the shared-`service` identity (Phase 2); the dashboard surfaces "acting as the shared publisher" rather than per-user management |

---

## Phase 7 — per-user auth ⏳ (conditional, mostly main-repo)

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
