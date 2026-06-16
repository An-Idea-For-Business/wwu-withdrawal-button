# SPEC — Optional "which products" field in the withdrawal form (partial withdrawal)

- **Slug:** wwu-wb
- **Target version:** 1.0.0-alpha.42 (stacks on alpha.41)
- **Status:** In implementation (2026-06-16)
- **Trigger:** A user (Marco Parrilla, FB) asked whether a consumer can withdraw from only *some* products of an
  order. Legally **yes** — the EU model withdrawal form (Annex I-B / Allegato I-B) declares withdrawal "**dei
  seguenti beni**" (the consumer lists the goods); it is not all-or-nothing. This adds an **optional** way for
  the consumer to indicate *which* products, surfaced to the merchant.

## Scope & non-goals
- **Informational only.** The plugin already never computes refund amounts — the merchant processes the (full
  or partial) refund manually. This field **records + displays** the consumer's selection; it does not drive a
  partial-refund calculation, return labels, or stock adjustments.
- **Optional + fail-open (hard requirement).** Empty/absent selection = withdrawal from the **whole order**,
  exactly as today. The field is NEVER a validation gate — the statutory right must work with it untouched.

## Design — mirror the existing `reason` field
`reason` is the precedent (optional free-text, sanitized + capped, flows into `statement.reason`, shown
conditionally in receipt/PDF). The new `products` field is an **array of strings** following the same pipeline.

### UI (form step 1) — `templates/form/withdrawal-form.php`
- Insert between the `reason` textarea and the actions `<p>`.
- Render ONLY when `items` (the order's line items) is non-empty; otherwise omit entirely (whole-order, as today).
- A fieldset of checkboxes `name="products[]"`, value = the item **name** (sanitized), label = item name with a
  muted `× {qty}` context. **All unchecked by default.**
- Heading + helper (i18n): "**Withdrawing from only some products?** Tick them — leave empty to withdraw from
  the whole order." Escaped output (`esc_html`, `esc_attr`).
- The same template feeds the shortcode, the Gutenberg block, and the WooMyAccount endpoint, so the three
  callers (`Shortcodes::form`, `WooMyAccount::render_form`, `Blocks` via Shortcodes) must pass `items` from
  `$order->items`.

### JS — `assets/frontend/withdrawal.js` `readFields()`
- Collect checked `products[]` values into an array and add to the JSON body. No other change.

### Domain — `src/Domain/WithdrawalRequest.php`
- New `public array $products`. In `from_input()`: accept an array, `sanitize_text_field()` each element, cap
  each to ~200 chars and the array to **50** items (DoS guard), drop empties, re-index.
- Add to `to_array()` under `products` → lands at `statement.products` in the evidence payload.
- **NOT** added to `is_valid()`. No new gate anywhere.

### Submission — REST + No-JS
- `REST\Routes\WithdrawalRoute::statement()` + `confirm()`: read the `products` param (array) and pass it
  through `WithdrawalRequest::from_input()` (mirror `reason`).
- `Frontend\NoScriptFlow::handle_statement()` + `handle_confirm()`: read `$_POST['products']` (array,
  `wp_unslash`), and re-emit each as `<input type="hidden" name="products[]">` in the step-2 form.

### Evidence log — no structural change
- `statement.products` rides inside the existing `payload.statement` object. `LogChain::canonicalize()` is
  `ksort`-recursive (key-order-independent) and per-row; adding a key only changes **new** rows' hashes.
  Existing rows stay self-consistent (verified against their stored `payload_json`). Non-breaking.

### Durable medium — `src/DurableMedium/ReceiptBuilder::data()`
- Add `products_selected` = a human string of the selected names (e.g. "Blue Shirt, Mug"), or '' when empty.
- Email templates (`templates/emails/withdrawal-confirmation.php` + `templates/emails/wwu-wb-withdrawal-ack.php`)
  and PDF (`templates/pdf/receipt-pdf.php`): add a **conditional** row "Products withdrawn: …" shown only when
  `products_selected !== ''` (mirror the `reason` guard). When empty, nothing changes (whole-order receipt).

### Admin — `src/Admin/RequestsDashboard::render()`
- New "Products" column after "Country": header `<th>` + row `<td>` reading `$payload['statement']['products']`
  (array → comma-joined, escaped). Empty → an em-dash / "Whole order".

### i18n
- Text domain `wwu-withdrawal-button`. New strings wrapped `esc_html__()` / `__()`. Regenerate `.pot`, translate
  the new strings into IT/DE/FR/ES/SV, recompile `.mo` (keep all 6 locales 100%).

## Verification
- `php -l` (all touched) + class scanner + PHPStan level 2 clean.
- Smoke: `WithdrawalRequest` products sanitize + cap + `to_array` shape; empty-products → whole-order path.
- Manual: a partial selection appears in the dashboard + receipt; an empty selection behaves exactly as today.

## Acceptance (fail-open gate)
A withdrawal submitted with **no** product selected must succeed and be recorded as a whole-order withdrawal,
with zero behavioural change vs alpha.41. The field never appears as required and never rejects a submission.
