# WWU Withdrawal Button — Compliance Matrix (clause → feature → test)

> Every discrete legal obligation in Art. 11a / Art. 54-bis (and the related duties) mapped to the **plugin feature** that satisfies it and the **smoke test / check** that proves it. This is the master checklist used in the audit phases (Standard #13) and the functional-completeness gate (Standard #14).
>
> Legend — Status: ✅ specified · 🟡 specified, implementation pending · ⏳ post-MVP. Verbatim text: see [`wwu-wb-legal-reference.md`](wwu-wb-legal-reference.md).

**Last updated:** 2026-06-13

---

## A. Art. 11a / Art. 54-bis — the withdrawal function

| # | Requirement (verbatim obligation) | Plugin feature | Test / check | Status |
|---|---|---|---|---|
| A1 | A withdrawal function exists for online distance contracts, **additional** to the Annex I-B form (Art. 11a(1); rec. 37) | Button surfaces (My Account action + order detail + endpoint tab + FluentCart portal/fallback + signed email link + `[wwu_wb_button]`); Annex I-B model form kept (`LegalDocGenerator`) | `shortcodes`, `platform_woo`, `platform_fluentcart`, `compliance.model_form_present` | 🟡 |
| A2 | Labelled with **the statutory words** ("recedere dal contratto qui" / national equivalent), unambiguous, **easily legible** (Art. 11a(1); 54-bis c.3) | `LabelResolver` per country/locale (statutory defaults; override emits warning); legibility/contrast guard | `labels.statutory_per_country`, `labels.confirmation_only_words`, UI contrast self-check | 🟡 |
| A3 | **Continuously available throughout the withdrawal period** (Art. 11a(1); c.3) | Button bound to each order's window; **never hidden during a valid period**; window is informational (no false blocking) | `window.available_within_period`, `window.never_hidden_before_deadline` | 🟡 |
| A4 | **Prominently displayed & easily accessible**; no app download / no burdensome steps; logged-in users not re-identified (Art. 11a(1); rec. 37) | Prominent placement in order area (same UI as purchase); pre-filled form for identified users; hyperlink in order email | `ux.prominence`, manual visual; `applicability.logged_in_prefill` | 🟡 |
| A5 | Statement function captures/confirms **(a) name, (b) contract id, (c) electronic means** (Art. 11a(2); c.2) | Step-1 form fields, pre-filled & editable; server re-validation | `durable_medium.statement_fields`, `rest.statement` | 🟡 |
| A6 | A **separate confirmation function** (two-step, anti-mistake) (Art. 11a(3); c.4) | `TwoStepController`: entry never fires withdrawal; distinct Step-2 control | `flow.two_step_enforced`, `flow.no_autosubmit` | 🟡 |
| A7 | Confirmation labelled **only** with "conferma recesso" / equivalent, legible (Art. 11a(3); c.5) | `LabelResolver` confirmation label; "only words" enforced (no combined CTA) | `labels.confirmation_only_words` | 🟡 |
| A8 | **Acknowledgement of receipt on a durable medium** (Art. 11a(4); c.6) | Email + attached **PDF** + permanent verifiable link (`DurableMedium`) | `durable_medium.email_sent`, `durable_medium.pdf_generated`, `durable_medium.link_token` | 🟡 |
| A9 | Acknowledgement includes **its content and the date and time of submission** (Art. 11a(4); c.6) | Receipt reproduces full statement + ISO date/time; log stores same | `durable_medium.content_complete`, `durable_medium.timestamp_present` | 🟡 |
| A10 | Acknowledgement **without undue delay** (Art. 11a(4); c.6) | Synchronous send on confirmation; admin warning if email disabled | `durable_medium.synchronous`, `compliance.email_enabled_warn` | 🟡 |
| A11 | **Timely exercise** if statement submitted before deadline (Art. 11a(5); c.7) | Authoritative server submission timestamp; late requests recorded + **flagged**, never auto-rejected | `window.timely_flag`, `log.submission_timestamp` | 🟡 |

## B. Tamper-evident log (Italian "log immodificabile" + probative value)

| # | Requirement | Plugin feature | Test / check | Status |
|---|---|---|---|---|
| B1 | Secure archive with **date, time, IP, contract data** | `{prefix}wwu_wb_log` append-only; raw IP + payload + timestamps | `log.table_exists`, `log.fields_present` | 🟡 |
| B2 | **Immutable** (no edit/delete) | `DATETIME`, no `updated_at`/`deleted_at`; no UI mutation path; insert-only repository | `log.no_updated_at`, `log.insert_only` | 🟡 |
| B3 | **Tamper-evidence** for litigation value | Global **hash chain** (`prev_hash`/`row_hash`); `LogChain::verify()` reports first break | `log.chain_integrity`, `log.tamper_detected` | 🟡 |
| B4 | **Trusted timestamp** (data certa) | **OpenTimestamps** anchoring (free, Bitcoin) + WP-Cron upgrade; pluggable RFC 3161/eIDAS | `timestamp.stamp_shape`, `timestamp.pending_to_confirmed` | 🟡 / ⏳ (eIDAS) |
| B5 | Evidence **export** for disputes | Signed PDF + log excerpt + chain proof export from dashboard | `durable_medium.export`, manual | 🟡 |
| B6 | GDPR documentation of the log | Generated privacy clause + Art. 30 record + DPIA note; retention config | `compliance.privacy_clause`, manual | 🟡 |

## C. No dark patterns (Art. 11a "as easy as purchase" + AGCM)

| # | Requirement | Plugin feature | Test / check | Status |
|---|---|---|---|---|
| C1 | Withdrawal **no harder than purchase** | Same order-area UI; minimal clicks; no forced phone/account | manual; `ux.prominence` | 🟡 |
| C2 | **No mandatory reason** field | Reason optional with explicit "prefer not to say" | `flow.reason_optional` | 🟡 |
| C3 | **No pre-confirmation upsell/discount** interstitial | Step-2 goes straight to confirm; no interstitial | `flow.no_interstitial` | 🟡 |
| C4 | **Legible / sufficient contrast** button | Contrast + min-size guard; admin self-check | `ux.contrast_guard` | 🟡 |
| C5 | **No buried button** (not behind menus) | Prominent in order area + email link | manual | 🟡 |

## D. Document updates (Art. 54-bis ecosystem)

| # | Requirement | Plugin feature | Test / check | Status |
|---|---|---|---|---|
| D1 | **Annex I-B model form** remains mandatory (coexists) | `[wwu_wb_model_form]` + multilingual PDF, pre-filled | `compliance.model_form_present` | 🟡 |
| D2 | **Pre-contractual info** states existence + location of the function | `[wwu_wb_info]` snippet + clause | `shortcodes.info`, `compliance.precontract` | 🟡 |
| D3 | **General Terms** updated (no obsolete procedures) | `ClauseLibrary` Terms snippet (multilingual) | `compliance.terms` | 🟡 |
| D4 | **Privacy Policy** reflects the log processing | `ClauseLibrary` privacy snippet + DPIA note | `compliance.privacy_updated` | 🟡 |

## E. Scope & applicability (Rome I, art. 59, dates)

| # | Requirement | Plugin feature | Test / check | Status |
|---|---|---|---|---|
| E1 | Applies to **all** online distance B2C contracts with a withdrawal right (not only financial) | No scoping to financial services; general orders | `applicability.general_scope` | 🟡 |
| E2 | Applicability follows the **EU/EEA consumer** (Rome I Art. 6) | `ApplicabilityResolver` per consumer country; CH-seller→EU-consumer covered | `applicability.consumer_country`, `applicability.ch_seller_eu_buyer` | 🟡 |
| E3 | **Switzerland-resident** = voluntary (no mandate) | CH never auto-mandatory; voluntary mode | `applicability.ch_resident_voluntary` | 🟡 |
| E4 | Respect **art. 59 exceptions** (no withdrawal right ⇒ no invitation) | `ArticleFiftyNineEvaluator` auto-detect + override; mixed-cart logic | `applicability.art59_matrix`, `applicability.mixed_cart` | 🟡 |
| E5 | **14-day period**, start delivery (goods) / conclusion (services-digital) | `WindowCalculator` (delivery→completed→paid→created); informational | `window.start_rules`, `window.deadline` | 🟡 |
| E6 | **Go-live 19 June 2026**; in force 23 Jan 2026 | `go_live_date` default 2026-06-19; compliance countdown | `compliance.go_live_default` | 🟡 |
| E7 | B2B out of scope | VAT heuristic (configurable, not hard-blocked) | `applicability.b2b_vat` | 🟡 |

## F. Ecosystem compatibility (not legal, but ship-blocking)

| # | Requirement | Plugin feature | Test / check | Status |
|---|---|---|---|---|
| F1 | **Complianz** must not block the functional flow | `data-wwu-wb=` marker + `cmplz_whitelisted_script_tags` + transient bust; functional category | `compat_complianz.whitelisted` | 🟡 |
| F2 | **TranslatePress** must not alter statutory labels | `data-no-translation` on labels + `trp_no_translate_selectors`; locale-aware email/PDF | `compat_translatepress.labels_protected` | 🟡 |
| F3 | **Page cache** must not stale the button | Auto-exclude My Account/form (Rocket/LiteSpeed); warn W3TC/Cloudflare | `compat_cache.exclusions` | 🟡 |
| F4 | **HPOS** safe | `wc_get_order` only; declare compatibility | `platform_woo.hpos_safe` | 🟡 |
| F5 | **FluentCart** integration or graceful fallback | Portal injection probe → standalone form fallback | `platform_fluentcart.injection_or_fallback` | 🟡 |

---

## Acceptance gate (per Standard #14, before any "stable")
- [ ] Every row above is ✅ or explicitly ⏳-deferred-and-documented.
- [ ] Every interactive affordance does something real or is hidden (no dead buttons / placeholders).
- [ ] Browser-visual pass of the full consumer flow (logged-in + guest) on live WooCommerce **and** FluentCart, in IT/EN/DE/FR/ES, with Complianz + TranslatePress + a cache plugin active.
- [ ] `grep` for placeholder markers returns zero user-facing matches.
- [ ] Smoke suite `0 fail`; chain integrity verified; OTS pending→confirmed observed at least once.
