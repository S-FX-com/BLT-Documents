# BLT Documents — Build Plan & Status

Phased plan for the initial build. All v1 phases are implemented; this file is
the working record and the backlog for follow-ups.

## Phase 1 — Foundation ✅
- [x] Main plugin file: header, constants, computed autoloader, PUC wiring.
- [x] Activation/deactivation; roles + Board Member; capabilities.
- [x] Custom tables (`folders`, `files`, `versions`) via `dbDelta` with a
      schema-version gate and idempotent `maybe_upgrade`.
- [x] Settings (single option), crypto (AEAD at-rest secret), HMAC signer.

## Phase 2 — Admin ✅
- [x] Menu + Documents screen (folders sidebar, file list, per-folder shortcode).
- [x] Add / Edit / Replace (versioning) / soft-delete flows (PRG + nonces).
- [x] Version history view (capability-gated, scoped downloads).
- [x] Settings screen + Test Connection (AJAX).
- [x] Copy-to-clipboard shortcode generator.

## Phase 3 — Worker + R2 ✅
- [x] TypeScript Worker: `/v1/health`, `/v1/get`, `/v1/put`.
- [x] HMAC + freshness + control-op binding; safe-key validation.
- [x] R2 streaming download; integrity-checked upload (`sha256`).
- [x] `wrangler.toml`, package/tsconfig, known-vector tests, `DEPLOY.md`.

## Phase 4 — Front end ✅
- [x] `[blt_documents]` shortcode → responsive 4-column table.
- [x] Nonce-free public current-version download route (streamed proxy).
- [x] Self-contained `.blt-doc-*` CSS; progressive-enhancement JS.

## Phase 5 — Migration ✅
- [x] `wp blt-documents migrate-wpfd [--dry-run]` importer.
- [x] WPFD categories → folders; filterable file discovery; password/expiry flags.

## Phase 6 — Packaging ✅
- [x] `phpcs.xml.dist`, `phpunit.xml.dist`, `composer.json`.
- [x] `.distignore` (excludes `/worker`), `readme.txt`, `uninstall.php` (opt-in).
- [x] Release workflow + `bump-version.php`.
- [x] PHP + Worker test suites.

## Follow-ups / v2 candidates
- [ ] Optional single-use download tokens (KV nonce store) for stricter replay
      protection beyond the ±300s window.
- [ ] Multi-tenant Worker auth (per-site token + D1 hash lookup) if the shared
      secret model needs tightening.
- [ ] Folder reordering UI (drag-and-drop `sort_order`).
- [ ] Bulk actions in the file list.
- [ ] `.pot` generation in CI.
- [ ] Revisit v1 non-goals only if a real need appears (passwords, expiry,
      counters, tags, preview).
