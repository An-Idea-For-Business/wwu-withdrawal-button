# Changelog — WWU Withdrawal Button

All notable changes to this project are documented here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/); the project uses Semantic Versioning.

## [Unreleased]

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
