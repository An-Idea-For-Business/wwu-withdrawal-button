# SPEC — WWU Withdrawal Button: Legal Documents & Delivery (Policy doc + Complianz injection + dynamic defaults + i18n tooling)

- **Slug:** `wwu-wb` · **Feature:** `legal-documents`
- **Target version:** `1.3.0` (minor — significant new feature set)
- **Status:** CONFIRMED 2026-06-25 — implementing (Phase A). All 6 open questions resolved (see §12).
- **Author:** Claude (Opus) with Edoardo
- **Created:** 2026-06-19
- **Recon sources:** `docs/analysis/wwu-wb-complianz-i18n-law-recon-2026-06-19-ANALYSIS.md` (online: Complianz hook schema, EU/IT law checklist, i18n tooling) + in-session local scan (ClauseLibrary, ExceptionTypes, settings, Install, Shortcodes, PdfBuilder).

---

## 1. Overview

Today the plugin **generates** ready-to-paste legal clauses (`ClauseLibrary`: `precontractual` / `terms` / `privacy` / `consent_privacy`) and **tells the merchant to paste them** into their own documents. There is no single consolidated document, no automated delivery, and the defaults are static.

This feature adds three things, all sharing one **dynamic document assembler**:

1. **A "Right of Withdrawal Policy" document** assembled at runtime from the merchant's actual configuration (withdrawal-window length, trader identity, active platforms, and the **selected Art. 59 exemption categories**), delivered three ways: an **auto-created WordPress Page (draft)**, a **`[wwu_wb_policy]` shortcode + Gutenberg block**, and a **downloadable PDF**.
2. **Opt-in injection of the withdrawal clauses into Complianz documents** (Terms & Conditions + Privacy Statement) via the `cmplz_document_elements` filter, with **per-document toggles**, draft/preview, explicit publish, and a lawyer-review disclaimer.
3. **Strengthened, dynamic default legal texts** — the `ClauseLibrary` defaults reviewed against the EU/IT legal checklist (Art. 6 CRD, Art. 11a / Art. 54-bis, Annex I-A/I-B, Art. 16 CRD = Art. 59 CdC) and made to interpolate live settings so the output is always coherent with what the merchant configured.

Plus an enabling dev-tool: **`wwu-tools/wwu-i18n.php`**, a reusable, validated translation pipeline (wp-cli + `gettext/gettext`) so the new strings — and all future ones — are managed without hand-rolled, error-prone scripts.

> **Legal-liability stance (non-negotiable):** the plugin never silently writes into a merchant's published legal documents. Everything is **draft/preview by default**, requires an **explicit publish/inject action**, and carries a persistent **"generic text — have your lawyer review it"** disclaimer. This SPEC preserves that stance everywhere.

---

## 2. Goals & Non-Goals

### Goals
- One **dynamic assembler** (`PolicyBuilder`) that is the single source of truth for the consolidated policy, rendered to Page + shortcode/block + PDF.
- The policy reflects **live settings**: `withdrawal_window_days`, trader info, applicability countries, and the **selected exemptions** (`wwu_wb_exemptions['by_reason']`) rendered with their `legal_ref` + plain-language line.
- **Opt-in Complianz injection** into `terms-conditions` and `privacy-statement` with independent toggles; inject the merchant's **override** when present, else the strengthened default.
- **Strengthened defaults** covering the legal checklist, still generic + safe, fully i18n + interpolated.
- **`wwu-tools/wwu-i18n.php`** robust pipeline (extract / apply / compile / status, with `msgfmt --check` gate) reusable across all WWU plugins.
- MVP renders in the **site language**; multilingual multi-page is post-MVP.

### Non-Goals
- NOT auto-publishing or auto-injecting anything without an explicit merchant action.
- NOT giving legal advice or guaranteeing compliance (disclaimers everywhere).
- NOT WPML/Polylang per-language policy pages in MVP (documented limitation).
- NOT replacing Complianz's own privacy/terms content — we **append** clearly-labelled elements.
- NOT a generic "legal documents" generator for unrelated policies (cookie, refund, shipping) — scope is the right of withdrawal only.

---

## 3. User Stories

