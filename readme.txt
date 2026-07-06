=== Terraviz ===
Contributors: zyraproject
Tags: terraviz, globe, embed, visualization, iframe
Requires at least: 6.1
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Embed live Terraviz "Science On a Sphere" globes into WordPress, and optionally publish and manage Terraviz datasets from wp-admin.

== Description ==

Terraviz visualizes NOAA "Science On a Sphere" datasets on an interactive 3D globe. This plugin lets a WordPress site **embed** live Terraviz globes into any page or post, and — optionally — **publish and manage** Terraviz datasets from wp-admin.

It is a **host-side adapter**, not a reimplementation: the globe runs inside an iframe served from a Terraviz node, and the plugin talks only to Terraviz's public, versioned HTTP APIs. **Embedding is entirely public and needs no account.** Publishing is an optional, administrator-configured capability that authenticates to a Terraviz node with a service token kept server-side — see *External services & privacy* below.

**Embed (no credentials required)**

* Five Gutenberg blocks: **Dataset**, **Tour**, **Catalog**, **Right-Now Hero** (the node's featured dataset), and **Related Datasets** (a "more like this" rail).
* A `[terraviz]` shortcode for the Classic Editor, sharing the same renderer as the blocks.
* Automatic embeds when you paste a Terraviz dataset or tour URL.
* A **server-side rendered fallback** under every embed — real title, abstract, thumbnail, and a link — so the content is indexable by search engines, visible without JavaScript, and accessible to screen readers.
* **Lazy loading**: the heavy globe only loads when scrolled into view, or on click via a poster, so a page with several embeds stays fast.

**Publish (optional, requires a service token)**

* A **Publisher dashboard** in wp-admin: list datasets by lifecycle state (draft, published, retracted), create and edit drafts, and publish, retract, or delete them.
* **Asset upload** for dataset media, sent directly from the browser to the node's storage via short-lived presigned URLs.
* **WordPress-native authorization**: what a user may do maps from their WordPress role — authors draft, editors publish, administrators configure. Every Terraviz write is proxied through PHP under a single shared node credential; the token never reaches the browser.
* Because writes share one node-scoped "service" identity, Terraviz attributes every action to that identity rather than the individual WordPress user, and the dashboard makes this explicit.
* **Surface a WordPress post in Terraviz** (optional): a "Show this post in Terraviz" toggle in the post editor publishes a short, linked-back summary of a published post to the Terraviz blog for in-globe discovery, carrying the datasets/tours cited by the Terraviz blocks in the post. WordPress stays the source of truth — only a summary and a link home are sent, and turning the toggle off (or unpublishing the post) removes it again.

A settings screen (**Settings → Terraviz**) chooses which Terraviz node embeds point at (default: the canonical public node), sets default view options, controls the loading/telemetry posture, and stores the optional publishing service token.

**External services & privacy**

This plugin communicates with a **Terraviz node** — a third-party service, by default `https://terraviz.zyra-project.org`, which a site operator can change in the settings or per block. It is not operated by WordPress.org. What is sent, and when:

* **Reading catalog data (server-side).** To render each embed's fallback, WordPress fetches public, read-only catalog data from the node (dataset titles, abstracts, thumbnails, related/featured lists) and caches it in WordPress transients. These are GET requests; no personal data is sent.
* **Loading the interactive globe (visitor's browser).** The globe loads in an iframe served directly from the node in each visitor's browser — nothing is proxied through your site. The node, not this plugin, governs that frame and any telemetry it carries; account for it as you would any embedded third-party iframe in your own privacy policy.
* **Publishing (server-side, only when configured).** If an administrator has stored a publishing service token and a permitted user acts in the Publisher dashboard, the dataset fields you enter and your lifecycle actions (create, update, publish, retract, delete) are sent from PHP to the node's publish API. That API sits behind **Cloudflare Access**; the plugin attaches a Cloudflare Access service token (a client id + client secret) as request headers on the server. The secret is encrypted at rest and is never sent to the browser.
* **Uploading assets (browser → storage).** When you upload dataset media, the file is hashed in your browser and its bytes are uploaded **directly from your browser to the node's Cloudflare R2 storage** through a short-lived presigned URL the plugin obtains server-side. The plugin proxies only small init/complete metadata; the service token is never exposed to the browser.
* **Surfacing a post in Terraviz (server-side, only when opted in).** If a permitted user turns on "Show this post in Terraviz" for a published post, the plugin sends that post's title, a short summary with a link back to the post embedded in it, and the ids of the datasets/tours it cites from PHP to the node's blog API (behind the same Cloudflare Access service token). Only that summary and link are sent — never the full post body — and it is removed when the post is opted out, unpublished, or deleted.

No third-party assets are bundled or loaded from CDNs, and the plugin makes no other outbound calls.

Using a Terraviz node is subject to that node's privacy policy. For the default node, see https://terraviz.zyra-project.org/privacy.

== Installation ==

1. Upload the `terraviz` folder to `/wp-content/plugins/`, or install through the Plugins screen.
2. Activate the plugin.
3. (Optional) Visit **Settings → Terraviz** to set the node origin and default embed options. The defaults work out of the box against the canonical public node.
4. Add a **Terraviz Dataset**, **Tour**, **Catalog**, **Right-Now Hero**, or **Related Datasets** block to any page or post, or use the `[terraviz dataset="..."]` shortcode.

== Frequently Asked Questions ==

= Do I need an account or API key to embed globes? =

No. Every block, shortcode, and URL embed works with no credentials — embedding only reads public data and loads a public iframe.

= How do I publish or manage datasets? =

Publishing is optional. An administrator stores a Terraviz **service token** under **Settings → Terraviz**, after which the **Terraviz** menu in wp-admin opens a Publisher dashboard where permitted users can create, edit, publish, retract, and delete datasets and upload their media. With no token stored, the dashboard is disabled and reports that a credential is not configured.

= Who is allowed to publish? =

Authorization is enforced in WordPress and maps from each user's role: administrators configure the node and token and can do everything; editors can create, edit, publish, and retract; authors can create and edit drafts; contributors and subscribers can embed blocks only. Because every write is proxied under one shared node credential, Terraviz attributes all actions to a single "service" identity — the dashboard shows this.

= How is the service token stored? =

In its own WordPress option, separate from the general settings, and never exposed through the REST API or returned to the browser. The client id is stored in the clear (it is only semi-secret); the client **secret** is encrypted at rest with authenticated encryption (libsodium secretbox, or OpenSSL AES-256-GCM as a fallback), keyed to your site's WordPress salts so the ciphertext does not travel with a database dump. If neither crypto library is available, the plugin refuses to store the secret rather than keep it in the clear. Rotating your WordPress salts invalidates a stored secret, which must then be re-entered.

= Uploading a dataset asset fails with a CORS or network error =

Asset bytes upload directly from your browser to the Terraviz node's object storage, which is a cross-origin request, so the node operator must allow your WordPress site's origin in the storage bucket's CORS policy. Uploads also require a secure (HTTPS) context, because the browser hashes the file before uploading.

= Where does the globe load from? =

From the Terraviz node origin you configure (default `https://terraviz.zyra-project.org`), directly in the visitor's browser via an iframe. Your site never proxies globe data.

= Is it accessible and SEO-friendly? =

Yes. Every embed renders a real HTML fallback (heading, abstract, thumbnail, canonical link) on the server, which is what crawlers index and what shows without JavaScript. The interactive globe is progressive enhancement layered on top.

= Can I point it at my own Terraviz node? =

Yes — set the node origin in **Settings → Terraviz**, or override it per block/shortcode with the `origin` attribute.

For safety, a per-embed `origin` override changes which node the interactive globe (iframe) loads from, but the plugin only fetches the server-side fallback data from the site-configured node. A site owner can allow the server-side fetch to use additional trusted nodes with the `terraviz_allowed_fetch_origins` filter.

== Shortcode ==

`[terraviz dataset="hurricane-season-2024" terrain="on" rotate="on"]`
`[terraviz tour="climate-futures"]`
`[terraviz catalog="true"]`
`[terraviz hero="true"]`
`[terraviz related="hurricane-season-2024"]`

The `dataset` and `tour` attributes accept a human-readable **slug** (e.g. `hurricane-season-2024`), a legacy id, or the canonical catalog id — you don't need to copy the long random id. You can also paste a Terraviz dataset/tour URL on its own line to auto-embed it.

Attributes: `dataset`, `tour`, `catalog`, `hero`, `related`, `origin`, `terrain`, `labels`, `borders`, `rotate`, `chat`, `layout` (1|2|4), `aspect` (e.g. `16:9`), `poster`, `interactive`, `heading` (h2–h6), `show_title`, `show_abstract`.

== Changelog ==

= 0.4.0 =
* Post/blog bridge: a "Show this post in Terraviz" toggle in the post editor publishes a short, linked-back summary of a published post to the Terraviz blog for in-globe discovery, carrying the datasets/tours cited by the Terraviz blocks in the post. One-way only — WordPress stays the source of truth; opting out, unpublishing, or deleting the post removes the summary.

= 0.3.0 =
* Publisher dashboard in wp-admin: list datasets by lifecycle state, create and edit drafts, and publish, retract, or delete them — all proxied server-side under a single shared node credential, so the token never reaches the browser.
* Direct-to-storage asset upload for dataset media, via short-lived presigned URLs (the file is hashed in the browser; bytes go straight to the node's storage).
* WordPress-native authorization: a role-to-tier mapping (administrators configure, editors publish, authors draft) gates who may do what through the plugin.
* Encrypted-at-rest Cloudflare Access service-token slot in the settings screen, plus a read-only "Verify credential" probe.
* Custom `manage_terraviz` capability, granted to administrators on activation, so a site owner can delegate Terraviz configuration without full `manage_options`.

= 0.1.0 =
* Initial release — Phase 1 zero-credential embeds.
* Dataset, Tour, and Catalog blocks with server-side rendered, accessible fallbacks and lazy iframe loading.
* `[terraviz]` shortcode and automatic URL embeds sharing one renderer.
* Accepts a human-readable dataset/tour slug (or legacy id), resolved to the canonical id — no need to type the long random id.
* Block-editor typeahead: search datasets and tours by title and pick one; a "Copy shortcode" button helps Classic-Editor authors.
* Settings screen: node origin, default embed options, loading/telemetry posture.
* Catalog/dataset caching in transients.
* PHP wire-contract types generated from Terraviz's published `/schema/v1` JSON Schema.

== Upgrade Notice ==

= 0.4.0 =
Adds an optional "Show this post in Terraviz" toggle that publishes a linked-back post summary to the Terraviz blog for discovery. Embedding and the publisher dashboard are unchanged.

= 0.3.0 =
Adds an optional publisher dashboard for managing Terraviz datasets from wp-admin. Embedding is unchanged and still needs no account; publishing is opt-in and requires an administrator to store a Terraviz service token.

= 0.1.0 =
Initial release.
