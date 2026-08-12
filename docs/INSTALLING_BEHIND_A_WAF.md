# Installing behind a WAF (Cloudflare "CVE-2019-7816" block)

If uploading `terraviz-<version>.zip` through **Plugins → Add New → Upload
Plugin** dies on a Cloudflare interstitial —

> **Sorry, you have been blocked** — You are unable to access `<your-host>`

— and your Cloudflare **Security → Events** log attributes it to the managed
rule **“Adobe ColdFusion – Dangerous File Upload – CVE:CVE-2019-7816”**, this
page is the fix.

Short version: **it's a false positive on the upload request, not a problem with
the plugin.** Either install the plugin by a route that isn't a browser upload
(no Cloudflare change needed — [jump](#option-a-install-without-the-browser-upload)),
or add a narrow WAF exception ([jump](#option-b-add-a-narrow-cloudflare-waf-exception)).

## What is actually being blocked

WordPress's "Upload Plugin" form posts the whole ZIP as a `multipart/form-data`
body to:

```
POST /wp-admin/update.php?action=upload-plugin
```

Cloudflare's managed rule for CVE-2019-7816 is a heuristic looking for the
ColdFusion unrestricted-file-upload pattern, and it inspects that request body.
A WordPress plugin ZIP is a few hundred KB of binary deflate stream posted to an
admin endpoint, which is close enough to trip it. This is a long-standing,
widely-reported false positive that hits WordPress plugin and theme uploads
generally — it is not specific to Terraviz. (Same rule, same symptom, different
plugins, across years of Cloudflare community threads — see
[Sources](#sources).)

The block happens **at Cloudflare's edge**. The request never reaches WordPress,
so there is nothing PHP-side — in this plugin or any other — that can catch,
handle, or work around it.

### For the record: what's in the ZIP

Worth stating plainly, since the rule's name implies otherwise. In
`terraviz-0.4.0.zip` (389 KB):

- **No ColdFusion, JSP, or ASP anything.** No `.cfm`, `.cfc`, `.cfml`, `.jsp`,
  `.jspx`, `.asp`, `.aspx`, `.phtml`, `.shtml`, or `.php5` files, and no such
  strings anywhere in the shipped sources.
- **Every file entry is deflate-compressed**, so no file *contents* appear as
  plaintext in the request body at all — only the entry names do (in the ZIP
  local headers and central directory), and those are ordinary
  `terraviz/src/**.php`, `terraviz/blocks/**.js`, `terraviz/build/**` paths.
- Contents are exactly what [`.distignore`](../.distignore) allows: `terraviz.php`,
  `uninstall.php`, `readme.txt`, `LICENSE`, `src/`, `blocks/`, `build/`, `assets/`.

The rule is matching the *shape* of the request, not any payload we ship.

## Option A: install without the browser upload

**Prefer this if you don't administer the Cloudflare zone** (on a managed or
institutional site, you often don't). None of these routes send the ZIP
*inbound* through the WAF, so the rule is never evaluated.

First, get the ZIP. There is **no published GitHub Release yet**, so today it
comes from one of:

- `npm ci && npm run package` in a clone → `dist/terraviz-<version>.zip`, or
- the **`terraviz-plugin-zip`** artifact on any green CI run (Actions → the run →
  Artifacts). Note that Actions artifacts are authenticated downloads — you get
  a ZIP-of-a-ZIP in your browser, not a URL a server can fetch anonymously.

### A1. WP-CLI, from a ZIP on the server *(easiest, if you have shell access)*

Copy the file over (`scp`, rsync, however you normally move files), then let
WP-CLI unpack and register it. This never crosses the WAF at all:

```bash
scp terraviz-0.4.0.zip you@your-host:~/
ssh you@your-host
wp plugin install ~/terraviz-0.4.0.zip --activate
```

### A2. WP-CLI, from a URL the server can reach

`wp plugin install` takes a URL as happily as a path, and the fetch is an
**outbound** request from your host — which Cloudflare's inbound WAF never
inspects. Useful once tagged Releases exist (the `release` workflow attaches the
ZIP to each one), or for any internal artifact store you already run:

```bash
wp plugin install https://example.internal/terraviz-0.4.0.zip --activate
```

### A3. SFTP / SSH, unzip in place

No WP-CLI? Unpack into the plugins directory by hand. The inner folder **must**
be named `terraviz` — that's the plugin slug the text domain and asset paths
assume (`bin/build-zip.sh` already names it that inside the archive):

```bash
cd /path/to/wp-content/plugins
unzip ~/terraviz-0.4.0.zip     # creates ./terraviz/
```

Then activate under **Plugins** in wp-admin. That page load is a normal GET and
won't trip the rule.

> Ownership matters: make the files readable (and owned) by the user your web
> server runs as, matching the other directories in `wp-content/plugins/`.

## Option B: add a narrow Cloudflare WAF exception

**Use this if you do administer the zone**, and especially if you'll be updating
the plugin (or any plugin) from wp-admin regularly. It's the option Cloudflare
itself points people to for managed-rule false positives.

The goal is to skip **only that one rule**, **only on the upload endpoint**, and
**only from your own IP** — not to disable the ruleset.

1. **Security → WAF → Managed rules**, on the affected zone.
2. Add an exception (Cloudflare has called this "Add exception" / "Skip rules" /
   "Browse rules" depending on dashboard vintage). Choose the type that skips
   **specific rules of a managed ruleset**, not "skip all remaining rules".
3. Find the rule by searching the Cloudflare Managed Ruleset for **`ColdFusion`**
   and selecting **“Adobe ColdFusion – Dangerous File Upload – CVE:CVE-2019-7816”**.
   (Cloudflare doesn't publish a stable rule ID for it in their docs — search by
   name in the dashboard.)
4. Scope the exception as tightly as you can. As a filter expression:

   ```
   (http.request.uri.path eq "/wp-admin/update.php"
     and http.request.uri.query contains "action=upload-plugin"
     and ip.src eq 203.0.113.10)
   ```

   Replace `203.0.113.10` with the admin's public IP. Add
   `action=upload-theme` (or drop the query clause) if you want theme uploads
   covered too.
5. **Order it before the Managed Ruleset.** This is the step people miss — an
   exception that evaluates *after* the managed rules does nothing. Drag it
   above the managed ruleset in the rule list.
6. Retry the upload, then re-check **Security → Events** to confirm it's allowed
   and that you didn't over-broaden the skip.

**Remove or re-tighten the exception when you're done** if you scoped it to a
temporary IP. Leaving a permanent, IP-unrestricted skip on `update.php` is a
meaningfully worse security posture than the block you're working around —
plugin upload is exactly the endpoint you'd want guarded.

### What not to do

- **Don't disable the whole "Cloudflare Specials" group** or the managed ruleset
  to get past this. It's a huge blast radius for one rule.
- **Don't pause Cloudflare** for the zone; it drops WAF *and* everything else
  (DDoS protection, Access, caching) site-wide while it's off.
- **Don't route around it with an "upload larger plugins"-style helper plugin.**
  These are commonly recommended for this exact block: they re-post the ZIP over
  AJAX, which happens not to match the rule. It works, but it dodges the control
  incidentally rather than scoping an exception to it — and you're installing a
  third-party plugin to do so. Same trade as Option B, more moving parts, less
  visibility into what's now unguarded.

## Verifying the install

However you got it in:

```bash
wp plugin list | grep terraviz          # expect: terraviz  active  <version>
```

Then load **Settings → Terraviz** and hit **Test connection** — it probes the
configured node's `/.well-known/terraviz.json`. Green there means the plugin is
installed *and* can reach the node, which is a stronger check than activation
alone.

Note that this is an **outbound** call from your WordPress host to the Terraviz
node. If your site sits behind an egress proxy or a locked-down firewall,
that's a separate thing to allow — unrelated to the Cloudflare inbound rule
above, but a common second stumble on hardened networks.

## Sources

- [Cloudflare Community — Cloudflare WAF is blocking WordPress Plugin](https://community.cloudflare.com/t/cloudflare-waf-is-blocking-wordpress-plugin/392138)
- [Cloudflare Community — Managed rule blocking WordPress plugin uploads](https://community.cloudflare.com/t/manged-rule-blocking-wordpress-plugin-uploads/449749)
- [Cloudflare Community — Cloudflare firewall update blocks php file uploads](https://community.cloudflare.com/t/clouflare-firewall-update-blocks-php-file-uploads/507256)
- [SkyMinds — Contourner le blocage du WAF Cloudflare sur les uploads ZIP WordPress](https://www.skyminds.net/contourner-blocage-waf-cloudflare-uploads-zip-wordpress/)
- [Cloudflare Docs — WAF exceptions](https://developers.cloudflare.com/waf/managed-rules/waf-exceptions/)
- [Cloudflare Docs — Create an exception (Ruleset Engine)](https://developers.cloudflare.com/ruleset-engine/managed-rulesets/create-exception/)
- [NVD — CVE-2019-7816](https://nvd.nist.gov/vuln/detail/CVE-2019-7816) (the actual ColdFusion vulnerability the rule is named for)
