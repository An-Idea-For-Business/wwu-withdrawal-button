# Live test — **FluentCart** Checkout consent capture

> Manual test checklist. Anyone can run it on a staging store. ~15 min.
> Goal: prove that the Art. 59 **exemption consent** is captured on the FluentCart checkout
> (standard, modal **and** block — they all run FluentCart's own checkout-form flow), and
> that when capture is unavailable the withdrawal button stays (fail-safe).
>
> Sibling checklists: `wwu-wb-woocommerce-block-consent-CHECKLIST.md`, `wwu-wb-edd-consent-CHECKLIST.md`.

## What this tests

Implemented in `src/Frontend/FluentCartCheckoutConsent.php`, using hooks confirmed by the
FluentCart team (2026-06-15, see `docs/analysis/wwu-wb-fluentcart-hooks-ANALYSIS.md`):

- **render** → `fluent_cart/before_payment_methods` (fires in standard, modal **and** block);
- **validate** → `fluent_cart/checkout/validate_before_process` (blocks with a `WP_Error`);
- **capture** → `fluent_cart/checkout/prepare_other_data` (authoritative, reads the just-created order).

Exemptions are matched by **product id _and_ category** (`product-categories` taxonomy).

## Preconditions

- [ ] **FluentCart** active; WWU Withdrawal Button active with FluentCart detected
  (Withdrawal Button → Dashboard should list FluentCart as a platform).
- [ ] A **conditional-exemption** FluentCart product:
  - **Digital, immediate access** (reason `59_o`), or **service performed at once** (`59_a`).
- [ ] (For the category test) the product belongs to a **product category**
  (`product-categories`).

## Setup (one-time)

1. [ ] **WP-Admin → Withdrawal Button → Settings → Exemptions**.
2. [ ] Tag your FluentCart test product with `59_o` (digital) or `59_a` (service) **by product**.
   Save. (Use the **"what the consumer sees"** preview to see the exact wording.)
3. [ ] (Optional, recommended) Have a **non-exempt** product ready to confirm it never shows a box.

## Test A — consent is required and captured (happy path)

1. [ ] As a shopper, add the **exempt** FluentCart product and open the **checkout**.
2. [ ] **Expected:** a **required acknowledgement checkbox** with the statutory wording appears
   **above the payment methods** (the `before_payment_methods` slot).
3. [ ] **Without ticking**, try to complete the order. **Expected:** checkout is **blocked**
   with a message naming the item type (the box is also `required` in the markup; the real gate
   is the server-side `validate_before_process`).
4. [ ] Tick the box and complete the order. **Expected:** order completes.

### Verify the capture (admin)

5. [ ] **Withdrawal Button → Consent records**: a new PII-free row for this order (product id,
   reason, date, wording hash). This is the **authoritative** consent record.
6. [ ] The **consumer received the durable-medium e-mail** (subject ends with *"confirmation of
   your right of withdrawal"*). Its dispatch is logged as a separate
   `exemption_confirmation_sent` event.

> Note: the FluentCart **order activity-timeline** note (*"Withdrawal evidence recorded"*, written via
> `fluent_cart_add_log()` in alpha.34) is **not** written at checkout — it appears **later, when you
> actually process a withdrawal request** on the order. To see it, run a withdrawal on this order from
> **Withdrawal Button → Requests** and re-open the order in FluentCart. (Consent capture itself is
> recorded in Consent records + the order's plugin meta + the durable-medium e-mail, as above.)

### Verify the effect on the button

8. [ ] In the FluentCart **customer portal** order view (and/or the plugin's public withdrawal
   form for that order), the **withdrawal button is hidden for the exempt item**; non-exempt
   items keep it.

## Test B — category-aware (new in alpha.34)

1. [ ] In **Settings → Exemptions**, remove the per-**product** tag and instead tag the product's
   **category** with the same reason. Save.
2. [ ] Add that product to the cart → open checkout. **Expected:** the checkbox **still appears**
   (the category match resolves via the `product-categories` taxonomy), and capture works exactly
   as in Test A.

## Test C — fail-safe (button must stay)

- [ ] **Non-exempt product only:** no checkbox, order completes, withdrawal button **stays**.
- [ ] **Mixed cart:** checkbox only for the exempt reason; after purchase the button is hidden
  for the exempt item, kept for the other.
- [ ] **Cart not readable / checkout variant without the hook:** no checkbox is shown and checkout
  is **not** blocked — the consent simply isn't captured and the button **stays** (by design there
  is no path where the button is wrongly hidden).

## Pass criteria

- [ ] Checkbox appears (above payment methods) only when a conditional-exempt item is in the cart.
- [ ] Checkout is blocked until ticked.
- [ ] After purchase: Consent records row **+** durable-medium e-mail (the FluentCart activity-log
  note is separate — it appears later, at withdrawal processing; see the note under Test A).
- [ ] Category-tagged exemption matches as well as product-tagged (Test B).
- [ ] Button hidden for the exempt item, kept otherwise; fail-safe holds in every "no capture" case.

## If something is off

- **No checkbox** → confirm the product (or its category) is tagged in Settings → Exemptions, and
  that you're on a FluentCart checkout. Use `?wwu_wb_diag=1` (as admin) to print the resolved
  reason for the order/items.
- **Box ticked but no Consent record** → capture runs on `prepare_other_data` reading the order's
  items; confirm no other plugin aborts that hook. The button still stays (fail-safe) — report it.
- **No activity-log note after processing a withdrawal** → `fluent_cart_add_log()` may be absent on
  your FluentCart build; the plugin then falls back to the order's `addNote()` and finally to per-order
  meta. The evidence is still recorded regardless of where the note lands. (Remember: this note is
  written at *withdrawal processing*, not at *consent capture* — see the note under Test A.)
