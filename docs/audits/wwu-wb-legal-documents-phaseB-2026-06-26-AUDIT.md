# AUDIT — WWU Withdrawal Button: Legal Documents Phase B (Complianz injection)

- **Slug:** `wwu-wb` · **Feature:** `legal-documents` (Phase B) · **Date:** 2026-06-26
- **Scope:** `src/Compat/ComplianzDocuments.php` (new) + its wiring: `Plugin.php` registration, `Install` seed (`complianz_inject_privacy/terms`), `SettingsPage` ("Complianz documents" section + save + `flush()`), the `complianz_docs` smoke suite.
- **Method:** manual self-audit (same documented fallback as Phase A — the workspace `CLAUDE.md` trips sub-agents into "Prompt too long"). Grounded in the official-source recon (`docs/analysis/…-complianz-i18n-law-recon-2026-06-19-ANALYSIS.md`) for the `cmplz_document_elements` contract.

## Security
- The two toggles are written through the **existing** settings form (`SettingsPage::handle_save`), which already gates on the settings nonce + `Authentication::capability()`. The new fields go through `Sanitizer::bool`.
- `inject()` only ever adds our own `__()` text or the merchant's own clause override (capability-gated at save) — no third-party raw HTML. Complianz then runs its own `wp_kses( cmplz_allowed_html() )` over `content`. Our text is plain.
- The admin preview escapes every value (`esc_html` for title/content; `wp_kses_post` for the one hardcoded companion link).
- **EU-region gate** (`'eu'` only, filterable) prevents legally-incorrect injection into US/CA/AU documents.
- **Findings: 0.**

## Correctness / edge cases
- Complianz inactive → the whole Settings section is hidden (`is_complianz_active()`), and the `cmplz_document_elements` filter never fires.
- Terms companion (`complianz-terms-conditions`) missing → the Terms toggle is **disabled** with an install notice; a previously-saved opt-in is preserved via a hidden input (a disabled checkbox posts nothing). Harmless no-op while the companion is gone; resumes on reinstall.
- Toggle off → nothing injected (smoke `complianz.inject.privacy_off` / `terms_off`).
- `flush()` is a no-op when `cmplz_flush_documents()` is absent.
- Element keys are all `wwu_wb_`-prefixed (smoke `complianz.keys.prefixed`) — no collision with Complianz's own keys.
- The admin **preview reuses the same `append_*()` builders** as the live injection, so what the merchant previews is exactly what gets added (no drift).
- **Findings: 0** (no code fix needed; the smoke suite restores the mutated `wwu_wb_settings` in a `finally`).

## Live-test verification points (not blocking)
1. **Complianz detection** — `is_complianz_active()` uses `defined('cmplz_version') || function_exists('cmplz_get_value')`. Confirm on the test subsite that the "Complianz documents" section appears when Complianz is active.
2. **Companion file path** — `terms_companion_active()` checks `is_plugin_active('complianz-terms-conditions/complianz-terms-conditions.php')`. Confirm the companion's real main-file path matches (else the Terms toggle stays disabled even when installed). Filterable via `wwu_wb_complianz_terms_companion_active`.
3. **Live render** — with a toggle on, open the Complianz Privacy Policy (and Terms, with the companion) and confirm the clauses appear, correctly numbered, and that toggling off removes them after `cmplz_flush_documents()`.

## Lint
- `php -l` clean: `ComplianzDocuments.php`, `Plugin.php`, `Install.php`, `SettingsPage.php`, `SmokeTests.php`.

## Residual / deferred (before 1.3.0)
- Run the live verification points above on the subsite with `complianz-gdpr-premium` (+ the Terms companion).
- Live REST smoke — `php wwu-tools/wwu-rest-test.php wwu-wb complianz_docs`.
- Phase C (`wwu-tools/wwu-i18n.php` + IT/EN strings) next; then the 1.3.0 release (readme/README/CHANGELOG + marketing pages).
