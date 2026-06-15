# Full-plugin security audit — 2026-06-15 (build 1.0.0-alpha.35 → fixes in alpha.36)

> Comprehensive whole-plugin security audit covering the entire WWU Withdrawal Button codebase
> (incl. the new `EddCustomerOrders`). Method: a 10-dimension multi-agent fan-out, each finding
> then **adversarially verified** (a second agent tried to refute it). Scope: `src/`, `templates/`,
> `uninstall.php`, plugin bootstrap; `build/` (identical dist copy) and `vendor/` excluded except the
> supply-chain dimension. Triggered by the (correct) observation that the prior audit was scoped only
> to access-control.

## Overall verdict — **SOUND**

**0 Critical · 0 High.** No injection, no XSS, no CSRF gap, no broken access control / IDOR, no file
or deserialization vulnerability, no crypto weakness, no vulnerable dependency. One **Medium** (SSRF
via a merchant-configured URL) and a cluster of **Low** hardening items were found and **fixed in
alpha.36**. The two-step withdrawal flow, guest-token model, evidence chain and admin gating are
well-built.

## Clean dimensions (verified, 0 findings)

| Dimension | Result |
|---|---|
| **SQL injection** | Every interpolated value goes through `$wpdb->prepare()` / hard-cast / whitelisted column; table names are constants; LIMIT bound or int-cast; no `$_GET/$_POST/meta` concatenated into SQL anywhere. |
| **XSS / output escaping** | Every output escaped (`esc_html/attr/url`, `wp_kses_post`); `custom_css` sanitised at save **and** output (`Sanitizer::css` strips style-tag breakout); consent-text filter escaped at all 5 render sites; no raw superglobal echo anywhere; CSV export has formula-injection guard. |
| **CSRF / nonce** | All 6 admin state-changing handlers double-gated (capability + `check_admin_referer`); no-JS flow nonce-checked + ownership-gated + confirm-token-bound; no `wp_ajax_*` handlers exist. |
| **AuthZ / IDOR / capability** | 3 ownership proofs enforced on every public entry incl. `EddCustomerOrders` (order id always from the EDD hook, never a request param); no leak/enumeration on failure; admin pages + admin/debug REST require `manage_woocommerce`; `?wwu_wb_diag=1` gated by `manage_options`. |
| **Files / path / deserialization** | Receipt uid double-constrained (route regex + `preg_replace`) → no traversal; Dompdf `isRemoteEnabled=false` + `isPhpEnabled=false` → no SSRF/LFI/RCE; **no `unserialize`/`maybe_unserialize` anywhere** (all JSON); `extract()`/`call_user_func` use internal-only data. |
| **Crypto / evidence integrity** | `hash_equals` on every secret comparison; domain-separated HMACs; deterministic canonical JSON (recursive `ksort`) so an edited evidence field is detectable; append serialised with `GET_LOCK`; `confirm_token` single-use, per-order, 48h TTL; OTS commitment nonce-salted; no `md5/sha1/mt_rand` for security. |
| **Supply chain** | Dompdf **3.1.5** (not affected by CVE-2021-3838 / CVE-2022-28368, both fixed ≥1.2.x); php-font-lib/php-svg-lib/masterminds-html5/sabberworm/safe all current. |

## Findings

| # | ID | Sev (verified) | Status |
|---|---|---|---|
| 1 | `rfc3161-endpoint-ssrf` | **Medium** ✅ confirmed | **Fixed** (alpha.36) |
| 2 | `no-rate-limit-statement-confirm` | Low (was Med) ✅ confirmed | **Fixed** |
| 3 | `unbounded-name-reason` | Low (was Med) ✅ confirmed | **Fixed** |
| 4 | `no-rate-limit-noscript` | Low ✅ | **Fixed** |
| 5 | `collector-pass-key-gap` | Low ✅ | **Fixed** |
| 6 | `customer-email-column-not-chained` | Low ✅ | **Tracked** (design decision) |
| — | `customer-email-in-immutable-log` | Info (GDPR) | Documented (legal, not security) |
| — | `uninstall-multisite-unbounded-get-sites` | **Refuted** | Non-security (super-admin hygiene) |
| 7 | `uninstall-missing-consent-retention-cron` | Low ✅ | **Fixed** |

### 1 — SSRF via merchant-configured RFC 3161 endpoint (Medium) — FIXED
`Rfc3161Provider` validated the merchant-supplied TSA URL only with `esc_url_raw` + WP core
`wp_http_validate_url()`, whose IPv4 list omits `169.254.0.0/16` (cloud-metadata `169.254.169.254`)
and `100.64.0.0/10` (CGNAT) and which has **no IPv6 handling** — so `http://169.254.169.254/…`,
`http://[::1]:6379/`, `http://[fd00::1]/`, `http://[::ffff:169.254.169.254]/` all passed. The endpoint
is settable by `manage_woocommerce` (Shop Manager, below full admin — relevant on delegated/multisite
stores) and the request fires **synchronously** on a public consumer withdrawal-confirm
(`wwu_wb_log_written` → `TimestampService::maybe_stamp()` → `wp_remote_post`), giving a usable blind
SSRF (internal port-scan/liveness via the debug warn channel + timing); verbatim IMDS-credential
exfil into `proof_blob` is conditional on a DER-shaped 200, but the blind SSRF stands alone.

