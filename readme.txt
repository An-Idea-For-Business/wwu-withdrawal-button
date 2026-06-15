=== WWU Withdrawal Button ===
Contributors: mredodos, webwakeup, anideaforbusiness
Tags: woocommerce, fluentcart, right of withdrawal, recesso, gdpr
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0-alpha.37
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

The EU online withdrawal button (Art. 11a / Art. 54-bis) for WooCommerce, FluentCart & Easy Digital Downloads: statutory two-step withdrawal, durable-medium receipt, immutable log.

Product page & documentation: https://webwakeup.it/wwu-withdrawal-button/

== Description ==

From 19 June 2026, EU law (Directive (EU) 2023/2673, new Art. 11a of the Consumer Rights Directive; Italy: Art. 54-bis Codice del Consumo) requires online stores to provide a **withdrawal function** that lets consumers withdraw from a distance contract as easily as they concluded it.

WWU Withdrawal Button makes a WooCommerce or FluentCart store compliant out of the box:

* A prominently displayed, legible **withdrawal button** with the exact statutory wording per language (IT, EN, DE, FR, ES — extensible).
* A **two-step flow**: withdrawal statement, then a confirmation labelled only with the statutory words. No dark patterns, no mandatory reason.
* An **acknowledgement of receipt on a durable medium**: immediate email + attached PDF + a permanent verifiable link, with the exact submission date and time.
* A **tamper-evident immutable log** (append-only, hash-chained, with IP and contract data) anchored to OpenTimestamps for free trusted timestamping, with a pluggable RFC 3161 / eIDAS provider.
* **WooCommerce (HPOS + legacy), FluentCart and Easy Digital Downloads (3.0+)** support via a common adapter.
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

= Do digital products lose the right of withdrawal automatically? =
No. The right of withdrawal applies by default, **including** to digital products. It is removed only for the two conditional Art. 59 exemptions (digital content with immediate access; a service fully performed) and only when the consumer gives prior express consent + acknowledgement at checkout. The plugin captures that on the WooCommerce checkout (a required tick-box), stores it as evidence, and only then hides the button — otherwise the button stays (fail-safe). **Physical products never need consent.** For the digital exemption the plugin also e-mails the consumer a durable-medium confirmation, as the law requires.

= Do I have to keep a register of these consents? =
The law does not name a "register", but the burden of proof is on you (Art. 6(9) Dir. 2011/83/EU; GDPR accountability Art. 5(2)) — you must be able to prove the consent. The plugin keeps it for you: the agreed wording, a SHA-256 hash, the date/time and (optionally) the IP are stored on the order and anchored in the tamper-evident log; a **Consent records** admin screen lists and exports them. The IP is anonymised automatically after the retention period.

= Is the timestamp legally valid? =
OpenTimestamps provides a free, independently-verifiable Bitcoin-anchored proof. A pluggable RFC 3161 / eIDAS qualified-timestamp provider is available for stronger "data certa".

== External services ==

This plugin connects to the **OpenTimestamps** public calendar servers to obtain a free, trusted timestamp (a "data certa") for the tamper-evident withdrawal log. This is the default timestamp provider; you can switch it to "none" in the settings to disable all external calls.

- **What is sent:** only a SHA-256 hash (a one-way digest) of the immutable-log record, plus a random privacy nonce. No personal data, order content, names, emails or IP addresses are ever sent — only an opaque hash that cannot be reversed.
- **When:** once when a withdrawal is confirmed (to submit the hash) and periodically via WP-Cron (to retrieve the Bitcoin-anchored proof).
- **Where:** the OpenTimestamps public calendars (a.pool.opentimestamps.org, b.pool.opentimestamps.org, a.pool.eternitywall.com, ots.btc.catallaxy.com).
- **Service info / privacy:** https://opentimestamps.org/

No other external services are used. The plugin does not load remote scripts, fonts or trackers on your site.

== Privacy ==

The plugin records withdrawal declarations (name, identified contract, email, IP address, date and time) in an append-only, tamper-evident log on **your own server**, because Art. 54-bis requires this as legal evidence (GDPR Art. 6(1)(c)/(f)). It generates a ready-to-paste privacy clause for your policy. Data is retained for a configurable period (10 years by default), and the uninstaller keeps the evidence log by default (legal hold) unless you opt to erase it.

For the conditional Art. 59 exemptions, the plugin also stores the consumer's checkout consent + acknowledgement (the agreed wording, a hash, the date/time and — unless you turn it off — the IP) as evidence to prove the exemption is valid. The lawful basis is **legitimate interest** (GDPR Art. 6(1)(f); defence of legal claims), **not** GDPR consent. The IP lives only on the order (never in the immutable log) and is automatically anonymised once the retention period lapses. A second ready-to-paste privacy clause is generated for this processing.

