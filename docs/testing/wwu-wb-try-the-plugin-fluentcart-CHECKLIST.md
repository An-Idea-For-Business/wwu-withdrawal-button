# Try the plugin end-to-end — **FluentCart**

> Evaluator checklist. Take a FluentCart store from install to a full, verified withdrawal on a real
> (staging) order — portal button → two-step flow → durable medium → evidence log → merchant
> processing → uninstall. ~30–45 min. Anyone can run it.
>
> For the *exemptions* corner (Art. 59 consent at checkout) see
> [`wwu-wb-fluentcart-consent-CHECKLIST.md`](wwu-wb-fluentcart-consent-CHECKLIST.md).

## Who/what this is for

A merchant or reviewer who wants to confirm the **whole compliance flow** works on FluentCart: the
statutory withdrawal entry points in the FluentCart **customer portal**, the two-step
statement→confirmation, the durable-medium receipt (e-mail + PDF + verifiable link), the tamper-evident
evidence log, and the admin side.

## 0. Environment

- [ ] WordPress 5.8+, PHP 8.1+ (directory build; or PHP 7.4+ with the legacy GitHub build), **FluentCart active**.
- [ ] Install the plugin from the release ZIP (Plugins → Add New → Upload) and **Activate**.
- [ ] (For the PDF copy) the ZIP bundles **Dompdf** — PDF works out of the box; otherwise the durable
  medium degrades to **e-mail-only** (still compliant), with an admin notice.

## 1. One-time setup (WP-Admin → **Withdrawal Button**)

1. [ ] **Settings → General:** turn the function **on** (`enabled`).
2. [ ] **Settings → Where the button applies:** set **Applicability = "Always"** for testing.
3. [ ] **Settings → Receipt & evidence:** timestamp provider (`OpenTimestamps`/`RFC 3161`/`none`),
   **Attach PDF** on, **notification e-mail** = a readable inbox.
4. [ ] **Public withdrawal page (recommended):** create a WP page with **`[webwakeupwdb_form]`** (or the
   **"Withdrawal — self-service"** block) and set it as the plugin's public form page
   (`public_form_page_id`). The FluentCart portal buttons link here when it's set.

## 2. Create a test order

- [ ] Place (or mark **paid**) a normal FluentCart order for a **non-exempt** product as a **test
  customer** whose FluentCart customer is linked to a WordPress user (so the portal shows it).
  Eligibility hinges on **`payment_status = paid`** (the green "Paid" badge), not the fulfillment status.

## 3. The withdrawal entry points appear (4 FluentCart portal surfaces)

As the **customer**, open the **FluentCart customer portal**:
- [ ] **Sidebar menu** has a **"Right of withdrawal"** item (SPA route).
- [ ] **Dashboard** shows a **banner above the orders table** with a CTA to the withdrawal page.
- [ ] The **"Right of withdrawal" page** lists your eligible orders (the chooser).
- [ ] **Open a single order** in the portal → a **withdrawal button** appears in the order summary
  (or a status notice if a request already exists). It links to the form (the public page when set).

> If an entry point is missing: confirm the order is **paid** + non-exempt + Applicability = Always, and
> that the customer is the order's owner. As admin, `?webwakeupwdb_diag=1` prints the applicability decision.
> (The FluentCart portal is a Vue SPA; in-portal styling of the chooser is best-effort, but the links to
> the standalone page always work — see Troubleshooting.)

## 4. The two-step withdrawal (the legal core)

1. [ ] Click the withdrawal button → the **two-step form** opens (name, order, e-mail, optional reason).
2. [ ] **Step 1 — submit the statement** → Step 2 is revealed.
3. [ ] **Step 2 — confirm** with the **statutory-words-only** button → **success** screen.
4. [ ] **No-JS check (optional):** disable JS and repeat via the standalone public page — it posts to
   `admin-post.php` and renders standalone Step-1 → Step-2 → success pages.

## 5. Durable-medium receipt

