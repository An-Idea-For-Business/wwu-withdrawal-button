# Test checklists — WWU Withdrawal Button

Manual, shareable checklists. Anyone can run them on a **staging** store. Two kinds:

## 1. "Try the plugin" — end-to-end evaluator guides (one per platform)

Take a store from install to a full, verified withdrawal: install → the withdrawal button/entry points
→ two-step statement→confirmation (incl. no-JS) → durable medium (e-mail + PDF + verify link) →
evidence-log integrity → merchant processing (refund + mark processed) → exemptions → compliance helpers
→ uninstall.

| Platform | Checklist | Button/entry surfaces |
|---|---|---|
| **WooCommerce** | [try-the-plugin-woocommerce](wwu-wb-try-the-plugin-woocommerce-CHECKLIST.md) | 3 (My Account orders action, order detail, "Right of withdrawal" tab) |
| **FluentCart** | [try-the-plugin-fluentcart](wwu-wb-try-the-plugin-fluentcart-CHECKLIST.md) | 4 (portal endpoint, sidebar item, dashboard banner, per-order button) |
| **Easy Digital Downloads** | [try-the-plugin-edd](wwu-wb-try-the-plugin-edd-CHECKLIST.md) | 3 since alpha.35 (purchase receipt / purchase-history rows / receipt e-mail link) + standalone page / payment-key / guest lookup |

## 2. Exemptions consent capture — focused feature checklists (Art. 59)

Verify the checkout **consent capture** for the two conditional exemptions (digital with immediate
access; service fully performed): the required checkbox appears, blocks checkout until ticked, captures
the consent, and the button is hidden for the exempt item only after consent (fail-safe otherwise).

| Platform | Checklist |
|---|---|
| **WooCommerce block Checkout** | [woocommerce-block-consent](wwu-wb-woocommerce-block-consent-CHECKLIST.md) (Additional Checkout Fields API, WC 9.9+) |
| **FluentCart** | [fluentcart-consent](wwu-wb-fluentcart-consent-CHECKLIST.md) (`before_payment_methods`, category-aware) |
| **Easy Digital Downloads** | [edd-consent](wwu-wb-edd-consent-CHECKLIST.md) (`edd_purchase_form_before_submit`, category-aware) |

## Quick start

1. Run the **"try the plugin"** checklist for your platform (kind 1) — that's the full evaluation.
2. If you sell digital/immediate content or services, also run the matching **consent** checklist (kind 2).
3. Everything is **fail-safe**: wherever consent capture or a surface is unavailable, the withdrawal
   button stays — the consumer never loses the right by accident.

> These are technical test aids, **not legal advice**. Have your store's documents reviewed by counsel.
