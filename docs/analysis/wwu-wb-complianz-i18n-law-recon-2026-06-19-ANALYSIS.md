# WWU Withdrawal Button — Recon Report: Complianz Integration + EU Law + i18n Tooling

**Date:** 2026-06-19  
**Author:** Research sub-agents (synthesised by Claude Code)  
**Purpose:** Ground a future SPEC for Complianz `cmplz_document_elements` integration, confirm EU/Italian law checklist, and validate the i18n toolchain.  
**Status:** Reference document — no plugin code changed.

---

## Contradiction flags (checked against three stated assumptions)

| # | Assumption | Result |
|---|---|---|
| C1 | Complianz Premium generates a `terms-conditions` document natively | **CONTRADICTED** — `terms-conditions` belongs to the separate `complianz-terms-conditions` companion plugin. Without it the type does not exist. A standalone plugin must register the type manually via `cmplz_pages_load_types`. See §1.4. |
| C2 | Art. 11a withdrawal-button obligation applies from 19 June 2026 | **CONFIRMED** — Dir. (EU) 2023/2673, Art. 2(1); Italy D.Lgs. 209/2025, Art. 54-bis. Today (2026-06-19) is the live date. |
| C3 | `wp i18n make-mo` compiles .po→.mo without a WP install | **CONFIRMED** — all five `wp i18n` subcommands hook on `before_wp_load` and are fully standalone. |

---

## Section 1 — Complianz `cmplz_document_elements` filter

### 1.1 Where the filter fires

File: `complianz-gdpr-premium/config/class-config.php`, method `load_documents()` (around line 634).

```php
// Complianz internal (do not copy):
$elements = apply_filters( 'cmplz_document_elements', $elements, $region, $type, $fields );
```

The filter fires once per document render, after Complianz has assembled its own default elements for that `$region`/`$type` pair.

**Signature your hook must match:**

```php
add_filter( 'cmplz_document_elements', 'your_callback', 20, 4 );

function your_callback(
    array  $elements,   // associative: key => element-definition array
    string $region,     // 'eu' | 'uk' | 'us' | 'ca' | 'au' | 'za' | 'br'
    string $type,       // document slug: 'privacy-policy' | 'cookie-policy' | 'terms-conditions' | ...
    array  $fields      // wizard field values for this region
): array {
    // ...
    return $elements;
}
```

Priority 20 is recommended so you run after Complianz's own priority-10 internal adjustments.

### 1.2 Element definition schema (all keys)

Each entry in `$elements` is a string key mapped to an associative array. All keys are optional unless marked required.

#### Content keys

| Key | Type | Notes |
|-----|------|-------|
| `title` | `string` | Renders as `<h2>` with auto paragraph number. |
| `subtitle` | `string` | Renders as `<p class="cmplz-subtitle">` with sub-paragraph number N.M. |
| `content` | `string` | Body text. Supports `[fieldname]` placeholder substitution. HTML via `wp_kses( cmplz_allowed_html() )`. |
| `p` | `bool` (default `true`) | When `false`, `content` is emitted raw without `<p>` wrap. |
| `class` | `string` | CSS class on the `<p>` wrapper. Ignored when `p === false`. |
| `annex` | `string` | Renders as `<h2 class="annex">` for lettered annex sections (e.g. "Annex I-A"). |
| `list` | `bool` | When `true`, emits the element as a list item inside the surrounding container. |

#### Numbering

| Key | Type | Notes |
|-----|------|-------|
| `numbering` | `bool` (default `true`) | When `false`, suppresses the paragraph-number prefix but does NOT reset the counter. |

#### Dropdown (collapsible `<details>`)

Three keys work together. The open and close elements **must share the same `condition`** (Complianz pairs them by condition match during rendering).

| Key | Type | Notes |
|-----|------|-------|
| `dropdown-open` | `bool` | Opens `<details class="cmplz-dropdown {class}"><summary>`. |
| `dropdown-title` | `string` | The visible heading inside `<summary>`. |
| `dropdown-class` | `string` | CSS class on `<details>`. |
| `dropdown-close` | `bool` | In a **separate** element, closes `</details>`. Must share the same `condition` as the open element. |

#### Condition / callback

| Key | Type | Notes |
|-----|------|-------|
| `condition` | `array` | Associative: `['field_name' => 'expected_value']`. AND logic across entries. Supports `'NOT '` prefix on values and `'EMPTY'` sentinel. The special value `'loop'` iterates multi-value wizard fields. |
| `callback_condition` | `string\|string[]` | Named function(s) returning truthy. Supports `'NOT functionname'` for negation. **Closures are not supported** — must be named functions. |
| `callback` | `string` | Named PHP function called at render time to generate/modify content dynamically. |