- [ ] The **customer receives the acknowledgement e-mail** (plain `wp_mail()` path when WooCommerce
  isn't present — FluentCart stores typically have no WC mailer). It contains name, order, items, reason,
  timestamp, the **evidence row hash**, the trader's details, the **PDF link** and the **verify link**.
- [ ] **PDF copy** opens (if `send_pdf` on + Dompdf present).
- [ ] **Verify link** shows `order number`, `submitted at`, `row hash`, **record intact = true**, and
  **within window**. (`?format=json` for the machine version.)

## 6. Evidence-log integrity (tamper-evidence)

- [ ] **Withdrawal Button → Requests:** the confirmed request is listed; the page shows the **"chain
  intact"** badge (hash-chained `{prefix}webwakeupwdb_log`, append-only).
- [ ] OpenTimestamps proof starts **pending** → **confirmed** by the hourly cron (no need to wait).

## 7. Merchant processing

1. [ ] In **Requests**, click **"Open order (refund)"** → it opens the FluentCart order admin
   (`admin.php?page=fluent-cart#/orders/{id}/view`). Issue the refund there.
2. [ ] Click **"Mark processed"** → status **"Processed"**; a `request_processed` event is appended to
   the log, **and** a *"Withdrawal evidence recorded"* note appears in the **FluentCart order activity
   timeline** (`fluent_cart_add_log`, alpha.34).
3. [ ] Click **"Resend e-mail"** → re-sends the acknowledgement (20-second throttle).

## 8. Exemptions (Art. 59) — optional

- [ ] If you sell digital/immediate or services, run
  [`wwu-wb-fluentcart-consent-CHECKLIST.md`](wwu-wb-fluentcart-consent-CHECKLIST.md) (consent on the
  FluentCart checkout, now **category-aware**). Physical products are never exempt.

## 9. Compliance helpers (admin)

- [ ] **Withdrawal Button → Compliance:** go-live countdown, **Annex I-B model form**, **pre-contractual**
  info, and ready-to-paste **legal clauses** — copy into your store policies (review by counsel).

## 10. Smoke test (optional, fast)

- [ ] **Withdrawal Button → Debug Inspector** → enable debug → **Run ALL** → expect 0 fail. (Or REST
  `POST /wp-json/webwakeupwdb/v1/debug/run-tests` with the `wp_rest` nonce.)

## 11. Uninstall / data hygiene

- [ ] By default (`erase_on_uninstall` **off** = legal-hold) the **evidence tables are kept**; plugin
  **options** (incl. the FluentCart per-order meta `webwakeupwdb_fc_*`) are removed.
- [ ] Only with **`erase_on_uninstall` = on** does uninstall **drop** `webwakeupwdb_log` + `webwakeupwdb_timestamps`
  + secret (irreversible). Multisite: handled per site.

## Pass criteria

- [ ] All four FluentCart portal entry points show for an eligible (paid) order.
- [ ] Two-step flow completes with the statutory confirmation label (and works with JS disabled).
- [ ] Acknowledgement e-mail + (optional) PDF + verify link delivered; verify shows **record intact**.
- [ ] Requests shows the request, **chain intact**, reflects refund, and writes the FluentCart timeline
  note on processing.
- [ ] Smoke tests 0 fail; uninstall respects the legal-hold default.

## Troubleshooting

- **No portal entry point** → order not `paid` / exempt / not owned by this customer / Applicability
  hides it. `?webwakeupwdb_diag=1` (admin) prints the decision.
- **Chooser looks unstyled in the portal** → the FluentCart portal is a Vue SPA and its shortcode tag
  isn't documented for asset detection, so in-portal styling is best-effort; the **standalone public
  page** always loads the plugin CSS/JS and is the reliable surface.
- **No e-mail** → check your SMTP/mail plugin; FluentCart stores use the plain `wp_mail()` fallback.
- **No FluentCart timeline note** → it's written at **withdrawal processing**, not at checkout; if
  `fluent_cart_add_log()` is unavailable on your build, the plugin falls back to `addNote()` then meta.