== Changelog ==

= 1.0.0-alpha.37 =
* FluentCart e-mail merge-tag `{{wwu.recesso_url}}` — you can now drop the per-order withdrawal link into FluentCart's own transactional e-mails (the FluentCart team confirmed the value-resolver hook + its data context on 2026-06-15). It's registered in the FluentCart e-mail-editor picker and resolves safely (renders empty when there's no order in context). Needs a live FluentCart test. Note: FluentCart has told us they are shipping a native EU withdrawal feature soon, which may overlap this.

= 1.0.0-alpha.36 =
* Security hardening from a full-plugin security audit (0 critical, 0 high). Fixes: an SSRF guard for the merchant-configured RFC 3161 timestamp endpoint (blocks internal / cloud-metadata / IPv6-loopback / CGNAT targets); per-IP rate limiting on the withdrawal statement/confirm endpoints (REST + no-JS); length caps on the name/reason fields; tighter debug secret-masking; and a cron cleanup on uninstall. No change to the consumer-facing flow. Full report in docs/audits/.

= 1.0.0-alpha.35 =
* **EDD integration completed — the withdrawal button now appears on the EDD customer's own pages.** Easy Digital Downloads customers see the statutory withdrawal button on the **purchase receipt** and in **purchase history**, and the withdrawal link is added to the EDD **purchase-receipt e-mail** — reaching full parity with WooCommerce and FluentCart (previously EDD relied only on the standalone public page). Built on EDD 3.x hooks verified against the official EDD source. Fail-safe as everywhere: the button only shows on eligible orders and links to your withdrawal page pre-authenticated. Needs a live EDD test.

= 1.0.0-alpha.34 =
* FluentCart improvements, verified against a direct FluentCart-team reply: the consent checkbox now renders on `before_payment_methods` (covers the standard, modal **and** block checkout), FluentCart exemptions are now **category-aware** (via the `product-categories` taxonomy, matching WooCommerce and EDD), and withdrawal/refund notes appear in the FluentCart order **activity timeline** (`fluent_cart_add_log`). Also adds 3 shareable **live-test checklists** (WooCommerce block, FluentCart, EDD) under `docs/testing/`. No change to the consumer-facing button.

= 1.0.0-alpha.33 =
* Added **Easy Digital Downloads (EDD 3.0+)** as a third supported platform: the withdrawal button, evidence flow and exemption consent capture now work on EDD stores (with category-aware exemptions). Needs a live EDD test. Consent capture now spans WooCommerce (classic + block), FluentCart and EDD.

= 1.0.0-alpha.32 =
* Exemption consent capture now also works on the WooCommerce **block-based Checkout** (via the official Additional Checkout Fields API, WooCommerce 9.9+), reaching full parity with the classic checkout and FluentCart. Pure PHP, no build step. Also adds the design SPEC for a future Easy Digital Downloads (EDD) integration. Needs a live block-checkout test; fail-safe until verified.

= 1.0.0-alpha.31 =
* Exemptions settings redesigned: the reasons are grouped (conditional / unconditional / seal-based) with tooltips, examples, a "What do you sell?" guided helper, a preview of what the consumer sees (checkbox + confirmation e-mail), and a status panel — all using the WWU UI Kit. Completed the Italian (and FR/ES/DE) translations, including the exemption labels that previously showed in English. The withdrawal button itself is unchanged.

= 1.0.0-alpha.30 =
* Exemptions consent capture now works on **FluentCart** too (checkout acknowledgement + durable-medium confirmation), reaching parity with WooCommerce. Built on FluentCart hooks re-verified against the official docs. FluentCart exemptions match by product ID. The "Open order" admin link now uses FluentCart's own order URL. Needs a live FluentCart test; fail-safe until verified.

= 1.0.0-alpha.29 =
* Exemptions (Art. 59) — durable-medium confirmation + evidence, retention, GDPR. For the conditional exemptions the plugin now e-mails the consumer a durable-medium confirmation reproducing the agreed consent wording (constitutive for digital content, Art. 59(1)(o)) and logs the dispatch separately. Stored consents have a configurable retention (default 10 years) with a daily routine that anonymises the IP afterwards; the IP is configurable and never written to the immutable log. Adds a ready-to-paste GDPR privacy clause (legitimate interest) and a "Consent records" admin page with CSV export. Clearer wording everywhere: physical products never need consent; the button is hidden only after consent is captured (fail-safe).

= 1.0.0-alpha.28 =
* Exemptions (Art. 59) — checkout consent capture. For the two conditional exemptions (digital content with immediate access; service fully performed), WooCommerce checkout now shows a required acknowledgement tick-box and stores the agreed wording (with a SHA-256 hash, timestamp and IP) on the order as evidence — so the button is hidden for those items only once the consumer has lawfully consented. Statutory wording is filterable via `wwu_wb_consent_text`. Classic WooCommerce checkout; the block Checkout and FluentCart capture are tracked follow-ups.

= 1.0.0-alpha.27 =
* Exemptions (Art. 59) — per-reason product/category tagging. Mark products or categories as exempt by a specific statutory reason (custom-made, perishable, sealed hygiene, dated services, digital immediate, service performed, …), each with its legal reference and plain-language guidance. The right of withdrawal stays the default — including digital products — and conditional reasons keep the button until consent is captured.

= 1.0.0-alpha.19 =
* FluentCart customer portal: the "Right of withdrawal" account page, the sidebar entry, the per-order button and the dashboard banner now work. Every FluentCart hook was corrected to the official FluentCart developer contract (verified against dev.fluentcart.com), and the order chooser reads each order's data through the correct customer/address relations. Fixes the blank page and missing button seen in live testing.

= 1.0.0-alpha.18 =
* FluentCart: first cut of the customer-portal withdrawal surfaces, and a platform-agnostic order chooser that merges WooCommerce and FluentCart orders.

= 1.0.0-alpha.17 =
* Withdrawal flow (WooCommerce HPOS + FluentCart), statutory labels (IT/EN/DE/FR/ES), two-step + no-JS fallback, durable-medium acknowledgement (email + PDF + verifiable link), tamper-evident hash-chained log + OpenTimestamps, Annex I-B model form + legal clauses, shortcodes, admin dashboard + compliance page, Complianz/cache compatibility. Security audit: 0 findings.

= 1.0.0-alpha.1 =
* Foundation: bootstrap, schema (immutable log + timestamp tables), debug stack, REST diagnostics.

== Upgrade Notice ==

= 1.0.0-alpha.37 =
FluentCart stores can now use the `{{wwu.recesso_url}}` merge-tag in FluentCart's own e-mails. No change for WooCommerce/EDD. FluentCart users: add the tag to a template and test on staging (FluentCart's own native withdrawal feature is also coming soon).