### 1.3 Document regeneration and caching

- Documents are regenerated on **wizard save** and on any `update_option` that Complianz watches. There is no separate regeneration trigger — updating the wizard re-runs `load_documents()` and re-applies all filters.
- The generated HTML is stored in a transient/option. If your filter logic depends on external state (e.g. a plugin option), you may need to call `cmplz_flush_documents()` after saving that option to force regeneration.
- Third-party elements added via the filter are included in every regeneration automatically — no separate registration step is needed as long as the filter is active.

### 1.4 The `terms-conditions` contradiction

**`terms-conditions` is NOT a native Complianz Premium document type.** It is registered by the separate `complianz-terms-conditions` plugin (slug `complianz-terms-conditions` on wordpress.org). Without that plugin, the filter fires only for `privacy-policy`, `cookie-policy`, and a few others.

**Two integration options for our plugin:**

**Option A — Require the companion plugin (recommended for production):**  
Add `Requires Plugins: complianz-terms-conditions` to the plugin header and check `is_plugin_active('complianz-terms-conditions/complianz-terms-conditions.php')` before hooking.

**Option B — Register the type manually (standalone, no dependency):**

```php
add_filter( 'cmplz_pages_load_types', function( array $pages ): array {
    foreach ( [ 'eu', 'uk', 'us', 'ca', 'au', 'za', 'br' ] as $region ) {
        if ( ! isset( $pages[ $region ]['terms-conditions'] ) ) {
            $pages[ $region ]['terms-conditions'] = [
                'title'             => __( 'Terms & Conditions', 'wwu-withdrawal-button' ),
                'public'            => true,
                'document_elements' => [],   // MUST be array, not ''
                'condition'         => [ 'terms-conditions' => 'generated' ],
            ];
        }
    }
    return $pages;
}, 5 );
```

Critical: `document_elements` **must be an empty array `[]`, not an empty string `''`**. An empty string silently prevents the `cmplz_document_elements` filter from ever running for that type.

### 1.5 Known gotchas

1. **EU-only guard is important:** Our withdrawal-button clauses apply only when `$region === 'eu'` (and UK post-Brexit). Adding them to US/CA/AU documents would be legally incorrect and confusing.
2. **Element key collisions:** The associative key is global within the element array. Always prefix with your plugin slug: `wwu_wb_withdrawal_title`, `wwu_wb_withdrawal_14days`, etc.
3. **Insertion order matters for paragraph numbering:** The auto-incrementing paragraph counter reflects array order. Use `array_slice` + `+` operator to insert after a specific key rather than `array_push` (which always appends).
4. **`dropdown-close` must share `condition` with `dropdown-open`:** If the condition differs, the close element may be omitted while the open element renders, producing unclosed `<details>` HTML.
5. **`callback_condition` closures are not supported:** Only named (global or static-method) functions work. PHP closures will cause a fatal when Complianz tries to call `is_callable()` + invoke.
6. **`content` goes through `wp_kses( cmplz_allowed_html() )`:** Only a subset of HTML is preserved. Raw `<table>`, `<form>`, `<input>` etc. are stripped. If rich HTML is needed, use the `callback` key to return pre-escaped content that bypasses kses.
7. **`numbering => false` does not reset the counter:** If you need an unnumbered section followed by a resuming numbered one, set `numbering => false` on the unnumbered element and leave `numbering => true` (default) on the next one — the counter simply did not increment, so the sequence is preserved.
8. **`p => false` and `class` together:** `class` is ignored when `p === false` (no `<p>` tag to attach the class to). Use the `callback` approach for class-controlled raw output.
9. **`cmplz_pages_load_types` priority:** Must be ≤ 5 so it fires before Complianz's internal load at priority 10.
10. **Caching race:** If the merchant saves your plugin settings and then immediately previews the Complianz document, they may see stale content. Call `cmplz_flush_documents()` after saving your option.
11. **Multisite:** Each subsite has its own wizard state and its own documents. The filter fires per-site with that site's `$fields`. No global/network-wide document state.

### 1.6 Complete PHP code example — adding withdrawal clauses to `terms-conditions`

