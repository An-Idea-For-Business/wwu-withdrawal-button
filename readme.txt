=== WWU Withdrawal Button ===
Contributors: mredodos, webwakeup, anideaforbusiness
Tags: woocommerce, fluentcart, right of withdrawal, recesso, gdpr
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0-alpha.2
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

The EU online withdrawal button (Art. 11a / Art. 54-bis) for WooCommerce & FluentCart: statutory two-step withdrawal, durable-medium receipt, immutable log.

== Description ==

From 19 June 2026, EU law (Directive (EU) 2023/2673, new Art. 11a of the Consumer Rights Directive; Italy: Art. 54-bis Codice del Consumo) requires online stores to provide a **withdrawal function** that lets consumers withdraw from a distance contract as easily as they concluded it.

WWU Withdrawal Button makes a WooCommerce or FluentCart store compliant out of the box:

* A prominently displayed, legible **withdrawal button** with the exact statutory wording per language (IT, EN, DE, FR, ES — extensible).
* A **two-step flow**: withdrawal statement, then a confirmation labelled only with the statutory words. No dark patterns, no mandatory reason.
* An **acknowledgement of receipt on a durable medium**: immediate email + attached PDF + a permanent verifiable link, with the exact submission date and time.
* A **tamper-evident immutable log** (append-only, hash-chained, with IP and contract data) anchored to OpenTimestamps for free trusted timestamping, with a pluggable RFC 3161 / eIDAS provider.
* **WooCommerce (HPOS + legacy) and FluentCart** support via a common adapter.
* **Compliance documents**: generates the Annex I-B model withdrawal form and ready clauses for Privacy / Terms / pre-contractual information.
* Compatible with **Complianz** and **TranslatePress**; **shortcodes**, **blocks**, hooks and template overrides for customisation.

This plugin is a technical aid to compliance and is **not legal advice**. Have your own counsel review your store's documents.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` and activate it.
2. Activate WooCommerce and/or FluentCart.
3. Go to **Withdrawal Button → Settings**, enable the function, and choose your applicability mode (EU/EEA only is the default).
4. Publish the generated Annex I-B model form and update your Privacy / Terms / pre-contractual information from the Compliance page.

== Frequently Asked Questions ==

= Who must comply? =
Any trader concluding distance B2C contracts via an online interface with EU/EEA consumers, regardless of the trader's own country (Rome I Art. 6). Switzerland-resident consumers are out of scope (voluntary mode).

= Does it replace the model withdrawal form? =
No. The button is **additional** to the Annex I-B model form, which remains mandatory in pre-contractual information. The plugin generates both.

= Is the timestamp legally valid? =
OpenTimestamps provides a free, independently-verifiable Bitcoin-anchored proof. A pluggable RFC 3161 / eIDAS qualified-timestamp provider is available for stronger "data certa".

== Changelog ==

= 1.0.0-alpha.1 =
* Foundation: bootstrap, schema (immutable log + timestamp tables), debug stack, REST diagnostics. Withdrawal flow and durable medium land in the following alpha builds.

== Upgrade Notice ==

= 1.0.0-alpha.1 =
First public foundation build. Not yet production-ready — track progress on GitHub.
