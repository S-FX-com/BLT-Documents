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

## Notes

- Replay protection is the ±300s freshness window + HTTPS (no server-side
  nonce cache), matching the BLT fleet convention. Keep `MAX_SKEW` (default
  300) identical on both ends.
- One Worker + one bucket serves all sites; each site's objects live under its
  own `{site-id}/…` key prefix.
- Prior versions are never deleted by the plugin — object lifecycle is managed
  in Cloudflare if ever needed.
