# WWU Withdrawal Button — Hooks & Filters Reference

All extension points the plugin exposes are prefixed `wwu_wb_`. These are the **supported public API**: you can safely rely on them across patch releases. Hooks not listed here are internal implementation details and may change without notice.

Every `apply_filters()` / `do_action()` listed below was verified by reading the source. Default values match the plugin as shipped; examples compile correctly under PHP 7.4+.

---

## Table of contents

1. [Access control](#1-access-control)
2. [Applicability & eligibility](#2-applicability--eligibility)
3. [Platforms / adapters](#3-platforms--adapters)
4. [FluentCart](#4-fluentcart)
5. [Exemptions (Art. 59)](#5-exemptions-art-59)
6. [Rendering / surfaces](#6-rendering--surfaces)
7. [Guest access & security](#7-guest-access--security)
8. [Durable medium / evidence](#8-durable-medium--evidence)
9. [Timestamping](#9-timestamping)
10. [Lifecycle & log](#10-lifecycle--log)
11. [At a glance](#11-at-a-glance)
12. [Automations (REST API & webhook)](#12-automations-rest-api--webhook)

---

## 1. Access control

### `wwu_wb_admin_capability`

| | |
|---|---|
| **Type** | `filter` |
| **Fire sites** | `src/Debug/Audience.php:102`, `src/REST/Authentication.php:35` |

**Signature**

```php
apply_filters( 'wwu_wb_admin_capability', string $capability )
```

| Param | Type | Description |
|---|---|---|
| `$capability` | `string` | WordPress capability required to access admin features and REST endpoints. Default: `'manage_options'`. |

**Purpose.** Overrides the capability gate used by both the debug audience check and every REST endpoint permission callback. Useful when you run the plugin in a multisite environment where a custom `shop_manager` capability should grant access.

**Example**

```php
// Grant access to users with 'edit_shop_orders' instead of 'manage_options'.
add_filter( 'wwu_wb_admin_capability', function ( $cap ) {
    return 'edit_shop_orders';
} );
```

---

## 2. Applicability & eligibility

### `wwu_wb_applicability_decision`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Domain/ApplicabilityResolver.php:62` |

**Signature**

```php
apply_filters( 'wwu_wb_applicability_decision', ApplicabilityDecision $decision, NormalizedOrder $order )
```

| Param | Type | Description |
|---|---|---|
| `$decision` | `ApplicabilityDecision` | The resolver's computed decision object (eligible, reason, deadline, etc.). |
| `$order` | `NormalizedOrder` | The platform-agnostic order value object. |

**Purpose.** Lets you override the entire applicability decision for an order — for instance to force the withdrawal button to appear (or not) based on business-specific rules not captured by the built-in evaluators.

**Example**

```php
use WWU\WithdrawalButton\Domain\ApplicabilityDecision;

// Always make B2B orders ineligible regardless of other criteria.
add_filter(
    'wwu_wb_applicability_decision',
    function ( ApplicabilityDecision $decision, $order ) {
        if ( $order->has_vat_number ) {
            return $decision->as_ineligible( 'b2b_excluded' );
        }
        return $decision;
    },
    10,
    2
);
```

---

### `wwu_wb_eligible_statuses`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Domain/ApplicabilityResolver.php:143` |

**Signature**

```php
apply_filters( 'wwu_wb_eligible_statuses', string[] $eligible, NormalizedOrder $order )
```

| Param | Type | Description |
|---|---|---|
| `$eligible` | `string[]` | Unprefixed WooCommerce / platform status slugs treated as eligible (e.g. `['processing', 'completed']`). |
| `$order` | `NormalizedOrder` | The normalized order. |

**Purpose.** Expands or restricts the set of order statuses that make the withdrawal button available. By default the plugin ships a sensible set covering standard WooCommerce and FluentCart statuses; add custom statuses here.

**Example**

```php
// Accept a custom 'shipped' status as eligible.
add_filter(
    'wwu_wb_eligible_statuses',
    function ( array $statuses, $order ) {
        $statuses[] = 'shipped';
        return $statuses;
    },
    10,
    2
);
```

---

### `wwu_wb_in_scope_countries`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Domain/Countries.php:71` |

**Signature**

```php
apply_filters( 'wwu_wb_in_scope_countries', string[] $countries )
```

| Param | Type | Description |
|---|---|---|
| `$countries` | `string[]` | Uppercase ISO-3166 alpha-2 codes. Default: EU-27 + EEA-EFTA (Norway, Iceland, Liechtenstein). |

**Purpose.** Adds or removes countries from the mandatory in-scope set. The plugin maps an order's billing country against this list; orders outside it fall back to the voluntary (Switzerland) or out-of-scope path. The return value is deduped and uppercased automatically.

**Example**

```php
// Add the UK post-Brexit as a voluntary scope (your legal team's call).
add_filter( 'wwu_wb_in_scope_countries', function ( array $countries ) {
    $countries[] = 'GB';
    return $countries;
} );
```

---

### `wwu_wb_withdrawal_window_days`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Domain/WindowCalculator.php:44` |

**Signature**

```php
apply_filters( 'wwu_wb_withdrawal_window_days', int $days, NormalizedOrder $order )
```

| Param | Type | Description |
|---|---|---|
| `$days` | `int` | Statutory withdrawal period in calendar days. Default: `14`. |
| `$order` | `NormalizedOrder` | The normalized order. |

**Purpose.** Changes the withdrawal window for specific orders. The EU Consumer Rights Directive mandates 14 days, but some jurisdictions or promotions may require a longer window.

**Example**

```php
// Extend to 30 days for a specific product category.
add_filter(
    'wwu_wb_withdrawal_window_days',
    function ( int $days, $order ) {
        foreach ( $order->items as $item ) {
            if ( in_array( 99, $item['category_ids'], true ) ) {
                return 30;
            }
        }
        return $days;
    },
    10,
    2
);
```

---

### `wwu_wb_compute_deadline`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Domain/WindowCalculator.php:68` |

**Signature**

```php
apply_filters( 'wwu_wb_compute_deadline', \DateTimeImmutable $deadline, NormalizedOrder $order, int $days )
```

| Param | Type | Description |
|---|---|---|
| `$deadline` | `\DateTimeImmutable` | The computed deadline (UTC). |
| `$order` | `NormalizedOrder` | The normalized order. |
| `$days` | `int` | The window length already filtered by `wwu_wb_withdrawal_window_days`. |

**Purpose.** Allows full control over the deadline DateTimeImmutable object — for example to snap it to end-of-business day, apply holiday extensions, or implement complex calendar rules.

**Example**

```php
// Move the deadline to end of day (23:59:59 UTC) instead of exact time.
add_filter(
    'wwu_wb_compute_deadline',
    function ( \DateTimeImmutable $deadline, $order, int $days ) {
        return $deadline->setTime( 23, 59, 59 );
    },
    10,
    3
);
```

---

## 3. Platforms / adapters

### `wwu_wb_platform_adapters`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Platform/PlatformRegistry.php:58` |

**Signature**

```php
apply_filters( 'wwu_wb_platform_adapters', OrderDataSource[] $adapters )
```

| Param | Type | Description |
|---|---|---|
| `$adapters` | `OrderDataSource[]` | Keyed array of registered adapter instances (`'woocommerce' => WooCommerceAdapter`, etc.). |

**Purpose.** Registers a custom platform adapter or replaces an existing one. Implement the `OrderDataSource` interface, return it here, and the plugin routes orders through your adapter automatically.

**Example**

```php
use MyPlugin\MyAdapter;

add_filter( 'wwu_wb_platform_adapters', function ( array $adapters ) {
    $adapters['my-platform'] = new MyAdapter();
    return $adapters;
} );
```

---

### `wwu_wb_order_is_renewal`

| | |
|---|---|
| **Type** | `filter` |
| **Fire sites** | `src/Platform/WooCommerceAdapter.php:118`, `src/Platform/FluentCartAdapter.php:396`, `src/Platform/EddAdapter.php:213` |

**Signature**

```php
apply_filters( 'wwu_wb_order_is_renewal', bool $is_renewal, string $order_ref, string $platform )
```

| Param | Type | Description |
|---|---|---|
| `$is_renewal` | `bool` | Whether the adapter detected this as a subscription-renewal order. |
| `$order_ref` | `string` | Stable order reference (ID). |
| `$platform` | `string` | Adapter key: `'woocommerce'`, `'fluentcart'`, or `'edd'`. |

**Purpose.** Overrides the adapter's subscription-renewal detection. Renewal orders may lose the right of withdrawal (Art. 59 ongoing service); integrators with custom subscription plugins should use this to feed their own detection result to the plugin.

**Example**

```php
// Detect renewals via a custom meta key.
add_filter(
    'wwu_wb_order_is_renewal',
    function ( bool $is_renewal, string $order_ref, string $platform ) {
        if ( 'woocommerce' === $platform ) {
            $order = wc_get_order( (int) $order_ref );
            if ( $order && $order->get_meta( '_my_subscription_renewal' ) ) {
                return true;
            }
        }
        return $is_renewal;
    },
    10,
    3
);
```

---

### `wwu_wb_order_has_vat_number`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Platform/WooCommerceAdapter.php:341` |

**Signature**

```php
apply_filters( 'wwu_wb_order_has_vat_number', bool $found, \WC_Order $order )
```

| Param | Type | Description |
|---|---|---|
| `$found` | `bool` | Whether the adapter found a VAT / business number in known meta keys. |
| `$order` | `\WC_Order` | The native WooCommerce order object. |

**Purpose.** Overrides WooCommerce B2B (VAT-number) detection. When `true`, the order is treated as a business purchase, which affects the default applicability evaluation (B2B orders may not have the same consumer-protection rights). Use a VAT-validation plugin's own API to provide a reliable signal.

**Example**

```php
// Integrate with WooCommerce EU VAT Number plugin.
add_filter(
    'wwu_wb_order_has_vat_number',
    function ( bool $found, \WC_Order $order ) {
        $vat = $order->get_meta( '_billing_vat_number' );
        return $found || ( '' !== trim( (string) $vat ) );
    },
    10,
    2
);
```

---

### `wwu_wb_edd_order_has_vat_number`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Platform/EddAdapter.php:441` |

**Signature**

```php
apply_filters( 'wwu_wb_edd_order_has_vat_number', bool $found, string $order_ref )
```

| Param | Type | Description |
|---|---|---|
| `$found` | `bool` | Whether the adapter found a VAT / business number for this EDD order. |
| `$order_ref` | `string` | The EDD order reference. |

**Purpose.** Same semantic as `wwu_wb_order_has_vat_number` but for Easy Digital Downloads orders, where the native order object type differs. Use the EDD orders API to supply your VAT detection result.

**Example**

```php
add_filter(
    'wwu_wb_edd_order_has_vat_number',
    function ( bool $found, string $order_ref ) {
        $vat = edd_get_order_meta( (int) $order_ref, '_vat_number', true );
        return $found || '' !== (string) $vat;
    },
    10,
    2
);
```

---

## 4. FluentCart

### `wwu_wb_fluentcart_native_active`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Platform/FluentCartAdapter.php:95` |

**Signature**

```php
apply_filters( 'wwu_wb_fluentcart_native_active', bool $detected )
```

| Param | Type | Description |
|---|---|---|
| `$detected` | `bool` | Whether the adapter auto-detected FluentCart as active (class-existence check). |

**Purpose.** Overrides the FluentCart active-detection heuristic. Set to `false` to disable the FluentCart adapter entirely even when the plugin is loaded; set to `true` to enable it in test environments where FluentCart classes are mocked.

**Example**

```php
// Disable FluentCart integration on staging.
add_filter( 'wwu_wb_fluentcart_native_active', '__return_false' );
```

---

### `wwu_wb_fluentcart_product_category_ids`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Platform/FluentCartAdapter.php:676` |

**Signature**

```php
apply_filters( 'wwu_wb_fluentcart_product_category_ids', int[] $ids, int $post_id )
```

| Param | Type | Description |
|---|---|---|
| `$ids` | `int[]` | Array of taxonomy term IDs assigned to the product. |
| `$post_id` | `int` | The FluentCart product post ID. |

**Purpose.** Overrides the category IDs retrieved for a FluentCart product. The category list feeds the Art. 59 exemption evaluator (digital downloads, services, etc.); if your FluentCart setup uses a non-standard taxonomy, return the correct term IDs here.

**Example**

```php
add_filter(
    'wwu_wb_fluentcart_product_category_ids',
    function ( array $ids, int $post_id ) {
        // Append IDs from a custom taxonomy.
        $extra = wp_get_post_terms( $post_id, 'my_product_type', array( 'fields' => 'ids' ) );
        return array_merge( $ids, is_array( $extra ) ? $extra : array() );
    },
    10,
    2
);
```

---

## 5. Exemptions (Art. 59)

### `wwu_wb_exception_types`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Domain/ExceptionTypes.php:84` |

**Signature**

```php
apply_filters( 'wwu_wb_exception_types', array $types )
```

| Param | Type | Description |
|---|---|---|
| `$types` | `array` | Associative map of `reason_id => definition` arrays for each Art. 59 exception type built into the plugin. |

**Purpose.** Registers custom Art. 59 exception types or modifies the definition of an existing one (label, consent text, required fields). Definitions control how the exemption evaluator classifies products and what consent wording is captured at checkout.

**Example**

```php
// Add a custom 'newspaper_subscription' exception type.
add_filter( 'wwu_wb_exception_types', function ( array $types ) {
    $types['newspaper_subscription'] = array(
        'label'          => __( 'Newspaper subscription', 'my-theme' ),
        'consent_kind'   => 'opt_out_periodicals',
        'requires_start' => false,
    );
    return $types;
} );
```

---

### `wwu_wb_exemption_consent`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Domain/ArticleFiftyNineEvaluator.php:119` |

**Signature**

```php
apply_filters( 'wwu_wb_exemption_consent', array $consent, NormalizedOrder $order, array $item, string $reason )
```

| Param | Type | Description |
|---|---|---|
| `$consent` | `array` | The consent data array assembled from checkout meta (keys vary by `$reason`). |
| `$order` | `NormalizedOrder` | The normalized order. |
| `$item` | `array` | The line-item array being evaluated. |
| `$reason` | `string` | The Art. 59 reason ID (e.g. `'digital_content'`, `'service_started'`). |

**Purpose.** Lets you augment or replace the consent data captured for a specific exemption reason. This is the integration point for headless / custom checkout flows that store consent differently from the plugin's default checkout block.

**Example**

```php
add_filter(
    'wwu_wb_exemption_consent',
    function ( array $consent, $order, array $item, string $reason ) {
        if ( 'digital_content' === $reason ) {
            // Pull consent from a custom order meta key.
            $consent['granted'] = (bool) $order->items[0]['_my_digital_consent'] ?? false;
        }
        return $consent;
    },
    10,
    4
);
```

---

### `wwu_wb_excluded_product_ids`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Domain/ExemptionResolver.php:48` |

**Signature**

```php
apply_filters( 'wwu_wb_excluded_product_ids', array $filtered, NormalizedOrder $order )
```

| Param | Type | Description |
|---|---|---|
| `$filtered` | `array` | Integer product IDs already excluded via admin settings (cast with `intval`). |
| `$order` | `NormalizedOrder` | The normalized order. |

**Purpose.** Back-compat / legacy filter to add product IDs to the exemption list at evaluation time, supplementing (or replacing) the admin-panel exclusion list. Returned IDs are merged into the exemption check; any product ID present in the list causes that line item to be evaluated as exempt.

**Example**

```php
// Exclude a hardcoded product from the withdrawal flow.
add_filter(
    'wwu_wb_excluded_product_ids',
    function ( array $ids, $order ) {
        $ids[] = 12345; // e.g. a "donation" product.
        return $ids;
    },
    10,
    2
);
```

---

### `wwu_wb_consent_text`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Domain/ConsentText.php:80` |

**Signature**

```php
apply_filters( 'wwu_wb_consent_text', string $text, string $kind, string $reason_id )
```

| Param | Type | Description |
|---|---|---|
| `$text` | `string` | The generated consent wording shown at checkout. |
| `$kind` | `string` | One of the `ExceptionTypes::CONSENT_*` constants (e.g. `'digital_content_ack'`). |
| `$reason_id` | `string` | The Art. 59 reason ID this text belongs to. |

**Purpose.** Replaces the statutory consent/acknowledgement text injected into the checkout for a given exception kind. Use this for jurisdictional translations, brand-voice rewrites, or to include a link to your own terms document.

**Example**

```php
add_filter(
    'wwu_wb_consent_text',
    function ( string $text, string $kind, string $reason_id ) {
        if ( 'digital_content_ack' === $kind ) {
            return __( 'I acknowledge that delivery begins immediately and I lose my right of withdrawal.', 'my-theme' );
        }
        return $text;
    },
    10,
    3
);
```

---

## 6. Rendering / surfaces

### `wwu_wb_template_path`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Frontend/Template.php:88` |

**Signature**

```php
apply_filters( 'wwu_wb_template_path', string $path, string $name, array $args )
```

| Param | Type | Description |
|---|---|---|
| `$path` | `string` | Absolute filesystem path to the template file the plugin will load. |
| `$name` | `string` | Template name / slug (e.g. `'withdrawal-button'`, `'confirmation-modal'`). |
| `$args` | `array` | Variables passed into the template scope. |

**Purpose.** Overrides a template with a file from your theme or plugin. The standard WordPress template-override pattern: place a file at `get_stylesheet_directory() . '/wwu-withdrawal-button/' . $name . '.php'` and return that path here.

**Example**

```php
add_filter(
    'wwu_wb_template_path',
    function ( string $path, string $name, array $args ) {
        $theme_file = get_stylesheet_directory() . '/wwu-withdrawal-button/' . $name . '.php';
        return file_exists( $theme_file ) ? $theme_file : $path;
    },
    10,
    3
);
```

---

### `wwu_wb_force_enqueue_frontend`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Frontend/Assets.php:171` |

**Signature**

```php
apply_filters( 'wwu_wb_force_enqueue_frontend', bool $enqueue )
```

| Param | Type | Description |
|---|---|---|
| `$enqueue` | `bool` | Whether to force-enqueue the plugin's frontend CSS/JS on the current page. Default: `false` (enqueue only on pages where the plugin detects it is needed). |

**Purpose.** Forces the plugin's frontend assets to load even when the auto-detection heuristic decides the current page does not need them — useful for custom page builders or AJAX-loaded order pages that the plugin cannot detect automatically.

**Example**

```php
// Force enqueue on a custom "my-orders" page template.
add_filter(
    'wwu_wb_force_enqueue_frontend',
    function ( bool $enqueue ) {
        return $enqueue || is_page_template( 'my-orders.php' );
    }
);
```

---

### `wwu_wb_order_admin_url`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Admin/RequestsDashboard.php:325` |

**Signature**

```php
apply_filters( 'wwu_wb_order_admin_url', string $url, string $platform, string $order_ref )
```

| Param | Type | Description |
|---|---|---|
| `$url` | `string` | Admin URL for the order edit screen as generated by the adapter. |
| `$platform` | `string` | Adapter key (e.g. `'woocommerce'`, `'fluentcart'`). |
| `$order_ref` | `string` | Order reference. |

**Purpose.** Overrides the clickable order link shown in the admin Requests Dashboard. Use this when you have a custom admin order screen or a headless back-office URL.

**Example**

```php
add_filter(
    'wwu_wb_order_admin_url',
    function ( string $url, string $platform, string $order_ref ) {
        if ( 'woocommerce' === $platform ) {
            return 'https://erp.example.com/orders/' . urlencode( $order_ref );
        }
        return $url;
    },
    10,
    3
);
```

---

### `wwu_wb_exemption_note_text`

| | |
|---|---|
| **Type** | `filter` |
| **Since** | `1.0.0-alpha.43` |
| **Fire site** | `src/Frontend/ExemptionNoteRenderer.php` |

**Signature**

```php
apply_filters( 'wwu_wb_exemption_note_text', string $text, array $reason_ids, \WWU\WithdrawalButton\Platform\NormalizedOrder $order )
```

| Param | Type | Description |
|---|---|---|
| `$text` | `string` | The resolved "why exempt" note (HTML). Either the built-in copy naming the matched Art. 59 exception(s) + legal reference, or the merchant override `wwu_wb_settings['custom_exemption_note']`. |
| `$reason_ids` | `string[]` | The per-item statutory reason ids that resolved (e.g. `['59_o']`). |
| `$order` | `NormalizedOrder` | The exempt order being rendered. |

**Purpose.** Overrides the consumer-facing note that explains *why* the withdrawal button is absent on a fully-exempt order (digital with immediate access, service performed, custom-made…). Return `''` to suppress the note entirely. Fail-safe: the renderer only runs when the order is genuinely exempt (`no_withdrawal_right`) and the reasons are non-seal-based — so this filter never fires on ordinary, out-of-scope, renewal or B2B orders.

**Example**

```php
add_filter(
    'wwu_wb_exemption_note_text',
    function ( string $text, array $reason_ids, $order ) {
        if ( in_array( '59_o', $reason_ids, true ) ) {
            return '<p>' . esc_html__( 'Digital content: you agreed to immediate access and waived withdrawal at checkout.', 'my-textdomain' ) . '</p>';
        }
        return $text;
    },
    10,
    3
);
```

---

## 7. Guest access & security

### `wwu_wb_client_ip`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Security/ClientInfo.php:36` |

**Signature**

```php
apply_filters( 'wwu_wb_client_ip', string $raw )
```

| Param | Type | Description |
|---|---|---|
| `$raw` | `string` | The `REMOTE_ADDR` value from the server superglobal. |

**Purpose.** Replaces the detected client IP before it is used for rate-limiting guest access. Use this when your infrastructure (reverse proxy, CDN, load balancer) places the real client IP in a non-standard header.

**Example**

```php
// Trust the Cloudflare CF-Connecting-IP header.
add_filter( 'wwu_wb_client_ip', function ( string $ip ) {
    $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    return filter_var( $cf, FILTER_VALIDATE_IP ) ? $cf : $ip;
} );
```

---

### `wwu_wb_rate_limit_max_attempts`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Frontend/GuestAccess.php:74` |
| **Docblock** | None in source — parameters inferred from context. |

**Signature**

```php
apply_filters( 'wwu_wb_rate_limit_max_attempts', int $max )
```

| Param | Type | Description |
|---|---|---|
| `$max` | `int` | Maximum allowed guest-access attempts within the rate-limit window. Default: `10`. |

**Purpose.** Changes how many times a guest IP may attempt to access the withdrawal form within the configured window before being blocked. Decrease for high-risk storefronts; increase for stores with many legitimate guests sharing an IP (e.g. corporate NAT).

**Example**

```php
// Allow up to 20 attempts per window.
add_filter( 'wwu_wb_rate_limit_max_attempts', function () {
    return 20;
} );
```

---

### `wwu_wb_rate_limit_window_seconds`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Frontend/GuestAccess.php:75` |
| **Docblock** | None in source — parameters inferred from context. |

**Signature**

```php
apply_filters( 'wwu_wb_rate_limit_window_seconds', int $seconds )
```

| Param | Type | Description |
|---|---|---|
| `$seconds` | `int` | Length of the rate-limit sliding window in seconds. Default: `300` (5 minutes). |

**Purpose.** Changes the time window over which guest attempts are counted. Shorten to react faster to bursts; lengthen to spread a lower attempt budget over a longer period.

**Example**

```php
// Use a 10-minute window instead of 5.
add_filter( 'wwu_wb_rate_limit_window_seconds', function () {
    return 600;
} );
```

---

## 8. Durable medium / evidence

### `wwu_wb_exemption_confirmation_html`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Mail/ExemptionConfirmation.php:180` |

**Signature**

```php
apply_filters( 'wwu_wb_exemption_confirmation_html', string $out, string $number, array $entries )
```

| Param | Type | Description |
|---|---|---|
| `$out` | `string` | The assembled HTML for the exemption confirmation email body. |
| `$number` | `string` | Human-readable order number. |
| `$entries` | `array` | Array of exemption entries (reason, product name, consent timestamp, etc.). |

**Purpose.** Overrides the HTML body of the Art. 59 exemption confirmation email sent to the consumer. Use this to apply your brand template, translate the email into a non-supported language, or embed a link to a PDF receipt.

**Example**

```php
add_filter(
    'wwu_wb_exemption_confirmation_html',
    function ( string $html, string $number, array $entries ) {
        ob_start();
        // load your own Twig/Blade/blade-like template here
        include get_stylesheet_directory() . '/emails/exemption-confirmation.php';
        return ob_get_clean() ?: $html;
    },
    10,
    3
);
```

---

### `wwu_wb_receipt_sent` _(action)_

Documented in [§ 10 Lifecycle & log](#10-lifecycle--log) because it fires as part of the post-confirmation flow. See `wwu_wb_receipt_sent` there.

---

## 9. Timestamping

### `wwu_wb_timestamp_provider`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Timestamp/TimestampService.php:73` |

**Signature**

```php
apply_filters( 'wwu_wb_timestamp_provider', TimestampProvider $provider, string $key )
```

| Param | Type | Description |
|---|---|---|
| `$provider` | `TimestampProvider` | The active timestamping provider instance. |
| `$key` | `string` | Provider key selected in admin settings (e.g. `'ots'`, `'rfc3161'`, `'none'`). |

**Purpose.** Swaps in a custom `TimestampProvider` implementation. Implement the `TimestampProvider` interface and return it here to integrate any trusted third-party timestamping authority (RFC 3161, QTSA, blockchain, etc.) not built into the plugin.

**Example**

```php
use MyPlugin\MyTimestampProvider;
use WWU\WithdrawalButton\Timestamp\TimestampProvider;

add_filter(
    'wwu_wb_timestamp_provider',
    function ( TimestampProvider $provider, string $key ) {
        if ( 'my_authority' === $key ) {
            return new MyTimestampProvider();
        }
        return $provider;
    },
    10,
    2
);
```

---

### `wwu_wb_ots_calendars`

| | |
|---|---|
| **Type** | `filter` |
| **Fire site** | `src/Timestamp/OpenTimestampsProvider.php:59` |

**Signature**

```php
apply_filters( 'wwu_wb_ots_calendars', string[] $calendars )
```

| Param | Type | Description |
|---|---|---|
| `$calendars` | `string[]` | URLs of OpenTimestamps calendar servers used for anchoring. Default: the OTS public calendar set. |

**Purpose.** Customizes the OpenTimestamps calendar servers used when the OTS timestamping provider is active. Add a private or organizational calendar, or remove public ones to comply with data-residency requirements.

**Example**

```php
// Use only your organization's private OTS calendar.
add_filter( 'wwu_wb_ots_calendars', function () {
    return array( 'https://ots.example.com/' );
} );
```

---

### `wwu_wb_timestamp_anchored` _(action)_

| | |
|---|---|
| **Type** | `action` |
| **Fire site** | `src/Timestamp/TimestampService.php:128` |

**Signature**

```php
do_action( 'wwu_wb_timestamp_anchored', int $log_id, string $row_hash, string $provider )
```

| Param | Type | Description |
|---|---|---|
| `$log_id` | `int` | ID of the immutable audit log row that was anchored. |
| `$row_hash` | `string` | SHA-256 hex digest of the serialized log row that was submitted for timestamping. |
| `$provider` | `string` | Provider key that produced the anchor (e.g. `'ots'`, `'rfc3161'`). |

**Purpose.** Fires after the timestamping provider successfully anchors a log row. Hook here to send the anchor reference to an external system, update a compliance database, or trigger a notification to the consumer.

**Example**

```php
add_action(
    'wwu_wb_timestamp_anchored',
    function ( int $log_id, string $row_hash, string $provider ) {
        // Forward the anchor confirmation to an external audit service.
        wp_remote_post( 'https://audit.example.com/anchor', array(
            'body' => array(
                'log_id'   => $log_id,
                'hash'     => $row_hash,
                'provider' => $provider,
            ),
        ) );
    },
    10,
    3
);
```

---

## 10. Lifecycle & log

### `wwu_wb_withdrawal_confirmed` _(action)_

| | |
|---|---|
| **Type** | `action` |
| **Fire site** | `src/Domain/WithdrawalService.php:198` |

**Signature**

```php
do_action(
    'wwu_wb_withdrawal_confirmed',
    string           $request_uid,
    NormalizedOrder  $order,
    WithdrawalRequest $req,
    int              $log_id,
    OrderDataSource  $adapter
)
```

| Param | Type | Description |
|---|---|---|
| `$request_uid` | `string` | UUID that uniquely identifies this withdrawal request (use as idempotency key). |
| `$order` | `NormalizedOrder` | The platform-agnostic order at the time of confirmation. |
| `$req` | `WithdrawalRequest` | The full withdrawal request value object (reason, items, timestamps, etc.). |
| `$log_id` | `int` | ID of the immutable audit log row written for this confirmation. |
| `$adapter` | `OrderDataSource` | The platform adapter that owns the order. |

**Purpose.** The main integration hook — fires once per successful withdrawal confirmation, after the log row is written and the platform order status is updated. Use this to trigger refunds, notify third-party systems, send custom emails, or start a fulfilment-reversal workflow.

**Example**

```php
use WWU\WithdrawalButton\Platform\NormalizedOrder;

add_action(
    'wwu_wb_withdrawal_confirmed',
    function ( string $uid, NormalizedOrder $order, $req, int $log_id, $adapter ) {
        // Trigger an automatic refund via WooCommerce.
        if ( 'woocommerce' === $order->platform ) {
            $wc_order = wc_get_order( (int) $order->order_ref );
            if ( $wc_order ) {
                wc_create_refund( array(
                    'order_id' => $wc_order->get_id(),
                    'reason'   => 'Consumer right of withdrawal',
                    'amount'   => $wc_order->get_total(),
                ) );
            }
        }
    },
    10,
    5
);
```

---

### `wwu_wb_subscription_cancel_result` _(action)_

| | |
|---|---|
| **Type** | `action` |
| **Fire site** | `src/Domain/WithdrawalService.php:278` |

**Signature**

```php
do_action(
    'wwu_wb_subscription_cancel_result',
    bool            $cancelled,
    string          $sub_ref,
    NormalizedOrder $order,
    OrderDataSource $adapter
)
```

| Param | Type | Description |
|---|---|---|
| `$cancelled` | `bool` | Whether the platform adapter successfully cancelled the subscription. |
| `$sub_ref` | `string` | The subscription reference ID on the platform. |
| `$order` | `NormalizedOrder` | The parent order that triggered the withdrawal. |
| `$adapter` | `OrderDataSource` | The platform adapter (WooCommerce, FluentCart, etc.). |

**Purpose.** Fires after the service attempts to cancel a subscription linked to a withdrawn order. Hook here to handle success or failure — for example, flag a subscription for manual review when `$cancelled` is `false`.

**Example**

```php
add_action(
    'wwu_wb_subscription_cancel_result',
    function ( bool $cancelled, string $sub_ref, $order, $adapter ) {
        if ( ! $cancelled ) {
            // Log a task for manual cancellation review.
            error_log( sprintf(
                'WWU Withdrawal: failed to cancel subscription %s for order %s — manual review required.',
                $sub_ref,
                $order->order_ref
            ) );
        }
    },
    10,
    4
);
```

---

### `wwu_wb_receipt_sent` _(action)_

| | |
|---|---|
| **Type** | `action` |
| **Fire site** | `src/DurableMedium/ConfirmationDispatcher.php:194` |

**Signature**

```php
do_action( 'wwu_wb_receipt_sent', string $request_uid, string $channel, array $data )
```

| Param | Type | Description |
|---|---|---|
| `$request_uid` | `string` | UUID of the withdrawal request this receipt covers. |
| `$channel` | `string` | Delivery channel used: `'email'` (confirmation email only) or `'email+pdf'` (email with PDF attachment). |
| `$data` | `array` | Receipt data array passed to the dispatcher (order ref, consumer email, timestamp, etc.). |

**Purpose.** Fires after the durable-medium confirmation (email / email + PDF) is successfully dispatched to the consumer. Hook here to log the dispatch event, push a copy to a document-management system, or send an additional channel-specific notification.

**Example**

```php
add_action(
    'wwu_wb_receipt_sent',
    function ( string $uid, string $channel, array $data ) {
        if ( 'email+pdf' === $channel ) {
            // Archive the PDF in your DMS.
            my_dms_archive( $uid, $data );
        }
    },
    10,
    3
);
```

---

### `wwu_wb_log_written` _(action)_

| | |
|---|---|
| **Type** | `action` |
| **Fire site** | `src/Storage/LogRepository.php:119` |

**Signature**

```php
do_action( 'wwu_wb_log_written', int $id, string $event, array $row )
```

| Param | Type | Description |
|---|---|---|
| `$id` | `int` | Primary key of the newly inserted immutable audit log row. |
| `$event` | `string` | Event slug written to the log (e.g. `'withdrawal_confirmed'`, `'exemption_applied'`). |
| `$row` | `array` | Full serialized row as stored in the audit table. |

**Purpose.** Fires after every write to the immutable audit log. Use this to replicate audit events to an external SIEM, append to an immutable ledger, or trigger compliance notifications. The row is already persisted when this fires — do not attempt to modify it.

**Example**

```php
add_action(
    'wwu_wb_log_written',
    function ( int $id, string $event, array $row ) {
        // Ship every audit event to a remote log sink.
        wp_remote_post( 'https://siem.example.com/events', array(
            'body'    => wp_json_encode( array( 'id' => $id, 'event' => $event, 'row' => $row ) ),
            'headers' => array( 'Content-Type' => 'application/json' ),
            'blocking' => false,
        ) );
    },
    10,
    3
);
```

---

## 11. At a glance

| Hook | Type | One-line purpose |
|------|------|-----------------|
| `wwu_wb_admin_capability` | filter | Override the WordPress capability required for all admin/REST access. |
| `wwu_wb_applicability_decision` | filter | Replace the computed applicability decision for an order. |
| `wwu_wb_client_ip` | filter | Override the detected client IP used for guest rate-limiting. |
| `wwu_wb_compute_deadline` | filter | Replace the computed withdrawal deadline `DateTimeImmutable`. |
| `wwu_wb_consent_text` | filter | Replace the statutory consent wording shown at checkout. |
| `wwu_wb_edd_order_has_vat_number` | filter | Override B2B/VAT detection for EDD orders. |
| `wwu_wb_eligible_statuses` | filter | Add or remove order statuses that make the button available. |
| `wwu_wb_exception_types` | filter | Register custom Art. 59 exception types or modify existing ones. |
| `wwu_wb_excluded_product_ids` | filter | Add product IDs to the exemption list at evaluation time. |
| `wwu_wb_exemption_confirmation_html` | filter | Replace the HTML body of the Art. 59 exemption confirmation email. |
| `wwu_wb_exemption_consent` | filter | Override captured consent data for a specific exemption reason. |
| `wwu_wb_exemption_note_text` | filter | Override the consumer "why exempt" note on fully-exempt orders. |
| `wwu_wb_fluentcart_native_active` | filter | Override whether the FluentCart adapter is treated as active. |
| `wwu_wb_fluentcart_product_category_ids` | filter | Override category term IDs for a FluentCart product. |
| `wwu_wb_force_enqueue_frontend` | filter | Force-enqueue frontend assets on pages not auto-detected. |
| `wwu_wb_in_scope_countries` | filter | Add or remove countries from the mandatory in-scope set. |
| `wwu_wb_order_admin_url` | filter | Override the admin order-edit URL shown in the Requests Dashboard. |
| `wwu_wb_order_has_vat_number` | filter | Override B2B/VAT detection for WooCommerce orders. |
| `wwu_wb_order_is_renewal` | filter | Override subscription-renewal detection (WooCommerce, FluentCart, EDD). |
| `wwu_wb_ots_calendars` | filter | Customize OpenTimestamps calendar server URLs. |
| `wwu_wb_platform_adapters` | filter | Register a custom platform adapter. |
| `wwu_wb_rate_limit_max_attempts` | filter | Change the guest rate-limit attempt cap. |
| `wwu_wb_rate_limit_window_seconds` | filter | Change the guest rate-limit sliding window duration. |
| `wwu_wb_template_path` | filter | Override a frontend template file path. |
| `wwu_wb_timestamp_provider` | filter | Swap in a custom timestamping provider implementation. |
| `wwu_wb_webhook_payload` | filter | Filter the outbound automations webhook payload before signing/sending. |
| `wwu_wb_withdrawal_window_days` | filter | Change the statutory withdrawal period (default: 14 days). |
| `wwu_wb_log_written` | action | Fires after every audit log write — replicate events to external sinks. |
| `wwu_wb_receipt_sent` | action | Fires after the durable-medium receipt is dispatched to the consumer. |
| `wwu_wb_subscription_cancel_result` | action | Fires after subscription cancellation is attempted. |
| `wwu_wb_timestamp_anchored` | action | Fires after a log row is successfully anchored by the timestamp provider. |
| `wwu_wb_webhook_delivered` | action | Fires after each outbound webhook delivery attempt (success or failure). |
| `wwu_wb_withdrawal_confirmed` | action | Fires once per successful withdrawal confirmation — main integration hook. |

---

## 12. Automations (REST API & webhook)

The read-only REST API + outbound webhook (since `1.0.0-alpha.44`). Full endpoint
reference, auth and signature-verification examples:
[`wwu-wb-rest-api-REFERENCE.md`](./wwu-wb-rest-api-REFERENCE.md). The two hooks
below are the extension points for the **webhook**.

### `wwu_wb_webhook_payload`

| | |
|---|---|
| **Type** | `filter` |
| **Since** | `1.0.0-alpha.44` |
| **Fire site** | `src/Api/WebhookDispatcher.php` |

**Signature**

```php
apply_filters( 'wwu_wb_webhook_payload', array $payload, string $request_uid )
```

| Param | Type | Description |
|---|---|---|
| `$payload` | `array` | The default JSON payload: `event, request_uid, platform, order_ref, order_number, consumer_email, status, country, within_window, created_at, row_hash`. **Never contains the raw IP.** |
| `$request_uid` | `string` | The confirmed request UID. |

**Purpose.** Add or reshape fields sent to your endpoint (e.g. attach an internal customer id, drop a field you don't need). The (possibly filtered) payload is what gets signed — the `X-WWU-WB-Signature` is computed over the final body, so your receiver verifies whatever you return here. Do **not** add the consumer IP back in (it is intentionally excluded).

**Example**

```php
add_filter(
    'wwu_wb_webhook_payload',
    function ( array $payload, string $request_uid ) {
        $payload['source'] = 'my-store';
        return $payload;
    },
    10,
    2
);
```

---

### `wwu_wb_webhook_delivered`

| | |
|---|---|
| **Type** | `action` |
| **Since** | `1.0.0-alpha.44` |
| **Fire site** | `src/Api/WebhookDispatcher.php` |

**Signature**

```php
do_action( 'wwu_wb_webhook_delivered', bool $ok, int $code, string $request_uid, string $delivery_id )
```

| Param | Type | Description |
|---|---|---|
| `$ok` | `bool` | Whether the receiver returned a `2xx`. |
| `$code` | `int` | HTTP status code (`0` on a transport error). |
| `$request_uid` | `string` | The request UID this delivery was for. |
| `$delivery_id` | `string` | The per-attempt uuid (the `X-WWU-WB-Delivery` header). |

**Purpose.** Observe webhook delivery outcomes — record them, alert on repeated failures, or trigger a fallback. Fires once per attempt (the dispatcher retries once on a transport error, so a failed delivery can fire twice with the same `request_uid` but different `delivery_id`).

**Example**

```php
add_action(
    'wwu_wb_webhook_delivered',
    function ( bool $ok, int $code, string $request_uid, string $delivery_id ) {
        if ( ! $ok ) {
            error_log( "WWU-WB webhook failed for {$request_uid} (HTTP {$code}, delivery {$delivery_id})" );
        }
    },
    10,
    4
);
```
