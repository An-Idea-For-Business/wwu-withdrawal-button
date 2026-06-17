# Full security audit — WWU Withdrawal Button — 2026-06-17

> Pre-wordpress.org-submission audit. **5 parallel sub-agents**, one slice each (2 Opus on the
> security-critical slices, 3 Sonnet). Plugin at `1.0.0` → fixes land in **`1.0.1`**.
> Scope: whole plugin except vendored `vendor/` (Dompdf et al. treated as third-party).

## Verdict

**No remotely-exploitable XSS, SQL injection, SSRF, CSRF, or IDOR was found.** Input
sanitisation, output escaping, `$wpdb->prepare()` usage, capability + nonce coverage, the
SSRF guard, HMAC token comparison (`hash_equals`), and PII scoping at the public boundary
are all correct and consistently applied.

The substantive findings are **three deeper integrity/privacy issues** in the
"tamper-evident evidence log" — they do **not** block the wordpress.org submission, but they
qualify the product's core legal claim and should be addressed in a focused follow-up
(tracked below). Everything else is Low/Info hardening, the bulk of which shipped in `1.0.1`.

## Fixed in 1.0.1

| ID | Sev | Area | Fix |
|----|-----|------|-----|
| PCP-1 | Blocker (wp.org) | `assets/ui-kit/js/clipboard.js` filename collides with a WP-core library | excluded from the dist build (`.distignore`); the file is unused (only `accordion`/`badge`/`utilities` are enqueued) |
| PCP-2 | Blocker (wp.org) | `readme.txt` Tested up to 6.8 < 7.0 | bumped to `7.0` (+ README.md) |
| PCP-3 | Blocker (wp.org) | `src/Debug/SmokeTests.php` literal `http://localhost/` (SSRF unit-test) | test now uses a private IP `10.0.0.1` (still blocked by the guard, not flagged) |
| PCP-4 | Warning (wp.org) | `composer.json` missing while `vendor/` ships | `composer.json` now included in the dist (vendor stays — Dompdf is needed at runtime; zip is 5 MB < 10 MB) |
| M-1 | Medium | `OpenTimestampsProvider` skipped `OutboundUrlGuard` + followed redirects (filter-reachable SSRF, inconsistent with webhook/RFC 3161) | guard re-check at request time + `redirection => 0` + `reject_unsafe_urls => true` on both stamp + upgrade |
| A4-1/2 | Low | `rfc3161_pass` + `webhook_secret` cast without `wp_unslash()` in `SettingsPage::handle_save()` | added `wp_unslash()` (formally-complete sanitisation; no real risk on PHP 7.4+) |
| A3-1/2 | Low | `NoScriptFlow` error branches relied on `render_page()` exiting, no explicit `return` | added explicit `return;` after both error renders (defensive) |

> **Note on PCP-4:** an audit sub-agent recommended *removing* `vendor/` from the zip; that is
> incorrect — wp.org permits bundled libraries, Dompdf is required at runtime, and the warning
> is literally "composer.json missing." Including `composer.json` is the documented fix.
> Excluding the whole `src/Debug/` directory was also rejected: the `Debug` facade is used
> throughout the runtime; only the one localhost literal needed changing.

## Deferred to a follow-up (1.1) — integrity & privacy of the evidence log

These are real, but they are **not** remote-exploit vulnerabilities and they do not block the
directory submission. They touch the legal-evidence design, so they want a deliberate change +
re-test rather than a rushed patch.

### HIGH-1 — Evidence-log hash chain is unkeyed SHA-256 over public data
`Storage/LogChain.php`. Each row hash = `sha256(prev_hash | canonical_evidence)`; the site
secret enters **only** at the genesis hash. An actor who can write to the DB (compromised
admin, hosting/DB breach, malicious contractor) can read `wwu_wb_secret` (plaintext option),
recompute genesis, then rewrite/insert/reorder/back-date any row and re-derive the whole
downstream chain — passing both `verify_row()` and `verify_chain()`. The chain is a
*consistency checksum*, not tamper-evidence against an insider. **Fix:** make the external
timestamp the real anchor (HIGH-2), HMAC-key the per-row hash (`hash_hmac` with the secret) as
defence-in-depth, and document that DB-admin compromise is out of scope for the chain alone —
the timestamp proof is the cross-check.

