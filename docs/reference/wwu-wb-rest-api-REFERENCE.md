# REST API & webhook — reference (automations)

> Read-only REST API + signed outbound webhook for WWU Withdrawal Button.
> Available since **1.0.0-alpha.44**. Namespace: `wwu-wb/v1`.
> Design rationale + feasibility decision: [`docs/specs/wwu-wb-rest-api-automations-SPEC.md`](../specs/wwu-wb-rest-api-automations-SPEC.md).
> Related hooks: [`wwu-wb-hooks-filters-REFERENCE.md`](./wwu-wb-hooks-filters-REFERENCE.md) §12.

This API lets external systems (Zapier, Make, n8n, a CRM, a helpdesk, a custom
dashboard) **read** withdrawal requests and be **notified** when one is confirmed.
It is deliberately read-only: there is **no endpoint to create or mutate a
withdrawal**, because a withdrawal is the consumer's own legal declaration and
must not be fabricated on their behalf.

**Privacy contract (always):** the consumer's **raw IP is never exposed** by the
API or the webhook — it stays in the evidence log. The `row_hash` is surfaced so a
receiver can verify integrity without ever seeing the IP. The list never includes
the email; the detail + webhook include the email (already in the merchant's order
data) but never the IP or the hash-chain internals.

---

## 1. Authentication

Reads authenticate with a **WordPress Application Password** (Users → Profile →
Application Passwords) over HTTPS, sent as HTTP Basic auth. WordPress resolves the
user; the request is then authorised by capability — the user must have the plugin
admin capability (default `manage_woocommerce`, filter `wwu_wb_admin_capability`).

- **Use HTTPS.** Application Passwords over plain HTTP leak the credential.
- No nonce is required or accepted (Application-Password requests carry none).
- All endpoints are `GET`, capability-gated, and rate-limited (~120 req/min per user).

```bash
# List the most recent confirmed requests
curl -s --user 'admin:xxxx xxxx xxxx xxxx xxxx xxxx' \
  'https://store.example.com/wp-json/wwu-wb/v1/requests?per_page=10'
```

A failed/anonymous call returns `401`; a logged-in user without the capability
returns `403`. Responses use the plugin envelope `{ "success": true, "data": … }`.

---

## 2. Endpoints

### `GET /requests` — list confirmed requests (paginated)

Lean rows — **no email, no IP.**

| Query param | Type | Notes |
|---|---|---|
| `page` | int | 1-based. Default `1`. |
| `per_page` | int | Default `25`, **max `100`** (clamped). |
| `platform` | string | `woocommerce` \| `fluentcart` \| `edd`. Exact match. |
| `status` | string | `open` \| `processed` \| `refunded`. |
| `after` | string | ISO date `YYYY-MM-DD` — lower bound on `created_at` (UTC). |
| `before` | string | ISO date `YYYY-MM-DD` — upper bound on `created_at` (UTC). |

Pagination is also returned in headers: `X-WP-Total`, `X-WP-TotalPages`.

```jsonc
// 200 OK
{
  "success": true,
  "data": [
    {
      "request_uid": "5b1f…-e2",
      "platform": "woocommerce",
      "order_ref": "1842",
      "order_number": "#1842",
      "status": "open",            // open | processed | refunded (derived from the log)
      "country": "IT",
      "within_window": true,
      "created_at": "2026-06-16T09:41:12Z"
    }
  ]
}
```

`status` is derived from the immutable log: `refunded` if a refund was recorded
against the order, else `processed` if the merchant marked the request processed,
else `open`.

### `GET /requests/{request_uid}` — one request

Adds the email + the partial-withdrawal product selection + the evidence
`row_hash`. **Never** the IP.

```jsonc
// 200 OK
{
  "success": true,
  "data": {
    "request_uid": "5b1f…-e2",
    "platform": "woocommerce",
    "order_ref": "1842",
    "order_number": "#1842",
    "status": "open",
    "country": "IT",
    "within_window": true,
    "created_at": "2026-06-16T09:41:12Z",
    "consumer_email": "buyer@example.com",
    "products": ["Wool scarf"],     // empty = whole order
    "submitted_at": "2026-06-16T09:41:12Z",
    "days_left": 11,
    "row_hash": "9f2c…"             // for external integrity verification
  }
}
```

