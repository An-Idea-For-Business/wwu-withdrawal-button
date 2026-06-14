<?php
/**
 * RFC 3161 trusted-timestamp provider.
 *
 * Sends the log row's SHA-256 digest to a Time-Stamp Authority (TSA) using the
 * RFC 3161 Time-Stamp Protocol and stores the signed response token as proof.
 * Unlike OpenTimestamps (which anchors to Bitcoin asynchronously), an RFC 3161
 * token is FINAL the moment the TSA responds — so stamps are confirmed
 * immediately and never need the upgrade cron.
 *
 * The request/response ASN.1 is hand-rolled (a TimeStampReq is a small, fixed
 * DER structure) so there is no dependency on the `openssl ts` CLI (often
 * disabled on shared hosting) and no extra library.
 *
 * Works with any RFC 3161 TSA by configuration (endpoint + optional Basic auth):
 *   - free, no account:  http://timestamp.sectigo.com  (and /qualified = eIDAS)
 *                        http://timestamp.digicert.com
 *   - eIDAS qualified QTSPs (account): Aruba, InfoCert, D-Trust, Universign,
 *     FNMT, SwissSign … — same protocol, just an authenticated endpoint.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Timestamp;

use WWU\WithdrawalButton\Debug\Debug;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RFC 3161 provider.
 */
final class Rfc3161Provider implements TimestampProvider {

	/**
	 * DER bytes of the SHA-256 algorithm OID (2.16.840.1.101.3.4.2.1).
	 *
	 * @var string
	 */
	private const OID_SHA256 = "\x06\x09\x60\x86\x48\x01\x65\x03\x04\x02\x01";

	/**
	 * Configured TSA endpoint URL.
	 *
	 * @var string
	 */
	private $endpoint;

	/**
	 * Optional Basic-auth username.
	 *
	 * @var string
	 */
	private $user;

	/**
	 * Optional Basic-auth password.
	 *
	 * @var string
	 */
	private $pass;

