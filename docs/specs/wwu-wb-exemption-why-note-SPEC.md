# SPEC — Consumer "why exempt" transparency note

- **Slug:** wwu-wb · **Target version:** 1.0.0-alpha.43 · **Status:** In implementation (2026-06-16)
- **Task:** #43 — when the withdrawal button is absent because the order is **exempt under Art. 59**, tell the
  consumer **why** (transparency), instead of silently showing nothing.

## Goal & non-goals
- **Goal:** a short, accurate, consumer-facing note on the order surfaces — shown ONLY when the right is
  genuinely removed by a captured Art. 59 exemption — naming the matched exception(s) + legal ref, noting the
  consumer's prior express consent. Merchant-overridable copy, i18n, fail-safe.
- **Non-goals:** no change to the exemption capture/evidence flow (checkout consent + durable-medium email
  already exist); no new admin reporting; the button-visibility logic is unchanged.

## Gate (hard fail-safe)
Render the note **only** when, from `Services::instance()->applicability->decide($order)`:
1. `$decision->show === false` **AND**
2. `$decision->reason === 'no_withdrawal_right'` (the ONLY reason meaning "Art. 59 exemption"; never for
   `out_of_scope` / `switzerland_voluntary` / `out_of_list` / `renewal_order` / `b2b_vat` / `ineligible_status`),
   **AND**
3. re-resolving the per-item reasons yields **at least one** matched Art. 59 reason id. If the reason list is
   empty (e.g. the order reached `no_withdrawal_right` only via the legacy `auto_detect_virtual` heuristic with
   no tagged product) → render **nothing**. Skip seal-based reasons (`59_e`/`59_i`) — they never legitimately
   hide the button.

## Architecture
- **New** `src/Frontend/ExemptionNoteRenderer.php` — `render( NormalizedOrder $order ): string`:
  - Collect distinct matched reason ids via `ExemptionResolver::reason_for_item()` over `$order->items`
    (dedupe; drop null + `59_e`/`59_i`). Empty → return `''`.
  - For each id, fetch `ExceptionTypes::get($id)` → `label` + `legal_ref`.
  - Copy: if `Settings::main()['custom_exemption_note']` is non-empty → render it (`wp_kses_post`); else the
    i18n default naming the reason(s) + legal ref + the prior-consent note.
  - Wrap in `apply_filters( 'wwu_wb_exemption_note_text', $html, $reason_ids, $order )`.
  - Output escaped; markup scoped `.wwu-wb-exempt-note`.
- **Default copy (IT/EN base, i18n):** "The right of withdrawal does not apply to this order: every item
  falls under a statutory exception to the 14-day right (**{labels}** — {legal_ref}), which you expressly
  agreed to at checkout." (accurate, transparent, non-alarming).

## Surfaces (call the gate + helper)
| Surface | File:method | Behaviour |
|---|---|---|
| `[wwu_wb_form]` + Gutenberg block | `Shortcodes::form()` (~109) | **replace** the generic "not available" notice with the specific note when the gate matches; keep generic for other reasons |
| Woo My Account order detail | `WooMyAccount::order_detail_button()` (~171) | render the note instead of the silent `return;` |
| FluentCart portal | `FluentCartPortal::inject()` (~223) | inject note into `$sections['after_summary']` |
| EDD customer surfaces | `EddCustomerOrders::button_for()` (~172) | return the note instead of `''` |

**Left silent (deliberate):** the My-Account **orders list** action row (`orders_list_action`, layout-sensitive
small links) and the bare `[wwu_wb_button]` (`Shortcodes::button`, minimal embed). The chooser tables
(`EligibleOrders`) keep skipping exempt orders — out of scope here.

## Editable copy (mirror `custom_guidance`)
- New `wwu_wb_settings['custom_exemption_note']` (string, `wp_kses_post`). Seeded empty in `Install` (→ uses
  i18n default). Admin `<textarea>` added to `SettingsPage::render_guidance_section()` with help text + a
  collapsible example. Saved in `handle_save()` via `wp_kses_post( wp_unslash( … ) )`.

## i18n / tests / docs
- New strings wrapped `__()` / `esc_html__()` (domain `wwu-withdrawal-button`); `.pot` regen + IT/DE/FR/ES/SV.
- Smoke (`SmokeTests`): renderer returns `''` for an order with no matched exemption reasons (fail-safe);
  returns a note naming the reason for an order whose items carry a `59_o`/`59_a` reason; custom override wins.
- Marketing pages (`_internal/marketing/landing.html` + `docs.html`) + CHANGELOG/readme/MASTER updated.

## Acceptance
A physical-goods order → button shows (note never appears). An exempt digital-immediate-access order with
captured consent → no button, the note appears naming "Digital content with immediate access (Art. 16(1)(m)
CRD / Art. 59(1)(o) CdC)". An out-of-scope (non-EU) order → no button, **no exemption note**. Empty/uncertain
exemption → no note (fail-safe). The button is never hidden by this change.
