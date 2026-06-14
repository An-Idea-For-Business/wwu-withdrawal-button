# Timestamp providers — what else we can plug in (RFC 3161 + eIDAS)

Analysis (2026-06-14) of the trusted-timestamping services the plugin could add to
its pluggable `TimestampProvider` interface, beyond the current OpenTimestamps.
The log already hash-chains every record; a timestamp authority adds independent
proof of *when* a record existed.

> Dates/endpoints/pricing are from web research — verify against the live EU
> Trusted List + each provider before shipping. Not legal advice.

## How it maps to our architecture

`src/Timestamp/` already has `TimestampProvider` (interface), `OpenTimestampsProvider`,
`NoneProvider`, and the `wwu_wb_timestamp_provider` filter. Adding any RFC 3161
authority is **one shared base class + a thin subclass** (override endpoint URL +
optional credentials) — not a class per country. So "qualified timestamp per
country" becomes a settings choice (endpoint + credentials), not new code each.

**Two different things:**
- **RFC 3161 (TSP)** — a Time-Stamp Authority signs your SHA-256 hash and returns a
  token *immediately*. Centralised trust (PKI chain). eIDAS-qualified if the TSA is
  on the EU Trusted List (Art. 42 → strongest legal presumption in EU courts).
- **OpenTimestamps (done)** — anchors the hash to Bitcoin; proof completes after a
  block (~hours). Decentralised, free, no authority to outlive. No explicit eIDAS
  status.
- **Optimal default = run both**: OTS (free, permanent backup) + a free RFC 3161
  token (immediate, court-ready). Zero cost, two independent proofs.

## Tier 1 — free RFC 3161 (no account)

| TSA | Endpoint | Auth | Trust tier | Use |
|---|---|---|---|---|
| **Sectigo** | `http://timestamp.sectigo.com` | none | Trusted (Adobe/MS) | general-purpose default |
| **Sectigo Qualified** ⭐ | `http://timestamp.sectigo.com/qualified` | none | **eIDAS Qualified (EU Trusted List)** | **free qualified token, no account** |
| DigiCert | `http://timestamp.digicert.com` | none | Trusted | alternative default |
| SwissSign | `http://tsa.swisssign.net` | none | Trusted (not ZertES-qualified) | CH-friendly, general |
| FreeTSA.org | `https://freetsa.org/tsr` | none | **Untrusted** | internal audit / fallback only |
| Certum public | `http://time.certum.pl` | none | Untrusted | low-stakes only |
| Apple | `http://timestamp.apple.com/ts01` | none | Untrusted | code-signing only — avoid |
| GlobalSign | (old public endpoint) | — | Trusted | now paid SaaS — avoid public endpoint |

**Headline finding:** **Sectigo `/qualified` gives a free, no-account, eIDAS-Art.42
qualified token** — the single best add-on for EU legal weight at zero cost/zero
friction. Sectigo advises ~15s between requests/IP (irrelevant: one stamp per
withdrawal). Spain's **Izenpe** (`http://tsa.izenpe.com`) is also listed as
Qualified with a possibly-public endpoint — verify current auth.

## Tier 2 — per-country eIDAS qualified (paid, account, RFC 3161)

Highest evidential value (national QTSP on the EU Trusted List). All speak RFC 3161
with Basic auth → same provider, different endpoint + credentials.

| Country | QTSP | Endpoint / access | Cost model |
|---|---|---|---|
| 🇮🇹 Italy | **Aruba** | `https://servizi.arubapec.it/tsa/ngrequest.php` (Basic auth) | bundles, ~€13.50/50 … €300/2000 |
| 🇮🇹 Italy | InfoCert | `https://digitaltimestamp.infocert.it/idts-rest/dts/timestamp` | ~€9.70/100, contract |
| 🇮🇹 Italy | Namirial, Actalis | endpoint after contract | bundle / enterprise |
| 🇩🇪 Germany | **D-Trust** (Bundesdruckerei) | contract endpoint; DCF77-synced | enterprise |
| 🇫🇷 France | **Universign**/Docaposte, ChamberSign | REST wrapping RFC 3161 | per-transaction |
| 🇪🇸 Spain | **FNMT** (national mint), Izenpe, Camerfirma | FNMT via registration; Izenpe possibly public | gov / commercial |
| 🇨🇭 Switzerland (ZertES) | **SwissSign** (qualified endpoint), **QuoVadis CH** `http://ts.quovadisglobal.com/ch` | contract | commercial |

## PHP integration

Two routes:
- **`openssl ts` CLI** (simplest): `openssl ts -query -sha256 -digest <hex> -cert -out req.tsq` → POST `application/timestamp-query` via cURL (+ Basic auth for QTSPs) → store the DER `TimeStampResp` (base64) → `openssl ts -verify` to check. Needs `exec()` + openssl (often disabled on shared hosting).
- **Pure PHP** (`hablutzel1/phpcmstimestamper`, phpseclib, MIT): builds the ASN.1/DER with no system binary — portable, but adds a Composer dep (we already bundle vendor/ for Dompdf, so feasible).

Store per stamp: `provider`, `hash_algo`, `hash_hex`, `token_b64` (DER token or `.ots`), `timestamp_utc`, `tsa_cert_serial`. Our `TimestampRepository` already stores a proof blob + provider key — extend its columns/usage.

## Recommendation

1. **Implement one `Rfc3161Provider` abstract base** (shared `stamp()`/`verify()` via openssl, pure-PHP fallback) + thin subclasses.
2. Ship two zero-config free options: **Sectigo standard** (Trusted) and **Sectigo `/qualified`** (free eIDAS-qualified) — selectable in Settings next to OpenTimestamps/None.
3. Add a generic **"Custom RFC 3161 (endpoint + credentials)"** option so any QTSP (Aruba, InfoCert, D-Trust, Universign, FNMT, SwissSign…) works by config — covers every per-country qualified provider without bespoke code.
4. Keep OpenTimestamps as the default; allow running OTS **and** an RFC 3161 stamp together for belt-and-braces evidence.

## Sources

EU Trusted List / eIDAS Art. 42; community TSA list (Manouchehri gist); Sectigo,
DigiCert, FreeTSA, SwissSign docs; Aruba/InfoCert/Namirial (IT); D-Trust (DE);
Universign/ChamberSign (FR); FNMT/Izenpe/Camerfirma (ES); SwissSign/QuoVadis (CH,
ZertES); PHP RFC 3161 references (d-mueller.de, Tractis, hablutzel1/phpcmstimestamper).
URLs in the research workflow output.
