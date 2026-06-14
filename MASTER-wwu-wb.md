# MASTER — WWU Withdrawal Button

> Single index for the **WWU Withdrawal Button** plugin: the EU online right-of-withdrawal function ("withdrawal button", Art. 11a / Art. 54-bis) for WooCommerce & FluentCart. One line per doc; never put content here.

- **Slug:** `wwu-wb` · **Folder:** `wwu-withdrawal-button`
- **Status:** **MVP feature-complete** (F0–F8 + audit hardening), merged to `main`, **released** [`v1.0.0-alpha.27`](https://github.com/An-Idea-For-Business/wwu-withdrawal-button/releases/tag/v1.0.0-alpha.27) — in live testing. Current build `1.0.0-alpha.31` (exemptions management UX — grouped/tooltip'd settings, "what do you sell?" helper, consumer preview, status panel, full it/fr/es/de i18n).
- **Target version:** `1.0.0` · **License:** GPL-3.0-or-later
- **Credits:** mredodos · Matteo Alfieri (An Idea for Business) · WebWakeUp ([webwakeup.it](https://webwakeup.it))
- **Product page (live):** [webwakeup.it/wwu-withdrawal-button](https://webwakeup.it/wwu-withdrawal-button/)
- **Legal go-live:** **2026-06-19** (contracts concluded on/after)
- **Last updated:** 2026-06-14 (exemptions management UX + full i18n shipped — alpha.31)

## What it is (one paragraph)
A free, open-source WordPress plugin that makes a store compliant with Directive (EU) 2023/2673 (new Art. 11a of the Consumer Rights Directive 2011/83/EU; Italy: Art. 54-bis Codice del Consumo via D.Lgs. 209/2025): a prominently displayed, continuously available, statutory-labelled withdrawal button → two-step statement + confirmation → durable-medium acknowledgement (email + PDF + verifiable link) → tamper-evident immutable log anchored to OpenTimestamps. Dual platform (WooCommerce HPOS+legacy / FluentCart), multilingual (IT/EN/FR/ES/DE + extensible), Complianz/TranslatePress-compatible, shortcodes + blocks, plus generators for the Annex I-B model form and Privacy/Terms/pre-contractual clauses.

## Conventions
Namespace `WWU\WithdrawalButton` · constants `WWU_WB_*` · options `wwu_wb_*` · meta `_wwu_wb_*` · REST `wwu-wb/v1` · hooks `wwu_wb_*` · CSS `.wwu-wb-*` · text domain `wwu-withdrawal-button` · JS `window.wwuWbData`.

## Specifications
- [SPEC — EU withdrawal button](docs/specs/wwu-wb-eu-withdrawal-button-SPEC.md) — 12 canonical sections; the authoritative design (2026-06-13).
- [SPEC — Withdrawal exemptions (Art. 59)](docs/specs/wwu-wb-withdrawal-exemptions-SPEC.md) — exempting products/services with the legal consent-capture conditions; exception-type registry + admin UI + **P1–P3 + FluentCart shipped** (alpha.27→alpha.30: tagging, WooCommerce + FluentCart checkout consent capture, durable-medium confirmation, retention/purge, GDPR clause, consent-records view). Only the WooCommerce block-Checkout capture remains (2026-06-14).
- [SPEC — Exemptions management UX](docs/specs/wwu-wb-exemptions-ux-SPEC.md) — **shipped alpha.31**: grouped + tooltip'd exemptions settings (WWU UI Kit accordions), a guided "what do you sell?" helper, consumer-preview of the checkbox + durable-medium e-mail, an exemptions status/health panel, full it/fr/es/de i18n, and a landing section with worked examples (events/recordings/Zoom). Core button untouched (2026-06-14).
- [SPEC — North America subscription-cancellation ("click-to-cancel")](docs/specs/wwu-wb-subscription-cancellation-na-SPEC.md) — design (no code yet) for a DISTINCT click-to-cancel module in the same plugin, jurisdiction-toggled (Quebec Bill 10, BC Bill 4, Ontario/NB pending, US ROSCA + state ARLs incl. California AB 2863), with WooCommerce Subscriptions + FluentCart integration. Grounded in a 5-agent official-source sweep (2026-06-14).

## Legal reference
- [Legal reference (verbatim)](docs/legal/wwu-wb-legal-reference.md) — Art. 11a EN+IT, Recital 37, Art. 54-bis, Annex I-B, per-country labels (DE §356a / FR D.221-5 / ES / CH), Rome I applicability, GDPR.
- [Compliance matrix](docs/legal/wwu-wb-compliance-matrix.md) — clause → feature → test, with the Standard #14 acceptance gate.
- [Exemption-consent evidence & record-keeping](docs/legal/wwu-wb-exemption-consent-evidence-NOTE.md) — official-source + adversarially-verified note (2026-06-14): no named "register" (burden of proof Art. 6(9) CRD + GDPR accountability Art. 5(2)); durable-medium confirmation **constitutive** for the digital exemption (Art. 59(1)(o) CdC / Art. 16(1)(m)+14(4)(b)(iii) CRD); 10-year defensible retention (art. 2946 c.c.) configurable + purge routine; GDPR lawful basis = legitimate interest (Art. 6(1)(f)), not consent. Drives exemptions P3 + the privacy clause.

## Plans
- [Implementation roadmap (PLAN)](docs/plans/wwu-wb-roadmap-PLAN.md) — phases F0–F9 + audits + queued HyperFrames video + post-MVP.

## Audits
- [Core F0–F6 audit (2026-06-13)](docs/audits/wwu-wb-core-2026-06-13-AUDIT.md) — security (0 findings) + performance + compliance; all critical/high gaps closed.

## Analysis
- [Timestamp providers (RFC 3161 + eIDAS)](docs/analysis/wwu-wb-timestamp-providers-ANALYSIS.md) — which trusted-timestamp authorities to add to the pluggable provider (free Sectigo `/qualified`, per-country QTSPs) + PHP integration (2026-06-14).
- [FluentCart customer-portal hooks (verified)](docs/analysis/wwu-wb-fluentcart-hooks-ANALYSIS.md) — official-source verification of every FluentCart hook/API (custom_endpoints, menu items, dashboard data, order-details slots, Order/Customer models) + the corrections shipped in alpha.19 (2026-06-14).

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