```php
<?php
/**
 * Complianz integration — adds withdrawal-right clauses to the
 * Terms & Conditions document for EU/UK regions.
 *
 * Hook priority 20 runs after Complianz's own priority-10 adjustments.
 *
 * @param array  $elements Existing document elements.
 * @param string $region   Region code: 'eu', 'uk', etc.
 * @param string $type     Document type slug.
 * @param array  $fields   Wizard field values for this region.
 * @return array
 */
add_filter( 'cmplz_document_elements', 'wwu_wb_add_complianz_withdrawal_elements', 20, 4 );

function wwu_wb_add_complianz_withdrawal_elements(
    array $elements,
    string $region,
    string $type,
    array $fields
): array {

    // Only for T&C in EU or UK.
    if ( ! in_array( $region, [ 'eu', 'uk' ], true ) ) {
        return $elements;
    }
    if ( 'terms-conditions' !== $type ) {
        return $elements;
    }

    $button_url = get_permalink( get_option( 'wwu_wb_withdrawal_page_id' ) );

    $withdrawal_elements = [

        // --- Section heading ------------------------------------------------
        'wwu_wb_withdrawal_title' => [
            'title'     => __( 'Right of Withdrawal', 'wwu-withdrawal-button' ),
            'numbering' => true,
        ],

        // --- 14-day period (always shown) -----------------------------------
        'wwu_wb_withdrawal_14days' => [
            'content' => sprintf(
                /* translators: %s: URL of the online withdrawal page */
                __(
                    'You have the right to withdraw from this contract within 14 calendar days '
                    . 'without giving any reason. The withdrawal period expires 14 days after '
                    . 'the day you, or a third party indicated by you (other than the carrier), '
                    . 'take physical possession of the goods. '
                    . 'To exercise the right of withdrawal, you may use the dedicated function '
                    . 'available in your customer account at: %s',
                    'wwu-withdrawal-button'
                ),
                esc_url( $button_url )
            ),
        ],

        // --- Effects of withdrawal (always shown) ---------------------------
        'wwu_wb_withdrawal_effects' => [
            'content' => __(
                'If you withdraw from this contract, we will reimburse all payments received '
                . 'from you, including delivery costs (except for extra costs arising from your '
                . 'choice of a delivery type other than the least expensive standard delivery '
                . 'we offer), without undue delay and in any event not later than 14 days from '
                . 'the day we are informed about your decision to withdraw. We will carry out '
                . 'such reimbursement using the same means of payment as you used for the '
                . 'initial transaction. You may incur fees from us only if you expressly '
                . 'agreed to such fees and in connection with a supplementary contract.',
                'wwu-withdrawal-button'
            ),
        ],

        // --- Return of goods (physical goods only) --------------------------
        'wwu_wb_withdrawal_return' => [
            'content'   => __(
                'You shall send back the goods or hand them over to us without undue delay '
                . 'and in any event not later than 14 days from the day on which you '
                . 'communicate your withdrawal. The deadline is met if you send back the goods '
                . 'before the period of 14 days has expired. You will bear the direct cost '
                . 'of returning the goods.',
                'wwu-withdrawal-button'
            ),
            'condition' => [ 'sell_physical_products' => 'yes' ],
        ],

        // --- Digital-content exception (conditional on digital products) ----
        'wwu_wb_withdrawal_digital_exception' => [
            'content' => __(
                'If the contract concerns digital content not supplied on a tangible medium, '
                . 'you lose the right of withdrawal once performance has begun with your prior '
                . 'express consent and your acknowledgement that you thereby lose your right '
                . 'of withdrawal.',
                'wwu-withdrawal-button'
            ),
            'class'     => 'wwu-wb-digital-note',
            'condition' => [ 'sell_digital_products' => 'yes' ],
        ],

        // --- Model withdrawal form (collapsible) ----------------------------
        'wwu_wb_model_form_open' => [
            'dropdown-open'  => true,
            'dropdown-title' => __( 'Model Withdrawal Form (optional)', 'wwu-withdrawal-button' ),
            'dropdown-class' => 'wwu-wb-model-form',
        ],
        'wwu_wb_model_form_body' => [
            'p'       => false,
            'content' => wwu_wb_model_form_html(),
        ],
        'wwu_wb_model_form_close' => [
            'dropdown-close' => true,
        ],
    ];

    // Insert after 'contact_details' if it exists; otherwise append.
    $keys = array_keys( $elements );
    $pos  = array_search( 'contact_details', $keys, true );

    if ( false !== $pos ) {
        $elements = array_slice( $elements, 0, $pos + 1, true )
                  + $withdrawal_elements
                  + array_slice( $elements, $pos + 1, null, true );
    } else {
        $elements = $elements + $withdrawal_elements;
    }

    return $elements;
}

/**
 * Returns the HTML for the Annex I-A model withdrawal form.
 * Output is trusted (static template), so no kses filtering applied.
 *
 * @return string
 */
function wwu_wb_model_form_html(): string {
    ob_start();
    ?>
    <p><strong><?php esc_html_e( 'Model Withdrawal Form', 'wwu-withdrawal-button' ); ?></strong></p>
    <p><?php esc_html_e(
        '(Complete and return this form only if you wish to withdraw from the contract.)',
        'wwu-withdrawal-button'
    ); ?></p>
    <ul>
        <li><?php esc_html_e(
            'To [trader\'s name, geographical address, and, where available, fax number and e-mail address]:',
            'wwu-withdrawal-button'
        ); ?></li>
        <li><?php esc_html_e(
            'I/We (*) hereby give notice that I/We (*) withdraw from my/our (*) contract of sale of the following goods (*)/for the provision of the following service (*),',
            'wwu-withdrawal-button'
        ); ?></li>
        <li><?php esc_html_e( 'Ordered on (*)/received on (*)', 'wwu-withdrawal-button' ); ?></li>
        <li><?php esc_html_e( 'Name of consumer(s)', 'wwu-withdrawal-button' ); ?></li>
        <li><?php esc_html_e( 'Address of consumer(s)', 'wwu-withdrawal-button' ); ?></li>
        <li><?php esc_html_e( 'Signature of consumer(s) (only if this form is notified on paper)', 'wwu-withdrawal-button' ); ?></li>
        <li><?php esc_html_e( 'Date', 'wwu-withdrawal-button' ); ?></li>
    </ul>
    <p><em><?php esc_html_e( '(*) Delete as appropriate.', 'wwu-withdrawal-button' ); ?></em></p>
    <?php
    return ob_get_clean();
}
```

