=== Terraviz ===
Contributors: zyraproject
Tags: terraviz, globe, noaa, science on a sphere, embed
Requires at least: 6.1
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Embed live Terraviz "Science On a Sphere" globes — a single dataset, a tour, or the full catalog — into WordPress pages and posts.

== Description ==

Terraviz visualizes NOAA "Science On a Sphere" datasets on an interactive 3D globe. This plugin lets a WordPress site drop a live Terraviz globe into any page or post using Gutenberg blocks or a shortcode.

It is a **host-side adapter**, not a reimplementation: the globe runs inside an iframe served from a Terraviz node, and the plugin talks only to Terraviz's public, versioned HTTP APIs. There are **no credentials** — the embed/read path is entirely public.

**What it does**

* Three Gutenberg blocks: **Dataset**, **Tour**, and **Catalog**.
* A `[terraviz]` shortcode for the Classic Editor, sharing the same renderer as the blocks.
* Automatic embeds when you paste a Terraviz dataset or tour URL.
* A **server-side rendered fallback** under every embed — real title, abstract, thumbnail, and a link — so the content is indexable by search engines, visible without JavaScript, and accessible to screen readers.
* **Lazy loading**: the heavy globe only loads when scrolled into view, or on click via a poster, so a page with several embeds stays fast.
* A settings screen to choose which Terraviz node embeds point at (default: the canonical public node), set default view options, and control the loading/telemetry posture.

**Privacy & self-containment**

The plugin makes no surprise outbound calls. Its only network requests are to the configured Terraviz node's **public catalog API**, used to render the server-side fallback, cached in WordPress transients. The interactive globe loads directly from the Terraviz node in the visitor's browser — nothing is proxied through your site — and carries that node's own telemetry. No external assets are bundled or fetched from third parties.

== Installation ==

1. Upload the `terraviz` folder to `/wp-content/plugins/`, or install through the Plugins screen.
2. Activate the plugin.
3. (Optional) Visit **Settings → Terraviz** to set the node origin and default embed options. The defaults work out of the box against the canonical public node.
4. Add a **Terraviz Dataset**, **Tour**, or **Catalog** block to any page or post, or use the `[terraviz dataset="..."]` shortcode.

== Frequently Asked Questions ==

= Do I need an account or API key? =

No. This release only embeds public content; there are no credentials anywhere. (Publishing datasets from WordPress is a later, separately gated phase.)

= Where does the globe load from? =

From the Terraviz node origin you configure (default `https://terraviz.zyra-project.org`), directly in the visitor's browser via an iframe. Your site never proxies globe data.

= Is it accessible and SEO-friendly? =

Yes. Every embed renders a real HTML fallback (heading, abstract, thumbnail, canonical link) on the server, which is what crawlers index and what shows without JavaScript. The interactive globe is progressive enhancement layered on top.

= Can I point it at my own Terraviz node? =

Yes — set the node origin in **Settings → Terraviz**, or override it per block/shortcode with the `origin` attribute.

== Shortcode ==

`[terraviz dataset="INTERNAL_SOS_768" terrain="on" rotate="on"]`
`[terraviz tour="climate-futures"]`
`[terraviz catalog="true"]`

Attributes: `dataset`, `tour`, `catalog`, `origin`, `terrain`, `labels`, `borders`, `rotate`, `chat`, `layout` (1|2|4), `aspect` (e.g. `16:9`), `poster`, `interactive`, `heading` (h2–h6), `show_title`, `show_abstract`.

== Changelog ==

= 0.1.0 =
* Initial release — Phase 1 zero-credential embeds.
* Dataset, Tour, and Catalog blocks with server-side rendered, accessible fallbacks and lazy iframe loading.
* `[terraviz]` shortcode and automatic URL embeds sharing one renderer.
* Settings screen: node origin, default embed options, loading/telemetry posture.
* Catalog/dataset caching in transients.
* PHP wire-contract types generated from Terraviz's published `/schema/v1` JSON Schema.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