	/**
	 * Constructor.
	 *
	 * @param array $config { endpoint, user, pass }.
	 */
	public function __construct( array $config ) {
		$this->endpoint = trim( (string) ( $config['endpoint'] ?? '' ) );
		$this->user     = (string) ( $config['user'] ?? '' );
		$this->pass     = (string) ( $config['pass'] ?? '' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'rfc3161';
	}

	/**
	 * {@inheritDoc}
	 */
	public function stamp( string $sha256_hex ): ?array {
		$digest = @hex2bin( $sha256_hex ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $digest || 32 !== strlen( $digest ) ) {
			return null;
		}
		if ( ! $this->endpoint_is_valid() ) {
			Debug::warn( 'timestamp', 'rfc3161.endpoint_invalid', array() );
			return null;
		}

		try {
			$nonce = random_bytes( 8 );
		} catch ( \Exception $e ) {
			$nonce = wp_generate_password( 8, true, true );
		}

		$request = $this->build_request( $digest, $nonce );

		$headers = array( 'Content-Type' => 'application/timestamp-query' );
		if ( '' !== $this->user ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $this->user . ':' . $this->pass );
		}

		$response = wp_remote_post(
			$this->endpoint,
			array(
				'body'                => $request,
				'headers'             => $headers,
				'timeout'             => 15,
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			Debug::warn( 'timestamp', 'rfc3161.request_failed', array( 'code' => is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response ) ) );
			return null;
		}

		$body   = (string) wp_remote_retrieve_body( $response );
		$status = $this->read_status( $body );
		if ( null === $status || ( 0 !== $status && 1 !== $status ) ) {
			// 0 = granted, 1 = grantedWithMods; anything else is a rejection.
			Debug::warn( 'timestamp', 'rfc3161.rejected', array( 'status' => $status ) );
			return null;
		}

		Debug::info( 'timestamp', 'rfc3161.stamped', array( 'bytes' => strlen( $body ) ) );

		return array(
			'nonce_hex'  => bin2hex( $nonce ),
			'proof_blob' => $body,
			'pending'    => false, // RFC 3161 token is final immediately.
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * RFC 3161 tokens are complete at stamp time; there is nothing to upgrade.
	 */
	public function upgrade( array $stamp ): ?array {
		return null;
	}

	/**
	 * Whether the configured endpoint is a syntactically valid http(s) URL.
	 *
	 * @return bool
	 */
	private function endpoint_is_valid(): bool {
		if ( '' === $this->endpoint ) {
			return false;
		}
		$scheme = strtolower( (string) wp_parse_url( $this->endpoint, PHP_URL_SCHEME ) );
		return ( 'http' === $scheme || 'https' === $scheme ) && false !== wp_http_validate_url( $this->endpoint );
	}

	/**
	 * Build the DER-encoded TimeStampReq.
	 *
	 * TimeStampReq ::= SEQUENCE {
	 *   version        INTEGER { v1(1) },
	 *   messageImprint MessageImprint,   -- SEQUENCE { AlgorithmIdentifier, OCTET STRING }
	 *   nonce          INTEGER OPTIONAL,
	 *   certReq        BOOLEAN DEFAULT FALSE
	 * }
	 *
	 * @param string $digest 32 raw bytes (SHA-256).
	 * @param string $nonce  Random nonce bytes.
	 * @return string DER bytes.
	 */
	private function build_request( string $digest, string $nonce ): string {
		$algorithm = $this->der_seq( self::OID_SHA256 . "\x05\x00" );          // AlgorithmIdentifier (sha256 + NULL).
		$imprint   = $this->der_seq( $algorithm . $this->der_octet( $digest ) ); // MessageImprint.
		$version   = "\x02\x01\x01";                                            // INTEGER 1.
		$nonce_int = $this->der_int( $this->positive_int_bytes( $nonce ) );     // INTEGER nonce.
		$cert_req  = "\x01\x01\xff";                                            // BOOLEAN TRUE (ask for the TSA cert).

		return $this->der_seq( $version . $imprint . $nonce_int . $cert_req );
	}

	/**
	 * Read the PKIStatus integer from a TimeStampResp.
	 *
	 * TimeStampResp ::= SEQUENCE { status PKIStatusInfo, timeStampToken … }
	 * PKIStatusInfo ::= SEQUENCE { status PKIStatus (INTEGER), … }
	 *
	 * @param string $der Response bytes.
	 * @return int|null Status, or null if it could not be parsed.
	 */
	private function read_status( string $der ): ?int {
		$pos = 0;
		// Outer SEQUENCE.
		if ( ! $this->expect_tag( $der, $pos, 0x30 ) ) {
			return null;
		}
		// PKIStatusInfo SEQUENCE.
		if ( ! $this->expect_tag( $der, $pos, 0x30 ) ) {
			return null;
		}
		// status INTEGER.
		if ( ! isset( $der[ $pos ] ) || "\x02" !== $der[ $pos ] ) {
			return null;
		}
		++$pos;
		$len = $this->read_length( $der, $pos );
		if ( $len < 1 || ! isset( $der[ $pos + $len - 1 ] ) ) {
			return null;
		}
		$value = 0;
		for ( $i = 0; $i < $len; $i++ ) {
			$value = ( $value << 8 ) | ord( $der[ $pos + $i ] );
		}
		return $value;
	}

	/**
	 * Advance past a tag + length, leaving $pos at the start of the contents.
	 *
	 * @param string $der DER bytes.
	 * @param int    $pos Cursor (by reference).
	 * @param int    $tag Expected tag byte.
	 * @return bool
	 */
	private function expect_tag( string $der, int &$pos, int $tag ): bool {
		if ( ! isset( $der[ $pos ] ) || ord( $der[ $pos ] ) !== $tag ) {
			return false;
		}
		++$pos;
		$this->read_length( $der, $pos );
		return true;
	}

	/**
	 * Read a DER length at $pos, advancing $pos to the content start.
	 *
	 * @param string $der DER bytes.
	 * @param int    $pos Cursor (by reference).
	 * @return int Content length.
	 */
	private function read_length( string $der, int &$pos ): int {
		if ( ! isset( $der[ $pos ] ) ) {
			return 0;
		}
		$first = ord( $der[ $pos ] );
		++$pos;
		if ( $first < 0x80 ) {
			return $first;
		}
		$bytes = $first & 0x7f;
		$len   = 0;
		for ( $i = 0; $i < $bytes; $i++ ) {
			if ( ! isset( $der[ $pos ] ) ) {
				break;
			}
			$len = ( $len << 8 ) | ord( $der[ $pos ] );
			++$pos;
		}
		return $len;
	}

	/**
	 * DER SEQUENCE wrapper.
	 *
	 * @param string $contents Encoded contents.
	 * @return string
	 */
	private function der_seq( string $contents ): string {
		return "\x30" . $this->der_len( strlen( $contents ) ) . $contents;
	}

	/**
	 * DER OCTET STRING.
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	private function der_octet( string $bytes ): string {
		return "\x04" . $this->der_len( strlen( $bytes ) ) . $bytes;
	}

	/**
	 * DER INTEGER from already-normalised positive content bytes.
	 *
	 * @param string $bytes Positive integer content bytes.
	 * @return string
	 */
	private function der_int( string $bytes ): string {
		return "\x02" . $this->der_len( strlen( $bytes ) ) . $bytes;
	}

	/**
	 * Normalise random bytes into a positive DER INTEGER content.
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	private function positive_int_bytes( string $bytes ): string {
		if ( '' === $bytes ) {
			return "\x01";
		}
		// Clear the high bit of the first byte so the integer is positive, and
		// avoid a leading 0x00 that would be a non-minimal encoding.
		$first = ord( $bytes[0] ) & 0x7f;
		if ( 0 === $first ) {
			$first = 0x01;
		}
		return chr( $first ) . substr( $bytes, 1 );
	}

	/**
	 * Encode a DER length.
	 *
	 * @param int $len Length.
	 * @return string
	 */
	private function der_len( int $len ): string {
		if ( $len < 0x80 ) {
			return chr( $len );
		}
		$out = '';
		while ( $len > 0 ) {
			$out  = chr( $len & 0xff ) . $out;
			$len >>= 8;
		}
		return chr( 0x80 | strlen( $out ) ) . $out;
	}
}
