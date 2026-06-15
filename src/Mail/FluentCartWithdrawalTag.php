<?php
/**
 * FluentCart e-mail merge tag: {{wwu.recesso_url}}.
 *
 * Lets a merchant drop the per-order right-of-withdrawal link into FluentCart's OWN
 * transactional e-mails (order receipt, etc.) — the Recital 37 hyperlink, the canonical
 * guest path. It registers the tag in the FluentCart e-mail-editor picker and resolves
 * it at send time.
 *
 * Hook contract verified with the FluentCart team (2026-06-15,
 * docs/analysis/wwu-wb-fluentcart-hooks-ANALYSIS.md §"Third verification round"):
 *   - picker  → `fluent_cart/editor_shortcodes` (group keyed by slug, `shortcodes` map);
 *   - resolve → `fluent_cart/smartcode_fallback`, whose `$data` is the current rendering
 *     context. In order e-mails it carries `order`/`customer`/transaction; in subscription
 *     e-mails `subscription`/`order`/`customer`/`transactions`. CRUCIALLY the same hook
 *     also fires in footers / generic template parsing where `$data` is EMPTY — so we
 *     MUST verify `$data['order']` exists before building a per-order URL (the team's
 *     explicit guidance), and bail to '' otherwise.
 *
 * The callback is shape-tolerant: the team noted the resolver is usually called
 * `($code, $data)` but one parser path passes `($value, $code, $data, $conditions)`.
 * We disambiguate by the type of the second arg and never clobber another plugin's
 * fallback value.
 *
 * Fail-safe like every other surface: returns '' when withdrawal is disabled, no public
 * form page is set, the order is ineligible, or no order context is present. **Needs a
 * live FluentCart test** — the resolver shape cannot be exercised without a FluentCart
 * install.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Mail;

use WWU\WithdrawalButton\Core\Services;
use WWU\WithdrawalButton\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FluentCart withdrawal-URL merge tag.
 */
final class FluentCartWithdrawalTag {

	/**
	 * The tag id (FluentCart dotted convention: {{wwu.recesso_url}}).
	 *
	 * @var string
	 */
	private const TAG = 'wwu.recesso_url';

	/**
	 * Register the picker entry + the resolver.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'fluent_cart/editor_shortcodes', array( $this, 'register_picker' ), 10, 1 );
		add_filter( 'fluent_cart/smartcode_fallback', array( $this, 'resolve' ), 10, 4 );
	}

	/**
	 * Add the tag to the FluentCart e-mail-editor shortcode picker.
	 *
	 * @param mixed $groups Existing shortcode groups (array expected).
	 * @return array
	 */
	public function register_picker( $groups ): array {
		$groups = is_array( $groups ) ? $groups : array();

		$groups['wwu_wb'] = array(
			'title'      => __( 'Right of withdrawal', 'wwu-withdrawal-button' ),
			'key'        => 'wwu_wb',
			'shortcodes' => array(
				'{{' . self::TAG . '}}' => __( 'Withdrawal link for this order', 'wwu-withdrawal-button' ),
			),
		);

		return $groups;
	}

	/**
	 * Resolve {{wwu.recesso_url}} at send time. Shape-tolerant + never clobbers a
	 * value that is not ours.
	 *
	 * @param mixed $a First positional arg (the filtered value, or the code).
	 * @param mixed $b Second positional arg (the data array, or the code).
	 * @param mixed $c Third positional arg (the data array in the 4-arg shape).
	 * @param mixed $d Fourth positional arg (conditions; unused).
	 * @return mixed
	 */
	public function resolve( $a = '', $b = '', $c = array(), $d = array() ) {
		// Disambiguate the two documented call shapes:
		//   2-arg: apply_filters(hook, $code, $data)            → $b is the data.
		//   4-arg: apply_filters(hook, $value, $code, $data, …) → $b is the code.
		if ( is_array( $b ) || is_object( $b ) ) {
			$code = (string) $a;
			$data = $b;
		} else {
			$code = (string) $b;
			$data = $c;
		}

		if ( self::TAG !== trim( $code, "{} \t\n\r" ) ) {
			return $a; // Not our tag — return the filtered value unchanged.
		}

		return $this->build_url( $data );
	}

	/**
	 * Build the per-order withdrawal URL from the FluentCart rendering context, or ''
	 * when there is no usable/eligible order (incl. the empty-context case).
	 *
	 * @param mixed $data FluentCart smartcode data (array/object expected).
	 * @return string
	 */
	private function build_url( $data ): string {
		if ( ! Settings::enabled() ) {
			return '';
		}

		// $data may be EMPTY (footers / generic parsing) — require an order object.
		$order = null;
		if ( is_array( $data ) && isset( $data['order'] ) ) {
			$order = $data['order'];
		} elseif ( is_object( $data ) && isset( $data->order ) ) {
			$order = $data->order;
		}
		if ( ! is_object( $order ) ) {
			return '';
		}

		$order_id = (int) ( $order->id ?? 0 );
		if ( $order_id <= 0 ) {
			return '';
		}

		$adapter = Services::instance()->platforms->get( 'fluentcart' );
		if ( ! $adapter ) {
			return '';
		}
		$normalized = $adapter->get_order( (string) $order_id );
		if ( ! $normalized || ! Services::instance()->applicability->decide( $normalized )->show ) {
			return '';
		}

		$page_id = (int) ( Settings::main()['public_form_page_id'] ?? 0 );
		if ( $page_id <= 0 ) {
			return '';
		}
		$permalink = get_permalink( $page_id );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return '';
		}

		$args = array( 'wwu_wb_order' => rawurlencode( $normalized->order_ref ) );
		// Carry the order's own key for guest authentication (FluentCart order hash/uuid),
		// mirroring the WooCommerce order-email link.
		$key = (string) ( $order->order_hash ?? $order->uuid ?? '' );
		if ( '' !== $key ) {
			$args['key'] = rawurlencode( $key );
		}

		return esc_url_raw( add_query_arg( $args, $permalink ) );
	}
}
