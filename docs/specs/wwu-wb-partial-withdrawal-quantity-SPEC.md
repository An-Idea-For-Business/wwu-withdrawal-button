# SPEC — Partial-withdrawal per-item quantity

> Extends the existing **partial withdrawal — product selection** feature (`wwu-wb-partial-withdrawal-products-SPEC.md`, shipped alpha.42). Adds an optional **quantity per selected line** so a consumer can declare "I withdraw from 1 of the 3 T-shirts I bought", not just "this product".
>
> Origin: GitHub issue [#47](https://github.com/An-Idea-For-Business/wwu-withdrawal-button/issues/47) (tatofree-code). Target version **`1.2.13`** (additive, back-compat enhancement — `1.3.0` is reserved for the Legal Documents feature). Status: **DRAFT — awaiting confirmation before code.**

## 1. Overview

Today the withdrawal form's step 1 renders one checkbox per order line (`name="products[]" value="<item name>"`); the form even shows the ordered quantity ("× 3") but ticking a line means the **whole** line. This adds an optional, always-visible **number input per line** (1 … ordered qty, default = full qty) so the consumer can declare a partial quantity. The chosen quantities flow — additively, without changing the existing `statement.products` shape — into the durable-medium receipt, the tamper-evident log, the admin Requests dashboard and the public REST API.

Like the rest of partial withdrawal, this is an **informational declaration**: it records what the consumer asked for. The plugin never moves money or stock — the merchant processes the refund/return manually.

## 2. Goals & Non-Goals

**Goals**
- Let the consumer pick a quantity (1 … ordered qty) for each line they withdraw from.
- Always usable **without JavaScript** (legal invariant — the withdrawal flow must work no-JS).
- **Zero break** to the existing `statement.products` contract (flat name array) consumed by the immutable log (frozen history), the public REST API, the receipt and the dashboard.
- Default fail-open: a ticked line with the number left at its default = the whole line, exactly like today.
- Surface the quantity on the receipt (email + PDF), the Requests dashboard and the REST API.

**Non-Goals**
- No enforcement against stock/refund amount (informational only — unchanged philosophy).
- No per-variation / per-bundle modelling beyond what order line items already give (WooCommerce variations are already separate lines).
- No change to the refund/return mechanics (still manual).
- No new persistence table; reuses the existing statement + log payload.

## 3. User Stories

- *As a consumer* who bought 3 of an item and wants to return 1, I tick the line and set the quantity to 1, then confirm — the receipt and the merchant's record both say "× 1 (of 3)".
- *As a consumer without JavaScript*, I still see the number field next to each line and can set it.
- *As a merchant*, the Requests dashboard "Products" column shows the declared quantity per line so I refund the right amount.
- *As an API consumer* (existing integration), my code that reads `products` keeps working unchanged; if I want quantities I read the new additive `product_quantities` field.

## 4. Architecture

Single data path, all additive. Grounded in the recon (file:line in §References):

1. **Form (`templates/form/withdrawal-form.php`)** — for each `$item` (which already carries `$item['qty']`), keep the `products[]` checkbox unchanged and add a sibling `<input type="number" name="product_qty[<item name>]" min="1" max="<ordered qty>" value="<ordered qty>" step="1">`, always visible. No-JS safe.
2. **No-JS flow (`src/Frontend/NoScriptFlow.php`)** — mirror the same field if/where it renders the product list.
3. **REST intake (`src/REST/Routes/WithdrawalRoute.php:188,228`)** — additionally read the `product_qty` param and pass it into `from_input()` alongside `products`.
4. **Domain model (`src/Domain/WithdrawalRequest.php`)** — add `public array $product_quantities = array();` (map `name => int`), sanitised in `from_input()`, emitted by `to_array()` as `statement.product_quantities`. `statement.products` is **unchanged**.
5. **Receipt (`src/DurableMedium/ReceiptBuilder.php:51`)** — render "Name × qty" instead of just "Name" when a quantity is present.
6. **Dashboard (`src/Admin/RequestsDashboard.php:142-143`)** — same "Name × qty" rendering in the Products column.
7. **Public REST API (`src/Api/RequestReader.php:121-129`)** — add `product_quantities` to the response (additive; `products` key unchanged).
8. **Smoke tests (`src/Debug/SmokeTests.php`)** — extend the partial-withdrawal suite.

## 5. Data Model

The statement gains ONE additive field; the frozen `products` shape is untouched.

```
statement = {
  name, order_ref, email, reason,
  products:            string[]            // UNCHANGED — selected item names (frozen log + REST contract)
  product_quantities:  { <item name>: int } // NEW, additive — chosen qty per selected line, default = ordered qty
}
```

- `product_quantities` is keyed by item **name** (matching `products[]` values). Recorded for each selected product; default = the ordered quantity (so "untouched" = whole line).
- Sanitisation in `from_input()`: iterate the submitted `product_qty` map, cap at 50 keys (mirrors the `products` 50-item DoS cap), `(int)` each value, clamp to `>= 1` and a sane upper cap (e.g. 10000); keep only keys that also appear in the sanitised `products` list (drop orphans).
- **Empty-map note (CLAUDE.md trap #5):** an empty `product_quantities` PHP `array()` serialises to JSON `[]`, not `{}`. Harmless here (every consumer reads it back as an array and treats absent/empty as "full quantity"), but documented. When `products` is empty (whole-order withdrawal), `product_quantities` is empty too.
- **Back-compat:** old log rows (no `product_quantities`) read back as "all full" — the renderers fall back to the plain name. The REST API adds a key; it removes/renames nothing.

## 6. API / Interfaces

- **Form fields:** `products[]` (unchanged) + `product_qty[<name>]` (new, per line).
- **REST intake** (`WithdrawalRoute`): accept `product_qty` (object/map) on the confirm call; thread into `from_input()`.
- **Value object** (`WithdrawalRequest`): new public `$product_quantities`; serialised in `to_array()`.
- **Public read API** (`RequestReader`): response gains `product_quantities` (map name→int), additive. Existing `products` array unchanged. (Document in `docs/reference/wwu-wb-rest-api-REFERENCE.md`.)
- **Hooks:** no new hook required for MVP; the existing statement filters already see the richer statement. (Optional future filter `wwu_wb_statement_product_quantities` — Open Question.)

## 7. UI / UX

- Per line: `[✓] Product name  [ 1 ] of 3` — checkbox (existing) + number input (new, default = ordered qty) + an "of N" hint. Label the number input for a11y (`aria-label="Quantity to withdraw for <name>"`).
- Always visible (no-JS). With JS (progressive enhancement only, optional): could disable the number when the line is unchecked — but the server already ignores quantities for unchecked lines, so JS is not required.
- For lines with ordered qty = 1, render the number input as `min=1 max=1 value=1` (or omit it and just show the checkbox — Open Question; simplest is to show it disabled/readonly at 1).
- Receipt / dashboard rendering: "Product name × 2" (omit "× N" when qty equals the full line, to stay clean — or always show it; decide in §Open Questions).
- i18n: new strings (`Quantity`, `of %d`, the aria-label) via `__()`, added to the `.pot` and the 6 locales.
- Compliance: the quantity must not become a dark pattern — the field is optional and defaults to the full quantity, so doing nothing still withdraws the whole line (no friction added to exercising the right).

## 8. Edge Cases

- **Unchecked line with a quantity typed** → ignored server-side (only quantities for selected `products` are kept).
- **Quantity 0 / negative / empty** → clamped to the full ordered qty (fail-open toward the consumer's right), or to 1; spec: empty/invalid ⇒ treat as full line.
- **Quantity > ordered qty** (e.g. "5 of 3") → the form's `max` bounds it; server caps to the upper sanity limit but does **not** hard-reject (informational; the merchant verifies). Optionally clamp to ordered qty at confirm time when the order is in hand (Open Question — adds order-refetch coupling).
- **Duplicate item names** (same product on two lines) → the name-keyed map merges them (pre-existing limitation of name-based selection, not introduced here). Documented; merchant sees the order anyway.
- **No-JS submit** → number inputs are plain form fields, read server-side regardless of JS.
- **Old log rows / old API clients** → no `product_quantities` ⇒ rendered as full lines; no break.
- **Whole-order withdrawal** (no lines ticked) → `products` empty, `product_quantities` empty; behaviour identical to today.

## 9. Security

- Server-side sanitisation in `from_input()` (never trust the form): `(int)` cast, clamp `>= 1`, upper cap, 50-key cap, drop keys not in `products`. Mirrors the existing `products` DoS guards.
- The quantity is character/integer data on a `<textarea>`-free form; escaped on output (`esc_html`) in the receipt/dashboard. No new injection surface.
- No new capability or endpoint; rides the existing public withdrawal endpoint (nonce + per-IP rate limit + order-ref/email verification unchanged).
- The immutable log's hash chain commits to the full statement; adding a field is covered by the existing serialisation (no schema/migration change).

## 10. Performance

- Negligible: a handful of integer fields per order line, sanitised once at submit. No new query (the ordered qty is already in the normalised order the form is built from). No change to the hot path.

## 11. Testing Strategy

- Extend the partial-withdrawal smoke suite (`src/Debug/SmokeTests.php`, near the existing `products => ['Widget A','Widget B']` fixture):
  - `from_input` keeps `products` shape unchanged (back-compat assertion).
  - `product_quantities` sanitises: int cast, clamp ≥1, upper cap, 50-key cap, orphan keys (not in `products`) dropped.
  - default-full: a selected product with no quantity entry ⇒ rendered/recorded as the full line.
  - `to_array()` emits `statement.product_quantities`; `RequestReader` exposes it additively while `products` stays a string array.
- Manual: WooCommerce order with qty 3 → withdraw 1 → check the receipt (email + PDF), the dashboard column, the REST API response, and the no-JS flow.
- Lint: PHP 0 errors; build both zips (8.1 + `--php74`) and confirm the form renders.

## 12. Open Questions

1. **Display when qty = full line:** always show "× N", or omit "× N" when the consumer withdraws the whole line? (Lean: show "× N" only when N < ordered, else just the name — cleaner.)
2. **Clamp to ordered qty server-side at confirm** (order in hand) vs rely on the form `max` + sanity cap? (Lean: form `max` + sanity cap; informational, no hard reject.)
3. **Lines with ordered qty = 1:** show a disabled `1`, or hide the number entirely? (Lean: hide the number for qty-1 lines — less clutter.)
4. **Version:** `1.2.13` (treat as a focused enhancement) vs a `1.3.x` minor. (Lean: `1.2.13`, additive + back-compat; `1.3.0` stays the Legal Documents slot.)
5. Optional `wwu_wb_statement_product_quantities` filter for developers — ship now or defer? (Lean: defer until asked.)

## References

Local code recon (2026-06-25, by-hand after the sub-agent hit autocompact-thrash on a large file):

- Form: `templates/form/withdrawal-form.php:72-85` — `products[]` checkbox per line; `$item['qty']` already available for the "× N" hint.
- Domain model: `src/Domain/WithdrawalRequest.php:34` (`public array $products`), `:57-78` (`from_input` sanitises `products`: 50 × 200-char caps), `:114` (`to_array` emits `products`).
- REST intake: `src/REST/Routes/WithdrawalRoute.php:188,228` (`'products' => $request->get_param('products')`).
- Receipt: `src/DurableMedium/ReceiptBuilder.php:51` (`'products_selected' => implode(', ', $req->products)`).
- Dashboard: `src/Admin/RequestsDashboard.php:142-143` (`$payload['statement']['products']` → `implode`).
- Public REST API: `src/Api/RequestReader.php:121-129` (exposes `products` as `string[]`).
- Ordered quantity per item (the bound for the input): `src/Platform/WooCommerceAdapter.php:290` (`'qty' => (int) $item->get_quantity()`), `FluentCartAdapter.php:623` (`'qty' => (int) (quantity ?? 1)`), `EddAdapter.php:415` (same). Confirms every normalised order item carries `qty`.
- Smoke fixture: `src/Debug/SmokeTests.php:999` (`'products' => array('Widget A','Widget B')`) — confirms the flat-string-array shape.
- Disambiguation: the `by_reason[...]['products']` matches (`WooBlockCheckoutConsent`, `ExemptionResolver`, SmokeTests 625-713) are the **Art. 59 exemption** product-ID config — a different `products`, not the withdrawal statement.
