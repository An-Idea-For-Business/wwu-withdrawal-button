# Swedish (sv_SE) localization — status + review note (alpha.40, 2026-06-15)

**Status: DRAFT — pending native-speaker + legal review.**

## What shipped in alpha.40
- **Statutory labels** (`src/Domain/LabelResolver.php`, `STATUTORY['sv']`), sourced from the official
  Swedish EUR-Lex text of **Art. 11a** (Dir. 2011/83/EU as amended by Dir. (EU) 2023/2673), transposed in
  **Distansavtalslagen (2005:59)**:
  - withdrawal-button label: **"ångra avtalet här"**
  - confirmation label: **"bekräfta frånträde"**
  - authority citation shown in admin: **"Distansavtalslagen (2005:59)"**
- `Countries::COUNTRY_LOCALE` maps **`SE → sv`** so a Swedish consumer gets the Swedish label even on a
  different-locale site (mirrors the DE/AT/FR/BE/LU/ES handling).
- **Partial UI translation**: `languages/wwu-withdrawal-button-sv_SE.po` + `.mo` — **147 of 476** UI strings
  translated; the remaining 326 fall back to English (WordPress default). 2 entries flagged `#, fuzzy`.

## Why partial
Two automated translation passes over the full 476-string catalogue failed to complete (the ~100 KB `.po`
overflowed the translator agent's context window). The 147 strings that landed were recovered via `msgmerge`
against the `.pot`. The statutory labels — the legally-critical part — are complete and verified against the
official source.

## What still needs doing (follow-up)
1. **Translate the remaining ~326 UI strings** in `sv_SE.po` (do it in batches, not one shot). Recompile `.mo`.
2. **Native + legal review by Daniel Andersson** (offered help on the FB launch post). Focus especially on:
   - the statutory button/confirm wording (is "ångra avtalet här" / "bekräfta frånträde" the exact phrasing
     a Swedish consumer + DCO/Konsumentverket would expect, vs "frånträd avtalet här"?);
   - the Annex I-B model withdrawal form text ("standardformulär för utövande av ångerrätten");
   - resolve the 2 `#, fuzzy` entries.
3. Once reviewed, drop the "draft" wording from the readme/changelog and the `DRAFT` comment in `LabelResolver`.

## Sources
- [Directive (EU) 2023/2673 — Swedish (EUR-Lex)](https://eur-lex.europa.eu/legal-content/SV/TXT/?uri=CELEX:32023L2673)
- [Sweden to Require Withdrawal Button — Bird & Bird](https://www.twobirds.com/en/insights/2026/sweden/sweden-to-require-withdrawal-button-for-online-shopping)
- [Directive 2011/83/EU — Swedish (EUR-Lex)](https://eur-lex.europa.eu/legal-content/SV/TXT/?uri=CELEX:32011L0083)