---

## Section 2 — EU and Italian law requirements

### 2.1 Legislative framework

| Source | Article | Subject |
|--------|---------|---------|
| Dir. 2011/83/EU (Consumer Rights Directive, CRD) | Art. 6(1)(h) | Pre-contractual info: right of withdrawal conditions, time limit, procedure |
| CRD | Art. 6(1)(i) | Pre-contractual info: model withdrawal form (Annex I-B) must be provided |
| CRD | Art. 7(1) | Info requirements for distance contracts (in plain, intelligible language) |
| CRD | Art. 10 | 12-month extension of the 14-day period if merchant failed to provide withdrawal info |
| CRD | Art. 11 | How to exercise the right (any unequivocal statement; model form is one option) |
| Dir. (EU) 2023/2673 | inserts Art. 11a | **Online withdrawal button** mandatory for distance contracts concluded online; applies from 19 June 2026 |
| CRD | Art. 12 | Effects of withdrawal on ancillary contracts |
| CRD | Art. 13 | Merchant refund obligation: ≤ 14 days, same payment method |
| CRD | Art. 14 | Consumer return obligation: ≤ 14 days |
| CRD | Art. 16 | Exceptions to the right of withdrawal (exhaustive list) |
| CRD Annex I-A | — | Model withdrawal form (to be completed by consumer) |
| CRD Annex I-B | — | Instructions for the completion of the model form (to be provided by merchant) |
| D.Lgs. 206/2005 (Codice del Consumo) | Art. 54 | Italian transposition of Art. 11 CRD |
| D.Lgs. 209/2025 | inserts Art. 54-bis | Italian transposition of Art. 11a CRD; GU 8 January 2026; in force 19 June 2026 |

### 2.2 Art. 11a (new, Dir. 2023/2673) — Online withdrawal function

Full text (relevant excerpt):

> "1. For contracts concluded through online interfaces, traders shall make available to the consumer a withdrawal function allowing the consumer to exercise the right of withdrawal from the contract in accordance with Article 11.
> 2. The withdrawal function shall be clearly labelled and easily accessible for the consumer. The consumer shall be able to exercise the right of withdrawal directly and without going through additional steps that could delay the processing of the withdrawal.
> 3. After the consumer has completed the withdrawal procedure, the trader shall send an acknowledgement of receipt of the withdrawal to the consumer on a durable medium."

**Key operational requirements derived from Art. 11a:**

- The button/function must be prominently placed in the customer account or order-history area — not buried in a footer link.
- "Without going through additional steps" means the consumer should not need to call a phone number, write an email, or navigate through an unrelated support flow. Our 2-step flow (select items → confirm) is compliant as long as both steps are on-screen within the withdrawal function itself.
- The acknowledgement on a "durable medium" means: **an email to the consumer's address is sufficient**. A web notification that disappears, or a note only visible inside an account area the consumer may lose access to, does NOT qualify.

### 2.3 Italian Art. 54-bis (D.Lgs. 209/2025)

Verbatim (official Italian text):

> **Art. 54-bis** (Funzione di recesso online)
>
> 1. Per i contratti conclusi tramite interfacce online, il professionista mette a disposizione del consumatore una funzione di recesso che consenta al consumatore di esercitare il diritto di recesso dal contratto ai sensi dell'articolo 54.
> 2. La funzione di recesso deve essere chiaramente etichettata e facilmente accessibile per il consumatore. Il consumatore deve poter esercitare il diritto di recesso direttamente, senza dover compiere ulteriori passaggi che potrebbero ritardare la gestione del recesso.
> 3. Dopo che il consumatore ha completato la procedura di recesso, il professionista trasmette al consumatore, su supporto durevole, conferma di ricevimento del recesso.