- **As a merchant**, I open *Compliance → Withdrawal Policy*, see a live preview assembled from my settings + chosen exemptions, click **"Create draft page"**, review it, and publish — so my shop has one clear withdrawal policy.
- **As a merchant using Complianz**, I toggle **"Add the withdrawal clauses to my Complianz Terms"** and **"…to my Privacy Statement"**, see a preview, confirm, and the clauses appear (clearly labelled) in those documents — without me copy-pasting.
- **As a merchant**, I download the **policy PDF** to archive it or attach it to my records.
- **As a developer**, I embed `[wwu_wb_policy]` (or the block) on my own Terms page so it stays in sync with the plugin settings.
- **As a non-EU/edge merchant**, when the policy would be misleading (e.g. no withdrawal right configured), the plugin warns me instead of producing a wrong document.
- **As the WWU dev (me)**, I run `php wwu-tools/wwu-i18n.php apply wwu-withdrawal-button it_IT trans.json` and the `.po`/`.mo` update + validate, with zero risk of malformed files.

---

## 4. Architecture

### 4.1 The dynamic assembler — `src/Legal/PolicyBuilder.php`
A pure builder (no side effects) with:
```
PolicyBuilder::build( string $lang, array $opts = [] ): PolicyDocument
```
`PolicyDocument` = a value object exposing `->title()`, `->sections(): Section[]`, `->to_html()`, `->to_plain()`. Each `Section` = `{ id, heading, body_html, source }`. Sections (per the legal checklist, §5/F-domains):
1. **Right & period** — the 14-day (or configured) right, start trigger, no-reason. (`precontractual` clause + `withdrawal_window_days`.)
2. **How to withdraw (the online button)** — the statutory button modality + the Annex I-B model form + the durable-medium acknowledgement. (`terms` clause + `[wwu_wb_model_form]`.)
3. **Refund & return of goods** — 14-day refund, same payment method, return window. (Art. 13/14.)
4. **Exceptions that apply to this shop** — iterates the merchant's selected exemptions from `wwu_wb_exemptions['by_reason']`, rendering for each: `ExceptionTypes::get($id)['label']` + `['legal_ref']` + a plain-language consumer line derived from `['hint']`. Conditional ones note the consent requirement. **If none selected → this section is omitted** (coherence).
5. **Evidence & privacy** — the withdrawal-log + exemption-consent privacy clauses. (`privacy` + `consent_privacy`.)
6. **Trader identity** — site name / address / `merchant_email` (first address only, via `Sanitizer::first_email`).

The builder reads: `ClauseLibrary::get($type,$lang)` (override-aware), `wwu_wb_settings` (window, trader, send_pdf), `wwu_wb_applicability` (countries), `wwu_wb_exemptions['by_reason']` (selected reasons), `ExceptionTypes::all()` (labels/refs). All text via `__()`.

### 4.2 Delivery surfaces
- **Page:** `Install::ensure_policy_page()` (mirrors existing `ensure_form_page()` at `Install.php:326`), `wp_insert_post` with `post_status = 'draft'`, content = the `[wwu_wb_policy]` shortcode (keeps it live), stores `wwu_wb_settings['policy_page_id']`. A new admin action `admin_post_wwu_wb_create_policy_page` (nonce + capability) creates/links it. The page is **never auto-published**; merchant publishes from the normal WP editor or a "Publish" button that flips status with a confirm.
- **Shortcode + block:** `[wwu_wb_policy]` registered in `Shortcodes.php` (joins `wwu_wb_button/form/status/model_form/info`). Renders `PolicyBuilder::build(current_lang)->to_html()` wrapped in `.wwu-wb-policy` + a dismissible disclaimer. A thin Gutenberg block (`wwu-wb/policy`) wraps the shortcode (server-rendered).
- **PDF:** new `templates/pdf/policy-pdf.php` (mirrors `templates/pdf/receipt-pdf.php`, CSS 2.1, DejaVu fonts) rendered by the existing `PdfBuilder::render()`. A "Download policy PDF" button (admin + optional front-end via `?wwu_wb_policy_pdf=1` gated) streams it.

