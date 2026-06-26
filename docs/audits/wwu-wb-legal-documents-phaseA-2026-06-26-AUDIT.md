# AUDIT — WWU Withdrawal Button: Legal Documents Phase A (delivery surfaces)

- **Slug:** `wwu-wb` · **Feature:** `legal-documents` (Phase A) · **Date:** 2026-06-26
- **Scope:** the Phase A code on `claude/legal-documents` (WIP commits `821b757…3f7cc5a`): `PolicyBuilder`, `PolicyDocument`, the `[wwu_wb_policy]` shortcode, page management + one-click recreate (form + policy), freeze-to-static-HTML, the Compliance "Informativa sul diritto di recesso" sub-section, the policy PDF surface, the `policy` smoke suite, theme-inheriting CSS, and the SPEC alignment.
- **Method:** **manual self-audit.** The multi-agent fan-out (Standard #13 default) was skipped because this workspace's `CLAUDE.md` reliably trips sub-agents into "Prompt is too long" — the documented fallback (`~/.claude/CLAUDE.md` §"Audit prima di dire fatto") is exactly *audit manuale documentato, non saltarlo*. A fuller adversarial sub-agent pass + a live REST smoke run + a browser visual check remain queued before the 1.3.0 release (see Residual).

## Security
- **All three new `admin-post` actions** gate on `current_user_can( Authentication::capability() )` **and** `check_admin_referer()`: `handle_recreate_page`, `handle_freeze_policy`, `handle_policy_pdf`.
- **Output escaping** — the Compliance sub-section escapes every dynamic value (`esc_html` / `esc_url`); the live preview passes through `wp_kses_post` (a defensive second pass over already-builder-escaped HTML). The PDF template echoes builder-escaped HTML with a documented `phpcs:ignore`; `site_name` / date are `esc_html`.
- **`handle_freeze_policy`** writes `post_content` via `wp_update_post`; for users without `unfiltered_html` (multisite non-super-admins) KSES runs — the assembled policy uses only standard tags (`div/h2/h3/section/p/ul/li/strong/em/span/br`), so nothing material is stripped.
- **The `[wwu_wb_policy]` shortcode is intentionally public** (a generic, non-sensitive notice, like the Terms page); attributes are sanitized (`sanitize_text_field` for `lang`, `sanitize_key` for `sections` inside `PolicyBuilder`).
- **Findings: 0.**

## Correctness / edge cases
- No policy page yet / old install missing the `policy_page_id` key → `?? 0` → the "Create" button shows.
- Dompdf absent → the "Download PDF" button is **hidden** (gated on `PdfBuilder::is_available()`) **and** `handle_policy_pdf` `wp_die`s with guidance; the page + shortcode still work.
- `ensure_policy_page()` is idempotent + self-healing (recreates on delete/trash; returns the existing id otherwise).
- Exceptions section present/absent tracks the configured exemptions (covered by the smoke suite).
- `get_edit_post_link()` may return null → falls back to `get_permalink()`.
- **Finding (FIXED):** the `policy` smoke suite mutated the real `wwu_wb_exclusions` option to exercise the exceptions branch without a guaranteed restore. Wrapped the mutation in `try/finally` so a thrown assertion / `build()` can never leak the test value into the merchant's settings; documented that the window is a single request (run smoke off-peak).

## Integration / contracts
- The assembler reads the **correct option name `wwu_wb_exclusions`** (the SPEC said `wwu_wb_exemptions` — corrected 4×).
- `policy_page_id` is seeded in `Install` and read consistently by the Dashboard (`Settings::main()`) and Compliance (`get_option`).
- `Template::render( 'pdf/policy-pdf.php', … )` matches the receipt-PDF rendering convention (same `templates/` root → `PdfBuilder::render()`).
- The global disclaimer has a **single source** (`PolicyBuilder::disclaimer_html()`) used by the shortcode, the freeze handler, the Compliance preview, and the PDF.
- `SUITES['policy'] => 'suite_policy'` registered; `AdminController` registers all three new actions.
- **Findings: 0.**

## Lint
- `php -l` clean across all touched files: `PolicyBuilder`, `PolicyDocument`, `Shortcodes`, `DashboardPage`, `AdminController`, `ComplianceStatusPage`, `SmokeTests`, `templates/pdf/policy-pdf.php`.

## Residual / deferred (before 1.3.0)
- Adversarial **sub-agent audit** (security + correctness/perf) once runnable, or a fresh-context manual pass.
- **Live REST smoke run** — `php wwu-tools/wwu-rest-test.php wwu-wb policy` on the test subsite (the suite is written but not yet executed against a live install).
- **Browser visual check** of the Compliance sub-section + the rendered policy page (Standard #14 affordance/visual gate).
- **Phase B** (Complianz injection) + **Phase C** (`wwu-tools/wwu-i18n.php` + IT/EN strings) still to implement; each gets its own audit.
- At release: bump to `1.3.0`, update `readme.txt` / `README.md` / CHANGELOG + the marketing pages (standing rule).
