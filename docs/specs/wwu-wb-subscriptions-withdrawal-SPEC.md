# SPEC — Subscription-aware withdrawal (EU 14-day right)

> **Status:** **Implemented in `1.0.0-alpha.38`** (2026-06-15) — needs a live test with a subscription plugin active. **Created:** 2026-06-15 · **Slug:** `wwu-wb` · **Feature:** `subscriptions-withdrawal`
> **Decision (user, 2026-06-15):** make the EU withdrawal button subscription-aware (button on the INITIAL order only; suppressed on renewals), grounded in a 2-agent recon (codebase + official-source + EUR-Lex/Cod. Consumo). See § References.
> **Implementation note:** shipped with the recommended defaults — renewals suppressed (`treat_renewals_as_withdrawable` off), auto-cancel off, pro-rata + refund manual. Renewal detection is guarded + **fail-open** per platform (`SubscriptionAware`); the FluentCart renewal marker is best-effort pending a FluentCart-team confirmation (question logged in `_internal/`). Smoke suite `subscriptions` added. Filter `wwu_wb_order_is_renewal` to override detection.
> **Distinct from** the NA "click-to-cancel" module ([wwu-wb-subscription-cancellation-na-SPEC.md](wwu-wb-subscription-cancellation-na-SPEC.md)) — that is forward-looking *cancellation of renewals*; this is the *14-day withdrawal* applied to subscriptions. They do not overlap.

---

## 1. Overview

A subscription is **one** distance contract concluded once (at sign-up). The EU right of withdrawal (Art. 9 CRD = art. 52 Cod. Consumo) attaches to that **conclusion** and runs **14 days**; **renewals do not restart it** (a renewal is the continuation of the same contract, not a new distance contract). Today the plugin has **no subscription awareness**: every paid order — including each renewal order — passes the applicability gates, so the withdrawal button would wrongly appear on renewals (implying a fresh 14-day right and showing a window counted from the renewal date).

This feature teaches the plugin to:
1. **Detect** whether an order is a subscription **renewal** vs the **initial** order (WooCommerce Subscriptions / FluentCart native subscriptions / EDD Recurring).
2. **Suppress** the withdrawal button on **renewal** orders (one statutory gate, all surfaces).
3. Keep the button on the **initial** subscription order within its 14-day window (its own order date is already the conclusion date — no window change needed).
4. On a confirmed withdrawal of an initial subscription order, **help the merchant cancel the subscription + refund** (optional auto-cancel; refund stays manual because of the pro-rata rule).

> **Compliance crux:** a native "cancel subscription" control is **not** a substitute for the withdrawal button — *cancel ≠ refund ≠ undo the contract*. A store that offers only cancellation is not compliant for the initial 14-day period. This feature fills exactly that gap.

---

## 2. Goals & Non-Goals

### Goals
1. **Renewal detection** per platform, behind plugin-availability guards, with a filter escape hatch (`wwu_wb_order_is_renewal`) for custom subscription systems.
2. **Single-gate suppression**: one check in `ApplicabilityResolver` (reason `renewal_order`) automatically covers all 8 button surfaces; plus close the one surface that bypasses applicability (`Shortcodes::form()`).
3. **Correct window** on the initial order (already its own date — verify, no change expected).
4. **Withdrawal-of-subscription action**: on confirm, flag the request as a subscription in the Requests dashboard with a pro-rata/cancel reminder; optionally **auto-cancel** the subscription via the platform API (opt-in setting, default OFF).
5. Reuse the existing spine (NormalizedOrder value object, applicability gate, evidence log, durable medium) — no parallel infrastructure.
6. **Fail-open / fail-safe**: if subscription detection is unavailable or uncertain, behave as today (treat as a normal order) — never wrongly *hide* a legitimate withdrawal button, but do hide on a *confirmed* renewal.

