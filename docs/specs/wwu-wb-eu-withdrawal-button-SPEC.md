# WWU Withdrawal Button — SPEC

**Feature:** EU online right-of-withdrawal function ("withdrawal button") for WooCommerce & FluentCart
**Legal basis:** Directive (EU) 2023/2673 → Art. 11a Directive 2011/83/EU; Italy: Art. 54-bis Codice del Consumo (D.Lgs. 209/2025)
**Slug:** `wwu-wb` · **Folder:** `wwu-withdrawal-button`
**Target version:** `1.0.0`
**Status:** DRAFT (spec) — implementation pending
**Authors / credits:** mredodos · Matteo Alfieri (An Idea for Business) · WebWakeUp ([webwakeup.it](https://webwakeup.it))
**License:** GPL-3.0-or-later
**Last updated:** 2026-06-13

> ⚠️ **Hard deadline:** the obligation applies to contracts concluded **on or after 19 June 2026**. This SPEC is written on 2026-06-13.
> ⚠️ **Not legal advice.** The plugin is a technical aid to compliance. The verbatim statutory text and per-country analysis live in [`docs/legal/wwu-wb-legal-reference.md`](../legal/wwu-wb-legal-reference.md) and the requirement→feature mapping in [`docs/legal/wwu-wb-compliance-matrix.md`](../legal/wwu-wb-compliance-matrix.md). Merchants must have their own counsel review their documents.

---

## 1. Overview

A free, open-source WordPress plugin that makes an online store **legally compliant** with the new EU "withdrawal function" obligation (the mandatory *withdrawal button*) introduced by Directive (EU) 2023/2673 (new Art. 11a of the Consumer Rights Directive 2011/83/EU) and transposed in Italy as **Art. 54-bis del Codice del Consumo** (D.Lgs. 209/2025), applicable from **19 June 2026**.

The plugin implements the **complete legal mechanism**, not just a button:

1. A **prominently displayed, continuously available, legible withdrawal button** in the customer's order area, labelled with the exact statutory wording per language/jurisdiction.
2. A **two-step flow**: (Step 1) an online withdrawal statement form collecting/confirming name + contract identification + the electronic means for the acknowledgement; (Step 2) a separate **confirmation button** labelled *only* with the statutory confirmation wording.
3. An **automatic acknowledgement of receipt on a durable medium** (email + attached PDF + permanent verifiable link), reproducing the statement content and the exact **date and time of submission**, sent *without undue delay*.
4. A **tamper-evident immutable log** (append-only, hash-chained, with IP + contract data + timestamps) anchored to **OpenTimestamps** (free, Bitcoin-backed) and ready for a pluggable **eIDAS-qualified RFC 3161** timestamp authority.
5. **No dark patterns**: the withdrawal must be at least as easy as the purchase (legibility/contrast guard, no forced phone call, no buried button, no pre-confirmation upsell windows, no mandatory reason field).
6. **Compliance document helpers**: generates the **Annex I-B model withdrawal form** (multilingual) — which remains mandatory and *coexists* with the button — plus ready-to-paste clauses for Privacy Policy, General Terms, and pre-contractual information.

It integrates with **WooCommerce** (HPOS + legacy) and **FluentCart** via a platform-adapter layer, is fully **multilingual** (IT/EN/FR/ES/DE Day 1, extensible), and is built to be **compatible with Complianz and TranslatePress**, with **shortcodes + blocks** for placement/customisation.

### Who must comply (operationalised)
Any trader concluding **distance B2C contracts via an online interface** with a right of withdrawal, **for consumers resident in the EU/EEA** — regardless of the trader's own country (Rome I Art. 6: a Swiss/UK/US seller targeting EU consumers must comply *for those EU consumers*). Applicability is decided **per order, by the consumer's country**, never by the store's country. See [§4.6 Applicability](#46-applicability-engine).

### Penalties (why this matters)
In Italy, AGCM may act *ex officio* with fines from **€5,000 to €10,000,000** (Art. 27 c.9 Cod. Consumo) or up to **4% of turnover** (c.9-bis); clauses obstructing withdrawal are **void**; exposure can be retroactive on procedures already in use. France: missing any cumulative requirement extends the withdrawal window to **12 months + 14 days**.

---

## 2. Goals & Non-Goals

### 2.1 Goals
- **G1 — Full Art. 11a compliance** out of the box: button + two-step + durable-medium acknowledgement + correct statutory labels per language.
- **G2 — Tamper-evident legal evidence**: immutable hash-chained log with IP + contract data + precise timestamps; OpenTimestamps anchoring; signed PDF + verifiable link as durable medium.
- **G3 — Dual platform Day 1**: WooCommerce (HPOS + legacy) and FluentCart, behind a common adapter.
- **G4 — Multilingual & jurisdiction-aware**: IT/EN/FR/ES/DE Day 1 with per-country statutory label tables (DE `§356a`, FR `D.221-5`, ES directive direct-effect), extensible to other locales.
- **G5 — Guest coverage**: signed-link entry in the order email + public lookup form (order # + email) + receipt always sent to the order email + anti-abuse rate limiting.
- **G6 — Ecosystem-friendly**: compatible with Complianz (functional-script whitelist) and TranslatePress (legal labels protected from machine translation); excluded from page cache where needed; shortcodes + blocks + documented hooks/filters + template overrides.
- **G7 — Compliance documents**: Annex I-B model form (multilingual PDF + shortcode) + ready clauses for Privacy/Terms/pre-contractual info (IT complete + EU-generic + DE/FR/ES specifics).
- **G8 — WWU engineering standards**: PSR-4, debug stack (Standard #11), UI Kit (Day 1), `wwu-tools` REST test contract, custom-table + migrator + versioning, multisite-aware activation/uninstall, English code/docs.
- **G9 — Contribution-ready open source**: GitHub repo with README, CONTRIBUTING, CODE_OF_CONDUCT, issue/PR templates, LICENSE (GPLv3), changelog; subtle non-intrusive WebWakeUp credit.

### 2.2 Non-Goals (MVP)
- **NG1** — The plugin does **not** create or decide the substantive right of withdrawal or its Art. 59 exceptions; it *respects* existing product flags and offers auto-detect + override. It modernises the **procedure**, not the exemptions.
- **NG2** — **No automatic refunds.** A refund (separate 14-day obligation) is only *prepared as a draft* for manual merchant approval; the plugin never triggers a live gateway refund. (Anti-dark-pattern: the plugin must never *block* withdrawal pending refund logic.)
- **NG3** — **No full client-side `.ots` binary verification** in MVP (store proof blob + deep-link to opentimestamps.org/verify; full PHP verifier is a later phase).
- **NG4** — **No eIDAS-qualified timestamp** in MVP (provider interface is built; Aruba/Namirial require a paid commercial contract — pluggable, post-MVP).
- **NG5** — No partial/line-item withdrawal UI in MVP (whole-order withdrawal is compliant; per-item is a documented future option — recital 37 *permits* but does not *require* it).
- **NG6** — No CRM/marketing automation (FluentCRM tagging is an optional documented hook, not shipped).
- **NG7** — Switzerland-resident consumers are **out of scope as a mandate** (voluntary mode only); the plugin does not invent a Swiss obligation.

---

## 3. User Stories

### Consumer (EU/EEA, logged-in)
- As a logged-in customer, I see a clearly labelled **"recedere dal contratto qui"** button on my order, within the withdrawal period, in the same area where I bought.
- Clicking it opens a short form with my name, order, and email **pre-filled** (I don't re-identify myself — recital 37). I click **"conferma recesso"**.
- I immediately receive an **email with a PDF** reproducing my declaration and the **exact date/time**, plus a permanent link to re-download it.

### Consumer (guest, no account)
- I receive a **withdrawal link in my order email**; clicking it opens the pre-verified form.
- Or I visit a public **"exercise your right of withdrawal"** page, enter my **order number + email**, and proceed. The receipt is sent to the order's email on file (so the real owner is always notified), and abusive attempts are rate-limited.

### Merchant / shop admin
- I enable the plugin, pick my **applicability mode** (EU/EEA only / always / custom), and the correct **statutory labels appear automatically per language**.
- I see incoming withdrawal requests in a **dashboard** (status, timestamp, evidence), with one-click **export** of the signed PDF + log excerpt for a dispute, and the order is marked **"Recesso richiesto"**.
- A **compliance checklist** reminds me to publish the generated Annex I-B model form and update my Privacy/Terms/pre-contractual info; it warns me about Complianz/cache/TranslatePress configuration.

### Developer / integrator
- I place the button anywhere with `[wwu_wb_button order_id="…"]` or a block; I override labels, the email/PDF, the exclusion logic, and the applicability decision via documented `wwu_wb_*` filters; I override templates from my theme.

### AI agent / support engineer
- I `curl` the `/wwu-wb/v1/debug/run-tests` and `/debug/snapshot` endpoints (Standard #11) to introspect runtime state and verify compliance without a browser.

---

## 4. Architecture

### 4.0 Naming & conventions (canonical)
| Aspect | Value |
|---|---|
| Display name | **WWU Withdrawal Button** |
| Extended title | *EU Right of Withdrawal (Art. 11a / Art. 54-bis) for WooCommerce & FluentCart* |
| Folder / slug | `wwu-withdrawal-button` |
| Text domain | `wwu-withdrawal-button` |
| PHP namespace | `WWU\WithdrawalButton\*` (PSR-4, custom autoloader, no Composer runtime) |
| Constant prefix | `WWU_WB_*` |
| Options prefix | `wwu_wb_*` |
| Order meta prefix | `_wwu_wb_*` |
| REST namespace | `wwu-wb/v1` |
| Hooks prefix | `wwu_wb_*` |
| CSS prefix | `.wwu-wb-*` (UI Kit core stays `.wwu-ui-*`) |
| JS localize object | `window.wwuWbData` |
| Nonce action (admin/AJAX) | `wwu_wb_nonce`; REST uses standard `wp_rest` |
| Capability baseline | `manage_woocommerce` (admin), filterable `wwu_wb_admin_capability` |
| DB version option | `wwu_wb_db_version` (autoload yes — hot-path), schema constant `WWU_WB_SCHEMA_VERSION` |

### 4.1 High-level layers
```
wwu-withdrawal-button.php  (bootstrap: constants, env guards, autoloader, Plugin::boot)
│
├─ Core/            Plugin singleton, Install/Uninstall, Migrator, Cron
├─ Platform/        OrderDataSource interface + WooCommerceAdapter + FluentCartAdapter + PlatformRegistry
├─ Domain/          WithdrawalRequest (VO), WithdrawalService, ApplicabilityResolver,
│                   WindowCalculator, LabelResolver, ArticleFiftyNineEvaluator
├─ Storage/         Database/LogTable + Database/TimestampTable, LogRepository (append-only),
│                   LogChain (hash-chain build/verify), TimestampRepository
├─ Timestamp/       TimestampProvider interface + OpenTimestampsProvider + Rfc3161Provider + NoneProvider + UpgradeCron
├─ DurableMedium/   ReceiptBuilder (HTML), PdfBuilder (Dompdf), ReceiptStore + verifiable-link page
├─ Mail/            WC_Email subclass (Woo) + GenericMailer (FluentCart/standalone) + admin notification
├─ Frontend/        WooMyAccount (orders action + detail injection + endpoint tab),
│                   FluentCartPortal (section_parts filter + fallback), PublicForm (shortcode page),
│                   SignedLink (HMAC order links), TwoStepController, Assets
├─ Shortcodes/      button | form | status | model_form | info  (+ Blocks/ SSR wrappers)
├─ Legal/           LegalDocGenerator (Annex I-B PDF/HTML), ClauseLibrary (Privacy/Terms/pre-contractual)
├─ Compat/          Complianz, TranslatePress, CacheExclusions (Rocket/LiteSpeed/W3TC/Cloudflare)
├─ Admin/           SettingsPage, RequestsDashboard, ComplianceStatusPage, AdminAssets
├─ Debug/           Audience + Collector + Debug facade + Inspector page + SmokeTests
├─ REST/            Authentication + RestApi + Routes/{Withdrawal, Debug, DebugTests, Verify}
└─ I18n/            TextDomain loader + LanguageProvider (TRP/WPML/Polylang/core cascade)
```

### 4.2 Platform adapter (G3)
A single `OrderDataSource` interface normalises orders so the entire Domain layer is platform-agnostic:
```php
interface OrderDataSource {
    public function key(): string;                 // 'woocommerce' | 'fluentcart'
    public function is_active(): bool;             // class_exists('WooCommerce') / function_exists('fluent_cart_api')
    public function get_order( string $order_ref ): ?NormalizedOrder; // never get_post() for HPOS
    public function verify_owner( string $order_ref, int $user_id ): bool;
    public function verify_guest_key( string $order_ref, string $key ): bool;
    public function set_status( string $order_ref, string $status ): bool;
    public function add_note( string $order_ref, string $note ): void;
    public function get_consumer_country( string $order_ref ): string;  // billing → shipping fallback
    public function get_line_items( string $order_ref ): array;         // for Art.59 evaluation
    public function get_dates( string $order_ref ): array;              // created/paid/completed/delivered
    public function get_locale( string $order_ref ): string;            // stored at checkout
}
```
- **WooCommerceAdapter** — HPOS-safe (`wc_get_order`, `OrderUtil::custom_orders_table_usage_is_enabled`, `$order->update_meta_data()/save()`). Declares HPOS compatibility on `before_woocommerce_init` (`FeaturesUtil::declare_compatibility('custom_order_tables', …, true)`). Guest verification via `hash_equals($order->get_order_key(), $key)`.
- **FluentCartAdapter** — detect `function_exists('fluent_cart_api')`; read via FluentCart ORM models (`FluentCart\App\Models\Order`); inject UI via `fluent_cart/customer/order_details_section_parts` (`end_of_order` slot) with a **runtime verification step** (the customer portal is a Vue SPA; if injected HTML is not rendered, fall back to the standalone public form). Status changes via REST `PUT /fluent-cart/v2/orders/{id}/statuses` or order-status hooks. Registered on `fluentcart_loaded`.
- **PlatformRegistry** decides, per order reference, which adapter owns it. Both can be active simultaneously.

> **Reconciliation note:** WooCommerce data lives in order meta (`_wwu_wb_*`) for *operational* status visible in WC admin, **and** in the immutable custom log for *legal evidence*. The two are distinct on purpose — order meta is mutable operational state; the log is append-only proof.

### 4.3 Two-step flow (Art. 11a(2)+(3))
1. **Entry** (`withdraw` button) — never fires the withdrawal; always leads to the statement form. Sources: My Account orders action, order-detail injection, custom endpoint tab, FluentCart portal/fallback, signed order-email link, `[wwu_wb_button]`.
2. **Step 1 — statement form** — fields: name, contract/order identifier, electronic means (email) — **pre-filled & editable** for identified users; required + editable for guests. **No mandatory reason field** (optional, with an explicit "prefer not to say"). Server-side ownership/token re-validation.
3. **Step 2 — confirmation** — a single control labelled **only** with the statutory confirmation words (e.g. `conferma recesso`). On activation → `WithdrawalService::confirm()`:
   - persist append-only **log** row (statement content + IP + UA + timestamps, hash-chained),
   - submit `row_hash` to **OpenTimestamps** (async; WP-Cron upgrades the proof),
   - generate **PDF receipt** + store + mint **verifiable link** token,
   - send **durable-medium acknowledgement** email (PDF attached) to the consumer *and* admin notification,
   - set order status **`wc-withdrawal-requested`** ("Recesso richiesto"); add order note,
   - fire `wwu_wb_withdrawal_submitted` / `wwu_wb_receipt_sent` / `wwu_wb_log_written` / `wwu_wb_timestamp_anchored`.

The submission timestamp is authoritative for **timely exercise** (Art. 11a(5)): a request submitted before the deadline is valid regardless of later processing; late requests are recorded and **flagged**, never blocked (merchant decides).

### 4.4 Immutable log & timestamping (G2)
- **`{prefix}wwu_wb_log`** — append-only, **global hash chain**: `row_hash = sha256(prev_row_hash | request_uid | event | canonical(payload) | created_at)`. Genesis `prev_hash = sha256(site_secret)`. Inserts serialised via a DB transaction + `GET_LOCK` (low write volume). `DATETIME` only (never `TIMESTAMP`), **no** `updated_at`/`deleted_at`. No UI ever updates/deletes a row.
- **`LogChain::verify()`** walks the chain and reports the first broken link (tamper detection), surfaced in the admin dashboard + a smoke test.
- **OpenTimestamps** — `OpenTimestampsProvider` POSTs `sha256(row_hash)+16-byte nonce` to all 4 public calendars (`a/b.pool.opentimestamps.org`, `a.pool.eternitywall.com`, `ots.btc.catallaxy.com`), stores the partial proof, and a **WP-Cron** poller upgrades it to the Bitcoin-anchored proof (HTTP 404 = still pending). Result = free *data certa*.
- **`TimestampProvider`** interface (pluggable): `OpenTimestampsProvider` (default), `Rfc3161Provider` (freetsa.org free / Aruba PEC / Namirial paid eIDAS — post-MVP), `NoneProvider` (audit-only). Stored in `{prefix}wwu_wb_timestamps`.
- **Retention** default **10 years** (contract limitation period), configurable; uninstall offers keep/erase choice (legal-hold awareness).

### 4.5 Durable medium (G2)
- **Email** (canonical durable medium) — WooCommerce: a custom `WC_Email` subclass (manageable under WooCommerce → Emails, theme-overridable, locale-switched). FluentCart/standalone: `GenericMailer` with the same templates. Contains full statement content + exact date/time + unique request ID + verification hash.
- **PDF** — `PdfBuilder` (Dompdf, LGPL-2.1; `isRemoteEnabled=false`; DejaVu Sans for full Latin coverage IT/EN/FR/ES/DE), A4, table-layout. Attached to the email and stored under a protected uploads subdir (`uploads/wwu-wb/receipts/{yyyy}/{uid}.pdf`) with a randomised filename.
- **Permanent verifiable link** — `?wwu_wb_receipt={uid}&t={hmac}` resolves a page that re-serves the PDF + shows the hash + OTS status. Token = HMAC(uid, wp_salt); rate-limited; no enumeration.

### 4.6 Applicability engine
`ApplicabilityResolver::decide(NormalizedOrder): Decision{ applicable, reason, country, statutory_jurisdiction }`:
1. **Consumer country** = billing country → shipping fallback.
2. **Mode** (`wwu_wb_applicability_mode`): `eu_eea_only` (default, strict legal minimum), `always` (show to everyone — low-risk superset, simplest UX), `custom_list`. EU27 + EEA-EFTA (NO/IS/LI) geofence is a filterable constant (`wwu_wb_in_scope_countries`).
3. **B2B detection** — if a validated VAT number is present, default to *out of scope* (configurable, not hard-blocked — "consumer" is about purpose, not VAT registration).
4. **Art. 59 exceptions** — `ArticleFiftyNineEvaluator` per line item: virtual/downloadable already delivered, services fully performed, custom/made-to-measure, sealed hygiene, perishable — auto-detected (WC `is_virtual()/is_downloadable()/get_type()/category`) + admin override (excluded categories/products). **Mixed cart**: show the function if **any** item is withdrawable.
5. **Switzerland** — CH-resident consumer ⇒ no statutory mandate ⇒ voluntary only (never auto-mandatory). CH **seller** to EU consumer ⇒ mandatory for that EU consumer (handled by step 1, consumer-country based).
Each branch returns a machine-readable `reason` for the debug log and admin transparency.

### 4.7 Labels (G4) — statutory wording resolver
`LabelResolver::for(country, locale)` returns the **withdrawal** + **confirmation** label, defaulting to the **official EU directive wording** per language, with **national overrides**:

| Locale / country | Withdrawal | Confirmation | Authority |
|---|---|---|---|
| it / IT | `recedere dal contratto qui` | `conferma recesso` | Art. 54-bis Cod. Consumo |
| en | `withdraw from contract here` | `confirm withdrawal` | Dir. 2011/83 Art. 11a |
| de / DE | `Vertrag widerrufen` *(no "hier")* | `Widerruf bestätigen` | §356a BGB |
| fr / FR | `renoncer au contrat ici` | `confirmer la rétractation` | Art. D.221-5 |
| es / ES | `desistir del contrato aquí` | `confirmar desistimiento` | Dir. (RDL 1/2007 pending) |

Rules: the **confirmation** label is rendered *only* with the statutory words (Art. 11a(3) "only/soltanto" constraint — no combined CTAs). Admin overrides are allowed but emit a `Debug::warn('compliance','label_overridden',…)` and an admin notice if the new text is not in the equivalence allow-list. Labels are gated through i18n **and** marked `data-no-translation` so TranslatePress / machine translators cannot alter the legally exact string.

### 4.8 Compliance documents (G7)
`LegalDocGenerator` renders the **Annex I-B / Allegato I parte B** model withdrawal form (multilingual, PDF + `[wwu_wb_model_form]`), pre-filled with store + order data when available. `ClauseLibrary` provides ready snippets (IT complete; EN/FR/ES/DE based on the harmonised directive with national references + a "have local counsel review" disclaimer) for: Privacy Policy (the immutable log processes personal data — Art. 30 record + Art. 6(1)(c) basis + DPIA note), General Terms, and pre-contractual information (must state the existence and location of the withdrawal function). A **compliance checklist** in admin tracks publication state.

### 4.9 Storage strategy (custom tables — justified)
Per the workspace custom-table standard: Options API for config; **2 custom tables** for the tamper-evident log and the timestamp proofs (append-only, indexed, potentially large, hash-chained — Options API cannot serve this). Numbered **Migrator** (`WWU_WB_Migrator` + `Migration_N::up()`), `wwu_wb_db_version` option, `maybe_upgrade()` on `plugins_loaded:5`, `DROP TABLE` only in `uninstall.php`. See [§5 Data Model](#5-data-model).

### 4.10 No MU-plugin
Unlike AM Lite / PWA Manager, this plugin needs **no** MU-plugin: it does not filter `active_plugins`, does not serve early rewrite endpoints outside WooCommerce's own. Multisite-aware **activation/uninstall** (per-site table creation + option seeding + `wp_initialize_site` provisioning) is included; no shared MU file.

---

## 5. Data Model

### 5.1 Options (Options API)
| Option | Autoload | Shape / purpose |
|---|---|---|
| `wwu_wb_settings` | yes | `{ enabled, endpoint_slug, public_form_page_id, withdrawal_window_days(14), send_pdf(true), receipt_link_enabled(true), merchant_email, retention_years(10), go_live_date('2026-06-19') }` |
| `wwu_wb_applicability` | yes | `{ mode:'eu_eea_only'|'always'|'custom_list', custom_countries:[], b2b_vat_out_of_scope:true }` |
| `wwu_wb_labels` | yes | per-locale overrides `{ it:{withdraw,confirm}, … }` (empty ⇒ statutory defaults) |
| `wwu_wb_exclusions` | yes | `{ excluded_category_ids:[], excluded_product_ids:[], auto_detect_virtual:true }` |
| `wwu_wb_timestamp` | yes | `{ provider:'opentimestamps'|'rfc3161'|'none', rfc3161_url, calendars:[…] }` |
| `wwu_wb_compliance` | no | checklist state: `{ model_form_published, privacy_updated, terms_updated, precontract_updated }` |
| `wwu_wb_debug` | no | Audience config `{ enabled, mode, roles:[], users:[], console_level }` |
| `wwu_wb_db_version` | yes | schema version (hot-path `maybe_upgrade`) |
| `wwu_wb_secret` | no | random per-site secret for log genesis + token HMAC (never exposed) |

### 5.2 Order meta (platform — HPOS-safe via adapter)
`_wwu_wb_status` (pending/approved/rejected/refunded), `_wwu_wb_request_uid`, `_wwu_wb_requested_at` (mysql), `_wwu_wb_locale`, `_wwu_wb_country`, `_wwu_wb_pending_token` (single-use, 48h). Written only via `$order->update_meta_data()/save()` (WC) or ORM (FluentCart) — never `update_post_meta()`.

### 5.3 Custom table — immutable log `{prefix}wwu_wb_log`
```sql
CREATE TABLE {prefix}wwu_wb_log (
  id             bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  request_uid    char(36)     NOT NULL,
  platform       varchar(20)  NOT NULL DEFAULT 'woocommerce',
  order_ref      varchar(64)  NOT NULL DEFAULT '',
  customer_email varchar(255) NOT NULL DEFAULT '',
  event          varchar(40)  NOT NULL,           -- statement_submitted|confirmed|receipt_sent|status_changed|approved|rejected|refunded
  payload_json   longtext     NOT NULL,           -- name, contract details, electronic means, statement text, UA, locale, submission ISO ts
  ip_address     varchar(45)  NOT NULL DEFAULT '',-- raw IP — legal evidence requirement (Art. 54-bis log)
  prev_hash      char(64)     NOT NULL DEFAULT '',
  row_hash       char(64)     NOT NULL,
  ots_proof_id   bigint(20) unsigned DEFAULT NULL,
  created_at     datetime     NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_request (request_uid),
  KEY idx_order (platform, order_ref),
  KEY idx_email (customer_email(60)),
  KEY idx_created (created_at)
) {charset};
```
*Notes:* `DATETIME` (immutable), no `updated_at`, two spaces after `PRIMARY KEY` (dbDelta), raw IP stored because the statute requires it as evidence (GDPR Art. 6(1)(c) basis + Art. 30 record + retention).

### 5.4 Custom table — timestamps `{prefix}wwu_wb_timestamps`
```sql
CREATE TABLE {prefix}wwu_wb_timestamps (
  id           bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  log_id       bigint(20) unsigned NOT NULL,
  sha256_hex   char(64)  NOT NULL,
  nonce_hex    char(32)  NOT NULL,
  provider     varchar(40) NOT NULL DEFAULT 'opentimestamps',
  proof_blob   longblob   DEFAULT NULL,
  bitcoin_block int unsigned DEFAULT NULL,
  status       enum('pending','confirmed','failed') NOT NULL DEFAULT 'pending',
  submitted_at datetime  NOT NULL,
  confirmed_at datetime  DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY idx_log (log_id),
  KEY idx_status (status)
) {charset};
```

---

## 6. API / Interfaces

### 6.1 REST (`wwu-wb/v1`)
| Endpoint | Method | Auth | Purpose |
|---|---|---|---|
| `/withdrawal/statement` | POST | nonce `wp_rest` + ownership/token | Submit Step 1 statement (returns confirmation token) |
| `/withdrawal/confirm` | POST | nonce + token | Step 2 — fire withdrawal, log, receipt |
| `/withdrawal/lookup` | POST | rate-limited | Guest order lookup (order # + email) → signed access |
| `/receipt/{uid}` | GET | HMAC token | Re-serve durable-medium PDF (verifiable link) |
| `/verify/{uid}` | GET | HMAC token | Show hash + OTS status + chain integrity |
| `/debug/run-tests` | POST | admin + audience | Smoke tests (wwu-tools contract) |
| `/debug/snapshot` | GET | admin + audience | Collector snapshot (`?since=`) |

REST permission callbacks check `is_user_logged_in()` + capability only — **never** re-verify the nonce (WP REST handles `X-WP-Nonce` before the callback; trap from recon).

### 6.2 Shortcodes (+ SSR Gutenberg blocks `wwu-wb/*`)
- `[wwu_wb_button order_id="" label="" style="primary|ghost|link" show_after_deadline="false"]`
- `[wwu_wb_form order_id="" token="" redirect_url="" ]`
- `[wwu_wb_status order_id="" token=""]`
- `[wwu_wb_model_form lang="" autofill_order="true" order_id=""]` — Annex I-B model form
- `[wwu_wb_info context="checkout|email|page" format="full|summary|ul"]` — pre-contractual info snippet

Every order-scoped shortcode passes through `wwu_wb_assert_order_access()` (logged-in ownership → guest HMAC token → `manage_woocommerce` bypass), escapes all output, returns `''` on failure (no enumeration). Templates resolve via `wwu_wb_get_template()` (theme override at `{theme}/wwu-withdrawal-button/`).

### 6.3 Hooks (documented, `@since 1.0.0`)
**Filters:** `wwu_wb_button_label`, `wwu_wb_confirmation_label`, `wwu_wb_withdrawal_window_days`, `wwu_wb_compute_deadline`, `wwu_wb_is_applicable`, `wwu_wb_excluded_product_ids`, `wwu_wb_in_scope_countries`, `wwu_wb_email_content`, `wwu_wb_pdf_content`, `wwu_wb_receipt_pdf_path`, `wwu_wb_template_path`, `wwu_wb_admin_capability`, `wwu_wb_rate_limit_max_attempts`, `wwu_wb_rate_limit_window_seconds`, `wwu_wb_order_access_granted`.
**Actions:** `wwu_wb_withdrawal_submitted`, `wwu_wb_receipt_sent`, `wwu_wb_log_written`, `wwu_wb_timestamp_anchored`, `wwu_wb_status_changed`.

### 6.4 Public JS API
`window.WWU_WB` (frontend): `runWithdrawalFlow()`, `openForm(orderRef)`, `isStandalone()`. Bus events `wwu-wb:*`. All emitted `<script>` tags carry `data-wwu-wb="…"` (Complianz whitelist marker).

---

## 7. UI / UX

- **Anti-dark-pattern guards (Standard #12 + Art. 11a):** legibility check (min font size, WCAG AA contrast on the button), prominent placement, no pre-confirmation upsell/discount interstitial, no mandatory reason, no forced phone/account creation, withdrawal as easy as purchase. A self-check panel in admin flags violations.
- **WooCommerce surfaces:** My Account → Orders row action; order-detail notice/button after the items table; a dedicated **"Recesso"** My Account endpoint tab listing the customer's requests + statuses.
- **FluentCart surfaces:** portal injection via `order_details_section_parts` (verified) → fallback standalone public form.
- **Two-step UI:** Step 1 form (pre-filled, "Show example" collapsibles, tooltips per Standard #12) → Step 2 a single statutory-labelled confirm button + a clear "what happens next" line.
- **Receipt confirmation screen:** durable, with link to the PDF + "we also emailed it to you".
- **Admin:** Settings (labels, applicability, exclusions, timestamp provider, retention) · Requests Dashboard (UI Kit table: filters, status, evidence, export, chain-integrity badge) · Compliance Status (go-live countdown to 19 June 2026, document checklist, Complianz/cache/TranslatePress warnings) · Debug Inspector (Standard #11). Admin strings in English (dev tool / support tickets); customer-facing strings i18n.
- **UI Kit Day 1 (Scenario A):** physical copy in `assets/ui-kit/`; selective enqueue `[toast, accordion, ajax, form-field, switch, save-bar, notice, badge, status-chip, tabs]`; plugin CSS prefix `.wwu-wb-*`.
- **WebWakeUp credit:** a discreet "Made by WebWakeUp" line in the Settings footer + a Credits row; **no** intrusive banners, **no** forced external links (wordpress.org-compliant).

---

## 8. Edge Cases
1. **Guest after 10-min WC grace period** — order-key link expired ⇒ public lookup form (order # + email) with rate limiting; receipt still to order email.
2. **HPOS vs legacy** — `meta_query` only on HPOS; provide PHP-filter fallback on legacy (recon trap).
3. **Custom status slug** — register with `wc-` prefix (3 hooks) but compare unprefixed (`get_status()` strips it); dual bulk-action hooks (CPT + HPOS).
4. **My Account / form page cached** — auto-exclude via Rocket/LiteSpeed filters; warn for W3TC/Cloudflare (manual rule).
5. **FluentCart Vue SPA** — if injected HTML is stripped, auto-fallback to standalone form (runtime probe).
6. **Digital auto-complete (FluentCart/WC)** — Art. 59 window may close instantly; evaluate exclusion per item, show informational notice instead of a dead button.
7. **OTS calendars down** — submit to 4 in parallel; mark `pending`; cron retries; never block confirmation on anchoring.
8. **Salt rotation invalidates order-link tokens** — also accept the single-use order-meta token; document.
9. **Delivery date unknown for physical goods** — window start = completed date → paid → created fallback + `_wwu_wb_delivery_date` override; window is **informational** only (never hides the button) → no false blocking.
10. **Late submission** — recorded + flagged "fuori termine", merchant decides validity; never auto-rejected (anti-dark-pattern).
11. **Mixed EU/non-EU cart or B2B** — applicability per consumer country + VAT heuristic; show if any in-scope withdrawable item.
12. **Spain not transposed** — use directive ES labels + direct-effect note; flag for re-check at release.
13. **Germany** — keep §356a Widerrufsbutton separate from a §312k Kündigungsbutton; `Vertrag widerrufen` has **no** "hier".
14. **Multisite** — per-site tables/options/secret; `wp_initialize_site` provisions new sites; uninstall iterates all blogs.
15. **Concurrent confirmations** — DB transaction + `GET_LOCK` serialise the hash chain; idempotency guard on `_wwu_wb_request_uid`.
16. **PHP close-tag / delimiter-pair traps (#56)** in templates (model form contains `<?php` examples) — block comments only, no literal `?>` in `//`.

---

## 9. Security
- **Ownership/auth:** logged-in order ownership; guest = `hash_equals(order_key, key)` or signed HMAC order-email link; `manage_woocommerce` bypass for admins. Confirmation token single-use, 48h, stored in order meta.
- **Rate limiting** on lookup/verify/receipt (transient per IP, filterable; default 10/5 min); identical generic errors (no enumeration).
- **Sanitise all input / escape all output**; nonces on all admin forms/AJAX; REST capability checks; no secrets in logs/snapshots/exports (Collector secret-masking; raw IP in the **legal** log is intentional + documented, never in the debug Collector).
- **PDF safety** — Dompdf `isRemoteEnabled=false`; receipts stored outside web root or under a protected dir with random filenames; served only via HMAC token.
- **Template-include LFI guard** — `wwu_wb_template_path` results confined via `realpath()` to trusted dirs (trap #50/#49 family); custom query vars namespaced `wwu_wb_*` (trap #49).
- **GDPR** — log = Art. 6(1)(c) (acknowledgement) + 6(1)(f) (audit); Art. 30 record + DPIA note generated; retention configurable; uninstall keep/erase choice with legal-hold warning.
- **Complianz** — functional scripts never blocked (marker whitelist); no marketing-consent gating of the withdrawal flow (it is contract performance / legal obligation).

## 10. Performance
- **Zero frontend overhead for non-applicable pages**: assets enqueue only on My Account / form page / order context. Front-of-store and admin-unrelated pages load nothing.
- **Hot paths**: applicability + label resolution are per-request cached; window calc is pure arithmetic.
- **DB**: tight composite indexes; hash-chain insert is O(1) (reads only the last row under lock); OTS submission and PDF generation run on the confirmation request but are bounded (4 HTTP calls w/ short timeouts; PDF ~1 page) — heavy anchoring upgrade is deferred to WP-Cron.
- **No autoloaded bloat**: large/rare options `autoload=no`; tables hold the volume.
- **Caching**: My Account + form page excluded from full-page cache; everything else cache-friendly.

## 11. Testing Strategy
- **Smoke tests** (Standard #11, `wwu-tools` contract) via `POST /wwu-wb/v1/debug/run-tests`: suites `log` (append-only, no updated_at, **chain integrity**, tamper detection), `timestamp` (provider stamp/upgrade shape, pending→confirmed), `applicability` (EU/EEA/CH/B2B/Art.59 matrix), `labels` (statutory per-country, confirmation "only words"), `window` (start/deadline/late-flag), `durable_medium` (PDF generated, email shape, verifiable link token), `platform_woo` (HPOS-safe read/write, status hooks), `platform_fluentcart` (detection + injection probe), `shortcodes`, `compat_complianz`, `compat_translatepress`, `rest_self`.
- **PHPUnit** (where feasible): hash-chain build/verify, applicability resolver, label resolver, Art.59 evaluator, token HMAC, rate limiter.
- **Manual test plans** (`tests/manual-*.md`): end-to-end consumer flow (logged-in + guest) on a live WooCommerce **and** FluentCart store, multilingual labels, Complianz active, TranslatePress active, cache plugin active, iPhone + desktop visual.
- **Audit phase between implementation phases** (Standard #13): security + performance + edge-case + legibility/dark-pattern review; sub-agent delegated where useful.
- **Functional-completeness gate** (Standard #14): no dead affordances; browser-visual every control before "stable".

## 12. Open Questions
1. **Spain transposition** — re-verify on boe.es at release; update article refs/labels if RDL 1/2007 is amended (currently direct-effect with directive ES wording).
2. **France `D.221-5` verbatim** — confirm exact décret wording + any minimum contrast/form rules from Légifrance primary text.
3. **Germany §356a** — confirm the consolidated gesetze-im-internet.de text once it updates on/after 19 June 2026 (currently via BGBl. 2026 I Nr. 28 / law-firm quotes).
4. **FluentCart Vue SPA rendering** — verify on a live install whether `order_details_section_parts` HTML renders; decide portal vs standalone default. Confirm digital product-type field name + order activity-log writability.
5. **EEA (NO/IS/LI)** — confirm transposition status before asserting mandatory; default in-scope but flagged.
6. **Delivery-date source** for physical-goods window start (shipping plugin integration vs manual) — informational only in MVP.
7. **Partial/line-item withdrawal** — ship whole-order in MVP; gauge demand for per-item.
8. **eIDAS qualified timestamp** — if required, obtain Aruba/Namirial TSA endpoints + pricing (commercial contract).
9. **License/PDF cross-check** — GPLv3 ⇒ Dompdf (LGPL-2.1) confirmed compatible; do **not** swap to mPDF (GPL-2.0-only, incompatible with GPLv3) without changing the plugin licence.
10. **AGCM penalty figure** — €10M/4% sourced to Art. 27 c.9/9-bis Cod. Consumo via practitioner sources; final legal copy should cite the consolidated text.

---

## References
Primary legal sources (verified; full verbatim text in [`docs/legal/wwu-wb-legal-reference.md`](../legal/wwu-wb-legal-reference.md)):
- Directive (EU) 2023/2673 — https://eur-lex.europa.eu/eli/dir/2023/2673/oj/eng (+ /ita /deu /fra /spa) — **Art. 11a** + **Recital 37**, application **19 June 2026**.
- Directive 2011/83/EU (consolidated) — https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:02011L0083-20220528 — Art. 3, 9, 16, **Annex I(B)**, Recitals 10 & 58.
- Rome I — Regulation (EC) No 593/2008, **Art. 6** — https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32008R0593
- Italy — D.Lgs. 209/2025 (G.U. 8 Jan 2026) → **Art. 54-bis Cod. Consumo** — https://www.normattiva.it/atto/caricaDettaglioAtto?atto.dataPubblicazioneGazzetta=2026-01-08&atto.codiceRedazionale=26G00002 ; arts. 27/45/52/53/59.
- Germany — **§356a BGB (n.F.)**, BGBl. 2026 I Nr. 28 — https://www.gesetze-im-internet.de/bgb/__356a.html (+ §312k for contrast).
- France — **Ordonnance 2026-2 + Décret 2026-3**, Art. L.221-21 / **D.221-5** — https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000053310520/2026-06-19
- Spain — RDL 1/2007 (LGDCU) arts. 102 ss. (transposition pending as of 06/2026) — https://www.boe.es/buscar/act.php?id=BOE-A-2007-20555
- Switzerland — CO/OR Art. 40a–40g + kmu.admin.ch (no e-commerce withdrawal right) — https://www.fedlex.admin.ch/eli/cc/27/317_321_377/en
- Source article (trigger) — https://fuoridallanorma.substack.com/p/il-pulsante-che-devi-mettere-entro

Technical sources: WooCommerce HPOS/My Account/WC_Email developer docs; FluentCart dev.fluentcart.com (315+ hooks, `order_details_section_parts`); OpenTimestamps calendar HTTP API; Dompdf (dompdf/dompdf, LGPL-2.1); Complianz `cmplz_whitelisted_script_tags`; TranslatePress `data-no-translation` / `trp_woo_email_language`. Full source lists in the legal reference + the recon transcripts.

> Two adversarial legal verifications (EUR-Lex primary sources) **confirmed** the core claims: the Art. 11a label/confirmation/durable-medium requirements, and the consumer-location-based (Rome I Art. 6) applicability incl. the Swiss seller→EU consumer case. A second batch of verifications was rate-limited at the provider; those claims (dates, Annex I-B coexistence, CH no-mandate, 14-day start) are independently sourced in the legal reference and flagged in §12 for counsel confirmation.
