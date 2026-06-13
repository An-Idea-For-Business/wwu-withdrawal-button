<?php
/**
 * Value object for a consumer's withdrawal statement (Art. 11a(2) data).
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable withdrawal statement.
 */
final class WithdrawalRequest {

	/**
	 * Constructor.
	 *
	 * @param string $name      Consumer name (Art. 11a(2)(a)).
	 * @param string $order_ref Identified contract (Art. 11a(2)(b)).
	 * @param string $email     Electronic means for the acknowledgement (Art. 11a(2)(c)).
	 * @param string $reason    Optional reason (never required by law).
	 */
	public function __construct(
		public string $name,
		public string $order_ref,
		public string $email,
		public string $reason = ''
	) {}

	/**
	 * Build + sanitise from raw request data.
	 *
	 * @param array $data Raw input.
	 * @return WithdrawalRequest
	 */
	public static function from_input( array $data ): WithdrawalRequest {
		return new self(
			sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			sanitize_text_field( (string) ( $data['order_ref'] ?? '' ) ),
			sanitize_email( (string) ( $data['email'] ?? '' ) ),
			sanitize_textarea_field( (string) ( $data['reason'] ?? '' ) )
		);
	}

	/**
	 * Whether the mandatory statement fields are present.
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return '' !== $this->name && '' !== $this->order_ref && is_email( $this->email );
	}

	/**
	 * Export as an array for the evidence payload.
	 *
	 * @return array<string,string>
	 */
	public function to_array(): array {
		return array(
			'name'      => $this->name,
			'order_ref' => $this->order_ref,
			'email'     => $this->email,
			'reason'    => $this->reason,
		);
	}
}
