# Contributing to WWU Withdrawal Button

Thank you for helping build a public-interest compliance tool. This guide gets you productive fast and keeps the codebase consistent.

## Ground rules

- **Code, comments and documentation are in English.** User-facing strings are i18n (`__()`, `_e()`, …) and translated per locale.
- **No legal advice in code or docs** — cite primary sources ([`docs/legal/`](docs/legal/)) and let counsel decide. Statutory button labels are locked to the official wording; changing them needs a sourced justification.
- **GPL-3.0-or-later.** By contributing you agree your contribution is licensed under the same terms.

## Project layout

```
wwu-withdrawal-button.php   Bootstrap (constants, autoloader, boot)
src/                        PSR-4  WebWakeUpWdb\WithdrawalButton\*
  Core/  Platform/  Domain/  Storage/  Timestamp/  DurableMedium/
  Mail/  Frontend/  Shortcodes/  Legal/  Compat/  Admin/  Debug/  REST/  I18n/
assets/                     admin + frontend CSS/JS, ui-kit/ (bundled)
templates/                  overridable view templates
languages/                  .pot + .po/.mo
docs/                       SPEC, legal reference, compliance matrix, plan, changelog
tests/                      manual test plans + (where feasible) PHPUnit
```

## Conventions (must follow)

- **Namespace** `WebWakeUpWdb\WithdrawalButton\*` · **constants** `WEBWAKEUPWDB_*` · **options** `webwakeupwdb_*` · **meta** `_webwakeupwdb_*` · **REST** `webwakeupwdb/v1` · **hooks** `webwakeupwdb_*` · **CSS** `.wwu-wb-*` · **text domain** `wwu-withdrawal-button`.
- **Files < ~1500 lines**, single responsibility, PHPDoc on every class/method/property, JSDoc on every JS function.
- **Security first:** sanitise all input, escape all output, capability checks, nonces on admin/AJAX. REST permission callbacks **must not** re-verify the WP nonce (WP REST does it before the callback).
- **HPOS-safe:** read/write orders only via the platform adapter / `wc_get_order()` — never `get_post()` / `get_post_meta()` on orders.
- **The immutable log is append-only:** never add an `UPDATE`/`DELETE` path against `webwakeupwdb_log`; never add `updated_at`/`deleted_at`.
- **Debug-first (Standard #11):** new features extend the debug Collector (new channels/entries) and add a smoke-test suite.

### ⚠️ The PHP close-tag trap (read this)

Never put a literal `?>` inside a `//` single-line comment, and never put a literal `*/` inside a `/* */` block comment — both terminate the surrounding context at parse time and cause baffling, far-away failures. When you must document such delimiters, use prose ("the PHP close marker") or omit the trailing `?>` from view templates entirely. A pre-commit grep helps:

```bash
git diff --cached -- '*.php' | grep -nP '^\+.*//.*\?>' && echo "↑ remove the literal close-tag from the // comment"
```

## Local development

```bash
composer install            # builds vendor/ (Dompdf)
php -l <file>               # lint every PHP file you touch
node --check <file>         # lint every JS file you touch
```

### Running the smoke tests

The plugin exposes the canonical WWU debug endpoints. With the workspace `wwu-tools` runner:

```bash
php wwu-tools/wwu-rest-test.php wwu-wb            # all suites
php wwu-tools/wwu-rest-test.php wwu-wb foundation # one suite
```

Enable debug first under **Withdrawal Button → Settings → Debug** (audience), then authenticate the CLI runner with a WordPress Application Password. The expected report shape is `{ summary:{pass,fail,skip,total}, suites:[{name,tests:[{name,status,output}]}] }`.

## Workflow

1. Open (or comment on) an issue describing the change. `good first issue` items are a great start.
2. Branch from `main`: `feat/…`, `fix/…`, `docs/…`.
3. Keep PRs focused. Update `docs/changelog/wwu-wb-CHANGELOG.md`. If you change behaviour, extend the relevant smoke suite and the [compliance matrix](docs/legal/wwu-wb-compliance-matrix.md).
4. Ensure `php -l` and `node --check` are clean and (if configured) `composer lint` (WordPress Coding Standards) passes.
5. Open a PR using the template; describe what legal requirement / issue it addresses.

## Reporting bugs / requesting features

Use the issue templates. For anything security-sensitive, **do not** open a public issue — follow [SECURITY.md](SECURITY.md).

## Translations

Add or improve a locale by editing `languages/<text-domain>-<locale>.po` and compiling the `.mo`. **Do not machine-translate the statutory button labels** — they must match the official national wording (see [legal reference §4](docs/legal/wwu-wb-legal-reference.md)).
