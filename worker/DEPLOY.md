# BLT Documents Worker — Deployment

The Worker is the enforcement + delivery plane. It gates a **private** R2
bucket: objects have no public URL, and every read/write requires a fresh,
HMAC-signed request from the BLT Documents WordPress plugin.

## Prerequisites

- A Cloudflare account with R2 enabled.
- `wrangler` v3+ authenticated (`wrangler login`).
- A zone (e.g. `s-fx.com`) if you want a first-party hostname (optional — R2
  itself does not require a zone).

## One-time setup

```sh
cd worker
npm install

# 1. Create the private bucket (no public access, no custom domain).
wrangler r2 bucket create blt-documents

# 2. Generate a strong shared secret and set it on the Worker.
#    Keep this value — you paste the SAME string into the plugin.
openssl rand -hex 32            # copy the output
wrangler secret put WORKER_SECRET   # paste it when prompted

# 3. (Optional) adjust the route/zone in wrangler.toml, or set
#    workers_dev = true to test on *.workers.dev first.

# 4. Deploy.
npm run deploy
```

## Wire up the plugin

In WordPress → **BLT Documents → Settings**:

| Field         | Value                                                        |
|---------------|-------------------------------------------------------------|
| Worker URL    | `https://docs.s-fx.com` (your route) — base URL, no path     |
| Worker Secret | the exact `WORKER_SECRET` you set above                      |

Save, then click **Test Connection**. A green "Connection OK." confirms the
signing contract round-trips.

## Endpoints (all POST, all signed)

| Path         | Purpose                                             |
|--------------|-----------------------------------------------------|
| `/v1/health` | Connectivity + credential check (the Test button).  |
| `/v1/get`    | Stream an object back to WordPress.                 |
| `/v1/put`    | Store an uploaded object (SHA-256 verified by R2).  |

## The signing contract

Every request carries:

- `Authorization: Bearer <WORKER_SECRET>`
- `X-BLT-Timestamp: <unix seconds>`
- `X-BLT-Control: <base64url JSON>` — `{op,key,sha256,content_type,site}`
- `X-BLT-Signature: <hex HMAC-SHA256 of "{ts}.{control_b64}">`

The Worker rejects anything that is stale (>300s skew), unsigned, wrongly
signed, or whose `op` does not match the endpoint. `op` and `key` are inside
the signed string, so a captured request cannot be replayed against a
different object or operation. See `src/auth.ts` and `test/auth.test.ts`.

## Tests

```sh
npm test        # known-vector tests: SHA-256, HMAC, freshness, control decode
npm run typecheck
```

## Multi-tenancy & isolation

One Worker + one bucket can serve many sites; each site's objects live under its
own `{site-id}/…` key prefix, and the Worker enforces that a request's key
matches the `site` it signed (`keyInSite`). That prevents a site from
*accidentally* touching another prefix.

However, with a **single shared `WORKER_SECRET`** this is not cryptographic
tenant isolation: any party that can recover the shared secret (e.g. one
tenant's WordPress DB + salts, or an operator) can mint a valid signature for
any `site` value. For deployments where the client sites are **not** mutually
trusted, isolate them properly:

- Give each site its **own `WORKER_SECRET`** (deploy a Worker per site, or
  extend the Worker to look secrets up per site — e.g. a KV/D1 `site_id → secret
  hash` map, mirroring the BLT-Secure fleet pattern), **or**
- Give each site its **own bucket** (or a scoped R2 credential).

For a single S-FX-managed board (the primary use case), the shared secret is
acceptable — all sites are first-party.

## Notes

- Replay protection is the ±300s freshness window + HTTPS (no server-side
  nonce cache), matching the BLT fleet convention. Keep `MAX_SKEW` (default
  300) identical on both ends.
- Prior versions are never deleted by the plugin — object lifecycle is managed
  in Cloudflare if ever needed.