### HIGH-2 — External timestamp anchor is best-effort and never fully verified
`Timestamp/OpenTimestampsProvider.php`, `Rfc3161Provider.php`, `TimestampService.php`. OTS
proof is stored but `bitcoin_block` is always `null` (the `.ots` attestation is never parsed/
verified); the RFC 3161 response is only checked for `PKIStatus` (the TSA **signature** and
`messageImprint` are never validated, and the docblock suggests `http://` endpoints); and a
failed *initial* stamp is never retried. So the anchor that should detect HIGH-1's back-dating
is either absent or unverified. **Fix:** parse/verify the `.ots` proof and surface it on the
public verify endpoint; verify the RFC 3161 token (imprint + nonce + signature) and require
`https`; retry failed initial stamps on cron and flag un-anchored confirmed rows in admin.

### MEDIUM-1 — Withdrawal-event IPs in the immutable log have no GDPR erasure horizon
`Domain/WithdrawalService.php` (statement/confirm/cancel write `ClientInfo::ip()` into the
append-only log), `Core/ConsentRetention.php` (purge only anonymises the order-meta consent
IPs, never the log). The log's withdrawal IPs are retained indefinitely — at odds with GDPR
Art. 5(1)(e). The IP is part of the hashed evidence, so it can't simply be blanked. **Fix:**
hash only the **anonymised** IP (`wp_privacy_anonymize_ip()`) and keep the full IP in a
separate purgeable column (mirroring the consent model); also purge `customer_email` in the log
after the horizon (it is not in the hash, so safe to blank).

## Low / Info (accepted or documented, no change needed now)

- **A1 L-1/L-2:** read-API rate-limit is non-atomic + keys on `REMOTE_ADDR` — acceptable (the
  read API is admin-capability-gated; the comment + CLAUDE.md trap already note it).
- **A1 L-3:** `RequestReader::confirmed_row()` does `SELECT *` (pulls `ip_address` into memory);
  no leak today (no output shape emits it) — future-proof by selecting explicit columns.
- **A1 L-4:** CSS sanitizer regex is bypassable, but author is `manage_woocommerce` (already
  trusted) and `<`/`>` stripping prevents the `</style>` break-out that matters.
- **A1 L-5:** webhook body has no signed timestamp/nonce — receiver owns idempotency (delivery
  id header). Optional hardening.
- **A3 Info:** `wwu_wb_applicability_decision` filter return value not type-checked (a hostile
  filter could fatal) — add an `instanceof` guard.
- **Verified clean (spot list):** Dompdf `isRemoteEnabled=false` (no SSRF/LFI); PDF/email/verify
  templates fully escaped; no email-header injection; append uses a named MySQL lock (no chain
  fork); SSRF guard covers IPv6/IPv4-mapped/CGNAT/cloud-metadata; secrets `autoload=no` + masked
  + never exported; CSV export is formula-injection-safe + admin/nonce-gated; i18n text domain
  consistent; all globals namespaced; uninstall.php multisite-complete; no `eval`/`base64`-on-code/
  `extract`-as-security/`session_start`; PHP 7.4 floor respected.

## Method

Each slice was audited read-only by a dedicated sub-agent: (1) REST API + webhooks + SSRF
[Opus], (2) evidence log + crypto + PII + durable medium [Opus], (3) platform adapters +
consent + consumer flow [Sonnet], (4) admin + settings + XSS/CSRF [Sonnet], (5) wordpress.org
compliance + lifecycle [Sonnet].