**Fix:** new `src/Security/OutboundUrlGuard.php` — scheme allow-list + host resolved to its A/AAAA
records, every resolved address rejected if private/reserved/loopback/link-local/CGNAT/IPv4-mapped
(IPv4 **and** IPv6, via `FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE` + explicit `100.64/10` and
`::ffff:` extraction), unresolvable hosts rejected (fail-closed). Wired into
`Rfc3161Provider::endpoint_is_valid()` (request-time, TOCTOU-safe with the existing `redirection=>0`)
**and** `SettingsPage::handle_save()` (never persists an unsafe endpoint). Mirrors the proven
`SgtmGuard` pattern from WWU Pixel Manager 0.7.0-alpha.

### 2 & 4 — No rate limit on `statement`/`confirm` + no-JS handlers (Low) — FIXED
`GuestAccess::check_rate_limit()` (10/5 min per IP) was applied only to `lookup` + the receipt
endpoints. A holder of a valid credential (own order key / ~2h HMAC token) could hammer
`statement`/`confirm`, appending permanent log rows and resetting the in-flight `pending` token
(griefing a real consumer mid-confirmation). Authorised self-abuse on an accessible order, not
anonymous DoS → Low. **Fix:** `check_rate_limit()` now gates `WithdrawalRoute::statement()`/`confirm()`
and `NoScriptFlow::handle_statement()`/`handle_confirm()`.

### 3 — Unbounded `name`/`reason` (Low) — FIXED
`WithdrawalRequest::from_input()` sanitised but did not length-cap `name`/`reason`, so an oversized
value permanently bloats the append-only log (LONGTEXT) and drives heavy PDF/e-mail renders. Requires
a valid credential → Low. **Fix:** caps applied after sanitising — `name` 200, `order_ref` 100,
`email` 254, `reason` 2000 (multibyte-safe).

### 5 — Debug Collector masking missed the 4-char `pass` key (Low) — FIXED
Substring-matching hints (`password`, `passwd`) could not match the shorter key `pass` used in the
RFC 3161 config. No active leak today (all `Rfc3161Provider` debug calls pass empty context), but a
future diagnostic logging that array would have exposed the TSA password. **Fix:** added `'pass'` to
`Collector::SECRET_KEY_HINTS`.

### 6 — `customer_email` column outside the hash chain (Low) — TRACKED
The denormalised `customer_email` column isn't in the evidence digest (the authoritative copy lives in
the hashed `payload.statement.email`), so an actor with **direct DB write** (the same trust level the
chain is designed to detect) could spoof the email shown in the dashboard/CSV while `verify_*` still
reports "intact". **Decision deferred** (not code-changed now): the clean fix either bumps the chain
canonical-field set (breaks verification of pre-existing rows) or refactors the admin surfaces to
display from the hashed payload. Tracked for a dedicated change with a chain-format version bump.

### 7 — `consent_retention_purge` cron not cleared on uninstall (Low) — FIXED
`uninstall.php` cleared two cron hooks but not the daily GDPR purge. **Fix:** added
`wp_clear_scheduled_hook('wwu_wb_consent_retention_purge')`.

## Non-security items (recorded, no code change)
- **`customer-email-in-immutable-log` (Info):** the email is retained in the immutable table as legal
  evidence; a GDPR Art. 17 erasure can't delete it without breaking the chain. This is the intended
  legal-evidence trade-off (Art. 6(1)(c)/(f)); documented in the privacy clause. Optional future:
  store a pseudonymised token in the column and keep the raw email only in the purgeable order meta.
- **`uninstall-multisite-unbounded-get-sites` (Refuted as security):** real activation/uninstall
  asymmetry (uninstall doesn't batch like `activate_network`), but only a Super Admin can trigger it
  and the worst case is leftover dead config — a maintenance ticket, not a vulnerability.

## Files changed (alpha.36)
`src/Security/OutboundUrlGuard.php` (new) · `src/Timestamp/Rfc3161Provider.php` ·
`src/Admin/SettingsPage.php` · `src/REST/Routes/WithdrawalRoute.php` · `src/Frontend/NoScriptFlow.php`
· `src/Domain/WithdrawalRequest.php` · `src/Debug/Collector.php` · `uninstall.php`. Lint: PHP 0 errors.

## Method note
14 agents total (10 dimension audits + 4 verifiers for the Medium+ findings). The adversarial pass
**downgraded** two findings (Med→Low) and **refuted** one (the multisite-uninstall item) — i.e. it
actively pruned over-claims, which is the point. No Critical/High survived verification because none
were raised.
