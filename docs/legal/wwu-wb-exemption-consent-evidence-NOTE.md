# Legal note — Evidence & record-keeping for the Art. 59 exemption consents

> **Scope.** What the law requires for *recording/proving* the consumer's consent +
> acknowledgement that unlock the two **conditional** withdrawal-right exemptions
> (digital immediate access — Art. 59(1)(o) CdC / Art. 16(1)(m) CRD; service fully
> performed — Art. 59(1)(a) CdC / Art. 16(1)(a) CRD): is a *register* required, what is
> the role of the *durable-medium confirmation*, how long to *retain*, and the *GDPR* basis.
>
> **Sources:** EUR-Lex consolidated Dir. 2011/83/EU (`CELEX:02011L0083-20220528`),
> Gazzetta Ufficiale + Normattiva for D.Lgs. 206/2005 (Codice del Consumo) and the Codice
> civile, Garante/EDPB for GDPR. Verified by a multi-agent official-source sweep +
> adversarial cross-check (2026-06-14). **This is a compliance starting point, NOT legal
> advice** — final plugin-facing legal copy + the LIA + privacy-notice text must be
> validated by a qualified Italian lawyer, and the in-force *multivigente* CdC wording
> re-pulled from Normattiva (note the **D.Lgs. 26/2023** Omnibus amendment to Art. 59(1)(a)).

## 1. Is a "register of consents" legally required? — **No (not by name).**

There is **no statute that names a "registro dei consensi"** for these exemptions. The duty
to *keep the proof* is **burden-of-proof-driven**, not register-driven:

- **Art. 6(9) CRD** — *"the burden of proof concerning the provision of the information …
  shall be on the trader."* Combined with the general principle that **whoever invokes an
  exception must prove its constitutive facts** (the exemption = loss of the consumer's
  withdrawal right → the trader invokes it → the trader must prove consent + acknowledgement
  were given).
- **Art. 5(2) GDPR (accountability)** — the controller must be *able to demonstrate*
  compliance.

**Positioning rule for the plugin:** market the feature as *"evidence to discharge the
trader's burden of proof (Art. 6(9) CRD / Codice del Consumo) and GDPR accountability (Art.
5(2))"* — **never** as a "registro obbligatorio / legally-mandated register." Over-claiming
the law is itself a defect.

## 2. Durable-medium confirmation — **constitutive for the DIGITAL exemption.**

For **digital content on a non-tangible medium** the durable-medium confirmation is a
**hard, constitutive** condition, not best practice:

- **Art. 16(1)(m) CRD = Art. 59(1)(o) CdC** — the exemption requires **three cumulative**
  elements: (i) prior express consent to begin during the withdrawal period, (ii)
  acknowledgement of losing the right, **(iii) the trader has provided confirmation per Art.
  7(2)/8(7) CRD = Art. 50(2)/51(7) CdC** (the durable-medium confirmation). The Italian text
  is explicit: *"… e il professionista abbia fornito la conferma conformemente all'articolo
  50, comma 2, o all'articolo 51, comma 7."*
- **Art. 14(4)(b)(iii) CRD (negative mirror)** — if confirmation was **not** provided, the
  consumer **does not lose** the right and bears no cost → **missing confirmation VOIDS the
  digital exemption**, even with a perfect checkout checkbox.
- **Art. 8(7) CRD = Art. 51(7) CdC** — the confirmation must be on a durable medium, **at the
  latest before performance begins**, and must **include** the consumer's prior express
  consent + acknowledgement. **Art. 2(7) CRD** + recital 23: **e-mail qualifies** as a
  durable medium.

**Asymmetry to preserve — the SERVICE exemption is different.** Art. 16(1)(a) / Art. 59(1)(a)
condition the exemption on prior express consent + acknowledgement **only**; the text does
not itself cross-reference Art. 8(7). So a missing durable-medium confirmation is **not
unambiguously fatal** to the service exemption (the confirmation duty still applies
independently under Art. 51(7); Art. 7(3)/8(8) require the consumer's *request* to begin
early to be on a durable medium). Do **not** let plugin copy claim it voids the service
exemption.

**Two distinct legal acts — the gap to close.** The SHA-256 + timestamp + IP log proves *what
wording was shown and accepted*. It does **not** prove the **delivery** of the durable-medium
confirmation. The plugin must **separately emit and log the dispatch** of the confirmation
e-mail (recipient + send timestamp + message-id), *before performance begins*, reproducing
the **verbatim** consent + acknowledgement — for digital this closes a real hole in the
exemption defence; for services it is an independent duty + strong best practice.

## 3. Retention — **10 years from contract conclusion as a defensible, configurable maximum.**

| Layer | Period | Basis | Status |
|---|---|---|---|
| Withdrawal window (evidentiary floor) | 14 days → **12 months + 14 days** if info omitted | Art. 52 + 53 CdC (Art. 9–10 CRD) | record must stay live ≥ ~12.5 months |
| Ordinary contractual prescription (ceiling) | **10 years** | **Art. 2946 c.c.** (from Art. 2935) | the real driver |
| Accounting-records duty (congruence) | 10 years | Art. 2220 c.c. | makes 10y congruent, not excessive |

- **10 years from contract conclusion** is a **reasoned, prescription-aligned default** — it
  is **not** a figure any consumer/privacy statute names for *this* record.
