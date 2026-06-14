# SPEC — Easy Digital Downloads (EDD) integration

> A third platform adapter (after WooCommerce + FluentCart) so the withdrawal button,
> evidence flow and Art. 59 exemption consent capture work on **Easy Digital Downloads
> 3.0+** stores. EDD is digital-goods-first, so the **digital-immediate exemption
> (Art. 59(1)(o))** is its most common case — the consent-capture feature matters here.
>
> **Status: design (not implemented).** Integration surface verified against official
> EDD sources (2026-06-14, see References). Awaiting confirmation before coding.
> Target: a new `1.0.x` minor once shipped.

## 1. Overview

The plugin's platform layer is an `OrderDataSource` interface with two adapters
(`WooCommerceAdapter`, `FluentCartAdapter`) resolved by a `PlatformRegistry`; the
Frontend/Domain/REST layers never touch platform internals. EDD is added the same way:
a new `EddAdapter` + an `EddCheckoutConsent` capture class. Everything downstream
(button surfaces, evidence log, durable-medium e-mail, `ConsentReader`, retention purge,
Consent-records view) already works platform-agnostically and needs no change.

EDD 3.0 stores orders in **custom tables** via the `EDD\Orders\*` API (NOT the legacy
`edd_payment` CPT). The adapter targets 3.0+ only.

## 2. Goals & Non-Goals

**Goals**
- `EddAdapter implements OrderDataSource` — load/normalise an EDD order, ownership/guest
  checks, withdrawal-requested marking, notes, plugin meta.
- `EddCheckoutConsent` — render + validate + capture the conditional-exemption consent on
  the EDD checkout, reusing `WooCheckoutConsent::build_consent_entries` + `ExemptionConfirmation`.
