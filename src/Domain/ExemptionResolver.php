<?php
/**
 * Resolves which statutory exemption reason (if any) applies to a line item.
 *
 * Reads the `wwu_wb_exclusions` option in its per-reason shape
 * `{ by_reason: { '<reason>': { products:[], categories:[] }, ... } }` and answers
 * "what reason, if any, tags this item?". Back-compat: the legacy flat
 * `excluded_product_ids` / `excluded_category_ids` lists (and the
 * `wwu_wb_excluded_product_ids` filter) are folded into the generic `manual`
 * reason at read time, so old installs keep working before/without migration.
 *
 * This only maps an item to a reason. Whether that reason actually REMOVES the
 * withdrawal right (unconditional vs consent-gated vs seal-based) is decided by
 * {@see ArticleFiftyNineEvaluator}, using {@see ExceptionTypes}.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Domain;

use WWU\WithdrawalButton\Platform\NormalizedOrder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-item exemption-reason resolver.
 */
final class ExemptionResolver {

	/**
	 * The exemption reason id tagging an item, or null when it is not exempt.
	 *
	 * @param array<string,mixed> $item  Normalized line item.
	 * @param NormalizedOrder     $order Order (for the back-compat filter context).
	 * @return string|null Reason id (a key of ExceptionTypes::all()) or null.
	 */
	public static function reason_for_item( array $item, NormalizedOrder $order ): ?string {
		$product_id   = (int) ( $item['product_id'] ?? 0 );
		$category_ids = array_map( 'intval', (array) ( $item['category_ids'] ?? array() ) );

		// Back-compat filter (legacy integrators) → treated as a 'manual' exclusion.
		$filtered = array_map( 'intval', (array) apply_filters( 'wwu_wb_excluded_product_ids', array(), $order ) );
		if ( $product_id > 0 && in_array( $product_id, $filtered, true ) ) {
			return 'manual';
		}

		foreach ( self::map() as $reason => $sets ) {
			$products   = array_map( 'intval', (array) ( $sets['products'] ?? array() ) );
			$categories = array_map( 'intval', (array) ( $sets['categories'] ?? array() ) );

			if ( $product_id > 0 && in_array( $product_id, $products, true ) ) {
				return (string) $reason;
			}
			if ( ! empty( $category_ids ) && ! empty( $categories ) && array_intersect( $category_ids, $categories ) ) {
				return (string) $reason;
			}
		}

		return null;
	}

	/**
	 * The per-reason exclusion map, with legacy flat lists folded into 'manual'.
	 *
	 * @return array<string,array{products:int[],categories:int[]}>
	 */
	public static function map(): array {
		$opt = (array) \WWU\WithdrawalButton\Core\Settings::get( 'wwu_wb_exclusions' );

		$by_reason = ( isset( $opt['by_reason'] ) && is_array( $opt['by_reason'] ) ) ? $opt['by_reason'] : array();

		// Fold legacy flat lists into the generic 'manual' reason (read-time, lossless).
		$flat_prods = array_map( 'intval', (array) ( $opt['excluded_product_ids'] ?? array() ) );
		$flat_cats  = array_map( 'intval', (array) ( $opt['excluded_category_ids'] ?? array() ) );
		if ( ! empty( $flat_prods ) || ! empty( $flat_cats ) ) {
			$manual               = isset( $by_reason['manual'] ) && is_array( $by_reason['manual'] ) ? $by_reason['manual'] : array();
			$manual['products']   = array_values( array_unique( array_merge( array_map( 'intval', (array) ( $manual['products'] ?? array() ) ), $flat_prods ) ) );
			$manual['categories'] = array_values( array_unique( array_merge( array_map( 'intval', (array) ( $manual['categories'] ?? array() ) ), $flat_cats ) ) );
			$by_reason['manual']  = $manual;
		}

		// Drop reasons that are no longer registered (e.g. a removed custom type).
		foreach ( array_keys( $by_reason ) as $reason ) {
			if ( ! ExceptionTypes::exists( (string) $reason ) ) {
				unset( $by_reason[ $reason ] );
			}
		}

		return $by_reason;
	}

	/**
	 * Whether the merchant has tagged ANY product/category with an exemption reason.
	 *
	 * @return bool
	 */
	public static function has_any(): bool {
		foreach ( self::map() as $sets ) {
			if ( ! empty( $sets['products'] ) || ! empty( $sets['categories'] ) ) {
				return true;
			}
		}
		return false;
	}
}
