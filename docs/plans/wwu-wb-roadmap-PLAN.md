# WWU Withdrawal Button — Implementation Roadmap (PLAN)

> Phased build plan for the EU withdrawal-button compliance plugin. Each large implementation phase is followed by a dedicated **audit phase** (Standard #13). "Done" for any phase requires the relevant rows of the [compliance matrix](../legal/wwu-wb-compliance-matrix.md) to be ✅ and the functional-completeness gate (Standard #14) to hold for the surfaces it ships.

**Last updated:** 2026-06-13 · **SPEC:** [`../specs/wwu-wb-eu-withdrawal-button-SPEC.md`](../specs/wwu-wb-eu-withdrawal-button-SPEC.md)

---

## 0. Deadline reality

- **Today:** 2026-06-13. **Legal go-live:** 2026-06-19 (contracts concluded on/after). **6 days.**
- The obligation binds **new** contracts from 19 June. The realistic target is a **compliant core live on GitHub by 19 June** for WooCommerce (largest market), with FluentCart, OpenTimestamps confirmation polish, and eIDAS as fast-follow.
- **Critical path for "legally compliant on day one"** (must ship): button + statutory labels + two-step + durable-medium email **with PDF** + immutable log + Annex I-B form + privacy/terms/pre-contractual snippets + applicability (EU/EEA) + WooCommerce.
- **Important but fast-follow** (days after): FluentCart adapter, OpenTimestamps Bitcoin-anchor upgrade polling UX, RFC 3161 provider, verifiable-link page polish, DE/FR/ES live-store visual QA, wordpress.org submission.

---

## Phase map

| Phase | Title | Ships | Audit after |
|---|---|---|---|
| **F0** | Foundation + Debug primer | bootstrap, constants, autoloader, Plugin singleton, Install/Uninstall/Migrator (2 tables), multisite-aware activation, Debug stack (Audience/Collector/Debug/Inspector), REST scaffold + `/debug/*`, UI Kit Day 1, env guards | A0 security/PHP |
| **F1** | Platform layer + WooCommerce core flow | `OrderDataSource` + `WooCommerceAdapter` (HPOS), custom order status, My Account surfaces + endpoint tab, `LabelResolver`, `WindowCalculator`, `ApplicabilityResolver` (EU/EEA), `TwoStepController`, statement+confirm REST | **A1** (security + dark-pattern/legibility) |
| **F2** | Immutable log + hash chain | `LogTable`, `LogRepository` (append-only), `LogChain` build/verify, serialised insert, order-meta operational state, evidence in dashboard | **A2** (tamper-evidence + concurrency + GDPR) |
| **F3** | Durable medium | `PdfBuilder` (Dompdf vendor), `ReceiptBuilder`, `WC_Email` subclass, permanent verifiable-link page + token, admin notification | **A3** (email deliverability + token security + PDF i18n) |
| **F4** | OpenTimestamps + provider interface | `TimestampProvider` + `OpenTimestampsProvider` + `TimestampTable` + WP-Cron upgrade poller + `NoneProvider`; `Rfc3161Provider` stub | **A4** (async/cron robustness + privacy nonce) |
| **F5** | Multilingual + compliance documents | i18n bag IT/EN/FR/ES/DE + .pot; `LanguageProvider` cascade; statutory per-country labels incl. DE §356a / FR D.221-5 / ES direct-effect; `LegalDocGenerator` (Annex I-B PDF + shortcode) + `ClauseLibrary` (privacy/terms/pre-contractual) | **A5** (legal-string accuracy + translation integrity) |
| **F6** | FluentCart adapter | `FluentCartAdapter`, portal injection (`order_details_section_parts`) + **runtime probe** + standalone public-form fallback, status via hooks/REST | **A6** (SPA rendering + fallback parity) |
| **F7** | Ecosystem compatibility + shortcodes/blocks | Complianz whitelist, TranslatePress protection + locale emails, cache exclusions, all 5 shortcodes + SSR blocks, template overrides, guest signed-link + public lookup + rate limiting | **A7** (compat + enumeration/CSRF) |
| **F8** | Admin polish + Requests Dashboard + Compliance Status | Settings, Dashboard (filters/export/chain badge), Compliance Status (go-live countdown, doc checklist, warnings), Standard #12 tooltips/help/onboarding | **A8** (UX completeness gate #14) |
| **F9** | Release engineering | README/CONTRIBUTING/CODE_OF_CONDUCT/SECURITY/issue+PR templates, GitHub Actions (php-lint, phpcs, smoke), build script (Dompdf vendor → ZIP), `readme.txt`, GitHub Release `v1.0.0`, GitHub repo public | cumulative ship-readiness audit |
| **P-video** | HyperFrames explanatory video (QUEUED) | `_internal/video/` script + HyperFrames composition + render — **triggered when F0–F9 done** | — |
| **P1+** | Post-MVP | RFC 3161/eIDAS (Aruba/Namirial), full `.ots` PHP verifier, partial/line-item withdrawal, FluentCRM hook, wordpress.org submission, EEA labels, refund-draft workflow | — |

---

## Phase detail (critical-path first)

### F0 — Foundation + Debug primer
- Bootstrap `wwu-withdrawal-button.php`: `WWU_WB_*` constants, double-load guard, min PHP/WP/WC checks with self-deactivate, PSR-4 `src/Autoloader.php`, Dompdf vendor loaded at file-load, `Plugin::boot()`.
- `Core/Install` (multisite-aware: per-site option seed via `add_option` with autoload strings, `Migrator::migrate` for 2 tables, `flush_rewrite_rules` after endpoint), `Core/Uninstall` (multisite loop, DROP tables, keep/erase log choice), `wp_initialize_site` provisioning.
- `Storage/Database/LogTable` + `TimestampTable` (dbDelta two-space rule, DATETIME), `Core/Migrator` (`Migration_1::up()`), `wwu_wb_db_version` + `maybe_upgrade` on `plugins_loaded:5`.
- `Debug/{Audience,Collector,Debug}` (trap #53 fix, secret-mask, ring buffer), `Admin/InspectorPage`, `REST/{Authentication,RestApi}` + `Routes/{Debug,DebugTests}` (no nonce re-verify), per-site `wwu_wb_secret`.
- UI Kit Scenario A copy + loader; `Admin/AdminAssets`.
- **Done:** plugin activates clean on single + multisite; `curl /debug/run-tests` returns canonical shape; tables exist; Inspector renders.

### F1 — WooCommerce core flow (compliance heart)
- `Platform/OrderDataSource` + `WooCommerceAdapter` (HPOS declare + safe read/write), `PlatformRegistry`.
- Custom status `wc-withdrawal-requested` (3 hooks + dual bulk).
- `Frontend/WooMyAccount`: orders-list action, order-detail injection, **endpoint tab** (`add_rewrite_endpoint` + 4 hooks, configurable slug, namespaced to avoid trap #49).
- `Domain/LabelResolver` (statutory IT/EN defaults first; full table in F5), `WindowCalculator` (informational), `ApplicabilityResolver` (EU/EEA mode + B2B + Art.59 stub), `TwoStepController`.
- REST `/withdrawal/statement` + `/withdrawal/confirm` (ownership/token, no nonce re-verify); `WithdrawalService::confirm()` wires log+status+note+actions (receipt/OTS stubbed until F3/F4).
- **A1 audit:** ownership/token security, two-step enforcement, legibility/dark-pattern, HPOS read/write correctness.

### F2 — Immutable log + hash chain
- `LogRepository::append()` (transaction + `GET_LOCK`, compute `prev_hash`/`row_hash`, canonical JSON), `LogChain::verify()`, idempotency on `request_uid`.
- Operational order meta (`_wwu_wb_*`) in parallel.
- Smoke suite `log` (append-only, no updated_at, chain integrity, tamper detection).
- **A2 audit:** concurrency/race on the chain, GDPR (raw IP basis + retention), injection on payload.

### F3 — Durable medium
- `vendor/dompdf` (Composer-built, committed or built in CI), `DurableMedium/PdfBuilder` (`isRemoteEnabled=false`, DejaVu Sans, A4), `ReceiptBuilder` (HTML, locale-switched), `Mail/WC_Email` subclass (manageable, theme-overridable), `ReceiptStore` (protected dir, random filename), verifiable-link REST `/receipt/{uid}` + `/verify/{uid}` (HMAC, rate-limited), admin notification.
- Smoke suite `durable_medium`.
- **A3 audit:** email deliverability, token enumeration/replay, PDF i18n (à ä ö ü ß ñ ç), file-path traversal.

### F4 — OpenTimestamps + provider interface
- `Timestamp/{TimestampProvider,OpenTimestampsProvider,NoneProvider,Rfc3161Provider(stub)}`, `TimestampRepository`, `Timestamp/UpgradeCron` (every 30 min, 404=pending, retry budget 48h then failed), 16-byte nonce, 4 calendars parallel.
- Smoke suite `timestamp`.
- **A4 audit:** cron robustness, calendar-down resilience, privacy (nonce), never block confirmation.

### F5 — Multilingual + compliance documents
- `I18n/TextDomain` + `.pot` (`php wwu-tools/wwu-generate-pot.php wwu-withdrawal-button`); ship `it_IT, en_*, de_DE, fr_FR, es_ES` .po/.mo; `LanguageProvider` (TRP/WPML/Polylang/core cascade).
- Full statutory `LabelResolver` table (DE no-"hier", FR D.221-5, ES direct-effect, confirmation "only words").
- `Legal/LegalDocGenerator` (Annex I-B PDF + `[wwu_wb_model_form]`), `Legal/ClauseLibrary` (privacy/terms/pre-contractual, IT complete + EU-generic + DE/FR/ES).
- **A5 audit:** statutory-string accuracy (per country), translation integrity, document completeness.

### F6 — FluentCart adapter
- `FluentCartAdapter` (`function_exists('fluent_cart_api')`, ORM reads, `fluentcart_loaded` registration), portal injection via `fluent_cart/customer/order_details_section_parts` (`end_of_order`) + **runtime probe** (inject test marker, detect render) → standalone public-form fallback; status via order-status hooks / REST.
- Smoke suite `platform_fluentcart`.
- **A6 audit:** Vue SPA rendering reality, fallback parity, digital auto-complete window edge.

### F7 — Compatibility + shortcodes/blocks + guest
- `Compat/Complianz` (marker `data-wwu-wb=` + `cmplz_whitelisted_script_tags` + `cmplz_service_category` functional + transient bust), `Compat/TranslatePress` (`data-no-translation` + `trp_no_translate_selectors` + `trp_woo_email_language` + locale meta), `Compat/CacheExclusions` (Rocket/LiteSpeed auto, W3TC/Cloudflare warn).
- All 5 shortcodes + SSR blocks + template loader (`wwu_wb_get_template`, theme override, realpath confinement).
- Guest: `Frontend/SignedLink` (HMAC order-email link), `Frontend/PublicForm` (lookup order#+email, rate-limited, receipt to titleholder).
- Smoke suites `compat_*`, `shortcodes`.
- **A7 audit:** Complianz/TRP real behavior, enumeration/CSRF/rate-limit, LFI on template filter.

### F8 — Admin + dashboard + compliance status
- `Admin/SettingsPage` (labels/applicability/exclusions/timestamp/retention), `Admin/RequestsDashboard` (UI Kit table, filters, status transitions, evidence export, chain-integrity badge), `Admin/ComplianceStatusPage` (go-live countdown, document checklist, Complianz/cache/TRP warnings), Standard #12 tooltips/help/onboarding.
- **A8 audit:** functional-completeness gate (#14) — no dead affordances; browser-visual every control.

### F9 — Release engineering
- Community files (README, CONTRIBUTING, CODE_OF_CONDUCT, SECURITY.md, issue/PR templates), `LICENSE` (GPLv3), `readme.txt` (wordpress.org format), `composer.json` (Dompdf dev dep + build), GitHub Actions (`php -l`, phpcs WPCS, smoke runner), build script (`bin/build.sh` → ZIP excluding `_internal/`, `tests/`, dev files), tag `v1.0.0`, **create public GitHub repo + Release**.
- Cumulative ship-readiness audit → SHIP verdict.

### P-video — HyperFrames explanatory video (QUEUED)
- **Trigger:** F0–F9 complete (feature-complete + audits passed).
- `_internal/video/` (gitignored): EN+IT script + storyboard, screen recordings/screenshots, HyperFrames composition, render. Topics: what the law requires, what the plugin does in 60–90s, install + 3-step setup, the consumer flow, the evidence log. Distributed on webwakeup.it + GitHub README embed link.

---

## Risks & mitigations
- **6-day deadline** → WooCommerce-first critical path; FluentCart/OTS-upgrade/eIDAS as fast-follow (documented, not silently dropped).
- **FluentCart Vue SPA** → runtime probe + standalone fallback guarantees a compliant path regardless.
- **Spain not transposed** → directive labels + direct-effect note + release re-check.
- **Legal-string drift** → statutory defaults locked + override warnings + per-country authority cited in code comments + A5 audit.
- **OTS calendar downtime** → 4 calendars + cron retry + never blocks confirmation.
- **License/PDF** → GPLv3 + Dompdf (LGPL-2.1); mPDF (GPL-2.0-only) explicitly avoided.