**"Supporto durevole" (durable medium) — what qualifies:**

- Email body or PDF attachment sent to the consumer's registered address: YES
- Link to a web page: NO (page may be removed or access revoked)
- Customer account area notification only: NO (access may be lost after account deletion)
- SMS: YES (if it contains the full acknowledgement text, not just a "check your account" link)

### 2.4 Art. 16 exceptions (exhaustive list)

The right of withdrawal does NOT apply to:

| Exception | Art. 16 letter | Practical trigger for our plugin |
|-----------|----------------|----------------------------------|
| Service contracts fully performed before the 14-day period, with consumer consent + acknowledgement | (a) | Booked and fully delivered services |
| Price dependent on financial market fluctuations | (b) | Crypto/stocks — unlikely for typical WC stores |
| Goods made to consumer's specifications or clearly personalised | (c) | Custom-print, bespoke products |
| Goods that may deteriorate or expire rapidly | (d) | Fresh food, flowers |
| Sealed goods that are unsealed post-delivery and cannot be returned for health/hygiene reasons | (e) | Cosmetics, underwear |
| Alcoholic beverages (price agreed at conclusion, delivery after 30 days, value dependent on fluctuations) | (f) | Wine/spirits with delayed delivery |
| Urgent repair or maintenance contracts requested by consumer | (g) | Emergency plumber, etc. |
| Sealed audio/video recordings or computer software unsealed after delivery | (i) | Physical software boxes, DVDs |
| Newspapers, periodicals, magazines (except subscription contracts) | (j) | Single-issue print |
| Contracts concluded at public auction | (k) | Auction platforms |
| Accommodation, transport of goods, car rental, catering, leisure activities for a specific date/period | (l) | Hotel bookings, event tickets |
| Digital content not supplied on tangible medium, performance begun with prior consent + acknowledgement of loss of withdrawal right | (m) | Downloadable software, streaming access |
| Contracts for social services, healthcare, gambling | — | Out of scope for most WC stores |

**Our plugin's UX obligation (already implemented):** the merchant must configure which exceptions apply per product. For exception (m) (digital content), the consumer must actively tick a checkbox acknowledging they lose the right of withdrawal. The plugin already implements this consent checkbox + stores it in order meta.

### 2.5 Art. 13 — Merchant refund obligations

- Must reimburse within **14 days** from the day the merchant is informed of the withdrawal decision.
- Must refund **all payments received**, including standard delivery charges (not premium delivery surcharges if consumer chose a non-standard option).
- Must use the **same payment method** as the original transaction, unless the consumer expressly agrees otherwise and incurs no fees.
- May **withhold refund** until goods are returned or consumer provides evidence of return shipment — whichever comes first.
- May make a deduction for diminished value of goods if the diminishment results from handling the goods beyond what is necessary to establish their nature, characteristics, and functioning.

### 2.6 Art. 14 — Consumer return obligations

- Must send back goods within **14 days** from the day the consumer communicated the withdrawal.
- Bears the **direct cost** of returning goods, unless the merchant agreed to bear them or failed to inform the consumer that they would bear them (in which case the merchant bears them).
- Bears **no costs** for services performed during the withdrawal period if the merchant failed to provide required pre-contractual information.

### 2.7 Annotated compliance checklist — six domains

#### F.1 — Pre-contractual information (must be provided BEFORE the consumer is bound)

- [ ] **F.1.1** Right of withdrawal stated (Art. 6(1)(h)): 14-day period, conditions, procedure, model form.
- [ ] **F.1.2** Model withdrawal form provided (Art. 6(1)(i), Annex I-B instructions page). Must be in writing or on durable medium; in the T&C or a separate document.
- [ ] **F.1.3** If no right of withdrawal exists (Art. 16 exception), state that and the applicable exception explicitly.
- [ ] **F.1.4** Refund timeline and method stated (Art. 6(1)(j)): costs the consumer must bear for return.
- [ ] **F.1.5** For digital content not on tangible medium: prior express consent and acknowledgement checkbox must be on the checkout screen (Art. 6(1)(m), Art. 16(m)).

#### F.2 — Order confirmation (on durable medium — email qualifies)

- [ ] **F.2.1** Confirmation sent on durable medium within a reasonable time after conclusion (Art. 7(2), Art. 8(7)).
- [ ] **F.2.2** Confirmation includes a copy of, or a reference to, the pre-contractual information (including withdrawal right) on a durable medium. A link to the website does NOT qualify.
- [ ] **F.2.3** For digital content: the confirmation includes the express consent and acknowledgement the consumer gave.