### 4.3 Complianz injection — `src/Compat/ComplianzDocuments.php`
- `add_filter('cmplz_document_elements', [...], 20, 4)` → `inject($elements, $region, $type, $fields)`.
- For `$type === 'privacy-statement'` and toggle on → append elements (keys prefixed `wwu_wb_privacy_*`) built from the `privacy` + `consent_privacy` clauses.
- For `$type === 'terms-conditions'` and toggle on → append elements (keys `wwu_wb_terms_*`) built from the `terms` clause + model-form reference.
- **CRITICAL (recon):** `terms-conditions` is **not native** to Complianz Premium — it comes from the companion plugin `complianz-terms-conditions`. Strategy: detect it (`is_plugin_active`/class/const). If **absent** → the Terms toggle is shown **disabled** with a notice "install Complianz's free Terms & Conditions add-on to enable this", and we self-register the type ONLY as an explicit opt-in fallback via `add_filter('cmplz_pages_load_types', …, 5)` with `document_elements => []` (recon: must be `[]`, never `''`). MVP default: depend on the companion for Terms; always allow Privacy.
- Elements respect the documented schema (`title/subtitle/content/p/numbering/class/condition/dropdown-*`). All content escaped + `__()`.
- On settings save: call `cmplz_flush_documents()` (+ existing `Complianz::bust_cache()`) so the change is reflected.
- **Opt-in + preview:** toggles default OFF. A "Preview what will be added" expander renders the exact elements. Injection only happens while a toggle is ON; turning it OFF removes the elements on next regeneration. A persistent disclaimer sits next to the toggles.

### 4.4 Strengthened defaults — `src/Legal/ClauseLibrary.php`
Revise `CLAUSES` defaults (per §5 checklist) to be more complete but generic, keeping every `__()` and interpolation token. Add any missing interpolation (e.g. window days already configurable — ensure the `terms`/`precontractual` defaults use it). No API change to `get()/types()/default_text()/has_override()`; overrides keep priority.

