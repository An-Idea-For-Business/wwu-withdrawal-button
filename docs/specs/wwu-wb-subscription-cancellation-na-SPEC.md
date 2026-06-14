# SPEC — North America Subscription-Cancellation ("Click-to-Cancel") module

> **Status:** Design only — forward-looking. No implementation until the user gives the go-ahead.
> **Created:** 2026-06-14 · **Slug:** `wwu-wb` · **Module slug:** `na-cancel`
> **Scope decision (user, 2026-06-14):** a **distinct** subscription-cancellation module (NOT an extension of the EU 14-day withdrawal right), living in the **same plugin** as jurisdiction-toggled modules, covering **Quebec, other Canadian provinces, US Federal, and US states**.
> **Research basis:** 5-agent official-source sweep (2026-06-14) — see § References. Findings are dated "as of June 2026"; verify before build.

---

## 1. Overview

The EU "withdrawal button" (this plugin's current feature) implements the **14-day right of withdrawal on a purchase** — the consumer returns a product/service. North American "click-to-cancel" laws are a **different legal mechanism**: the easy, self-serve **cancellation of an ongoing / auto-renewing subscription**, plus pre-renewal notices and anti–dark-pattern rules. A merchant can owe both, neither, or one — they do not overlap.

This module adds a **jurisdiction-aware subscription-cancellation surface** to stores using **WooCommerce Subscriptions** or **FluentCart subscriptions**: a prominent, self-serve "Cancel subscription" control in the customer account, automated pre-renewal / annual reminder emails with prescribed content, an always-visible cancel CTA whenever a retention/"save" offer is shown, and a tamper-evident audit log of cancellations and notices (reusing this plugin's evidence-log infrastructure) to support the record-keeping and proof-of-receipt obligations.

It is a **separate module** from the withdrawal button: separate settings tab, separate applicability (per consumer **state/province**), separate labels, its own surfaces — but it reuses the plugin's shared spine (platform adapter pattern, evidence log, durable-medium notices, debug stack, REST + nonce/cap gating).

### Why this matters now (dated)
- **BC (Bill 4)** subscription/auto-renewal rules are **in force 2026-08-01** — the first hard NA deadline.
- **Quebec (Bill 10)** §187.28 cancellation button is **in force 2026-09-12**.
- **California ARL (AB 2863)** click-to-cancel button + annual reminder applies to contracts entered/amended/extended **on/after 2025-07-01** (already live).
- **US FTC federal "Click-to-Cancel" rule is VACATED** (8th Cir., 2025-07-08) — **ROSCA** (15 U.S.C. §§ 8401–8405) is the operative federal tool; **FTC v. Amazon** settled for **$2.5B** (2025-09).

---

## 2. Goals & Non-Goals

### Goals
1. Give subscribers a **one-step, self-serve online cancellation** control wherever they signed up online (CA/NY/CO/VA/MN; BC for ≤60-day renewals; Quebec §187.28).
2. Render a **"Cancel" / "Click to cancel" CTA simultaneously and prominently** whenever a retention/save offer is shown (California AB 2863 — the strictest, novel requirement).
3. Send **pre-renewal notices** (trial-ending + annual/long-term) and **annual reminders** with prescribed content (amount, date, how to cancel), per-jurisdiction timing.
4. Maintain a **proof-of-notice + proof-of-cancellation audit trail** (BC "manner that proves receipt"; California 3-year consent record retention).
5. Be **jurisdiction-toggled and per-consumer**: apply the right rules based on the consumer's state/province, default to the strictest configured baseline.
6. Integrate with **WooCommerce Subscriptions** and **FluentCart subscriptions** via official hooks/APIs; degrade gracefully when neither is present.
7. Reuse this plugin's spine (adapter pattern, evidence log, durable medium, debug, i18n) — no parallel infrastructure.

### Non-Goals
- **Not** the EU withdrawal right (that is the existing module; they stay separate).
- **Not** legal advice — a compliance aid; the merchant configures jurisdictions and is responsible for legal accuracy (mirror the existing plugin's disclaimer).
- **Not** building our own subscriptions/billing engine — we integrate with WooCommerce Subscriptions / FluentCart only.
- **Not** implementing Ontario / New Brunswick **binding** rules yet — both are **passed but not proclaimed** (June 2026); ship the generic mechanism that will satisfy them, gated off by default, and monitor for proclamation.
- **Not** relying on the **vacated** FTC federal rule; we encode **ROSCA** + state laws as the operative US framework.

---

## 3. User Stories

- **As a subscriber in California**, I can cancel my subscription online in one step from My Account, and if the store shows me a discount to stay, a clearly labeled "Cancel" button sits right next to it.
- **As a subscriber in BC** on a monthly plan, I can cancel at any time without penalty; on a long (>60-day) plan I receive an advance notice 30–60 days before renewal telling me the date, how to cancel, and that it renews if I do nothing.
- **As a Quebec subscriber**, I see a readily identifiable cancellation button and get a notice 2–10 days before a promo price ends, stating the new price and when it starts.
- **As a merchant**, I switch on the jurisdictions I sell to, pick a baseline (e.g. "strictest"), and the plugin wires the cancel control + notices into my existing WooCommerce Subscriptions / FluentCart store without me touching code.
- **As a merchant facing a complaint**, I export a tamper-evident log showing the cancellation was honored immediately and the required notices were delivered (proof of receipt).
- **As a developer**, I extend jurisdictions/labels/notice-timing via filters, and read the module's runtime state from the Debug Inspector.

---

## 4. Architecture

Mirror the existing plugin's layering; add a parallel **Subscription** spine alongside the **Order** spine.

```
src/
├── Subscription/
│   ├── SubscriptionSource.php        (interface — platform-agnostic subscription ops)
│   ├── WooSubscriptionsAdapter.php   (WooCommerce Subscriptions)
│   ├── FluentCartSubscriptionAdapter.php
│   ├── SubscriptionRegistry.php      (active adapters; both may be active)
│   └── NormalizedSubscription.php    (VO: id, platform, customer, status, next_renewal, term_days, trial_end, price, region)
├── NaCancel/
│   ├── JurisdictionResolver.php      (consumer state/province → rule set)
│   ├── RuleSet.php                   (per-jurisdiction: online_cancel?, click_to_cancel_button?, notice windows, annual_reminder?, anti_dark_pattern?)
│   ├── CancellationService.php       (verify ownership → cancel via adapter → log → notify)
│   ├── NoticeScheduler.php           (pre-renewal + annual reminders via Action Scheduler)
│   └── RetentionGuard.php            (enforce co-displayed cancel CTA when a save-offer renders)
├── Frontend/
│   ├── CancelSurface.php             (inject cancel control into Woo My Account + FluentCart portal)
│   └── CancelBlock.php / shortcode   ([wwu_wb_cancel])
├── Admin/NaCancelSettingsPage.php
└── REST/Routes/CancellationRoute.php (self-serve cancel: cap + nonce + ownership)
```

**Reused spine (no duplication):** `Storage/LogChain` + evidence tables (proof-of-cancellation/notice), `DurableMedium` (notice emails + retainable acknowledgement), `Debug/*` (Inspector + smoke suites), `Core/Services` (DI), Action Scheduler (already a dependency pattern in the workspace), i18n.

**Platform integration (official hooks):**
- **WooCommerce Subscriptions** — surface a cancel control via the `wcs_view_subscription_actions` / `woocommerce_my_subscriptions_actions` filters; gate transitions via the `woocommerce_can_subscription_be_updated_to` filter; cancel with `$subscription->update_status('pending-cancel'|'cancelled')`; optionally skip pending-cancel (mirror the official `woocommerce-subscriptions-skip-pending-cancel` extension) when a jurisdiction requires *immediate* cessation of charges; listen to `woocommerce_subscription_status_cancelled` for post-cancel logging/CRM.
- **FluentCart** — customer self-service uses `POST /wp-json/fluent-cart/v2/customer-profile/subscriptions/{uuid}/cancel-auto-renew` (cookie + `X-WP-Nonce`); **immediate full cancel** is admin-only (`PUT /…/orders/{order_id}/subscriptions/{id}/cancel`, Application Password) — so for customer-initiated *immediate* cancel we run a **server-side proxy** that verifies ownership then calls the model `cancelRemoteSubscription(reason, fire_hooks, note)`. The `SubscriptionCanceled` event hook name is **not in public docs** (June 2026) → source inspection needed (Open Question).

**Applicability:** reuse the EU module's "follow the consumer" principle, but keyed on **state/province**. Default mode `strictest` = apply the union of enabled jurisdictions' requirements to every subscriber (simplest + safest); optional `per_region` mode resolves rules by the subscriber's billing region.

---

## 5. Data Model

Options (`wwu_wb_na_cancel`): `{ enabled, jurisdictions[], baseline_mode (strictest|per_region), immediate_cancellation (bool), notice_windows{trial_days, annual_days}, annual_reminder (bool), retention_offer_guard (bool), labels{} }`.

Reuse the evidence log for new event types (append-only hash chain): `subscription_cancel_requested`, `subscription_cancel_confirmed`, `prerenewal_notice_sent`, `annual_reminder_sent`, `retention_offer_shown` — each row stores subscription id, platform, consumer region, timestamp, and (for notices) the delivery proof token. This directly serves **BC's proof-of-receipt** and **California's 3-year consent/record retention**.

`NormalizedSubscription` VO: `{ platform, sub_ref, customer_id, email, region(state/province), status, signup_channel, term_days, trial_end?, next_renewal?, price, currency }`. `signup_channel` (online/phone/in-person) drives the "same medium as signup" rule (NY explicit; CA implied; FTC §425.6 vacated but ROSCA-aligned).

Scheduled notices: tracked as Action Scheduler actions (group `wwu-wb-na-cancel`), keyed by subscription + notice type, idempotent (don't double-send).

---

## 6. API / Interfaces

- **REST** `POST /wwu-wb/v1/subscription/cancel` — body `{ platform, sub_ref }`; gates: logged-in + nonce + **ownership** (subscriber owns the subscription) + capability; returns `{ cancelled, effective, immediate }`. Self-serve only; never trusts a client-supplied owner.
- **Adapter interface** `SubscriptionSource`: `is_active()`, `list_for_user($user_id)`, `get($sub_ref)`, `can_cancel($sub_ref, $user_id)`, `cancel($sub_ref, $immediate)`, `next_renewal($sub_ref)`.
- **Filters:** `wwu_wb_na_jurisdiction_rules` (extend/override per-jurisdiction RuleSet), `wwu_wb_na_notice_window` ($days, $type, $region), `wwu_wb_na_cancel_label` ($label, $region), `wwu_wb_na_can_cancel` ($bool, $sub, $user).
- **Action:** `wwu_wb_subscription_cancelled` ($normalized_subscription, $immediate) — for CRM/automation (e.g. future FluentCRM module, see [[fluentcrm roadmap]]).
- **Shortcode/block:** `[wwu_wb_cancel]` / `wwu-wb/cancel-subscriptions` — renders the subscriber's cancelable subscriptions list (mirrors the EU `EligibleOrders` chooser pattern).

---

## 7. UI / UX

- **Customer cancel surface:** a prominent "Cancel subscription" button on the Woo *View Subscription* page and the FluentCart customer portal (reuse the `FluentCartPortal` custom-endpoint pattern shipped for the EU module). One step; confirm only if a jurisdiction allows a single confirmation (never multi-step "are you sure?" loops — Minnesota + California ban obstruction).
- **Retention-offer guard:** if the merchant (or another plugin) renders a save/discount offer in the cancel flow, `RetentionGuard` ensures a clearly labeled **"Click to cancel"** control is co-displayed, prominent, and proximate (California AB 2863). If our module can't guarantee co-display (offer rendered by a third party), show an admin warning rather than silently failing.
- **Notices:** branded emails via the durable-medium layer — pre-renewal (trial-ending; annual/long-term) and annual reminder, each stating product name, charge amount + frequency, renewal/charge date, and how to cancel.
- **Admin:** a "Subscription cancellation (North America)" settings tab — jurisdiction multi-select with per-jurisdiction "in force / not yet proclaimed" badges (ON/NB flagged not-yet-binding), baseline mode, immediate-vs-period-end toggle, notice windows, retention-guard switch, label overrides — each control with tooltip + plain-language help + example (Standard #12). Onboarding note that this is distinct from the EU button.
- **i18n:** en_US/en_CA + fr_CA (Quebec) at minimum; labels overridable per jurisdiction.

---

## 8. Edge Cases

- **Neither subscriptions platform active** → module self-disables, admin notice explains it needs WooCommerce Subscriptions or FluentCart subscriptions.
- **Both platforms active** → merge subscriptions across both (mirror the EU chooser's platform-agnostic merge; learn from the alpha.21 Eloquent-collection bug — unwrap FluentCart collections with `->all()`, never `(array)`).
- **FluentCart immediate-cancel** has no public customer REST endpoint → server-side proxy via `cancelRemoteSubscription()` after ownership check; if that path is unavailable, fall back to `cancel-auto-renew` (period-end) and clearly tell the consumer when charges stop.
- **Region unknown** → default to the strictest enabled jurisdiction (consumer-protective), never hide the cancel control.
- **`signup_channel` unknown** → assume online (offer online cancel).
- **Pre-renewal notice idempotency** → keyed Action Scheduler actions; never double-send; handle subscription edits that move the renewal date.
- **Already-cancelled / expired / pending-cancel** subscriptions → no cancel button (respect `woocommerce_can_subscription_be_updated_to`).
- **Ontario / New Brunswick** enabled by an over-eager admin → keep the rules behind a "not yet in force" gate; surface them as informational until proclamation.
- **FTC federal**: do **not** present the vacated rule as binding; the US baseline is ROSCA + the enabled state laws.

## 9. Security

- Self-serve cancel REST: logged-in + nonce + **ownership verification** through the adapter (never cancel a subscription the requester doesn't own) + capability. Mirror the EU module's `Authentication` gating.
- FluentCart admin endpoint requires an Application Password — **never** expose it client-side; the proxy runs server-side and verifies ownership before calling `cancelRemoteSubscription()`.
- Evidence-log rows are append-only (hash chain) — cancellations/notices are tamper-evident.
- All notice/label inputs sanitized; all output escaped; merge-tag values resolved server-side.
- No PII in URLs; notice links use signed tokens (reuse `VerifiableLink`).

## 10. Performance

- Zero frontend overhead for non-subscribers / logged-out (gate like the EU `Assets::should_enqueue`).
- Subscription lookups capped + per-request cached (mirror the adapters' order cache).
- Notices run on Action Scheduler (async, batched) — never inline on a pageview.
- Region resolution memoized per request.

## 11. Testing Strategy

- **Smoke suites** (extend `SmokeTests`, now drift-proof via `suite_names()`): `na_jurisdiction` (region → ruleset), `na_rules` (CA click-to-cancel button required; BC ≤60d any-time; notice windows), `na_notice_scheduler` (idempotent keying), `subscription_adapter` (platform-agnostic helpers, like the FluentCart `unwrap_collection`/`eligible_status` pattern — pure + testable without the platform active).
- **Adversarial/regression:** ownership bypass attempts; double-send notices; retention offer without co-displayed cancel CTA; region-unknown defaults to strictest.
- **Manual plan** against a WooCommerce Subscriptions + FluentCart test store: cancel one-step online; verify immediate vs period-end; verify notice content/timing; verify the audit log + proof tokens.
- **Admin diagnostic** parallel to `?wwu_wb_diag=1`: per subscription, show region, applicable ruleset, and why a control is/isn't shown.

## 12. Open Questions

1. **Plugin naming tension.** The plugin is "WWU Withdrawal Button" (EU). Per the user's choice this NA module lives in the same plugin. Do we (a) keep the name and treat NA as a clearly-separate module, or (b) eventually rebrand to an umbrella ("Consumer Exit Rights") with EU + NA modules? (User picked "same plugin, jurisdiction modules" — naming is the residual question.)
2. **FluentCart `SubscriptionCanceled` hook name** is not in public docs (June 2026) — needs source inspection before relying on it for post-cancel automation.
3. **Immediate vs period-end** default: California/ROSCA want charges to *stop*; WooCommerce defaults to `pending-cancel` (period-end). Ship a per-jurisdiction default or a global toggle? (Lean: immediate where the law implies "stop charges," period-end otherwise, configurable.)
4. **Quebec §187.28/§187.29 exact statutory text** unverified (LégisQuébec 403); **Quebec penalty tiers** specific to Bill 10 unconfirmed (extrapolated from the baseline CPA regime). Verify before publishing legal copy.
5. **Ontario / New Brunswick** proclamation + regulations pending — ship gated-off, monitor.
6. **Notice-window unification:** a 15–21-day pre-annual-renewal + 7–14-day pre-trial default satisfies CA + MN; confirm it also satisfies VA (30–60) — VA's wider window may need its own setting.
7. **NYC DCWP** municipal click-to-cancel rule (proposed April 2026) — monitor; not in scope until in force.

---

## References (official sources, dated June 2026)

**Quebec — Bill 10:** [OPC — Projet de loi no 10](https://www.opc.gouv.qc.ca/a-propos/projet/revente-billets) · [Québec press release (2025-12-02)](https://www.quebec.ca/nouvelles/actualites/details/depot-du-projet-de-loi-no-10-les-quebecois-bientot-mieux-proteges-contre-les-pratiques-abusives-de-revente-de-billets-et-de-renouvellement-dabonnements-en-ligne-67424) · [Assemblée nationale — PL 10](https://www.assnat.qc.ca/fr/travaux-parlementaires/projets-loi/projet-loi-10-43-2.html) · [CPA CQLR c P-40.1](https://www.legisquebec.gouv.qc.ca/en/document/cs/P-40.1) (403 at research time).

**BC — Bill 4 (2025):** [Bill 4 text](https://www.bclaws.gov.bc.ca/civix/document/id/bills/billsprevious/1st43rd:gov04-1) · [BC Gov news — Aug 1 2026](https://news.gov.bc.ca/releases/2026AG0007-000122) · [BPCPA SBC 2004 c 2 (s.190 penalties)](https://www.bclaws.gov.bc.ca/civix/document/id/complete/statreg/04002_00).

**Ontario — CPA 2023 (not proclaimed):** [CPA 2023 e-Laws](https://www.ontario.ca/laws/statute/23c23) · [Bill 142](https://www.ola.org/en/legislative-business/bills/parliament-43/session-1/bills/142) · [Proposed Phase 1 regs](https://www.ontariocanada.com/registry/view.do?postingId=44510&language=en).

**New Brunswick — CPA 2024 (not proclaimed):** [SNB 2024 c 1](https://laws.gnb.ca/en/document/cs/2024-C.1) (403) · [Bill 16](https://www.legnb.ca/en/legislation/bills/60/3/16/consumer-protection-act) · [FCNB](https://fcnb.ca/en/consumer-protection).

**US Federal — FTC / ROSCA:** [16 CFR Part 425](https://www.law.cornell.edu/cfr/text/16/part-425) · [§425.6 Simple Cancellation](https://www.law.cornell.edu/cfr/text/16/425.6) · [Final Rule 89 FR 90537](https://www.federalregister.gov/documents/2024/11/15/2024-25534/negative-option-rule) · [ROSCA 15 U.S.C. §§8401–8405](https://www.law.cornell.edu/uscode/text/15/chapter-110) · [FTC v. Amazon $2.5B (2025-09)](https://www.ftc.gov/news-events/news/press-releases/2025/09/amazon-pay-25-billion-resolve-ftc-charges-over-illegal-prime-cancellation-practices) · [Negative Option Rule landing](https://www.ftc.gov/legal-library/browse/rules/negative-option-rule).

**US States — ARLs:** [CA BPC §17602](https://leginfo.legislature.ca.gov/faces/codes_displaySection.xhtml?lawCode=BPC&sectionNum=17602) · [CA §§17600–17606](https://leginfo.legislature.ca.gov/faces/codes_displayText.xhtml?lawCode=BPC&division=7.&title=&part=3.&chapter=1.&article=9.) · [AB 2863](https://leginfo.legislature.ca.gov/faces/billTextClient.xhtml?bill_id=202320240AB2863) · [NY GBL §527-A](https://www.nysenate.gov/legislation/laws/GBS/527-A) · [CO SB25-145](https://leg.colorado.gov/bills/sb25-145) · [VA §59.1-207.46](https://law.lis.virginia.gov/vacode/title59.1/chapter17.8/section59.1-207.46/) · [MN §325G.57](https://www.revisor.mn.gov/statutes/cite/325G.57).

**Feasibility — WooCommerce Subscriptions:** [Action Reference](https://woocommerce.com/document/subscriptions/develop/action-reference/) · [Filter Reference](https://woocommerce.com/document/subscriptions/develop/filter-reference/) · [Management functions](https://woocommerce.com/document/subscriptions/develop/functions/management-functions/) · [Statuses](https://woocommerce.com/document/subscriptions/statuses/) · [Customising My Subscriptions](https://woocommerce.com/document/subscriptions/develop/customizing-the-my-subscriptions-page/) · [Skip pending-cancel ext](https://github.com/woocommerce/woocommerce-subscriptions-skip-pending-cancel).

**Feasibility — FluentCart:** [REST API](https://dev.fluentcart.com/restapi/) · [Subscriptions API](https://dev.fluentcart.com/api/subscriptions) · [Subscription model](https://dev.fluentcart.com/database/models/subscription) · [Customer dashboard: subscriptions](https://docs.fluentcart.com/guide/customer-dashboard/subscriptions).

> **Confidence caveats** carried from research: Quebec section numbers + penalty tiers are law-firm-derived (statute 403); FTC rule is **vacated** (ROSCA operative); Ontario/NB **not proclaimed**; FluentCart `SubscriptionCanceled` hook name unverified. Re-verify any figure before it appears in merchant-facing or legal copy.
