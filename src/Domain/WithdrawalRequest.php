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

	/** @var string Consumer name (Art. 11a(2)(a)). */
	public $name;

	/** @var string Identified contract (Art. 11a(2)(b)). */
	public $order_ref;

	/** @var string Electronic means for the acknowledgement (Art. 11a(2)(c)). */
	public $email;

	/** @var string Optional reason (never required by law). */
	public $reason;

	/** @var array Optional list of product names the consumer is withdrawing from (never required; empty = whole order). */
	public array $products = array();

	/**
	 * Optional per-line quantity the consumer is withdrawing from (map: product name => int).
	 * Recorded ONLY when the consumer entered a partial quantity; a missing entry means the
	 * whole line. Additive — `products` keeps its flat-string shape (frozen log + REST contract).
	 *
	 * @var array<string,int>
	 */
	public array $product_quantities = array();

	/**
	 * Constructor.
	 *
	 * @param string $name      Consumer name (Art. 11a(2)(a)).
	 * @param string $order_ref Identified contract (Art. 11a(2)(b)).
	 * @param string $email     Electronic means for the acknowledgement (Art. 11a(2)(c)).
	 * @param string $reason    Optional reason (never required by law).
	 */
	public function __construct( string $name, string $order_ref, string $email, string $reason = '' ) {
		$this->name      = $name;
		$this->order_ref = $order_ref;
		$this->email     = $email;
		$this->reason    = $reason;
	}

	/**
	 * Build + sanitise from raw request data.
	 *
	 * @param array $data Raw input.
	 * @return WithdrawalRequest
	 */
	public static function from_input( array $data ): WithdrawalRequest {
		// Length caps applied AFTER sanitising: defence against bloating the
		// append-only immutable log (a permanent, non-deletable LONGTEXT row) and
		// against heavy outbound e-mail / PDF renders from an oversized field. A
		// legitimate statement is far below these limits.
		$instance = new self(
			self::cap( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 200 ),
			self::cap( sanitize_text_field( (string) ( $data['order_ref'] ?? '' ) ), 100 ),
			self::cap( sanitize_email( (string) ( $data['email'] ?? '' ) ), 254 ),
			self::cap( sanitize_textarea_field( (string) ( $data['reason'] ?? '' ) ), 2000 )
		);

		/* Sanitise the optional products list (DoS guards: 50 items max, 200 chars each). */
		$raw_products = isset( $data['products'] ) && is_array( $data['products'] ) ? $data['products'] : array();
		$sanitized    = array();
		foreach ( array_slice( $raw_products, 0, 50 ) as $item ) {
			$v = self::cap( sanitize_text_field( (string) $item ), 200 );
			if ( '' !== $v ) {
				$sanitized[] = $v;
			}
		}
		$instance->products = array_values( $sanitized );

		/*
		 * Sanitise the optional per-product quantities (map: name => int). Same 50-key DoS
		 * cap as the products list. A value is kept ONLY when it is a positive int for a
		 * product that is also selected; a blank/invalid value is dropped, which means
		 * "the whole line" (fail-open toward the consumer's right). The shipped form leaves
		 * the field blank by default, so an untouched line records nothing.
		 */
		$raw_qty   = isset( $data['product_qty'] ) && is_array( $data['product_qty'] ) ? $data['product_qty'] : array();
		$selected  = array_flip( $instance->products );
		$qty_clean = array();
		foreach ( array_slice( $raw_qty, 0, 50, true ) as $name => $qty ) {
			$name = self::cap( sanitize_text_field( (string) $name ), 200 );
			if ( '' === $name || ! isset( $selected[ $name ] ) ) {
				continue;
			}
			$q = (int) $qty;
			if ( $q < 1 ) {
				continue;
			}
			$qty_clean[ $name ] = min( $q, 100000 );
		}
		$instance->product_quantities = $qty_clean;

		return $instance;
	}

	/**
	 * Cap a string to a maximum character length (multibyte-safe).
	 *
	 * @param string $value Value.
	 * @param int    $max   Maximum length.
	 * @return string
	 */
	private static function cap( string $value, int $max ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
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
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'name'      => $this->name,
			'order_ref' => $this->order_ref,
			'email'     => $this->email,
			'reason'    => $this->reason,
			'products'  => $this->products,
			'product_quantities' => $this->product_quantities,
		);
	}
}
