# Changelog — WWU Withdrawal Button

All notable changes to this project are documented here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/); the project uses Semantic Versioning.

## [Unreleased]

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