- **GDPR storage limitation (Art. 5(1)(e) + recital 39)** rewards the **shortest justifiable**
  period — a documented, risk-assessed shorter term (e.g. 24–36 months) is more proportionate
  for pure exemption-defence but leaves a gap against late contractual claims. A genuine
  balancing call.
- **Design requirements:** (a) make retention **configurable** (default 10y, allow shorter
  with a forced justification); (b) an append-only / hash-chained / OpenTimestamps log **still
  needs a deletion-or-anonymisation routine** at horizon end — *"immutable forever"* is itself
  a storage-limitation defect; (c) **never describe the log as "permanent/forever"** in the
  privacy notice — describe it as *"conservato per il periodo di prescrizione (es. 10 anni),
  poi cancellato/anonimizzato"*; (d) in a **live dispute**, retain beyond the horizon until
  resolved (Art. 17(3)(e) / prescription interruption).

## 4. GDPR — lawful basis = **legitimate interest (Art. 6(1)(f))**, not consent.

- The stored **IP is personal data** (CJEU C-582/14 *Breyer*) → the whole record falls under
  GDPR.
- **Primary lawful basis = Art. 6(1)(f) legitimate interest** — proving the exemption +
  defending legal claims (EDPB Guidelines 1/2024 list "establishment, exercise or defence of
  legal claims"). Requires a **documented LIA** (purpose / strict-necessity / balancing).
  **Art. 17(3)(e)** lets the trader lawfully **refuse an erasure request** on the record while
  a claim can still be brought.
- **Do NOT** use **Art. 6(1)(a) consent** as the basis (the checkout acknowledgement is
  *consumer-law* consent, not GDPR processing consent — basing retention on it would let the
  data subject withdraw it and defeat the evidence). **Do NOT** use **Art. 6(1)(c)** as
  *primary* (no statute mandates *this specific log*; 6(1)(c) needs a precise compelling norm).
- **Privacy notice (Art. 13)** must state: purpose + legal basis; the specific legitimate
  interest; the retention period or its criterion; the **Art. 21 right to object**; and the
  Art. 17 limit. Add the processing to the **Art. 30 RoPA**, apply **Art. 32** security,
  **minimise** fields. The **IP is the most exposed field** under the strict-necessity prong —
  make IP capture **configurable / justified in the LIA** (the wording+hash+timestamp may be
  argued to suffice without it).

## 5. What is BEST PRACTICE (not a legal mandate)

The hash-chain + OpenTimestamps anchoring **strengthens** Art. 5(2) demonstrability and
evidentiary weight, but **no cited article requires** tamper-evident anchoring. Do not market
it as legally required.

## 6. Implications for the plugin (today vs gaps)

- ✅ **Already done (1.0.0-alpha.28):** capture express consent + acknowledgement at the
  WooCommerce checkout; store **verbatim wording + SHA-256 + timestamp + IP** on the order;
  append a hash-chained, OpenTimestamps-anchored immutable-log event (`exemption_consent`).
  This is exactly the accountability/burden-of-proof artefact §1 + §4 describe.
- ⬜ **Gap A — durable-medium confirmation (digital = constitutive):** emit + **log the
  dispatch** of a durable-medium confirmation e-mail *before performance begins*, reproducing
  the verbatim consent + acknowledgement. (Tracked: exemptions **P3** / task #43.)
- ⬜ **Gap B — retention + purge:** configurable retention (default 10y) + a deletion/
  anonymisation routine at horizon end; stop describing the log as permanent.
- ⬜ **Gap C — GDPR clause:** the `ClauseLibrary` privacy clause must cover this processing
  (purpose, Art. 6(1)(f) interest, retention, Art. 21/17 limits); make IP capture configurable.
- ⬜ **Optional — admin "consent records" view:** a filterable/exportable list (order, reason,
  date, hash, IP) on top of the immutable log, for the merchant to produce evidence on demand.

## 7. Caveats

- **Not legal advice.** A qualified Italian lawyer should validate the final copy + the LIA +
  privacy-notice text.
- **Re-verify the in-force Italian text** of Art. 59 / 51 / 49 / 53 CdC on Normattiva
  (*multivigente*), noting **D.Lgs. 26/2023** added the *"obbligo di pagare"* / paid-contract
  conditioning to Art. 59(1)(a). Use the version in force at the transaction date.
- **Article-number drift** between original and consolidated CRD numbering is harmless; pin the
  consolidated CELEX `02011L0083-20220528` when citing.

## References

- Dir. 2011/83/EU (consolidated): Art. 6(9), 7(2)/(3), 8(7)/(8), 9(1), 10(1), 14(4)(b), 16(1)(a),
  16(1)(m), 2(7) — <https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:02011L0083-20220528>
- D.Lgs. 206/2005 (Codice del Consumo): Art. 49, 51 c.7, 52, 53, 59(1)(a), 59(1)(o) —
  <https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:decreto.legislativo:2005-09-06;206>
- Codice civile: Art. 2935, 2946, 2220 (+ 2948) — Normattiva R.D. 262/1942.
- GDPR (Reg. (EU) 2016/679): Art. 5(1)(e), 5(2), 6(1)(f), 13, 17(3)(e), 21, 30, 32; EDPB
  Guidelines 1/2024 on Art. 6(1)(f); Garante (accountability, storage limitation, IP as personal
  data); CJEU C-582/14 *Breyer*.
- Project: [exemptions SPEC](../specs/wwu-wb-withdrawal-exemptions-SPEC.md) · [legal reference](wwu-wb-legal-reference.md) · [compliance matrix](wwu-wb-compliance-matrix.md).