= 1.0.0-alpha.36 =
Security hardening from a full audit (0 critical/high): SSRF guard on the RFC 3161 endpoint, rate limits on the withdrawal endpoints, input length caps. Recommended for all installs; no behaviour change for consumers.

= 1.0.0-alpha.35 =
EDD stores now show the withdrawal button on the purchase receipt + purchase history, and add the withdrawal link to the EDD receipt e-mail (parity with WooCommerce/FluentCart). Set a public withdrawal page in Settings and re-test the EDD customer flow on staging.

= 1.0.0-alpha.34 =
FluentCart: consent now renders on the block/modal checkout too and is category-aware; order notes appear in the FluentCart timeline. No change for WooCommerce/EDD stores. FluentCart users: re-test the checkout consent on staging (checklist included).

= 1.0.0-alpha.33 =
Adds Easy Digital Downloads (EDD 3.0+) support. No change for WooCommerce/FluentCart stores. If you run EDD, test the checkout + button on staging.

= 1.0.0-alpha.32 =
Adds consent capture on the WooCommerce block Checkout (requires WooCommerce 9.9+ for the conditional field). No change to the classic checkout. Test on staging if you use the block checkout.

= 1.0.0-alpha.31 =
Settings/exemptions UI overhaul + completed Italian/FR/ES/DE translations (the exemption section was partly in English). No change to the withdrawal flow. Safe to update.

= 1.0.0-alpha.30 =
Adds FluentCart checkout consent capture for the conditional exemptions (parity with WooCommerce). Test on a staging FluentCart store before production; fail-safe (the button stays) until the field is verified on your setup.

= 1.0.0-alpha.29 =
Completes the digital/service exemptions: durable-medium confirmation e-mail (required for the digital exemption), configurable consent retention with automatic IP anonymisation, a GDPR privacy clause and a Consent records page. Review the new clause and your retention setting. Test on staging; not yet a stable release.

= 1.0.0-alpha.28 =
Adds lawful consent capture at checkout for digital-immediate and service-performed exemptions. If you exempt those product types, the button is now hidden only after the consumer ticks the required acknowledgement. Test on staging; not yet a stable release.

= 1.0.0-alpha.19 =
Recommended for FluentCart stores: fixes the customer-account withdrawal page (was blank) and the per-order button. Test on staging before production; not yet a stable release.

= 1.0.0-alpha.17 =
Feature-complete alpha. Test thoroughly on staging before production; not yet a stable release.
