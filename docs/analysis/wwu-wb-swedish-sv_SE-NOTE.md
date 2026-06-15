# Swedish (sv_SE) localization — status + review note (alpha.40, 2026-06-15)

**Status: UI 100% COMPLETE (495/495 strings, 0 fuzzy, 0 untranslated) — pending native-speaker + legal review by Daniel Andersson.**

## What shipped in alpha.40
- **Statutory labels** (`src/Domain/LabelResolver.php`, `STATUTORY['sv']`), sourced from the official
  Swedish EUR-Lex text of **Art. 11a** (Dir. 2011/83/EU as amended by Dir. (EU) 2023/2673), transposed in
  **Distansavtalslagen (2005:59)**:
  - withdrawal-button label: **"ångra avtalet här"**
  - confirmation label: **"bekräfta frånträde"**
  - authority citation shown in admin: **"Distansavtalslagen (2005:59)"**
- `Countries::COUNTRY_LOCALE` maps **`SE → sv`** so a Swedish consumer gets the Swedish label even on a
  different-locale site (mirrors the DE/AT/FR/BE/LU/ES handling).
- **Full UI translation**: `languages/wwu-withdrawal-button-sv_SE.po` + `.mo` — **all 495** UI strings
  translated (the first alpha.40 cut had only 147/476). 0 fuzzy, 0 untranslated.

## Completion (2026-06-15)
The first cut shipped only 147 strings — two automated passes over the ~100 KB `.po` overflowed the
translator agent's context window. The full catalogue is now complete: the remaining ~348 strings (the
alpha.38 Subscriptions section, the Art. 59 exemptions copy, the debug/inspector UI, consumer-facing flows)
were translated and injected in batches via `wwu-tools/wwu-po-fill.php` (extract untranslated → translate →
inject by exact msgid match — bypasses the agent context limit). The two former `#, fuzzy` guesses were
corrected, notably **"durable medium" → "varaktigt medium"** (the Distansavtalslagen legal term — the fuzzy
guess "hållbart medium" reads as *environmentally sustainable*, wrong register). The statutory labels — the
legally-critical part — were complete and source-verified from the start.

## What still needs doing (follow-up)
1. **Native + legal review by Daniel Andersson** (offered help on the FB launch post). Focus especially on:
   - the statutory button/confirm wording (is "ångra avtalet här" / "bekräfta frånträde" the exact phrasing
     a Swedish consumer + Konsumentverket would expect, vs "frånträd avtalet här"?);
   - the Annex I-B model withdrawal form text ("standardformulär för utövande av ångerrätten");
   - a spot-check of the long consumer-guidance + Art. 59 exemptions paragraphs for tone/register.
2. Once reviewed, drop the "draft" wording from the readme/changelog and the `DRAFT` comment in `LabelResolver`.

## Sources
- [Directive (EU) 2023/2673 — Swedish (EUR-Lex)](https://eur-lex.europa.eu/legal-content/SV/TXT/?uri=CELEX:32023L2673)
- [Sweden to Require Withdrawal Button — Bird & Bird](https://www.twobirds.com/en/insights/2026/sweden/sweden-to-require-withdrawal-button-for-online-shopping)
- [Directive 2011/83/EU — Swedish (EUR-Lex)](https://eur-lex.europa.eu/legal-content/SV/TXT/?uri=CELEX:32011L0083)
