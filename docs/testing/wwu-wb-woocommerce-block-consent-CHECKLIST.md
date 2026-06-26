# Live test — WooCommerce **block** Checkout consent capture

> Manual test checklist. Anyone can run it on a staging store. ~15 min.
> Goal: prove that the Art. 59 **exemption consent** is captured on the WooCommerce
> **block** Checkout (the Checkout *block*, not the classic shortcode), and that when
> capture is unavailable the withdrawal button stays (fail-safe).
>
> Sibling checklists: `wwu-wb-fluentcart-consent-CHECKLIST.md`, `wwu-wb-edd-consent-CHECKLIST.md`.

## What this tests

The block Checkout cannot render arbitrary PHP, so the consent checkbox is added
through WooCommerce's official **Additional Checkout Fields API**
(`woocommerce_register_additional_checkout_field`, **WooCommerce 9.9+**), scoped to the
cart, and captured server-side on `woocommerce_store_api_checkout_order_processed`.
Implemented in `src/Frontend/WooBlockCheckoutConsent.php`.

## Preconditions

- [ ] **WooCommerce ≥ 9.9** active (older versions: the field API does not exist → the
  plugin is a no-op here and the button simply stays — that is the WC<9.9 fail-safe row below).
- [ ] The store's **Checkout page uses the Checkout _block_** (Gutenberg block), *not* the
  classic `[woocommerce_checkout]` shortcode. (Classic checkout is covered by
  `WooCheckoutConsent` — use the classic flow, not this checklist.)
- [ ] WWU Withdrawal Button active; at least one platform = WooCommerce.
- [ ] A **conditional-exemption** product exists, i.e. something whose right of withdrawal
  is lost only after the consumer asks to start immediately:
  - **Digital, immediate access** (reason `59_o`) — e.g. a downloadable/virtual product, or
  - **Service, performed at once** (reason `59_a`).

## Setup (one-time)

1. [ ] Go to **WP-Admin → Withdrawal Button → Settings → Exemptions**.
2. [ ] Under the **conditional** group, tag your test product **by product** (or by its
   product category) with `59_o` (digital) or `59_a` (service). Save.
   - Tip: the **"what the consumer sees"** preview on that page shows the exact statutory
     acknowledgement wording that will appear at checkout.
3. [ ] (Optional, recommended) Have a **second, non-exempt** product (e.g. a physical item)
   ready, to confirm it never shows a checkbox.

## Test A — consent is required and captured (happy path)

1. [ ] As a shopper (logged-out is fine), add the **exempt** product to the cart.
2. [ ] Open the **block Checkout**.
3. [ ] **Expected:** a **required acknowledgement checkbox** appears with the statutory
   wording (e.g. *"Richiedo l'accesso immediato a questo contenuto digitale e riconosco
   che, una volta avviato il download…, perdo il mio diritto di recesso."*).
4. [ ] **Without ticking it**, try to place the order.
   **Expected:** checkout is **blocked** with a validation message naming the item type.
5. [ ] Tick the box, place the order. **Expected:** order completes normally.

### Verify the capture (admin)

6. [ ] **Withdrawal Button → Consent records**: a new row for this order — product id,
   reason (`59_o`/`59_a`), date/time, and a wording **hash** (the row is PII-free; the IP,
   if captured, lives only on the order meta, not here).
7. [ ] Open the **order** in WooCommerce → the order has the plugin's consent meta
   (stored under `_wc_other/...` by the Store API).
8. [ ] The **consumer received the durable-medium e-mail** — subject ends with
   *"confirmation of your right of withdrawal"* — reproducing the exact wording accepted.
   (This e-mail is *constitutive* for the digital exemption, so its delivery is logged as a
   separate `exemption_confirmation_sent` event — visible in Debug Inspector / the log.)

### Verify the effect on the button

9. [ ] On the consumer's **order view** (or the plugin's public withdrawal form for that
   order), the **withdrawal button is _hidden_ for the exempt item**, with the "why exempt"
   explanation. Any **non-exempt** item in the same order keeps its button.

## Test B — fail-safe (button must stay)

Run at least the first; the rest are quick confirmations.

- [ ] **Non-exempt product only:** add only the physical/non-tagged product → checkout shows
  **no** consent checkbox, order completes, and the withdrawal button **stays** on that order.
- [ ] **Mixed cart:** exempt + non-exempt together → checkbox shown only for the exempt
  reason; after purchase the button is hidden for the exempt item, kept for the other.
- [ ] **WooCommerce < 9.9** (if you can test it): no checkbox is rendered (API absent) and
  the withdrawal button **stays** — the consumer never loses the right by accident.

## Pass criteria

- [ ] Checkbox shows only when a conditional-exempt item is in the cart.
- [ ] Checkout is blocked until the box is ticked.
- [ ] After purchase: Consent records row **+** order meta **+** durable-medium e-mail all present.
- [ ] Button hidden for the exempt item, kept for everything else.
- [ ] In every "capture unavailable" case the button **stays** (no silent loss of the right).

## If something is off

- **No checkbox at all** → confirm WC ≥ 9.9, that the page is the Checkout **block**, and that
  the product (or its category) is actually tagged in Settings → Exemptions. Use
  `?webwakeupwdb_diag=1` (as admin) on the order/form to print the resolved exemption reason.
- **Checkbox shows but order isn't blocked** → check the browser console / WC logs; the field
  is registered `required`, so this would indicate a theme/Store-API conflict — capture it.
- **Box ticked but no Consent record** → the authoritative capture runs on
  `woocommerce_store_api_checkout_order_processed`; verify no other plugin short-circuits it.
  The button still stays (fail-safe), so the consumer is never harmed — but report it.
