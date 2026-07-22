# BLT Documents — Architecture & Conventions

Guidance for working in this repository. BLT Documents is part of the S-FX.com
"BLT" WordPress plugin family and follows its house conventions (see
BLT-Secure, BLT-Popups, Blt-Image-Optimizer).

## What this plugin does

Secure, versioned document delivery for governance boards. A board publishes
the current version of its controlling documents; when a document changes, an
admin uploads a new file and the live download always serves the current
version. Old files are retained forever but reachable only by authorized roles,
and **no file ever has a public, guessable, or crawlable URL.**

## Architecture (three planes)

```
Front-end shortcode  ->  WP REST route (nonce + capability check)
                              |  (HMAC-signed, short-TTL, server-to-server)
                              v
                      Cloudflare Worker  ->  R2 bucket (private, no public binding)
                              |
                              v
                  Streamed back through WordPress to the browser
```

- **Control plane** — this WordPress plugin. Owns the data model, roles, admin
  UI, and the capability checks. Signs every request to the Worker.
- **Enforcement/delivery plane** — `worker/` (TypeScript Cloudflare Worker).
  Independently verifies an HMAC signature + freshness before touching R2.
- **Storage plane** — a private Cloudflare R2 bucket. Every version is an
  immutable object keyed `{site-id}/{folder-slug}/{file-slug}/v{n}.{ext}`.

The browser only ever talks to the WordPress REST route. The R2 key, the Worker
URL, and the HMAC signature never leave the server. This is deliberate: exposing
a signed Worker URL to the browser would leak version numbers (is there a v2?),
so downloads are **proxied/streamed through WordPress**, not redirected.

## The plugin ↔ Worker signing contract

Reused, near-verbatim, from the BLT-Secure fleet signer. Every request the
plugin makes to the Worker carries:

- `Authorization: Bearer <WORKER_SECRET>`
- `X-BLT-Timestamp: <unix seconds>`
- `X-BLT-Control: <base64url JSON>` — `{op, key, sha256, content_type, site}`
- `X-BLT-Signature: <lowercase-hex HMAC-SHA256 of "{ts}.{control_b64}">`

`op` and `key` live inside the signed string, so a captured request cannot be
replayed against a different object or operation. Uploads are memory-safe: the
signature covers the file's SHA-256 (not its bytes), and R2 verifies the
content against that hash on `put`. Freshness window is ±300s (`MAX_SKEW`),
identical on both ends.

- PHP signer: `includes/class-blt-documents-signer.php`
- TS verifier: `worker/src/auth.ts` (with known-vector tests in
  `worker/test/auth.test.ts`)

If you change the canonical string on one side, change the other and update
both test suites — they exist to keep the two planes byte-identical.

## Conventions (match the family exactly)

- **No namespace.** Classes are `BLT_Documents_<Thing>` in files
  `includes/class-blt-documents-<thing>.php` (admin classes in `admin/`).
- **ABSPATH guard** `if ( ! defined( 'ABSPATH' ) ) { exit; }` at the top of
  every PHP file; `@package Blt_Documents` docblock.
- **Constants** `BLT_DOCUMENTS_VERSION|FILE|DIR|URL|BASENAME|REST_NS`. The
  version literal lives in the header **and** the constant — CI keeps them in
  sync; never hand-edit only one.
- **Autoloader** — computed prefix autoloader in `blt-documents.php` (probes
  `includes/` then `admin/`). Add a class by creating its file; no manifest.
- **Options** — one array option `blt_documents_settings` (+ scalar
  `blt_documents_db_version`). Never `register_setting`; settings are
  hand-sanitized in `Settings::save()`.
- **Custom tables** — `wp_blt_documents_folders|files|versions`, created via
  `dbDelta` in `BLT_Documents_Schema`, gated on a stored schema version and
  re-run on `init` (`maybe_upgrade`) so file-copy updates still migrate.
- **Direct queries** — interpolate only the table name; bind every value via
  `$wpdb->prepare` with `%d/%s`; whitelist any dynamic `ORDER BY`; annotate with
  the `phpcs:ignore WordPress.DB.*` comments.
- **Secrets** — the Worker secret is encrypted at rest
  (`BLT_Documents_Crypto`: libsodium → OpenSSL AES-GCM → tagged base64
  fallback; key derived from WP salts, never stored). A blank submitted secret
  preserves the stored value.
- **Admin** — singleton `BLT_Documents_Admin`, menu at position 81, capability
  `blt_manage_documents`, page-gated asset enqueue, vanilla JS (no jQuery),
  PRG form handling on `admin_init`, camelCase localized global `bltDocuments`.
- **Front end** — `[blt_documents]` shortcode returns an escaped string
  (`ob_start`/`ob_get_clean`), enqueues conditionally, self-contained
  `.blt-doc-*` CSS (no ACSS/utility-class dependency). Download links are real
  `<a>` elements to the REST route (work without JS).
- **Escaping** — `esc_html`/`esc_attr`/`esc_url` at output; `esc_url_raw` for
  storage; `wp_kses_post` for pre-built markup.
- **i18n** — text domain `blt-documents`; every user string wrapped.
- **Updates** — GitHub releases via the vendored plugin-update-checker
  (`includes/lib/`), restricted to the CI-built zip asset.

## Roles & capabilities

- `blt_manage_documents` — manage folders/files/versions, settings (admins).
- `blt_view_document_history` — download prior versions (admins + **Board
  Member** role).
- Public/logged-out visitors reach only the current version of published files.

## Repository layout

```
blt-documents.php            Main file: header, constants, PUC, autoloader, boot
includes/                    Control-plane classes (schema, repos, crypto, REST, render, migrator)
includes/lib/                Vendored plugin-update-checker (ships in the zip)
admin/                       Admin controller + views + assets
assets/                      Front-end CSS/JS
worker/                      TypeScript Cloudflare Worker (R2 + HMAC) — NOT in the plugin zip
tests/                       PHPUnit (no-WordPress bootstrap + pure-logic tests)
.github/                     Release workflow + version-bump script
tasks/                       Phased build plan / notes
```

## Packaging

- The distributed zip is a single top-level `blt-documents/` folder with
  `blt-documents.php` at its root. `/worker` and dev files are excluded via
  `.distignore`. The vendored PUC under `includes/lib/` **must** ship.
- Version bump + release is CI-only (`.github/workflows/release.yml`); commit
  messages containing `#minor`/`#major` change the bump level.

## Testing

- PHP: `composer test` (pure-logic: signer, crypto, settings). No WordPress
  required — `tests/bootstrap.php` shims the small surface used.
- Worker: `cd worker && npm test` (known-vector auth tests) and
  `npm run typecheck`.
- Lint: `composer phpcs` (WordPress-Extra ruleset in `phpcs.xml.dist`).

## Non-goals (v1)

No per-file passwords, no expiration dates, no hit counters, no tags /
multi-category, no in-browser preview. Keep the plugin narrow.
