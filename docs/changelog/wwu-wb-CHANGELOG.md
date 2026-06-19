# Changelog — WWU Withdrawal Button

All notable changes to this project are documented here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/); the project uses Semantic Versioning.

## [1.2.5] — 2026-06-19 — PHP 7.4 fix (Dompdf 2.x) + multi-recipient notification e-mail

**PHP 7.4 compatibility — Dompdf downgraded to 2.x.** Reported as issue #31. The bundled PDF engine was `dompdf/dompdf: ^3.1` (resolved v3.1.5), whose dependency tree (`sabberworm/php-css-parser` 9.x, `thecodingmachine/safe`) requires **PHP ≥ 8.1**, and Dompdf 3.x itself uses 8.1 syntax. This contradicted the plugin's declared `Requires PHP: 7.4`: on a PHP 7.4 site, Composer's generated `vendor/composer/platform_check.php` throws *"Your Composer dependencies require a PHP version >= 8.1.0"*, surfaced near the PDF-attachment option on the settings page. Fix: pin `dompdf/dompdf` to `^2.0` (resolved **v2.0.8**, tree PHP 7.1+); `composer.lock` regenerated — `thecodingmachine/safe` + the 3.x font/svg libs dropped, css-parser downgraded to 8.x. The `PdfBuilder` API (`Options`/`loadHtml`/`setPaper`/`render`/`output`) is identical in 2.x — verified by a standalone render producing a valid `%PDF-1.7`. `vendor/` is not committed (it is built from the lock at package time), so the fix is `composer.json` + `composer.lock`; the shipped `platform_check.php` now requires ≤ 7.4.

**Notification e-mail accepts multiple recipients.** Requested by a user. The merchant "new withdrawal request" alert can now go to several addresses: `Settings → Notification email(s)` (the field is now free text) accepts a comma-separated list. New `Sanitizer::email_list()` runs `sanitize_email()` on each entry, drops invalid/empty, de-duplicates and caps at 10. `ConfirmationDispatcher` passes the list straight to `wp_mail()`, which natively accepts a comma-separated `to`. `ReceiptBuilder` shows only the **first** address as the public trader contact on the consumer receipt (new `Sanitizer::first_email()`), so the internal recipients are never exposed to the customer. A single address stays fully back-compatible. 3 new smoke assertions in `suite_durable_medium`.

