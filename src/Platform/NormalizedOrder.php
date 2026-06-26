<?php
/**
 * Platform-agnostic value object describing an order for the withdrawal flow.
 *
 * Adapters (WooCommerce, FluentCart) normalise their native order into this
 * shape so the Domain layer never touches platform internals.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Platform;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable normalized order.
 */
final class NormalizedOrder {

	/** @var string Adapter key ('woocommerce'|'fluentcart'). */
	public $platform;

	/** @var string Stable order reference (id/number as string). */
	public $order_ref;

	/** @var string Human order number. */
	public $number;

	/** @var string Billing email. */
	public $email;

	/** @var int User ID (0 for guest). */
	public $customer_id;

	/** @var string Consumer country (billing → shipping). */
	public $country;

	/** @var string Native status slug (unprefixed). */
	public $status;

	/** @var string Locale captured at checkout (may be empty). */
	public $locale;

	/** @var \DateTimeImmutable|null Order created date. */
	public $created;

	/** @var \DateTimeImmutable|null Paid date. */
	public $paid;

	/** @var \DateTimeImmutable|null Completed/delivered date. */
	public $completed;

	/** @var array<int,array<string,mixed>> Line items. */
	public $items;

	/** @var bool Whether a VAT/business number was provided. */
	public $has_vat_number;

	/** @var bool Whether this is a subscription RENEWAL order (no fresh 14-day right). */
	public $is_renewal;

	/** @var string Platform subscription id tied to this order, or '' when not a subscription. */
	public $subscription_ref;

	/**
	 * Constructor.
	 *
	 * @param string                         $platform       Adapter key.
	 * @param string                         $order_ref      Stable order reference.
	 * @param string                         $number         Human order number.
	 * @param string                         $email          Billing email.
	 * @param int                            $customer_id    User ID (0 for guest).
	 * @param string                         $country        Consumer country.
	 * @param string                         $status         Native status slug (unprefixed).
	 * @param string                         $locale         Locale captured at checkout.
	 * @param \DateTimeImmutable|null        $created        Order created date.
	 * @param \DateTimeImmutable|null        $paid           Paid date.
	 * @param \DateTimeImmutable|null        $completed      Completed/delivered date.
	 * @param array<int,array<string,mixed>> $items            Line items.
	 * @param bool                           $has_vat_number   Whether a VAT/business number was provided.
	 * @param bool                           $is_renewal       Whether this is a subscription renewal order.
	 * @param string                         $subscription_ref Platform subscription id, or ''.
	 */
	public function __construct(
		string $platform,
		string $order_ref,
		string $number,
		string $email,
		int $customer_id,
		string $country,
		string $status,
		string $locale,
		?\DateTimeImmutable $created,
		?\DateTimeImmutable $paid,
		?\DateTimeImmutable $completed,
		array $items,
		bool $has_vat_number,
		bool $is_renewal = false,
		string $subscription_ref = ''
	) {
		$this->platform         = $platform;
		$this->order_ref        = $order_ref;
		$this->number           = $number;
		$this->email            = $email;
		$this->customer_id      = $customer_id;
		$this->country          = $country;
		$this->status           = $status;
		$this->locale           = $locale;
		$this->created          = $created;
		$this->paid             = $paid;
		$this->completed        = $completed;
		$this->items            = $items;
		$this->has_vat_number   = $has_vat_number;
		$this->is_renewal       = $is_renewal;
		$this->subscription_ref = $subscription_ref;
	}

	/**
	 * Whether this order belongs to a guest (no account).
	 *
	 * @return bool
	 */
	public function is_guest(): bool {
		return 0 === $this->customer_id;
	}

	/**
	 * Best available "start of withdrawal window" date (type-aware).
	 *
	 * Italian/EU law starts the 14-day clock from delivery for goods and from the
	 * conclusion of the contract for services / digital content (Art. 9 Dir.
	 * 2011/83/EU — Art. 52 Cod. Consumo). WooCommerce rarely knows the real
	 * delivery date, so we approximate:
	 *
	 *   - All-digital order (every item virtual/downloadable) → the ORDER date
	 *     (paid → created): the contract is concluded at purchase and is never
	 *     deferred to a later fulfilment/completed date.
	 *   - Otherwise (any tangible item, mixed cart, or unknown contents) →
	 *     completed → paid → created. This is UNCHANGED from earlier behaviour:
	 *     the completed date is the best delivery proxy, with a safe fall-back so
	 *     the countdown is never lost for shops that don't mark orders completed.
	 *
	 * Informational only — WindowCalculator never hides the button on this value;
	 * integrators with a real delivery date can refine it via the
	 * `webwakeupwdb_compute_deadline` filter.
	 *
	 * @return \DateTimeImmutable|null
	 */
	public function window_start(): ?\DateTimeImmutable {
		if ( $this->is_all_digital() ) {
			return $this->paid ?? $this->created;
		}
		return $this->completed ?? $this->paid ?? $this->created;
	}

	/**
	 * Whether every line item is digital (virtual or downloadable).
	 *
	 * Conservative by design: an empty or unknown item set returns false, so the
	 * order is treated as physical and the prior window-start behaviour is
	 * preserved (no disruption for non-WooCommerce platforms or carts we cannot
	 * classify).
	 *
	 * @return bool
	 */
	private function is_all_digital(): bool {
		if ( empty( $this->items ) || ! is_array( $this->items ) ) {
			return false;
		}
		foreach ( $this->items as $item ) {
			if ( ! is_array( $item ) ) {
				return false;
			}
			$virtual      = ! empty( $item['virtual'] );
			$downloadable = ! empty( $item['downloadable'] );
			if ( ! $virtual && ! $downloadable ) {
				return false;
			}
		}
		return true;
	}
}
