<?php
/**
 * Standalone HMAC-SHA256 signer for the stub-spart sidecar.
 *
 * Mirrors the spart/sdk SignatureVerifier::verify() (spartpay/spart-sdks,
 * php/src/Webhooks/SignatureVerifier.php) — header
 * format `t=<unix-seconds>,v1=<lowercase-hex>` over the byte sequence
 * `"{t}.{rawBody}"`. The duplication is deliberate: stub-spart boots via
 * `php -S router.php` with no composer autoloader and must stay
 * dependency-free.
 */

declare(strict_types=1);

final class StubSpartSignatureSigner {

	/**
	 * Build a signed `X-Spart-Signature` header value for `$rawBody`.
	 *
	 * @param string   $rawBody       The exact bytes that will be POSTed (no
	 *                                re-encoding — sign and send identical
	 *                                strings or verification will fail).
	 * @param string   $signingSecret The shared HMAC secret.
	 * @param int|null $timestamp     Unix seconds. Defaults to time(). Pass
	 *                                a fixed value for deterministic tests.
	 *
	 * @return array{header: string, timestamp: int} The header value (suitable
	 *                                                for `X-Spart-Signature`)
	 *                                                and the timestamp used.
	 */
	public static function sign( string $rawBody, string $signingSecret, ?int $timestamp = null ): array {
		$t   = $timestamp ?? time();
		$sig = hash_hmac( 'sha256', $t . '.' . $rawBody, $signingSecret );
		return array(
			'header'    => sprintf( 't=%d,v1=%s', $t, $sig ),
			'timestamp' => $t,
		);
	}
}
