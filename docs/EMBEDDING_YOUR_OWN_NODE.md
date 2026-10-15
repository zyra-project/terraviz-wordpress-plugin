# Letting WordPress embed your own Terraviz node

This only matters if **Settings → Terraviz** points at a node you (or your
organization) run. The canonical public node, `terraviz.zyra-project.org`,
allows any site to embed it, so there is nothing to change for it.

Short version: the node has to tell browsers that your WordPress site may frame
it. Add your site's origin to a `frame-ancestors` policy in the node's
`public/_headers` ([jump](#fix-name-your-wordpress-origins-in-frame-ancestors)),
and make sure nothing adds `X-Frame-Options` or puts Cloudflare Access in front
of the app.

## Symptom

- The block's server-rendered fallback shows (title, abstract, thumbnail), and
  **Test connection** passes, but the interactive globe never appears. Clicking
  **Load interactive globe**, or scrolling the embed into view, leaves a blank
  box or a browser "refused to connect" page.
- The browser console on the WordPress page shows one of these (Chrome's
  wording; Firefox and Safari say the same thing differently):

  ```
  Refused to frame 'https://terraviz.example.org/' because an ancestor violates
  the following Content Security Policy directive: "frame-ancestors 'self'".

  Refused to display 'https://terraviz.example.org/' in a frame because it set
  'X-Frame-Options' to 'sameorigin'.
  ```

**Test connection** still passes because it is a server-to-server request from
WordPress to the node. Framing rules only apply in the visitor's browser.

## Why it happens

The globe is the node's own web app in a cross-origin iframe
(`https://<node>/?dataset=…&embed=1`). The node decides who may frame it, using
two response headers:

- `Content-Security-Policy: frame-ancestors …` (the current mechanism), and
- `X-Frame-Options: DENY` or `SAMEORIGIN` (the legacy one).

Terraviz ships **neither**. Its `public/_headers` sets only
`X-Content-Type-Options`, `Referrer-Policy` and `Permissions-Policy`, so a fresh
fork can be embedded anywhere. Embeds break when something gets added on the way
to production:

1. **A CSP with `frame-ancestors 'self'` or `'none'`.** Terraviz's self-hosting
   guide ([Phase 13][sh-csp]) tells every fork to add its own CSP, and baseline
   policies copied from elsewhere often include one of these.
2. **`X-Frame-Options`**, from `_headers`, a Cloudflare response-header rule,
   Cloudflare's **Add security headers** managed transform (it adds
   `X-Frame-Options: SAMEORIGIN`), or an organization's security baseline.
3. **Cloudflare Access in front of the app.** The iframe gets Access's sign-in
   page instead of the globe, and that page refuses to be framed. No header
   change fixes this; see [Cloudflare Access](#cloudflare-access).

## Fix: name your WordPress origins in `frame-ancestors`

In your Terraviz fork, add a `Content-Security-Policy` line with a
`frame-ancestors` directive to the `/*` rule in `public/_headers`:

```
/*
  X-Content-Type-Options: nosniff
  Referrer-Policy: strict-origin-when-cross-origin
  Permissions-Policy: camera=(), microphone=(), geolocation=()
  Content-Security-Policy: frame-ancestors 'self' https://www.example.org https://example.org
```

Then redeploy the node. The build copies `public/_headers` into the output
directory, and Cloudflare Pages applies it from the next deployment.

Getting the list right:

- **Origins only:** scheme and host, plus the port if it isn't the default. No
  paths, no trailing slash.
- **List every site that will show embeds.** `https://example.org` and
  `https://www.example.org` are different origins, and so is a staging copy of
  the site. A wildcard such as `https://*.example.org` covers subdomains but not
  the bare `example.org`.
- **Keep `'self'`** so the node can still frame its own pages.
- **To allow any site**, as the canonical node does, leave `frame-ancestors` out
  entirely. A CSP without it doesn't restrict framing, because
  `frame-ancestors` does not fall back to `default-src`.
- **It has to be an HTTP header.** Browsers ignore `frame-ancestors` in a
  `<meta http-equiv>` policy.

### If the node already has a CSP

If the policy already has a `frame-ancestors` directive (often
`frame-ancestors 'self'` or `'none'`), **edit that directive** to the full list.
Don't add a second one: browsers use only the first `frame-ancestors` in a
policy and ignore any repeat, so the embed stays blocked.

If the policy has no `frame-ancestors` yet, add it as one more `;`-separated
directive. Either way the result has exactly one:

```
  Content-Security-Policy: default-src 'self'; connect-src 'self' …; img-src 'self' data: blob: …; frame-ancestors 'self' https://www.example.org
```

Don't add a second `Content-Security-Policy` line. Browsers enforce every policy
they receive, so a stricter one still blocks the embed. And when two `_headers`
rules set the same header, Pages joins the values with a comma, which browsers
also read as two separate policies.

If the CSP is set by a Cloudflare response-header rule instead (this is how the
canonical node does it, and edge rules don't travel with a fork), put
`frame-ancestors` in that rule. Keep the policy in one place.

### Remove `X-Frame-Options`

Delete any `X-Frame-Options` line from `_headers`, and turn off whatever adds it
at the edge: the **Add security headers** managed transform, or a custom
response-header rule. A `! X-Frame-Options` line in `_headers` won't do it,
because that only detaches headers set by other `_headers` rules, not ones
Cloudflare adds afterwards.

Current browsers ignore `X-Frame-Options` when the response also carries a
`frame-ancestors` policy, so the fix above works on its own. Removing the
conflicting header anyway keeps older browsers and security scanners from
disagreeing with it.

### `_headers` covers the static app only

Cloudflare Pages doesn't apply `_headers` to responses generated by Pages
Functions. That's fine for embeds: the embed URL is the node's static app (`/`),
and Terraviz's Functions live under `/api/` and `/schema/`, which are never
framed. If your fork adds a Function that serves the app itself (root
middleware, for example), set the header on that Function's response instead.

## Check it

```bash
curl -sI "https://terraviz.example.org/?dataset=<id>&embed=1" \
  | grep -i -E 'content-security-policy|x-frame-options'
```

You want a `content-security-policy` whose `frame-ancestors` names your
WordPress origin, and no `x-frame-options`. Then reload the WordPress page, load
the globe, and check that the console is clean.

## Cloudflare Access

If the node is behind Cloudflare Access (common for test environments), the
iframe shows Access's sign-in page, which can't be framed, so no header helps.
Either check embeds against a public node, or leave the app itself outside
Access and keep only the publish API (`/api/v1/publish/**`) protected, which is
all the plugin's service token needs. Upstream's
[`CANONICAL_TRANSITION.md`][ct] (conditions 8 and 9) walks through this
trade-off.

## The WordPress side

The plugin has nothing to configure for this. But if your WordPress site sends
its own CSP (from a security plugin or the host), that policy must allow the
node in `frame-src`, and the thumbnail host in `img-src`. Otherwise the browser
blocks the iframe from the other end, with a console message naming `frame-src`
rather than `frame-ancestors`.

## Sources

- [Terraviz — `docs/SELF_HOSTING.md`, Phase 13: Content-Security-Policy][sh-csp]
- [Terraviz — `docs/WORDPRESS_INTEGRATION_PLAN.md`, §4: `frame-ancestors`](https://github.com/zyra-project/terraviz/blob/main/docs/WORDPRESS_INTEGRATION_PLAN.md)
- [Terraviz — `CANONICAL_TRANSITION.md`, condition 8 and the embed gate][ct]
- [Cloudflare Docs — Pages headers (`_headers`)](https://developers.cloudflare.com/pages/configuration/headers/)
- [Cloudflare Docs — Managed Transforms reference (Add security headers)](https://developers.cloudflare.com/rules/transform/managed-transforms/reference/)
- [MDN — CSP `frame-ancestors`](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Security-Policy/frame-ancestors)
- [MDN — `X-Frame-Options`](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/X-Frame-Options)

[sh-csp]: https://github.com/zyra-project/terraviz/blob/main/docs/SELF_HOSTING.md#phase-13--content-security-policy
[ct]: https://github.com/zyra-project/terraviz/blob/main/CANONICAL_TRANSITION.md