### 4.5 i18n tooling — `wwu-tools/wwu-i18n.php`
A CLI dispatcher (hard CLI gate, like the other wwu-tools) with subcommands:
- `extract <plugin>` → regenerate `.pot` (wraps the existing `wwu-generate-pot.php`, or shells `wp i18n make-pot` if available).
- `merge <plugin>` → `msgmerge --update` (or `wp i18n update-po`) the `.pot` into every locale `.po`.
- `untranslated <plugin> <locale>` → emit a clean JSON `[{n,msgid,msgid_plural}]` of untranslated entries (the robust extractor from this session, hardened).
- `apply <plugin> <locale> <translations.json>` → fill the `.po` using the **`gettext/gettext` v5** library (`PoLoader`→modify→`PoGenerator`) so escaping/multiline/plurals can't be malformed; validate; refuse on key/format mismatch.
- `compile <plugin> [locale]` → `.po`→`.mo` via `msgfmt --check-format` (or `wp i18n make-mo`).
- `status <plugin>` → per-locale translated/untranslated counts.
`gettext/gettext` lives in a **wwu-tools-local `composer.json`** (NOT the plugin's — never bundled in the dist zip). `.distignore` already excludes `wwu-tools/`.

---

## 5. Data Model

### 5.1 New `wwu_wb_settings` keys (additive; seeded in `Install`)
| Key | Type | Default | Purpose |
|---|---|---|---|
| `policy_page_id` | int | 0 | Auto-created policy Page id (0 = none). |
| `policy_page_status` | string | `draft` | Tracks last known status for UX. |
| `complianz_inject_terms` | bool | false | Opt-in: inject withdrawal article into Complianz Terms. |
| `complianz_inject_privacy` | bool | false | Opt-in: inject privacy clauses into Complianz Privacy Statement. |
| `policy_pdf_public` | bool | false | Allow front-end policy-PDF download. |

### 5.2 Reused (read-only by the builder)
`wwu_wb_clauses` (overrides `[type][lang]`), `wwu_wb_settings.withdrawal_window_days|merchant_email|send_pdf`, `wwu_wb_applicability.mode|custom_countries`, `wwu_wb_exemptions.by_reason` (selected reasons → product/category targeting), `ExceptionTypes::all()` (static registry: id/label/legal_ref/conditional/consent_kind/seal_based/hint).

### 5.3 No new DB tables. No schema migration. The policy is assembled on the fly; only the new option keys persist.

---

## 6. API / Interfaces

- `WWU\WithdrawalButton\Legal\PolicyBuilder::build(string $lang, array $opts = []): PolicyDocument`
- `PolicyDocument::{title(),sections(),to_html(),to_plain()}`
- Shortcode `[wwu_wb_policy lang="" sections=""]` (sections = optional comma list to limit).
- Block `wwu-wb/policy` (server-rendered wrapper).
- Filters (developer extension):
  - `wwu_wb_policy_sections( array $sections, string $lang )` — add/remove/reorder sections.
  - `wwu_wb_policy_section_html( string $html, string $section_id, string $lang )`.
  - `wwu_wb_complianz_elements( array $elements, string $type, string $lang )` — last-chance edit before injection.
- Admin actions: `admin_post_wwu_wb_create_policy_page`, `admin_post_wwu_wb_publish_policy_page`, `admin_post_wwu_wb_policy_pdf` (all nonce + `Authentication::capability()`).
- CLI: `php wwu-tools/wwu-i18n.php <extract|merge|untranslated|apply|compile|status> …`.

---

## 7. UI / UX

- **Compliance → "Withdrawal Policy"** new sub-section: live preview (accordion of sections), buttons **Create draft page** / **Open page** / **Download PDF**, status chip (No page / Draft / Published), and the lawyer-review disclaimer banner (UI Kit notice).
- **Settings → Complianz** (new block): two toggles (Terms / Privacy) each with a "Preview" expander showing the exact element text; Terms toggle disabled + helper when the companion add-on is missing; `cmplz_flush_documents()` on save with a success toast.
- Every generated surface (page, shortcode, PDF) carries a visible **"This is a generic template — have it reviewed by your lawyer"** line.
- Tooltips + "Show example" per Standard #12 on the new controls.
- All strings `__()`/`_e()` (Italian UI), routed through the new i18n tool.

---

## 8. Edge Cases

- **No exemptions selected** → omit the exceptions section (don't print "none").
- **Withdrawal window = 14 vs custom** → text interpolates the number; ≥14 only (the legal minimum; the validator already clamps).
- **Applicability = all countries / non-EU** → policy still valid; if `enabled=false` or no withdrawal right exists at all, show a warning, not a misleading doc.
- **Clause override present** → use the override verbatim (no disclaimer prefix), both in the policy and the Complianz injection.
- **Complianz active but `terms-conditions` type missing** (no companion) → Terms toggle disabled + guidance; Privacy still works.
- **Complianz inactive** → the whole Complianz block hides; page/shortcode/PDF unaffected.
- **Policy page deleted/trashed by merchant** → `policy_page_id` self-heals to 0; "Create draft page" reappears.
- **Multisite / multilingual** → MVP renders site language; a notice explains per-language pages are manual for now.
- **PDF when Dompdf missing** → "Download PDF" disabled with the existing graceful notice (HTML page still available).
- **i18n apply with mismatched/missing keys** → the tool refuses + reports (no partial corruption); plural entries handled as `[singular,plural]`.
- **Uninstall** → remove the new option keys; optionally trash the auto-created policy page (configurable, default keep — it's the merchant's content); Complianz elements vanish once the plugin (and its filter) is gone.

---

## 9. Security

- All admin actions: nonce + `current_user_can(Authentication::capability())`.
- All assembled output escaped (`esc_html`/`wp_kses_post` for the curated clause HTML); the PDF path escapes per the existing receipt pattern (`PageCompiler`-style `</style>`/`</script>` neutralisation not needed — Dompdf input is our own templated HTML, but still `esc_html` dynamic values).
- Complianz elements: content is our own `__()` text + escaped settings values; no user-supplied raw HTML injected into someone else's legal doc beyond the merchant's own clause override (already capability-gated at save).
- Front-end policy-PDF: gated behind `policy_pdf_public` + rate-limited (reuse `GuestAccess` limiter) to avoid render-DoS; default OFF.
- The i18n tool is dev-only (CLI gate, never shipped; `.distignore` excludes `wwu-tools/`).
- No secrets, no external calls added.

---

## 10. Performance

- `PolicyBuilder` is pure + cheap (string assembly); cache the rendered HTML per `(lang, settings-hash)` in a transient (5 min) to avoid re-assembly on every page view; bust on settings/clause save.
- Complianz filter runs only on Complianz document render (admin/doc pages), not the front-end hot path; the element array is small.
- PDF render is on-demand only (button), never automatic.
- Zero added cost for visitors who never hit the policy page.

---

## 11. Testing Strategy

- **Smoke suite `policy`** (PHP, in `SmokeTests.php`): builder produces all expected sections; exemptions section reflects `by_reason` (present/absent); window interpolation; override-wins; plain + html outputs non-empty + escaped; PDF renders a valid `%PDF` (when Dompdf present, else skip).
- **Smoke suite `complianz_docs`**: element arrays well-formed for privacy + terms; toggle-off → empty; `terms-conditions`-missing path returns empty + flags; key-prefix `wwu_wb_*`.
- **i18n tool tests**: round-trip `extract → untranslated → apply (good JSON) → compile`; malformed-JSON input refused; plural handled; `msgfmt --check` passes; a deliberately-mismatched key reported not applied.
- **Manual plan** (`tests/manual-legal-documents-check.md`): create draft page, preview, publish, shortcode on a page, PDF download, Complianz Privacy injection (with + without the Terms companion), lawyer-disclaimer visible everywhere, IT/EN rendering.
- **Live**: verify on the test subsite with Complianz active (the workspace has `complianz-gdpr-premium`).
- Lint gate: PHP 0 errors; the i18n tool `--check` gate green before any commit.

---

## 12. Open Questions

**RESOLVED 2026-06-25 (confirmed with Edoardo before coding):**
1. Terms companion → **depend on `complianz-terms-conditions` + guide** (Terms toggle disabled with a notice when the companion is missing); Privacy injection always available.
2. Policy page content → **store the `[wwu_wb_policy]` shortcode** (always current) + an optional "freeze to static HTML" button.
3. PDF branding → **plain** (like the receipt), MVP.
4. Strengthened defaults language → **IT + EN now**, regenerate `.pot`, then DE/FR/ES via the new i18n tool (SV pending Daniel's review).
5. Document label → **"Informativa sul diritto di recesso"** + a sub-line clarifying it *complements, not replaces*, the Terms / pre-contractual information.
6. i18n tool → **build `wwu-tools/wwu-i18n.php` WWU-wide now** (Phase C).

Original open questions (kept for the record):
1. **Terms companion dependency:** ship requiring `complianz-terms-conditions` for Terms injection (cleanest) vs self-register the type via `cmplz_pages_load_types` (more autonomous, more surface). MVP leans "depend + guide"; confirm.
2. **Policy page content:** store the `[wwu_wb_policy]` shortcode (stays live, re-renders) vs bake the rendered HTML at create time (static snapshot the merchant edits freely). Shortcode = always current; snapshot = merchant control. Recommend **shortcode**, with a "freeze to static" button as an option.
3. **PDF letterhead/branding:** plain (like the receipt) vs include site logo/colours. MVP plain.
4. **Strengthened defaults — language coverage:** rewrite IT + EN now, regenerate `.pot`, then route DE/FR/ES/SV through the new i18n tool + sub-agents (SV pending Daniel). Confirm scope.
5. **Document legal name:** there is no single statutory document called "Right of Withdrawal Policy"; the obligation lives in *pre-contractual information* + *Terms of sale*. We present a consolidated convenience page labelled e.g. **"Informativa sul diritto di recesso"** + a sub-line clarifying it complements (not replaces) the Terms/pre-contractual info. Confirm the label.
6. **wwu-i18n scope:** build it WWU-wide now (lives in `wwu-tools/`, benefits every plugin) — confirm it's in-scope for this release vs a separate small task first.

---

### References
- Local recon: in-session scan — `src/Legal/ClauseLibrary.php` (types/OPTION/API), `src/Domain/ExceptionTypes.php` (14 categories + legal_ref + tier), `src/Core/Install.php` (settings seed + `ensure_form_page`), `src/Shortcodes/Shortcodes.php`, `src/DurableMedium/PdfBuilder.php`, `src/Compat/Complianz.php`.
- Online recon (full): `docs/analysis/wwu-wb-complianz-i18n-law-recon-2026-06-19-ANALYSIS.md` — Complianz `cmplz_document_elements` schema + `terms-conditions` companion finding + `cmplz_pages_load_types` + `cmplz_flush_documents()`; EU/IT legal checklist (Art. 6 / 11a / 13–14 / 16 CRD; Art. 54-bis / 59 CdC; Annex I-A/I-B); `wp i18n` standalone + `gettext/gettext` v5.
