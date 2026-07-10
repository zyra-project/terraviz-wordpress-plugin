# Blog area — WordPress posts as the authoring surface

**Status:** plan for review · **Phase:** 6 (Milestone B, Blog slice) ·
**Owner:** plugin dashboard track

The plugin-local design for the publisher dashboard's **Blog** area. It refines
the one-line Milestone-B entry ("in-dashboard Blog list view — complements the
existing WP→Terraviz post sync") into the model the maintainer asked for:
**WordPress posts are the blog**, Terraviz can seed the initial content, the
existing sync carries a markdown version back to the node, and the node's
`/blog` entry points home at the WordPress post.

> Cross-refs: [`IMPLEMENTATION_PLAN.md`](IMPLEMENTATION_PLAN.md) (Phase 4 blog
> bridge + Phase 6 Milestone B); the deck's **Blog** scene
> ([UI/UX review deck](https://docs.google.com/presentation/d/15yxZJrtdjtUy5PhG1pdnq8SpPfMXkv5dLgQaRjeHla8/edit)).

---

## 1. Principle

A Terraviz blog post **is a WordPress post**. We do not build a node-side
markdown editor in wp-admin; we lean on the WordPress environment authors
already have (the block editor, media library, revisions, roles). Terraviz is
the *distribution + discovery* surface: the node shows a short, grounded stub
under `/blog/:slug` that links back to the full WordPress post.

This keeps WordPress the source of truth (upstream §6/§9 non-goal: no two-way
body sync) and reuses the machinery already shipped in Phase 4.

### What flows which way

| Direction | Mechanism | Status |
|---|---|---|
| WP post → node blog stub (markdown summary + link home, grounded in cited datasets) | `src/Blog/Sync.php` (opt-in meta → cron → create/`PUT`/publish) | ✅ shipped (Phase 4) |
| Node blog draft → **new WP post** (Terraviz "drives the initial content") | **new** — this plan | 🔜 |
| Dashboard visibility into node posts (list, View, Edit-in-WordPress) | **new** — this plan | 🔜 |

---

## 2. What already exists (reuse, don't rebuild)

- **`src/Blog/Sync.php`** — one-way WP→node sync. On a publish-tier author
  opting a published post in (`_terraviz_blog_id` … see meta below), a cron job
  creates a node `blog_posts` stub (`create_blog` → `set_blog_action publish`),
  `PUT`s on re-sync, and unpublishes on opt-out/delete. Idempotency is owned via
  the returned node id stored in post meta.
  - Post-meta keys (the link between a WP post and its node stub):
    - `Sync::OPTIN_META` = `_terraviz_blog_optin`
    - `Sync::ID_META`   = `_terraviz_blog_id`  ← **the reverse-map key**
    - `Sync::SLUG_META` = `_terraviz_blog_slug`
    - `Sync::STATE_META`= `_terraviz_blog_state` (`synced|unsynced|error`)
  - The stub body already includes a canonical **"Read the full story on <site> →"**
    link back to the WP permalink (`Sync::body_md`). *This is the "node /blog
    points at the WordPress post" the maintainer described — already true for
    synced posts.*
- **`src/Blog/PostPanel.php`** + `blocks/post-panel/` — the "Show this post in
  Terraviz" document-panel toggle in the block editor (publish-tier only).
- **`PublishClient`** blog methods: `create_blog`, `update_blog`, `get_blog`,
  `set_blog_action`. **Missing:** `list_blog`.
- Upstream read API: `GET /blog`, `GET /blog/:slug` (public); publisher API
  `GET /api/v1/publish/blog?status=draft|published` → `{ posts: [toPublicPost] }`.

### Verified upstream contract (publisher blog list)

`GET /api/v1/publish/blog` — any signed-in publisher may read; `?status=` is
`draft|published`. Each post (`toPublicPost`):

```
{ id, slug, title, summary, bodyMd, datasetIds, eventId, authorId,
  status: 'draft'|'published', createdAt, updatedAt, publishedAt, tourId }
```

---

## 3. The gap

1. No dashboard **Blog list** — the sidebar tab is a "coming soon" slot.
2. No **reverse map** from a node post back to its WordPress post (the data
   exists in `_terraviz_blog_id`; nothing reads it that direction).
3. No **node→WP seeding** — a post authored/drafted on the node (e.g. an
   AI-assisted draft) can't be pulled into WordPress to finish and own.
4. `PublishClient` has no `list_blog`.

---

## 4. Design

### 4.1 Dashboard Blog list (Newsroom → Blog), publish-tier

Mirrors the deck's Blog scene: a subtitle, **"New post"**, and a table
**Title · Status · Updated · Actions**. Read from the node via a new proxy:

- `PublishClient::list_blog( array $query = [] )` → `GET /api/v1/publish/blog[?status=]`.
- `PublisherController` route `GET /publisher/blog` (publish-tier; the sidebar
  tab is already publish-tier, keeping nav == REST gate).
- `blocks/admin/Blog.js` — status filter tiles (Draft / Published, reusing the
  `DatasetList` tile+`SubTabs` patterns), table, actions.

**Row actions**, resolved against the WP↔node link:

| Node post state | Action(s) |
|---|---|
| Linked to a WP post (`_terraviz_blog_id` match found) | **Edit in WordPress** (deep-link to `post.php?post=<wpId>&action=edit`), **View** (node `/blog/:slug`, published only) |
| No linked WP post | **Create WordPress post** (§4.3), **View** (if published) |

**New post** → the WordPress new-post editor (`post-new.php`), with the "Show
this post in Terraviz" panel already available there. Authoring stays in WP.

### 4.2 Reverse map (node id → WP post)

A small server-side helper: `get_posts([ meta_key => Sync::ID_META, meta_value
=> <nodeId>, post_type => 'post', post_status => 'any', fields => 'ids' ])`,
returning the WP post id or null. Exposed to the dashboard by decorating each
listed node post with a resolved `wp_edit_url` (null when unlinked) so the
browser never guesses. Computed server-side in the `GET /publisher/blog`
handler (it already runs in PHP with WP context).

### 4.3 Seed a WordPress post from a node post ("Terraviz drives the content")

New action **Create WordPress post** on an unlinked node post:

- REST: `POST /publisher/blog/:id/import-to-wp` (publish-tier).
- Handler: `get_blog(:id)` → create a **draft** WP post with
  `wp_insert_post({ post_title, post_content, post_status: 'draft' })`, author =
  current user (real attribution, unlike the shared service identity).
  - `post_content` from the node `bodyMd`: convert markdown → blocks/HTML. v1 can
    wrap the markdown in a `core/freeform`/`core/html` block or a minimal
    md→HTML pass; a faithful md→blocks conversion is a later refinement (noted).
  - Set the link meta so the existing sync treats them as the same object:
    `ID_META = nodeId`, `SLUG_META = slug`, `STATE_META = 'synced'`, and
    `OPTIN_META = true` so a subsequent WP publish re-syncs (markdown back +
    link home) rather than creating a duplicate stub.
- Response: `{ wpId, editUrl }`; the dashboard redirects the author into the WP
  editor to finish and publish.

This realises the maintainer's three asks: WordPress posts as the surface,
Terraviz seeding initial content, and the markdown-back sync (via the existing
Phase-4 path on the next WP publish).

### 4.4 Optional: a Terraviz blog post template

"Perhaps a page template of some kind." A block-template / starter-pattern for
Terraviz-grounded posts (pre-seeds a dataset/tour block + the opt-in) so authors
get the grounding scaffold. **Deferred, optional** — not required for the flow
above; tracked as a later enhancement so the core list + seed ship first.

### 4.5 Media in the Blog area

Blog media is **WordPress-native**: the post's **featured image** and the media
library are the picker. The plugin adds *no* node-side media chooser for blog
posts. When a post is **grounded in an event**, the node already carries that
event's `image_url` into the blog stub's lead figure automatically (upstream
generation), so a grounded post inherits its image with no extra step.

**On seed (Slice 2), the node post's own media crosses into the WP post so the
draft opens fully illustrated:**

- **Cover image → WP featured image.** A node blog post carries a first-class
  `coverImageUrl` / `coverImageAlt` (the "Cover image" shown in the Terraviz blog
  editor). On import the plugin **sideloads** it into the media library and sets
  it as the post's featured image. The fetch uses `wp_safe_remote_get` (rejects
  private/reserved hosts — the URL may point at a third-party source such as NASA
  Worldview or a news photo), accepts only raster web image types
  (jpeg/png/gif/webp), and is size-capped. Best-effort: a failed or unsafe cover
  just leaves the draft without a featured image; the post still seeds.
- **Inline body media → blocks.** `markdown_to_blocks` now recognises a
  standalone `![alt](url)` line as a `core/image` block, an inline `![alt](url)`
  as an `<img>` within its paragraph, and a bare YouTube/Vimeo URL on its own line
  as a `core/embed` block. Every media URL is `esc_url`'d, so unsafe schemes are
  dropped (no tag emitted) exactly like body links.

Node media **suggestions** (suggested videos / images) are a distinct
capability that belongs on the **Event review** screen, not here — see §9.

---

## 5. Tiers, security, degradation

- **Publish-tier** throughout (matches the sidebar tab and the node's
  privileged-write posture; the shared service identity is used for the node
  read, so the plugin is the real gate — as elsewhere).
- Seeding creates a WP post **as the acting user** (`wp_insert_post` default
  author), so WordPress attribution is real even though node writes are the
  shared `service` identity.
- Sanitize node-supplied `bodyMd`/`title` on import (`wp_kses_post` /
  `sanitize_text_field`); the node content is publisher-authored but still passes
  through the allowlist before becoming WP content.
- No credential configured → the Blog list shows the same "connect a token"
  state as the rest of the dashboard; unlinked/never-synced posts still list.

---

## 6. Slices (ship in order)

1. ✅ **Blog list (read) + reverse map** — `list_blog` client + `GET
   /publisher/blog` (decorated with `wp_edit_url`) + `Blog.js` (tiles, table,
   View, Edit-in-WordPress) + wire the sidebar tab to built. New-post → WP
   editor. *No node writes; lowest risk.* (PR #33.)
2. ✅ **Seed WP post from a node post** — `POST /publisher/blog/:id/import-to-wp`
   creates a WP **draft** (authored by the acting user, gated on
   `current_user_can('edit_posts')`) from the node post's title + body, writes the
   `Sync` link meta (`ID`/`SLUG`/`OPTIN`) so the existing WP→node sync updates the
   same stub on publish, and is idempotent (returns the existing WP post when
   already linked). `Blog.js` adds a **Create WordPress post** action on unlinked
   posts that hands the author into the WP editor. The body is seeded as **real
   Gutenberg blocks** (`markdown_to_blocks`): paragraph / heading (clamped h2–h6)
   / list blocks, all user text escaped (`md_inline`) and `wp_kses_post`'d inside
   the block delimiters — not one Classic block. The post's grounding is then
   appended as **Terraviz embed blocks** (`terraviz/dataset` per linked dataset,
   `terraviz/tour` for a linked tour, under an "Explore the data" heading), so the
   linked data is live in the editor from the start. **Media crosses over too**:
   the node post's `coverImageUrl` is sideloaded (`wp_safe_remote_get`, raster
   types only, size-capped) and set as the WP **featured image**, and body
   `![alt](url)` images / bare video URLs become `core/image` / `core/embed`
   blocks (all URLs `esc_url`'d). Tables/ordered-lists/fenced-code (and richer
   md→block fidelity) are a later refinement.
3. **Blog post template** (optional) — starter pattern/template.

Each slice is its own PR. Slice 1 is the deck-faithful list and is independently
useful; slices 2–3 layer the node→WP authoring loop.

---

## 7. Non-goals

- A node-side rich blog editor in wp-admin (WordPress is the editor).
- Two-way **body** sync / WordPress as a mirror of node-edited content (upstream
  §6/§9). The stub stays a grounded pointer home.
- Auto-publishing: seeding creates a **draft**; nothing publishes without an
  explicit WP publish.
- A node-side media picker/suggestion UI in the Blog area — blog imagery is
  WordPress-native (featured image + media library). Node media *suggestions*
  live on Event review (§9).

---

## 8. Open questions

1. **Markdown → blocks fidelity** on import — acceptable to start with a simple
   wrap/HTML pass and refine to real block conversion later? (Assumed yes.)
2. **Unlinked published node posts** with no WP post — offer only "Create
   WordPress post", or also a plain read view? (Plan: seed + View.)
3. **Template** (§4.4) — worth a dedicated slice, or fold grounding guidance
   into the seeded post's starter content?

---

## 9. Related work — media suggestions live on Event review (not Blog)

This came up while scoping Blog ("where do media suggestions fit — YouTube
channels under Feeds, related images for the hero?"). They're a distinct,
contract-backed capability that belongs on the **Event review** screen, governed
by the Feeds → **Media channels** allowlist already shipped (#32). Captured here
so it isn't mistaken for Blog work; it gets its own slice/PR.

### The engine (verified upstream; privileged / publish-tier)

| Endpoint | Returns | Role |
|---|---|---|
| `GET media/youtube-search?q=` | `{ videos:[{videoId,title,channelId,channelName}] }` | video candidates **filtered to the allowlisted channels** (the payoff of the Media channels tab); KV-cached, degrades to `{videos:[]}` without a key |
| `GET media/nhc-storms` | `{ activeStorms:[{id,name}] }` | a forecast-cone suggestion matched to a storm event by name |
| `POST events/:id/image` `{ contentType, dataBase64 }` | sets the event `image_url` | upload the org's own photo (raster only, ≤4 MB) — the third path next to the feed `og:image` and the suggested picks |

### Placement — a "Suggested media" pane on the event-review screen

Video cards from `youtube-search` (seeded by the event title), an NHC cone card
from `nhc-storms` for storm events, and an image row (current image · suggested
picks · upload) that writes the chosen `imageUrl` / `videoEmbedUrl` through the
**existing event-review edit path**. Sits beside the "Generate tour" button
(#32).

### Why it's an Event feature — and how it still reaches Blog + hero

- The event `image_url` **flows downstream automatically**: the node uses it as
  the blog lead figure and the generated tour's intro/thumbnail. Choosing an
  event's media therefore enriches the derived blog post and tour **without** a
  Blog-side picker.
- The **hero** ("Right now") pins a *dataset*; its card image is that dataset's
  thumbnail. There is **no image-suggestion endpoint for the hero** in the
  current contracts — "suggest a variety of related images for the hero" is
  **net-new upstream work** (a suggestion endpoint + plugin UI), tracked as a
  wishlist item, not buildable today.

### Plugin work — ✅ shipped

Thin `PublishClient` reads (`search_youtube_media`, `list_nhc_storms`) + a
`set_event_image` (`events/:id/image`) upload proxy; capability-gated
`terraviz/v1/publisher/media/{youtube-search,nhc-storms}` + `events/:id/image`
routes (**publish-tier**, consistent with event review — distinct from the
configure-tier channel *allowlist*); and the Suggested-media pane
(`blocks/admin/MediaSuggest.js`, pure builders in `mediaSources.js`) wired into
`EventReview.js` below the edit fields.

**Sources built:** a **NASA Worldview** satellite snapshot (composed
client-side from the event's date + location — the URL *is* the image, no
fetch), an **NHC forecast cone** for named tropical storms (matched by name via
the `nhc-storms` proxy), and **agency-YouTube** video cards keyed by the event
title (`youtube-search` proxy), plus **upload your own photo** (base64 →
`events/:id/image`). A pick fills the review's `imageUrl` / `videoEmbedUrl`
fields and is saved through the existing review submit; a card self-hides when
its preview 404s.

**Security posture:** the image-upload proxy validates the base64 payload
before forwarding — decodes it, rejects anything over ~4 MB, and requires the
real bytes to be a raster image (`getimagesizefromstring`), forwarding the
*detected* MIME rather than the caller's claim. The video pick re-checks the
node's nocookie-embed host guard client-side (`isNocookieEmbedUrl`).

**Not built (still net-new upstream, unchanged):** Wikimedia Commons nearby
photos (a fourth image source upstream) were left out to keep the pane to the
plan's three contract-backed sources; and there's still **no hero
image-suggestion endpoint**, so the hero wishlist item remains upstream work.
