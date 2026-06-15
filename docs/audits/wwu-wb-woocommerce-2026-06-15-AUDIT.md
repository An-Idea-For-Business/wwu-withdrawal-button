# AUDIT — WooCommerce surface + plugin-conflict review (2026-06-15)

> **Scope:** the WooCommerce part of WWU Withdrawal Button (adapter, My Account, classic +
> block checkout consent, custom order status, refund recorder, WC email link, assets) **and**
> a cross-plugin **conflict** review (global hooks, enqueue handles, output buffering, rewrite
> endpoints, shared filters). **Method:** inline file-by-file read + grep map of every global
> registration. (The multi-agent workflow was attempted 3× but the Anthropic API server-side
> rate-limit blocked every sub-agent, so the audit was performed inline.) **Slug:** `wwu-wb`.

## Verdict

**No critical/high issues. One LOW conflict-safety fix applied; three items documented for
the live test; the WooCommerce surface and conflict surface are otherwise clean.**

---

## Fixed in this pass

### CONFLICT-1 (LOW) — `Mailer::send_html` could leak the global `wp_mail_content_type` filter
`src/Mail/Mailer.php`. The HTML mailer added `wp_mail_content_type ⇒ text/html`, called
`wp_mail()`, then `remove_filter()`. If `wp_mail()` — or any third-party hook firing inside it
(`phpmailer_init`, `wp_mail`, …) — **threw**, the `remove_filter` was skipped and the filter
**leaked for the rest of the request**, turning OTHER plugins' plain-text emails into HTML.
**Fix:** wrapped the `wp_mail()` call in `try { … } finally { remove_filter(…) }` so the global
filter is always removed. Pure conflict-safety; no behaviour change on the happy path.

---

## Documented — no code change (by design / needs the live test)

### NOTE-1 (MEDIUM, by design) — withdrawal transitions the order to a custom status
On a confirmed withdrawal the order moves to `wc-wb-requested` (`WooCommerce\OrderStatus`,
`WooCommerceAdapter::mark_withdrawal_requested`), overwriting `processing`/`completed`. This
fires `woocommerce_order_status_changed`, so other plugins keyed on order status (accounting,
shipping, **subscriptions**, analytics, "completed"-triggered automations) will react. This is
**intentional** — the Requests dashboard + the immutable log are the source of truth and the
status is the operator-visible flag — but it is a real integration consideration. A future
option could flag the withdrawal via order meta + note **without** changing the fulfilment
status, for stores whose downstream automations must not see the transition. Left as-is.

### NOTE-2 (LOW, UX) — the button also renders on the order-received (thank-you) page
`WooMyAccount::order_detail_button` hooks `woocommerce_order_details_after_order_table`, which
fires on the account *view-order* page **and** the *thank-you* page. So a just-placed order in an
eligible status (e.g. `processing`) shows the withdrawal button right after purchase. Legally
harmless (the right exists immediately; Art. 11a wants it "easily accessible") but possibly
unexpected UX. If undesired, scope the hook body with
`is_wc_endpoint_url( 'view-order' ) || is_account_page()`. Left as a product decision for the user.

### NOTE-3 (MEDIUM, needs live + doc re-verify) — block-checkout (Store API) consent capture
`WooBlockCheckoutConsent` relies on (a) the conditional `required`/`hidden` JSON Schema evaluated
against the Store API **document object** (`{cart:{items:{contains:{enum:[ids]}}}}`) and (b)
reading the saved value from order meta `_wc_other/wwu-wb/<name>`. Both depend on the exact shape
of the **Additional Checkout Fields API**, which changed across WC 8.9 → 9.x. If either is off,
the failure is **fail-safe** (no consent captured ⇒ the button **stays visible**, never wrongly
hidden), but the capture would silently no-op on block checkout. Action: verify on a real WC 9.9+
block checkout + re-check the current official docs (already in the live-test checklists).

---

## Confirmed clean

**Conflict surface (cross-plugin):**
- All enqueue handles (`wwu-wb-frontend/admin/inspector`), the localize global (`wwuWbData`),
  shortcodes (`wwu_wb_*`), `admin_post` actions (`wwu_wb_*`), the block (`wwu-wb/withdrawal-form`),
  the REST namespace (`wwu-wb/v1`), options (`wwu_wb_*`) and order meta (`_wwu_wb_*`) are **all
  namespaced** — no collisions with other plugins.
- `ob_start()` exists **only** in `Template::render` around a single template include
  (`ob_get_clean()` in the same method) — **not** a page-level output buffer, so it cannot
  conflict with other plugins' buffering.
- `script_loader_tag ⇒ mark_script_tag` is correctly **handle-scoped**
  (`if ( false === strpos( $handle, 'wwu-wb' ) ) return $tag;`) and idempotent — it never touches
  another plugin's `<script>` tags. The filter is added only on pages that load the bundle.
- `flush_rewrite_rules()` runs **only** on activation (`Install`) and on endpoint-slug change
  (`SettingsPage`) — never on a frontend `init`/`wp` hook, so no rewrite thrash / perf conflict.
- Active **compat integrations** (Complianz script-tag whitelist, WP Rocket / LiteSpeed URI
  exclusions) actively *reduce* conflicts.
- The custom order status uses the canonical 3-hook registration + bulk actions on **both** the
  legacy CPT and HPOS list screens; slug `wc-wb-requested` is unique.

**WooCommerce correctness / security:**
- **HPOS-pure:** every order read/write goes through `wc_get_order()` / `WC_Order` methods and
  `update_meta_data() + save()` (batch single save). No `get_post()/get_post_meta()/$wpdb` on order
  tables — works on HPOS **and** the legacy posts store.
- **Consent capture (classic + block):** stores `_wwu_wb_consent` on the order; PII-free immutable
  log event (verbatim text + IP live only on purgeable order meta); shared `consent_logged`
  idempotency guard across both paths; fail-safe (button stays if capture unavailable). Block path
  guarded by `function_exists` + `WC_VERSION ≥ 9.9.0` (graceful no-op on older WC).
- **Refund recorder:** `woocommerce_order_refunded`, gated on the order actually having a
  `request_uid`; append-only `refund_issued` event.
- **My Account:** owner-bound (`verify_owner` before rendering a form/button for a given order ref)
  — no IDOR; output escaped throughout; the orders-list action returns the correct keyed-array shape.
- **Email link:** `woocommerce_email_after_order_table`, skips admin emails, gated on `decide()->show`.
- Every **display** surface gates on `Services::instance()->applicability->decide($order)->show`
  (renewals + exemptions + B2B + out-of-scope all suppressed centrally).

---

## Follow-ups for the live test
1. Block checkout (WC 9.9+): confirm the consent checkbox is shown + required for a tagged
   product, blocks checkout when unticked, and the value persists to `_wc_other/wwu-wb/<name>` (NOTE-3).
2. Confirm/decide the thank-you-page button behaviour (NOTE-2).
3. Verify the `wc-wb-requested` transition doesn't disrupt the merchant's own status automations (NOTE-1).
