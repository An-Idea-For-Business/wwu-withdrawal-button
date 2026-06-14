# MASTER — WWU Withdrawal Button

> Single index for the **WWU Withdrawal Button** plugin: the EU online right-of-withdrawal function ("withdrawal button", Art. 11a / Art. 54-bis) for WooCommerce & FluentCart. One line per doc; never put content here.

- **Slug:** `wwu-wb` · **Folder:** `wwu-withdrawal-button`
- **Status:** **MVP feature-complete** (F0–F8 + audit hardening) on branch `claude/mvp-implementation` ([PR #1](https://github.com/An-Idea-For-Business/wwu-withdrawal-button/pull/1)) — in live testing. Current build `1.0.0-alpha.19`.
- **Target version:** `1.0.0` · **License:** GPL-3.0-or-later
- **Credits:** mredodos · Matteo Alfieri (An Idea for Business) · WebWakeUp ([webwakeup.it](https://webwakeup.it))
- **Legal go-live:** **2026-06-19** (contracts concluded on/after)
- **Last updated:** 2026-06-14

## What it is (one paragraph)
A free, open-source WordPress plugin that makes a store compliant with Directive (EU) 2023/2673 (new Art. 11a of the Consumer Rights Directive 2011/83/EU; Italy: Art. 54-bis Codice del Consumo via D.Lgs. 209/2025): a prominently displayed, continuously available, statutory-labelled withdrawal button → two-step statement + confirmation → durable-medium acknowledgement (email + PDF + verifiable link) → tamper-evident immutable log anchored to OpenTimestamps. Dual platform (WooCommerce HPOS+legacy / FluentCart), multilingual (IT/EN/FR/ES/DE + extensible), Complianz/TranslatePress-compatible, shortcodes + blocks, plus generators for the Annex I-B model form and Privacy/Terms/pre-contractual clauses.

## Conventions
Namespace `WWU\WithdrawalButton` · constants `WWU_WB_*` · options `wwu_wb_*` · meta `_wwu_wb_*` · REST `wwu-wb/v1` · hooks `wwu_wb_*` · CSS `.wwu-wb-*` · text domain `wwu-withdrawal-button` · JS `window.wwuWbData`.

## Specifications
- [SPEC — EU withdrawal button](docs/specs/wwu-wb-eu-withdrawal-button-SPEC.md) — 12 canonical sections; the authoritative design (2026-06-13).
- [SPEC — Withdrawal exemptions (Art. 59)](docs/specs/wwu-wb-withdrawal-exemptions-SPEC.md) — design (no code yet) for exempting products/services with the legal consent-capture conditions; admin UI + exception-type registry (2026-06-14).

## Legal reference
- [Legal reference (verbatim)](docs/legal/wwu-wb-legal-reference.md) — Art. 11a EN+IT, Recital 37, Art. 54-bis, Annex I-B, per-country labels (DE §356a / FR D.221-5 / ES / CH), Rome I applicability, GDPR.
- [Compliance matrix](docs/legal/wwu-wb-compliance-matrix.md) — clause → feature → test, with the Standard #14 acceptance gate.

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
