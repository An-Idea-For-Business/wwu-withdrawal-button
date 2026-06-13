# Changelog — WWU Withdrawal Button

All notable changes to this project are documented here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/); the project uses Semantic Versioning.

## [Unreleased]

### F1 — WooCommerce withdrawal flow (1.0.0-alpha.2, 2026-06-13)
- Platform adapter layer: `OrderDataSource` interface + `NormalizedOrder` VO +
  HPOS-safe `WooCommerceAdapter` + `PlatformRegistry`; custom order status
  `wc-wb-requested` (3 status hooks + dual bulk-action hooks).
- Domain: `LabelResolver` (statutory IT/EN/DE/FR/ES labels, override warning,
  DE "no hier" guard), `WindowCalculator` (informational 14-day window, never
  hides the button), `ArticleFiftyNineEvaluator` (per-item exclusions, mixed
  cart), `ApplicabilityResolver` (consumer-country / Rome I, CH voluntary, B2B
  VAT, modes), `WithdrawalRequest` VO, `WithdrawalService` (two-step orchestration).
- Immutable log: `LogChain` (canonical, order-independent hash) + append-only
  `LogRepository` (GET_LOCK-serialised global hash chain + chain verifier).
- Frontend: `WooMyAccount` (orders-list action + order-detail injection +
  account endpoint tab), two-step form template + vanilla JS controller,
  `Assets` (conditional enqueue + Complianz marker), `GuestAccess` (HMAC token +
  rate limit), `Template` loader (theme override + realpath confinement).
- REST: `/withdrawal/lookup`, `/withdrawal/statement`, `/withdrawal/confirm`
  (per-request ownership/key/token access; enumeration-safe errors).
- Smoke suites added: `labels`, `applicability`, `window`, `log`.
- Lint: php -l + node --check clean.

### Planning (2026-06-13)
- Interview completed (4 rounds) and two reconnaissance workflows run (12 + 7 sub-agents) with
  adversarial legal verification against EUR-Lex primary sources.
- **SPEC** written: `docs/specs/wwu-wb-eu-withdrawal-button-SPEC.md` (12 canonical sections).
- **Legal reference** (verbatim Art. 11a EN+IT, Recital 37, Art. 54-bis, Annex I-B, per-country
  labels, Rome I applicability, GDPR): `docs/legal/wwu-wb-legal-reference.md`.
- **Compliance matrix** (clause → feature → test): `docs/legal/wwu-wb-compliance-matrix.md`.
- **Implementation roadmap** (phases F0–F9 + audits + queued video): `docs/plans/wwu-wb-roadmap-PLAN.md`.
- **MASTER** index created; `.gitignore` + `_internal/` (gitignored) scaffolded.
- Key decisions locked: dual platform (WooCommerce + FluentCart) Day 1; PDF = Dompdf (LGPL-2.1,
  GPLv3-compatible); timestamping = OpenTimestamps + pluggable RFC 3161/eIDAS; immutable
  hash-chained append-only log; applicability by consumer country (Rome I); statutory per-country
  labels (DE §356a "Vertrag widerrufen" no-"hier", FR D.221-5, ES direct-effect); no MU-plugin.

> Implementation (Phase F0 onward) has not started yet. The first released version will be `1.0.0`.
