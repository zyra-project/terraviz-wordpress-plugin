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

1. **Blog list (read) + reverse map** — `list_blog` client + `GET
   /publisher/blog` (decorated with `wp_edit_url`) + `Blog.js` (tiles, table,
   View, Edit-in-WordPress) + wire the sidebar tab to built. New-post → WP
   editor. *No node writes; lowest risk.*
2. **Seed WP post from a node post** — `POST /publisher/blog/:id/import-to-wp`
   + the "Create WordPress post" action + md→content conversion (v1).
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

---

## 8. Open questions

1. **Markdown → blocks fidelity** on import — acceptable to start with a simple
   wrap/HTML pass and refine to real block conversion later? (Assumed yes.)
2. **Unlinked published node posts** with no WP post — offer only "Create
   WordPress post", or also a plain read view? (Plan: seed + View.)
3. **Template** (§4.4) — worth a dedicated slice, or fold grounding guidance
   into the seeded post's starter content?