#### F.3 — Withdrawal button / online function (Art. 11a, in force 19 June 2026)

- [ ] **F.3.1** An online withdrawal function is present in the customer-facing area (My Account, order history, etc.).
- [ ] **F.3.2** The function is clearly labelled (e.g. "Withdraw from contract", "Right of withdrawal", "Esercita il diritto di recesso").
- [ ] **F.3.3** The function is easily accessible — not buried inside an unrelated page or requiring a support ticket flow.
- [ ] **F.3.4** The consumer can complete the withdrawal procedure without additional steps unrelated to the withdrawal itself.
- [ ] **F.3.5** On completion, the consumer receives an **acknowledgement on a durable medium** (email with full confirmation).
- [ ] **F.3.6** The acknowledgement includes: date and time of the withdrawal, order reference, products/services withdrawn from, next steps (refund timeline, return instructions if applicable).

#### F.4 — Managing received withdrawals (merchant operations)

- [ ] **F.4.1** Refund processed within 14 days (Art. 13).
- [ ] **F.4.2** Refund covers standard delivery charges where applicable.
- [ ] **F.4.3** Refund made via same payment method unless consumer expressly agrees otherwise.
- [ ] **F.4.4** If goods are involved, refund may be withheld until goods returned or evidence of shipment received.
- [ ] **F.4.5** Return instructions communicated: merchant address, consumer bears cost (unless agreed otherwise).
- [ ] **F.4.6** Any partial withdrawal (one of several items in an order) handled proportionally.

#### F.5 — Policy document (T&C / withdrawal policy page)

