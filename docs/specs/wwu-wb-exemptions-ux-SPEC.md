# SPEC — Exemptions management UX (guided helper + preview + status + i18n)

> Enhancement bundle for the **merchant experience** around the Art. 59 exemptions.
> The plugin's CORE function — the compliant withdrawal button for everything that
> *does* carry the right — is untouched. This only makes the *exemption* configuration
> easier to set up correctly, verify, and maintain, and completes the Italian (and FR/ES/DE)
> translations. **Status: SHIPPED in 1.0.0-alpha.31** (WWU UI Kit accordions/badges/notices; guided "what do you sell?" helper; consumer preview; status panel; landing section; full it/fr/es/de i18n).
>
> **Decided in interview (2026-06-14):** Settings UI = grouping + tooltips **+ guided
> "what do you sell?" helper**; management tools = **consumer preview** + **status/health
> panel**; landing = **dedicated section with examples**; plus a **full i18n pass**.
>
> Builds on the verified [exemption-consent evidence note](../legal/wwu-wb-exemption-consent-evidence-NOTE.md)
> and the exemptions [SPEC](wwu-wb-withdrawal-exemptions-SPEC.md) (P1–P3 shipped).

## 1. Overview

Live testing surfaced three friction points: (a) the Exemptions settings are a long flat
table with reason labels/hints that are **untranslated** (43 it_IT strings missing,
including the whole reason catalog); (b) a merchant has to know *which* Art. 59 reason fits
their product (event ticket vs recording vs live Zoom vs service) — easy to mislabel; (c)
there is no way to *see* what the consumer will get (the checkbox + durable-medium e-mail)
or to check the exemption setup at a glance. This bundle fixes all three, plus the i18n gap.

## 2. Goals & Non-Goals

