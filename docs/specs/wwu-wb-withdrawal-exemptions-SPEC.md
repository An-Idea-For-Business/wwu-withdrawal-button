# SPEC — Product/service withdrawal exemptions (Art. 59 / Art. 16)

Design only (no implementation yet). Answers the community question: *"Can I exempt
certain products/services — e.g. digital products with immediate access, or services
already performed?"* The answer is **yes, but only under strict legal conditions** —
so the right design is not "hide the button" but **capture the legal prerequisites
and exempt only when they are met**.

> Legal references verified by research (EUR-Lex, Codice del Consumo, CJEU). Not legal
> advice — the merchant remains responsible; this feature helps them do it correctly.

## 1. Overview

The online withdrawal button (Dir. 2023/2673 / Art. 54-bis CdC, from 19 June 2026)
changes the **procedure**, not the **substantive exceptions** to the right of
withdrawal. Those exceptions live in **Art. 16 Dir. 2011/83/EU** (as amended by Dir.
2019/2161) and **Art. 59 D.Lgs. 206/2005 (Codice del Consumo)**.

The plugin already has `ArticleFiftyNineEvaluator` (per-item, mixed-cart) reading a
`wwu_wb_exclusions` option (`excluded_product_ids`, `excluded_category_ids`,
`auto_detect_virtual`) plus a `wwu_wb_excluded_product_ids` filter. **Gaps:** (a) no
admin UI to configure exclusions; (b) it does not capture or verify the consent /
acknowledgement / confirmation prerequisites that make the two most-requested
exemptions *legally valid*; (c) exclusions are an opaque boolean, not an auditable
exception reason.

## 2. Goals & Non-Goals

**Goals**
- Admin UI to mark products / categories as exempt, by **specific Art. 59 reason**.
- Capture the **legal prerequisites** at checkout for the *conditional* exceptions
  (digital-immediate; service fully performed), store them as evidence, and reproduce
  the trader's required **confirmation** in the order email.
