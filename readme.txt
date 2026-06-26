=== WWU Right of Withdrawal for Popular Ecommerce Platforms ===
Contributors: mredodos, webwakeup, anideaforbusiness
Tags: woocommerce, fluentcart, right of withdrawal, recesso, gdpr
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.3.0-alpha.3
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

EU statutory withdrawal button (Art. 11a) for WooCommerce, FluentCart & EDD: two-step flow, durable-medium receipt, tamper-evident log.

== Description ==

Product page & documentation: https://webwakeup.it/wwu-withdrawal-button/

From 19 June 2026, EU law (Directive (EU) 2023/2673, new Art. 11a of the Consumer Rights Directive; Italy: Art. 54-bis Codice del Consumo) requires online stores to provide a **withdrawal function** that lets consumers withdraw from a distance contract as easily as they concluded it. WWU Withdrawal Button adds that function — and everything around it you need to run it and to prove you did it right — to WooCommerce, FluentCart and Easy Digital Downloads.

= How it works (in plain terms) =

1. An eligible customer opens their order and clicks the statutory **"Withdraw from contract here"** button — in their account, from a link in the order e-mail, or on a public page (no account needed: they look the order up with its number + e-mail).
2. A simple **two-step form** appears: they review what they are withdrawing from (optionally ticking only some items — partial withdrawal is allowed), then confirm. No reason required, no hoops.
3. The instant they confirm, the customer receives an **acknowledgement of receipt on a durable medium** — an e-mail, a PDF copy and a permanent verifiable link — showing exactly what was withdrawn and the precise date and time. The order is flagged "withdrawal requested".
4. Every step is written to a **tamper-evident, append-only log** (hash-chained and timestamped) so you can prove what happened and when. You then handle the refund as usual — the plugin records that too.

That is the whole customer experience. Everything below exists to make it correct, easy to run, and defensible.

= For your customers =

* A prominently displayed, legible button with the **exact statutory wording per language** (IT, EN, DE, FR, ES, SV — extensible).
* The button appears where customers actually look: the **account area** (order list, order detail, a dedicated "Right of withdrawal" tab), a **link inside order e-mails**, a **public self-service page** with guest lookup, and anywhere via **shortcodes** or the **Gutenberg block**.
* A short, reassuring **step-by-step guide** during the flow (timing, refund, returns); the wording and the withdrawal window (≥14 days — you may grant more) are editable.
* When an order is genuinely exempt, a clear **"why is the button not here" note** explains the specific legal exception, instead of leaving the customer confused.
* A human-readable **verification certificate** for the receipt (integrity, order, date, hash) — not raw code.

= For you (the merchant) =

* An onboarding **Dashboard** with a setup checklist (one-click fixes), a plain "how it works" walkthrough, and a "where the button appears / why it might not" explainer.
* A one-click **e-mail delivery test** that detects your SMTP plugin and proves the receipt actually reaches the inbox — the #1 cause of "nothing happened".
* A **Requests dashboard** to manage every withdrawal: status (open / processed / refunded), a chain-integrity badge, and one-click **mark processed**, **resend receipt** and **open the order to refund** (the refund is logged as proof you met the 14 days). Subscription and partial-withdrawal requests are flagged.
* A **Compliance page**: a go-live countdown, the statutory labels in use, the document checklist with ready-to-paste clauses, and environment warnings (Complianz / cache / multilingual) to fix.
* Receipts are **real WooCommerce e-mails** (your logo, colours, header) with a preview. The withdrawal button and form **inherit your theme's typography and colour presets** out of the box, so they blend in automatically; for finer control, restyle every part from **Appearance → Customize → Additional CSS** (built into WordPress), targeting the plugin's documented CSS variables and classes.