`404` (`wwu_wb_not_found`) when no confirmed request has that id.

### `GET /orders/{platform}/{order_ref}/withdrawal` — per-order status

For an order-management integration that wants to know "has this order been
withdrawn?".

```jsonc
// 200 OK — order has a confirmed withdrawal
{ "success": true, "data": { "withdrawn": true, "status": "refunded", "request_uid": "5b1f…-e2", "created_at": "2026-06-16T09:41:12Z" } }

// 200 OK — order is known to the plugin but has no withdrawal
{ "success": true, "data": { "withdrawn": false, "status": "none" } }
```

`404` (`wwu_wb_order_unknown`) when the plugin has no record of that
platform/order at all.

---

## 3. Outbound webhook

Enable it under **Settings → Integrations**: tick "Webhook on confirmed
withdrawal", set your endpoint URL, and generate (or paste) a signing secret.

- Fires on a **confirmed** withdrawal, **asynchronously** (the consumer is never
  blocked by your endpoint).
- The endpoint URL is validated by the SSRF guard at **save time and again at
  send time** — internal / loopback / cloud-metadata / private / CGNAT addresses
  are refused. Delivery uses `redirection => 0` + `reject_unsafe_urls => true`.
- One retry on a transport error. HTTP error codes are reported (action below) but
  not retried — your receiver owns its own idempotency (use `X-WWU-WB-Delivery`).

### Request

```
POST <your endpoint>
Content-Type: application/json; charset=utf-8
User-Agent: WWU-Withdrawal-Button/<version>
X-WWU-WB-Event: withdrawal.confirmed
X-WWU-WB-Delivery: <uuid v4, unique per attempt>
X-WWU-WB-Signature: sha256=<hex HMAC-SHA256(rawBody, secret)>
```

```jsonc
{
  "event": "withdrawal.confirmed",
  "request_uid": "5b1f…-e2",
  "platform": "woocommerce",
  "order_ref": "1842",
  "order_number": "#1842",
  "consumer_email": "buyer@example.com",
  "status": "open",
  "country": "IT",
  "within_window": true,
  "created_at": "2026-06-16T09:41:12Z",
  "row_hash": "9f2c…"
}
```

(No raw IP — by design.) The payload is filterable server-side via
`wwu_wb_webhook_payload`.

### Verifying the signature

Compute `HMAC-SHA256` over the **exact raw request body** with your secret and
compare in constant time against the hex in `X-WWU-WB-Signature` (after the
`sha256=` prefix).

```php
// PHP receiver
$raw    = file_get_contents( 'php://input' );
$sig    = $_SERVER['HTTP_X_WWU_WB_SIGNATURE'] ?? '';
$expect = 'sha256=' . hash_hmac( 'sha256', $raw, $my_secret );
if ( ! hash_equals( $expect, $sig ) ) {
    http_response_code( 401 );
    exit;
}
```

```js
// Node.js receiver (express raw body)
const crypto = require('crypto');
const expected = 'sha256=' + crypto.createHmac('sha256', MY_SECRET).update(rawBody).digest('hex');
const ok = crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(req.get('X-WWU-WB-Signature') || ''));
```

Return any `2xx` to acknowledge. The plugin records the outcome and fires
`wwu_wb_webhook_delivered( bool $ok, int $code, string $request_uid, string $delivery_id )`.

---

## 4. Security notes

- The signing **secret** is stored autoload-off and only ever shown masked; it is
  never re-emitted to the browser or written to logs.
- Reads are rate-limited per user; `per_page` is capped at 100.
- There are **no write/create endpoints** in this version — a smaller attack
  surface and a legal hard line (a withdrawal can't be filed via API).
- The plugin passed a dedicated security audit for this surface before release
  (no critical/high/medium findings).