**FluentCart coexistence auto-detection wired.** FluentCart confirmed (2026-06-19) the stable signal for their free "Customer Rights" add-on (slug `fluent-cart-customer-rights`): the constant `FLUENT_CART_CUSTOMER_RIGHTS_PLUGIN_PATH`, defined on `plugins_loaded:20` as their double-load guard (won't be removed). `FluentCartAdapter::native_addon_active()` — until now a placeholder returning `false` — now returns `defined( 'FLUENT_CART_CUSTOMER_RIGHTS_PLUGIN_PATH' ) || class_exists( 'FluentCartCustomerRights' )`, still wrapped in the `wwu_wb_fluentcart_native_active` filter. So `fluentcart_mode = auto` (the default) genuinely auto-defers to FluentCart's native add-on now — the two-flows risk is gone without a manual "Off".

**Display name corrected for WordPress.org.** The directory rejected "WWU Right of Withdrawal for WooCommerce, …" because the restricted term **WooCommerce** may not appear in a plugin display name. The name is now **"WWU Right of Withdrawal for Popular Ecommerce Platforms"** — a generic, trademark-free descriptor; the supported platforms stay named in the description + tags. Slug + text domain unchanged.

No DB or schema change. PHP lint clean.

## [1.2.4] — 2026-06-19 — WordPress.org pre-review hardening + display-name refinement

Addresses the WordPress.org plugin-directory pre-review (Review ID `AUTOPREREVIEW … TRM-OWN-LIC`). **No functional change** to the withdrawal flow, storage or evidence log; **slug + text domain unchanged** (`wwu-withdrawal-button`).

**Ownership / trademark.** "WWU" is **our own brand** (WebWakeUp), not a third party's mark; ownership is verified by moving the WordPress.org account to an `@webwakeup.it` address. The display name is refined from "WWU Withdrawal Button" to **"WWU Right of Withdrawal"** — more distinctive (drops the generic "Button", which collides with crypto withdrawal-button plugins; uses the statutory term) while keeping the `wwu-withdrawal-button` slug, so the text domain and all six translations are untouched.

**Code hardening (reviewer items):**
- `GuestAccess::check_rate_limit()` wraps `$_SERVER['REMOTE_ADDR']` in `sanitize_text_field( wp_unslash( … ) )`.
- The three plain-text-e-mail URL outputs switch from `esc_url_raw()` to `esc_url()` (`OrderEmailLink::render`, `wwu-wb-withdrawal-ack.php`). The receipt URLs are single-parameter (`?t=<40-hex>`), so there is no `&`-encoding concern.
- `WithdrawalRoute` declares `permission_callback => '__return_true'` **explicitly** on each public endpoint (it was previously set via a shared `$args` the static scanner couldn't see), with a comment documenting these are intentionally public (guest withdrawal) and protected in-callback by nonce + per-IP rate limit + order-ref/e-mail verification.
- Removed `load_plugin_textdomain()` and its `init` hook — unnecessary since WP 4.6, which loads the bundled `/languages` `.mo` just-in-time (and language packs once hosted).
- `readme.txt` **External services** section expanded: the optional RFC 3161 / eIDAS authority is OFF by default and is a provider the merchant chooses + contracts with directly (Sectigo / Aruba / InfoCert / …), whose own ToS + privacy apply.

**Explained to the reviewer, not defects:** the plugin is 100% free and fully functional (no trialware / locked features); the inline `<style>` blocks live in standalone HTML responses (no-JS flow, receipt-verification page) and the Dompdf PDF, where enqueuing does not apply; the public REST endpoints are intentionally public with in-callback protection.

## [1.2.3] — 2026-06-19 — Detailed mail-failure reason (no more generic "email failed")

Follow-up to the 1.2.2 e-mail-send hardening. 1.2.2 stopped the crash; the failure
itself, however, was still reported generically (`wp_mail_returned_false` in the log, a
fixed "could not be sent" admin notice). This release surfaces the **specific** reason so
an SMTP misconfiguration is diagnosable at a glance.

**What changed.**

- `Mailer::send_html()` now captures the real failure reason and exposes it via a new
  `Mailer::last_error()`:
  - **wp_mail() returns false** → it hooks `wp_mail_failed` for the duration of the send
    and reads the `WP_Error` message WordPress emits there (the transport's own message,
    e.g. *"Could not authenticate"*, *"Could not connect to host smtp.…"*).
  - **wp_mail() throws** (the 1.2.2 case) → the `catch ( \Throwable )` records
    `$e->getMessage()`.
  - The reason is trimmed + capped to 300 chars (`Mailer::cap()`), so it never bloats the
    append-only log or the admin transient.
- `ConfirmationDispatcher::dispatch()` threads the reason through: a `$fail_reason` is
  captured from the WooCommerce `WC_Email` route's `catch`, then (most relevant) from
  `Mailer::last_error()` when the standalone send is the one that failed. On failure it
  now writes the reason into both the immutable `receipt_failed` log payload **and** the
  `wwu_wb_mail_failed` transient (now `array{uid, reason}` instead of a bare uid).
- `AdminController::maybe_mail_failure_notice()` reads the transient (back-compatible with
  a bare-string uid from a 1.2.2 failure) and appends **"Reported reason: …"** to the
  admin notice when a reason is present.

**Why.** The reporting merchant runs WP Mail SMTP across several sites; a detailed reason
turns "the e-mail failed" into "the SMTP host rejected auth", which is actionable without
opening the PHP error log. The legal guarantee is unchanged: the withdrawal is always
recorded, the consumer always reaches the confirmation page, the failure is always
logged + resendable.

PHP lint clean (3 files). No DB or schema change; the transient shape change is
back-compatible.

## [1.2.2] — 2026-06-18 — Critical e-mail-send hardening + FluentCart coexistence + smoke fix

**Critical fix — a fatal "critical error" when sending the acknowledgement e-mail.**
Reported by a merchant running WP Mail SMTP (free) across several WooCommerce sites. On
withdrawal confirmation, and on the admin "Resend e-mail" action, an exception raised
INSIDE `wp_mail()` by an SMTP plugin (WP Mail SMTP, FluentSMTP, a provider mailer), or a
`\Error` from Dompdf on PHP 8, escaped and crashed the request. WordPress's own
`wp_mail()` only catches `\PHPMailer\PHPMailer\Exception`; any other `\Throwable`
propagated up through `do_action('wwu_wb_withdrawal_confirmed')` to the REST / no-JS
confirm handler, producing a white-screen fatal even though the withdrawal had already
been recorded. Fix: the send path is now exception-safe end to end. `Mailer::send_html()`
wraps `wp_mail()` in `try/catch(\Throwable)` (returns false, logs
`durable_medium:mail.exception` + `error_log`); the WooCommerce `WC_Email` delivery route
is wrapped and falls through to the standalone mailer on a throw; and the optional PDF
block is wrapped so a Dompdf error just skips the attachment. A send failure now degrades
into the existing `receipt_failed` path (admin notice + resend) instead of a fatal, and
the consumer always reaches their confirmation page. After updating, the actual SMTP
cause is visible in the PHP error log + the admin "e-mail failed" notice.

**Smoke test fix.** The `withdrawal_request` suite built its fixtures with `order_id` (a
key `WithdrawalRequest::from_input()` ignores — it reads `order_ref`), so the
`is_valid_unaffected` assertion saw an empty `order_ref` and went red. Changed the four
fixtures to `order_ref`. No plugin-code change: `is_valid()` was always correct (the live
flow passes `order_ref`, which is why real withdrawals worked).

**FluentCart native withdrawal add-on: coexistence guidance.**
FluentCart **1.4.2** (June 2026) shipped its own first-party EU right-of-withdrawal
feature — the **"FluentCart customer rights"** add-on (public no-login page, two-step
statutory flow with timestamped acknowledgement, item/quantity selection with tax-aware
estimated refund, subscription withdrawal, duplicate-request guard, admin Withdrawal
Requests page, automated e-mails). This is the native add-on FluentCart had told us was
coming (2026-06-15) — the overlap we anticipated with the `fluentcart_mode` Auto-defer
design.

**Gap:** `FluentCartAdapter::native_addon_active()` is still a placeholder returning
`false` (no published detection signal when it was written), so Auto mode does **not**
yet auto-defer — a merchant running both could see two withdrawal flows on FluentCart.
The `wwu_wb_fluentcart_native_active` filter and the **Off** mode are the current manual
overrides.

**This release (interim, no fragile guessing):** the **Settings → FluentCart** copy is
corrected — the previous "*if* FluentCart *later* ships an add-on, Auto steps aside
automatically" notice (now wrong) becomes a `notice-warning` stating the add-on exists
(FluentCart 1.4.2+), that auto-detection isn't wired yet, and what to do (set **Off**, or
return true from `wwu_wb_fluentcart_native_active`) so customers don't see two flows. The
"Show example" help is updated likewise. No detection logic is guessed.

**Next:** asked the FluentCart team for the canonical detection signal (class / constant /
plugin slug) for the customer-rights add-on; once received, `native_addon_active()` gets
a one-line real check so Auto truly auto-defers. WooCommerce + EDD handling unaffected.
README status badge bumped to 1.2.2. PHP lint clean; no DB change.

## [1.2.1] — 2026-06-18 — Fix My Account endpoint 404 on fresh activation + clause filter

Two fixes prompted by a developer's support email (Antonio Costa):

**Fix — the WooCommerce "Right of withdrawal" account tab 404s on a fresh install.**
The My Account withdrawal tab is a rewrite endpoint (`/my-account/<endpoint_slug>`,
default `wwu-withdrawal`) registered by `WooMyAccount` on `init` via
`add_rewrite_endpoint()`. The activation-time `flush_rewrite_rules()` in
`Install::setup_site()` runs *before* that endpoint is registered — during the
activation request the plugin boot never runs (`plugins_loaded` has already fired),
so the endpoint isn't registered and the flush can't persist its rule. Result: the
tab 404s until the admin re-saves Permalinks. **Root cause is broad (every fresh
install).** Fix: a one-time **deferred flush** — `Install::setup_site()` sets an
autoloaded flag `wwu_wb_flush_pending = '1'`; a new `Install::maybe_deferred_flush()`
hooked on `wp_loaded` (after `init` has registered the endpoint) flushes once and
flips the flag to `'0'` (kept autoloaded, never deleted → cache-only no-op on later
requests, zero extra query). Wired in the bootstrap after `Plugin::boot()`. Option
added to `uninstall.php` cleanup. The slug is a **WooCommerce endpoint, not a page** —
no page needs to be created; re-saving Permalinks is the manual workaround for
already-affected installs.

**Editable legal clauses (Settings → Legal clauses) + `wwu_wb_clause_text` filter.**
Prompted by the same support email — Antonio asked whether the clause texts could be
edited. They now can, two ways. **No-code:** a new `SettingsPage::render_clauses_section()`
adds a **Settings → Legal clauses** section with an editable textarea per type
(precontractual / terms / privacy / consent_privacy) for the current admin language,
plus a "show the built-in default" toggle to copy the original as a starting point.
Saved to the new autoload:no option `wwu_wb_clauses` (shape `[type][lang]`; per-language,
other languages preserved; an empty field reverts to the built-in template).
`ClauseLibrary::get()` returns a saved override **verbatim** — no sample-text disclaimer,
no `[EN]` prefix — short-circuiting the template path; the Compliance page shows a
"customised" badge + a pointer to the section; the option is added to `uninstall.php`
cleanup. **Code:** `ClauseLibrary::get()` also runs the body through
`apply_filters( 'wwu_wb_clause_text', $text, $type, $lang )` on both the override and
default paths (Compliance page + `[wwu_wb_info]` shortcode). New `ClauseLibrary` API:
`OPTION`, `has_override()`, `default_text()`. Hooks reference 33 → 34.

No DB schema change (still version 3). PHP lint clean (4 files).

## [1.2.0] — 2026-06-18 — "Update your legal texts too" merchant reminder

Prompted by EU consumer lawyer Alessandro Vercellotti's public note: *the button is
mandatory, but the Terms & Conditions of sale must also be amended in the withdrawal
article to provide for the new button modality.* He is right — Art. 6 of the Consumer
Rights Directive requires the trader to inform the consumer **how** to exercise the
withdrawal, and that information now has to include the online button. Installing the
plugin adds the button; it does not (and cannot) edit the merchant's own published
documents. This release makes that impossible to miss and gives the merchant the exact
text to paste. No change to the withdrawal flow, storage, or evidence.

**Plugin (admin):**
- `DashboardPage` — new prominent reminder card right after the setup checklist:
  *"Installing the button is not enough — update your legal texts too"* + a button
  linking to the Compliance page where the ready-to-paste clauses live.
- `ComplianceStatusPage` — a `notice-warning` callout at the top of "Documents to
  update" stating the same point, and the two clauses the merchant must paste
  (pre-contractual information + general terms) now render **open by default** so they
  are not overlooked inside the collapsed list.
- `ClauseLibrary` — the `terms` ("How to withdraw") clause now **names the button
  explicitly** ("the dedicated online withdrawal button / l'apposito pulsante di recesso
  online — 'Recedere dal contratto qui'"), throughout the withdrawal period, alongside
  the Annex I-B model form. IT + EN. The `precontractual` clause already named it.

**Docs + web:**
- Marketing landing + documentation pages updated with a "you must also update your
  Terms & pre-contractual withdrawal clause" note.
- readme.txt changelog + Upgrade Notice.

**Documentation overhaul (same release) — explain how it works + showcase every helpful feature.**
On user request ("explain better how it works; put all the features that help the user, properly"):
- `readme.txt` (wp.org) Description rewritten: a plain-language **"How it works (in 4 steps)"** walkthrough
  + a complete, grouped feature list (For your customers / For you the merchant / Smart legal handling /
  Evidence & timestamps / Privacy & GDPR / Documents / Integrations & automation / Platforms & licence).
  Previously the Description was 7 terse bullets that hid the merchant cockpit, requests management,
  automations, privacy tooling, etc.
- `README.md` (GitHub): added the same 4-step "How it works" lead + 4 missing feature bullets (merchant
  cockpit incl. the e-mail delivery test, readable verification certificate, privacy/GDPR by design, the
  legal-texts reminder); status badge 1.0.0 → 1.2.0; Status section corrected (1.1.1 in wp.org review,
  1.2.0 ships via SVN after approval).
- `docs.html` (public docs): new **"Come funziona (in pratica)"** section (4 steps + an at-a-glance
  summary of the full feature set, each item linking to its detailed section) + sidebar nav entries for it
  and for the legal-docs section.
- `landing.html`: added showcase cards for **Automazioni &amp; API**, **Privacy &amp; GDPR by design**
  and **Gestione richieste** (the requests dashboard) — features that help the user but weren't shown.
  (landing.html + docs.html live under `_internal/`, outside the repo — republish to webwakeup.it.)

Lint: PHP 0 errors (ClauseLibrary, ComplianceStatusPage, DashboardPage). Smoke tests
unaffected (they assert clause presence, not wording).

## [1.1.1] — 2026-06-18 — wordpress.org Plugin Check polish

Final Plugin Check pass before submission. No functional change.

- Remove the unused UI-kit `clipboard.js` from the repo + package (its filename collided
  with a WP-core library; it was never enqueued — only `accordion`/`badge`/`utilities`
  are) and drop its loader entry + the `debug-bar` dependency on it. This makes the check
  pass regardless of how the plugin is installed (repo checkout or built zip).
- readme: move the "Product page & documentation" line out of the short-description block
  (the wp.org parser was counting it toward the 150-char short-description limit) into the
  Description section.

The remaining `WordPress.DB.DirectDatabaseQuery` / `DirectDB.UnescapedDBParameter` notices
are advisory false positives — every interpolated table name is `$wpdb->prefix` + a class
constant, never user input.

## [1.1.0] — 2026-06-18 — Evidence-log hardening (security-audit follow-up)

Closes the three deeper integrity/privacy findings from the 2026-06-17 audit
(`docs/audits/wwu-wb-full-2026-06-17-AUDIT.md`) before the wordpress.org submission.
No change to the withdrawal flow; existing logs keep verifying.

**HIGH-1 — keyed hash chain.** Each row hash is now HMAC-SHA256 keyed with the site
secret (`LogChain` v2), so a DB-write attacker WITHOUT the secret can no longer
recompute a forged chain. Every row records its `chain_version`; legacy v1 rows
(unkeyed SHA-256) still verify, so a mixed chain stays valid. (schema 2 → 3, Migration_3.)

**MEDIUM-1 — GDPR erasure horizon for the log IP.** The hash now commits to the
ANONYMISED IP (`wp_privacy_anonymize_ip`); the full IP is stored in a new non-hashed
`ip_full` column, retained for the legal window then blanked by the retention purge
(together with `customer_email`). The hashed evidence is never rewritten, so erasing
the PII never breaks the chain.

**HIGH-2 — timestamp verification + retry.** RFC 3161 now requires HTTPS by default
(`wwu_wb_rfc3161_allow_insecure_http` to override) and binds the returned token to the
exact submitted digest + nonce — a TSA/MITM cannot return a token for a different hash.
(Full TSA-signature verification is delegated to an external/qualified verifier using
the retained token.) OpenTimestamps / initial stamps that fail (e.g. calendars down)
are now retried on the hourly cron instead of being abandoned, and the admin Requests
screen surfaces any confirmed records that are not yet externally anchored.

Smoke tests added: v2 keyed hash, IP anonymisation, schema-v3 columns, https-required,
un-anchored count.

## [1.0.1] — 2026-06-17 — wordpress.org readiness + security hardening

Prepares the wordpress.org directory submission and lands the low-risk fixes from a full
5-part security audit (REST/SSRF, evidence-log/crypto/PII, adapters/consent, admin/XSS,
wp.org compliance). Audit report: `docs/audits/wwu-wb-full-2026-06-17-AUDIT.md`.

- **Plugin Check fixes:** the unused UI-kit `clipboard.js` (its filename collided with a
  WP-core library) is excluded from the build; `composer.json` now ships alongside the
  bundled `vendor/` (Dompdf); the SSRF smoke-test uses a private IP instead of a literal
  `localhost`; `readme.txt` "Tested up to" bumped to 7.0.
- **SSRF parity (audit M-1):** the OpenTimestamps stamp/upgrade calls now pass through
  `OutboundUrlGuard` and use `redirection => 0` + `reject_unsafe_urls => true`, matching the
  webhook and RFC 3161 callers.
- **Input hygiene:** `wp_unslash()` added to the RFC 3161 password and webhook secret fields
  in the settings save handler.
- **Defensive:** explicit `return` after the no-JS flow's error renders.

Audit verdict: **no remotely-exploitable XSS / SQLi / SSRF / CSRF / IDOR found.** Three
deeper integrity/privacy findings are tracked for a follow-up release: the evidence-log
hash chain is unkeyed SHA-256 (forgeable by a DB-write attacker), the external timestamp
anchor is not fully verified (OTS block height, RFC 3161 token signature), and
withdrawal-event IPs in the immutable log have no anonymisation horizon (GDPR storage
limitation).

## [1.0.0] — 2026-06-17 — First stable release

Promotes the alpha series to the first **stable** release, for the EU withdrawal-button
mandate that applies from **19 June 2026**. No functional change from `1.0.0-alpha.45`:
the External services disclosure in `readme.txt` was clarified (the optional RFC 3161 /
eIDAS provider and the optional outbound webhook are now spelled out, both off by
default), and translations were reconciled with the Crowdin TMS after PR #20 — all six
locales 545/545, `.mo` recompiled. The consolidated feature set is summarised in
`readme.txt`.

### FluentCart e-mail link helper + verified email-reachability matrix (1.0.0-alpha.45, 2026-06-16)
Closes the loop on **withdrawal-link reachability in transactional e-mails** across all three platforms — and
documents *why*, per each platform's official API. Verified state:

- **WooCommerce** — the withdrawal link is added **automatically** to every customer order e-mail
  (`woocommerce_email_after_order_table`), gated by eligibility, as a pre-authenticated guest link. (Already shipped.)
- **Easy Digital Downloads** — added **automatically** to the purchase-receipt e-mail (`edd_order_receipt` 3.2.0+ /
  `edd_purchase_receipt` legacy, de-duplicated). (Already shipped.)
- **FluentCart** — re-verified against FluentCart's current developer docs (June 2026): FluentCart exposes **no PHP
  hook** to auto-append content to its e-mail bodies (the only mechanism is the template editor + shortcodes). So
  auto-injection isn't possible *per their documentation*. Instead, **Settings → FluentCart** now shows a clear,
  optional **one-time setup helper**: it surfaces the `{{wwu.recesso_url}}` merge-tag with copy-ready code + a
  3-step guide (Settings → Emails → Customized Body → shortcode picker `{;}` → Save), shown only when FluentCart is
  active and this plugin actually handles it (not stepping aside to a native add-on). This turns the "manual but
  supported" path into a 30-second task while staying 100% within FluentCart's documented API.

**Legal framing:** Art. 11a(1) mandates the function on the **online interface** (the site) — covered by the My
Account/order area + the permanent public page; **Recital 37** *suggests* "hyperlinks leading the consumer to the
withdrawal function" (e-mails), which we satisfy automatically on WC + EDD and via the documented merge-tag on
FluentCart. No new hooks invented. 7 new admin strings, all 6 locales 100% (545/545). PHP `php -l` clean.

### Automations: read-only REST API + signed outbound webhook (1.0.0-alpha.44, 2026-06-16)
External systems (Zapier / Make / n8n / a CRM / helpdesk) can now **read** withdrawal requests and be
**notified** the instant a withdrawal is confirmed — without ever exposing the consumer's raw IP. Two surfaces,
both designed PII-first:

- **Read-only REST API** (`wwu-wb/v1`), authenticated with a WordPress **Application Password** + the plugin
  admin capability (no custom key system, no nonce — Application-Password requests carry none), rate-limited,
  HTTPS-recommended, all `GET`:
  - `GET /requests` — paginated confirmed requests (lean rows: `request_uid, platform, order_ref, order_number,
    status, country, within_window, created_at` — **never** the email or IP). Filters: `page`, `per_page`
    (cap 100), `platform`, `status` (open/processed/refunded), `after`/`before` (ISO date).
  - `GET /requests/{request_uid}` — one request, adding `consumer_email`, the partial `products` selection,
    `submitted_at`, `days_left` and the evidence `row_hash` (for external integrity checks) — **never** the IP
    or chain internals.
  - `GET /orders/{platform}/{order_ref}/withdrawal` — per-order status (`{withdrawn, status, request_uid?,
    created_at?}`; 404 when the order is unknown).
- **Outbound webhook** on `wwu_wb_withdrawal_confirmed` (opt-in under **Settings → Integrations**): an
  async, **HMAC-SHA256-signed** `POST` (`X-WWU-WB-Signature: sha256=…`, `X-WWU-WB-Event`, `X-WWU-WB-Delivery`)
  with a JSON payload (incl. `consumer_email` + `row_hash`, never the IP). The endpoint URL is validated through
  the `OutboundUrlGuard` SSRF guard at **save time and again at send time** (DNS-rebinding / TOCTOU defence),
  delivered with `redirection => 0` + `reject_unsafe_urls => true`, one retry on transport error. Signing secret
  stored autoload-off, shown only masked, never logged. Filter `wwu_wb_webhook_payload`, action
  `wwu_wb_webhook_delivered`.

There is deliberately **no endpoint to create a withdrawal** — a withdrawal is the consumer's own legal
declaration and must not be fabricated via an API. Write/mutation endpoints are likewise out of scope for now.
Dedicated **Opus security audit** (PII surface, Standard #13) — verdict **SHIP**, 0 critical/high/medium
(confirmed: no email/IP in any list row, prepared-statement correctness in the dynamic WHERE/`IN()`/EXISTS SQL,
dual SSRF check, secret hygiene, async boundary carries only the request_uid). New `Api\RequestReader` /
`Api\Webhook` / `Api\WebhookDispatcher` / `REST\Routes\ApiRoutes`; new option `wwu_wb_webhook` (autoload no);
new smoke suite `automations`. PHPStan L2 + class-scan + `php -l` clean. New
[REST API reference](../reference/wwu-wb-rest-api-REFERENCE.md); see also the
[spec](../specs/wwu-wb-rest-api-automations-SPEC.md) and the
[hooks reference](../reference/wwu-wb-hooks-filters-REFERENCE.md).

### Consumer "why exempt" transparency note (1.0.0-alpha.43, 2026-06-16)
When an order is exempt from the right of withdrawal under **Art. 59** (every item carries a captured exemption
— e.g. digital content with immediate access, or a service fully performed), the withdrawal button is absent.
Until now the consumer simply saw nothing; this adds a short, accurate **"why" note** on the order surfaces —
naming the matched statutory exception(s) + legal reference and noting the consumer's prior express consent at
checkout. New `ExemptionNoteRenderer` (shared helper), wired into the public form / Gutenberg block, the
WooCommerce My Account order detail, the FluentCart portal and the EDD customer surfaces. **Strictly gated +
fail-safe:** rendered ONLY when the applicability reason is `no_withdrawal_right` AND the per-item reasons
genuinely resolve to a non-seal-based Art. 59 exception — never on ordinary, out-of-scope, renewal or B2B
orders, and never when the exemption isn't evidence-backed (empty → nothing). The copy is merchant-overridable
(`wwu_wb_settings['custom_exemption_note']`, mirrors `custom_guidance`) and filterable
(`wwu_wb_exemption_note_text`). The button-visibility logic is unchanged. All 6 locales 100% (517/517).
PHPStan L2 + class-scan clean; new smoke suite `exemption_note`. See
[spec](specs/wwu-wb-exemption-why-note-SPEC.md).

### Optional "which products" field in the withdrawal form — partial withdrawal (1.0.0-alpha.42, 2026-06-16)
A consumer can now indicate **which products** of an order they are withdrawing from — EU law allows partial
withdrawal (the Annex I-B model form declares withdrawal "of the following goods"; it is not all-or-nothing).
An **optional** per-item checklist appears in step 1 of the withdrawal form (rendered only when the order's
line items are readable, **all unchecked by default**). Leaving it empty = withdrawal from the **whole order**,
exactly as before — it is never a validation gate (`WithdrawalRequest::is_valid()` unchanged; fail-open). The
selection (`statement.products`, mirroring the optional `reason` field) flows into the immutable evidence log,
the durable-medium acknowledgement (e-mail + PDF — a conditional "Products withdrawn" row) and a new
**Products** column in the admin Requests dashboard. **Informational only** — the merchant still processes the
(full or partial) refund manually. Sanitised array (50 items × 200 chars caps). All 6 locales 100% (510/510).
PHPStan L2 + class-scan clean; 5 new smoke assertions (`suite_withdrawal_request`). Implementation mirrored the
`reason` field across the form / REST + no-JS handlers / receipt / dashboard. See
[spec](specs/wwu-wb-partial-withdrawal-products-SPEC.md).

### Configurable FluentCart handling + auto-defer to a native add-on (1.0.0-alpha.41, 2026-06-15)
FluentCart confirmed it will ship its own withdrawal add-on. To avoid two buttons, a new
**Settings → FluentCart → Withdrawal handling** control (`wwu_wb_settings['fluentcart_mode']`, default
`auto`) governs our FluentCart surfaces:
- **Auto** (default) — render our FluentCart button / checkout consent / e-mail link / public form UNLESS
  FluentCart's native withdrawal add-on is detected, then step aside (no duplicate).
- **Always** — keep ours regardless of any native add-on.
- **Off** — never render our FluentCart surfaces.

Implemented as `FluentCartAdapter::should_render()` (+ `mode()` + `native_addon_active()`), consulted by the
**four consumer entry points only** — `FluentCartPortal`, `FluentCartCheckoutConsent`,
`FluentCartWithdrawalTag` and `EligibleOrders::collect_fluentcart()` (public form). The **admin Requests
dashboard and any in-flight durable-medium confirmation are untouched**, so a FluentCart withdrawal already
recorded is never stranded when handling is turned off (`is_active()` keeps its pure-presence meaning — the
suppression lives at the surfaces, not the registry). Native-add-on detection is **filterable** via
`wwu_wb_fluentcart_native_active`; its exact class/constant signal is pending from FluentCart, so Auto never
auto-defers until that filter (or a future build) wires the real check. 8 new smoke assertions in
`suite_fluentcart`. PHPStan level 2 clean; class scan clean. See
[spec](specs/wwu-wb-fluentcart-handling-mode-SPEC.md).

### UI Kit bundled + Swedish (sv_SE) added (1.0.0-alpha.40, 2026-06-15)
- **UI Kit now bundled** (`assets/ui-kit/` — css/js/dist/php, kit 0.9.2). The admin code referenced the
  WWU UI Kit (`.wwu-ui-accordion/badge/notice` + `maybe_enqueue_ui_kit()`) but the kit assets were never
  packaged, so the guarded loader silently no-op'd and **Settings → Exemptions rendered unstyled**. Fixed.
  Also corrected `AdminAssets` enqueue list: `['accordion','badge','utilities']` (the components actually
  used) — the old list requested unused components + an invalid `notice` id (`.wwu-ui-notice` lives in
  `utilities`). The kit is a **runtime asset** → it ships in the zip (unlike dev tools).
- **Swedish (sv_SE)** added. `LabelResolver` gains the statutory `sv` entry — button **"ångra avtalet här"**,
  confirm **"bekräfta frånträde"**, authority **"Distansavtalslagen (2005:59)"** — sourced from the official
  Swedish EUR-Lex text of Art. 11a (Dir. 2011/83/EU as amended by 2023/2673). `Countries::COUNTRY_LOCALE`
  maps `SE → sv`. The **complete** `sv_SE.po`/`.mo` (495/495 UI strings, 0 fuzzy) ships; a native-speaker
  review (Daniel) is still **pending** but every string is translated. The two former fuzzy guesses were
  corrected — notably *durable medium* → **"varaktigt medium"** (the Distansavtalslagen legal term, not the
  mistranslation "hållbart medium"). See [Swedish note](../analysis/wwu-wb-swedish-sv_SE-NOTE.md).
- New workspace dev tools (NOT shipped — live in `wwu-tools/`, outside the plugin repo + zip):
  `wwu-class-scan.php` (catches the bare-class fatal that `php -l` misses — the alpha.38/39 bug class),
  `wwu-phpstan.php` (PHPStan + WP/WC stubs, parametrized per slug), and `wwu-po-fill.php` (extract/inject
  `.po` translations without loading the full catalogue into an agent context — used to complete sv_SE).
  The plugin is clean on PHPStan + the class scan.

### Critical fix — Settings page fatal "class not found" (1.0.0-alpha.39, 2026-06-15)
A merchant reported a **fatal error** (`Uncaught Error: Class "WWU\WithdrawalButton\Admin\Settings"
not found`) that took down the **entire Settings page**. Root cause: `SettingsPage::render_exemptions_status()`
called `Settings::main()` **without** importing the class, so PHP resolved the unqualified `Settings`
against the current namespace (`…\Admin\Settings`, which does not exist) and threw. **Fix:** added
`use WWU\WithdrawalButton\Core\Settings;`. **`php -l` does not catch this** (it's runtime class
resolution, not a syntax error) — so a token-based scanner was run across **all 91 `src/` files** and
confirmed this was the **only** such bare-class fatal; every other WWU and external (`WC_*`/`WP_*`/
`DateTime`/…) reference is either imported or fully qualified. This release also carries the WooCommerce
+ conflict audit and the mail-filter leak fix below.

### WooCommerce + conflict audit; mail-filter leak fix (1.0.0-alpha.39, 2026-06-15)
Inline WooCommerce-surface + cross-plugin **conflict** audit (the multi-agent workflow was blocked
3× by an Anthropic server-side rate limit, so it was done file-by-file) —
[report](../audits/wwu-wb-woocommerce-2026-06-15-AUDIT.md). **0 critical / 0 high.**
- **Fix (Low, conflict-safety):** `Mail\Mailer::send_html` now removes the `wp_mail_content_type`
  filter in a `try/finally`. Previously a throw from `wp_mail()` (or a third-party hook inside it)
  could leave the filter forced to `text/html`, turning other plugins' plain-text emails into HTML
  for the rest of the request.
- **Clean:** HPOS purity (all order I/O via `wc_get_order`/`WC_Order` + `update_meta_data()+save()`),
  classic + block consent capture (fail-safe, PII-free log, shared idempotency), refund recorder,
  My Account (owner-bound, no IDOR), and the whole conflict surface — every handle/shortcode/
  admin-post/block/REST/option/meta is namespaced; `ob_start` is template-scoped; `script_loader_tag`
  is handle-scoped; `flush_rewrite_rules` runs only on activation + slug change.
- **Documented for the live test (no code change):** the withdrawal order-status transition fires
  other plugins' `woocommerce_order_status_changed` listeners (by design); the button also renders
  on the order-received/thank-you page; the block-checkout Store API field-schema + `_wc_other/`
  meta key want a real WC 9.9+ verification (fail-safe either way).

### Subscription-aware withdrawal (1.0.0-alpha.38, 2026-06-15)
Implements the [subscriptions × withdrawal SPEC](../specs/wwu-wb-subscriptions-withdrawal-SPEC.md).
EU law gives **one** 14-day right of withdrawal **per contract**, starting at conclusion (Art. 9 CRD /
art. 52 Cod. Consumo) — a **renewal does not restart it**. So the button shows on the **initial order
only** and is suppressed on renewals, through a single gate that covers all 8 button surfaces.
- **`Platform\NormalizedOrder`** gained `$is_renewal` + `$subscription_ref` (both optional, appended last —
  back-compat with every existing constructor call).
- **`Platform\SubscriptionAware`** (new optional interface): `is_renewal_order()` (MUST fail open),
  `subscription_ref()`, `cancel_subscription()`. Implemented by all three adapters behind `function_exists`/
  `class_exists` guards so stores **without** a subscription plugin are untouched and the 23-method
  `OrderDataSource` contract is unchanged.
  - **WooCommerce** — `wcs_order_contains_renewal()` (fallback `_subscription_renewal` meta);
    `wcs_get_subscriptions_for_order()` for the ref + cancel (`can_be_updated_to('cancelled')` →
    `update_status('cancelled')`).
  - **FluentCart** — initial order detected via `Subscription::where('parent_order_id', …)`; renewal marker
    is best-effort (order `type`) pending FluentCart confirmation, fail-open; cancel via
    `cancelRemoteSubscription()` → `cancel()` → status flip.
  - **EDD** — `EDD_Subscriptions_DB::get_subscriptions(['parent_payment_id'=>…])` + `EDD_Subscription`
    (`can_cancel()`/`cancel()`); renewal status `edd_subscription`, fail-open.
- **`Domain\ApplicabilityResolver::evaluate()`** — single `renewal_order` gate after status-eligibility,
  before B2B. Suppressed unless `treat_renewals_as_withdrawable` is on.
- **`Shortcodes::form()`** — applicability guard so a renewal reached via `[wwu_wb_form order_id=…]` cannot
  render the two-step form either (matches the button surfaces).
- **`Domain\WithdrawalService::confirm()`** — on confirm, stamps `is_subscription_initial` +
  `subscription_ref` on the order; if the merchant opted in to auto-cancel, cancels the subscription and
  writes a `subscription_cancelled` evidence row + an order note (refund/pro-rata stay manual). New filter
  `wwu_wb_subscription_cancel_result`, action retained.
- **Settings → Subscriptions** (new section) — two opt-in toggles, both **off** by default:
  `treat_renewals_as_withdrawable`, `cancel_subscription_on_withdrawal`. Standard #12: each ships a hint, a
  legal note and a worked example, plus a detected-plugin notice.
- **`Admin\RequestsDashboard`** — subscription orders get a **"Subscription"** badge + a reminder (stop
  renewals, apply any pro-rata, then refund — none automatic unless auto-cancel is on).
- New filter `wwu_wb_order_is_renewal` (override detection). Renewal detection is guarded and **fail-open**:
  an undetermined state keeps the button visible (over-showing is the safe failure for a consumer right).
- **Needs a live test** with WooCommerce Subscriptions / FluentCart subscriptions / EDD Recurring active.
  Lint: PHP 0 errors across 8 touched + 1 new file.

### FluentCart e-mail merge-tag `{{wwu.recesso_url}}` (1.0.0-alpha.37, 2026-06-15)
The FluentCart team confirmed the value-resolver hook contract (2026-06-15) — see
[FluentCart hooks analysis](../analysis/wwu-wb-fluentcart-hooks-ANALYSIS.md) §"Third verification round" —
so the long-deferred e-mail merge-tag is now implementable safely.
- **`Mail\FluentCartWithdrawalTag`** (new) registers `{{wwu.recesso_url}}` in the FluentCart e-mail-editor
  picker (`fluent_cart/editor_shortcodes`) and resolves it at send time (`fluent_cart/smartcode_fallback`).
  Implements the verified `$data` contract: shape-tolerant callback (2-arg `($code,$data)` **or** 4-arg
  `($value,$code,$data,$conditions)`, never clobbering a non-matching value) **and the team's required
  `$data['order']` presence check** — `$data` can be EMPTY in footers / generic parsing, so the tag renders
  `''` there. Same fail-safe gates as every surface (enabled + applicability `show` + a configured public
  form page); the URL carries the order's own key (`order_hash`/`uuid`) for guest auth, like `OrderEmailLink`.
  Wired in `Plugin.php` (FluentCart-active block). **Needs a live FluentCart test** (the resolver shape
  can't be exercised without FluentCart); fail-safe until then.
- README "known issues" note flipped from "deferred" to "implemented (needs live test)".
- **⚠ Strategic note:** the FluentCart team also said they are shipping a **native EU withdrawal feature
  soon**, which may overlap our FluentCart-specific surfaces. Recorded in the analysis doc; positioning to
  be decided once its scope/timeline are known (questions tracked in `_internal/`).
- Lint: PHP 0 errors (1 new file + Plugin.php). No consumer-facing behaviour change.

### Security hardening — full-plugin audit fixes (1.0.0-alpha.36, 2026-06-15)
A comprehensive whole-plugin security audit (10 dimensions, multi-agent + adversarial verification —
[report](../audits/wwu-wb-full-security-2026-06-15-AUDIT.md)) returned **0 critical / 0 high**: SQLi, XSS,
CSRF, AuthZ/IDOR (incl. the new `EddCustomerOrders`), file/path/deserialization, crypto/evidence-integrity
and the Dompdf 3.1.5 dependency all clean. One Medium + a cluster of Low items were found and fixed here.
- **SSRF guard (Medium)** — new `Security\OutboundUrlGuard` rejects internal / cloud-metadata
  (`169.254.169.254`) / loopback / private / CGNAT / IPv4-mapped-IPv6 targets for the merchant-configured
  **RFC 3161 TSA endpoint** (resolves A/AAAA, fail-closed on unresolvable). Wired into
  `Rfc3161Provider::endpoint_is_valid()` (request-time, with the existing `redirection=>0`) **and**
  `SettingsPage::handle_save()` (never persists an unsafe endpoint). `wp_http_validate_url()` alone was
  insufficient (no IPv6, misses 169.254/16 + 100.64/10). Mirrors WWU Pixel Manager's `SgtmGuard`.
- **Rate limiting (Low)** — `GuestAccess::check_rate_limit()` (10/5 min per IP) now also gates
  `WithdrawalRoute::statement()`/`confirm()` and the no-JS `NoScriptFlow` handlers (were only on `lookup` +
  receipt), preventing authorised-credential griefing/log-bloat.
- **Input caps (Low)** — `WithdrawalRequest::from_input()` length-caps name (200) / order_ref (100) /
  email (254) / reason (2000), so an oversized field can't bloat the append-only log or heavy PDF renders.
- **Debug masking (Low)** — added `'pass'` to `Collector::SECRET_KEY_HINTS` (the 4-char RFC3161 `pass` key
  was below the substring hints; no active leak, latent).
- **Uninstall (Low)** — clear the `wwu_wb_consent_retention_purge` cron on uninstall.
- **Tracked (not changed):** `customer_email` column excluded from the hash chain (needs a chain-format
  decision); the email-in-immutable-log GDPR trade-off (documented). The multisite-uninstall batching item
  was **refuted** as a security finding (super-admin hygiene).
- Lint: PHP 0 errors (1 new file + 7 changed). No consumer-facing behaviour change.

### EDD integration completed — customer-facing withdrawal button + e-mail link (1.0.0-alpha.35, 2026-06-15)
Closes the EDD gap: the statutory withdrawal button — the plugin's single most important surface — now
appears on the EDD customer's own pages, reaching full parity with WooCommerce (`WooMyAccount`) and
FluentCart (`FluentCartPortal`). Previously EDD was a data-adapter + checkout-consent integration only, and
EDD customers could reach the form only via the standalone public page. Hooks verified against the official
EDD source first — see [EDD customer surfaces analysis](../analysis/wwu-wb-edd-customer-surfaces-ANALYSIS.md).
- **`Frontend\EddCustomerOrders`** (new) — injects the withdrawal button on the **purchase receipt**
  (`edd_order_receipt_after_table`) and at the end of each **purchase-history** order row
  (`edd_order_history_row_end`), and appends the withdrawal **link to the receipt e-mail** body
  (`edd_order_receipt` filter, EDD 3.2.0+, with the legacy `edd_purchase_receipt` filter wired for 3.0–3.1
  and de-duplicated so 3.2+ never appends twice). All EDD 3.x hooks (the legacy 2.x `edd_payment_receipt_*`
  / `edd_purchase_history_row_*` hooks were **removed** from the 3.x templates — using them would have been a
  silent dead button).
- The button links to the standalone public form page pre-authenticated with the EDD **payment key**
  (`?wwu_wb_order=&key=`), exactly like the WooCommerce order-email link; EDD has no routable My Account
  endpoint, so the public page remains the form host.
- **Fail-safe everywhere:** renders nothing on ineligible orders, shows the localized status notice when a
  request already exists, and skips when no public page is configured. Inline button styles (EDD
  receipt/history pages don't load the plugin stylesheet). No new i18n strings (reuses the statutory label).
- Wired in `Plugin.php` in the EDD-active block. Lint: PHP 0 errors. **Needs a live EDD test** — see the EDD
  evaluator checklist.

### Docs — end-to-end "try the plugin" evaluator checklists (docs-only, 2026-06-15)
Adds a full `docs/testing/` suite so anyone can evaluate the plugin on a staging store, plus an index
([docs/testing/README.md](../testing/README.md)). Grounded in a code-recon pass (accurate shortcodes,
blocks, hooks, admin slugs).
- **3 end-to-end evaluator checklists** — one per platform — covering install → withdrawal button/entry
  points → two-step statement→confirmation (incl. the no-JS `admin-post.php` fallback) → durable medium
  (acknowledgement e-mail + PDF + verifiable `/verify/{uid}` link) → evidence-log chain-integrity →
  merchant processing (refund + "Mark processed" + resend) → exemptions → Compliance helpers → uninstall
  (legal-hold default): `wwu-wb-try-the-plugin-{woocommerce,fluentcart,edd}-CHECKLIST.md`.
- Documents the real per-platform entry surfaces: **WooCommerce** 3 (My Account orders action / order
  detail / "Right of withdrawal" tab), **FluentCart** 4 (portal endpoint / sidebar / dashboard banner /
  per-order button), **EDD** receipt + purchase-history button + receipt-e-mail link (added in alpha.35),
  with the standalone public page / payment-key link / guest lookup as alternative entry points.
- No code change; the 3 exemption consent-capture checklists from alpha.34 are cross-linked.

### FluentCart — team-verified improvements + 3 live-test checklists (1.0.0-alpha.34, 2026-06-15)
Acts on a direct FluentCart team reply (2026-06-15) confirming the integration mechanics; see
[FluentCart hooks analysis](../analysis/wwu-wb-fluentcart-hooks-ANALYSIS.md) §"Second verification round".
- **Render hook → `fluent_cart/before_payment_methods`** (was `after_payment_methods`). The team's
  recommended slot fires in the **standard, modal AND block** checkout renderers. The FluentCart block
  checkout runs FluentCart's own checkout-form flow (not the WooCommerce Store API), so these hooks
  already cover it — no separate block path needed (unlike WooCommerce).
- **FluentCart exemptions are now category-aware.** New `FluentCartAdapter::category_ids_for_post()`
  (`taxonomy_exists()`-guarded) resolves the **`product-categories`** taxonomy (team-confirmed);
  `map_items()` fills `category_ids`; checkout render/validate/capture pass categories to
  `ExemptionResolver::reason_for()`. Closes the "product-ID only" gap noted in alpha.30–33 — parity
  with WooCommerce (`product_cat`) and EDD (`download_category`).
- **Admin order-timeline note via `fluent_cart_add_log()`** (confirmed signature), with a guarded
  fallback to `$order->addNote()` then per-order meta — withdrawal/refund notes now show in the
  FluentCart order activity timeline.
- **Confirmed, no code change:** custom fields ARE submitted inside the checkout `<form>` (unchecked
  checkboxes absent → treated as "no"); `$order->getViewUrl('admin')` route; `fluent_cart/order_paid`
  exists (use `order_paid_done` for async side-effects); `smartcode_fallback` 4-arg resolver signature
  (team-confirmed via reply but still absent from FluentCart's public hooks pages — support-claim-only
  until publicly documented or live-tested; for the still-deferred email merge-tag); custom-status +
  `effective_from` notes for future work.
- **3 live-test checklists** under `docs/testing/` so anyone can run the remaining live tests —
  WooCommerce **block** Checkout, **FluentCart**, **EDD** — each with setup, happy-path, category-aware
  and fail-safe sections.
- Lint: PHP 0 errors (2 files changed). One new admin-facing string ("Withdrawal evidence recorded")
  added to the catalogue and translated (IT/FR/ES/DE).

### Third platform — Easy Digital Downloads (EDD) adapter + checkout consent capture (1.0.0-alpha.33, 2026-06-14)
Adds **Easy Digital Downloads 3.0+** as a third supported platform (after WooCommerce +
FluentCart), implementing the [EDD SPEC](../specs/wwu-wb-edd-integration-SPEC.md) on the
official-source-verified surface.
- **`Platform\EddAdapter`** (`OrderDataSource`) — loads orders via `edd_get_order()` →
  `EDD\Orders\Order`, normalises email / WP user id / status (`complete` → eligible) /
  refund (`refunded`/`partially_refunded`) / line items (downloads, **category-aware** via
  `download_category`) / billing country; ownership via `user_id`, guest via `payment_key`;
  notes via `edd_add_note`; plugin meta in first-class EDD order meta (`edd_*_order_meta`,
  prefix `wwu_wb_`). All calls guarded (version-tolerant), per-request cache.
- **`Frontend\EddCheckoutConsent`** — renders the required conditional acknowledgement on
  `edd_purchase_form_before_submit`, blocks via `edd_checkout_error_checks` (`edd_set_error`),
  and captures on `edd_built_order` ($order_id + $_POST both available at checkout time, unlike
  `edd_complete_purchase`). Reuses `WooCheckoutConsent::build_consent_entries` +
  `ExemptionConfirmation` (durable-medium e-mail) + the PII-free immutable-log event;
  `ConsentReader` reads the consent through the adapter, unchanged. **EDD exemptions are
  category-aware** (better than FluentCart's product-ID-only).
- **`PlatformRegistry`** registers `EddAdapter` (active when `edd_get_order` exists);
  **`RequestsDashboard`** "Open order" link uses `edd_get_admin_url(...)` for EDD.
- Smoke: `EddAdapter::eligible_status('complete') → 'completed'`. No new i18n strings (reuses
  existing). **Needs a live EDD test.** Consent capture now spans **WooCommerce (classic +
  block), FluentCart and EDD**.

### Exemptions — WooCommerce block Checkout consent capture + EDD integration SPEC (1.0.0-alpha.32, 2026-06-14)
Closes the last consent-capture gap (WooCommerce **block** Checkout) and plans the next
platform (Easy Digital Downloads). Built on a verified official-docs research pass.
- **`Frontend\WooBlockCheckoutConsent`** — captures consent on the block-based Checkout via the
  official **Additional Checkout Fields API** (pure PHP, no JS build; WooCommerce **9.9.0+**).
  Registers a required `checkbox` (location `order`) per conditional reason, gated by a
  JSON-Schema `required`/`hidden` on `cart.items` so it only appears/requires when the cart
  contains a tagged product (product-ID gating). WooCommerce validates it **server-side** on the
  Store API request and persists it to order meta automatically; on
  `woocommerce_store_api_checkout_order_processed` we read the value, re-derive the order's
  conditional items via the verified order path (which **does** resolve categories), and run the
  same capture as classic checkout (`build_consent_entries` + durable-medium e-mail + PII-free
  log). Classic and block paths share the `_wwu_wb_consent` / `consent_logged` meta + idempotency
  guard — never a double capture. No-op (fail-safe) on WC < 9.9 or the shortcode checkout.
  Conditional product-id gating is cached in a transient, refreshed on settings save.
- **Cross-platform parity reached** — exemption consent capture now works on **WooCommerce
  (classic + block) and FluentCart**. No remaining WooCommerce capture gap.
- **EDD integration SPEC** ([docs/specs/wwu-wb-edd-integration-SPEC.md](../specs/wwu-wb-edd-integration-SPEC.md))
  — design for a third platform adapter (Easy Digital Downloads 3.0+), with the integration
  surface verified against official EDD sources (`edd_get_order`/`EDD\Orders\*`, `edd_*_order_meta`,
  `edd_purchase_form_*` + `edd_checkout_error_checks` + `edd_complete_purchase`, `download_category`,
  `edd_get_admin_url`). EDD exemptions will be **category-aware** (unlike FluentCart). Not yet
  implemented — awaiting confirmation.
- **Needs a live block-checkout test.** The classic-checkout path is unchanged.

### Exemptions management UX + full i18n (1.0.0-alpha.31, 2026-06-14)
Merchant-experience pass on the Art. 59 exemptions ([SPEC](../specs/wwu-wb-exemptions-ux-SPEC.md)).
The core withdrawal button is untouched; this only makes the *exemption* setup legible and
fixes the Italian (and FR/ES/DE) translations the merchant saw in English.
- **Grouped, kit-based settings** — the ~13 reasons are now organised into three WWU UI Kit
  accordions (**Conditional — need consent / Unconditional — exempt by nature / Seal-based —
  assess on return**), each reason with a `?` tooltip (the hint), a collapsible **example**
  (Standard #12, input→outcome), and the existing product/category-ID inputs. `ExceptionTypes::group()`
  derives the bucket from the existing flags; each reason ships an `example`.
- **Guided "What do you sell?" helper** — five cards (event tickets / digital downloads &
  recordings / live sessions like Zoom / immediate services / physical goods) explain the correct
  Art. 59 reason and link to the matching group. Suggest-only — it never writes IDs (stays fail-safe).
- **"What the consumer sees" preview** — under each conditional reason, a preview of the exact
  checkbox wording + the durable-medium confirmation e-mail, built by the same code the consumer
  e-mail uses (`ExemptionConfirmation::preview_html()`), so the preview can't drift.
- **Exemptions status panel** — a UI Kit notice with badges: reasons configured, product/category
  ID counts, IP-capture on/off, retention years, and when the IP-purge last ran
  (`wwu_wb_consent_last_purge`).
- **Landing** — a dedicated "Vendi biglietti, corsi o contenuti digitali?" section with the three
  worked examples (events / recordings / Zoom) + the fail-safe line.
- **Full i18n** — completed all previously-untranslated IT strings (incl. the exemption-reason
  catalogue) + the new UX strings, in IT/EN/FR/ES/DE; `.mo` recompiled (target 0 untranslated).
- Smoke suite `consent` extended (group derivation, every reason has an example, preview present
  for conditional / empty for unconditional).

### Exemptions — FluentCart checkout consent capture + canonical admin order URL (1.0.0-alpha.30, 2026-06-14)
Brings the exemption consent capture to **FluentCart**, reaching cross-platform parity
with WooCommerce, after FluentCart support answered our integration questions and each hook
was **re-verified against the official docs**
([analysis](../analysis/wwu-wb-fluentcart-hooks-ANALYSIS.md) — the verification caught real
discrepancies vs the support reply).
- **`Frontend\FluentCartCheckoutConsent`** — mirrors `WooCheckoutConsent` on the verified
  FluentCart hooks: render on `fluent_cart/after_payment_methods` (action), block on
  `fluent_cart/checkout/validate_before_process` (filter → `WP_Error`), capture on
  `fluent_cart/checkout/prepare_other_data` (action). The **authoritative capture** reads the
  order's line items via the adapter's already-verified `order_items` path (never the
  unverified Cart shape); render/validate read the cart **best-effort + guarded**, so if the
  cart can't be read no checkbox is shown and checkout is not blocked — **fail-safe** (the
  button stays; no path wrongly hides it). Reuses `WooCheckoutConsent::build_consent_entries`
  + `Mail\ExemptionConfirmation` (durable-medium e-mail + dispatch log) + the PII-free
  immutable-log event; `Frontend\ConsentReader` already reads it platform-agnostically.
- **Note:** FluentCart line items don't resolve product categories, so FluentCart exemptions
  match by **product ID only** (category tagging is a no-op there until categories are resolved).
- **Canonical admin order URL** — `RequestsDashboard`'s "Open order (refund)" link now uses
  the FluentCart Order model's `getViewUrl('admin')` (verified), falling back to the canonical
  SPA route `admin.php?page=fluent-cart#/orders/{id}/view` (with the `/view` suffix). Still
  filterable via `wwu_wb_order_admin_url`.
- **Verified-hooks reference** recorded in the FluentCart analysis doc (confirmed vs docs:
  checkout render/validate/prepare hooks, Order meta API, `getViewUrl`; discrepancies flagged:
  `subscription_canceled` is `fluent_cart/payments/subscription_canceled`, `order_paid` does
  not exist → use `order_paid_done`; `smartcode_fallback`/`editor_shortcodes` + `cancelRemoteSubscription`
  `effective_from` are support-claim-only, deferred until live-tested).
- **Needs a live FluentCart test** (whether a custom checkout field survives the FluentCart
  submission is version-dependent). Until verified, the fail-safe holds. Remaining capture gap:
  WooCommerce **block** Checkout (Store API).

### Exemptions feature — P3: durable-medium confirmation + evidence, retention, GDPR (1.0.0-alpha.29, 2026-06-14)
Closes the gaps an official-source legal review surfaced
([legal note](../legal/wwu-wb-exemption-consent-evidence-NOTE.md), verified against EUR-Lex /
Gazzetta Ufficiale / Garante + EDPB). The checkout capture (P2) proved *what* the consumer
accepted; this phase delivers the rest the law actually requires.
- **Durable-medium confirmation e-mail (`ExemptionConfirmation`)** — for the digital exemption
  the confirmation on a durable medium is **constitutive** (Art. 16(1)(m)(iii) + 14(4)(b)(iii)
  CRD = Art. 59(1)(o) CdC): without it the exemption does not hold. On order creation the
  plugin now e-mails the consumer a confirmation **reproducing the verbatim consent +
  acknowledgement** wording, before performance begins, for both conditional reasons, and
  **logs the dispatch as its own immutable-log event** (`exemption_confirmation_sent`) — the
  consent log alone does not prove delivery, so the two legal acts are logged separately.
- **Retention + purge (`ConsentRetention`)** — a daily cron anonymises the IP on stored
  consents once the configurable horizon (`retention_years`, default 10y per art. 2946 c.c.)
  lapses. GDPR storage limitation (Art. 5(1)(e) + recital 39) requires a deletion horizon — an
  "immutable forever" record is itself a defect. The consent immutable-log events are now
  **PII-free** (text hash + reason + timestamp only); the IP lives **only** on the purgeable
  order meta, so the hash chain stays verifiable while the personal data is erasable.
- **Configurable IP capture** — `wwu_wb_settings['consent_capture_ip']` (default on). The IP is
  the most exposed field under the GDPR strict-necessity test, so the merchant can turn it off;
  the wording + hash + timestamp remain.
- **GDPR privacy clause** — a second ready-to-paste clause (`consent_privacy`, IT/EN) covers the
  consent-evidence processing with the correct basis: **legitimate interest (Art. 6(1)(f))**,
  retention tied to the limitation period, Art. 21 objection, Art. 17(3)(e) limit — **not** GDPR
  consent. Surfaced on the Compliance page.
- **"Consent records" admin page** — a paginated, CSV-exportable list (order, reason, date,
  text hash, IP/anonymised, confirmation status), framed as **evidence to discharge the burden
  of proof, not a legally-named "register"**. CSV-injection guarded.
- **Copy-everywhere** — Settings exemptions section, the conditional-reason hints, the dashboard
  "why the button might not show", README + readme.txt FAQ/Privacy and the marketing docs now
  state clearly: **physical products never need consent**; only the two conditional reasons do;
  the plugin captures consent first, then hides the button; without consent the button stays
  (fail-safe); and the stored consent is **evidence**, not a register.
- **Legal note** `docs/legal/wwu-wb-exemption-consent-evidence-NOTE.md` — the verified basis the
  above is built on (no named register; durable-medium constitutive for digital; 10y defensible
  retention; lawful basis = legitimate interest; tamper-evident anchoring is best practice, not a
  mandate). **Not legal advice** — re-verify the in-force CdC text on Normattiva (post D.Lgs.
  26/2023) and have counsel validate the final copy.
- Smoke suite `consent` extended (privacy clause present IT/EN; confirmation no-op guards).

### Exemptions feature — P2: checkout consent capture (1.0.0-alpha.28, 2026-06-14)
Second phase of the Art. 59 / Art. 16 exemptions feature
([SPEC](../specs/wwu-wb-withdrawal-exemptions-SPEC.md)). The two **conditional**
exemptions (digital immediate access 59_o, service fully performed 59_a) only remove
the right of withdrawal when the consumer gave **prior express consent** AND
**acknowledged losing the right**. This phase captures exactly that at the WooCommerce
checkout, so those items are hidden from the button **only once the consent exists** —
the missing half that made conditional reasons fail-safe (button always shown) until now.
- **`WooCheckoutConsent`** (WooCommerce classic checkout) — detects conditional-exempt
  items in the cart and renders one **required acknowledgement checkbox per reason**
  (`woocommerce_review_order_before_submit`), blocks checkout server-side until each is
  ticked (`woocommerce_checkout_process`), and stores the agreed wording **verbatim +
  SHA-256 hash + timestamp + IP** on the order meta `_wwu_wb_consent`
  (`woocommerce_checkout_create_order`). After the order exists it writes an order note +
  an **append-only immutable-log event** (`exemption_consent`) as durable evidence.
- **`ConsentText`** — statutory acknowledgement wording per consent kind (digital /
  service), i18n in IT/EN/FR/ES/DE, fully overridable via the new **`wwu_wb_consent_text`**
  filter. The exact text the consumer agreed to is stored on the order, so it is
  reconstructable later even if the default changes.
- **`ConsentReader`** — feeds the stored consent back to the evaluator through the
  existing `wwu_wb_exemption_consent` filter, **platform-agnostically** (reads the order
  meta via the adapter): WooCommerce works today; FluentCart will work as soon as its
  checkout-capture hook lands (asked to the FluentCart team) — the read side needs no
  further change.
- **`ExemptionResolver::reason_for($product_id, $category_ids)`** — order-independent
  reason lookup so the same per-reason map drives both the placed-order evaluator and the
  cart at checkout.
- **Settings copy updated** — the Exemptions section now states that the consent tick-box
  is added automatically on the WooCommerce checkout and points at the `wwu_wb_consent_text`
  filter.
- **Smoke suite `consent`** — wording per kind + filterability, order-independent reason
  lookup, and the storable-entry builder (only ticked + conditional reasons produce
  entries). The evaluator round-trip stays covered by suite `exemptions`.
- **Scope:** classic (PHP/shortcode) WooCommerce checkout. The block-based Checkout
  (Store API) and FluentCart checkout capture are tracked follow-ups; until then those
  paths keep the button (fail-safe). P3 (consumer-facing transparency copy + durable-medium
  confirmation line in the email) is the next phase.

### Exemptions feature — P1: per-reason tagging + evaluator (1.0.0-alpha.27, 2026-06-14)
First phase of the Art. 59 / Art. 16 product/service exemptions feature
([SPEC](../specs/wwu-wb-withdrawal-exemptions-SPEC.md)). The withdrawal right stays
the default (digital included); the merchant now tags only what the law actually
exempts, by **specific statutory reason** — not an opaque boolean.
- **`ExceptionTypes` registry** — one entry per Art. 59 letter `{ id, label, legal_ref,
  conditional, consent_kind, seal_based, hint }`, filterable via `wwu_wb_exception_types`.
  Two conditional reasons (service performed 59_a, digital immediate 59_o); seal-based
  ones (59_e/59_i) that can't be known at order time; the rest unconditional.
- **`ExemptionResolver`** — reads the new `wwu_wb_exclusions.by_reason` map (per reason:
  product + category IDs), with the legacy flat lists + `wwu_wb_excluded_product_ids`
  filter folded into a generic `manual` reason at read time (back-compat, no migration
  needed).
- **`ArticleFiftyNineEvaluator` upgraded** — an item is exempt only when its reason
  applies: unconditional → exempt; **conditional → only when consent was captured**
  (via the new `wwu_wb_exemption_consent` filter, which the P2 checkout layer will hook —
  until then the button stays, fail-safe); seal-based → never auto-hidden.
- **Admin UI** — Settings → "Exemptions (Art. 59)": a product/category-ID picker per
  reason, each with its legal ref + plain-language hint + a "needs consent" / "not
  auto-hidden" tag (Standard #12). Saves the `by_reason` map and migrates away the old
  flat lists.
- **Smoke suite `exemptions`** — registry flags, back-compat filter, mixed-cart, and the
  conditional/seal/unconditional gates (incl. consent via the filter).
- P2 (checkout consent capture + the durable-medium confirmation) and P3 (consumer
  transparency copy) are the next phases.

### Status surfaces: refund detection, localized labels, prominent FluentCart button (1.0.0-alpha.26, 2026-06-14)
More live-test polish on the per-order / chooser status surfaces:
- **Refund now reflected on FluentCart too.** Added `OrderDataSource::is_refunded()`
  (WooCommerce: `get_total_refunded() > 0`; FluentCart: `payment_status` refunded/
  partially-refunded). `request_status_label()` and both chooser branches now show
  **"Refunded"** once the money is back — previously a refunded FluentCart order kept
  showing "Withdrawal handled". Refund > handled > requested precedence, one shared
  helper for every surface.
- **"Withdrawal handled" / "Withdrawal requested" were showing in English.** The
  context-less `__()` strings used by the chooser/notice weren't in the .po (only a
  `msgctxt "Order status"` variant existed, which is a different gettext key). Added the
  bare entries + translations to all four .po + the .pot and recompiled the .mo.
- **FluentCart per-order button is now an actual button.** The FluentCart portal is a Vue
  SPA where the plugin stylesheet may not load, so the injected withdrawal link rendered
  as plain underlined text. The button template now accepts an `inline` flag that adds
  self-contained inline styles; `FluentCartPortal::button_html()` passes it.

### Live-test fixes: re-request guard, dashboard order link, guidance i18n (1.0.0-alpha.25, 2026-06-14)
Three issues found in live FluentCart testing:
- **Button no longer offered on an order already withdrawn/processed.** The per-order
  FluentCart surface (`FluentCartPortal::inject`) showed the button again even after a
  request existed. It now checks the request status (like WooCommerce's order-detail
  already did) and shows a **localized** status notice instead. Extracted
  `EligibleOrders::request_status_label()` as the single source so both surfaces show a
  clean translated label ("Withdrawal requested" / "Withdrawal handled"), never the raw
  internal status — also fixing WooMyAccount's old raw-status notice.
- **Requests Dashboard: "Open order (refund)" link for both platforms.** The refund link
  was WooCommerce-only. `order_admin_url($platform, $order_ref)` now returns the WC order
  edit screen for WooCommerce and a best-effort FluentCart admin deep-link (filterable via
  `wwu_wb_order_admin_url`, since FluentCart's exact admin order route isn't documented).
- **Mixed IT/EN in the consumer-guidance box → fixed.** When the withdrawal window became
  configurable (alpha.20), the intro + first bullet gained a `%d` placeholder, but the
  it/de/fr/es .po still had the old "14"-hardcoded msgids, so those two strings fell back
  to English. Updated the two msgids/msgstrs to `%d` in all four .po + the .pot and
  recompiled the .mo (refund "within 14 days" strings deliberately left literal).

### Digital auto-exclusion now defaults OFF — the right is the default (1.0.0-alpha.24, 2026-06-14)
The admin diagnostic confirmed why a FluentCart digital test order was hidden:
`status="completed" … reason=no_withdrawal_right`. Not a bug — the Art. 59 *digital
auto-detect* (`wwu_wb_exclusions.auto_detect_virtual`) was seeded **ON**, so any
completed digital order was auto-excluded. But that flag was legally over-broad: the
digital-content exemption (Art. 59 lett. o / Art. 16(m)) only applies when prior express
consent + an acknowledgment of losing the right were captured — which the auto-detect
does **not** verify — so it risked hiding the button from consumers who still have the
right (under-compliance), and it contradicted the page's own admin copy ("do not simply
hide the button without the legal conditions").
- **Default flipped to OFF** (`Install` seed). The withdrawal right is the default;
  merchants opt in, exclude specific products/categories, or use the proper (consent-
  capturing) exemptions feature.
- **Migration 2** flips existing installs from `true` → `false`. There is no UI for this
  flag, so any stored `true` is the old seed (never a deliberate choice) — no intent is
  overwritten. Schema version bumped 1 → 2.
- **Smoke tests updated**: replaced the now-wrong `art59_digital_excluded` assertion with
  `digital_matches_auto_detect` (shown iff auto-detect off — robust to the setting) and a
  deterministic `excluded_product_hidden` via the `wwu_wb_excluded_product_ids` filter.

### FluentCart get_order() returned null — Eloquent magic-method guard removed (1.0.0-alpha.23, 2026-06-14)
The admin diagnostic showed the final layer: with the collection now iterated
(alpha.21), `get_order(1)` still returned null. Root cause in `FluentCartAdapter::load()`:
the lookup was guarded by `method_exists($model, 'find')`, but Eloquent's `find()` is a
**magic static** (`__callStatic` → query builder), so `method_exists()` returns false and
the guard skipped the lookup entirely — returning null for every order. Removed the guard
(kept `class_exists` + try/catch). Same family as the alpha.21 `(array)`-on-a-collection
bug: wrong assumptions about Eloquent magic methods. FluentCart orders now load and the
chooser/button render.

### Smoke-test runner fixed + FluentCart regression tests (1.0.0-alpha.22, 2026-06-14)
- **Inspector smoke-test runner was dead** (`TypeError: (suite.tests || []).forEach is
  not a function` on "Run ALL"). Root cause: `suite_rfc3161()` returned the already-
  wrapped `{name, tests}` shape while every other suite returns a flat array, so `run()`
  double-wrapped it and `tests` serialized to a JSON **object** (no `.forEach`). Fixed the
  suite to return the flat array; hardened `inspector.js` to coerce any object-shaped
  `tests`/`suites` to an array (`toArray()`), so a single malformed suite can never again
  break the whole report.
- **No more drift between suites and UI buttons.** The Inspector button row was a
  hardcoded list missing `rfc3161`; it now derives from `SmokeTests::suite_names()`.
- **New `fluentcart` smoke suite** covering the alpha.20/.21 bugs as regressions, without
  needing FluentCart active: the Eloquent-collection unwrap (`->all()` vs `(array)`
  internals), array/scalar edge cases, and the `payment_status` → eligible-status mapping.
  Extracted `FluentCartAdapter::unwrap_collection()` + `::eligible_status()` as pure static
  helpers (now the single source used by the adapter, the chooser and the diagnostic).
- **Applicability suite extended** with three regressions: empty/unreadable items default
  to withdrawable, a `paid` status is an eligible concluded contract, and an empty country
  is out-of-scope (hidden) in the default EU-only mode.

### FluentCart orders now appear — Eloquent collection iteration fix (1.0.0-alpha.21, 2026-06-14)
The admin diagnostic (alpha.20) pinpointed the real reason FluentCart orders never
reached the chooser: `collect_fluentcart()` iterated the orders with `foreach
( (array) $orders )`. Casting an Eloquent **collection** with `(array)` iterates the
collection object's internal properties, not its models — so every order ref came out
empty and `get_order('')` returned null. Fixed by unwrapping with `->all()` before the
loop (same fix in the diagnostic). This sits in front of the alpha.20 applicability
gates: with the orders now actually iterated, the country/status/items fixes take
effect and eligible FluentCart orders show with the per-order button.

### FluentCart applicability gates fixed — orders now show + button appears (1.0.0-alpha.20, 2026-06-14)
After alpha.19 the FluentCart portal page rendered, but FluentCart orders still did
not appear in the chooser and the per-order button was missing. Root cause: three
applicability gates were silently filtering FluentCart orders out, each fixed against
the official FluentCart model schema:
- **Empty line items no longer hide the order.** `ArticleFiftyNineEvaluator` returned
  *no withdrawal right* when an order had zero readable items — but the right of
  withdrawal is the DEFAULT and Art. 59 exceptions are the exception. It now defaults
  to "withdrawable" when items can't be read, so a platform whose item relation didn't
  load never silently hides the function. Also: the adapter now reads items via the
  official `order_items` relation and `OrderItem.post_id` / `fulfillment_type`.
- **Status gate now understands FluentCart.** Eligibility presupposes a concluded
  (paid) contract, which FluentCart signals via **`payment_status` = paid** — the
  green "Paid" badge — not necessarily the fulfillment `status` (which may be
  `pending`). The adapter surfaces `paid` so the normalized status reads as eligible.
- **Country resolved from the right relation.** Billing country (ISO-2) is read from
  the `billing_address` relation, then the `order_addresses` collection (type=billing),
  then a flat fallback — previously an unresolved country made every order read as
  out-of-scope (hidden) in the default EU-only mode.
- **Customer match by email fallback.** When a FluentCart customer isn't linked to a
  WP `user_id` (guest/manual checkout), the chooser now also matches by the user's email.
- **New admin diagnostic** (`?wwu_wb_diag=1`, `manage_options` only, read-only): on the
  standalone withdrawal page it prints, per FluentCart order, the status / country /
  item count and the exact applicability decision + reason — so any residual gate is
  visible in one look instead of guesswork.

### FluentCart integration corrected to official-doc-verified contract (1.0.0-alpha.19, 2026-06-14)
Live testing showed the alpha.18 FluentCart surfaces did not work (the "Diritto di
recesso" menu entry appeared but opened a blank page; no per-order button; no
banner). Each hook/API was re-verified against the **official** docs
(dev.fluentcart.com, traced to FluentCart source files) and corrected:
- **Blank portal page → fixed.** `fluent_cart/customer_portal/custom_endpoints`:
  the endpoint slug must be the array KEY (`$endpoints['wwu-withdrawal'] = [...]`)
  and the only documented key is `render_callback`, which must **`echo`** its HTML
  (FluentCart ignores the return value). Our callback returned a string → blank.
  Now echoes; the wrong `key`/`slug`/`label`/`title`/`callback` keys were removed.
- **Menu item → correct shape.** `fluent_cart/global_customer_menu_items`: items
  are keyed by slug with exactly `label`, `css_class` (**`fct_route`** — required so
  the SPA routes client-side), `link` (not `url`), `icon_svg` (raw SVG, not `icon`).
  Removed the unsupported `key`/`title`/`route`/`priority`/`url`/`icon` keys.
- **Dashboard banner → 2 args.** `fluent_cart/customer_dashboard_data` passes
  `($data, $context)`; the registration was `,10,1` (dropping `$context`). Now
  `,10,2`. The `sections_parts.before_orders_table` slot was already correct.
- **Per-order button data → fixed (the real reason it never showed).** The
  `order_details_section_parts` hook usage was already correct, but the button is
  gated by applicability, which needs the order's **country** — and the FluentCart
  adapter read flat columns that don't exist. Per the official Order model schema,
  email is on `$order->customer->email`, billing country on
  `$order->billing_address->country` (OrderAddress), and the **WordPress user id on
  `$order->customer->user_id`** (the order's `customer_id` is the FluentCart customer
  PK, never a WP user). The adapter now reads through these relations (with flat
  fallbacks); `verify_owner()` compares the customer's `user_id`. This also fixes an
  ownership-check bug that compared the FluentCart customer PK to the WP user id.
- **Email merge tag removed (honestly).** `fluent_cart/email_notification_merge_tags`
  does **not exist** in the official docs; the real `fluent_cart/editor_shortcodes`
  only populates the editor picker with no documented value-resolver, so a tag would
  render literally in sent mail. Pulled rather than ship a guessed API; deferred
  pending an official resolver hook.
- FluentCart order chooser rows now link to the standalone public form page (which
  always loads our CSS/JS), so the link works on FluentCart-only stores too.
- Verification recorded in `docs/analysis/wwu-wb-fluentcart-hooks-ANALYSIS.md`.

### FluentCart customer-portal integration (1.0.0-alpha.18, 2026-06-14)
- **Fix: FluentCart customers now see the withdrawal flow.** Previously the
  FluentCart side wired only a single, unverified order-details filter and the
  account chooser was WooCommerce-only, so a FluentCart store showed nothing in
  the customer account. Re-built on FluentCart's documented server-side hooks
  (dev.fluentcart.com), all defensive about array shape so a future key rename
  degrades to "surface absent" instead of fataling:
  - `fluent_cart/customer_portal/custom_endpoints` — a dedicated **"Right of
    withdrawal"** portal page rendering the order chooser + two-step form.
  - `fluent_cart/global_customer_menu_items` — a sidebar entry linking to it.
  - `fluent_cart/customer_dashboard_data` — a reassuring banner above the orders
    table (`before_orders_table` slot).
  - `fluent_cart/customer/order_details_section_parts` — the per-order button,
    moved to the recommended **`after_summary`** slot (was `end_of_order`) and
    reading the order model from `$context['order']`.
  - `fluent_cart/email_notification_merge_tags` — a `{{wwu.recesso_url}}` merge
    tag for FluentCart's transactional emails.
- **`EligibleOrders` is now platform-agnostic**: it merges the customer's
  WooCommerce *and* FluentCart orders (querying FluentCart's `Customer`/`Order`
  models by `user_id`), each source guarded so an inactive platform contributes
  nothing. Running WooCommerce + FluentCart together is fully supported — the two
  surfaces register independently; reactivation is not required.
- **`Assets::ensure()`** (new public method) force-loads the frontend CSS/JS on
  the FluentCart portal — a server-rendered Vue SPA the standard context gate
  can't detect — still respecting the master "enabled" switch and de-duplicating
  by handle. The portal page is also detected heuristically via its shortcode/
  block marker for the normal `wp_enqueue_scripts` path.
- Withdrawal status/`processed_at` are persisted per-order by the
  platform-agnostic `WithdrawalService` through the adapter, so the localized
  status pill ("Withdrawal requested" / "handled") works identically on
  FluentCart and WooCommerce.

### Consumer guidance + timestamp provider reference (1.0.0-alpha.17, 2026-06-14)
- **Consumer "how withdrawal works" guidance** (`partials/consumer-guidance.php`)
  shown wherever a consumer can start a withdrawal — the two-step form and the My
  Account / public chooser: a short reassuring intro + a collapsible plain-language
  explanation (14-day period, no reason needed, the two-step confirm, immediate
  email acknowledgement, refund within 14 days same method, returning goods, Art.
  59 exceptions, contact us). Improves UX and transparency/compliance. i18n.
- **Timestamp providers reference in Settings**: a collapsible list of the main
  providers with one-line blurbs and links to their official sites (OpenTimestamps,
  Sectigo free/qualified, DigiCert, and per-country QTSPs Aruba/InfoCert, D-Trust,
  Universign, FNMT, SwissSign) so the merchant can pick and paste the right
  endpoint.
- Audit follow-up: the `wwu_wb_timestamp` default seed now uses the nested
  `rfc3161 => { endpoint, user, pass }` shape the code reads (was a dead
  `rfc3161_url` key). RFC 3161 audit verdict was ship (2 low security notes
  accepted: admin-config endpoint as trust boundary; nonce fallback entropy).

### RFC 3161 / eIDAS timestamp provider (1.0.0-alpha.16, 2026-06-14)
- **New `Rfc3161Provider`** for the pluggable timestamp layer: sends the log
  row's SHA-256 to an RFC 3161 Time-Stamp Authority and stores the signed token.
  Unlike OpenTimestamps (Bitcoin, asynchronous), an RFC 3161 token is final
  immediately, so the stamp is confirmed at creation (the upgrade cron skips it).
  The ASN.1 request/response is hand-rolled (no `openssl ts` exec dependency, no
  extra library). Verified end-to-end against Sectigo's free eIDAS-qualified TSA
  (PKIStatus granted).
- **Settings**: "Trusted timestamp" now offers OpenTimestamps / RFC 3161 / None,
  with an endpoint field (prefilled with the free, no-account eIDAS-qualified
  `timestamp.sectigo.com/qualified`) and optional Basic-auth credentials for paid
  national QTSPs (Aruba, InfoCert, D-Trust, Universign, FNMT, SwissSign…). The
  password uses the "leave blank to keep" pattern and is never re-emitted.
- `TimestampService` routes `provider=rfc3161` to the new provider and confirms
  synchronous proofs immediately. Smoke suite `rfc3161` (request DER shape, SHA-256
  OID, digest embedding, PKIStatus parsing, empty-endpoint/bad-hex guards).
- Background: any RFC 3161 TSA works by config; `wwu_wb_timestamp_provider` filter
  still lets integrators inject custom providers. See
  `docs/analysis/wwu-wb-timestamp-providers-ANALYSIS.md`.

### Refund evidence + procedure guide (1.0.0-alpha.15, 2026-06-14)
- **Reimbursement is now recorded in the evidence log.** `WooRefundRecorder`
  hooks `woocommerce_order_refunded` and appends a `refund_issued` event (amount,
  currency, refund id, timestamp, acting user) for orders that have a withdrawal
  request — proof that the trader met the 14-day reimbursement duty. The Requests
  page Status column shows "Refunded <amount>" (read live from WooCommerce).
- **"What to do after receiving a request" guide** on the Requests page: a
  collapsible, plain-language step-by-step (scope check → returns/withholding →
  reimburse within 14 days, same payment method → mark processed), accurate to
  Art. 56–57 with an Art. 59 caveat. i18n.
- Process-workflow review fixes: honest `mark_failed` feedback when the order
  can't be loaded; a 20s debounce on "Resend email" to avoid double-sending to
  the consumer.

### Merchant process workflow + list CSS (1.0.0-alpha.14, 2026-06-14)
- **Process workflow on the admin Requests page.** A withdrawal is the consumer's
  unilateral right (no "approve" step) — so the actions are operational: a
  **Status** column (Open / Processed), **Mark processed** (records the state on
  the order + logs `request_processed` to the immutable log), **Resend email**
  (re-dispatches the acknowledgement — fulfils the failure notice's "resend from
  the Requests page" promise; reconstructs the statement from the confirmed log
  row), and **Refund order** (deep-links to the WooCommerce order screen where the
  refund is issued within the 14-day deadline). A plain-language banner explains
  the legal nature (reimburse within 14 days, same payment method).
  `ConfirmationDispatcher::dispatch()` now returns the send result and exposes a
  `resend( uid )` method.
- **Front-end list CSS.** Removed the duplicate "Right of withdrawal" heading
  (the My Account endpoint / page already render it) and gave the orders chooser
  a self-contained `.wwu-wb-orders` style (replacing WooCommerce's
  `shop_table_responsive`, which clashed with the theme and overlapped the
  button/status): padded cells, row borders, a compact inline status pill, a
  small button variant, and a theme-independent mobile stack.

### Frontend usability: order list, verify page, email preview, block (1.0.0-alpha.13, 2026-06-14)
- **Eligible-orders list**: the My Account "Right of withdrawal" tab rendered an
  empty placeholder, and the public `[wwu_wb_form]` page with no order in context
  only said "Order not found" — so a customer never saw an order to act on. A
  shared `EligibleOrders` builder now lists the customer's recent WooCommerce
  orders (HPOS-safe) that are eligible now or already requested; both surfaces use
  it. Guests are guided to the order-email link.
- **Readable verification page**: `/verify/{uid}` now content-negotiates — a
  browser gets a self-contained "certificate" page (intact/altered, order,
  datetime, within-window, evidence hash), API clients and `?format=json` still
  get JSON.
- **Email preview in Settings**: a "Preview the acknowledgement email" link opens
  the email built from sample data — on WooCommerce through the WC style inliner,
  so it shows the store's email branding exactly as sent.
- **Gutenberg block** `wwu-wb/withdrawal-form` ("Withdrawal — self-service"): a
  dynamic, server-rendered block that wraps the form shortcode (same applicability
  + ownership gates), shipped with no build step (vanilla editor JS +
  ServerSideRender preview). WooCommerce Blocks note: the My Account page is still
  shortcode-rendered in current WooCommerce, so the existing classic hooks remain
  the supported integration and keep working under block themes; the block adds an
  editor placement option.

### WooCommerce email integration (1.0.0-alpha.12, 2026-06-14)
- The consumer **acknowledgement of receipt is now a first-class `WC_Email`**
  (`WooAckEmail`, id `wwu_wb_withdrawal_ack`). It appears under WooCommerce →
  Settings → Emails, inherits the store's email branding (logo, base colour,
  header/footer), is customisable (subject, heading, additional content, email
  type) and template-overridable in the theme at
  `woocommerce/emails/wwu-wb-withdrawal-ack.php` (+ `plain/`).
- **Delivery routing** in `ConfirmationDispatcher`: on WooCommerce it sends the
  branded WC email when the merchant has it enabled; otherwise (FluentCart, WC
  absent, or the email disabled) it falls back to the plain standalone mailer.
  Because the acknowledgement is legally mandatory, the WooCommerce enable/disable
  toggle controls only the *styling/integration* — it never stops the email from
  being sent. The `receipt_failed` log + admin notice still fire if neither path
  delivers.
- Locale stays the consumer's (the dispatcher's `switch_to_locale()` owns it; the
  WC email deliberately does not call `setup_locale()`). The PDF receipt is passed
  through as the email attachment when available.
- Reviewed by parallel security + correctness sub-agents.

### Email diagnostics + onboarding rework (1.0.0-alpha.11, 2026-06-14)
- **Decoupled the PDF from the send**: `ConfirmationDispatcher` now guards the
  optional PDF render/store with `PdfBuilder::is_available()`. A missing Dompdf
  can never affect the acknowledgement email (which is the legal durable medium);
  the PDF is only an extra copy.
- **Email-delivery diagnostics on the dashboard** (the real answer to "why didn't
  I get an email?"): a checklist row detects a known SMTP plugin (FluentSMTP, WP
  Mail SMTP, Post SMTP, Easy WP SMTP, Mailster) or a `phpmailer_init` integration
  and warns when none is present, plus a **"Send test email"** button. The handler
  is capability-gated + nonce-checked + throttled and always sends only to the
  current admin's own address (never request input), so it can't be abused as a
  relay. Reviewed by parallel security + correctness sub-agents (0 findings).
- **Onboarding rework**: the dashboard no longer leads with page-creation unless a
  form page is actually missing; when everything is in place it explains **how the
  plugin works** (the 4-step consumer flow) and where the button appears.
- **Fix (visible bug)**: `Template::render()` had a `$name` parameter colliding
  with template variables of the same key (`extract(EXTR_SKIP)` skipped them), so
  the form's "name" field (and the email) showed the template path instead of the
  consumer's name. Rendering now happens in an isolated scope with reserved
  variable names — fixes the form and all email/PDF templates.
- **Onboarding Dashboard** (`DashboardPage`): replaces the placeholder. A setup
  checklist (enabled / platform / form page / PDF) with one-click fixes, a plain
  explanation of **where the button appears**, and the reasons it might be hidden
  on an order (out-of-scope country, non-contract status, Art.59/B2B, disabled) —
  written for non-technical merchants.
- **Settings completed**: a "Where the button applies" section (applicability
  mode always / EU-EEA / custom + custom country list + B2B toggle) so merchants
  can choose to show it everywhere; a "Receipt & evidence" section (attach-PDF,
  notification email, retention years, OpenTimestamps/none provider, account-tab
  slug with rewrite flush). The PDF row warns when the library is missing and how
  to fix it without the command line.

### Custom CSS + styling reference (1.0.0-alpha.9, 2026-06-13)
- Frontend CSS refactored to expose **CSS custom properties** (`--wwu-wb-*`) on
  every element (accent, radius, button/field/notice colors, spacing) for
  one-line theming, while keeping all `.wwu-wb-*` classes for full control.
- **Settings → Appearance — Custom CSS**: a textarea where the merchant pastes
  CSS (loaded inline after the plugin styles, so it overrides), plus a collapsible
  **reference listing every CSS variable (with default + purpose) and every class
  (with what it targets)** and ready examples. Sanitised via `Sanitizer::css()`
  (strips tags/`</style>` break-out, neutralises `expression()`/`javascript:`/
  `behavior:`/`@import`; 50 KB cap). Applied on the frontend surfaces and the
  no-JS fallback pages. Compliance note in the UI: must not hide/shrink/low-contrast
  the statutory button.

### Audit hardening — core F0–F6 (1.0.0-alpha.7, 2026-06-13)
Parallel security + performance + compliance audit (sub-agents). Security: **0
findings**. Closed all critical/high compliance gaps + all high/medium perf
findings. See `docs/audits/wwu-wb-core-2026-06-13-AUDIT.md`.
- **Compliance (critical)**: order-status eligibility gate centralised in
  `ApplicabilityResolver` (no button on failed/cancelled/refunded/unpaid orders);
  guest path fixed — `Install::ensure_form_page()` auto-creates the public
  `[wwu_wb_form]` page, `OrderEmailLink` adds the statutory link (with order key)
  to WooCommerce customer emails, admin warning when no form page is set.
- **Compliance (high)**: `NoScriptFlow` server-rendered no-JavaScript fallback
  (admin-post two-step); JS is now progressive enhancement.
- **Compliance (medium/low)**: mail-failure now logged (`receipt_failed`) + admin
  notice (`wwu_wb_mail_failed`); late flag shown in the admin email.
- **Performance**: DB-1 chain-corruption guard (abort append if `GET_LOCK` fails);
  public `/verify` does O(1) per-row integrity (full-chain scan cached, admin
  only); `Core\Settings` per-request option cache across hot resolvers;
  `batch_meta()` (1 save instead of 7 in confirm); `script_loader_tag` filter
  scoped to enqueue; `labels/exclusions/timestamp` options set autoload=no.
- Lint clean (php -l 67 files, node --check).

### F4 + compat (1.0.0-alpha.5/.6, 2026-06-13)
OpenTimestamps anchoring + Complianz/cache compatibility + FluentCart adapter
(see commits).

### F5/F7/F8 — Legal documents, shortcodes, admin dashboard (1.0.0-alpha.4, 2026-06-13)
- **F5 legal documents**: `ModelForm` (Annex I-B model withdrawal form, official
  wording IT/EN/DE/FR/ES) + `ClauseLibrary` (pre-contractual / terms / privacy
  clauses, IT+EN full, others EN-fallback with review note).
- **F7 shortcodes**: `[wwu_wb_button]`, `[wwu_wb_form]`, `[wwu_wb_status]`,
  `[wwu_wb_model_form]`, `[wwu_wb_info]` — order-scoped ones gated by ownership/
  key/token, render nothing on failure.
- **F8 admin**: Requests dashboard (confirmed requests from the immutable log,
  late flag, evidence link, chain-integrity badge) + Compliance page (go-live
  countdown, document checklist + copyable clauses + model-form shortcode,
  Complianz/TranslatePress/cache environment notes). New menu: Dashboard /
  Requests / Compliance / Settings / Debug Inspector.
- Lint clean.

### F3 — Durable medium (1.0.0-alpha.3, 2026-06-13)
- `ConfirmationDispatcher` listens on `wwu_wb_withdrawal_confirmed` and sends the
  acknowledgement of receipt synchronously (Art. 11a(4) "without undue delay"),
  reproducing the statement content + exact submission date/time.
- `PdfBuilder` (Dompdf, LGPL-2.1; `isRemoteEnabled=false`, DejaVu Sans, A4) with
  graceful degradation to email-only when the vendor dir is absent. Dompdf v3.1.5
  added via Composer (`composer.lock` committed; `vendor/` gitignored).
- `ReceiptStore` (protected uploads dir, .htaccess deny + uid-named files),
  `VerifiableLink` (stable HMAC token), `ReceiptBuilder` (trader/Annex-I-B-style
  data), `Mailer` (HTML + attachment). Locale-switched to the consumer's language.
- REST `/receipt/{uid}` (token-gated PDF stream) + `/verify/{uid}` (hash +
  submission time + chain-integrity status), rate-limited, enumeration-safe.
- Email/PDF templates (consumer, admin, PDF). Smoke suite `durable_medium`.
- Lint clean.

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
