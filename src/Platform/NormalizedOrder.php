<?php
/**
 * Platform-agnostic value object describing an order for the withdrawal flow.
 *
 * Adapters (WooCommerce, FluentCart) normalise their native order into this
 * shape so the Domain layer never touches platform internals.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Platform;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable normalized order.
 */
final class NormalizedOrder {

	/**
	 * Constructor.
	 *
	 * @param string                     $platform       Adapter key ('woocommerce'|'fluentcart').
	 * @param string                     $order_ref      Stable order reference (id/number as string).
	 * @param string                     $number         Human order number.
	 * @param string                     $email          Billing email.
	 * @param int                        $customer_id    User ID (0 for guest).
	 * @param string                     $country        Consumer country (billing → shipping).
	 * @param string                     $status         Native status slug (unprefixed).
	 * @param string                     $locale         Locale captured at checkout (may be empty).
	 * @param \DateTimeImmutable|null    $created        Order created date.
	 * @param \DateTimeImmutable|null    $paid           Paid date.
	 * @param \DateTimeImmutable|null    $completed      Completed/delivered date.
	 * @param array<int,array<string,mixed>> $items      Line items: [{product_id, name, qty, virtual, downloadable, type, category_ids}].
	 * @param bool                       $has_vat_number Whether a VAT/business number was provided.
	 */
	public function __construct(
		public string $platform,
		public string $order_ref,
		public string $number,
		public string $email,
		public int $customer_id,
		public string $country,
		public string $status,
		public string $locale,
		public ?\DateTimeImmutable $created,
		public ?\DateTimeImmutable $paid,
		public ?\DateTimeImmutable $completed,
		public array $items,
		public bool $has_vat_number
	) {}

	/**
	 * Whether this order belongs to a guest (no account).
	 *
	 * @return bool
	 */
	public function is_guest(): bool {
		return 0 === $this->customer_id;
	}

	/**
	 * Best available "start of withdrawal window" date.
	 *
	 * Italian law starts the 14-day clock from delivery for goods and from
	 * conclusion for services/digital. WooCommerce rarely knows the delivery
	 * date, so we approximate: completed → paid → created.
	 *
	 * @return \DateTimeImmutable|null
	 */
	public function window_start(): ?\DateTimeImmutable {
		return $this->completed ?? $this->paid ?? $this->created;
	}
}
