# Analysis — FluentCart customer-portal hooks, verified against official docs

> Created 2026-06-14 (build `1.0.0-alpha.19`). Records the official-source verification of every FluentCart hook/API the withdrawal plugin depends on, after live testing showed the first (alpha.18) integration did not work. Authoritative source: **dev.fluentcart.com** (developer docs, traced to FluentCart source files) + **docs.fluentcart.com**.

## Why this exists

The alpha.18 FluentCart integration was written from inferred/paraphrased hook shapes. On the live site the result was: the **"Diritto di recesso"** sidebar entry appeared but opened a **blank page**, the per-order button never showed, and no banner appeared. Per the standing rule *"always verify against official documentation; if you can't find it, ask"*, each hook/API was re-checked against the official docs before re-coding. This doc is the verified contract reference for future changes.

## Verified contracts

### 1. `fluent_cart/customer_portal/custom_endpoints` — portal page
- **Source:** dev.fluentcart.com/hooks/filters/customers-and-subscriptions (FluentCart `app/Hooks/Handlers/ShortCodes/CustomerProfileHandler.php`).
- **Signature:** `add_filter('fluent_cart/customer_portal/custom_endpoints', cb, 10, 1)`; `cb($endpoints): array`.
- **Shape:** the endpoint **slug is the array key**; the only documented keys are `render_callback` (callable) **or** `page_id` (int). `render_callback` must **`echo`** — the return value is ignored.
- **Was wrong:** we appended `$endpoints[] = ['key'=>…, 'slug'=>…, 'label'=>…, 'title'=>…, 'render_callback'=>…, 'callback'=>…]` and the callback **returned** a string → FluentCart echoed nothing → **blank page**.
- **Fixed:** `$endpoints['wwu-withdrawal'] = ['render_callback' => [$this, 'render_endpoint']]`; `render_endpoint()` now echoes.

### 2. `fluent_cart/global_customer_menu_items` — sidebar entry
- **Source:** dev.fluentcart.com/hooks/filters/customers-and-subscriptions.
- **Signature:** `add_filter(…, cb, 10, 2)`; `cb($menuItems, $context): array`. `$context['base_url']` is e.g. `/customer-portal/#/`.
- **Shape:** item keyed by slug; exactly `label`, `css_class`, `link`, `icon_svg` (raw SVG). **`css_class => 'fct_route'`** is required so the SPA navigates client-side.
- **Was wrong:** `url` (→ `link`), `icon` (→ `icon_svg`), plus unsupported `key`/`title`/`route`/`priority`/`slug`, and missing `css_class`.
- **Fixed:** `$menuItems['wwu-withdrawal'] = ['label'=>…, 'css_class'=>'fct_route', 'link'=>$base.'wwu-withdrawal', 'icon_svg'=>…]`.

### 3. `fluent_cart/customer_dashboard_data` — dashboard banner
- **Source:** dev.fluentcart.com/hooks/filters/customers-and-subscriptions (FluentCart `app/Http/Controllers/FrontendControllers/CustomerProfileController.php`).
- **Signature:** `add_filter(…, cb, 10, 2)`; `cb($data, $context): array` (must return `$data`). `$context['customer']` is the customer model. Slots: `$data['sections_parts']['before_orders_table']` and `after_orders_table`.
- **Was wrong:** registered with `,10,1` (dropping `$context`).
- **Fixed:** `,10,2`, callback `($data, $context)`. Slot path was already correct.

### 4. `fluent_cart/customer/order_details_section_parts` — per-order button
- **Source:** dev.fluentcart.com/hooks/filters/orders-and-payments (FluentCart `app/Http/Controllers/FrontendControllers/CustomerOrderController.php`).
- **Signature:** `add_filter(…, cb, 10, 2)`; `cb($sections, $context): array`. `$context = ['order' => Order, 'formattedData' => [...]]`. Slots (all HTML-string values): `before_summary`, `after_summary`, `after_licenses`, `after_subscriptions`, `after_downloads`, `after_transactions`, `end_of_order`.
- **Verdict:** our hook usage already matched (`after_summary`, `$context['order']->id`, 2 args). **No hook change.** The button still never showed because of the data bug in §6.

