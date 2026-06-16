# Localization workflow — Crowdin (TMS)

> Professional translation + native review pipeline for WWU Withdrawal Button.
> Replaces the ad-hoc "translate by hand + CSV proofread" flow.

## Why a TMS

The plugin ships **6 locales** (IT/EN/DE/FR/ES/SV) and adds strings every release.
A Translation Management System gives what a CSV or a raw `.po` cannot: a web editor
with **translation memory**, a **glossary/termbase**, **QA checks** (placeholder
mismatch, length, punctuation), and a **review/approval workflow** (translated →
proofread → approved) — with everything syncing back to the repo as a pull request.

Native speakers (e.g. Daniel for Swedish) review **in Crowdin's UI**, not in a file.

## Integration choice — native GitHub App (default)

This repo's push credential (the `gh` OAuth token used via Git Credential Manager)
does **not** carry the `workflow` scope, so GitHub rejects any push that creates or
updates files under `.github/workflows/` (empirically confirmed). `.github/workflows/`
is therefore kept in `.git/info/exclude`.

Because of that, the pipeline uses **Crowdin's native GitHub App** rather than a
GitHub Action: the App authorizes itself on the repository (its own permissions,
independent of the `gh` token) and needs **no workflow files**. It reads `crowdin.yml`
and opens pull requests with reviewed translations.

> A GitHub Actions variant (fully automated, incl. `.mo` compilation in CI) is
> documented at the end — it only needs the one-time `gh auth refresh … -s workflow`.

## How it works (native App)

```
 source strings change (PHP)
        │  (locally: regenerate .pot, commit, push to main)
        ▼
 languages/wwu-withdrawal-button.pot  ──►  Crowdin GitHub App  ──►  Crowdin project
                                                                      translate · review
 languages/*.po  ◄── pull request ◄────────  Crowdin GitHub App  ◄──  approve
        │
        └─►  compile .mo locally (msgfmt) ──►  merge
```

- The App watches the repo's `.pot` (source) and the per-locale `.po` (translations)
  defined in `crowdin.yml`, and round-trips them through Crowdin.
- The repo's `.pot` is the source of truth; regenerate it locally before committing
  (WP-CLI `wp i18n make-pot .` — canonical — or the convenience `wwu-tools/wwu-generate-pot.php`).
- **`.mo` binaries:** Crowdin syncs `.po` only. When a Crowdin translation PR lands new
  `.po`, recompile the `.mo` (`msgfmt`) before/after merge. (Future nicety: add an
  `msgfmt` step to `bin/build.*` so the packaged ZIP always carries fresh `.mo`.)

## One-time setup (human steps)

1. **Create the Crowdin project** (Open Source plan, free for public repos): visibility
   **Public**, source language **English**, target languages **Italian, German, French,
   Spanish, Swedish**, project type **File-based**. (You are here.)

2. **Land `crowdin.yml` on `main`** — merge the PR that adds it (this doc's companion).
   The GitHub App reads it to map the source `.pot` → per-locale `.po`.

3. **Connect the repository:** in the Crowdin project → **Integrations → GitHub** →
   install/authorize the **Crowdin GitHub App** on `An-Idea-For-Business/wwu-withdrawal-button`,
   select branch **main**. The first sync imports the source strings **and your existing
   translations** from the repo's `.po` files, so reviewers *review* rather than
   retranslate the 538 existing strings. (If the existing translations don't import,
   upload them once from the Crowdin UI or via the CLI — see below.)

4. **Invite the reviewer:** project → **Members** → invite the native speaker (Daniel)
   with the **Proofreader** role for **Swedish** (edit + approve). They work in the web UI
   — no files, no tooling.

5. *(Optional)* add a **glossary** (withdrawal, right of withdrawal, durable medium,
   consumer, product names) and enable the **QA checks** so placeholder/markup mistakes
   are caught automatically.

### Optional CLI seeding (only if step 3 didn't import translations)

Needs a Crowdin **Personal Access Token** (Account Settings → API, scoped to *Projects*):

```bash
export CROWDIN_PROJECT_ID=...        # project id
export CROWDIN_PERSONAL_TOKEN=...    # personal access token
cd wwu-withdrawal-button
npx @crowdin/cli@latest upload sources
npx @crowdin/cli@latest upload translations   # seeds existing IT/DE/FR/ES/SV
```

## Ongoing flow

- You change strings → regenerate `.pot` locally → push to `main`. The App updates the
  source in Crowdin; new strings appear for translators.
- Translators/reviewers work in Crowdin.
- The App opens a **translations PR** to the repo with the reviewed `.po`. Recompile the
  `.mo` (`msgfmt`), review the diff, merge to ship.

## Files in this repo

| File | Purpose |
|---|---|
| `crowdin.yml` | Maps the source `.pot` → per-locale `.po` (`%locale_with_underscore%` = `it_IT`, `sv_SE`, …). No credentials. Excluded from the dist ZIP. |

## Notes & gotchas

- **Verify the locale mapping** after the first sync: Crowdin "Spanish" must map to `es-ES`
  (→ `es_ES.po`) and "Swedish" to `sv-SE` (→ `sv_SE.po`); adjust under project *Language
  Mapping* if a filename comes out as `es.po`/`sv.po`.
- **`make-pot` excludes** `vendor,node_modules,tests,bin,_internal,assets/ui-kit` so the
  bundled UI Kit's strings and the Dompdf vendor don't pollute the catalog.
- **Future:** once on **wordpress.org**, translations can also go through
  **translate.wordpress.org** (GlotPress), where a native reviewer becomes the official
  Swedish **PTE**. The `.po`/`.pot` in `languages/` remain the single source either way.

## Alternative — GitHub Actions (full automation)

If you prefer CI-driven sync (auto `make-pot`, auto `.mo` compilation, scheduled PRs),
grant the push credential the `workflow` scope **once**:

```bash
gh auth refresh -h github.com -s workflow   # browser click; still direct gh auth, no PAT
```

…then remove `.github/workflows/` from `.git/info/exclude` and ask for the two workflow
files (`crowdin-upload.yml` make-pot + upload; `crowdin-download.yml` download + `msgfmt`
+ PR). They are guarded by `if: env.CROWDIN_PROJECT_ID != ''`, so they stay inert until
the `CROWDIN_PROJECT_ID` / `CROWDIN_PERSONAL_TOKEN` repo secrets are set.
