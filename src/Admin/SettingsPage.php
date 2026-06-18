<?php
/**
 * Settings page.
 *
 * F0 surface: master enable toggle + debug audience configuration (so an admin
 * can turn debug on and use the /debug/* REST endpoints + Inspector). Later
 * phases extend this page with labels, applicability, exclusions, timestamp
 * provider and retention sections.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Admin;

use WWU\WithdrawalButton\Api\Webhook;
use WWU\WithdrawalButton\Core\Settings;
use WWU\WithdrawalButton\Debug\Audience;
use WWU\WithdrawalButton\Frontend\Template;
use WWU\WithdrawalButton\Mail\WooAckEmail;
use WWU\WithdrawalButton\REST\Authentication;
use WWU\WithdrawalButton\Security\OutboundUrlGuard;
use WWU\WithdrawalButton\Security\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page handler.
 */
final class SettingsPage {

	/**
	 * Nonce action for the settings form.
	 *
	 * @var string
	 */
	private const NONCE = 'wwu_wb_save_settings';

	/**
	 * Nonce action for the email-preview link.
	 *
	 * @var string
	 */
	private const PREVIEW_NONCE = 'wwu_wb_preview_email';

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Authentication::capability() ) ) {
			return;
		}

		$settings = wp_parse_args( (array) get_option( 'wwu_wb_settings', array() ), array( 'enabled' => false ) );
		$debug    = Audience::config();
		$saved    = isset( $_GET['wwu_wb_saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap wwu-wb-wrap">';
		echo '<h1>' . esc_html__( 'WWU Withdrawal Button — Settings', 'wwu-withdrawal-button' ) . '</h1>';

		if ( $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wwu-withdrawal-button' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wwu_wb_save_settings" />';
		wp_nonce_field( self::NONCE );

		echo '<h2>' . esc_html__( 'General', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Enable withdrawal function', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<label><input type="checkbox" name="enabled" value="1" ' . checked( ! empty( $settings['enabled'] ), true, false ) . ' /> ';
		echo esc_html__( 'Show the withdrawal button to eligible consumers.', 'wwu-withdrawal-button' ) . '</label>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: go-live date. */
				__( 'Mandatory for EU/EEA consumers on contracts concluded on or after %s.', 'wwu-withdrawal-button' ),
				WWU_WB_GO_LIVE_DATE
			)
		) . '</p>';
		echo '</td></tr>';
		echo '</tbody></table>';

		$this->render_applicability_section( $settings );
		$this->render_subscriptions_section( $settings );
		$this->render_platforms_section( $settings );
		$this->render_guidance_section( $settings );
		$this->render_clauses_section( $settings );
		$this->render_exemptions_section();
		$this->render_receipt_section( $settings );
		$this->render_integrations_section();

		echo '<h2>' . esc_html__( 'Debug', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Enable debug', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<label><input type="checkbox" name="debug_enabled" value="1" ' . checked( ! empty( $debug['enabled'] ), true, false ) . ' /> ';
		echo esc_html__( 'Collect runtime diagnostics and expose the /debug REST endpoints + Inspector.', 'wwu-withdrawal-button' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Audience', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<select name="debug_mode">';
		$modes = array(
			Audience::MODE_ALL_ADMINS       => __( 'All admins', 'wwu-withdrawal-button' ),
			Audience::MODE_SPECIFIC_ROLES   => __( 'Specific roles', 'wwu-withdrawal-button' ),
			Audience::MODE_SPECIFIC_USERS   => __( 'Specific users', 'wwu-withdrawal-button' ),
			Audience::MODE_CURRENT_USER_ONLY => __( 'Current user only', 'wwu-withdrawal-button' ),
		);
		foreach ( $modes as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $debug['mode'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Console level', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<select name="debug_console_level">';
		foreach ( array( 'silent', 'error', 'warn', 'info', 'debug' ) as $level ) {
			echo '<option value="' . esc_attr( $level ) . '" ' . selected( $debug['console_level'], $level, false ) . '>' . esc_html( $level ) . '</option>';
		}
		echo '</select>';
		echo '</td></tr>';

		echo '</tbody></table>';

		$this->render_appearance_section( $settings );

		submit_button( __( 'Save settings', 'wwu-withdrawal-button' ) );
		echo '</form>';

		echo '<p style="margin-top:2em;color:#666;">' . wp_kses_post(
			sprintf(
				/* translators: %s: WebWakeUp link. */
				__( 'WWU Withdrawal Button — a free open-source compliance tool by %s, with mredodos and Matteo Alfieri (An Idea for Business).', 'wwu-withdrawal-button' ),
				'<a href="https://webwakeup.it" target="_blank" rel="noopener">WebWakeUp</a>'
			)
		) . '</p>';

		echo '</div>';
	}

	/**
	 * Render the "Where it applies" section (applicability mode + B2B).
	 *
	 * @param array $settings Current settings (for context only).
	 * @return void
	 */
	private function render_applicability_section( array $settings ): void {
		$app  = wp_parse_args(
			(array) get_option( 'wwu_wb_applicability', array() ),
			array(
				'mode'                 => 'eu_eea_only',
				'custom_countries'     => array(),
				'b2b_vat_out_of_scope' => true,
			)
		);
		$mode = (string) $app['mode'];

		echo '<h2>' . esc_html__( 'Where the button applies', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Applicability', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<select name="applicability_mode">';
		$opts = array(
			'eu_eea_only' => __( 'Only EU/EEA consumers (legal minimum — recommended)', 'wwu-withdrawal-button' ),
			'always'      => __( 'Always show it (to every consumer, also non-EU)', 'wwu-withdrawal-button' ),
			'custom_list' => __( 'Only a custom list of countries', 'wwu-withdrawal-button' ),
		);
		foreach ( $opts as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $mode, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'The button is mandatory only for EU/EEA consumers. "Always show it" is a safe superset if you prefer one consistent flow (showing it to non-EU buyers is allowed and low-risk).', 'wwu-withdrawal-button' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Custom countries', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<input type="text" name="applicability_custom" class="regular-text" value="' . esc_attr( implode( ', ', (array) $app['custom_countries'] ) ) . '" placeholder="IT, DE, FR" />';
		echo '<p class="description">' . esc_html__( 'Two-letter country codes, comma-separated. Used only with the "custom list" mode.', 'wwu-withdrawal-button' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Business (B2B) orders', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<label><input type="checkbox" name="applicability_b2b" value="1" ' . checked( ! empty( $app['b2b_vat_out_of_scope'] ), true, false ) . ' /> ';
		echo esc_html__( 'Hide the button when a VAT number was provided (treat as business / out of scope).', 'wwu-withdrawal-button' ) . '</label>';
		echo '</td></tr>';

		echo '</tbody></table>';
	}

	/**
	 * Render the "Subscriptions" section.
	 *
	 * EU law gives ONE 14-day right per contract, at conclusion (Art. 9 CRD / art. 52
	 * Cod. Consumo); a renewal does NOT restart it. So by default the button shows on
	 * the initial order only and is suppressed on renewals. Two opt-in toggles let the
	 * merchant override that (rarely needed) and auto-cancel the subscription when a
	 * consumer withdraws (refund + any pro-rata stay manual). Standard #12: each toggle
	 * ships a plain-language hint, a legal note, and a worked example.
	 *
	 * @param array $settings Current settings.
	 * @return void
	 */
	private function render_subscriptions_section( array $settings ): void {
		$treat_renewals = ! empty( $settings['treat_renewals_as_withdrawable'] );
		$auto_cancel    = ! empty( $settings['cancel_subscription_on_withdrawal'] );
		$active         = $this->subscription_plugin_active();

		echo '<h2>' . esc_html__( 'Subscriptions', 'wwu-withdrawal-button' ) . '</h2>';

		if ( '' !== $active ) {
			echo '<div class="wwu-ui-notice info" style="margin:12px 0;max-width:860px;"><p style="margin:0;">' . esc_html(
				sprintf(
					/* translators: %s: detected subscription plugin name. */
					__( 'Detected subscription plugin: %s. These settings take effect for its orders.', 'wwu-withdrawal-button' ),
					$active
				)
			) . '</p></div>';
		} else {
			echo '<p class="description" style="max-width:860px;">' . esc_html__( 'No subscription plugin detected (WooCommerce Subscriptions, FluentCart subscriptions, EDD Recurring). These settings are harmless until one is active.', 'wwu-withdrawal-button' ) . '</p>';
		}

		echo '<p class="description" style="max-width:860px;">' . wp_kses_post( __( 'EU law gives <strong>one 14-day right of withdrawal per contract</strong>, starting when the contract is concluded (Art. 9 CRD / art. 52 Cod. Consumo). A <strong>renewal does not restart it</strong>. So the button appears on the <strong>initial order only</strong> and is hidden on renewals. Withdrawal is <strong>not</strong> the same as cancelling: a consumer can always stop future renewals from their account; the withdrawal button is the statutory 14-day right that also entitles them to a refund.', 'wwu-withdrawal-button' ) ) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Renewal orders', 'wwu-withdrawal-button' )
			. ' <span class="wwu-wb-help" tabindex="0" title="' . esc_attr__( 'Leave OFF in almost all cases. A renewal continues the same contract — it has no fresh 14-day right.', 'wwu-withdrawal-button' ) . '" style="display:inline-block;width:16px;height:16px;line-height:16px;text-align:center;border-radius:50%;background:#e0e0e0;color:#333;font-size:11px;cursor:help;">?</span></th><td>';
		echo '<label><input type="checkbox" name="treat_renewals_as_withdrawable" value="1" ' . checked( $treat_renewals, true, false ) . ' /> ';
		echo esc_html__( 'Also show the withdrawal button on renewal orders.', 'wwu-withdrawal-button' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off (recommended): renewals continue the same contract and have no new 14-day right. Turn on only if your legal advice says a specific renewal restarts the right.', 'wwu-withdrawal-button' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'On withdrawal', 'wwu-withdrawal-button' )
			. ' <span class="wwu-wb-help" tabindex="0" title="' . esc_attr__( 'When a consumer withdraws from the initial order, optionally stop future renewals automatically.', 'wwu-withdrawal-button' ) . '" style="display:inline-block;width:16px;height:16px;line-height:16px;text-align:center;border-radius:50%;background:#e0e0e0;color:#333;font-size:11px;cursor:help;">?</span></th><td>';
		echo '<label><input type="checkbox" name="cancel_subscription_on_withdrawal" value="1" ' . checked( $auto_cancel, true, false ) . ' /> ';
		echo esc_html__( 'Automatically cancel the subscription when the consumer withdraws from its initial order.', 'wwu-withdrawal-button' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default. The refund and any pro-rata deduction (for service already used) always stay manual — only the future renewals are stopped. The Requests dashboard reminds you either way.', 'wwu-withdrawal-button' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"></th><td>';
		echo '<details class="wwu-wb-clause"><summary><strong>' . esc_html__( 'Show example', 'wwu-withdrawal-button' ) . '</strong></summary>';
		echo '<p class="description" style="max-width:760px;margin-top:8px;">' . esc_html__( 'A customer subscribes on 1 March (initial order). They get 14 days to withdraw → the button shows on that order until 15 March. On 1 April the subscription renews (renewal order) → no button, because the right was already exercised-or-expired on the original contract. If they no longer want the service they cancel future renewals from their account; that is separate from the 14-day withdrawal.', 'wwu-withdrawal-button' ) . '</p>';
		echo '</details>';
		echo '</td></tr>';

		echo '</tbody></table>';
	}

	/**
	 * Render the "FluentCart" section — withdrawal-handling mode (Auto / Always / Off).
	 *
	 * Auto-defers to FluentCart's own withdrawal add-on when present so consumers never
	 * see two buttons. Gates only our consumer-facing FluentCart surfaces; the admin
	 * Requests dashboard + in-flight confirmations always keep working.
	 * {@see \WWU\WithdrawalButton\Platform\FluentCartAdapter::should_render()}.
	 *
	 * @param array $settings Current settings.
	 * @return void
	 */
	private function render_platforms_section( array $settings ): void {
		$mode = (string) ( $settings['fluentcart_mode'] ?? 'auto' );
		if ( ! in_array( $mode, array( 'auto', 'always', 'off' ), true ) ) {
			$mode = 'auto';
		}
		$fc_active = class_exists( '\\FluentCart\\App\\Models\\Order' ) || function_exists( 'fluent_cart_api' );
		$fc_native = \WWU\WithdrawalButton\Platform\FluentCartAdapter::native_addon_active();

		echo '<h2>' . esc_html__( 'FluentCart', 'wwu-withdrawal-button' ) . '</h2>';

		if ( ! $fc_active ) {
			echo '<p class="description" style="max-width:860px;">' . esc_html__( 'FluentCart is not active. This setting takes effect only once FluentCart is installed.', 'wwu-withdrawal-button' ) . '</p>';
		} elseif ( $fc_native ) {
			echo '<div class="wwu-ui-notice info" style="margin:12px 0;max-width:860px;"><p style="margin:0;">' . esc_html__( "FluentCart's own withdrawal add-on was detected. In Auto mode this plugin steps aside on FluentCart orders, so customers see a single withdrawal button.", 'wwu-withdrawal-button' ) . '</p></div>';
		} else {
			echo '<div class="wwu-ui-notice info" style="margin:12px 0;max-width:860px;"><p style="margin:0;">' . esc_html__( 'FluentCart is active and this plugin handles its withdrawal button. If FluentCart later ships its own withdrawal add-on, Auto mode steps aside automatically.', 'wwu-withdrawal-button' ) . '</p></div>';
		}

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="wwu-wb-fluentcart-mode">' . esc_html__( 'Withdrawal handling', 'wwu-withdrawal-button' ) . '</label>'
			. ' <span class="wwu-wb-help" tabindex="0" title="' . esc_attr__( "Auto (recommended): show our button on FluentCart orders, but step aside automatically if FluentCart's own withdrawal add-on is installed, so consumers never see two buttons.", 'wwu-withdrawal-button' ) . '" style="display:inline-block;width:16px;height:16px;line-height:16px;text-align:center;border-radius:50%;background:#e0e0e0;color:#333;font-size:11px;cursor:help;">?</span></th><td>';

		$options = array(
			'auto'   => __( "Auto — defer to FluentCart's native add-on when present (recommended)", 'wwu-withdrawal-button' ),
			'always' => __( 'Always — keep our button on FluentCart orders regardless', 'wwu-withdrawal-button' ),
			'off'    => __( 'Off — never handle FluentCart orders', 'wwu-withdrawal-button' ),
		);
		echo '<select id="wwu-wb-fluentcart-mode" name="fluentcart_mode">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $mode, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		echo '<p class="description" style="max-width:760px;">' . esc_html__( 'Controls our consumer-facing FluentCart surfaces only: the portal button, the checkout consent capture, the withdrawal e-mail link, and the public form. The admin Requests dashboard and any in-flight confirmation always keep working, so existing requests are never stranded.', 'wwu-withdrawal-button' ) . '</p>';

		echo '<details class="wwu-wb-clause"><summary><strong>' . esc_html__( 'Show example', 'wwu-withdrawal-button' ) . '</strong></summary>';
		echo '<p class="description" style="max-width:760px;margin-top:8px;">' . esc_html__( 'FluentCart announced a dedicated withdrawal add-on. When you install it, leave this on Auto: this plugin detects it and hides its own FluentCart button so customers see only one. Pick Off to disable our FluentCart handling now, or Always to keep ours regardless of any add-on.', 'wwu-withdrawal-button' ) . '</p>';
		echo '</details>';

		echo '</td></tr>';
		echo '</tbody></table>';

		// FluentCart e-mail link helper. WooCommerce and EDD e-mails receive the
		// withdrawal link automatically (documented hooks). FluentCart exposes no hook
		// to append to its e-mail body — only the merge-tag, which the merchant inserts
		// into the template — so we surface clear one-time instructions here. Shown only
		// when we actually handle FluentCart (active + not stepping aside to a native add-on).
		$fc_we_handle = ( 'always' === $mode ) || ( 'auto' === $mode && ! $fc_native );
		if ( $fc_active && $fc_we_handle ) {
			echo '<div class="wwu-ui-notice info" style="margin:0 0 8px;max-width:860px;">';
			echo '<p style="margin:0 0 6px;"><strong>' . esc_html__( 'Add the withdrawal link to FluentCart e-mails (optional)', 'wwu-withdrawal-button' ) . '</strong></p>';
			echo '<p class="description" style="margin:0 0 8px;">' . esc_html__( 'WooCommerce and Easy Digital Downloads order e-mails get the withdrawal link automatically. FluentCart does not let plugins add content to its e-mails automatically, so to include the link in your FluentCart order e-mails add this shortcode once to your receipt template:', 'wwu-withdrawal-button' ) . '</p>';
			echo '<p style="margin:0 0 8px;"><code style="user-select:all;padding:3px 8px;background:#f0f0f1;border-radius:4px;">{{wwu.recesso_url}}</code></p>';
			echo '<details class="wwu-wb-clause"><summary><strong>' . esc_html__( 'How to add it (3 steps)', 'wwu-withdrawal-button' ) . '</strong></summary>';
			echo '<ol class="description" style="max-width:760px;margin:8px 0 0 18px;">';
			echo '<li>' . esc_html__( 'In FluentCart open Settings → Emails and edit your order receipt / order confirmation notification.', 'wwu-withdrawal-button' ) . '</li>';
			echo '<li>' . esc_html__( 'Switch the body to "Customized Body", then use the shortcode picker ({;}) above the editor — or simply paste the shortcode above where you want the link to appear.', 'wwu-withdrawal-button' ) . '</li>';
			echo '<li>' . esc_html__( 'Save. FluentCart replaces the shortcode with the consumer\'s personal, pre-authenticated withdrawal link when the e-mail is sent.', 'wwu-withdrawal-button' ) . '</li>';
			echo '</ol>';
			echo '<p class="description" style="max-width:760px;margin-top:8px;">' . esc_html__( 'Optional: customers can always reach the withdrawal from the FluentCart "Right of withdrawal" portal page and the public form, regardless of the e-mail.', 'wwu-withdrawal-button' ) . '</p>';
			echo '</details>';
			echo '</div>';
		}
	}

	/**
	 * Best-effort name of an active subscription plugin (for the contextual note).
	 *
	 * @return string Plugin name, or '' when none is detected.
	 */
	private function subscription_plugin_active(): string {
		if ( function_exists( 'wcs_get_subscriptions_for_order' ) || class_exists( '\\WC_Subscriptions' ) ) {
			return 'WooCommerce Subscriptions';
		}
		if ( class_exists( '\\FluentCart\\App\\Models\\Subscription' ) ) {
			return 'FluentCart Subscriptions';
		}
		if ( class_exists( '\\EDD_Subscription' ) ) {
			return 'EDD Recurring Payments';
		}
		return '';
	}

	/**
	 * Render the "Consumer guidance" section (window length + custom text).
	 *
	 * @param array $settings Current settings.
	 * @return void
	 */
	private function render_guidance_section( array $settings ): void {
		$days   = isset( $settings['withdrawal_window_days'] ) ? max( 14, (int) $settings['withdrawal_window_days'] ) : 14;
		$custom = isset( $settings['custom_guidance'] ) ? (string) $settings['custom_guidance'] : '';

		echo '<h2>' . esc_html__( 'Consumer guidance', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Withdrawal period (days)', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<input type="number" name="withdrawal_window_days" min="14" max="365" value="' . esc_attr( (string) $days ) . '" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'The legal minimum is 14. You may grant MORE (e.g. 30) as a voluntary extension — the figure shown to customers updates accordingly. You cannot set less than 14.', 'wwu-withdrawal-button' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Custom guidance text', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<textarea name="custom_guidance" rows="6" class="large-text" placeholder="' . esc_attr__( 'Leave empty to use the built-in explanation. Paste your own to fully replace it — e.g. to spell out exemptions for event tickets or services started immediately.', 'wwu-withdrawal-button' ) . '">' . esc_textarea( $custom ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'When set, this replaces the default "how withdrawal works" block shown to customers. Basic HTML is allowed. You are responsible for its legal accuracy — keep it truthful and do not discourage withdrawal.', 'wwu-withdrawal-button' ) . '</p>';
		echo '<p class="description">' . wp_kses_post( __( 'For products with no right of withdrawal (e.g. dated event tickets, immediate-access digital content), the proper fix is to exempt them per Art. 59 — see the upcoming Exemptions feature. Do not simply hide the button without the legal conditions.', 'wwu-withdrawal-button' ) ) . '</p>';
		echo '</td></tr>';

		// Custom exemption note (why the button is absent on Art. 59 exempt orders).
		$custom_note = isset( $settings['custom_exemption_note'] ) ? (string) $settings['custom_exemption_note'] : '';
		echo '<tr><th scope="row">' . esc_html__( 'Custom exemption note', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<textarea name="custom_exemption_note" rows="4" class="large-text" placeholder="' . esc_attr__( 'Leave empty to use the built-in note. When set, this replaces the default text shown to consumers when an order is exempt under Art. 59 (e.g. digital content with immediate access) — explaining why the withdrawal button is absent.', 'wwu-withdrawal-button' ) . '">' . esc_textarea( $custom_note ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Shown to the consumer instead of the default "The right of withdrawal does not apply …" message. Basic HTML allowed. The note only appears when the order is confirmed exempt by the Art. 59 rules you configured — never on ordinary orders.', 'wwu-withdrawal-button' ) . '</p>';
		echo '<details style="margin-top:6px;"><summary style="cursor:pointer;color:#2271b1;">' . esc_html__( 'Show default note example', 'wwu-withdrawal-button' ) . '</summary>';
		echo '<blockquote style="margin:8px 0;padding:8px 12px;border-left:4px solid #c3c4c7;color:#3c434a;">';
		echo esc_html__( 'The right of withdrawal does not apply to this order: every item falls under a statutory exception to the 14-day right (Digital content with immediate access — Art. 16(1)(m) CRD / Art. 59(1)(o) CdC), which you expressly agreed to at checkout.', 'wwu-withdrawal-button' );
		echo '</blockquote></details>';
		echo '</td></tr>';

		echo '</tbody></table>';
	}

	/**
	 * Render the "Legal clauses" section: editable overrides for the ready-to-paste
	 * pre-contractual / terms / privacy clauses, for the current admin language.
	 * An empty field uses the built-in sample template; saved text replaces it
	 * everywhere (Compliance page + [wwu_wb_info] shortcode), without the
	 * "sample text" disclaimer. Stored in the wwu_wb_clauses option per type+lang.
	 *
	 * @param array $settings Current settings (unused; reads its own option).
	 * @return void
	 */
	private function render_clauses_section( array $settings ): void {
		unset( $settings );
		$lang      = strtolower( substr( determine_locale(), 0, 2 ) );
		$overrides = (array) get_option( \WWU\WithdrawalButton\Legal\ClauseLibrary::OPTION, array() );

		$labels = array(
			'precontractual'  => __( 'Pre-contractual information', 'wwu-withdrawal-button' ),
			'terms'           => __( 'General terms ("How to withdraw")', 'wwu-withdrawal-button' ),
			'privacy'         => __( 'Privacy policy (withdrawal log)', 'wwu-withdrawal-button' ),
			'consent_privacy' => __( 'Privacy policy (exemption-consent evidence)', 'wwu-withdrawal-button' ),
		);

		echo '<h2>' . esc_html__( 'Legal clauses', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<p class="description" style="max-width:60em;">' . esc_html(
			sprintf(
				/* translators: %s: two-letter language code. */
				__( 'Customise the ready-to-paste legal clauses (language: %s). Leave a field empty to use the built-in sample template. When you type your own wording it replaces the default everywhere — the Compliance page and the [wwu_wb_info] shortcode — and the "sample text" note is dropped. This is your own text: have your lawyer review it.', 'wwu-withdrawal-button' ),
				strtoupper( $lang )
			)
		) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( \WWU\WithdrawalButton\Legal\ClauseLibrary::types() as $type ) {
			$label   = isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
			$current = isset( $overrides[ $type ][ $lang ] ) ? (string) $overrides[ $type ][ $lang ] : '';
			$default = \WWU\WithdrawalButton\Legal\ClauseLibrary::default_text( $type, $lang );

			echo '<tr><th scope="row">' . esc_html( $label );
			if ( '' !== trim( $current ) ) {
				echo ' <span class="wwu-wb-badge wwu-wb-badge--ok" style="font-weight:400;">' . esc_html__( 'customised', 'wwu-withdrawal-button' ) . '</span>';
			}
			echo '</th><td>';
			echo '<textarea name="wwu_wb_clauses[' . esc_attr( $type ) . ']" rows="5" class="large-text" placeholder="' . esc_attr__( 'Leave empty to use the built-in sample template.', 'wwu-withdrawal-button' ) . '">' . esc_textarea( $current ) . '</textarea>';
			echo '<details style="margin-top:6px;"><summary style="cursor:pointer;color:#2271b1;">' . esc_html__( 'Show the built-in default (copy it to start from there)', 'wwu-withdrawal-button' ) . '</summary>';
			echo '<textarea readonly rows="5" class="large-text" style="margin-top:6px;background:#f6f7f7;">' . esc_textarea( $default ) . '</textarea>';
			echo '</details>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render the "Receipt & evidence" section.
	 *
	 * @param array $settings Current settings.
	 * @return void
	 */
	private function render_receipt_section( array $settings ): void {
		$ts       = wp_parse_args( (array) get_option( 'wwu_wb_timestamp', array() ), array( 'provider' => 'opentimestamps' ) );
		$merchant = (string) ( $settings['merchant_email'] ?? get_option( 'admin_email' ) );
		$retention= (int) ( $settings['retention_years'] ?? 10 );
		$slug     = (string) ( $settings['endpoint_slug'] ?? 'wwu-withdrawal' );

		echo '<h2>' . esc_html__( 'Receipt & evidence', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Attach PDF receipt', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<label><input type="checkbox" name="send_pdf" value="1" ' . checked( ! empty( $settings['send_pdf'] ), true, false ) . ' /> ';
		echo esc_html__( 'Attach a PDF copy to the acknowledgement email (the email itself is always the durable medium).', 'wwu-withdrawal-button' ) . '</label>';
		if ( ! \WWU\WithdrawalButton\DurableMedium\PdfBuilder::is_available() ) {
			echo '<p class="description" style="color:#8a1f21;">' . esc_html__( 'PDF library not detected — PDF receipts are currently disabled (the email still works). Install the plugin from the official ZIP to enable PDFs.', 'wwu-withdrawal-button' ) . '</p>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Notification email', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<input type="email" name="merchant_email" class="regular-text" value="' . esc_attr( $merchant ) . '" />';
		echo '<p class="description">' . esc_html__( 'Where to notify you of new withdrawal requests.', 'wwu-withdrawal-button' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Evidence retention (years)', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<input type="number" name="retention_years" min="1" max="30" value="' . esc_attr( (string) $retention ) . '" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'How long to keep the immutable log (default 10 — the contract limitation period).', 'wwu-withdrawal-button' ) . '</p>';
		echo '</td></tr>';

		$rfc = wp_parse_args(
			(array) ( $ts['rfc3161'] ?? array() ),
			array(
				'endpoint' => 'http://timestamp.sectigo.com/qualified',
				'user'     => '',
				'pass'     => '',
			)
		);

		echo '<tr><th scope="row">' . esc_html__( 'Trusted timestamp', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<select name="timestamp_provider">';
		$tsopts = array(
			'opentimestamps' => __( 'OpenTimestamps (free, Bitcoin-anchored — recommended)', 'wwu-withdrawal-button' ),
			'rfc3161'        => __( 'RFC 3161 timestamp authority (immediate; eIDAS-qualified options)', 'wwu-withdrawal-button' ),
			'none'           => __( 'None (the hash chain alone is the evidence)', 'wwu-withdrawal-button' ),
		);
		foreach ( $tsopts as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( (string) $ts['provider'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Only a one-way hash leaves your site — never personal data. OpenTimestamps anchors to Bitcoin (free, ~hours). RFC 3161 gets an immediate signed token from a Time-Stamp Authority.', 'wwu-withdrawal-button' ) . '</p>';
		echo '</td></tr>';

		// RFC 3161 configuration (only used when that provider is selected).
		echo '<tr><th scope="row">' . esc_html__( 'RFC 3161 — TSA endpoint', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<input type="url" name="rfc3161_endpoint" class="regular-text code" value="' . esc_attr( (string) $rfc['endpoint'] ) . '" placeholder="http://timestamp.sectigo.com/qualified" />';
		echo '<p class="description">' . wp_kses_post( __( 'Free, no account: <code>http://timestamp.sectigo.com/qualified</code> (eIDAS-qualified) or <code>http://timestamp.digicert.com</code>. For a national qualified provider (e.g. Aruba, InfoCert, D-Trust, Universign, FNMT, SwissSign) paste their RFC 3161 endpoint and fill in the credentials below.', 'wwu-withdrawal-button' ) ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'RFC 3161 — credentials (optional)', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<input type="text" name="rfc3161_user" class="regular-text" autocomplete="off" value="' . esc_attr( (string) $rfc['user'] ) . '" placeholder="' . esc_attr__( 'Username (only for account-based QTSPs)', 'wwu-withdrawal-button' ) . '" />';
		echo '<br><input type="password" name="rfc3161_pass" class="regular-text" autocomplete="new-password" value="" placeholder="' . esc_attr__( 'Password — leave blank to keep the saved one', 'wwu-withdrawal-button' ) . '" style="margin-top:6px;" />';
		echo '<p class="description">' . esc_html__( 'Basic-auth username/password, only required by paid qualified providers. Free TSAs need none. The password is stored on your server and never shown again.', 'wwu-withdrawal-button' ) . '</p>';
		echo '</td></tr>';

		$this->render_timestamp_reference();

		echo '<tr><th scope="row">' . esc_html__( 'My Account tab slug', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<input type="text" name="endpoint_slug" class="regular-text" value="' . esc_attr( $slug ) . '" />';
		echo '<p class="description">' . esc_html__( 'The URL slug of the "Right of withdrawal" tab in the customer account. Change only if it conflicts.', 'wwu-withdrawal-button' ) . '</p>';
		echo '</td></tr>';

		// Live preview of the acknowledgement email (opens in a new tab).
		$preview_url = wp_nonce_url( admin_url( 'admin-post.php?action=wwu_wb_preview_email' ), self::PREVIEW_NONCE );
		echo '<tr><th scope="row">' . esc_html__( 'Email preview', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<a class="button" href="' . esc_url( $preview_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Preview the acknowledgement email', 'wwu-withdrawal-button' ) . '</a>';
		echo '<p class="description">' . esc_html__( 'Opens a sample of the email the consumer receives. On WooCommerce it uses your store\'s email branding (header, footer, colours) exactly as it will be sent.', 'wwu-withdrawal-button' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
	}

	/**
	 * Render a collapsible reference of timestamp providers with links.
	 *
	 * Short blurbs so the merchant can pick a free option or their national
	 * qualified provider and paste the right RFC 3161 endpoint.
	 *
	 * @return void
	 */
	private function render_timestamp_reference(): void {
		// [ name, one-line description, official site ].
		$refs = array(
			array( 'OpenTimestamps', __( 'Free, Bitcoin-anchored, no account — the default. Proof completes after a block (~hours).', 'wwu-withdrawal-button' ), 'https://opentimestamps.org' ),
			array( 'Sectigo', __( 'Free RFC 3161. Its /qualified endpoint is eIDAS-qualified and needs no account — recommended free option.', 'wwu-withdrawal-button' ), 'https://www.sectigo.com/resource-library/time-stamping-server' ),
			array( 'DigiCert', __( 'Free RFC 3161 timestamp authority, no account: http://timestamp.digicert.com', 'wwu-withdrawal-button' ), 'https://knowledge.digicert.com/general-information/rfc3161-compliant-time-stamp-authority-server' ),
			array( 'Aruba (IT)', __( 'Italian eIDAS-qualified timestamp (marca temporale). Paid account; widely accepted in Italy.', 'wwu-withdrawal-button' ), 'https://www.aruba.it/marca-temporale.aspx' ),
			array( 'InfoCert (IT)', __( 'Italian eIDAS-qualified timestamp. Paid account.', 'wwu-withdrawal-button' ), 'https://www.infocert.it' ),
			array( 'D-Trust (DE)', __( 'German eIDAS-qualified timestamp (Bundesdruckerei). Account required.', 'wwu-withdrawal-button' ), 'https://www.d-trust.net' ),
			array( 'Universign (FR)', __( 'French eIDAS-qualified timestamp. Account required.', 'wwu-withdrawal-button' ), 'https://www.universign.com' ),
			array( 'FNMT (ES)', __( 'Spanish eIDAS-qualified timestamp (national mint). Registration required.', 'wwu-withdrawal-button' ), 'https://www.sede.fnmt.gob.es' ),
			array( 'SwissSign (CH)', __( 'Swiss ZertES-qualified timestamp. Account required.', 'wwu-withdrawal-button' ), 'https://www.swisssign.com' ),
		);

		echo '<tr><th scope="row"></th><td>';
		echo '<details class="wwu-wb-clause"><summary><strong>' . esc_html__( 'Timestamp providers — which to choose', 'wwu-withdrawal-button' ) . '</strong></summary>';
		echo '<ul style="margin:0.8em 0 0;padding-left:1.2em;max-width:760px;">';
		foreach ( $refs as $r ) {
			echo '<li style="margin-bottom:0.5em;"><strong>' . esc_html( $r[0] ) . '</strong> — ' . esc_html( $r[1] )
				. ' <a href="' . esc_url( $r[2] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'site', 'wwu-withdrawal-button' ) . ' &#8599;</a></li>';
		}
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'Any RFC 3161 authority works — paste its endpoint above and add credentials if it is account-based. Running OpenTimestamps and an RFC 3161 token together gives two independent proofs.', 'wwu-withdrawal-button' ) . '</p>';
		echo '</details>';
		echo '</td></tr>';
	}

	/**
	 * Render a styled preview of the acknowledgement email with sample data.
	 *
	 * Capability-gated + nonce-checked. Renders nothing real — it builds the email
	 * from representative sample data so the merchant can see how it looks. On
	 * WooCommerce it goes through the WC_Email style inliner (store branding);
	 * otherwise it renders the plain standalone template. Outputs a full HTML
	 * document in the browser and exits.
	 *
	 * @return void
	 */
	public function handle_preview_email(): void {
		if ( ! current_user_can( Authentication::capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wwu-withdrawal-button' ) );
		}
		check_admin_referer( self::PREVIEW_NONCE );

		$data = $this->sample_receipt_data();
		$html = '';

		// Prefer the branded WooCommerce email when WooCommerce is available.
		if ( class_exists( '\WC_Email' ) && function_exists( 'WC' ) && WC() && method_exists( WC(), 'mailer' ) ) {
			$emails = WC()->mailer()->get_emails();
			$key    = WooAckEmail::CLASS_KEY;
			if ( isset( $emails[ $key ] ) && method_exists( $emails[ $key ], 'preview' ) ) {
				$html = (string) $emails[ $key ]->preview( $data );
			}
		}

		// Fallback: the plain standalone template (FluentCart / no WooCommerce).
		if ( '' === $html ) {
			$html = Template::render( 'emails/withdrawal-confirmation.php', $data );
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- email templates escape their own values; WC style_inline returns trusted markup.
		exit;
	}

	/**
	 * Representative sample data for the email preview (no real order needed).
	 *
	 * @return array
	 */
	private function sample_receipt_data(): array {
		$format = get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' );
		return array(
			'name'            => __( 'Sample Customer', 'wwu-withdrawal-button' ),
			'order_number'    => '1234',
			'items'           => __( 'Sample product × 1', 'wwu-withdrawal-button' ),
			'email'           => 'customer@example.com',
			'reason'          => '',
			'submitted_local' => wp_date( $format ),
			'submitted_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'row_hash'        => str_repeat( '0', 64 ),
			'trader'          => array(
				'name'    => (string) get_bloginfo( 'name' ),
				'address' => '',
				'email'   => (string) get_option( 'admin_email' ),
			),
			'download_url'    => '#',
			'verify_url'      => '#',
			'within_window'   => true,
			'site_name'       => (string) get_bloginfo( 'name' ),
		);
	}

	/**
	 * Render the "Appearance & custom CSS" section + the styling reference.
	 *
	 * @param array $settings Current settings.
	 * @return void
	 */
	private function render_appearance_section( array $settings ): void {
		$custom_css = (string) ( $settings['custom_css'] ?? '' );

		echo '<h2>' . esc_html__( 'Appearance — Custom CSS', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Paste CSS to restyle any element of the withdrawal flow on your site. It is loaded after the plugin styles, so it always wins. Tip: override the CSS variables below for quick color/shape changes, or target the classes for full control.', 'wwu-withdrawal-button' ) . '</p>';
		echo '<p class="description" style="color:#8a1f21;"><strong>' . esc_html__( 'Compliance note:', 'wwu-withdrawal-button' ) . '</strong> ' . esc_html__( 'the withdrawal button must stay legible and prominent (Art. 11a). Do not hide it, shrink it, or reduce its contrast.', 'wwu-withdrawal-button' ) . '</p>';

		echo '<p><textarea name="custom_css" rows="10" class="large-text code" spellcheck="false" placeholder=":root { --wwu-wb-accent: #f49619; --wwu-wb-radius: 8px; }">' . esc_textarea( $custom_css ) . '</textarea></p>';

		// --- Reference: CSS variables ---
		$vars = array(
			array( '--wwu-wb-accent', '#1a1f3a', __( 'Button background color', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-accent-text', '#ffffff', __( 'Button text color', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-accent-focus', '#1a1f3a', __( 'Button focus outline color', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-radius', '5px', __( 'Button corner radius', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-field-radius', '4px', __( 'Input/result corner radius', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-button-padding', '0.7em 1.4em', __( 'Button padding', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-button-font-size', '1rem', __( 'Button font size', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-button-font-weight', '600', __( 'Button font weight', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-button-border', '2px solid currentColor', __( 'Button border', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-form-max-width', '540px', __( 'Form max width', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-field-border', '#c3c4c7', __( 'Input border color', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-field-padding', '0.6em 0.7em', __( 'Input padding', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-label-weight', '600', __( 'Field label weight', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-note-color', '#666666', __( 'Helper/note text color', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-success-bg / -border / -text', '#edfaef / #46b450 / #1a4d24', __( 'Success message colors', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-error-bg / -border / -text', '#fcf0f1 / #d63638 / #8a1f21', __( 'Error message colors', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-notice-bg / -accent', '#f6f7f7 / #1a1f3a', __( 'Status-notice colors', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-spacing', '1em', __( 'Vertical spacing rhythm', 'wwu-withdrawal-button' ) ),
		);

		// --- Reference: classes ---
		$classes = array(
			array( '.wwu-wb-button-wrap', __( 'Wrapper around the withdrawal button', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-button', __( 'Every button/CTA (withdraw, continue, confirm)', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-continue', __( 'The Step-1 "Continue" button', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-confirm', __( 'The statutory "Confirm withdrawal" button', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-window-note', __( 'The "X days left" note next to the button', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-form-wrap', __( 'The withdrawal form container', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-form-title', __( 'The form heading', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-field', __( 'Each field row (label + input)', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-actions', __( 'The actions row holding a button', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-step2 / .wwu-wb-step2-intro', __( 'The Step-2 confirmation block + its intro text', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-result.is-success / .is-error', __( 'The result message (success / error states)', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-status-notice', __( 'The "request status" notice on an order', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-notice', __( 'Generic inline notice', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-model-form (+ __fields)', __( 'The Annex I-B model form block', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-info', __( 'The pre-contractual info block', 'wwu-withdrawal-button' ) ),
		);

		echo '<details class="wwu-wb-clause" style="margin-top:1em;"><summary><strong>' . esc_html__( 'CSS reference — variables & classes', 'wwu-withdrawal-button' ) . '</strong></summary>';

		echo '<h3>' . esc_html__( 'CSS variables (quick overrides)', 'wwu-withdrawal-button' ) . '</h3>';
		echo '<table class="widefat striped" style="max-width:860px;"><thead><tr><th>' . esc_html__( 'Variable', 'wwu-withdrawal-button' ) . '</th><th>' . esc_html__( 'Default', 'wwu-withdrawal-button' ) . '</th><th>' . esc_html__( 'Controls', 'wwu-withdrawal-button' ) . '</th></tr></thead><tbody>';
		foreach ( $vars as $v ) {
			echo '<tr><td><code>' . esc_html( $v[0] ) . '</code></td><td><code>' . esc_html( $v[1] ) . '</code></td><td>' . esc_html( $v[2] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Classes (full control)', 'wwu-withdrawal-button' ) . '</h3>';
		echo '<table class="widefat striped" style="max-width:860px;"><thead><tr><th>' . esc_html__( 'Class', 'wwu-withdrawal-button' ) . '</th><th>' . esc_html__( 'Targets', 'wwu-withdrawal-button' ) . '</th></tr></thead><tbody>';
		foreach ( $classes as $c ) {
			echo '<tr><td><code>' . esc_html( $c[0] ) . '</code></td><td>' . esc_html( $c[1] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Examples', 'wwu-withdrawal-button' ) . '</h3>';
		$examples = "/* Brand the button (quick way) */\n:root {\n    --wwu-wb-accent: #f49619;\n    --wwu-wb-accent-text: #ffffff;\n    --wwu-wb-radius: 8px;\n}\n\n/* Full control on the confirm button */\n.wwu-wb-confirm {\n    text-transform: uppercase;\n    letter-spacing: .5px;\n}\n\n/* Make the form wider */\n.wwu-wb-form-wrap { max-width: 680px; }";
		echo '<pre class="wwu-wb-inspector__snapshot" style="max-width:860px;">' . esc_html( $examples ) . '</pre>';

		echo '</details>';
	}

	/**
	 * Render the "Exemptions (Art. 59)" section: per-reason product/category pickers.
	 *
	 * The right of withdrawal is the default; the merchant tags only the products /
	 * categories that fall under a specific statutory exception. Conditional reasons
	 * (service performed / digital immediate) note that the button is hidden only
	 * once consent is captured (a later phase); seal-based reasons note they are not
	 * auto-hidden. Standard #12: every reason ships its legal ref + plain-language hint.
	 *
	 * @return void
	 */
	private function render_exemptions_section(): void {
		$exclusions = (array) get_option( 'wwu_wb_exclusions', array() );
		$by_reason  = ( isset( $exclusions['by_reason'] ) && is_array( $exclusions['by_reason'] ) ) ? $exclusions['by_reason'] : array();
		$auto       = ! empty( $exclusions['auto_detect_virtual'] );

		// Surface any legacy flat lists under the generic 'manual' picker.
		$legacy_p = array_map( 'intval', (array) ( $exclusions['excluded_product_ids'] ?? array() ) );
		$legacy_c = array_map( 'intval', (array) ( $exclusions['excluded_category_ids'] ?? array() ) );

		echo '<h2>' . esc_html__( 'Exemptions (Art. 59)', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<p class="description" style="max-width:860px;">' . wp_kses_post( __( 'The right of withdrawal applies <strong>by default to every product</strong>, including digital goods and services. <strong>Physical products never need consent</strong> — the 14-day right just applies and no checkbox is ever shown for them. Only the <strong>two conditional exemptions</strong> (digital content with immediate access; a service fully performed) remove the right, and only when the consumer gives <strong>prior express consent</strong> AND <strong>acknowledges losing the right</strong>. Tag a product or category under a specific reason below by entering product IDs and/or category IDs (comma-separated).', 'wwu-withdrawal-button' ) ) . '</p>';

		echo '<p class="description" style="max-width:860px;">' . wp_kses_post( __( 'For the two conditional reasons the plugin <strong>captures the consent first, then hides the button</strong>: on the <strong>WooCommerce checkout</strong> it adds the required tick-box automatically and stores the agreed wording on the order. Until that tick-box is confirmed — and on platforms/checkouts where capture is not available yet — the button <strong>stays visible, even on digital items</strong> (fail-safe toward the consumer\'s right). The plugin never hides the button "on digital" blindly. The acknowledgement wording is filterable via <code>wwu_wb_consent_text</code>. Seal-based reasons depend on the consumer unsealing after delivery, so they are never auto-hidden.', 'wwu-withdrawal-button' ) ) . '</p>';

		echo '<p class="description" style="max-width:860px;">' . wp_kses_post( __( 'The stored consent is <strong>evidence to discharge your burden of proof</strong> (Art. 6(9) Dir. 2011/83/EU; GDPR accountability Art. 5(2)) — not a legally-named "register". For the <strong>digital</strong> exemption the law also requires you to send the consumer a confirmation on a <strong>durable medium</strong> (e-mail) before access begins, or the exemption does not hold. See the plugin\'s legal note for the full basis.', 'wwu-withdrawal-button' ) ) . '</p>';

		$main_opt   = (array) get_option( 'wwu_wb_settings', array() );
		$capture_ip = array_key_exists( 'consent_capture_ip', $main_opt ) ? ! empty( $main_opt['consent_capture_ip'] ) : true;

		// Status panel + guided "what do you sell?" helper (WWU UI Kit notices).
		$this->render_exemptions_status( $by_reason, $legacy_p, $legacy_c, $capture_ip );
		$this->render_exemptions_helper();

		// Bucket the reasons by behavioural group (derived from their flags).
		$by_group = array(
			'conditional'   => array(),
			'unconditional' => array(),
			'seal_based'    => array(),
		);
		foreach ( \WWU\WithdrawalButton\Domain\ExceptionTypes::all() as $id => $def ) {
			$by_group[ \WWU\WithdrawalButton\Domain\ExceptionTypes::group( (string) $id ) ][ (string) $id ] = $def;
		}

		$groups = array(
			'conditional'   => array(
				__( 'Conditional — these need the consumer\'s consent', 'wwu-withdrawal-button' ),
				__( 'Tag here only digital content with immediate access and services fully performed. The button is hidden ONLY after the consumer consents at checkout; otherwise it stays (fail-safe).', 'wwu-withdrawal-button' ),
				true,
			),
			'unconditional' => array(
				__( 'Unconditional — exempt by nature (no consent)', 'wwu-withdrawal-button' ),
				__( 'These have no right of withdrawal by law (custom-made, perishable, dated events, …). Tagged products hide the button with no checkbox.', 'wwu-withdrawal-button' ),
				false,
			),
			'seal_based'    => array(
				__( 'Seal-based — assess on return (never auto-hidden)', 'wwu-withdrawal-button' ),
				__( 'These depend on the consumer unsealing after delivery, which cannot be known at order time. Tag them, but the button stays; assess on return.', 'wwu-withdrawal-button' ),
				false,
			),
		);

		foreach ( $groups as $gkey => $g ) {
			$ids        = $by_group[ $gkey ];
			$configured = 0;
			foreach ( array_keys( $ids ) as $rid ) {
				$r = ( isset( $by_reason[ $rid ] ) && is_array( $by_reason[ $rid ] ) ) ? $by_reason[ $rid ] : array();
				if ( ! empty( $r['products'] ) || ! empty( $r['categories'] ) || ( 'manual' === $rid && ( ! empty( $legacy_p ) || ! empty( $legacy_c ) ) ) ) {
					++$configured;
				}
			}

			echo '<details class="wwu-ui-accordion" id="wwu-wb-group-' . esc_attr( $gkey ) . '"' . ( $g[2] ? ' open' : '' ) . '>';
			echo '<summary class="wwu-ui-accordion-header"><span>' . esc_html( $g[0] ) . '</span><span class="wwu-ui-accordion-meta">';
			if ( $configured > 0 ) {
				echo '<span class="wwu-ui-badge success-soft">' . esc_html(
					sprintf(
						/* translators: %d: number of reasons with products/categories tagged. */
						_n( '%d configured', '%d configured', $configured, 'wwu-withdrawal-button' ),
						$configured
					)
				) . '</span> ';
			}
			echo '<span class="wwu-ui-badge neutral">' . esc_html( (string) count( $ids ) ) . '</span></span></summary>';
			echo '<div class="wwu-ui-accordion-body">';
			echo '<p class="description">' . esc_html( $g[1] ) . '</p>';
			foreach ( $ids as $rid => $rdef ) {
				$this->render_reason_block( (string) $rid, $rdef, $by_reason, $legacy_p, $legacy_c );
			}
			echo '</div></details>';
		}

		// Advanced: legacy auto-detect + IP capture toggle.
		$this->render_exemptions_advanced( $auto, $capture_ip );
	}

	/**
	 * Render a single exemption-reason block (label + tooltip + example + inputs +,
	 * for conditional reasons, a "what the consumer sees" preview). Used inside the
	 * grouped accordions. The 'manual' reason also folds in any legacy flat lists.
	 *
	 * @param string              $id        Reason id.
	 * @param array<string,mixed> $def       Reason definition (ExceptionTypes::all()).
	 * @param array<string,mixed> $by_reason Saved per-reason map.
	 * @param int[]               $legacy_p  Legacy flat product ids (for 'manual').
	 * @param int[]               $legacy_c  Legacy flat category ids (for 'manual').
	 * @return void
	 */
	private function render_reason_block( string $id, array $def, array $by_reason, array $legacy_p, array $legacy_c ): void {
		$row = ( isset( $by_reason[ $id ] ) && is_array( $by_reason[ $id ] ) ) ? $by_reason[ $id ] : array();
		$p   = array_map( 'intval', (array) ( $row['products'] ?? array() ) );
		$c   = array_map( 'intval', (array) ( $row['categories'] ?? array() ) );
		if ( 'manual' === $id ) {
			$p = array_values( array_unique( array_merge( $p, $legacy_p ) ) );
			$c = array_values( array_unique( array_merge( $c, $legacy_c ) ) );
		}

		$tag = '';
		if ( ! empty( $def['conditional'] ) ) {
			$tag = ' <span class="wwu-ui-badge warning-soft wwu-ui-badge-sm">' . esc_html__( 'needs consent', 'wwu-withdrawal-button' ) . '</span>';
		} elseif ( ! empty( $def['seal_based'] ) ) {
			$tag = ' <span class="wwu-ui-badge neutral wwu-ui-badge-sm">' . esc_html__( 'not auto-hidden', 'wwu-withdrawal-button' ) . '</span>';
		}

		$hint = (string) ( $def['hint'] ?? '' );

		echo '<div class="wwu-wb-reason" id="wwu-wb-reason-' . esc_attr( $id ) . '" style="border:1px solid #e0e0e0;border-radius:4px;padding:12px 14px;margin:0 0 10px;background:#fff;">';
		echo '<p style="margin:0 0 8px;font-weight:600;">' . esc_html( (string) ( $def['label'] ?? $id ) ) . $tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $tag is static escaped kit markup.
		if ( '' !== $hint ) {
			echo ' <span class="wwu-wb-help" tabindex="0" title="' . esc_attr( $hint ) . '" style="display:inline-block;width:16px;height:16px;line-height:16px;text-align:center;border-radius:50%;background:#e0e0e0;color:#333;font-size:11px;cursor:help;">?</span>';
		}
		echo '<br><span style="font-weight:400;color:#666;font-size:12px;">' . esc_html( (string) ( $def['legal_ref'] ?? '' ) ) . '</span></p>';

		echo '<label style="margin-right:14px;">' . esc_html__( 'Product IDs', 'wwu-withdrawal-button' ) . ' <input type="text" class="regular-text" name="exempt[' . esc_attr( $id ) . '][products]" value="' . esc_attr( implode( ', ', $p ) ) . '" placeholder="' . esc_attr__( 'e.g. 12, 84', 'wwu-withdrawal-button' ) . '"></label>';
		echo '<label>' . esc_html__( 'Category IDs', 'wwu-withdrawal-button' ) . ' <input type="text" class="regular-text" name="exempt[' . esc_attr( $id ) . '][categories]" value="' . esc_attr( implode( ', ', $c ) ) . '" placeholder="' . esc_attr__( 'e.g. 5', 'wwu-withdrawal-button' ) . '"></label>';

		if ( ! empty( $def['example'] ) ) {
			echo '<details style="margin-top:8px;"><summary style="cursor:pointer;color:#2271b1;">' . esc_html__( 'Show example', 'wwu-withdrawal-button' ) . '</summary><p class="description" style="margin:6px 0 0;">' . esc_html( (string) $def['example'] ) . '</p></details>';
		}

		if ( ! empty( $def['conditional'] ) ) {
			echo '<p class="description" style="color:#1d6b2f;margin-top:8px;"><strong>' . esc_html__( 'How the plugin enforces this:', 'wwu-withdrawal-button' ) . '</strong> ' . esc_html__( 'on the WooCommerce / FluentCart checkout a required acknowledgement tick-box is added automatically. The button stays visible until the consumer ticks it; only then is it hidden for this item. On other checkouts/platforms the button stays (fail-safe). For the digital reason you must also deliver a durable-medium (e-mail) confirmation to the consumer before access begins.', 'wwu-withdrawal-button' ) . '</p>';

			$consent_text = \WWU\WithdrawalButton\Domain\ConsentText::for_reason( $id );
			$email_html   = \WWU\WithdrawalButton\Mail\ExemptionConfirmation::preview_html( $id );
			if ( '' !== $consent_text ) {
				echo '<details style="margin-top:6px;"><summary style="cursor:pointer;color:#2271b1;">' . esc_html__( 'Preview what the consumer sees', 'wwu-withdrawal-button' ) . '</summary>';
				echo '<div style="border-left:3px solid #2271b1;padding:8px 12px;background:#f6f7f7;margin-top:6px;">';
				echo '<p style="margin:0 0 4px;"><strong>' . esc_html__( 'At checkout, the buyer must tick:', 'wwu-withdrawal-button' ) . '</strong></p>';
				echo '<p style="margin:0 0 10px;font-style:italic;">&ldquo;' . esc_html( $consent_text ) . '&rdquo;</p>';
				if ( '' !== $email_html ) {
					echo '<p style="margin:0 0 4px;"><strong>' . esc_html__( 'And receives this confirmation e-mail:', 'wwu-withdrawal-button' ) . '</strong></p>';
					echo '<div style="background:#fff;border:1px solid #e0e0e0;padding:8px 10px;">' . $email_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- preview_html() escapes its content internally.
				}
				echo '</div></details>';
			}
		}

		echo '</div>';
	}

	/**
	 * Render the exemptions status / health panel (UI Kit notice + badges).
	 *
	 * @param array<string,mixed> $by_reason  Saved per-reason map.
	 * @param int[]               $legacy_p   Legacy flat product ids.
	 * @param int[]               $legacy_c   Legacy flat category ids.
	 * @param bool                $capture_ip Whether IP capture is on.
	 * @return void
	 */
	private function render_exemptions_status( array $by_reason, array $legacy_p, array $legacy_c, bool $capture_ip ): void {
		$reasons_configured = 0;
		$product_ids        = 0;
		$category_ids       = 0;
		foreach ( $by_reason as $rid => $sets ) {
			if ( ! is_array( $sets ) ) {
				continue;
			}
			$pn = count( array_filter( array_map( 'intval', (array) ( $sets['products'] ?? array() ) ) ) );
			$cn = count( array_filter( array_map( 'intval', (array) ( $sets['categories'] ?? array() ) ) ) );
			if ( $pn > 0 || $cn > 0 ) {
				++$reasons_configured;
			}
			$product_ids  += $pn;
			$category_ids += $cn;
		}
		$product_ids  += count( $legacy_p );
		$category_ids += count( $legacy_c );

		$years      = max( 1, min( 30, (int) ( Settings::main()['retention_years'] ?? 10 ) ) );
		$last_purge = (string) get_option( 'wwu_wb_consent_last_purge', '' );

		echo '<div class="wwu-ui-notice info" style="margin:12px 0;"><p style="margin:0 0 6px;"><strong>' . esc_html__( 'Exemptions status', 'wwu-withdrawal-button' ) . '</strong></p>';
		echo '<p style="margin:0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">';

		echo '<span class="wwu-ui-badge ' . ( $reasons_configured > 0 ? 'success-soft' : 'neutral' ) . '">' . esc_html(
			sprintf(
				/* translators: %d: number of reasons configured. */
				_n( '%d reason configured', '%d reasons configured', $reasons_configured, 'wwu-withdrawal-button' ),
				$reasons_configured
			)
		) . '</span>';

		echo '<span class="wwu-ui-badge neutral">' . esc_html(
			sprintf(
				/* translators: 1: product id count, 2: category id count. */
				__( '%1$d product IDs, %2$d category IDs', 'wwu-withdrawal-button' ),
				$product_ids,
				$category_ids
			)
		) . '</span>';

		echo '<span class="wwu-ui-badge ' . ( $capture_ip ? 'info-soft' : 'neutral' ) . '">' . esc_html(
			$capture_ip ? __( 'IP capture: on', 'wwu-withdrawal-button' ) : __( 'IP capture: off', 'wwu-withdrawal-button' )
		) . '</span>';

		echo '<span class="wwu-ui-badge neutral">' . esc_html(
			sprintf(
				/* translators: %d: retention years. */
				__( 'Retention: %d years', 'wwu-withdrawal-button' ),
				$years
			)
		) . '</span>';

		$purge_label = '' !== $last_purge
			? sprintf( /* translators: %s: human time diff. */ __( 'IP purge last ran %s ago', 'wwu-withdrawal-button' ), human_time_diff( (int) strtotime( $last_purge ) ) )
			: __( 'IP purge: not run yet (runs daily)', 'wwu-withdrawal-button' );
		echo '<span class="wwu-ui-badge neutral">' . esc_html( $purge_label ) . '</span>';

		echo '</p></div>';
	}

	/**
	 * Render the guided "What do you sell?" helper (UI Kit notice + nested accordions).
	 * Suggest-only: it explains the legal mapping and points to the matching reason
	 * group; it never writes IDs for the merchant.
	 *
	 * @return void
	 */
	private function render_exemptions_helper(): void {
		$cards = array(
			array(
				__( 'Event tickets (a specific date)', 'wwu-withdrawal-button' ),
				__( 'A ticket for an event tied to a specific date has NO right of withdrawal (Art. 59(1)(n)). Tag it under "Accommodation / transport / leisure on a specific date" in the Unconditional group — the button is hidden, no checkbox.', 'wwu-withdrawal-button' ),
				'unconditional',
			),
			array(
				__( 'Digital downloads & recordings', 'wwu-withdrawal-button' ),
				__( 'Content the buyer downloads or streams immediately is a CONDITIONAL exemption (Art. 59(1)(o)): tag it under "Digital content with immediate access" in the Conditional group. The buyer sees a consent checkbox and receives a durable-medium e-mail; only then is the button hidden. (A subscription/membership: the initial order keeps the right; the digital access is the 59(1)(o) part.)', 'wwu-withdrawal-button' ),
				'conditional',
			),
			array(
				__( 'Live sessions (e.g. Zoom)', 'wwu-withdrawal-button' ),
				__( 'A dated live session (e.g. a webinar on a fixed date) → treat like an event ticket: "leisure on a specific date" (Unconditional). A session that simply starts immediately, with no fixed event date → "Service fully performed" (Conditional, needs consent).', 'wwu-withdrawal-button' ),
				'conditional',
			),
			array(
				__( 'Services performed immediately', 'wwu-withdrawal-button' ),
				__( 'A service that begins at once and is fully performed → "Service fully performed" (Art. 59(1)(a), Conditional). The buyer consents at checkout; the exemption applies only once the service is fully performed (partial → pro-rata, the right survives).', 'wwu-withdrawal-button' ),
				'conditional',
			),
			array(
				__( 'Physical goods', 'wwu-withdrawal-button' ),
				__( 'Nothing to do — physical products always keep the 14-day right and always show the button. Only tag the specific exceptions (custom-made, perishable, sealed hygiene) under the matching reason.', 'wwu-withdrawal-button' ),
				'',
			),
		);

		echo '<div class="wwu-ui-notice" style="margin:12px 0;"><p style="margin:0 0 8px;"><strong>' . esc_html__( 'What do you sell? (quick guide)', 'wwu-withdrawal-button' ) . '</strong></p>';
		foreach ( $cards as $card ) {
			echo '<details style="margin:0 0 6px;border:1px solid #e0e0e0;border-radius:4px;background:#fff;">';
			echo '<summary style="cursor:pointer;padding:8px 12px;font-weight:600;">' . esc_html( $card[0] ) . '</summary>';
			echo '<div style="padding:0 12px 10px;">';
			echo '<p class="description" style="margin:4px 0;">' . esc_html( $card[1] ) . '</p>';
			if ( '' !== $card[2] ) {
				echo '<p style="margin:6px 0 0;"><a href="#wwu-wb-group-' . esc_attr( $card[2] ) . '">' . esc_html__( '→ Go to the matching group below', 'wwu-withdrawal-button' ) . '</a></p>';
			}
			echo '</div></details>';
		}
		echo '</div>';
	}

	/**
	 * Render the advanced exemption toggles (legacy auto-detect + IP capture).
	 *
	 * @param bool $auto       Whether legacy auto-detect is on.
	 * @param bool $capture_ip Whether IP capture is on.
	 * @return void
	 */
	private function render_exemptions_advanced( bool $auto, bool $capture_ip ): void {
		echo '<details class="wwu-ui-accordion" style="margin-top:10px;">';
		echo '<summary class="wwu-ui-accordion-header"><span>' . esc_html__( 'Advanced (legacy auto-detect + privacy)', 'wwu-withdrawal-button' ) . '</span></summary>';
		echo '<div class="wwu-ui-accordion-body">';

		echo '<p style="margin:0 0 10px;"><label><input type="checkbox" name="exempt_auto_detect" value="1" ' . checked( $auto, true, false ) . '> <strong>' . esc_html__( 'Auto-exclude delivered digital (legacy)', 'wwu-withdrawal-button' ) . '</strong></label><br><span class="description">' . esc_html__( 'Treat virtual/downloadable items on completed orders as exempt. OFF by default — the proper path is the "Digital content with immediate access" reason with consent capture.', 'wwu-withdrawal-button' ) . '</span></p>';

		echo '<p style="margin:0;"><label><input type="checkbox" name="consent_capture_ip" value="1" ' . checked( $capture_ip, true, false ) . '> <strong>' . esc_html__( 'Store consumer IP with the consent', 'wwu-withdrawal-button' ) . '</strong></label><br><span class="description">' . esc_html__( 'Record the IP address alongside each captured consent as corroborating evidence. Stored only on the order (anonymised automatically after the retention period), never in the immutable log. Turn off to minimise personal data — the agreed wording, its hash and the timestamp remain.', 'wwu-withdrawal-button' ) . '</span></p>';

		echo '</div></details>';
	}

	/**
	 * Render the "Integrations" section: read-only REST API + outbound webhook.
	 *
	 * The read API needs no setting here — it authenticates with WordPress
	 * Application Passwords + the admin capability. The webhook is opt-in: enable +
	 * URL + a generated HMAC secret. The secret is only ever shown masked; the URL
	 * is validated through the SSRF guard on save.
	 *
	 * @return void
	 */
	private function render_integrations_section(): void {
		$cfg     = Webhook::config();
		$has_key = '' !== $cfg['secret'];
		$rest    = esc_url( rest_url( WWU_WB_REST_NAMESPACE ) );

		echo '<h2>' . esc_html__( 'Integrations (automations)', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<p class="description" style="max-width:820px;">' . esc_html__( 'Connect external systems (Zapier, Make, n8n, a CRM or helpdesk) to your withdrawal requests. Reading is done with a WordPress Application Password; a webhook can also notify your endpoint the moment a withdrawal is confirmed. The consumer IP is never exposed — only a row hash, for integrity checks.', 'wwu-withdrawal-button' ) . '</p>';

		/* --- Read-only REST API (Application Passwords) --- */
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Read API', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<p style="margin-top:0;">' . esc_html__( 'Authenticate with a WordPress Application Password (Users → Profile → Application Passwords) over HTTPS. The user needs the plugin admin capability. These endpoints are read-only:', 'wwu-withdrawal-button' ) . '</p>';
		echo '<ul style="list-style:disc;margin-left:1.4em;">';
		echo '<li><code>GET ' . esc_html( $rest ) . '/requests</code> — ' . esc_html__( 'list confirmed requests (paginated).', 'wwu-withdrawal-button' ) . '</li>';
		echo '<li><code>GET ' . esc_html( $rest ) . '/requests/{request_uid}</code> — ' . esc_html__( 'one request (with the consumer email + any partial product selection).', 'wwu-withdrawal-button' ) . '</li>';
		echo '<li><code>GET ' . esc_html( $rest ) . '/orders/{platform}/{order_ref}/withdrawal</code> — ' . esc_html__( 'per-order withdrawal status.', 'wwu-withdrawal-button' ) . '</li>';
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'There is no endpoint to create a withdrawal: a withdrawal is the consumer’s own legal declaration and cannot be filed on their behalf via the API.', 'wwu-withdrawal-button' ) . '</p>';
		echo '</td></tr>';
		echo '</tbody></table>';

		/* --- Outbound webhook --- */
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Webhook on confirmed withdrawal', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<label><input type="checkbox" name="webhook_enabled" value="1" ' . checked( $cfg['enabled'], true, false ) . ' /> ';
		echo esc_html__( 'POST a signed JSON payload to my endpoint when a withdrawal is confirmed.', 'wwu-withdrawal-button' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Delivered asynchronously, so the consumer is never kept waiting. Payload: event, request_uid, platform, order_ref, order_number, consumer_email, status, country, within_window, created_at, row_hash.', 'wwu-withdrawal-button' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="wwu-wb-webhook-url">' . esc_html__( 'Endpoint URL', 'wwu-withdrawal-button' ) . '</label></th><td>';
		echo '<input type="url" id="wwu-wb-webhook-url" class="regular-text" name="webhook_url" value="' . esc_attr( $cfg['url'] ) . '" placeholder="https://hooks.example.com/withdrawal" />';
		echo '<p class="description">' . esc_html__( 'HTTPS recommended. Internal, loopback and cloud-metadata addresses are refused (SSRF protection).', 'wwu-withdrawal-button' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="wwu-wb-webhook-secret">' . esc_html__( 'Signing secret', 'wwu-withdrawal-button' ) . '</label></th><td>';
		if ( $has_key ) {
			echo '<p style="margin-top:0;"><code>' . esc_html( Webhook::masked_secret( $cfg['secret'] ) ) . '</code> ';
			echo '<span class="description">' . esc_html__( '(stored — leave the field blank to keep it)', 'wwu-withdrawal-button' ) . '</span></p>';
		}
		echo '<input type="text" id="wwu-wb-webhook-secret" class="regular-text" name="webhook_secret" value="" autocomplete="off" placeholder="' . esc_attr__( 'Paste your own secret, or tick “Generate” below', 'wwu-withdrawal-button' ) . '" />';
		echo '<p><label><input type="checkbox" name="webhook_regenerate" value="1" /> ' . esc_html__( 'Generate a new random secret on save', 'wwu-withdrawal-button' ) . '</label></p>';
		echo '<p class="description">' . wp_kses(
			__( 'Each delivery is signed: <code>X-WWU-WB-Signature: sha256=HMAC-SHA256(body, secret)</code>, with <code>X-WWU-WB-Event</code> and a unique <code>X-WWU-WB-Delivery</code> id. Verify the signature on your side to trust the payload. The secret is never shown again in full and never written to logs.', 'wwu-withdrawal-button' ),
			array( 'code' => array() )
		) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
	}

	/**
	 * Handle the settings POST (admin-post.php). PRG redirect on success.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! current_user_can( Authentication::capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wwu-withdrawal-button' ) );
		}
		check_admin_referer( self::NONCE );

		// General settings.
		$settings               = (array) get_option( 'wwu_wb_settings', array() );
		$old_slug               = sanitize_title( (string) ( $settings['endpoint_slug'] ?? 'wwu-withdrawal' ) );
		$settings['enabled']    = Sanitizer::bool( $_POST['enabled'] ?? '' );
		$settings['custom_css'] = Sanitizer::css( isset( $_POST['custom_css'] ) ? wp_unslash( $_POST['custom_css'] ) : '' );
		$settings['send_pdf']        = Sanitizer::bool( $_POST['send_pdf'] ?? '' );
		$settings['merchant_email']  = sanitize_email( (string) ( $_POST['merchant_email'] ?? '' ) );
		$settings['retention_years'] = max( 1, min( 30, (int) ( $_POST['retention_years'] ?? 10 ) ) );
		// IP capture for the exemption-consent evidence (GDPR strict-necessity → configurable).
		$settings['consent_capture_ip'] = Sanitizer::bool( $_POST['consent_capture_ip'] ?? '' );
		// Consumer guidance: window is clamped to the 14-day legal minimum; custom
		// text replaces the default block (basic HTML allowed, merchant-owned).
		$settings['withdrawal_window_days'] = max( 14, min( 365, (int) ( $_POST['withdrawal_window_days'] ?? 14 ) ) );
		$settings['custom_guidance']        = wp_kses_post( wp_unslash( $_POST['custom_guidance'] ?? '' ) );
		$settings['custom_exemption_note']  = wp_kses_post( wp_unslash( $_POST['custom_exemption_note'] ?? '' ) );
		// Subscriptions: a renewal does not restart the 14-day right, so the button is
		// suppressed on renewals unless the merchant opts in; auto-cancelling the
		// subscription on a withdrawal is opt-in (refund/pro-rata stay manual).
		$settings['treat_renewals_as_withdrawable']    = Sanitizer::bool( $_POST['treat_renewals_as_withdrawable'] ?? '' );
		$settings['cancel_subscription_on_withdrawal'] = Sanitizer::bool( $_POST['cancel_subscription_on_withdrawal'] ?? '' );
		// FluentCart handling: auto (defer to a native add-on when present) / always / off.
		$settings['fluentcart_mode'] = Sanitizer::enum( $_POST['fluentcart_mode'] ?? '', array( 'auto', 'always', 'off' ), 'auto' );
		$new_slug                    = sanitize_title( (string) ( $_POST['endpoint_slug'] ?? 'wwu-withdrawal' ) );
		$settings['endpoint_slug']   = '' !== $new_slug ? $new_slug : 'wwu-withdrawal';
		update_option( 'wwu_wb_settings', $settings );

		// Legal clause overrides (Settings -> Legal clauses), per type for the current
		// admin language. An empty field reverts that clause to the built-in template;
		// other languages' overrides are preserved.
		$clause_lang    = strtolower( substr( determine_locale(), 0, 2 ) );
		$clauses        = (array) get_option( \WWU\WithdrawalButton\Legal\ClauseLibrary::OPTION, array() );
		$posted_clauses = ( isset( $_POST['wwu_wb_clauses'] ) && is_array( $_POST['wwu_wb_clauses'] ) ) ? (array) wp_unslash( $_POST['wwu_wb_clauses'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized below.
		foreach ( \WWU\WithdrawalButton\Legal\ClauseLibrary::types() as $clause_type ) {
			$value = isset( $posted_clauses[ $clause_type ] ) ? sanitize_textarea_field( (string) $posted_clauses[ $clause_type ] ) : '';
			if ( '' === trim( $value ) ) {
				unset( $clauses[ $clause_type ][ $clause_lang ] );
				if ( isset( $clauses[ $clause_type ] ) && empty( $clauses[ $clause_type ] ) ) {
					unset( $clauses[ $clause_type ] );
				}
			} else {
				$clauses[ $clause_type ][ $clause_lang ] = $value;
			}
		}
		update_option( \WWU\WithdrawalButton\Legal\ClauseLibrary::OPTION, $clauses, false );

		// Applicability.
		$applicability = array(
			'mode'                 => Sanitizer::enum( $_POST['applicability_mode'] ?? '', array( 'eu_eea_only', 'always', 'custom_list' ), 'eu_eea_only' ),
			'custom_countries'     => Sanitizer::country_list( $_POST['applicability_custom'] ?? '' ),
			'b2b_vat_out_of_scope' => Sanitizer::bool( $_POST['applicability_b2b'] ?? '' ),
		);
		update_option( 'wwu_wb_applicability', $applicability );

		// Exemptions (Art. 59) — per-reason product/category maps. Only registered
		// reasons are kept; the legacy flat lists are migrated away (now under
		// by_reason.manual). The crude auto-detect toggle stays for back-compat.
		$posted_exempt = ( isset( $_POST['exempt'] ) && is_array( $_POST['exempt'] ) ) ? (array) wp_unslash( $_POST['exempt'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is cast to ints below.
		$by_reason     = array();
		foreach ( $posted_exempt as $reason => $sets ) {
			$reason = sanitize_key( (string) $reason );
			if ( ! \WWU\WithdrawalButton\Domain\ExceptionTypes::exists( $reason ) ) {
				continue;
			}
			$products   = $this->parse_id_list( $sets['products'] ?? '' );
			$categories = $this->parse_id_list( $sets['categories'] ?? '' );
			if ( ! empty( $products ) || ! empty( $categories ) ) {
				$by_reason[ $reason ] = array(
					'products'   => $products,
					'categories' => $categories,
				);
			}
		}
		$exclusions                        = (array) get_option( 'wwu_wb_exclusions', array() );
		$exclusions['by_reason']           = $by_reason;
		$exclusions['auto_detect_virtual'] = Sanitizer::bool( $_POST['exempt_auto_detect'] ?? '' );
		unset( $exclusions['excluded_product_ids'], $exclusions['excluded_category_ids'] );
		update_option( 'wwu_wb_exclusions', $exclusions );
		// The block-checkout field gating caches the conditional product ids; refresh it.
		\WWU\WithdrawalButton\Frontend\WooBlockCheckoutConsent::flush_cache();

		// Timestamp provider.
		$timestamp = (array) get_option( 'wwu_wb_timestamp', array() );
		$timestamp['provider'] = Sanitizer::enum( $_POST['timestamp_provider'] ?? '', array( 'opentimestamps', 'rfc3161', 'none' ), 'opentimestamps' );

		// RFC 3161 config. The password uses the "leave blank to keep" pattern so
		// the saved secret is never re-emitted to the browser.
		$rfc3161           = (array) ( $timestamp['rfc3161'] ?? array() );
		$rfc3161['endpoint'] = esc_url_raw( trim( (string) ( $_POST['rfc3161_endpoint'] ?? '' ) ) );
		// SSRF: never persist a TSA endpoint that resolves to an internal/reserved
		// target (cloud-metadata, loopback, private, CGNAT, IPv4-mapped IPv6). The
		// request-time guard in Rfc3161Provider blocks it too, but refusing to store
		// it stops the misconfiguration at the source.
		if ( '' !== $rfc3161['endpoint'] && ! \WWU\WithdrawalButton\Security\OutboundUrlGuard::is_safe_url( $rfc3161['endpoint'] ) ) {
			$rfc3161['endpoint'] = '';
		}
		$rfc3161['user']     = sanitize_text_field( (string) ( $_POST['rfc3161_user'] ?? '' ) );
		$new_pass            = (string) wp_unslash( $_POST['rfc3161_pass'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Basic-auth secret kept verbatim.
		if ( '' !== $new_pass ) {
			$rfc3161['pass'] = $new_pass;
		}
		$timestamp['rfc3161'] = $rfc3161;

		update_option( 'wwu_wb_timestamp', $timestamp );

		// Integrations: outbound webhook. The URL passes through the SSRF guard at
		// save time (the dispatcher re-checks at send time); the secret uses the
		// "leave blank to keep" pattern (+ optional regenerate), so the stored HMAC
		// key is never re-emitted to the browser. Enabling without a secret mints one
		// so every delivery is signed.
		$webhook            = (array) get_option( 'wwu_wb_webhook', array() );
		$webhook['enabled'] = Sanitizer::bool( $_POST['webhook_enabled'] ?? '' );
		$webhook_url        = esc_url_raw( trim( (string) ( $_POST['webhook_url'] ?? '' ) ) );
		if ( '' !== $webhook_url && ! OutboundUrlGuard::is_safe_url( $webhook_url ) ) {
			$webhook_url = '';
		}
		$webhook['url'] = $webhook_url;

		$current_secret = (string) ( $webhook['secret'] ?? '' );
		$posted_secret  = trim( (string) wp_unslash( $_POST['webhook_secret'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- HMAC key kept verbatim; capped below.
		if ( Sanitizer::bool( $_POST['webhook_regenerate'] ?? '' ) ) {
			$webhook['secret'] = Webhook::generate_secret();
		} elseif ( '' !== $posted_secret ) {
			$webhook['secret'] = substr( $posted_secret, 0, 128 );
		} elseif ( $webhook['enabled'] && '' === $current_secret ) {
			$webhook['secret'] = Webhook::generate_secret();
		} else {
			$webhook['secret'] = $current_secret;
		}
		update_option( 'wwu_wb_webhook', $webhook );

		// If the account-tab slug changed, refresh rewrite rules so it works immediately.
		if ( $new_slug !== $old_slug ) {
			flush_rewrite_rules( false );
		}

		// Debug audience.
		$debug = Audience::config();
		$debug['enabled']       = Sanitizer::bool( $_POST['debug_enabled'] ?? '' );
		$debug['mode']          = Sanitizer::enum(
			$_POST['debug_mode'] ?? '',
			array(
				Audience::MODE_ALL_ADMINS,
				Audience::MODE_SPECIFIC_ROLES,
				Audience::MODE_SPECIFIC_USERS,
				Audience::MODE_CURRENT_USER_ONLY,
			),
			Audience::MODE_ALL_ADMINS
		);
		$debug['console_level'] = Sanitizer::enum(
			$_POST['debug_console_level'] ?? '',
			array( 'silent', 'error', 'warn', 'info', 'debug' ),
			'warn'
		);
		update_option( 'wwu_wb_debug', $debug );
		Audience::reset_cache();
		\WWU\WithdrawalButton\Core\Settings::flush();
		\WWU\WithdrawalButton\Compat\Complianz::bust_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => AdminController::SETTINGS_SLUG,
					'wwu_wb_saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Parse a comma-separated ID list into a clean array of positive ints.
	 *
	 * @param mixed $value Raw input (string or array).
	 * @return int[]
	 */
	private function parse_id_list( $value ): array {
		$value = is_array( $value ) ? implode( ',', $value ) : (string) $value;
		$ids   = array_map( 'intval', array_map( 'trim', explode( ',', $value ) ) );
		$ids   = array_filter(
			$ids,
			static function ( $id ) {
				return $id > 0;
			}
		);
		return array_values( array_unique( $ids ) );
	}
}