= Smart legal handling (so you don't have to think about it) =

* **Subscriptions** — the law gives one 14-day right per contract, so the button shows on the **initial order only** and is hidden on renewals (WooCommerce Subscriptions, FluentCart, EDD Recurring). Fail-safe, with opt-in overrides.
* **Partial withdrawal** — customers can withdraw from only some items of an order.
* **Art. 59 exemptions** — tag products or categories by the specific statutory reason (events on a fixed date, digital content with immediate access, a service fully performed…). For the conditional reasons the plugin captures the customer's **express consent at checkout** (WooCommerce classic + block, FluentCart, EDD), stores it as evidence, sends the required durable-medium confirmation, and only then hides the button. **Physical products always keep the right** — never hidden by mistake.
* **Applicability by country** — EU/EEA consumers only (default) or always; B2B (VAT) orders can be treated as out of scope.

= Evidence, timestamps & integrity =

* The immutable log is **append-only and hash-chained** (HMAC-keyed with your site secret), so tampering is detectable.
* **Recommended** trusted timestamping (off by default — one click to enable): free, independently-verifiable **OpenTimestamps** (Bitcoin) anchoring, or a **qualified eIDAS RFC 3161** timestamp (a free Sectigo endpoint, or your national authority — Aruba, InfoCert, D-Trust, Universign, FNMT, SwissSign), for an independent **"data certa"** of when each withdrawal was received. The hash chain is the baseline evidence on its own; once you enable a provider, failed stamps retry automatically and any not-yet-anchored records are surfaced in the admin. (It is off by default only because WordPress.org requires external calls to be opt-in — the plugin prompts you to switch it on.)

= Privacy & GDPR =

* The log commits to an **anonymised IP**; the full IP lives separately and is **erased after a configurable retention** (10 years by default).
* A **Consent records** screen lists and exports the exemption consents (CSV). Two ready-to-paste privacy clauses are generated (withdrawal log + exemption-consent), on a legitimate-interest basis. The uninstaller keeps the evidence log by default (legal hold) unless you opt to erase it.

= Documents & compliance =

* Generates the **Annex I-B model withdrawal form** and ready clauses for **pre-contractual information, Terms & Conditions and Privacy** — and reminds you, clearly, that installing the button is **not enough**: your Terms and pre-contractual withdrawal article must be updated to describe the new button modality (the plugin gives you the exact text to paste).

= Integrations & automation =

* A **read-only REST API** (authenticated with a standard Application Password) to list requests and check an order's withdrawal status, plus an optional **signed webhook** (HMAC-SHA256) fired the moment a withdrawal is confirmed — for Zapier, Make, n8n, a CRM or a helpdesk. Privacy-first: the consumer's IP is never exposed. **33 documented hooks/filters** for developers.
* Plays nicely with **Complianz**, **TranslatePress** and page-cache plugins (WP Rocket / LiteSpeed / W3TC).

= Platforms & licence =

* **WooCommerce (HPOS + legacy), FluentCart and Easy Digital Downloads (3.0+)** through a common adapter — one plugin for all three. On FluentCart it can step aside automatically if FluentCart ships its own native withdrawal add-on, so customers never see two buttons.
* **Free and open source** (GPLv3) — no upsell, no tracking, no remote scripts or fonts loaded on your site. Passed a full multi-dimension security audit (0 critical / 0 high).

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

= Is the timestamp legally valid? Should I enable it? =
Yes — and we recommend you do. A trusted timestamp gives you an independent **"data certa"**: proof of the exact moment a withdrawal was received, which is the fact the statutory 14-day deadline turns on and the hardest thing to prove after the fact. **OpenTimestamps** is free, needs no account, and provides an independently-verifiable Bitcoin-anchored proof; a pluggable **RFC 3161 / eIDAS** qualified-timestamp provider is available for the strongest "data certa". It is **off by default** (WordPress.org requires external connections to be opt-in) and only an anonymous one-way hash is ever sent — never personal data — so turn it on in **Settings → Receipt & evidence** (the Dashboard checklist links you straight there).

= Which PHP version do I need? What about PHP 7.4? =
The build in the WordPress.org directory requires **PHP 8.1+** (it bundles the latest Dompdf 3.x PDF engine, whose dependencies need 8.1). If your host still runs **PHP 7.4 or 8.0**, the directory simply will not offer you this update — install the **PHP 7.4-compatible build** from our GitHub releases instead (identical features, Dompdf pinned to the 2.x line). That legacy build is a courtesy bridge and **will not be maintained forever**: PHP 7.4 reached end-of-life in November 2022, so please plan to move your store to PHP 8.1+ (it is faster and more secure), after which you get the directory version with automatic updates.

== External services ==

This plugin makes **no external calls by default**. Every outbound connection listed below is **opt-in** and stays **off** until you explicitly enable it in the settings. The tamper-evident log works fully offline — it is append-only and hash-chained with your site secret — so timestamping only *adds* an extra, independently-verifiable anchor; it is never required for the plugin to function.

**OpenTimestamps** (opt-in, off by default) — only if you set the timestamp provider to "OpenTimestamps" (Settings → Receipt & evidence) does the plugin connect to the OpenTimestamps public calendar servers to obtain a free, trusted timestamp (a "data certa") for the log.

- **What is sent:** only a SHA-256 hash (a one-way digest) of the immutable-log record, plus a random privacy nonce. No personal data, order content, names, emails or IP addresses are ever sent — only an opaque hash that cannot be reversed.
- **When:** only while the provider is enabled — once when a withdrawal is confirmed (to submit the hash) and periodically via WP-Cron (to retrieve the Bitcoin-anchored proof). Nothing is ever sent while the provider is "None" (the default).
- **Where:** the OpenTimestamps public calendars (a.pool.opentimestamps.org, b.pool.opentimestamps.org, a.pool.eternitywall.com, ots.btc.catallaxy.com).
- **Service info / privacy:** https://opentimestamps.org/

**RFC 3161 / eIDAS timestamp authority** (opt-in, off by default) — if you instead set the provider to an RFC 3161 authority, the same one-way SHA-256 hash (no personal data) is sent to the authority URL **you** configure. This is a provider you choose and contract with directly (examples: a free Sectigo endpoint, or a national authority such as Aruba, InfoCert, D-Trust, Universign, FNMT, SwissSign); please review that provider's own terms of service and privacy policy. No such call is made until you enable it.

**Outbound webhook** (opt-in, off by default — Settings → Integrations) — if enabled, the plugin sends a signed POST to the endpoint URL **you** specify whenever a withdrawal is confirmed. The payload carries a verification hash and contract reference, never the consumer's IP address.

No other external services are used. The plugin does not load remote scripts, fonts or trackers on your site.

== Privacy ==

The plugin records withdrawal declarations (name, identified contract, email, IP address, date and time) in an append-only, tamper-evident log on **your own server**, because Art. 54-bis requires this as legal evidence (GDPR Art. 6(1)(c)/(f)). It generates a ready-to-paste privacy clause for your policy. Data is retained for a configurable period (10 years by default), and the uninstaller keeps the evidence log by default (legal hold) unless you opt to erase it.

For the conditional Art. 59 exemptions, the plugin also stores the consumer's checkout consent + acknowledgement (the agreed wording, a hash, the date/time and — unless you turn it off — the IP) as evidence to prove the exemption is valid. The lawful basis is **legitimate interest** (GDPR Art. 6(1)(f); defence of legal claims), **not** GDPR consent. The IP lives only on the order (never in the immutable log) and is automatically anonymised once the retention period lapses. A second ready-to-paste privacy clause is generated for this processing.

== Changelog ==

= 1.3.0-alpha.3 =
* **Italian translation of the new legal-documents strings + an i18n refresh** (Phase C). The consolidated policy, the Complianz toggles, the freeze / recreate buttons and the reworded timestamp copy are now in Italian (it_IT ~99%). German, French and Spanish carry the new strings untranslated for now (coming via the same pipeline); Swedish awaits a native review.

= 1.3.0-alpha.2 =
* **New — add the withdrawal clauses to your Complianz documents** (Phase B). If you use Complianz, two opt-in toggles under **Settings → Complianz documents** append the right-of-withdrawal clauses to your **Privacy Policy** (always) and **Terms & Conditions** (when Complianz's free Terms & Conditions add-on is installed). Off by default, **EU only**, with a live "what will be added" preview — turning a toggle off removes the clauses again on the next regeneration. They **complement, not replace** your own legal texts (have your lawyer review them).

= 1.3.0-alpha.1 =
* **New — a consolidated "Right of withdrawal" information notice** (Phase A of the Legal Documents feature). One notice is assembled live from your settings and the exceptions you selected, and delivered three ways: the `[wwu_wb_policy]` shortcode, an auto-created **draft page** (one click to recreate if you delete it), and a downloadable **PDF**. Manage it under **Compliance → "Informativa sul diritto di recesso"**: preview it, create/open the page, **freeze** it to static HTML, or download the PDF. It **complements — it does not replace** — your Terms; a disclaimer says so on every surface. Pre-release for testing; the Complianz injection and the translation tooling arrive in the next alphas.

= 1.2.13 =
* **Partial withdrawal: choose a quantity per item.** When you withdraw from only some products and you bought more than one of an item, you can now enter **how many** to withdraw (e.g. 1 of 3) — an optional number field next to each line, no JavaScript required; leave it blank to withdraw the whole line. The chosen quantity appears on the durable-medium receipt (email + PDF), in the Requests dashboard and in the read-only REST API. Informational only (you still process the refund). Fully back-compatible: the existing `products` data is unchanged — a new additive `product_quantities` field carries the amounts. Requested in issue #47.

= 1.2.12 =
* **Now requires PHP 8.1** (was 7.4). This build bundles the latest stable PDF engine, **Dompdf 3.1.5**, whose dependencies need PHP 8.1. Sites still on PHP 7.4–8.0 are not offered this update and stay on 1.2.11; a PHP 7.4-compatible build of the same features is published on GitHub.
* **Custom CSS field removed.** To restyle the withdrawal flow, use WordPress core's **Appearance → Customize → Additional CSS**, targeting the plugin's documented CSS variables and classes (a full reference is shown in Settings → Appearance). The plugin no longer processes arbitrary user CSS.
* **No external calls by default.** Trusted timestamping (OpenTimestamps / RFC 3161) is now **opt-in and off by default** — the plugin makes no outbound connection unless you enable a provider in Settings → Receipt & evidence. The append-only, hash-chained evidence log works fully offline. (WordPress.org review compliance: opt-in consent + no arbitrary code insertion.)
* Updated the bundled Dompdf PDF library from 2.0.8 to the latest stable **3.1.5**.

= 1.2.11 =
* **Consent Records page is now cross-platform (fixes #41).** The admin Consent Records screen and its CSV export only read WooCommerce orders, so on an Easy Digital Downloads or FluentCart store they showed the "WooCommerce not active" notice even though consent was being captured. The page now sources its records from the **tamper-evident, cross-platform evidence log** (every platform's checkout-consent capture already writes there), so WooCommerce, EDD and FluentCart consents all appear; the full per-entry evidence (including the IP) is read back from the order when it still exists, and survives as a PII-free record if the order was later deleted. A new "Platform" column is shown. This is strictly a read-only change — the append-only, hash-chained evidence log is never altered.

= 1.2.10 =
* **WordPress.org compliance pass (no behaviour change).** Added `wp_unslash()` to the inputs read by the settings-save handler — the values were already sanitised (custom sanitisers, integer casts, `sanitize_*` / `esc_url_raw`), this just adds the WordPress-canonical unslash step the Plugin Check tool expects. Silenced a false-positive "query not prepared" error on the integrity-check read (it has no user input: the table name comes from `$wpdb->prefix` and the row limit is an integer cast, so there is nothing to prepare). Trimmed three over-long upgrade notices to the 300-character limit. No change to the withdrawal flow, your data or the evidence log.

= 1.2.9 =
* **Type-aware withdrawal window (informational).** The 14-day countdown shown to the customer is now calculated by product type: all-digital orders (every item virtual/downloadable) start from the order date — the conclusion of the contract, per Art. 9 of Directive 2011/83/EU and Art. 52 of the Italian Codice del Consumo — while any order containing a physical item is unchanged (the completed/delivery date, falling back to the order date). The window stays informational: the button is never hidden on this value, and you decide the validity of late requests. No breaking changes.

= 1.2.8 =
* **Guest withdrawal link fixed on every surface.** Completes the 1.2.7 fix. The `[wwu_wb_button]` / `[wwu_wb_form]` shortcodes and the WooCommerce order-actions link now also route guests (customers without an account) to the public withdrawal page instead of the login-gated account area — a shared helper builds the URL the same way everywhere, so a guest is never sent to the login screen regardless of which surface shows the link (some themes render the order-actions on the order-received page). Logged-in customers are unaffected; no change to the flow, your data or the evidence log.

= 1.2.7 =
* **Guest withdrawal button no longer asks for login.** The withdrawal button shown in the order recap / thank-you page sent guest (no-account) customers to the login screen, because it pointed at the My Account area. Guests are now routed to the public withdrawal page, carrying the order reference + order key (the same pre-authenticated link the order e-mail already uses), so they can withdraw without an account. Logged-in customers are unaffected.

= 1.2.6 =
* **Translations completed for all bundled locales.** Several admin strings added in recent releases — the "Legal clauses" editor, the "Notification email(s)" field, the legal-texts reminders on the Compliance page and the FluentCart e-mail helper — were showing in English because they had not been translated yet. All five bundled language files (Italian, German, French, Spanish, Swedish) are now complete for these strings, so those screens display in your language. (The Swedish strings were machine-assisted and are pending a native review.)

= 1.2.5 =
* **PHP 7.4 compatibility fixed (PDF library).** The bundled PDF engine (Dompdf) had been updated to a 3.x release that requires PHP 8.1, which contradicted the plugin's "Requires PHP 7.4" and produced a Composer "platform" error near the PDF option on PHP 7.4 sites. Dompdf is now pinned to the 7.4-compatible 2.x line (PHP 7.1+), so the plugin runs on PHP 7.4 again with no change to the PDF receipts. (Thanks to the reporter of issue #31.)
* **Notification e-mail now accepts multiple recipients.** Settings → "Notification email(s)" accepts several addresses separated by commas, so the "new withdrawal request" alert can reach more than one person. The first address is also shown to the customer as the shop contact. A single address keeps working exactly as before.
* **FluentCart coexistence is now automatic.** FluentCart shipped its own free "Customer Rights" add-on. With the FluentCart handling left on Auto (the default), the plugin now detects that add-on automatically and steps aside on FluentCart, so customers never see two withdrawal flows. WooCommerce and EDD are unaffected; you can still force the behaviour from Settings → FluentCart.

= 1.2.4 =
* **Housekeeping + WordPress.org compliance hardening.** Display name refined to "WWU Right of Withdrawal" (the plugin slug is unchanged, so nothing breaks on update). Additional input sanitisation on the rate-limiter, URL escaping tightened in the plain-text e-mails, explicit REST permission callbacks declared on the public withdrawal endpoints, and the now-unneeded textdomain loader removed (WordPress loads translations automatically since 4.6). No change to the withdrawal flow, your data or the evidence log.

= 1.2.3 =
* **Acknowledgement e-mail failures now report the exact reason, not a generic message.** Building on the 1.2.2 fix: when the acknowledgement e-mail cannot be sent, the plugin now captures the specific reason from the mail transport — the SMTP plugin's own error (for example "Could not authenticate" or "Could not connect to host") or the thrown exception's message — and shows it in the admin "e-mail failed" notice and records it in the tamper-evident log, instead of a generic "email failed". Diagnosing an SMTP misconfiguration (WP Mail SMTP, FluentSMTP, a provider mailer) is now immediate, without digging through the PHP error log. The withdrawal is still always recorded and the consumer always reaches their confirmation page.

= 1.2.2 =
* **Critical fix: no more "critical error" when sending the acknowledgement e-mail.** When a consumer confirmed a withdrawal (and when an admin clicked "Resend e-mail"), an exception raised inside WordPress's `wp_mail()` by an SMTP plugin (for example WP Mail SMTP or FluentSMTP), or a PDF/Dompdf error on PHP 8, could escape and crash the whole request with a fatal "critical error" even though the withdrawal itself was already recorded. The e-mail path is now exception-safe on both delivery routes (the standalone mailer and the WooCommerce e-mail) and for the optional PDF: a send failure degrades gracefully (it is logged, the admin gets a "resend" notice, the consumer still sees their confirmation page) instead of taking down the page. After updating, check your SMTP plugin's settings or log for the underlying cause.
* **FluentCart now has its own native withdrawal add-on — clearer guidance.** As of **FluentCart 1.4.2** (June 2026), FluentCart ships a first-party EU "right of withdrawal" feature ("customer rights"). If you enable it **and** keep this plugin handling FluentCart, customers could see two withdrawal flows. **Settings → FluentCart** now states this clearly and tells you what to do: set the FluentCart handling to **Off** (or have a developer return true from the `wwu_wb_fluentcart_native_active` filter) so only one flow shows. Automatic detection of FluentCart's add-on will arrive in a later update. Your WooCommerce and EDD handling is unaffected.

= 1.2.1 =
* **Fix — the "Right of withdrawal" account tab no longer returns a 404 on a fresh install.** On WooCommerce the withdrawal tab is a rewrite endpoint; its rewrite rule was not being persisted during activation, so clicking the tab in **My Account** led to a 404 until you re-saved Permalinks. The plugin now performs a one-time rewrite-rules flush on the first page load after activation, so the tab resolves immediately. (If you already hit this: **Settings → Permalinks → Save Changes** also fixes it — no page needs to be created, the slug is a WooCommerce endpoint, not a page.)
* **Edit the legal clauses from the admin — no code needed.** A new **Settings → Legal clauses** section lets you replace the built-in pre-contractual / terms / privacy / exemption-consent clauses with your own wording. Your text then appears on the Compliance page and wherever the `[wwu_wb_info]` shortcode is used (and the "sample text" note is dropped). Leave a field empty to keep the built-in template; a "Show the built-in default" toggle lets you copy the original as a starting point. Developers can also override programmatically with the new `wwu_wb_clause_text` filter. The built-in clauses are sample templates — adapt them to your business and have your counsel review them.

= 1.2.0 =
* **Reminder to update your legal texts — the button is not a substitute.** Installing the withdrawal button does not change your shop's own documents, and EU law (Art. 6 of the Consumer Rights Directive) requires your Terms & Conditions of sale and your pre-contractual information to describe *how* the consumer withdraws — which now includes the new online "withdrawal button". The plugin now states this prominently on the Dashboard and the Compliance page, opens the two clauses you must paste (pre-contractual information + general terms) by default, and the ready-to-paste "How to withdraw" and pre-contractual clauses now name the button explicitly. This release also **rewrites the plugin description** to explain, in plain steps, **how the withdrawal flow works** and to showcase the **full feature set** (customer help, merchant cockpit, smart legal handling, automations, privacy tooling). No change to the withdrawal flow itself.

= 1.1.1 =
* **wordpress.org Plugin Check polish.** Removes the unused UI-kit `clipboard.js` from the package entirely (its filename collided with a WordPress-core library; it was never loaded — only the accordion, badge and utilities components are), and moves the documentation link out of the short-description block. No functional change.

= 1.1.0 =
* **Evidence-log hardening (security-audit follow-up).** The tamper-evident log is now stronger against a database-level/insider attacker and cleaner under GDPR: each row hash is **HMAC-keyed** with the site secret (so a DB-write attacker without the secret can no longer recompute a forged chain); the hash commits to the **anonymised** IP while the full IP is stored separately and **erased after the retention horizon**; RFC 3161 timestamps now **require HTTPS** and are **bound to the exact submitted digest** (a TSA/MITM cannot return a token for a different hash); failed initial timestamps are **retried automatically**, with any not-yet-anchored records surfaced in the admin. Existing logs keep verifying (each row records its chain version). No change to the withdrawal flow.

= 1.0.1 =
* **wordpress.org readiness + security hardening.** Resolves the Plugin Check items for the directory submission: the unused `clipboard.js` UI-kit asset is no longer shipped, `composer.json` is now included alongside the bundled library, the SSRF smoke-test no longer uses a literal localhost host, and "Tested up to" is current. Plus minor hardening from a full security audit: the OpenTimestamps calls now pass through the same SSRF guard as the webhook / RFC 3161 callers (and never follow redirects), and two admin credential fields gain `wp_unslash()`. No functional change to the withdrawal flow.

= 1.0.0 =
* **First stable release**, for the EU withdrawal-button mandate that applies from **19 June 2026**. Consolidates the full feature set: the statutory two-step withdrawal flow with per-language wording (IT, EN, DE, FR, ES, SV), a durable-medium acknowledgement (email + PDF + verifiable link + OpenTimestamps), a tamper-evident hash-chained log, the Art. 59 exemptions with checkout consent capture and a consumer "why exempt" note, optional partial withdrawal, WooCommerce (HPOS + legacy) / FluentCart / Easy Digital Downloads adapters, the withdrawal link in order e-mails, a read-only REST API + signed webhook for automations, and the Annex I-B model form + ready legal clauses. All six locales fully translated (545/545). No functional change from 1.0.0-alpha.45 — the External services disclosure was clarified and translations finalised for release.

= 1.0.0-alpha.45 =
* **Withdrawal link in your order e-mails — across all platforms.** The withdrawal link is added **automatically** to WooCommerce customer order e-mails and to the Easy Digital Downloads purchase-receipt e-mail (so customers can reach the withdrawal straight from the e-mail, as the law's Recital 37 suggests). FluentCart doesn't allow plugins to add content to its e-mails automatically, so **Settings → FluentCart** now shows a short, optional one-time guide to drop the `{{wwu.recesso_url}}` shortcode into your FluentCart receipt template (copy-ready, 3 steps). Nothing invasive, nothing required — the withdrawal is always reachable from the account/portal and the public page regardless.

= 1.0.0-alpha.44 =
* **Connect your withdrawal requests to other tools (automations).** A new **Settings → Integrations** section adds two optional, developer-friendly ways to plug withdrawal requests into Zapier, Make, n8n, a CRM or a helpdesk. (1) A **read-only REST API** to list requests and check an order's withdrawal status, authenticated with a standard WordPress Application Password. (2) An optional **webhook** that sends a signed notification to your endpoint the moment a withdrawal is confirmed. Privacy-first: the consumer's IP address is never exposed — only a verification hash. There is intentionally no way to *create* a withdrawal via the API (a withdrawal is the consumer's own legal act). Passed a dedicated security audit before release. No change to the withdrawal flow itself.

= 1.0.0-alpha.43 =
* **Consumers now see WHY the withdrawal button is absent on exempt orders.** When an order is exempt from the right of withdrawal under Art. 59 (e.g. digital content with immediate access, or a service fully performed — both with the consumer's consent at checkout), the button is hidden. The plugin now shows a short, accurate note explaining the specific statutory exception and its legal reference, instead of just silence. Shown on the withdrawal form, the WooCommerce/EDD account pages and the FluentCart portal. The text is editable (Settings → Consumer guidance). It only appears on genuinely exempt orders — never on ordinary ones; button visibility is unchanged.

= 1.0.0-alpha.42 =
* **You can now withdraw from only some products of an order.** EU law allows partial withdrawal (it's not all-or-nothing), so step 1 of the withdrawal form gains an **optional** checklist of the order's products — tick the ones you're withdrawing from, or leave it empty to withdraw from the whole order (the default). The choice appears on the confirmation e-mail/PDF and in the admin Requests dashboard. It's informational: you still process the refund (full or partial) yourself. No change for anyone who withdraws from the whole order.

= 1.0.0-alpha.41 =
* **FluentCart handling is now configurable.** FluentCart is building its own withdrawal add-on, so a new **Settings → FluentCart** control lets you choose how this plugin behaves on FluentCart orders: **Auto** (recommended — show our button, but step aside automatically if FluentCart's own add-on is installed, so customers never see two buttons), **Always** (keep ours regardless), or **Off** (never handle FluentCart). Only our consumer-facing FluentCart surfaces are affected — the admin Requests dashboard and any in-flight confirmation keep working. No change for WooCommerce or EDD.

= 1.0.0-alpha.40 =
* **Admin UI styling restored + Swedish added.** The bundled WWU UI Kit is now shipped with the plugin (it was referenced but never packaged), so the Settings → Exemptions section (accordions, badges, notices) renders styled instead of plain. Added **Swedish (sv_SE)**: the statutory withdrawal-button label ("ångra avtalet här") and confirmation ("bekräfta frånträde") per the official EUR-Lex Art. 11a wording (Distansavtalslagen 2005:59), plus a complete UI translation (all 495 strings; the legal term "varaktigt medium" corrected) — pending a native Swedish review by Daniel before it's marked final.

= 1.0.0-alpha.39 =
* **Critical fix — the Settings page no longer fatals.** A merchant reported "Class WWU\WithdrawalButton\Admin\Settings not found", which crashed the whole settings screen. A missing `use` import made an unqualified `Settings::main()` resolve to the wrong namespace. Fixed, and a scan of all 91 source files confirmed there were no other cases. This release also carries a small mail-safety fix (the HTML mailer now always removes its `wp_mail_content_type` filter, so a third-party email error can't turn other plugins' plain-text emails into HTML) and a WooCommerce-surface + plugin-conflict audit (0 critical / 0 high). **Recommended for all installs.**

= 1.0.0-alpha.38 =
* **Subscriptions handled correctly (WooCommerce Subscriptions, FluentCart, EDD Recurring).** EU law gives one 14-day right of withdrawal per contract, at conclusion — a renewal does **not** restart it. The button now appears on the **initial order only** and is suppressed on renewal orders (single gate covering every surface). Two opt-in settings under **Settings → Subscriptions**: "also show on renewals" (off by default) and "auto-cancel the subscription on withdrawal" (off by default — the refund and any pro-rata always stay manual). The Requests dashboard flags subscription orders with a reminder. Renewal detection is guarded and fail-open (an undetermined state keeps the button visible). Needs a live test with a subscription plugin active.

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

= 1.2.13 =
Partial withdrawal now lets the customer pick a quantity per item (e.g. 1 of 3) — an optional, no-JS number field; blank = the whole line. Shown on the receipt, dashboard and REST API. Informational and fully back-compatible (additive data). Issue #47.

= 1.2.12 =
Now requires PHP 8.1 (was 7.4); ships Dompdf 3.1.5. Custom CSS field removed — restyle via WordPress Customizer → Additional CSS. Timestamping is now opt-in: no external calls by default. PHP 7.4–8.0 sites stay on 1.2.11; a compatible build is on GitHub.

= 1.2.11 =
Fixes the Consent Records admin page (and CSV export) showing only WooCommerce consents: it now reads cross-platform from the tamper-evident evidence log, so Easy Digital Downloads and FluentCart consents appear too. Read-only — the immutable log is never altered. (GitHub #41.)

= 1.2.10 =
WordPress.org compliance pass: wp_unslash() added on the settings-save inputs (already sanitised) and a false-positive prepared-SQL error silenced on the integrity query. Over-long upgrade notices trimmed. No change to the withdrawal flow or your data.

= 1.2.9 =
Type-aware withdrawal window: for all-digital orders the 14-day countdown now starts at the order date (contract conclusion); orders with physical items are unchanged (delivery/completed date). Informational only — the button is never hidden on this. No breaking changes.

= 1.2.8 =
Completes the guest fix: the [wwu_wb_button] shortcode and the order-actions link now also route guests to the public withdrawal page, not just the automatic button, so a guest is never sent to the login screen. Logged-in customers unaffected.

= 1.2.7 =
Fixes the withdrawal button in the order recap sending guest (no-account) customers to the login screen; guests are now routed to the public withdrawal page. Logged-in customers are unaffected. No breaking changes.

= 1.2.6 =
Completes the bundled translations (Italian, German, French, Spanish, Swedish) for recent admin strings that were still showing in English — the Legal clauses editor, the Notification email(s) field and the Compliance reminders. (Swedish is machine-assisted, pending native review.)

= 1.2.5 =
Fixes PHP 7.4 compatibility: the bundled PDF library (Dompdf) had been requiring PHP 8.1 and showed a Composer platform error on PHP 7.4 sites; it is now pinned to the 7.4-compatible 2.x line. Also: the notification e-mail field now accepts multiple comma-separated recipients.

= 1.2.4 =
Housekeeping + WordPress.org compliance hardening (input sanitisation, output escaping, explicit REST permission callbacks). The display name is now "WWU Right of Withdrawal"; the slug is unchanged. No change to the withdrawal flow or your data.

= 1.2.3 =
Follow-up to the 1.2.2 e-mail fix: a failed acknowledgement e-mail now shows the exact transport reason (for example the SMTP plugin's "Could not authenticate") in the admin notice and the immutable log, instead of a generic "email failed", so an SMTP misconfiguration is obvious at a glance.

= 1.2.2 =
Critical: fixes a fatal "critical error" when sending the acknowledgement e-mail (on confirmation and admin resend), caused by an SMTP plugin throwing inside wp_mail. The send now fails gracefully instead of crashing. Also: set FluentCart handling to Off if you use its native add-on.

= 1.2.1 =
Fixes the WooCommerce "Right of withdrawal" account tab returning a 404 on a fresh install — a one-time rewrite flush now runs after activation (re-saving Permalinks also fixes it). You can also edit the legal clauses from Settings → Legal clauses (no code).

= 1.2.0 =
Reminder: installing the button does not update your shop's legal texts. Your Terms & Conditions and pre-contractual information must describe the new withdrawal-button modality (Art. 6 CRD). The plugin now flags this and gives you the ready-to-paste clauses. No change to the flow.

= 1.0.0-alpha.43 =
Adds a consumer-facing "why exempt" note: on orders exempt under Art. 59, the plugin explains why the withdrawal button is absent (the exception + legal reference) instead of showing nothing. Editable, fail-safe, only on genuinely exempt orders; button visibility unchanged.

= 1.0.0-alpha.42 =
Adds an optional "which products" checklist to the withdrawal form (partial withdrawal), shown on the receipt and the Requests dashboard. Optional and fail-open — leaving it empty withdraws from the whole order as before. No breaking changes.

= 1.0.0-alpha.41 =
Adds a FluentCart handling mode (Auto/Always/Off) so this plugin steps aside automatically when FluentCart's own withdrawal add-on is installed. No breaking changes; WooCommerce and EDD are unaffected.

= 1.0.0-alpha.40 =
Restores the admin UI styling (the UI Kit is now bundled) and adds Swedish (statutory button label + complete UI translation, pending native review). No breaking changes.

= 1.0.0-alpha.39 =
Critical fix: the Settings page no longer crashes with a "Class … Settings not found" fatal. Update recommended for everyone. Also includes a mail-safety fix and a WooCommerce/conflict audit.

= 1.0.0-alpha.38 =
Subscriptions are handled correctly: the button shows on the initial order only and is hidden on renewals (one 14-day right per contract). Two opt-in toggles under Settings → Subscriptions. If you use WooCommerce/FluentCart/EDD subscriptions, test on staging.

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
Completes the digital/service exemptions: durable-medium confirmation e-mail, configurable consent retention with automatic IP anonymisation, a GDPR privacy clause and a Consent records page. Review the new clause + retention setting. Test on staging; not yet stable.

= 1.0.0-alpha.28 =
Adds lawful consent capture at checkout for digital-immediate and service-performed exemptions. If you exempt those product types, the button is now hidden only after the consumer ticks the required acknowledgement. Test on staging; not yet a stable release.

= 1.0.0-alpha.19 =
Recommended for FluentCart stores: fixes the customer-account withdrawal page (was blank) and the per-order button. Test on staging before production; not yet a stable release.

= 1.0.0-alpha.17 =
Feature-complete alpha. Test thoroughly on staging before production; not yet a stable release.