### 5. `fluent_cart/email_notification_merge_tags` — DOES NOT EXIST
- **Searched:** all six filter category pages + both action overviews + admin-and-templates. The filter name is **not in the official docs**.
- **Closest real hook:** `fluent_cart/editor_shortcodes` (1 arg) registers tags in the email **editor picker** only — shape `['group' => ['title'=>…, 'key'=>…, 'shortcodes'=>['{{ns.tag}}' => 'Label']]]`, where the value is a display label, not the resolved value. There is **no documented value-resolver** hook for send-time replacement.
- **Decision:** removed the merge-tag code rather than ship a guessed API. A `{{wwu.recesso_url}}` tag with no resolver would render literally in sent mail. Deferred pending an official resolver hook (ask FluentCart / re-check docs).

### 6. Order/Customer models — data access (the real reason the button never showed)
- **Source:** dev.fluentcart.com/database/models/order, /customer, /relationships, /schema.
- **Verified facts:**
  - Namespaces `\FluentCart\App\Models\Order` and `\FluentCart\App\Models\Customer` — correct.
  - `fct_customers.user_id` links a FluentCart customer to a **WordPress user**.
  - `fct_orders.customer_id` is the **FluentCart customer PK** (FK to `fct_customers`), **not** a WP user id. It is **nullable**.
  - Email lives on the **customer** relation: `$order->customer->email`.
  - Billing country lives on the **billing_address** relation (`OrderAddress`): `$order->billing_address->country`.
  - `$order->created_at` exists; there is no flat paid column (use transactions / `completed_at`).
  - `$order->id` is the primary key.
