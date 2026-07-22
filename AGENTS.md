# AGENTS.md — BLT Documents

Working notes for automated agents. Read `CLAUDE.md` first for the full
architecture and conventions; this file is the short operational checklist.

## Ground rules

- Match the BLT family house style exactly (no namespace, `BLT_Documents_*`
  classes, `class-blt-documents-<thing>.php` files, ABSPATH guard,
  `@package Blt_Documents`). Do not introduce jQuery, a JS framework, a Worker
  router library, or ACSS/utility-class CSS dependencies.
- The plugin has **zero runtime Composer dependencies**. `composer.json` is
  dev-tooling only. `plugin-update-checker` is vendored in `includes/lib/`.
- Secrets are encrypted at rest and never logged. The Worker secret is the
  shared HMAC key; treat it as sensitive.

## The signing contract is load-bearing

`includes/class-blt-documents-signer.php` (PHP) and `worker/src/auth.ts` (TS)
must agree byte-for-byte. If you touch the canonical signed string
(`"{ts}.{control_b64}"`), the control shape, or `MAX_SKEW` (300), update **both
sides and both test suites** (`tests/test-signer.php`, `worker/test/auth.test.ts`).

## Security invariants (do not regress)

- No file ever gets a public/guessable/persistent URL. Downloads stream through
  the WP REST route; the R2 key and signature never reach the browser.
- Current version of a *published* file is public; any historical version
  requires `blt_view_document_history`. Drafts are never public.
- Every serving path sends `X-Robots-Tag: noindex, nofollow` +
  `Cache-Control: private, no-store`, and the route is disallowed in robots.txt.
- Prior versions are never deleted through the admin flow. "Delete" trashes the
  file record (soft) and leaves R2 + history intact.

## Before committing

- `composer phpcs` — must pass the WordPress-Extra ruleset.
- `composer test` — PHP pure-logic tests.
- `cd worker && npm run typecheck && npm test` — Worker types + auth vectors.
- `php -l` any changed PHP file.
- Keep the version string identical in the header and `BLT_DOCUMENTS_VERSION`
  (CI enforces this; don't bump by hand — let the release workflow do it).

## Branch / release

- Develop on the designated feature branch; never push to `main` directly.
- Releases are CI-only. `#minor` / `#major` in a commit message sets the bump.
