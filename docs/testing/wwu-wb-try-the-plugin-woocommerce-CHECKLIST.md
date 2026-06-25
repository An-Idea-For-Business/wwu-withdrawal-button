# Try the plugin end-to-end — **WooCommerce**

> Evaluator checklist. Take a WooCommerce store from install to a full, verified withdrawal
> on a real (staging) order — button → two-step flow → durable medium → evidence log → merchant
> processing → uninstall. ~30–45 min. Anyone can run it.
>
> For the *exemptions* corner (Art. 59 consent at checkout) see
> [`wwu-wb-woocommerce-block-consent-CHECKLIST.md`](wwu-wb-woocommerce-block-consent-CHECKLIST.md).

## Who/what this is for

A merchant or reviewer who wants to confirm the **whole compliance flow** works on WooCommerce:
the statutory withdrawal button, the two-step statement→confirmation, the durable-medium receipt
(e-mail + PDF + verifiable link), the tamper-evident evidence log, and the admin side.

## 0. Environment

- [ ] WordPress 5.8+, PHP 8.1+ (directory build; or PHP 7.4+ with the legacy GitHub build), **WooCommerce active** (HPOS or legacy both fine).
- [ ] Install the plugin from the release ZIP (Plugins → Add New → Upload) and **Activate**.
- [ ] (For the PDF copy) the ZIP bundles **Dompdf** in `vendor/` — PDF works out of the box. If you
  installed from source without `composer install`, the durable medium degrades to **e-mail-only**
  (still compliant); an admin notice will say so.

## 1. One-time setup (WP-Admin → **Withdrawal Button**)

1. [ ] **Settings → General:** turn the function **on** (`enabled`). The go-live date (2026-06-19) is
   shown for information — you can test before it.
2. [ ] **Settings → Where the button applies:** for testing set **Applicability = "Always"** so a test
   order isn't hidden by consumer-country gating. (Production default is `EU/EEA only`.)
3. [ ] **Settings → Receipt & evidence:**
   - **Trusted timestamp provider** = `OpenTimestamps` (free) or `RFC 3161` (Sectigo endpoint is
     pre-filled) — or `none` to skip anchoring during the test.
   - **Attach PDF** (`send_pdf`) on (to test the PDF copy).
   - **Notification e-mail** (`merchant_email`) = an inbox you can read.
   - Note the **My Account endpoint slug** (`endpoint_slug`, default `wwu-withdrawal`).
4. [ ] **Public withdrawal page (recommended):** create a normal WP page (e.g. *"Right of withdrawal"*),
   put the **`[wwu_wb_form]`** shortcode (or the **"Withdrawal — self-service"** block) on it, publish,
   and set it as the plugin's public form page (Settings → it stores `public_form_page_id`). This is the
   guest/standalone entry point.
5. [ ] If you changed the endpoint slug, visit **Settings → Permalinks → Save** once (flush rewrite rules).

## 2. Create a test order

