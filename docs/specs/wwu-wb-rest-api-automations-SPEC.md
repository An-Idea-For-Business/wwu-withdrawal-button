# SPEC — Public REST API + outbound webhook (automations)

- **Slug:** wwu-wb · **Target version:** 1.0.0-alpha.44 (after the alpha.43 why-note ships) · **Status:** Designed (2026-06-16) — feasibility decided, implementation pending
- **Task:** #37 — "public REST API for automations". The decision of *what is feasible* was delegated; this SPEC records it.

## Feasibility decision (the delegated call)
| Surface | Verdict | Why |
|---|---|---|
| **Outbound webhook** on withdrawal confirmed | ✅ **Build** | The real no-code automation bridge (Zapier/Make/n8n/CRM/helpdesk). Leverages the existing `wwu_wb_withdrawal_confirmed` action; the merchant configures where *their own* data goes. |
| **Read-only REST API** (list / detail / per-order status) | ✅ **Build** | External reporting, reconciliation against refunds, custom dashboards, read-based automations. |
| **Create a withdrawal** via API | ❌ **Never** | A withdrawal is the consumer's **legal declaration**; creating it via a third-party automation = fabricating a legal act on someone's behalf. Legal + security hard-no. No endpoint, ever. |
| **Write** (mark processed / record refund) via API | ⏸ **Defer** | Lower value (merchants close refunds in Woo/accounting, then mark from admin) + more sensitive mutation surface. Add later only if a concrete need appears. |

So: **webhook + read-only API**. PII is involved → auth + privacy are the crux.

## Goals & non-goals
- **Goals:** let external systems (a) be *notified* when a withdrawal is confirmed (webhook), and (b) *read* withdrawal requests/status (REST), securely.
- **Non-goals:** no create/mutate of withdrawals; no public (unauthenticated) data; no polling-replacement for the webhook; no new PII beyond what the evidence log already holds.

## Auth model (decided)
- **REST reads → WordPress Application Passwords + capability + rate limit.** External callers authenticate via Basic auth (Application Password over HTTPS) → WP resolves the user → `permission_callback` requires `current_user_can( <WWU_WB admin capability, filter wwu_wb_admin_capability> )`. This is exactly the pattern `wwu-tools/wwu-rest-test.php` already uses against the debug endpoints. **No custom API-key system** (Application Passwords are WP-native, per-user, revocable, scoped to REST/XML-RPC — less surface, less to maintain).
- Reuse the existing `src/REST/Authentication.php` capability + rate-limit helpers (NOT the nonce path — external callers have no nonce; rely on Application-Password Basic auth + capability).
- Endpoints are **read-only** (`GET`) → no CSRF concern.
- **HTTPS required** in copy/docs (Application Passwords over plain HTTP leak credentials).

## REST endpoints (namespace `wwu-wb/v1`, all `GET`, all capability-gated + rate-limited)
1. `GET /requests` — paginated list of **confirmed** withdrawal requests. Query: `page`, `per_page` (cap 100), `after`/`before` (ISO date on `created_at`), `platform`, `status`. Returns lean rows: `request_uid`, `platform`, `order_ref`, `order_number`, `status`, `country`, `within_window`, `created_at`. **No raw IP**, no hash internals.
2. `GET /requests/{request_uid}` — one request: the list row + `consumer_email`, `products` (if a partial selection), `submitted_at`, `days_left`, the evidence `row_hash` (for external verification) — but **never** the raw IP or the chain internals.
3. `GET /orders/{platform}/{order_ref}/withdrawal` — per-order status for an order-management integration: `{ withdrawn: bool, status, request_uid?, created_at? }`. 404 when the platform/order is unknown; `{ withdrawn:false }` when no request exists.
- Source: `LogRepository::list_confirmed()` / a new lean reader. Envelope: WP REST standard (`X-WP-Total`/`X-WP-TotalPages` on the list).

## Outbound webhook (decided shape)
- **Settings:** `wwu_wb_webhook` option (autoload no) → `{ enabled: bool, url: string, secret: string }` (admin UI under a new "Integrations" settings section; "Generate secret" button). URL validated + **passed through `Security\OutboundUrlGuard`** (the SSRF guard from the RFC-3161 work) on save and before each send.
- **Trigger:** on `wwu_wb_withdrawal_confirmed`. Dispatch **async** (a single scheduled event / `wp_schedule_single_event` on shutdown, or Action Scheduler if already bundled) so it never blocks the consumer's request.
- **Payload (JSON):** `{ event: "withdrawal.confirmed", request_uid, platform, order_ref, order_number, consumer_email, status, country, within_window, created_at, row_hash }`. (Merchant's own data → their own endpoint; still no raw IP.)
- **Signature:** header `X-WWU-WB-Signature: sha256=<HMAC-SHA256(body, secret)>` + `X-WWU-WB-Event` + a `X-WWU-WB-Delivery` uuid, so the receiver can verify authenticity (GitHub-webhook style). Documented in the hooks reference.
- **Delivery:** `wp_remote_post`, `timeout` small, `reject_unsafe_urls => true`, one retry on transport error; log success/failure to the Debug collector. Filterable payload via `wwu_wb_webhook_payload`. New action `wwu_wb_webhook_delivered`.

## Security checklist (the crux — must hold)
- PII (email) only ever returned/sent **after** capability auth (REST) or to the **merchant-configured** endpoint (webhook). Never public.
- **Raw IP never exposed** by the API or webhook (it stays in the evidence log only; the API exposes the `row_hash` for verification, not the IP).
- Rate-limit the read endpoints (reuse `Authentication::enforce_rate_limit`).
- Webhook URL behind `OutboundUrlGuard` (block internal/loopback/metadata) at save-time AND send-time (TOCTOU).
- HMAC secret stored as an option (autoload no), shown masked in UI after first save (mirror the AM-Pro `masked_api_key` pattern); never in logs/snapshots.
- No write/mutation endpoints in this version (smaller attack surface).
- `permission_callback` must NOT also re-verify a nonce (Application-Password REST requests carry no nonce — that's the alpha-PWA trap #53 lesson).

## Testing
- Smoke: HMAC signature correctness (known body+secret → known digest); payload shape; `OutboundUrlGuard` rejects an internal URL; the read endpoints' permission_callback denies an anonymous request and allows a capable one; list pagination cap.
- Live: `wwu-tools/wwu-rest-test.php` can hit the read endpoints with an Application Password (extend it if needed).
- **A dedicated security audit (Standard #13) before shipping** — this is a PII surface.

## Open questions
1. Action Scheduler vs `wp_schedule_single_event` for async webhook — check whether AS is already bundled; if not, single-event is fine for one webhook.
2. Multiple webhook endpoints (array) vs one — start with one; array is additive later.
3. Should the read API also expose Art. 59 **exemption** records? Out of scope here (separate concern); the existing Consent-records admin export covers it.
