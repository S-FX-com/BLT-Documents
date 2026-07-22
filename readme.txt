=== BLT Documents ===
Contributors: sfxcom
Tags: documents, downloads, versioning, cloudflare, r2
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure, versioned document delivery for governance boards. Files live in a private Cloudflare R2 bucket behind an HMAC-gated Worker — never crawlable, never hotlinkable.

== Description ==

BLT Documents is a narrow, hardened replacement for heavier file-download
plugins. It is built for one job: letting a board publish the current version
of its controlling documents (bylaws, rules & regulations, minutes, policies,
deeds) reliably and privately.

* **Real version control.** Replacing a file keeps the old one forever. The
  live download always serves the current version; prior versions are retained
  but reachable only by authorized roles.
* **Nothing is crawlable or hotlinkable.** No file — current or historical —
  ever has a persistent, public, or guessable URL. Each download is minted
  per-request and expires immediately. Serving routes send
  `noindex, nofollow` and are disallowed in robots.txt.
* **Defense in depth.** WordPress checks the user's capability before anything
  reaches storage; a self-hosted Cloudflare Worker independently requires a
  valid HMAC-signed, short-TTL request before it will touch the private R2
  bucket.
* **A simple shortcode.** `[blt_documents folder="governing-documents"]` drops
  a folder's current documents into any page as a minimal, responsive table.

= Roles =

* **Manage documents** (`blt_manage_documents`) — upload, replace, organize
  folders, generate shortcodes. Administrators by default.
* **View document history** (`blt_view_document_history`) — see and download
  prior versions. Administrators and the new **Board Member** role.
* Everyone else, including logged-out visitors, only ever reaches the current
  version of a published document.

= Architecture =

WordPress plugin (control plane) + self-hosted Cloudflare Worker
(enforcement/delivery plane) + Cloudflare R2 (private storage). See the bundled
worker/DEPLOY.md for the Worker setup runbook.

== Installation ==

1. Install and activate the plugin.
2. Deploy the Cloudflare Worker (see `worker/DEPLOY.md`), create the private R2
   bucket, and set a shared `WORKER_SECRET`.
3. In **BLT Documents → Settings**, enter the Worker URL and the same secret,
   then click **Test Connection**.
4. Create a folder, add documents, and drop `[blt_documents folder="..."]` onto
   any page.

== Frequently Asked Questions ==

= Where are the files stored? =

In a private Cloudflare R2 bucket with no public access. The plugin never
exposes a file URL; downloads are streamed through a nonce/capability-gated
WordPress route.

= Can search engines index the documents? =

No. Serving routes emit `noindex, nofollow`, the route is disallowed in
robots.txt, and there is no static URL to crawl.

= Are old versions ever deleted? =

Never by the plugin. Replacing a file appends a new version and retains the
old one. R2 object lifecycle is managed in Cloudflare if ever needed.

== Changelog ==

= 1.0.0 =
* Initial release: folders, files, version history, private R2 delivery via an
  HMAC-gated Cloudflare Worker, shortcode front-end, Board Member role, and a
  WP File Download migration command.