- Exempt an item **only** when the law actually allows it; otherwise keep the button
  (fail-safe toward the consumer's right).
- Make every exemption decision **auditable** (which item, which reason, what consent).

**Non-Goals**
- Changing the substantive exceptions (we implement the law, not redefine it).
- Auto-classifying ambiguous goods (seal-broken, custom-made) — these need the
  merchant's input; we provide the controls, not a guess.
- Computing refunds / proportional payments (handled by WooCommerce refunds).

## 3. Legal foundation (what the law actually says)

Directive 2023/2673 is **procedure-only** — it does not touch Art. 16/59.

**The two the user asked about are CONDITIONAL:**

- **Services fully performed** — Art. 16(1)(a) CRD / Art. 59(1)(a) CdC. No withdrawal
  *after the service is fully performed*, but only if: (1) performance began with the
  consumer's **prior express consent**, AND (2) the consumer **acknowledged losing the
  right** once fully performed. If only *partially* performed at withdrawal → the right
  applies and the consumer owes **proportional** payment (Art. 13(3)/Art. 57).
- **Digital content not on a tangible medium (immediate access)** — Art. 16(1)(m) /
  Art. 59(1)(o), with Art. 14(4)(b). No withdrawal once performance began, but only if
  **all three**: (1) **prior express consent** to begin within the 14-day period, (2)
  consumer **acknowledgement** of losing the right, AND (3) the trader's **confirmation**
  of (1)+(2) on a durable medium (Art. 8(7) distance / Art. 51(7) CdC). The 3rd element
  was added by Dir. 2019/2161. **If any condition fails: the withdrawal right SURVIVES
  and the consumer owes ZERO payment** for content already delivered (Art. 14(4)(b)).
  CJEU **C-641/19 (PE Digital)** — interpret strictly: not every digital output is
  "digital content" (a generated report can be a *service*).

**Unconditional exceptions** (no consent needed — exempt by nature): price linked to
financial-market fluctuations (b); custom-made / clearly personalised (c);
perishable / rapidly expiring (d); **sealed health/hygiene goods unsealed after
delivery** (e — only once the seal is broken); inseparably mixed after delivery (f);
alcoholic beverages with delayed market-linked delivery (g); urgent repairs requested
by the consumer (h); **sealed audio/video/software unsealed** (i); newspapers/periodicals
except subscriptions (j); public auctions (k); accommodation/transport/car-rental/
catering/leisure for a specific date or period (l).

**Italy specifics:** Art. 59(1-bis) CdC **disapplies** exceptions (a),(b),(c),(e) for
contracts concluded during **unsolicited home visits / organised selling excursions** —
relevant if the merchant ever sells off-premises.

## 4. Architecture

Extend the existing per-item evaluator; add a checkout consent layer; surface an admin
UI. No new tables (Options + order meta + the existing immutable log).

```
Checkout ──► ConsentCapture (per conditional item) ──► order meta + log event
                                   │
Settings UI ──► wwu_wb_exclusions (per-reason product/category maps)
                                   │
ApplicabilityResolver ──► ArticleFiftyNineEvaluator (UPGRADED)
   for each item: exempt? = tagged-with-reason AND (reason unconditional
                            OR consent captured for THIS order)   ──► show button if ANY item still withdrawable
                                   │
Order email (confirmation) ──► reproduces the captured consent  (the Art. 8(7) "confirmation")
```

**Exception-type registry** — a definition per Art. 59 letter: `{ id, label, legalRef,
conditional: bool, consent_kind: 'none'|'service_performed'|'digital_immediate',
auto_detectable: bool }`, filterable via `wwu_wb_exception_types`. This replaces the
opaque "excluded" boolean with an auditable reason.

## 5. Data Model

- **Option `wwu_wb_exclusions`** (extend): from flat `excluded_product_ids/…` to a
  per-reason map, e.g. `{ by_reason: { '59_o': {products:[…],categories:[…]},
  '59_a': {…}, '59_c': {…} }, auto_detect_virtual: bool }`. Back-compat: migrate the
  old flat lists into a generic `'manual'` reason on upgrade.
- **Order meta** (per conditional line item or per order):
  `_wwu_wb_consent` = `[{ product_id, reason_id, text_version, text_hash, consented_at,
  ip }]` — the captured express consent + acknowledgement, with the exact wording shown.
- **Immutable log** (new event): `withdrawal_exempt_consent` recording the consent at
  capture time (append-only, time-stampable like other events) so the trader can prove
  the Art. 8(7) confirmation existed.

## 6. API / Interfaces

- Filters: `wwu_wb_exception_types` (extend the registry), keep
  `wwu_wb_excluded_product_ids` (back-compat).
- Hooks: capture on `woocommerce_checkout_create_order` / `_order_processed`
  (WooCommerce), FluentCart order-created equivalent; reproduce consent on the
  acknowledgement + order-confirmation email.
- `ArticleFiftyNineEvaluator::item_is_withdrawable()` gains the consent gate.
- No new REST endpoints required for v1.

## 7. UI / UX

**Admin — Settings → "Exemptions (Art. 59)"** (new section):
- Searchable product + category pickers, grouped under a chosen **exception reason**
  (dropdown of the Art. 59 letters with a one-line explanation + the legal ref, per
  Standard #12 tooltip+example).
- For **conditional** reasons (digital-immediate, service-performed): a clear notice
  *"This exemption only applies when the customer's consent + acknowledgement was
  captured at checkout — enable the checkout consent below."* + the **editable statutory
  wording** of the consent (i18n, with a safe default).
- `auto_detect_virtual` toggle kept, but reframed as *"treat delivered virtual/
  downloadable items as digital content (only valid with consent capture on)"*.

**Checkout — consent capture** (only when the cart has a conditional-exempt item):
- A required checkbox with the statutory wording, e.g. *"I request immediate
  performance and acknowledge that I lose my right of withdrawal once it begins /
  is fully performed."* Per-item where carts mix exempt + non-exempt.
- Conversion-safe: only shown for the relevant items; concise; not a wall of legalese.

**Consumer transparency:** where the button is hidden for an exempt order, the
guidance partial explains *which items* are exempt and why (Art. 59 reason), so it
never looks like a denied right.

**Order email (confirmation):** reproduce the captured consent text + timestamp — this
*is* the trader's Art. 8(7)/51(7) confirmation on a durable medium.

## 8. Edge Cases

- **Conditions not captured** → item stays withdrawable (fail-safe). Never hide the
  button for a conditional exemption without stored consent.
- **Partial service performance** → not exempt; consumer may withdraw, owes proportional
  payment (out of scope to compute; the button stays).
- **Seal-based (e/i)** → cannot be known at order time (depends on the consumer
  unsealing). Treat as withdrawable by default; the merchant assesses on return. Provide
  the tag for the *product* but document it does not auto-hide the button.
- **CJEU strict (C-641/19)** → warn the merchant not to tag services as "digital
  content"; offer the correct "service performed" reason instead.
- **Mixed cart** → button shown if ANY item is still withdrawable (existing behaviour).
- **Off-premises (IT 59 1-bis)** → if a future channel is off-premises, disapply
  (a)(b)(c)(e). Out of scope for online-only v1; note in code.
- **Digital zero-payment** → if conditions failed and the consumer withdraws after
  download, they owe nothing — the plugin must not imply otherwise.

## 9. Security

- Consent capture is consumer input → sanitise; store the **text version/hash** shown
  (not attacker-controllable free text). Capability-gate the admin UI (existing
  capability). Product/category IDs cast to int + validated against existing taxonomies.
- Consent in the immutable log inherits the hash-chain + optional timestamp.

## 10. Performance

- Evaluator stays O(items); the per-reason map lookup is array membership. Consent read
  is one order-meta fetch. No N+1. Admin pickers use WooCommerce's AJAX product search.

## 11. Testing Strategy

- Smoke suite `exemptions`: unconditional reason hides button; conditional reason WITHOUT
  consent keeps button; conditional WITH consent hides button; mixed cart shows button;
  back-compat migration of old flat lists; CJEU-guard (service not mislabelled digital).
- Manual: checkout consent capture → order meta + log event → confirmation email
  reproduces it; consumer transparency copy on an exempt order.

## 12. Open Questions

1. Per-line-item consent vs per-order — line-item is more correct for mixed carts but
   heavier UX. Default: per-item, fall back to per-order when the whole cart is exempt.
2. Should `auto_detect_virtual` be **off by default** until consent capture is enabled
   (to avoid hiding the button without valid consent)? **RESOLVED (1.0.0-alpha.24,
   2026-06-14): YES.** The seed was flipped OFF and Migration 2 flips existing installs.
   The withdrawal right is now the shipped default — digital products are covered (the
   button shows) unless a legally-valid exemption is configured here. This SPEC's job is
   exactly that: read the EXACT Art. 16/59 list (§3), let the merchant assign their
   products to a specific legal reason (§7), capture consent for the conditional ones,
   and otherwise apply the button to everything.
3. FluentCart consent-capture hook parity — confirm the equivalent checkout hook + meta.
4. Phasing: P1 admin UI + reason tagging (uses existing evaluator); P2 checkout consent
   + gate; P3 email confirmation + log event + consumer transparency. Each with an audit.
   **Status: P1 SHIPPED (1.0.0-alpha.27, 2026-06-14)** — `ExceptionTypes` registry +
   `ExemptionResolver` (by_reason map + back-compat fold) + upgraded
   `ArticleFiftyNineEvaluator` (unconditional exempt / conditional gated on the
   `wwu_wb_exemption_consent` filter / seal-based never auto-hidden) + Settings UI +
   smoke suite `exemptions`. P2 (checkout consent capture writing that filter's data) +
   P3 remain — tracked as task #41.

## References

- Dir. 2011/83/EU Art. 16, Art. 14(4)(b), Art. 8(7); Dir. (EU) 2019/2161; Dir. (EU)
  2023/2673 (Art. 11a). Art. 59, 57, 51(7) D.Lgs. 206/2005 (Codice del Consumo); Art.
  54-bis (D.Lgs. 209/2025). CJEU **C-641/19 PE Digital** (8 Oct 2020). EUR-Lex +
  research workflow output (2026-06-14).