- [ ] Place (or mark **paid/processing/completed**) a normal WooCommerce order for a **non-exempt**
  product, ideally as a **logged-in test customer** (you'll also test the guest path later).

## 3. The withdrawal button appears (3 WooCommerce surfaces)

As the **customer**:
- [ ] **My Account → Orders** — the order row shows a **withdrawal action** (statutory label, e.g.
  *"Recesso"* / *"Withdraw"*).
- [ ] **My Account → order detail** (open the order) — the **withdrawal button** appears below the order
  table (or a status notice if a request already exists).
- [ ] **My Account → "Right of withdrawal" tab** — a dedicated account tab lists eligible orders and
  opens the form.

> If a button is missing: confirm the order is paid + non-exempt + Applicability = Always, then append
> **`?wwu_wb_diag=1`** (as admin) to the order/page to print the applicability decision.

## 4. The two-step withdrawal (the legal core)

1. [ ] Click the withdrawal button → the **two-step form** opens (name, order [read-only], e-mail,
   optional reason).
2. [ ] **Step 1 — submit the statement.** Step 2 is revealed.
3. [ ] **Step 2 — confirm.** The confirmation button is labelled with **only the statutory words**
   (Art. 11a(3); e.g. *"conferma recesso"* in IT). Click it → **success** screen.
4. [ ] **No-JS check (optional but recommended):** disable JavaScript and repeat — the form posts to
   `admin-post.php` and renders standalone Step-1 → Step-2 → success pages (no theme). The result is the
   same; this proves the legal flow works without scripts.

## 5. Durable-medium receipt

- [ ] The **customer receives the acknowledgement e-mail** (WooCommerce-styled when WC e-mails are on;
  subject like *"Acknowledgement of your withdrawal — order …"*). It contains the consumer name, order,
  items, reason, the **timestamp**, the **evidence row hash**, the trader's details, a **PDF download
  link** and a **verify link**.
- [ ] **PDF copy:** open the PDF link → an A4 receipt renders (if `send_pdf` on + Dompdf present).
- [ ] **Verify link:** open it → a human-readable verification page shows `order number`,
  `submitted at`, `row hash`, **record intact = true**, and whether it was **within the 14-day window**.
  (Add `?format=json` for the machine-readable version.)

## 6. Evidence-log integrity (tamper-evidence)

- [ ] **Withdrawal Button → Requests:** the confirmed request is listed with date, order, e-mail,
  country, an **in-time badge**, and a status of **Open**.
- [ ] The page shows a **"chain intact"** badge (green). This is the hash-chained immutable log
  (`{prefix}wwu_wb_log`); there is no edit/delete path.
- [ ] If you chose OpenTimestamps, the proof starts **pending** and is upgraded to **confirmed** by an
  hourly cron (Bitcoin calendars) — you don't need to wait for the test.

## 7. Merchant processing

1. [ ] In **Requests**, click **"Open order (refund)"** → the WooCommerce order edit screen opens in a
   new tab. Issue a refund there as usual.
2. [ ] Back in **Requests**, the status now shows **"Refunded"** with the live WooCommerce amount (the
   refund is read from WooCommerce, the source of truth, and recorded in the log).
3. [ ] Click **"Mark processed"** → status becomes **"Processed"**; a `request_processed` event is
   appended to the log.
4. [ ] Click **"Resend e-mail"** → the acknowledgement is re-sent (20-second throttle).

## 8. Exemptions (Art. 59) — optional

- [ ] If you sell digital/immediate or services, run the consent corner via
  [`wwu-wb-woocommerce-block-consent-CHECKLIST.md`](wwu-wb-woocommerce-block-consent-CHECKLIST.md)
  (classic **and** block Checkout). For physical products there is nothing to do — they're never exempt.

## 9. Compliance helpers (admin)

- [ ] **Withdrawal Button → Compliance:** go-live countdown, the **Annex I-B model form**
  (`[wwu_wb_model_form]`), the **pre-contractual** info (`[wwu_wb_info]`), and ready-to-paste
  **legal clauses** (pre-contractual / terms / privacy / consent-privacy). Copy what you need into your
  store's policies. (Have them reviewed by counsel — the plugin is an aid, not legal advice.)

## 10. Smoke test (optional, fast)

- [ ] **Withdrawal Button → Debug Inspector** → enable debug for your user → **Run ALL** smoke tests →
  expect 0 fail. (Or via REST: `POST /wp-json/wwu-wb/v1/debug/run-tests` with the `wp_rest` nonce.)

## 11. Uninstall / data hygiene

- [ ] Deactivate + delete the plugin. By default (`erase_on_uninstall` **off** = legal-hold) the
  **evidence tables are kept** (you may need the proof for years); plugin **options are removed**.
- [ ] Only if you set **`erase_on_uninstall` = on** does uninstall **drop** `wwu_wb_log` +
  `wwu_wb_timestamps` and the secret — irreversible, explicit opt-in. (Multisite: handled per site.)

## Pass criteria

- [ ] Button shows on all three WooCommerce surfaces for an eligible order; hidden for ineligible.
- [ ] Two-step flow completes with the statutory confirmation label — **and** works with JS disabled.
- [ ] Acknowledgement e-mail + (optional) PDF + verify link all delivered; verify shows **record intact**.
- [ ] Requests shows the request, **chain intact**, and reflects refund + processed status.
- [ ] Smoke tests 0 fail; uninstall respects the legal-hold default.

## Troubleshooting

- **No button** → order not paid / exempt / Applicability hides it. Use `?wwu_wb_diag=1` (admin).
- **No e-mail** → check WooCommerce → Settings → E-mails (our *"Acknowledgement of receipt of your
  withdrawal"* e-mail) or your SMTP/mail plugin; with WC e-mails off, a plain `wp_mail()` fallback is used.
- **No PDF** → `send_pdf` off or Dompdf vendor missing (admin notice). E-mail-only still satisfies the duty.
- **"Integrity broken at row #N"** badge → the log was altered out-of-band; investigate (it should never
  happen through the plugin, which has no update/delete path).
