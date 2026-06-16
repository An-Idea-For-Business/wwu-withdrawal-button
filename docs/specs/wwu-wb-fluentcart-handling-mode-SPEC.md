# SPEC — Configurable FluentCart handling (Auto / Always / Off)

- **Slug:** wwu-wb
- **Target version:** 1.0.0-alpha.41
- **Status:** Implemented (2026-06-15)
- **Trigger:** FluentCart replied (2026-06-15) that they will ship a **dedicated native withdrawal
  add-on**. We must not render a *second* withdrawal button on FluentCart orders when their add-on is
  present — so our FluentCart handling becomes configurable, with an automatic "step aside" default.

## Goal
Let the merchant decide whether this plugin renders its consumer-facing FluentCart withdrawal surfaces,
and have it **defer automatically** to FluentCart's own add-on when that add-on is installed — without a
plugin update, and without ever stranding a withdrawal that was already recorded through us.

## Setting
- Option: `wwu_wb_settings['fluentcart_mode']` — enum `auto | always | off`, default **`auto`**.
- Seeded in `Install::seed_default_options()`; sanitised in `SettingsPage::handle_save()` via
  `Sanitizer::enum( …, ['auto','always','off'], 'auto' )`.
- UI: new **Settings → FluentCart** section (`SettingsPage::render_platforms_section()`) — a select with
  the three modes, a `?` tooltip, a one-line description, a collapsible "Show example", and a contextual
  banner reporting whether FluentCart is active and whether its native add-on was detected (Standard #12).

## Decision logic — `FluentCartAdapter` (static, pure)
```
mode(): 'auto' | 'always' | 'off'          // cached option read, whitelisted, default 'auto'
native_addon_active(): bool                // false by default; filter wwu_wb_fluentcart_native_active
should_render(): bool
    'off'    → false
    'always' → true
    'auto'   → ! native_addon_active()
```
`native_addon_active()` ships **false** (FluentCart's add-on detection signal — class/constant — is not yet
published; pending their team). It is **filterable** so the real check can be wired the moment the add-on
ships, or by an integrator, without a release.

## Where the gate is applied (and where it is NOT)
`should_render()` gates the **four consumer entry points only**:

| Surface | File | Behaviour when suppressed |
|---|---|---|
| Portal button | `Frontend/FluentCartPortal::inject()` | returns the sections unchanged (no button) |
| Checkout consent | `Frontend/FluentCartCheckoutConsent::capture()` | returns early (no consent field) |
| E-mail link tag | `Mail/FluentCartWithdrawalTag` | returns `''` (no stray `{{wwu.recesso_url}}`) |
| Public form list | `Frontend/EligibleOrders::collect_fluentcart()` | returns `[]` (no FluentCart rows) |

**Deliberately NOT gated** (so existing requests are never stranded):
- `PlatformRegistry::is_active()/get()/active()/resolve_for_order()` keep their pure **presence** meaning.
- The **admin Requests dashboard** (`RequestsDashboard`) still loads the FluentCart adapter for
  already-recorded requests.
- In-flight **durable-medium confirmation** (`ConfirmationDispatcher`) still completes.
- A direct withdrawal **submission** for a FluentCart order is still honoured (the consumer's legal right);
  suppression removes the *offer/UI*, not the right.

### Why not gate `is_active()`?
Conflating "FluentCart present" with "we handle it" would blind the admin dashboard + in-flight
confirmation (both reach the adapter through `get('fluentcart')`), stranding recorded withdrawals. Keeping
the gate at the four surfaces is surgical and zero-blast-radius for WooCommerce/EDD.

## Tests
`Debug/SmokeTests::suite_fluentcart()` adds 8 assertions (save/restore the option so the live site is
unchanged): mode whitelist, native default false + filterable true, and `should_render()` for
off / always-over-native / auto-plain / auto-defers.

## Verification
- `php -l` clean (7 files). Class scanner clean (91 files, no bare-class fatal). PHPStan level 2: no errors.
- Live smoke (`wwu-tools/wwu-rest-test.php wwu-wb fluentcart`) validates once alpha.41 is deployed.

## Follow-ups
1. Ask FluentCart for the **native add-on detection signal** (class/constant) → wire it as the default in
   `native_addon_active()` (replacing the `false` placeholder) so Auto truly auto-defers out of the box.
2. i18n: the new admin strings are added to the `.pot` and translated into IT/DE/FR/ES/SV.