- **Category-aware exemptions** on EDD (resolve a download's `download_category` terms) —
  better than FluentCart (product-ID only).
- Admin "Open order" link via `edd_get_admin_url(...)`.

**Non-Goals**
- Legacy EDD < 3.0 (`edd_payment` CPT) support — out of scope unless required.
- A bespoke EDD customer-portal surface — the standalone public form page + the account
  `[edd_purchase_history]` link are enough for MVP (mirror the FluentCart "standalone page" stance).
- Changing the evaluator / consent contracts (unchanged).

## 3. User Stories

- *As a merchant on EDD selling ebooks/courses,* I tag the immediate-download products under
  "Digital content with immediate access"; the EDD checkout shows the required consent
  checkbox, the buyer gets the durable-medium e-mail, and the button is then hidden — exactly
  like WooCommerce.
- *As a consumer who bought a normal (non-exempt) digital good,* I still see the withdrawal
  button on my EDD account / the public page and can withdraw.
- *As the merchant,* the withdrawal request appears in the Requests dashboard with an "Open
  order" link straight into the EDD order screen.

## 4. Architecture

- **`Platform\EddAdapter implements OrderDataSource`** (mirror of `FluentCartAdapter`, all
  calls guarded):
  - `key()` → `'edd'`; `is_active()` → `function_exists('edd_get_order')`.
  - `get_order($ref)` → `edd_get_order((int)$ref)` → `EDD\Orders\Order`; build `NormalizedOrder`:
    email `$order->email`; user id `$order->user_id`; status mapped (`complete`→paid-eligible,
    `refunded`/`partially_refunded`); country from the order address; items from
    `$order->get_items()` → each `EDD\Orders\Order_Item` → `product_id` (download id), name,
    `category_ids` via `wp_get_post_terms($item->product_id, 'download_category', ['fields'=>'ids'])`,
    `virtual/downloadable = true` (EDD is digital).
  - `is_refunded($ref)` → status in `refunded|partially_refunded`.
  - `verify_owner` → `(int)$order->user_id === $user_id`; `verify_guest_key` → `hash_equals($order->payment_key, $key)`.
  - `mark_withdrawal_requested` → set our meta + a note (EDD status transitions are merchant-driven).
  - `add_note` → `edd_add_note(['object_id'=>$id,'object_type'=>'order','content'=>$note])`.
  - `get_meta/set_meta/batch_meta` → `edd_get_order_meta` / `edd_update_order_meta` (prefix
    `wwu_wb_`). (EDD order meta is first-class — unlike FluentCart we do NOT need our own option.)
- **`Frontend\EddCheckoutConsent`** (mirror of `WooCheckoutConsent`):
  - render on `edd_purchase_form_before_submit` (+ `edd_purchase_form_user_info_fields` so the
    field also appears where EDD's block/Checkout-block re-fires personal-info fields), shown
    only when the cart (`edd_get_cart_contents()`) has a conditional-exempt download;
  - validate on `edd_checkout_error_checks($valid_data, $_POST)` → `edd_set_error(code, msg)`;
  - capture on `edd_complete_purchase($order_id, $payment, $customer)` → read `$_POST` consent +
    re-derive the order's conditional items (adapter `get_order`) → `build_consent_entries` →
    `$adapter->set_meta($order_id, 'consent', $entries)` + durable-medium e-mail + PII-free log.
- **`PlatformRegistry::create_default()`** → append `new EddAdapter()` when the class loads.
- **`Plugin::register_services()`** → register `EddCheckoutConsent` when EDD active.
- **`RequestsDashboard::order_admin_url()`** → add an `'edd'` branch returning
  `edd_get_admin_url(['page'=>'edd-payment-history','view'=>'view-order-details','id'=>$ref])`.

## 5. Data Model

No new tables. EDD order meta (`edd_*_order_meta`) holds `wwu_wb_consent`,
`wwu_wb_consent_logged`, `wwu_wb_consent_confirmation_sent`, `wwu_wb_native_status_note`.
The `_wwu_wb_consent` entry shape is identical to the WooCommerce/FluentCart one, so
`ConsentReader` reads it via `$adapter->get_meta($ref,'consent')` unchanged. Retention purge
currently targets WooCommerce orders; an EDD purge pass is a follow-up (Open Q).

## 6. API / Interfaces (verified EDD 3.0)

| API | Kind | Purpose |
|---|---|---|
| `edd_get_order(int $id): EDD\Orders\Order\|false` | fn | Load an order |
| `EDD\Orders\Order` `->email/->status/->user_id/->customer_id/->payment_key`, `->get_items()` | model | Read fields |
| `EDD\Orders\Order_Item` `->product_id/->price_id/->status` | model | Line items (downloads) |
| `edd_add_order_meta / edd_get_order_meta / edd_update_order_meta` | fn | Plugin meta |
| `edd_add_note(['object_id','object_type'=>'order','content'])` | fn | Timeline note |
| `edd_get_admin_url(['page'=>'edd-payment-history','view'=>'view-order-details','id'=>$id])` | fn | Admin order URL |
| taxonomy `download_category` on the `download` CPT; `wp_get_post_terms($pid,'download_category')` | tax | Category-based exemptions |
| `edd_purchase_form_before_submit` / `edd_purchase_form_user_info_fields` | action | Render checkout field |
| `edd_checkout_error_checks($valid_data, $_POST)` + `edd_set_error()` | action | Block checkout |
| `edd_complete_purchase($order_id, EDD_Payment, EDD_Customer)` | action | Persist consent (once) |
| `edd_transition_order_status($old,$new,$order_id)`, `edd_refund_order($order_id,$refund_id,$all)` | action | Lifecycle / refund evidence |

No new REST endpoints; the `wwu-wb/v1` flow is platform-agnostic.

## 7. UI / UX

- **Checkout:** a required acknowledgement checkbox per conditional reason, statutory wording
  (`ConsentText`), shown only when a conditional-exempt download is in the EDD cart.
- **Consumer surfaces:** the standalone public form page (always works) + a link from the EDD
  account (`[edd_purchase_history]`) — a richer in-account surface is a post-MVP nicety.
- **Admin:** Requests dashboard "Open order (refund)" → EDD order screen.
- Strings i18n; the consumer e-mail is the same `ExemptionConfirmation`.

## 8. Edge Cases

- **EDD < 3.0** → `is_active()` false (no `edd_get_order`) → adapter absent, fail-safe.
- **EDD block checkout** (if/when present) — confirm `edd_purchase_form_*` re-fire; if a custom
  field doesn't survive the block submission, same fail-safe as the WC/FluentCart blocks (no
  checkbox → button stays). **Open Q — needs a live test.**
- **Category resolution** — a download with many `download_category` terms → read ids; bounded.
- **Refund** — `status` `refunded`/`partially_refunded` reflected in `is_refunded`.
- **Guest vs account** — `user_id` 0 for guest → guest token path via `payment_key`.
- **Country/applicability** — resolve from the EDD order address; if absent, the EU/EEA mode
  may hide the button (consistent with the other adapters).

## 9. Security

- Capability/nonce: EDD checkout has its own nonce; we read `$_POST` consent inside
  `edd_checkout_error_checks`/`edd_complete_purchase` (post-nonce). Values consumed as booleans
  by reason key. Output escaped. No secrets. Same posture as `WooCheckoutConsent`.

## 10. Performance

- Adapter reads are O(1) per order, cached per request (mirror FluentCart's `$cache`).
- Category resolution per line item uses `wp_get_post_terms` (cached by WP). No frontend impact
  for non-checkout pages.

## 11. Testing Strategy

- Smoke: pure pieces (status mapping, item normalisation shape) testable without EDD; gate the
  EDD-dependent ones with `function_exists('edd_get_order')` skips.
- Manual: an EDD 3.0 store — tag a download under 59_o, buy it, confirm the checkbox blocks,
  the consent stores, the durable-medium e-mail sends, the button hides, and it appears in
  Consent records; a non-exempt download still shows the button.
- `wwu-tools` REST smoke continues to work (platform-agnostic).

## 12. Open Questions

1. **EDD block checkout** — does EDD ship a block-based checkout in the target version, and do
   the `edd_purchase_form_*` hooks fire there? (Affects field rendering; default: classic hooks,
   fail-safe on block.) *Needs a live test / EDD docs confirmation.*
2. **Retention purge for EDD** — extend `ConsentRetention` to also sweep EDD orders, or keep it
   WooCommerce-only for now? *Default: follow-up; document the gap.*
3. **Adapter-only first vs full capture** — ship the read adapter (button + evidence) first,
   then the checkout consent capture? *Default: both together, mirroring FluentCart.*
4. **Country source** — confirm the EDD 3.0 order address accessor for applicability.
5. **Customer-portal surface** — standalone page only (MVP) vs an in-account injection.

## References

- EDD 3.0 orders API — `edd_get_order`, `EDD\Orders\Order`/`Order_Item`, `edd_*_order_meta`,
  `edd_add_note`, `edd_get_admin_url`, `edd_complete_purchase`, `edd_checkout_error_checks`,
  `edd_transition_order_status`, `edd_refund_order`; `download` CPT + `download_category`
  taxonomy — verified against the awesomemotive/easy-digital-downloads GitHub
  (`includes/orders/`, `includes/checkout/`, `includes/post-types.php`) + easydigitaldownloads.com
  docs (multi-agent official-source sweep, 2026-06-14).
- Internal: [`OrderDataSource`](../../src/Platform/OrderDataSource.php), the FluentCart adapter
  template, and [the FluentCart hooks analysis](../analysis/wwu-wb-fluentcart-hooks-ANALYSIS.md)
  (the verify-before-build discipline).