- **Was wrong:** the adapter read `customer_email`/`billing_country` as flat columns (don't exist → empty), and took the WP user id from `$order->user_id ?? $order->customer_id` — so country/email came back empty (→ applicability `show=false` → **no button, empty chooser**) and ownership compared the customer PK to the WP user id.
- **Fixed:** adapter reads through `customer` / `billing_address` relations (lazy-loaded via a guarded `rel()` helper, with flat fallbacks); WP user id from `customer->user_id`; `verify_owner()` compares the customer's `user_id`. The chooser query (`Customer::where('user_id', $wpUserId)->first()` then `Order::where('customer_id', $customer->id)`) was already correct.

## Applicability gates (alpha.20) — why FluentCart orders were still hidden

After the hooks were fixed (alpha.19) the portal page rendered, but FluentCart orders
were still filtered out by three data-layer gates in `ApplicabilityResolver`/
`ArticleFiftyNineEvaluator`. Verified against the official model schema and fixed:

- **Items (Art. 59 gate).** `has_withdrawable_item()` looped `$order->items`; with zero
  items it returned false → `no_withdrawal_right` → hidden. The withdrawal right is the
  default, so empty/unreadable items now default to *withdrawable*. The adapter reads
  items via the official `order_items` relation; `OrderItem.post_id` is the product ref
  and `OrderItem.fulfillment_type` (physical|digital|service) the type.
- **Status.** Eligibility presupposes a paid contract. FluentCart signals this via
  **`payment_status` = paid** (the "Paid" badge), not the fulfillment `status` (which can
  be `pending`). The adapter surfaces `paid` so the platform-agnostic eligible-status
  allowlist matches.
- **Country.** `fct_order_addresses.country` is ISO-2. It is read via `billing_address`
  → `order_addresses` (type=billing) → flat fallback. An unresolved country had made the
  order read as out-of-scope (hidden) in the default `eu_eea_only` mode.
- **Customer match.** Added an email fallback (`Customer::where('email', …)`) for orders
  whose customer is not linked to a WP `user_id`.
- **Diagnostic.** `?wwu_wb_diag=1` (admin, read-only) prints each FluentCart order's
  status/country/items + the applicability `show`/`reason` on the standalone page.

## Residual notes / open items
- **Asset loading on the SPA portal:** the FluentCart portal shortcode tag is not documented, so `maybe_enqueue_on_portal()` uses a heuristic marker match. Not critical: chooser rows and the per-order button link to the standalone public form page, which always loads our CSS/JS.
- **Email merge tag:** deferred (no official resolver hook found). If FluentCart confirms one, wire `{{wwu.recesso_url}}` then.
- **Line items / VAT** for Art. 59 exemptions: items are read via the `order_items` relation now, but exemptions are design-only (not enforced yet).

## Verified — checkout consent capture + lifecycle (2026-06-14, FluentCart support reply + docs re-check)

FluentCart support answered our integration questions; every hook was re-verified against the
official docs (`dev.fluentcart.com/hooks/...` + the Order/Subscription model pages). **Build
only on the docs-confirmed names; treat the rest as support-claim-only until tested live.**

### Confirmed in docs (safe to build on)
- **Checkout render** (ACTION, payload `$data['cart']`): `fluent_cart/checkout_form_opening`,
  `fluent_cart/before_payment_methods`, `fluent_cart/after_payment_methods`,
  `fluent_cart/after_order_notes`, `fluent_cart/after_checkout_button`.
- **Checkout validation** (FILTER): `fluent_cart/checkout/validate_before_process`
  (`($validation=true, $data)` → return `true` or a `WP_Error` to block);
  `fluent_cart/checkout/validate_data` (`($errors, $data)` → return the errors array).
- **Capture to order meta** (ACTION — **not** a filter): `fluent_cart/checkout/prepare_other_data`
  — `$data` has `cart`, `order` (draft, already created), `prev_order`, `request_data`,
  `validated_data`. Write with `$data['order']->updateMeta($key, $value)`; return nothing.
- **Order meta API** (`FluentCart\App\Models\Order`): `getMeta($key, $default=false)`,
  `updateMeta($key, $value)`, `deleteMeta($key)`.
- **Admin order URL:** `$order->getViewUrl('admin')` (method confirmed; the exact URL string is
  unverified — test it; canonical route `admin.php?page=fluent-cart#/orders/{id}/view`).
- **Refund/status** (ACTION): `fluent_cart/order_refunded` + `_fully_refunded` / `_partially_refunded`
  (payload `order, refunded_items, new_refunded_items, refunded_amount, manage_stock, transaction,
  customer, type`); `fluent_cart/order_status_changed(_to_{status})` + `fluent_cart/payment_status_changed(_to_{status})`
  (payload `order, old_status, new_status, manageStock, activity`).

### Discrepancies vs the support reply (do NOT trust the reply blindly)
- **`subscription_canceled` real name = `fluent_cart/payments/subscription_canceled`** (the
  `/payments/` sub-namespace; the reply omitted it). Subscription status: `fluent_cart/payments/subscription_{status}`.
- **`fluent_cart/order_paid` does NOT exist in docs** → use **`fluent_cart/order_paid_done`** (async,
  via Action Scheduler; payload `order, transaction, customer, subscription?`) or `payment_status_changed_to_paid`.

### Support-claim-only (NOT in docs — TEST before relying)
- **`fluent_cart/smartcode_fallback`** + **`fluent_cart/editor_shortcodes`** (the email merge-tag
  resolver) — the `/hooks/actions/emails.html` page 404s. Keep the `{{wwu.recesso_url}}` merge-tag
  **deferred** until verified on a dev install.
- **`cancelRemoteSubscription(['effective_from' => 'immediately'])`** — `effective_from` undocumented
  (docs list only `reason, fire_hooks, note`). For the future NA click-to-cancel module, test that
  `'immediately'` forces instant cancel vs end-of-term.

### Implementation mapping
- **FluentCart consent capture** = mirror of `Frontend\WooCheckoutConsent` → a new
  `Frontend\FluentCartCheckoutConsent`: render the per-reason checkbox on a render hook, block via
  `validate_before_process`, store the entries in `prepare_other_data` (`$order->updateMeta('consent', …)`,
  which the adapter's `set_meta` already wraps), send the durable-medium confirmation + log the dispatch
  (reuse `Mail\ExemptionConfirmation`). `Frontend\ConsentReader` already reads it platform-agnostically.
- **Admin order URL** = replace the best-effort guess in `RequestsDashboard`/`FluentCartAdapter` with
  `$order->getViewUrl('admin')`.
