# BLT Documents

Secure, versioned document delivery for governance boards — a hardened
replacement for heavier file-download plugins.

Files live in a **private Cloudflare R2 bucket** behind an **HMAC-gated
Cloudflare Worker**. The live download always serves the current version; old
versions are retained forever but reachable only by authorized roles. No file
ever has a public, guessable, or crawlable URL.

- **Control plane:** this WordPress plugin (PHP, Bedrock-compatible).
- **Enforcement/delivery plane:** [`worker/`](worker/) — a TypeScript
  Cloudflare Worker.
- **Storage plane:** Cloudflare R2 (private bucket).

## Quick start

1. **Deploy the Worker** — see [`worker/DEPLOY.md`](worker/DEPLOY.md). Create
   the private R2 bucket and set a shared `WORKER_SECRET`.
2. **Install the plugin**, then in **BLT Documents → Settings** enter the
   Worker URL and the same secret and click **Test Connection**.
3. **Add documents** into folders, and drop the shortcode onto any page:

   ```
   [blt_documents folder="governing-documents"]
   [blt_documents folder="governing-documents" type="Minutes"]
   ```

## Roles

| Capability                    | Who                               | Can                                  |
|-------------------------------|-----------------------------------|--------------------------------------|
| `blt_manage_documents`        | Administrators                    | Upload, replace, organize, shortcodes|
| `blt_view_document_history`   | Administrators + **Board Member** | Download prior versions              |
| _(none)_                      | Everyone, incl. logged-out        | Download the current version only    |

## Development

```sh
composer install
composer phpcs      # WordPress-Extra lint
composer test       # PHP pure-logic tests (signer, crypto, settings)

cd worker
npm install
npm run typecheck
npm test            # HMAC / auth known-vector tests
```

See [`CLAUDE.md`](CLAUDE.md) for architecture and house conventions, and
[`AGENTS.md`](AGENTS.md) for the operational checklist.

## Migrating from WP File Download

```sh
wp blt-documents migrate-wpfd --dry-run
wp blt-documents migrate-wpfd
```

WPFD categories become folders and each source file is imported as v1. File
discovery is adaptable via the `blt_documents_wpfd_files` filter (WPFD storage
varies by version). Password-protected / expiring files are flagged for manual
handling — those features are out of v1 scope.

## License

GPL-2.0-or-later.
