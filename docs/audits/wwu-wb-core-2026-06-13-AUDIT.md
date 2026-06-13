# Audit — Core (F0–F6) · 2026-06-13

Parallel audit (security + performance + compliance-correctness) of the WWU
Withdrawal Button core, run by sub-agents over the real source. This records the
findings and how each was resolved.

## Security — 0 findings
All checked surfaces were found correct: Dompdf hardened (no SSRF, lazy, try/catch);
template loader strips `../`/NUL + realpath-confines; full output escaping; input
sanitisation + server re-validation; admin capability + nonce; debug triple-gate +
TRAP #53 fix; Collector secret masking; per-site secret never exposed; uninstall
legal-hold default; `random_bytes` secret with fallback.

## Compliance (Art. 11a / Art. 54-bis)

| ID | Sev | Finding | Resolution |
|---|---|---|---|
| A4b-status-gate | **critical** | Button shown on failed/cancelled/refunded/unpaid orders (no contract) | Central status-eligibility gate in `ApplicabilityResolver` (allowlist processing/completed/on-hold/paid/…, filter `wwu_wb_eligible_statuses`); covers Woo + shortcode + FluentCart |
| guest-page-zero | **critical** | Guest path dead when `public_form_page_id=0` (dead button, no email link, no warning) | (1) `Install::ensure_form_page()` auto-creates a published `[wwu_wb_form]` page; (2) `OrderEmailLink` injects the statutory link (with order key) into WooCommerce customer emails; (3) red admin warning on the Compliance page when no form page is set |
| A6b-js-only | high | Withdrawal flow was JS-only; non-functional without JavaScript | `NoScriptFlow` server-rendered two-step via admin-post.php (statement → confirm); JS remains progressive enhancement (preventDefault); form now has real POST action + nonce |
| A1-A4-not-default-surfaced | high | No hyperlink in order communications (Recital 37) | `OrderEmailLink` (above) covers guests; My Account surfaces cover logged-in users |
| A10-mail-failure-silent | medium | `wp_mail` failure swallowed; no alert | Capture send result → `receipt_failed` immutable-log row + `wwu_wb_mail_failed` transient → dismissible admin notice |
| A11-late-admin-email | low | Late flag absent from admin email | `within_window` passed to receipt data → late banner in the admin notification |
| A2-contrast-selfcheck | low | Contrast/legibility self-check claimed but not implemented | Downgraded to a manual check (documented); labels keep `data-no-translation` + CSS min-size/contrast |
| guest-name-prefill | low | Guests retype name | Accepted (guests are not "already identified" per Recital 37); future enhancement |

## Performance — fixes applied

| ID | Sev | Fix |
|---|---|---|
| DB-1 | medium→**fixed** | `LogRepository::append()` now aborts (returns 0 + error log) if `GET_LOCK` is not acquired — prevents hash-chain corruption under lock timeout |
| PERF-2 | high→fixed | Public `/verify/{uid}` no longer runs a full-chain scan; it does an O(1) single-row integrity check (`verify_row`). The admin dashboard uses a cached chain status (`chain_status_cached`, 15-min transient) |
| PERF-1/5/7/8 | high/med→fixed | New `Core\Settings` per-request option cache used in `should_enqueue`, `should_show`, `ApplicabilityResolver`, `ArticleFiftyNineEvaluator`, `WindowCalculator`, `LabelResolver`; `wwu_wb_labels/exclusions/timestamp` set to `autoload=no` |
| PERF-4 | medium→fixed | `OrderDataSource::batch_meta()` — `WithdrawalService::confirm()` now does ONE order save instead of seven |
| PERF-6 | medium→fixed | `script_loader_tag` marker filter registered only when our script is actually enqueued, not site-wide |
| PERF-3/9/10, COLD-1 | low/med | Noted; acceptable for current scale (single-platform resolve, synchronous PDF within the legally-required ack, autoloaded `db_version` read). Candidates for a later pass. |

## Verdict
0 security findings. All critical + high compliance gaps closed. All high/medium
performance findings fixed. Remaining items are low-severity/noted.
