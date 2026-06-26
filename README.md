<!--
  README — WWU Withdrawal Button
  Comments and documentation are in English (project convention).
-->
<h1 align="center">WWU Withdrawal Button</h1>

<p align="center">
  <strong>The EU "withdrawal button" for WooCommerce, FluentCart &amp; Easy Digital Downloads — free &amp; open source.</strong><br>
  Implements the online right-of-withdrawal function required by
  <a href="https://eur-lex.europa.eu/eli/dir/2023/2673/oj/eng">Directive (EU) 2023/2673</a>
  (Art. 11a of the Consumer Rights Directive 2011/83/EU) — in Italy
  <strong>Art. 54-bis del Codice del Consumo</strong> (D.Lgs. 209/2025).
</p>

<p align="center">
  <a href="#"><img alt="License: GPL v3" src="https://img.shields.io/badge/License-GPLv3-blue.svg"></a>
  <img alt="Status" src="https://img.shields.io/badge/status-alpha%20v1.3.0--alpha.5-orange.svg">
  <img alt="Applies from" src="https://img.shields.io/badge/applies%20from-19%20June%202026-critical.svg">
</p>

> ⚠️ **The obligation applies to distance contracts concluded on or after 19 June 2026.** This is a technical aid to compliance, **not legal advice** — have your own counsel review your store's documents.

