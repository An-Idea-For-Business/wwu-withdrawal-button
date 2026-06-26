<?php
/**
 * Outbound-webhook configuration + signing helpers.
 *
 * The secret is a shared HMAC key: it MUST be stored retrievable (we sign every
 * delivery with it) so it lives in the `webwakeupwdb_webhook` option (autoload no) and
 * is only ever shown masked in the admin UI. It is never written to a log, a
 * snapshot, or a debug entry. The signature scheme mirrors GitHub webhooks
 * (`sha256=<hex HMAC-SHA256(body, secret)>`) so receivers can use familiar
 * verification code.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Webhook config + crypto.
 */
final class Webhook {

	/**
	 * Option storing the webhook config (autoload no).
	 *
	 * @var string
	 */
	public const OPTION = 'webwakeupwdb_webhook';

	/**
	 * Single-event hook that performs an async delivery.
	 *
	 * @var string
	 */
	public const DELIVER_HOOK = 'webwakeupwdb_deliver_webhook';

	/**
	 * Read the webhook config with a stable shape.
	 *
	 * @return array{enabled:bool,url:string,secret:string}
	 */
	public static function config(): array {
		$cfg = (array) get_option( self::OPTION, array() );
		return array(
			'enabled' => ! empty( $cfg['enabled'] ),
			'url'     => isset( $cfg['url'] ) ? (string) $cfg['url'] : '',
			'secret'  => isset( $cfg['secret'] ) ? (string) $cfg['secret'] : '',
		);
	}

	/**
	 * Whether the webhook is fully configured (enabled + url + secret).
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		$c = self::config();
		return $c['enabled'] && '' !== $c['url'] && '' !== $c['secret'];
	}

	/**
	 * Compute the delivery signature for a body.
	 *
	 * @param string $body   Raw request body.
	 * @param string $secret Shared secret.
	 * @return string `sha256=<hex digest>`.
	 */
	public static function sign( string $body, string $secret ): string {
		return 'sha256=' . hash_hmac( 'sha256', $body, $secret );
	}

	/**
	 * Mask a secret for display (never reveal more than the last 4 chars).
	 *
	 * @param string $secret Secret.
	 * @return string
	 */
	public static function masked_secret( string $secret ): string {
		$len = strlen( $secret );
		if ( 0 === $len ) {
			return '';
		}
		if ( $len <= 4 ) {
			return str_repeat( "\u{2022}", $len );
		}
		return str_repeat( "\u{2022}", 10 ) . substr( $secret, -4 );
	}

	/**
	 * Generate a fresh high-entropy secret (alphanumeric, 40 chars).
	 *
	 * @return string
	 */
	public static function generate_secret(): string {
		return wp_generate_password( 40, false );
	}
}
