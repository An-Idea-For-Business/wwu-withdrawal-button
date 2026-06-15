<?php
/**
 * SSRF guard for merchant-configured outbound URLs (the RFC 3161 TSA endpoint).
 *
 * WordPress core's `wp_http_validate_url()` is NOT a sufficient SSRF boundary for a
 * user-supplied URL: its IPv4 private-range list omits 169.254.0.0/16 (link-local /
 * cloud-metadata 169.254.169.254) and 100.64.0.0/10 (CGNAT), and it does not handle
 * IPv6 at all (so `http://[::1]/`, `http://[fd00::1]/`, `http://[::ffff:169.254.169.254]/`
 * all pass). This guard closes that gap: scheme allow-list + host resolved to its A/AAAA
 * records, every resolved address rejected if it falls in a private/reserved/link-local
 * range (IPv4 and IPv6, including IPv4-mapped IPv6 and CGNAT). Unresolvable hosts are
 * rejected (fail-closed). Mirrors the SgtmGuard pattern proven in WWU Pixel Manager.
 *
 * Keep `redirection => 0` on the actual request so a host that validates here cannot
 * 30x-rebind to an internal target after the check (TOCTOU/DNS-rebinding defence).
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outbound URL safety guard.
 */
final class OutboundUrlGuard {

	/**
	 * Whether a user-supplied URL is safe to fetch (no SSRF to internal targets).
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	public static function is_safe_url( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return false;
		}

		$host = trim( (string) $parts['host'], '[]' ); // Strip IPv6 literal brackets.
		if ( '' === $host ) {
			return false;
		}

		// Reject loopback hostnames outright (they may not resolve via DNS).
		if ( in_array( strtolower( $host ), array( 'localhost', 'ip6-localhost', 'ip6-loopback' ), true ) ) {
			return false;
		}

		$ips = self::resolve_ips( $host );
		if ( empty( $ips ) ) {
			return false; // Unresolvable → fail closed.
		}

		foreach ( $ips as $ip ) {
			if ( ! self::ip_is_public( $ip ) ) {
				return false; // ANY resolved address in a blocked range rejects the URL.
			}
		}

		return true;
	}

	/**
	 * Resolve a host to its IP literals (the host itself if already an IP, else its
	 * A + AAAA records).
	 *
	 * @param string $host Host or IP literal.
	 * @return string[]
	 */
	private static function resolve_ips( string $host ): array {
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}

		$ips = array();

		$a = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- false on failure, handled.
		if ( is_array( $a ) ) {
			$ips = array_merge( $ips, $a );
		}

		if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
			$aaaa = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- false on failure, handled.
			if ( is_array( $aaaa ) ) {
				foreach ( $aaaa as $record ) {
					if ( ! empty( $record['ipv6'] ) ) {
						$ips[] = (string) $record['ipv6'];
					}
				}
			}
		}

		return array_values( array_unique( $ips ) );
	}

	/**
	 * Whether an IP literal is a public, routable address (not private/reserved/
	 * loopback/link-local/CGNAT/IPv4-mapped-internal).
	 *
	 * @param string $ip IP literal.
	 * @return bool
	 */
	private static function ip_is_public( string $ip ): bool {
		// PHP's own private+reserved filter covers most of it: IPv4 10/8, 172.16/12,
		// 192.168/16, 127/8, 169.254/16 (link-local + cloud metadata), 0/8, 240/4,
		// 192.0.2/24; IPv6 ::1, ::, fc00::/7 (ULA), fe80::/10 (link-local).
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- false on failure, handled.
		if ( false === $packed ) {
			return false;
		}

		// IPv4-mapped IPv6 (::ffff:a.b.c.d) — extract the embedded IPv4 and re-check it,
		// so ::ffff:169.254.169.254 / ::ffff:127.0.0.1 cannot smuggle an internal v4 in.
		if ( 16 === strlen( $packed ) && "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" === substr( $packed, 0, 12 ) ) {
			$embedded = inet_ntop( substr( $packed, 12 ) );
			return is_string( $embedded ) && self::ip_is_public( $embedded );
		}

		// CGNAT 100.64.0.0/10 — not covered by PHP's reserved-range filter.
		if ( 4 === strlen( $packed ) ) {
			$long = ip2long( $ip );
			if ( false !== $long && ( $long & 0xFFC00000 ) === ( ip2long( '100.64.0.0' ) & 0xFFC00000 ) ) {
				return false;
			}
		}

		return true;
	}
}
