<!--
  README — WWU Withdrawal Button
  Comments and documentation are in English (project convention).
-->
<h1 align="center">WWU Withdrawal Button</h1>

<p align="center">
  <strong>The EU "withdrawal button" for WooCommerce &amp; FluentCart — free &amp; open source.</strong><br>
  Implements the online right-of-withdrawal function required by
  <a href="https://eur-lex.europa.eu/eli/dir/2023/2673/oj/eng">Directive (EU) 2023/2673</a>
  (Art. 11a of the Consumer Rights Directive 2011/83/EU) — in Italy
  <strong>Art. 54-bis del Codice del Consumo</strong> (D.Lgs. 209/2025).
</p>

<p align="center">
  <a href="#"><img alt="License: GPL v3" src="https://img.shields.io/badge/License-GPLv3-blue.svg"></a>
  <img alt="Status" src="https://img.shields.io/badge/status-alpha%20(building)-orange.svg">
  <img alt="Applies from" src="https://img.shields.io/badge/applies%20from-19%20June%202026-critical.svg">
</p>

> ⚠️ **The obligation applies to distance contracts concluded on or after 19 June 2026.** This is a technical aid to compliance, **not legal advice** — have your own counsel review your store's documents.

🌐 **Product page & docs:** [webwakeup.it/wwu-withdrawal-button](https://webwakeup.it/wwu-withdrawal-button/)

---

## Why this exists

From **19 June 2026**, any online store selling to consumers in the EU/EEA must let them **withdraw from a contract just as easily as they concluded it**: a prominently displayed, legible **"withdrawal button"**, a two-step confirmation, and an **acknowledgement of receipt on a durable medium** recording the exact date and time. Non-compliance in Italy can mean **AGCM fines up to €10,000,000 or 4% of turnover**, void clauses, and *ex officio* action.

This plugin makes a WooCommerce or FluentCart store compliant **out of the box**.

## What it does

- ✅ **Statutory withdrawal button** in the customer's order area, with the exact legal wording per language (IT `recedere dal contratto qui`, EN `withdraw from contract here`, DE `Vertrag widerrufen`, FR `renoncer au contrat ici`, ES `desistir del contrato aquí`).
- ✅ **Two-step flow** (statement → `conferma recesso`) — no dark patterns, no mandatory reason, as easy as buying.
- ✅ **Durable-medium acknowledgement**: immediate email + attached **PDF** + a permanent verifiable link, reproducing the statement content and the precise submission timestamp.
- ✅ **Tamper-evident immutable log** (append-only, hash-chained, IP + contract data + timestamps) anchored to **OpenTimestamps** (free, Bitcoin-backed *data certa*), with a pluggable RFC 3161 / eIDAS provider.
- ✅ **WooCommerce (HPOS + legacy) and FluentCart** via a common adapter.
- ✅ **Multilingual** (IT/EN/FR/ES/DE, extensible) and **jurisdiction-aware** (DE §356a, FR D.221-5, ES direct-effect; Switzerland handled as voluntary; applicability follows the **consumer's country** per Rome I Art. 6).
- ✅ **Compliance documents**: generates the **Annex I-B model withdrawal form** (multilingual) and ready clauses for Privacy Policy / Terms / pre-contractual info.
- ✅ **Plays nicely** with **Complianz** and **TranslatePress**; excluded from page cache where needed; **shortcodes + blocks + hooks + template overrides** for customisation.

## Status

🚧 **In active development.** This repository currently contains the full design + legal analysis (see [`docs/`](docs/)) and the **F0 foundation** (bootstrap, schema, debug stack, REST diagnostics). The withdrawal flow, durable medium, log chain and compliance documents are landing phase by phase — see the [roadmap](docs/plans/wwu-wb-roadmap-PLAN.md).

## Known issues & limitations

We document limitations openly. None of these block the legal compliance core — the withdrawal button, two-step flow, durable-medium acknowledgement, verifiable link and immutable log all work on both platforms.

- **FluentCart native emails can't carry a withdrawal merge-tag (deferred enhancement).** We'd like to inject the withdrawal link into FluentCart's *own* transactional emails (e.g. the order receipt) via a merge tag like `{{wwu.recesso_url}}`. FluentCart (as of June 2026) exposes `fluent_cart/editor_shortcodes` to list a tag in the email editor, but **no documented hook to resolve the tag's value at send time** — so a tag would render literally. We removed the attempt rather than ship something broken. **This is not a broken feature:** the plugin sends **its own** acknowledgement email on withdrawal confirmation (it falls back to a standalone `wp_mail` sender when WooCommerce's email system isn't present), and the consumer reaches the withdrawal from the FluentCart account **"Right of withdrawal"** page and the standalone public page. Tracked to revisit if/when FluentCart documents a value-resolver hook. (See [`docs/analysis/wwu-wb-fluentcart-hooks-ANALYSIS.md`](docs/analysis/wwu-wb-fluentcart-hooks-ANALYSIS.md).)
- **FluentCart customer portal is a Vue SPA.** In-portal styling of the order chooser is best-effort (the portal's shortcode tag isn't documented for asset detection); the chooser rows and the per-order button link to the standalone page where the plugin's CSS/JS always load, so the flow works regardless.
- **PDF receipt needs Dompdf.** If you install from source without building the bundled `vendor/` (`composer install`), PDF generation is skipped and the durable medium is **email-only** — which still satisfies the obligation (the email carries the full textual acknowledgement). Build the vendor dir to enable the attached PDF.
- **Digital products show the button by default.** Since `1.0.0-alpha.24` the Art. 59 digital auto-exclusion defaults **off** — the right of withdrawal is the default and the button shows on digital products too. The digital-content exemption (Art. 59 lett. o) is only legally valid when prior express consent + acknowledgement were captured; a future *Exemptions* feature ([SPEC](docs/specs/wwu-wb-withdrawal-exemptions-SPEC.md)) lets merchants configure that correctly. Until then, exclude specific products/categories via the `wwu_wb_excluded_product_ids` filter if needed.

## Documentation

| Doc | What |
|---|---|
| [SPEC](docs/specs/wwu-wb-eu-withdrawal-button-SPEC.md) | Full technical specification (12 sections) |
| [Legal reference](docs/legal/wwu-wb-legal-reference.md) | Verbatim statutory text (Art. 11a EN+IT, Art. 54-bis, Annex I-B, per-country labels) |
| [Compliance matrix](docs/legal/wwu-wb-compliance-matrix.md) | Every legal obligation → feature → test |
| [Roadmap](docs/plans/wwu-wb-roadmap-PLAN.md) | Phased implementation plan |
| [MASTER index](MASTER-wwu-wb.md) | One-page project index |

## Requirements

- WordPress 5.8+ · PHP 7.4+
- WooCommerce 5.0+ **and/or** FluentCart
- For contributors: Composer (to build the bundled Dompdf vendor) and Node (block editor build).

## Installation (from source, pre-release)

```bash
git clone https://github.com/An-Idea-For-Business/wwu-withdrawal-button.git
cd wwu-withdrawal-button
composer install --no-dev      # builds vendor/ (Dompdf, LGPL-2.1)
# then copy/symlink the folder into wp-content/plugins/ and activate
```

A packaged ZIP will be attached to each [GitHub Release](../../releases) once the first stable build ships.

## Contributing

Contributions are very welcome — this is a public-interest compliance tool. Please read **[CONTRIBUTING.md](CONTRIBUTING.md)** and our **[Code of Conduct](CODE_OF_CONDUCT.md)**. Good first issues are labelled `good first issue`. Security reports: see **[SECURITY.md](SECURITY.md)**.

## Authors & credits

Built and maintained by **[mredodos](https://github.com/mredodos)**, **Matteo Alfieri — An Idea for Business**, and **[WebWakeUp](https://webwakeup.it)**.

## License

[GPL-3.0-or-later](LICENSE). Bundled library: Dompdf (LGPL-2.1, GPLv3-compatible).
