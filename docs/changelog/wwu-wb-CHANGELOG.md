# Changelog — WWU Withdrawal Button

All notable changes to this project are documented here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/); the project uses Semantic Versioning.

## [Unreleased]

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