### Non-Goals
- **Not** the forward-looking cancellation right (NA click-to-cancel module is separate).
- **Not** auto-computing the **pro-rata refund amount** (needs contract price + service-supplied logic the plugin doesn't own) — the plugin *reminds*; the merchant *decides*. (Optional future estimate.)
- **Not** building a subscriptions engine — integrate with WC Subscriptions / FluentCart / EDD Recurring only.
- **Not** changing the Art. 59 digital-exemption behaviour (already handled at checkout — a digital subscription with immediate-access consent already loses the right correctly).

---

## 3. User Stories

- **Consumer (initial order, within 14 days):** I open my first subscription order and see the withdrawal button; I withdraw; the trader cancels my subscription and refunds me (less any pro-rata for service already used).
- **Consumer (renewal order):** I open a renewal order — there is **no** withdrawal button (correctly, the 14-day right is long gone); to stop the subscription I use the platform's cancel control.
- **Merchant:** in **Requests** I see a withdrawal flagged *"subscription — initial order"* with a reminder to cancel the subscription + a note about the possible pro-rata deduction, and (if I enabled it) the subscription was already cancelled automatically.
- **Developer/merchant with a custom subscription plugin:** I hook `wwu_wb_order_is_renewal` to teach the plugin my renewal detection.

---

## 4. Architecture

**Single source of truth: a data flag on the value object, one gate in the resolver.** Recon confirmed all 8 button surfaces (WooMyAccount ×3, FluentCartPortal, EddCustomerOrders ×4, EligibleOrders, Shortcodes, OrderEmailLink, FluentCartWithdrawalTag, NoScriptFlow) converge on `Services::instance()->applicability->decide($order)->show`. So suppression belongs in `ApplicabilityResolver`, fed by a flag the adapters set.

```
adapter->get_order($ref)                         (per platform: detect renewal)
   → NormalizedOrder{ …, is_renewal: bool, subscription_ref: string }
        → ApplicabilityResolver::evaluate()
             gate 1  is_eligible_status                (existing)
             gate 1.5 if $order->is_renewal → show=false, reason='renewal_order'   (NEW)
             gate 2  B2B VAT                           (existing)
             gate 3  Art. 59 withdrawable item         (existing)
             gate 4  scope/mode                        (existing)
        → ApplicabilityDecision{ show, mandatory, reason, country }
   → every surface honours ->show automatically
```

**Cancellation is a behaviour, not data** → a separate **optional capability interface** `SubscriptionAware` that an adapter implements only when its subscription plugin is active. The withdrawal-confirm path + the Requests dashboard use it via `instanceof` checks (no change to the 23-method `OrderDataSource` contract; adapters without subscriptions are unaffected).

```
WithdrawalService::confirm()  (initial subscription order)
   → record withdrawal (existing) + stamp meta is_subscription_initial=1, subscription_ref
   → if setting 'cancel_subscription_on_withdrawal' AND adapter instanceof SubscriptionAware
        → adapter->cancel_subscription($order_ref)   (guarded; logged as a distinct evidence event)
RequestsDashboard
   → if is_subscription_initial: badge "Subscription" + reminder (cancel + pro-rata) + a "Cancel subscription" action when not auto-cancelled
```

### Per-platform detection (verified, guarded)
- **WooCommerce Subscriptions** (paid plugin, guard `function_exists('wcs_order_contains_renewal')`): `is_renewal = wcs_order_contains_renewal($order)`; fallback meta `_subscription_renewal`. Subscription for cancel: `wcs_get_subscriptions_for_renewal_order($order)` / `wcs_get_subscriptions_for_order($order, ['order_type'=>'any'])`; cancel `$subscription->can_be_updated_to('cancelled') && $subscription->update_status('cancelled')`.
- **FluentCart** native subs (guard `class_exists('\FluentCart\App\Models\Subscription')`): an order is **initial** iff `Subscription::where('parent_order_id', $orderId)->first()` matches it → `is_renewal = (subscription exists for this customer/cycle) && order_id !== parent_order_id`. Cancel: `$subscription->cancelRemoteSubscription([...])`. *Caveat (recon): mapping a renewal charge back to its subscription goes through the `transactions` relation — no named helper; parent-order detection is the documented, reliable signal.*
- **EDD Recurring** (paid extension, guard `class_exists('EDD_Subscription')`): a payment is a **renewal** iff its status is `edd_subscription` (display "Renewal"); the **parent** is `EDD_Subscription->parent_payment_id` / `get_original_payment_id()`. Cancel: `$sub->can_cancel() && $sub->cancel()`. *Caveat (recon): no official `edd_get_subscription_by_payment()`; resolve via `parent_payment_id` / `get_child_payments()` / status.*

---

## 5. Data Model

**`NormalizedOrder` (src/Platform/NormalizedOrder.php)** — two new constructor params, appended last (back-compatible, default empty):
- `bool $is_renewal = false` — true for a subscription renewal order.
- `string $subscription_ref = ''` — the platform subscription id (for the cancel action / dashboard), '' when not a subscription.
- `window_start()` unchanged (`completed ?? paid ?? created`) — for the **initial** order this is already the conclusion date. No new date field needed (renewals are suppressed, so their window is never computed).

**`ApplicabilityDecision`** — new `reason` slug value `renewal_order` (no shape change).

**Order meta / evidence (set on confirm of an initial subscription order):**
- `_wwu_wb_subscription_initial` = `1` (per-platform via adapter meta).
- `_wwu_wb_subscription_ref` = the subscription id.
- Evidence-log event `subscription_cancelled` (PII-free: `{platform, subscription_ref, by, at, result}`) when auto-cancel runs.

**Settings (`wwu_wb_settings`):**
- `cancel_subscription_on_withdrawal` (bool, default **false**) — auto-cancel the subscription when an initial-order withdrawal is confirmed.
- `treat_renewals_as_withdrawable` (bool, default **false**) — escape hatch to disable suppression (e.g. a store that genuinely concludes a new contract per cycle). Off = legally-correct default.

---

## 6. API / Interfaces

**New optional interface `WWU\WithdrawalButton\Platform\SubscriptionAware`:**
```php
interface SubscriptionAware {
    /** Whether $order_ref is a subscription RENEWAL order. */
    public function is_renewal_order( string $order_ref ): bool;
    /** The platform subscription id tied to $order_ref, or ''. */
    public function subscription_ref( string $order_ref ): string;
    /** Cancel the subscription tied to $order_ref. Returns true on success. */
    public function cancel_subscription( string $order_ref ): bool;
}
```
Each adapter implements it **only** when its subscription plugin is active (guards above); the value-object `$is_renewal`/`$subscription_ref` are populated from `is_renewal_order()`/`subscription_ref()` inside `get_order()`.

**Filters:**
- `wwu_wb_order_is_renewal` ( bool $is_renewal, string $order_ref, string $platform_key ) — override/teach detection.
- `wwu_wb_subscription_cancel_result` ( bool $ok, string $order_ref, string $platform_key ) — observe/override the cancel outcome.
- Existing `wwu_wb_applicability_decision` already lets a site override the `renewal_order` suppression per order (safety valve).

**No REST/shortcode signature changes.** `Shortcodes::form()` gains an internal applicability guard (see Edge Cases).

---

## 7. UI / UX

- **Consumer:** zero new UI on the happy path — the button simply does not render on renewal orders, and the existing `?wwu_wb_diag=1` admin print shows `reason: renewal_order` for transparency.
- **Settings → Receipt & evidence (or a small "Subscriptions" group):** the two toggles (auto-cancel; treat-renewals-as-withdrawable), each with a tooltip + a one-line plain-language explanation per Standard #12, and a banner explaining the legal distinction (recesso vs disdetta) — reuse the wording already drafted in this session.
- **Requests dashboard:** a `Subscription` badge on rows where `_wwu_wb_subscription_initial`; an inline reminder *"This is a subscription's initial order — cancel the subscription and refund (a pro-rata deduction may apply if the consumer requested immediate start)"*; a **"Cancel subscription"** action button (shown when the adapter is `SubscriptionAware` and not already auto-cancelled), nonce + capability gated like the other row actions.
- **Durable-medium e-mail (optional copy tweak):** when the withdrawn order is a subscription, add a line clarifying that future renewals are stopped.

---

## 8. Edge Cases

1. **`Shortcodes::form()` bypass (recon finding #3):** `[wwu_wb_form order_id="X"]` renders the form via access-control only, **no** applicability check — so a renewal order's form could still render after the button is suppressed. **Fix:** add `if ( ! Services::instance()->applicability->decide($order)->show ) return notice;` after the order resolves in `form()`. (The button/list surfaces already gate; this closes the direct-shortcode hole.)
2. **No subscription plugin active:** all guards false → `is_renewal=false` everywhere → behaviour identical to today. No renewal orders exist anyway.
3. **Mixed order** (a renewal that also contains a one-off product): rare; treat by the renewal flag (suppress) — the one-off's withdrawal is an acceptable edge the merchant can override via `wwu_wb_applicability_decision`. Documented.
4. **FluentCart renewal-charge → subscription mapping** is not a documented named helper (recon caveat) — detection leans on `parent_order_id` equality (initial) + the `transactions` relation; if uncertain, **fail-open** (treat as non-renewal) so a legitimate button is never wrongly hidden. Flagged for the live test.
5. **Auto-cancel failure** (gateway refuses / API error): log `subscription_cancelled` with `result=failed`, keep the withdrawal recorded, surface the manual "Cancel subscription" action — never block the withdrawal on a cancel failure.
6. **Window on the initial order:** confirm `window_start()` uses the initial order's own date (it does). If a store backfills renewals as separate parent-less orders, the filter escape hatch covers it.
7. **Art. 59 digital subscription:** already handled at checkout (immediate-access consent → button hidden). The renewal gate is additive and consistent.
8. **B2B / out-of-scope country:** unchanged — those gates still run after the renewal gate (a renewal that's also B2B is suppressed by the first matching gate; order of gates documented).

---

## 9. Security

- No new untrusted input: detection reads platform order data (already trusted, server-side). The cancel action is gated by the existing admin capability (`manage_woocommerce`) + nonce (Requests dashboard) and, for auto-cancel, runs only inside the already-authenticated withdrawal-confirm path on an order the caller proved ownership of.
- Auto-cancel is **opt-in** (default off) so the plugin never mutates a subscription without explicit merchant consent.
- Guard every platform call with `function_exists`/`class_exists`/`method_exists` (recon-specified) — no fatal on a store without the subscription plugin.
- The `subscription_cancelled` evidence event is PII-free (no email/IP), consistent with the immutable-log policy.
- Filters (`wwu_wb_order_is_renewal`, `wwu_wb_subscription_cancel_result`) are server-side, not user-reachable.

---

## 10. Performance

- Renewal detection runs once per `get_order()` (already cached per request by each adapter's `load()` cache). WC `wcs_order_contains_renewal` is a meta read; FluentCart is one indexed `parent_order_id` query; EDD is a payment-status/meta read. Negligible (≤1 extra cached query per order render).
- No new frontend cost for non-subscription stores (guards short-circuit). No change to the hot path for visitors who never see the button.

---

## 11. Testing Strategy

- **Smoke (PHP):** a `SubscriptionAware` stub adapter; assert `ApplicabilityResolver::decide()` returns `show=false, reason='renewal_order'` when `$is_renewal=true`, and unchanged otherwise; assert `Shortcodes::form()` returns the notice for a renewal order.
- **Unit:** per-adapter `is_renewal_order()` against fixture orders (WC `_subscription_renewal` meta; EDD `edd_subscription` status; FluentCart `parent_order_id` equality), all guarded paths returning false when the plugin constant/class is absent.
- **Manual live (new checklist `docs/testing/…-subscriptions-CHECKLIST.md`):** on a store with WC Subscriptions (and separately FluentCart / EDD Recurring): (a) initial order shows the button within 14 days; (b) a renewal order shows **no** button (and `?wwu_wb_diag=1` → `renewal_order`); (c) withdraw on the initial order → Requests shows the Subscription badge + reminder; (d) with auto-cancel on, the subscription status becomes cancelled; (e) refund still recorded.
- **Fail-open:** with the subscription plugin deactivated, every order behaves as a normal order.

---

## 12. Open Questions

1. **Auto-cancel default** — keep OFF (merchant control, recommended) or ON for "easiest compliance"? (Leaning OFF.)
2. **Pro-rata estimate** — do we ever want to *estimate* the Art. 14(3) deduction (price × elapsed/period) as a merchant hint, or stay guidance-only? (Leaning guidance-only for v1.)
3. **FluentCart renewal-charge detection** — confirm on a live FluentCart subscription store that `parent_order_id` equality + the transactions relation reliably classify renewal orders (recon caveat).
4. **Refund automation** — out of scope for v1 (merchant issues the refund), but a future "refund on withdrawal" could reuse `WooRefundRecorder`. Confirm we stay manual.
5. **FluentCart native EU withdrawal** (announced, "soon") — if FluentCart ships native subscription withdrawal, coordinate so we don't double-handle (track with the FluentCart positioning question already in `_internal/`).

---

## References
Recon (2026-06-15), official sources verified by the web agent:
- WooCommerce Subscriptions — `wcs_order_contains_subscription`/`wcs_order_contains_renewal`, `wcs_get_subscriptions_for_renewal_order`, `WC_Subscription::update_status()/can_be_updated_to()/get_parent()`: <https://woocommerce.com/document/subscriptions/develop/functions/order-cart-functions/> · <https://github.com/Automattic/woocommerce-subscriptions-core/blob/trunk/includes/wcs-order-functions.php> · <https://github.com/Automattic/woocommerce-subscriptions-core/blob/trunk/includes/class-wc-subscription.php>
- FluentCart — `Subscription.parent_order_id`, `order()`/`transactions()` relations, `cancelRemoteSubscription()`: <https://dev.fluentcart.com/database/models/subscription> · <https://docs.fluentcart.com/guide/customer-dashboard/subscriptions>
- EDD Recurring — `EDD_Subscription` `parent_payment_id`/`get_original_payment_id()`/`get_child_payments()` (renewal status `edd_subscription`), `can_cancel()`/`cancel()`: <https://easydigitaldownloads.com/docs/recurring-payments-developer-edd_subscription/>
- EU law — Directive 2011/83/EU Art. 9 (14-day right + start), Art. 13 (refund ≤14 days), Art. 14(3)/(4) (pro-rata for express immediate-start service): EUR-Lex 32011L0083. Italy: Codice del Consumo (D.lgs 206/2005) art. 52, 56, 57. Withdrawal button: Dir. (EU) 2023/2673 new Art. 11a = art. 54-bis Cod. Consumo.
- Codebase integration points: `src/Platform/{NormalizedOrder,OrderDataSource,WooCommerceAdapter,FluentCartAdapter,EddAdapter}.php`, `src/Domain/{ApplicabilityResolver,ApplicabilityDecision,WindowCalculator}.php`, the 8 button surfaces (recon §buttonSurfaces).
