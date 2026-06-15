# MASTER — WWU Withdrawal Button

> Single index for the **WWU Withdrawal Button** plugin: the EU online right-of-withdrawal function ("withdrawal button", Art. 11a / Art. 54-bis) for WooCommerce & FluentCart. One line per doc; never put content here.

- **Slug:** `wwu-wb` · **Folder:** `wwu-withdrawal-button`
- **Status:** **MVP feature-complete** (F0–F8 + audit hardening), merged to `main`, **released** [`v1.0.0-alpha.36`](https://github.com/An-Idea-For-Business/wwu-withdrawal-button/releases/tag/v1.0.0-alpha.36) — in live testing. Current build `1.0.0-alpha.38` (**subscription-aware withdrawal**: button on the initial order only, suppressed on renewals; on top of the FluentCart `{{wwu.recesso_url}}` merge-tag, EDD parity, the full security audit). ⚠ FluentCart is shipping a **native EU withdrawal feature** soon — revisit FluentCart-specific investment.
- **Target version:** `1.0.0` · **License:** GPL-3.0-or-later
- **Credits:** mredodos · Matteo Alfieri (An Idea for Business) · WebWakeUp ([webwakeup.it](https://webwakeup.it))
- **Product page (live):** [webwakeup.it/wwu-withdrawal-button](https://webwakeup.it/wwu-withdrawal-button/)
- **Legal go-live:** **2026-06-19** (contracts concluded on/after)
- **Last updated:** 2026-06-15 (**Subscription-aware withdrawal IMPLEMENTED — `1.0.0-alpha.38`**: per the SPEC, the button now shows on the initial order only and is suppressed on renewals via one `renewal_order` gate fed by `NormalizedOrder::$is_renewal`; new `SubscriptionAware` interface on all 3 adapters; two opt-in Settings → Subscriptions toggles; Requests "Subscription" badge + reminder; `confirm()` stamps the link + optional auto-cancel + `subscription_cancelled` evidence. Needs a live test with a subscription plugin. On top of alpha.37 FluentCart merge-tag.)

## What it is (one paragraph)
A free, open-source WordPress plugin that makes a store compliant with Directive (EU) 2023/2673 (new Art. 11a of the Consumer Rights Directive 2011/83/EU; Italy: Art. 54-bis Codice del Consumo via D.Lgs. 209/2025): a prominently displayed, continuously available, statutory-labelled withdrawal button → two-step statement + confirmation → durable-medium acknowledgement (email + PDF + verifiable link) → tamper-evident immutable log anchored to OpenTimestamps. Dual platform (WooCommerce HPOS+legacy / FluentCart), multilingual (IT/EN/FR/ES/DE + extensible), Complianz/TranslatePress-compatible, shortcodes + blocks, plus generators for the Annex I-B model form and Privacy/Terms/pre-contractual clauses.

## Conventions
Namespace `WWU\WithdrawalButton` · constants `WWU_WB_*` · options `wwu_wb_*` · meta `_wwu_wb_*` · REST `wwu-wb/v1` · hooks `wwu_wb_*` · CSS `.wwu-wb-*` · text domain `wwu-withdrawal-button` · JS `window.wwuWbData`.

## Specifications
- [SPEC — EU withdrawal button](docs/specs/wwu-wb-eu-withdrawal-button-SPEC.md) — 12 canonical sections; the authoritative design (2026-06-13).
- [SPEC — Withdrawal exemptions (Art. 59)](docs/specs/wwu-wb-withdrawal-exemptions-SPEC.md) — exempting products/services with the legal consent-capture conditions; exception-type registry + admin UI + **P1–P3 + FluentCart shipped** (alpha.27→alpha.30: tagging, WooCommerce **classic + block** + FluentCart checkout consent capture, durable-medium confirmation, retention/purge, GDPR clause, consent-records view, grouped/tooltip'd UX, full i18n). All capture surfaces shipped; EDD is the next platform (SPEC) (2026-06-14).
- [SPEC — EDD (Easy Digital Downloads) integration](docs/specs/wwu-wb-edd-integration-SPEC.md) — **shipped alpha.33**: third platform adapter (EDD 3.0+) `EddAdapter` + `EddCheckoutConsent`, category-aware exemptions, on the official-source-verified surface (2026-06-14). Live EDD test pending.
- [SPEC — Exemptions management UX](docs/specs/wwu-wb-exemptions-ux-SPEC.md) — **shipped alpha.31**: grouped + tooltip'd exemptions settings (WWU UI Kit accordions), a guided "what do you sell?" helper, consumer-preview of the checkbox + durable-medium e-mail, an exemptions status/health panel, full it/fr/es/de i18n, and a landing section with worked examples (events/recordings/Zoom). Core button untouched (2026-06-14).
- [SPEC — North America subscription-cancellation ("click-to-cancel")](docs/specs/wwu-wb-subscription-cancellation-na-SPEC.md) — design (no code yet) for a DISTINCT click-to-cancel module in the same plugin, jurisdiction-toggled (Quebec Bill 10, BC Bill 4, Ontario/NB pending, US ROSCA + state ARLs incl. California AB 2863), with WooCommerce Subscriptions + FluentCart integration. Grounded in a 5-agent official-source sweep (2026-06-14).
- [SPEC — Subscription-aware withdrawal (EU 14-day)](docs/specs/wwu-wb-subscriptions-withdrawal-SPEC.md) — **shipped alpha.38** (2026-06-15): the withdrawal button is now subscription-aware — button on the INITIAL order only, **suppressed on renewals** (one `renewal_order` gate in `ApplicabilityResolver` fed by `NormalizedOrder::$is_renewal`, auto-covering all 8 surfaces; + a `Shortcodes::form()` guard). Per-platform renewal detection (WC Subscriptions `wcs_order_contains_renewal`; FluentCart `Subscription.parent_order_id`; EDD Recurring `edd_subscription` status) via the optional `SubscriptionAware` adapter interface (fail-open); optional auto-cancel-on-withdrawal (default off, `confirm()` stamps the link + `subscription_cancelled` evidence) + Requests "Subscription" badge & reminder (cancel + Art. 14(3) pro-rata, all manual). Two opt-in Settings → Subscriptions toggles. Filter `wwu_wb_order_is_renewal`. Live test pending. Distinct from the NA click-to-cancel module.

## Legal reference
- [Legal reference (verbatim)](docs/legal/wwu-wb-legal-reference.md) — Art. 11a EN+IT, Recital 37, Art. 54-bis, Annex I-B, per-country labels (DE §356a / FR D.221-5 / ES / CH), Rome I applicability, GDPR.
- [Compliance matrix](docs/legal/wwu-wb-compliance-matrix.md) — clause → feature → test, with the Standard #14 acceptance gate.
- [Exemption-consent evidence & record-keeping](docs/legal/wwu-wb-exemption-consent-evidence-NOTE.md) — official-source + adversarially-verified note (2026-06-14): no named "register" (burden of proof Art. 6(9) CRD + GDPR accountability Art. 5(2)); durable-medium confirmation **constitutive** for the digital exemption (Art. 59(1)(o) CdC / Art. 16(1)(m)+14(4)(b)(iii) CRD); 10-year defensible retention (art. 2946 c.c.) configurable + purge routine; GDPR lawful basis = legitimate interest (Art. 6(1)(f)), not consent. Drives exemptions P3 + the privacy clause.

## Plans
- [Implementation roadmap (PLAN)](docs/plans/wwu-wb-roadmap-PLAN.md) — phases F0–F9 + audits + queued HyperFrames video + post-MVP.

## Audits
- [Core F0–F6 audit (2026-06-13)](docs/audits/wwu-wb-core-2026-06-13-AUDIT.md) — security (0 findings) + performance + compliance; all critical/high gaps closed.
- [Full-plugin security audit (2026-06-15)](docs/audits/wwu-wb-full-security-2026-06-15-AUDIT.md) — whole-codebase, 10 dimensions, multi-agent + adversarial verification. **0 critical / 0 high.** 7 dimensions clean (SQLi/XSS/CSRF/IDOR/files/crypto/supply-chain); fixed 1 Medium SSRF (RFC 3161 endpoint → new `Security\OutboundUrlGuard`) + a Low cluster (rate limits, input caps, debug masking, uninstall cron) in alpha.36.

## Analysis
- [Timestamp providers (RFC 3161 + eIDAS)](docs/analysis/wwu-wb-timestamp-providers-ANALYSIS.md) — which trusted-timestamp authorities to add to the pluggable provider (free Sectigo `/qualified`, per-country QTSPs) + PHP integration (2026-06-14).
- [EDD customer surfaces (verified)](docs/analysis/wwu-wb-edd-customer-surfaces-ANALYSIS.md) — the EDD 3.x hooks (source-verified) that render the withdrawal button on the EDD receipt / purchase history / receipt e-mail; documents that the legacy 2.x `edd_payment_receipt_*` hooks were removed from 3.x templates (alpha.35, 2026-06-15).
- [FluentCart hooks (verified)](docs/analysis/wwu-wb-fluentcart-hooks-ANALYSIS.md) — official-source verification of every FluentCart hook/API (custom_endpoints, menu items, dashboard data, order-details slots, Order/Customer models) + the corrections shipped in alpha.19; **+ §"Second verification round" (2026-06-15)** = a direct FluentCart-team reply confirming custom-field submission, the `before_payment_methods` render hook, block-checkout flow, the `product-categories` taxonomy, `getViewUrl('admin')`, `order_paid`, `smartcode_fallback`, `fluent_cart_add_log` — all actioned in alpha.34.

## Testing ([docs/testing/](docs/testing/README.md) — index)
**End-to-end "try the plugin" evaluator guides** (install → button/entry points → two-step flow + no-JS → durable medium e-mail/PDF/verify link → evidence-log integrity → merchant refund + processing → exemptions → uninstall):
- [Try the plugin — WooCommerce](docs/testing/wwu-wb-try-the-plugin-woocommerce-CHECKLIST.md) — 3 button surfaces (My Account orders action / order detail / "Right of withdrawal" tab).
- [Try the plugin — FluentCart](docs/testing/wwu-wb-try-the-plugin-fluentcart-CHECKLIST.md) — 4 portal surfaces (endpoint / sidebar / dashboard banner / per-order button).
- [Try the plugin — EDD](docs/testing/wwu-wb-try-the-plugin-edd-CHECKLIST.md) — 3 surfaces since alpha.35 (purchase receipt / purchase-history rows / receipt e-mail link) + standalone page / payment-key / guest lookup.

**Exemptions consent-capture (Art. 59) focused checklists:**
- [WooCommerce **block** Checkout consent](docs/testing/wwu-wb-woocommerce-block-consent-CHECKLIST.md) — Additional Checkout Fields API (WC 9.9+).
- [**FluentCart** Checkout consent](docs/testing/wwu-wb-fluentcart-consent-CHECKLIST.md) — `before_payment_methods`, category-aware, activity-log note.
- [**EDD** Checkout consent](docs/testing/wwu-wb-edd-consent-CHECKLIST.md) — `edd_purchase_form_before_submit` + `edd_built_order`, `download_category`-aware.
- All are runnable on a staging store by anyone; everything is fail-safe (button stays whenever a surface/capture is unavailable).

## Changelog
- [CHANGELOG](docs/changelog/wwu-wb-CHANGELOG.md)

## Key decisions (quick reference)
| Decision | Choice | Why |
|---|---|---|
| Platforms | WooCommerce (HPOS+legacy) + FluentCart, Day 1, adapter | user requirement |
| PDF library | **Dompdf** (LGPL-2.1) | GPLv3-compatible; mPDF GPL-2.0-only avoided |
| Timestamping | **OpenTimestamps** (free, Bitcoin) + pluggable RFC 3161/eIDAS | free *data certa* now, eIDAS later |
| Log storage | 2 custom tables, append-only **hash chain**, DATETIME | tamper-evidence; Options API can't serve it |
| Applicability | per **consumer country** (Rome I Art. 6); CH = voluntary | follows the consumer, not the seller |
| Labels | statutory defaults per country/locale; override warns | Art. 11a "only words" for confirmation |
| Guest access | signed order-email link + public lookup + receipt to titleholder + rate-limit | coverage without dark-pattern friction |
| MU-plugin | **none** (only multisite-aware activation) | no need to filter active_plugins |
| Distribution | GitHub public now, wordpress.org later | 6-day deadline; review is slow |
