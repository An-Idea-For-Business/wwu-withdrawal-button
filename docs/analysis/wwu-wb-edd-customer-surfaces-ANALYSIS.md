# Analysis — EDD customer-facing withdrawal surfaces, verified against official source

> Created 2026-06-15 (build `1.0.0-alpha.35`). Records the source-verified EDD 3.x hooks used to
> render the statutory withdrawal button on the EDD customer's own pages (`EddCustomerOrders`), reaching
> full parity with WooCommerce (`WooMyAccount`) and FluentCart (`FluentCartPortal`). Authoritative source:
> the **`awesomemotive/easy-digital-downloads`** `main` branch (template + email class source, read via the
> GitHub API).

## Why this exists

EDD was first added (alpha.33) as a **data-adapter + checkout-consent** integration only — there was no
withdrawal button on EDD's own customer pages, unlike WooCommerce (3 surfaces) and FluentCart (4). That
left the *most important* surface — the statutory button the consumer actually clicks — missing on EDD.
This pass closes that gap. Per the standing rule (verify third-party hook contracts against official docs),
every hook was read from EDD source **before** coding — which caught that the obvious-looking legacy 2.x
hooks were **removed** from the EDD 3.x templates and would have produced a silent dead button.

## Verified hooks (EDD 3.x)

| Surface | Hook | Type | Args (EDD 3.x) | Template / source | EDD version |
|---|---|---|---|---|---|
| Purchase **receipt** (`[edd_receipt]`) | `edd_order_receipt_after_table` | action | `( EDD\Orders\Order $order, array $args )` | `templates/shortcode-receipt.php` (after the receipt table) | 3.x (3.1.1+) |
| Purchase **history** (`[purchase_history]`) | `edd_order_history_row_end` | action | `( EDD\Orders\Order $order )` | `templates/history-purchases.php` (end of each order row) | 3.x |
| Receipt **e-mail** body (modern) | `edd_order_receipt` | filter | `( string $body, EDD\Orders\Order $order )` | `src/Emails/Types/OrderReceipt.php` (`set_email_body_content()`) | 3.2.0+ |
| Receipt **e-mail** body (legacy) | `edd_purchase_receipt` | filter | `( string $message, int $payment_id, array $meta )` | back-compat shim (`LegacyPaymentFilters`) | 3.0–3.1 (still fires on 3.2+ if hooked) |

### Legacy 2.x hooks — DO NOT use (removed from 3.x templates)
These fired with an `EDD_Payment`/`$payment_id` and are **not present** in the current templates:
`edd_payment_receipt_after`, `edd_payment_receipt_after_table`, `edd_purchase_history_row_start/_end`.
Hooking them on an EDD 3.x store = a button that never renders (the exact silent-failure this analysis
prevented). The 2.x→3.x migration commit message reads *"Move payment_receipt hooks to deprecated functions,
add new hooks."*

## Implementation mapping (`src/Frontend/EddCustomerOrders.php`)

- **Registration:** `edd_order_receipt_after_table` (2 args) → `receipt_button()`;
  `edd_order_history_row_end` (1 arg) → `history_row_button()`; `edd_order_receipt` (2 args) →
  `email_link()`; `edd_purchase_receipt` (3 args) → `email_link_legacy()`.
- **HTML context (why `_after_table`, not `_after`):** `edd_order_receipt_after` fires *inside* the
  receipt `<table>` (after the product rows). A block `<div>` echoed there is foster-parented by the HTML
  parser *above* the table — wrong placement. `edd_order_receipt_after_table` fires after the table (block
  context), where the `<div>` button is valid. Likewise the purchase-history button is wrapped in a `<td>`
  because `edd_order_history_row_end` fires inside the `<tr>`.
- **E-mail double-append guard:** on EDD 3.2.0+ both `edd_order_receipt` and (via the `LegacyPaymentFilters`
  shim, when something is hooked to it) `edd_purchase_receipt` can fire. Both route to `append_email_link()`,
  which is **de-duplicated per order** (`$rendered['email:'.$order_ref]`) so the link is appended once.
- **URL:** EDD has no routable My Account endpoint, so the button links to the plugin's standalone public
  form page (`public_form_page_id`) pre-authenticated with `?wwu_wb_order=&key=<payment_key>` — identical to
  the WooCommerce order-email link (`OrderEmailLink`). The EDD payment key satisfies the adapter's
  `verify_guest_key()`.
- **Fail-safe:** every surface renders nothing when the order is ineligible (`ApplicabilityResolver::decide`),
  a request already exists (shows the localized status notice instead), or no public page is configured.
  Inline button styles (the EDD receipt/history pages don't load the plugin stylesheet).
- **Order id + payment key extraction:** tolerant of the EDD 3.0 `Order` object (`->id`, `->payment_key`)
  and a numeric id (then `edd_get_order()`), so the same callbacks survive minor EDD shape differences.

## Sources
- `https://github.com/awesomemotive/easy-digital-downloads/blob/main/templates/shortcode-receipt.php`
- `https://github.com/awesomemotive/easy-digital-downloads/blob/main/templates/history-purchases.php`
- `https://github.com/awesomemotive/easy-digital-downloads/blob/main/src/Emails/Types/OrderReceipt.php`
  (+ `src/Emails/Types/LegacyPaymentFilters.php`)
- `includes/emails/tags.php` (`edd_add_email_tag` — the email-tag alternative, not used here because we
  append automatically rather than relying on the merchant placing a `{tag}`).

## Alternative considered (not used)
**E-mail tag** (`edd_add_email_tags` + `edd_add_email_tag('withdrawal_info', …)`, EDD 3.0+): registers a
`{withdrawal_info}` placeholder the merchant can position in the e-mail template. Rejected for the default
path because it requires manual merchant action; the `edd_order_receipt` filter appends automatically
(out-of-the-box). The tag remains a sensible future opt-in for merchants who want inline placement.
