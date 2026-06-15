# Live test — **Easy Digital Downloads (EDD)** Checkout consent capture

> Manual test checklist. Anyone can run it on a staging store. ~15 min.
> Goal: prove that the Art. 59 **exemption consent** is captured on the EDD checkout, and
> that when capture is unavailable the withdrawal button stays (fail-safe).
>
> Sibling checklists: `wwu-wb-woocommerce-block-consent-CHECKLIST.md`, `wwu-wb-fluentcart-consent-CHECKLIST.md`.

## What this tests

Implemented in `src/Frontend/EddCheckoutConsent.php` + `src/Platform/EddAdapter.php`
(EDD **3.0+**, custom-tables order API — see `docs/specs/wwu-wb-edd-integration-SPEC.md`):

- **render** → `edd_purchase_form_before_submit`;
- **validate** → `edd_checkout_error_checks` + `edd_set_error` (blocks the purchase);
- **capture** → `edd_built_order` (authoritative, reads the just-built order).

EDD sells digital downloads, so the relevant reason is almost always **`59_o`** (digital
content, immediate access). Exemptions match by **download id _and_ category**
(`download_category` taxonomy).

## Preconditions

- [ ] **Easy Digital Downloads ≥ 3.0** active; WWU Withdrawal Button active with EDD detected
  (Withdrawal Button → Dashboard should list EDD as a platform).
- [ ] At least one **download** to tag as a conditional exemption.
- [ ] (For the category test) the download is in a **download category** (`download_category`).

## Setup (one-time)

1. [ ] **WP-Admin → Withdrawal Button → Settings → Exemptions**.
2. [ ] Tag your test **download** with `59_o` (digital, immediate access) **by product**. Save.
   (Use the **"what the consumer sees"** preview to see the exact wording.)
3. [ ] (Optional, recommended) Have a **non-exempt** download ready to confirm it never shows a box.

## Test A — consent is required and captured (happy path)

1. [ ] As a shopper, add the **exempt** download to the cart and open the **EDD checkout**.
2. [ ] **Expected:** a **required acknowledgement checkbox** with the statutory wording appears
   **before the Purchase/submit button** (the `edd_purchase_form_before_submit` slot).
3. [ ] **Without ticking**, submit the purchase. **Expected:** EDD shows a checkout **error**
   (via `edd_set_error`) and does **not** complete.
4. [ ] Tick the box and complete the purchase. **Expected:** purchase completes.

### Verify the capture (admin)

5. [ ] **Withdrawal Button → Consent records**: a new PII-free row (download id, reason `59_o`,
   date, wording hash).
6. [ ] **Downloads → Orders → the order** has the plugin's EDD order meta (`edd_*_order_meta`,
   keys prefixed `wwu_wb_`).
7. [ ] The **consumer received the durable-medium e-mail** (subject ends with *"confirmation of
   your right of withdrawal"*). Its dispatch is logged as a separate `exemption_confirmation_sent`
   event.

### Verify the effect on the button

8. [ ] On the consumer's order view (and/or the plugin's public withdrawal form for that order),
   the **withdrawal button is hidden for the exempt download**; any non-exempt item keeps it.

## Test B — category-aware

1. [ ] In **Settings → Exemptions**, replace the per-**download** tag with a tag on the download's
   **category**. Save.
2. [ ] Buy that download again → the checkbox **still appears** (category match via
   `download_category`), and capture works as in Test A.

## Test C — fail-safe (button must stay)

- [ ] **Non-exempt download only:** no checkbox, purchase completes, withdrawal button **stays**.
- [ ] **Mixed cart:** checkbox only for the exempt reason; after purchase the button is hidden for
  the exempt download, kept for the other.
- [ ] **EDD < 3.0 or order API unavailable:** the adapter reports inactive and no checkbox is shown;
  the withdrawal button **stays** (no silent loss of the right).

## Pass criteria

- [ ] Checkbox appears (before submit) only when a conditional-exempt download is in the cart.
- [ ] Purchase is blocked until ticked.
- [ ] After purchase: Consent records row **+** EDD order meta **+** durable-medium e-mail.
- [ ] Category-tagged exemption matches as well as download-tagged (Test B).
- [ ] Button hidden for the exempt download, kept otherwise; fail-safe holds in every "no capture" case.

## If something is off

- **No checkbox** → confirm EDD ≥ 3.0, and that the download (or its category) is tagged in
  Settings → Exemptions. Use `?wwu_wb_diag=1` (as admin) to print the resolved reason.
- **Box ticked but no Consent record** → capture runs on `edd_built_order`; confirm no other plugin
  short-circuits the order build. The button still stays (fail-safe) — report it.
- **No e-mail** → check EDD's own mail settings / a transactional-mail plugin; the consent is still
  captured and logged regardless.
