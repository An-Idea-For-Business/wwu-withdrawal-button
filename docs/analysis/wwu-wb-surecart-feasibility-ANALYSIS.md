# SureCart — withdrawal-button adapter feasibility (2026-06-15)

> Requested after FluentCart asked whether we'd also support SureCart. Research via a sub-agent against
> official SureCart developer docs (developer.surecart.com). **Verdict: PARTIALLY FEASIBLE** — buildable
> (~2–3 days core) but with two structural blockers to resolve first.

## The decisive architectural fact
SureCart is **API-first / headless**: orders, customers, subscriptions, line items live on SureCart's
servers, **not** in the local WordPress DB. Every read is a synchronous HTTP call to `api.surecart.com` via
the bundled PHP SDK (`SureCart\Models\{Order,Checkout,Customer,Subscription,LineItem,…}`,
`Order::with([...])->find('ord_…')`). This is unlike WooCommerce/FluentCart/EDD (local DB reads) and forces
a different rendering + storage strategy.

## What maps cleanly to our `OrderDataSource` contract
| Need | Status |
|---|---|
| Load order by id | ✅ `Order::with(['customer','customer.billing_address','checkout.line_items'])->find($id)` |
| Customer email | ✅ via `customer` expand |
| WP user from order | ✅ `$customer->getUser()` → WP_User (owner check vs `wp_get_current_user()`) |
| Billing country ISO-2 | 🟡 via `customer.billing_address` — exact field name unconfirmed |
| Paid status | 🟡 `paid` confirmed; full status enum unconfirmed |
| created date | ✅ `created_at`; **paid_at** likely only via `Charge` expand (unconfirmed) |
| Line items (id/name/qty) | 🟡 via `checkout.line_items`; virtual/downloadable flag needs a `Product` lookup |
| Inject button in customer dashboard | ✅ `surecart_template_dashboard_body_open` hook or `render_block` filter |
| Add order note (audit trail) | ✅ SureCart **Notes API** (REST) |
| Renewal vs initial | ✅ via hooks `surecart/purchase_created` (initial) vs `surecart/subscription_renewed` (renewal) — NO `is_renewal` field on the order |
| Cancel subscription | 🟡 `cancel_at_period_end` field exists; exact SDK call unconfirmed |

## The two BLOCKERS (resolve before building)
1. **Guest order ownership** — no documented `order_key`/guest-token equivalent (WooCommerce-style). A
   "verify verification code" endpoint exists but the PHP-side guest-validation path is unconfirmed. → the
   adapter may be **logged-in-only** (same constraint EDD's adapter already accepts) until confirmed with
   SureCart support / source inspection.
2. **No order metadata** — SureCart metadata is confirmed only on **Checkout** + **Product**, not on the
   completed Order. Our per-order withdrawal state (status, deadline, evidence link) therefore needs a
   **side WP table keyed by SureCart order id** (architecturally clean; ~½ day). State is then local-only
   (not visible in SureCart's own dashboard).

## Other risks
- **Frontend API latency** — button render must go through a non-blocking AJAX endpoint, not inline page
  template (each render = outbound HTTP). Different rendering strategy from the other 3 adapters.
- **Webhooks not on by default** — `surecart/order_created` + `surecart/subscription_renewed` must be
  enabled in the SureCart dashboard → needs a clear admin notice.
- **Email injection gap** — SureCart sends its own transactional emails (not `wp_mail`); injecting the
  statutory withdrawal link into the order-confirmation email may not be possible from WP → operator may
  need to add a static notice via SureCart's email editor. **EU-compliance-relevant** (the right should
  appear in the confirmation email).
- API rate limit 150 ops / 10s (fine for single-site; queue bulk admin operations).

## Recommendation
Defer to a post-1.0 effort, behind a **SPEC + interview** (the adapter shape differs enough — side table,
AJAX render, webhook setup, logged-in-only guest path — that it deserves its own design pass). Confirm the
two blockers with SureCart support first. WooCommerce + FluentCart + EDD coverage is unaffected.

## Sources
Official SureCart developer docs — see the full agent report; key pages:
[PHP Models](https://developer.surecart.com/documentation/php-models),
[Orders actions](https://developer.surecart.com/documentation/actions-filters/orders),
[Subscriptions actions](https://developer.surecart.com/documentation/actions-filters/subscriptions),
[Metadata API](https://developer.surecart.com/api-reference/metadata),
[Templates actions](https://developer.surecart.com/documentation/actions-filters/templates),
[Customer dashboard shortcodes](https://surecart.com/docs/customer-dashboard-shortcodes/).

## Outreach — questions to confirm with the SureCart team (2026-06-15)
Requested by the user after Anil Agrawal asked for SureCart support on the FB launch thread. Send this to
SureCart developer support / partnerships before committing to the adapter. Two blockers + seven details:

**Blockers**
1. **Guest order ownership** — supported server-side (PHP) way to verify a not-logged-in buyer owns a given
   order (WooCommerce `order_key` equivalent)? Or is order access effectively logged-in-only?
2. **Order-confirmation email** — can the email SureCart sends be extended from the WP side (hook/filter to
   append a block), or only via the SureCart dashboard email editor? Needed to carry the statutory notice/link.

**Details**
3. Custom metadata on a *completed Order* (we saw it on Checkout + Product) — or do we use our own WP side table?
4. Exact ISO-3166 alpha-2 country field on `customer.billing_address`.
5. Full order `status` enum + where the paid timestamp lives (`paid_at` on Order, or via a `Charge` expand?).
6. Initial-vs-renewal: is `surecart/purchase_created` vs `surecart/subscription_renewed` the right signal, or
   is there an `is_renewal`-type flag on the order itself?
7. SDK write path to cancel a subscription at period end (`cancel_at_period_end`).
8. Are `surecart/order_created` + `surecart/subscription_renewed` webhooks available, and on by default?
9. Recommended hook/filter to render UI inside the customer dashboard
   (`surecart_template_dashboard_body_open` / a `render_block` filter?).

The full copy-paste outreach message lives in the conversation; this list is the durable record of what we need
answered to unblock the adapter.