🌐 **Product page & docs:** [webwakeup.it/wwu-withdrawal-button](https://webwakeup.it/wwu-withdrawal-button/)

---

## Why this exists

From **19 June 2026**, any online store selling to consumers in the EU/EEA must let them **withdraw from a contract just as easily as they concluded it**: a prominently displayed, legible **"withdrawal button"**, a two-step confirmation, and an **acknowledgement of receipt on a durable medium** recording the exact date and time. Non-compliance in Italy can mean **AGCM fines up to €10,000,000 or 4% of turnover**, void clauses, and *ex officio* action.

This plugin makes a WooCommerce, FluentCart or Easy Digital Downloads store compliant **out of the box**.

## What it does

**How it works (the customer's experience):** the customer opens their order and clicks the statutory **"withdraw from contract here"** button — in their account, from a link in the order e-mail, or on a public page with guest lookup (order number + e-mail, no account needed). A **two-step form** appears: they review what they're withdrawing from (optionally only *some* items — partial withdrawal), then confirm — no reason required, no hoops. The instant they confirm they receive an **acknowledgement of receipt on a durable medium** (e-mail + PDF + a permanent verifiable link, with the exact date and time) and the order is flagged *withdrawal requested*. Every step is written to a **tamper-evident, timestamped log**, and you handle the refund as usual (logged too). Everything below makes that flow correct, easy to run, and defensible.

- ✅ **Statutory withdrawal button** in the customer's order area, with the exact legal wording per language (IT `recedere dal contratto qui`, EN `withdraw from contract here`, DE `Vertrag widerrufen`, FR `renoncer au contrat ici`, ES `desistir del contrato aquí`).
- ✅ **Two-step flow** (statement → `conferma recesso`) — no dark patterns, no mandatory reason, as easy as buying.
- ✅ **Durable-medium acknowledgement**: immediate email + attached **PDF** + a permanent verifiable link, reproducing the statement content and the precise submission timestamp.
- ✅ **Tamper-evident immutable log** (append-only, hash-chained, IP + contract data + timestamps) with **optional** trusted-timestamp anchoring — **OpenTimestamps** (free, Bitcoin-backed *data certa*) or a pluggable RFC 3161 / eIDAS provider — **opt-in, off by default** (the log works offline; the plugin prompts you to enable the anchor).
- ✅ **WooCommerce (HPOS + legacy), FluentCart and Easy Digital Downloads (3.0+)** via a common adapter.
- ✅ **Multilingual** (IT/EN/FR/ES/DE, extensible) and **jurisdiction-aware** (DE §356a, FR D.221-5, ES direct-effect; Switzerland handled as voluntary; applicability follows the **consumer's country** per Rome I Art. 6).
- ✅ **Compliance documents**: generates the **Annex I-B model withdrawal form** (multilingual) and ready clauses for Privacy Policy / Terms / pre-contractual info.
- ✅ **Plays nicely** with **Complianz** and **TranslatePress**; excluded from page cache where needed; **shortcodes + blocks + hooks + template overrides** for customisation — with a [documented public hooks & filters API](docs/reference/webwakeupwdb-hooks-filters-REFERENCE.md) for developers.
- ✅ **Partial withdrawal** (since `1.0.0-alpha.42`) — the consumer can withdraw from only *some* products of an order (EU partial withdrawal; leave empty for the whole order); the selection appears on the durable-medium receipt and the merchant's Requests dashboard.
- ✅ **Transparency on exempt orders** (since `1.0.0-alpha.43`) — when an order is exempt under Art. 59, the consumer sees a short note explaining *why* the withdrawal button is absent (the specific statutory exception + legal reference), instead of silence. Editable + fail-safe.
- ✅ **Configurable FluentCart handling** (since `1.0.0-alpha.41`) — FluentCart is shipping its own native withdrawal add-on; a **Settings → FluentCart** mode (Auto / Always / Off) makes this plugin step aside automatically so customers never see two buttons.
- ✅ **Automations: read-only REST API + signed webhook** (since `1.0.0-alpha.44`) — read withdrawal requests (Application-Password auth) and receive an HMAC-signed webhook the instant a withdrawal is confirmed, to wire into Zapier / Make / n8n / a CRM. PII-first (the consumer IP is never exposed); no create-withdrawal endpoint by design. [API reference](docs/reference/webwakeupwdb-rest-api-REFERENCE.md).
- ✅ **Merchant cockpit (no code needed)** — an onboarding **Dashboard** (setup checklist with one-click fixes + a one-click **e-mail delivery test** that detects your SMTP and proves receipts arrive — the #1 "nothing happened" cause), a **Requests dashboard** to manage every withdrawal (status open / processed / refunded, chain-integrity badge, one-click *mark processed* / *resend receipt* / *open the order to refund* with the refund logged as proof), and a **Compliance page** (go-live countdown, statutory labels in use, ready-to-paste clauses, environment warnings for Complianz / cache / multilingual).
- ✅ **Readable verification certificate** — the receipt's verifiable link shows a clear certificate (integrity, order, date/time, hash), not raw code; JSON available for whoever wants it.
- ✅ **Privacy & GDPR by design** — the immutable log commits to an **anonymised IP** while the full IP lives separately and is erased after a configurable retention (10 years by default); a **Consent records** screen lists and exports the Art. 59 exemption consents (CSV); two ready-to-paste privacy clauses are generated (legitimate-interest basis). No trackers, no remote scripts or fonts.
- ✅ **"Update your legal texts too" reminder** (since `1.2.0`) — installing the button does **not** update your own documents. The Dashboard and Compliance page now prominently remind you to amend the withdrawal article in your **Terms & Conditions and pre-contractual information** to describe the new button modality (Art. 6 CRD), and the generated "How to withdraw" / pre-contractual clauses name the button explicitly — so you have the exact text to paste. Since `1.2.1` you can **edit those clauses directly in Settings → Legal clauses** (no code) or via the `webwakeupwdb_clause_text` filter.

## Status

🟢 **Stable.** The full withdrawal flow — statutory button, two-step confirmation, durable-medium acknowledgement (email + PDF + verifiable link), tamper-evident hash-chained log, and the compliance-document generators — ships across WooCommerce, FluentCart and Easy Digital Downloads. Latest stable on GitHub: see the **[latest release](../../releases/latest)** and the [changelog](docs/changelog/webwakeupwdb-CHANGELOG.md). Six locales (IT/EN/DE/FR/ES/SV). The **`1.2.12`** build is in the **WordPress.org** manual-review queue; once approved the latest version ships to the directory via SVN. Recent: **`1.2.0`** added an "update your legal texts too" merchant reminder (Art. 6 CRD); **`1.2.1`** fixed the My Account tab 404 on fresh installs + made the legal clauses editable from Settings; **`1.2.2`** fixed a fatal "critical error" when an SMTP plugin (e.g. WP Mail SMTP) threw inside `wp_mail` during the acknowledgement send, and added coexistence guidance now that **FluentCart 1.4.2** ships its own native withdrawal add-on; **`1.2.3`** makes a failed acknowledgement e-mail report the exact transport reason (e.g. the SMTP plugin's "Could not authenticate") in the admin notice + immutable log, instead of a generic "email failed"; **`1.2.4`** WordPress.org compliance hardening (more distinctive, trademark-free display name; extra input sanitisation, output escaping, explicit REST permission callbacks); **`1.2.5`** restored PHP 7.4 compatibility (pinned the bundled Dompdf to the 7.4-compatible 2.x line), added comma-separated multiple recipients for the notification e-mail, and wired automatic detection of FluentCart's native "Customer Rights" add-on so Auto mode defers to it; **`1.2.12`** is a WordPress.org-compliance build that **removed the Custom CSS field** (restyle via WordPress core's Customizer → Additional CSS), made trusted timestamping **opt-in** (no external calls by default), and moved to **PHP 8.1 + Dompdf 3.1.5** — a PHP 7.4-compatible build of the same features remains on GitHub. See the [roadmap](docs/plans/webwakeupwdb-roadmap-PLAN.md) for what's next.

## Installation

The plugin isn't on the WordPress.org directory **yet** (submission in progress) — install it from the release ZIP:

1. Download **`wwu-withdrawal-button.zip`** from the [latest release](../../releases/latest).
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Select the ZIP → **Install Now** → **Activate**.
4. Make sure **WooCommerce**, **FluentCart** and/or **Easy Digital Downloads** is active (at least one).
5. Open **Withdrawal Button → Settings**, enable the function, and choose your applicability mode (EU/EEA only is the default).
6. From the **Compliance** page, publish the generated **Annex I‑B model form** and update your Privacy / Terms / pre‑contractual information.

**Requirements:** WordPress 5.8+, PHP 8.1+ (tested up to WordPress 7.0). **Still on PHP 7.4 or 8.0?** A separate PHP 7.4-compatible build (Dompdf pinned to the 2.x line, same features) is published in the [GitHub releases](../../releases) — but it is a temporary bridge and **will not be maintained indefinitely**: PHP 7.4 has been end-of-life since November 2022, so plan to move your store to PHP 8.1+ (faster and more secure), after which you get the directory build with automatic updates. Updating later: deactivate the old version and upload the new ZIP the same way — your settings and the evidence log are preserved.

<details>
<summary>Installing from source (developers)</summary>

The repository ships without the compiled PDF library. Build the bundled `vendor/` (Dompdf) first — otherwise the durable medium falls back to **email‑only** (still compliant; the e‑mail carries the full acknowledgement):

```bash
composer install --no-dev --optimize-autoloader
bash bin/build.sh   # → dist/wwu-withdrawal-button.zip (needs rsync + zip)
```

`bin/build.sh` applies `.distignore`, so the ZIP excludes dev files (`_internal/`, `docs/`, `tests/`, `bin/`, …) and bundles `vendor/`.
</details>

## Known issues & limitations

We document limitations openly. None of these block the legal compliance core — the withdrawal button, two-step flow, durable-medium acknowledgement, verifiable link and immutable log all work across all three platforms (WooCommerce, FluentCart, Easy Digital Downloads).

- **Subscriptions (since `1.0.0-alpha.38` — needs a live test).** EU law gives **one** 14-day right of withdrawal **per contract**, at conclusion — a renewal does **not** restart it. The button now shows on the **initial order only** and is suppressed on renewal orders (a single applicability gate, fed by `NormalizedOrder::$is_renewal`, covering all eight surfaces; renewal detection for WooCommerce Subscriptions, FluentCart subscriptions and EDD Recurring is guarded and **fail-open**, so an undetermined state keeps the button visible). Two opt-in toggles live under **Settings → Subscriptions**: *also show on renewals* (off) and *auto-cancel the subscription on withdrawal* (off — the refund and any pro-rata always stay manual). The Requests dashboard flags subscription orders with a reminder. Override detection via `webwakeupwdb_order_is_renewal`. Withdrawal stays distinct from "cancel my subscription" (the latter just stops future renewals; the withdrawal is the statutory 14-day right + refund).
- **FluentCart email merge-tag `{{wwu.recesso_url}}` (since `1.0.0-alpha.37` — needs a live test).** You can drop the per-order withdrawal link into FluentCart's *own* transactional emails (e.g. the order receipt) via the `{{wwu.recesso_url}}` tag. The FluentCart team confirmed (2026-06-15) the value-resolver hook (`fluent_cart/smartcode_fallback`) and its `$data` context, so the tag is now registered in the FluentCart email-editor picker and resolved at send time — with the team's required `$data['order']` safety check (it renders empty in footers / contexts without an order). **Heads-up:** FluentCart told us they are shipping a **native EU withdrawal feature soon**, which may overlap this; our integration stays fail-safe and the consumer can always reach the withdrawal from the FluentCart **"Right of withdrawal"** portal page and the standalone public page regardless. (See [`docs/analysis/webwakeupwdb-fluentcart-hooks-ANALYSIS.md`](docs/analysis/webwakeupwdb-fluentcart-hooks-ANALYSIS.md) §"Third verification round".)
- **FluentCart customer portal is a Vue SPA.** In-portal styling of the order chooser is best-effort (the portal's shortcode tag isn't documented for asset detection); the chooser rows and the per-order button link to the standalone page where the plugin's CSS/JS always load, so the flow works regardless.
- **PDF receipt needs Dompdf.** If you install from source without building the bundled `vendor/` (`composer install`), PDF generation is skipped and the durable medium is **email-only** — which still satisfies the obligation (the email carries the full textual acknowledgement). Build the vendor dir to enable the attached PDF.
- **Digital products show the button by default.** Since `1.0.0-alpha.24` the Art. 59 digital auto-exclusion defaults **off** — the right of withdrawal is the default and the button shows on digital products too. The digital-content exemption (Art. 59 lett. o) is only legally valid when prior express consent + acknowledgement were captured. The **Exemptions** feature ([SPEC](docs/specs/webwakeupwdb-withdrawal-exemptions-SPEC.md)) lets merchants do this correctly: **Settings → Exemptions** tags products/categories by specific statutory reason, and since `1.0.0-alpha.28` the **WooCommerce checkout captures the required consent + acknowledgement** for the conditional reasons (digital-immediate / service-performed) — only then is the button hidden for those items (filterable wording via `webwakeupwdb_consent_text`). Consent capture is **live on every checkout**: WooCommerce **classic** (alpha.28) and **block** Checkout (alpha.32, via the official Additional Checkout Fields API, WC 9.9+), **FluentCart** (alpha.30; since alpha.34 on the `before_payment_methods` hook so it also covers the modal/block checkout, and **category-aware**) and **Easy Digital Downloads** (alpha.33, EDD 3.0+, category-aware — [SPEC](docs/specs/webwakeupwdb-edd-integration-SPEC.md)). All three platforms now match exemptions by **both product and category**. Any path where capture isn't available keeps the button (fail-safe).

## Documentation

| Doc | What |
|---|---|
| [SPEC](docs/specs/webwakeupwdb-eu-withdrawal-button-SPEC.md) | Full technical specification (12 sections) |
| [Legal reference](docs/legal/webwakeupwdb-legal-reference.md) | Verbatim statutory text (Art. 11a EN+IT, Art. 54-bis, Annex I-B, per-country labels) |
| [Compliance matrix](docs/legal/webwakeupwdb-compliance-matrix.md) | Every legal obligation → feature → test |
| [Exemption-consent evidence note](docs/legal/webwakeupwdb-exemption-consent-evidence-NOTE.md) | What the law requires for recording the Art. 59 exemption consents (burden of proof, durable-medium confirmation, retention, GDPR basis) — verified against official sources |
| [Hooks & filters reference](docs/reference/webwakeupwdb-hooks-filters-REFERENCE.md) | The supported public extension API — 34 hooks (filters + actions) with signatures + examples |
| [REST API & webhook reference](docs/reference/webwakeupwdb-rest-api-REFERENCE.md) | The read-only automations API + signed outbound webhook — endpoints, auth, payload, HMAC verification |
| [Roadmap](docs/plans/webwakeupwdb-roadmap-PLAN.md) | Phased implementation plan |
| [Test checklists](docs/testing/README.md) | End-to-end "try the plugin" evaluator guides (WooCommerce / FluentCart / EDD) + exemption consent-capture checklists — runnable on a staging store by anyone |
| [MASTER index](MASTER-webwakeupwdb.md) | One-page project index |

## Requirements

- WordPress 5.8+ · PHP 8.1+ (or the PHP 7.4-compatible build on GitHub)
- WooCommerce 5.0+, FluentCart **and/or** Easy Digital Downloads 3.0+
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