**Goals**
- Make the Exemptions settings legible: group the ~13 reasons by behaviour, add a tooltip +
  a collapsible example to each (Standard #10 + #12).
- A **guided "What do you sell?" helper** that maps common product types (event tickets,
  digital downloads/recordings, live sessions, immediate services, physical goods) to the
  right reason and points the merchant to the field — **suggest-only**, no auto-writes.
- A **consumer preview** (per conditional reason) showing the exact checkbox wording + the
  durable-medium confirmation e-mail the consumer will receive.
- A **status/health panel** summarising the exemption setup (reasons configured, confirmation
  e-mail transport, retention + IP capture, last purge run).
- **Complete the translations** (it_IT 43 + 6 fuzzy, fr_FR/es_ES/de_DE equivalents + any new
  strings here), recompile `.mo`.
- A **dedicated landing section** with the real examples (events / recordings / Zoom).

**Non-Goals**
- Changing the substantive exemptions or the evaluator/capture logic (P1–P3 stay as is).
- Auto-creating WooCommerce categories or auto-writing product/category IDs (the helper only
  guides — the merchant stays in control; misconfiguration stays fail-safe).
- Resolving live product counts per reason with heavy queries (the panel reports configured
  IDs, not a per-product scan).
- A full Settings-page redesign (out of scope; only the Exemptions section is reworked).

## 3. User Stories

- *As Nadia (sells event tickets, recordings, Zoom),* I click "What do you sell? → Event
  tickets" and the helper tells me to tag them under "leisure on a specific date" and jumps
  me to that field; for recordings it tells me to use "digital immediate access" and warns me
  the consumer will see a consent checkbox + get a confirmation e-mail.
- *As a merchant,* before going live I expand "Preview" under "Digital content with immediate
  access" and read the exact checkbox sentence + the e-mail the buyer will receive.
- *As a merchant,* I open the Exemptions status box and see "2 reasons configured, confirmation
  e-mail OK (FluentSMTP), retention 10y, IP capture on, last purge 2 days ago".
- *As an Italian admin,* the whole Exemptions section is in Italian.

## 4. Architecture

No new domain logic; this is presentation + i18n on top of the existing pieces.

- `Admin/SettingsPage::render_exemptions_section()` — reworked: render reasons **grouped** by
  a new `ExceptionTypes::group()` helper (`conditional | unconditional | seal_based`), each in
  a `<details>` group; per-reason tooltip (`wwu-wb` tooltip span) + collapsible example.
- `Admin/ExemptionsHelper` (new, ~120 lines) — renders the "What do you sell?" cards + the
  product-type → reason map (static, filterable `wwu_wb_product_type_map`). Pure presentation;
  emits anchors/`data-target` to the reason groups. Minimal vanilla JS (expand + scroll), with
  a no-JS anchor fallback.
- `Admin/ExemptionsStatus` (new, ~120 lines) — aggregates: reasons-configured count (from
  `wwu_wb_exclusions`), confirmation e-mail transport (reuse `DashboardPage::mail_transport`
  via an extracted shared helper), `retention_years` + `consent_capture_ip`, last purge
  (`wwu_wb_consent_last_purge` option). Rendered as a box in the Exemptions section.
- `Domain/ExceptionTypes` — add `group(string $id): string` + a `'example'` field to each
  definition (Standard #12 input→output example). Back-compat: `example` optional.
- `Mail/ExemptionConfirmation` — extract `build_html()` to accept synthetic entries so the
  preview can render the same body the consumer gets (single source of truth).
- `Core/ConsentRetention::purge()` — record `update_option('wwu_wb_consent_last_purge', gmdate)`
  each run (for the status panel).
- Assets: a small `assets/admin/exemptions.(js|css)` (vanilla, scoped `.wwu-wb-*`) for the
  helper expand/scroll + tooltip; enqueued only on the Settings screen.
- Landing: `_internal/marketing/landing.html` — new section (gitignored source for the live site).

## 5. Data Model

No new tables. Touched options:
- `wwu_wb_exclusions` — unchanged shape (`by_reason`, `auto_detect_virtual`).
- `wwu_wb_settings` — already has `retention_years`, `consent_capture_ip` (alpha.29).
- **New:** `wwu_wb_consent_last_purge` (string, autoload `no`) — ISO timestamp of the last
  retention-purge run. Written by `ConsentRetention::purge()`.
- `ExceptionTypes::all()` rows gain an optional `'example'` string (in-memory only, not stored).

## 6. API / Interfaces

- `ExceptionTypes::group(string $id): string` → `conditional|unconditional|seal_based`.
- `ExceptionTypes::all()` rows: `+ 'example' => string`.
- `ExemptionConfirmation::build_html(string $number, array $entries): string` made callable
  for previews (already private — promote to a `public static` preview-safe builder, or add
  `preview_html(string $reason): string`).
- Filter `wwu_wb_product_type_map` — `[{ key, label, reason_id, note }]` for the helper, so
  integrators can extend the product-type catalogue.
- No new REST endpoints. No change to the capture/evaluator contracts.

## 7. UI / UX

- **Exemptions section** → three `<details>` groups, open order: **Conditional (need consent)**,
  **Unconditional (exempt by nature)**, **Seal-based (assess on return)**. Each reason: label +
  legal ref + `?` tooltip (the hint) + `<details>Show example</details>` (input→outcome) + the
  existing ID inputs + (conditional only) a `<details>Preview what the consumer sees</details>`.
- **"What do you sell?" helper** at the top: 5 cards (Event tickets / Digital downloads &
  recordings / Live sessions (Zoom) / Services done immediately / Physical goods). Click →
  inline explanation (legal mapping + consumer consequence) + a "Go to this reason" button that
  expands + scrolls to the matching group. Physical goods → "keep the button, nothing to do".
- **Status box**: compact table, colour-coded (reuse `.wwu-wb-badge`), with one-line hints.
- **Landing**: a section "Vendi biglietti, corsi o contenuti digitali?" with the three worked
  examples + the fail-safe reassurance, in the existing landing visual style.
- Strings: English source, i18n; consumer-facing preview uses the real localised wording.

## 8. Edge Cases

- A reason with no example defined → omit the example block (no empty `<details>`).
- Conditional preview when `ConsentText` is filtered/empty → show "(custom wording via
  `wwu_wb_consent_text`)" instead of a blank.
- Helper with JS disabled → cards are anchor links to the reason group ids (no dead clicks).
- Status panel when WooCommerce inactive → "consent capture runs on WooCommerce" note.
- `wwu_wb_consent_last_purge` missing (never run) → "not run yet (runs daily)".
- Grouping must include `manual` (unconditional) and any **custom** reason added via
  `wwu_wb_exception_types` lacking a `group` → default to `unconditional`.
- i18n: keep every `%s/%d/%1$d` placeholder + HTML tag verbatim; remove `#, fuzzy` after
  finalising; never emit a stray `messages.mo` (always `msgfmt -o`).

## 9. Security

- All new screens are capability-gated (`Authentication::capability()`), output escaped
  (`esc_html/esc_attr/wp_kses_post`), no new input beyond the existing save handler.
- The helper/preview are read-only; no nonce-bearing actions added.
- New JS is vanilla, scoped, no innerHTML of untrusted data (preview text is escaped server-side
  and passed as localised strings).
- Status panel reuses the test-email transport detection (no secrets surfaced).

## 10. Performance

- Status panel reads options only (no product queries) → O(1). The "configured" counts come
  from the `wwu_wb_exclusions` array already in memory.
- Admin assets enqueued **only** on the plugin Settings screen (`is_plugin_screen` guard).
- No frontend impact (all admin-side). The `consent_last_purge` write is once/day in cron.

## 11. Testing Strategy

- Extend smoke suite `consent`/new `exemptions_ux`: `ExceptionTypes::group()` returns the right
  bucket for 59_a/59_o (conditional), 59_c/59_l/manual (unconditional), 59_e/59_i (seal_based);
  every registered reason maps to a known group; every reason has a non-empty `example`;
  `ExemptionConfirmation::preview_html('59_o')` is non-empty and contains the consent wording.
- i18n: `msgfmt --statistics` shows **0 untranslated, 0 fuzzy** for it_IT (and the others) after
  the pass; all 4 `.mo` recompile with 0 errors.
- Manual: load Settings on an it_IT site → section is Italian, grouped, helper works, preview
  renders, status box correct; JS-off fallback; `php -l` clean.

## 12. Open Questions

1. **Status panel location** — inside the Exemptions settings section (proposed) vs the
   Dashboard. Proposed: Exemptions section (context-local). *Default: Exemptions section.*
2. **Helper product types** — the 5 proposed cover Nadia's cases; add "Subscriptions /
   memberships" as a 6th with the nuance (initial order keeps the right; digital access →
   59_o)? *Default: include it as a short note under "Digital downloads".*
3. **Per-reason product counts** — show only "configured / not configured" (cheap) vs an actual
   product count per category (one query/reason). *Default: configured/not-configured + the raw
   ID counts; no product scan.*
4. **Landing depth** — a compact 3-example block vs a longer explainer. *Default: 3 worked
   examples + fail-safe line, matching the existing landing density.*