- [ ] **F.5.1** A dedicated withdrawal-right section exists in the T&C or as a standalone page.
- [ ] **F.5.2** The section mentions the **online withdrawal function** and how to access it (URL or "via My Account").
- [ ] **F.5.3** The 14-day period is stated clearly.
- [ ] **F.5.4** Effects of withdrawal are explained (refund timeline, return obligation, cost allocation).
- [ ] **F.5.5** Applicable Art. 16 exceptions are listed and explained.
- [ ] **F.5.6** The model withdrawal form (Annex I-A) is included (or linked on durable medium).
- [ ] **F.5.7** The policy is updated to name the button (per Alessandro Vercellotti's reminder — already done in ClauseLibrary `terms` clause from v1.2.0).

#### F.6 — Exceptions by product type

| Product type | Right of withdrawal | Conditions | Checklist item |
|---|---|---|---|
| Physical goods (standard) | Yes, 14 days | Consumer returns goods at own cost | F.6.1: inform consumer about return cost in pre-contractual info |
| Physical goods (personalised / custom-made) | No (Art. 16(c)) | Merchant must state exception before order | F.6.2: show exception notice in product page / checkout |
| Physical goods (hygiene-sealed, unsealed) | No (Art. 16(e)) | Must inform consumer | F.6.3: checkbox or notice before purchase |
| Digital content (download, streaming) | No, once performance started (Art. 16(m)) | Consumer gave prior express consent + acknowledged loss of right | F.6.4: mandatory consent checkbox at checkout |
| Services (fully performed) | No (Art. 16(a)) | Consumer requested early performance + acknowledged | F.6.5: consent checkbox for early performance |
| Subscriptions (ongoing services) | Yes, 14 days from conclusion | Proportional fee for service already delivered | F.6.6: handle subscription withdrawals separately |
| Event tickets / hotel / transport | No (Art. 16(l)) | Specific date/period contracts | F.6.7: inform consumer |
| Fresh / perishable goods | No (Art. 16(d)) | | F.6.8: inform consumer |

### 2.8 AGCM enforcement context

AGCM fined eDreams €9 million (January 2026) for failure to provide a straightforward online withdrawal mechanism. The fine was based on the existing Art. 11 obligations (unequivocal statement route) — the new Art. 11a obligation (explicit button) makes non-compliance even more visible and enforceable. The "no additional steps" requirement is the main enforcement target.

---

## Section 3 — i18n tooling APIs

### 3.1 WP-CLI `wp i18n` subcommands

All five subcommands run on the `before_wp_load` hook. **No WordPress installation is required.** They work on plain PHP source files in any directory.

| Subcommand | Purpose | Key options |
|---|---|---|
| `wp i18n make-pot <source-dir> <output.pot>` | Extract translatable strings into a .pot file | `--domain=<textdomain>`, `--include=<patterns>`, `--exclude=<patterns>`, `--skip-js`, `--skip-php`, `--skip-blade` |
| `wp i18n update-po <source.pot> [<po-files...>]` | Merge new/changed strings from .pot into existing .po files | — |
| `wp i18n make-mo <source.po\|dir> [<dest-dir>]` | Compile .po → .mo (binary) | Pass directory to batch-compile all .po files in it |
| `wp i18n make-json <source.po\|dir> [<dest-dir>]` | Extract JS strings from .po into .json (for `wp.i18n.setLocaleData`) | `--no-purge` (keep JS strings in the .po), `--pretty-print` |
| `wp i18n make-php <source.po\|dir> [<dest-dir>]` | Generate a PHP file containing a pre-built translation map (for load-time performance) | — |

**Critical pipeline ordering:**

```
make-pot → make-mo → make-json [--no-purge]
```

`make-json` by default **removes JS-originated strings from the .po file** (purge). If you run `make-mo` after `make-json`, the .mo will be missing those strings. Always compile .mo first, or always use `--no-purge` with `make-json`.

**Practical examples:**

```bash
# 1. Extract strings
wp i18n make-pot . languages/wwu-withdrawal-button.pot --domain=wwu-withdrawal-button

# 2. Update existing .po files from the .pot
wp i18n update-po languages/wwu-withdrawal-button.pot languages/

# 3. Compile .mo BEFORE make-json
wp i18n make-mo languages/wwu-withdrawal-button-it_IT.po languages/

# 4. Extract JS translations (--no-purge preserves .po for further use)
wp i18n make-json languages/wwu-withdrawal-button-it_IT.po languages/ --no-purge

# 5. (Optional) PHP precompiled map for high-performance loads
wp i18n make-php languages/wwu-withdrawal-button-it_IT.po languages/
```

**Note on this machine:** Per memory `reference_poedit_gettext.md`, Poedit's bundled `msgfmt`/`msgmerge` are the available .po/.mo toolchain on this Windows machine. `wp i18n make-mo` wraps these same binaries. If WP-CLI is not installed, use Poedit's CLI directly:

```bash
msgfmt languages/wwu-withdrawal-button-it_IT.po -o languages/wwu-withdrawal-button-it_IT.mo
```

### 3.2 `gettext/gettext` PHP library v5

Package: `composer require gettext/gettext`  
Current stable: 5.7.3 (MIT licence)  
Repository: https://github.com/php-gettext/Gettext

This library operates entirely in PHP userland — no system `msgfmt` binary needed. Useful for server-side .po/.mo manipulation (e.g. in a build script that runs inside WP-CLI without native gettext tools, or for dynamic generation of .po content from database strings).

#### Class map

| Class | Namespace | Purpose |
|---|---|---|
| `PoLoader` | `Gettext\Loader` | Parse a .po file into a `Translations` object |
| `MoLoader` | `Gettext\Loader` | Parse a .mo binary into a `Translations` object |
| `PoGenerator` | `Gettext\Generator` | Write a `Translations` object back to .po |
| `MoGenerator` | `Gettext\Generator` | Write a `Translations` object to .mo binary |
| `Translations` | `Gettext` | Collection of `Translation` entries; implements `Countable`, `IteratorAggregate` |
| `Translation` | `Gettext` | Single entry: context, original, plural, translated string(s) |

#### Complete usage example

```php
<?php
use Gettext\Loader\PoLoader;
use Gettext\Loader\MoLoader;
use Gettext\Generator\PoGenerator;
use Gettext\Generator\MoGenerator;
use Gettext\Translation;

// --- Load a .po file ---
$loader       = new PoLoader();
$translations = $loader->loadFile( 'languages/wwu-withdrawal-button-it_IT.po' );

// --- Iterate and inspect ---
foreach ( $translations as $translation ) {
    $original    = $translation->getOriginal();      // source string
    $translated  = $translation->getTranslation();   // null = untranslated
    $context     = $translation->getContext();        // null or 'Tab Title' etc.
    $plural      = $translation->getPlural();         // null or plural form
    $comments    = $translation->getComments();       // extracted/translator comments
}

// --- Find and update a specific entry ---
$entry = $translations->find( null, 'Save changes' );
if ( null !== $entry ) {
    $entry->translate( 'Salva modifiche' );
}

// --- Add a new entry with plural forms ---
$new_entry = Translation::create( null, '%d withdrawal request', '%d withdrawal requests' );
$new_entry->translate( '%d richiesta di recesso' );
$new_entry->translatePlural( '%d richieste di recesso' );
$translations->add( $new_entry );

// --- Add a contextualised entry ---
$ctx_entry = Translation::create( 'Button label', 'Withdraw' );
$ctx_entry->translate( 'Recedi' );
$translations->add( $ctx_entry );

// --- Write back to .po and compile to .mo ---
( new PoGenerator() )->generateFile( $translations, 'languages/wwu-withdrawal-button-it_IT.po' );
( new MoGenerator() )->generateFile( $translations, 'languages/wwu-withdrawal-button-it_IT.mo' );

// --- Merge two .po files (union, first-wins) ---
$base    = $loader->loadFile( 'languages/wwu-withdrawal-button-it_IT.po' );
$updates = $loader->loadFile( 'languages/crowdin-it_IT.po' );
$merged  = $base->mergeWith( $updates );
( new PoGenerator() )->generateFile( $merged, 'languages/wwu-withdrawal-button-it_IT.merged.po' );
```

#### Key method signatures

```php
// Translations methods
$translations->find( ?string $context, string $original ): ?Translation;
$translations->add( Translation $translation ): self;
$translations->mergeWith( Translations $other, int $flags = Merge::ADD | Merge::HEADERS ): self;
count( $translations );  // number of entries (implements Countable)

// Translation methods
Translation::create( ?string $context, string $original, ?string $plural = null ): self;
$translation->getOriginal(): string;
$translation->getPlural(): ?string;
$translation->getContext(): ?string;
$translation->getTranslation(): ?string;   // null = not yet translated
$translation->translate( string $value ): self;
$translation->translatePlural( string $value, int $index = 1 ): self;  // index 1 = first plural form
$translation->getComments(): Comments;  // iterable
$translation->getExtractedComments(): Comments;

// Generators
( new PoGenerator() )->generateFile( Translations $t, string $path ): bool;
( new MoGenerator() )->generateFile( Translations $t, string $path ): bool;
( new PoGenerator() )->generateString( Translations $t ): string;  // in-memory
( new MoGenerator() )->generateString( Translations $t ): string;  // in-memory
```

#### When to use this library vs `wp i18n`

| Scenario | Use |
|---|---|
| Extracting strings from PHP/JS source files | `wp i18n make-pot` (parses source code, not just strings) |
| Compiling .po → .mo on CI or in a build script | `wp i18n make-mo` (wraps system msgfmt) or `MoGenerator` (pure PHP, no binary needed) |
| Programmatic manipulation of .po content (add/update/merge strings from DB) | `gettext/gettext` PHP library |
| Merging Crowdin output with local .po without losing entries | `gettext/gettext` `mergeWith()` OR `msgcat --use-first` (CLI) |
| Generating .json for `wp.i18n` | `wp i18n make-json` — no PHP equivalent in this library |

---

## References

### EU Legislation

- Directive 2011/83/EU of the European Parliament and of the Council of 25 October 2011 on consumer rights: https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32011L0083
- Directive (EU) 2023/2673 amending Directive 2011/83/EU (inserts Art. 11a): https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32023L2673
- Consolidated text of Dir. 2011/83/EU (including 2023/2673 amendments): https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:02011L0083-20240619

### Italian Law

- D.Lgs. 206/2005 (Codice del Consumo) — Gazzetta Ufficiale: https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:decreto.legislativo:2005-09-06;206
- D.Lgs. 209/2025 (transposition of Omnibus II / Dir. 2023/2673) — GU 8 January 2026: https://www.gazzettaufficiale.it/eli/gu/2026/01/08/5/sg/pdf

### AGCM Enforcement

- AGCM Provvedimento n. 31156 (eDreams, January 2026): https://www.agcm.it/media/comunicati-stampa/2026/1/PS12693

### CJEU Jurisprudence

- C-49/11 (Content Services): https://curia.europa.eu/juris/liste.jsf?num=C-49/11
- C-375/15 (BAWAG): https://curia.europa.eu/juris/liste.jsf?num=C-375/15
- C-529/19 (Möbel Kraft): https://curia.europa.eu/juris/liste.jsf?num=C-529/19
- C-641/19 (PE Digital): https://curia.europa.eu/juris/liste.jsf?num=C-641/19
- C-681/17 (slewo): https://curia.europa.eu/juris/liste.jsf?num=C-681/17
- C-96/21 (DM Beauty Club): https://curia.europa.eu/juris/liste.jsf?num=C-96/21
- C-249/21 (Fuhrmann-2): https://curia.europa.eu/juris/liste.jsf?num=C-249/21

### Complianz

- Complianz GDPR Premium (local workspace): `complianz-gdpr-premium/complianz-gdpr-premium/`
- Complianz Terms & Conditions companion plugin: https://wordpress.org/plugins/complianz-terms-conditions/
- Complianz developer docs (hooks): https://complianz.io/hooks/

### WP-CLI i18n

- WP-CLI `wp i18n` command reference: https://developer.wordpress.org/cli/commands/i18n/
- WP-CLI source (i18n package): https://github.com/wp-cli/i18n-command

### gettext/gettext PHP Library

- GitHub: https://github.com/php-gettext/Gettext
- Packagist: https://packagist.org/packages/gettext/gettext
- Changelog v5: https://github.com/php-gettext/Gettext/blob/master/CHANGELOG.md
