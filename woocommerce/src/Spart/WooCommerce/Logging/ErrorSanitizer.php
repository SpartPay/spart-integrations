<?php
/**
 * Logging\ErrorSanitizer — turns Throwables into safe-to-log strings.
 *
 * @package Spart\WooCommerce\Logging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Logging;

/**
 * Produces "<class basename>: <message>" strings from Throwables, with the
 * merchant API key redacted and the result truncated to 500 message-chars.
 *
 * No stack trace, no rawBody, no errorDetails are ever included — those can
 * leak credentials or PII into shared log files.
 */
final class ErrorSanitizer {

	private const MAX_MESSAGE_CHARS = 500;
	private const REDACTED_TOKEN    = '<redacted>';

	/**
	 * Convert a throwable to a safe single-line summary.
	 *
	 * Order of operations:
	 *  1. Replace every occurrence of `$api_key` with `<redacted>` (when a
	 *     non-empty key is provided).
	 *  2. Truncate the message to 500 characters.
	 *  3. Prefix with the short class name.
	 *
	 * @param \Throwable $e       Throwable to sanitize.
	 * @param string     $api_key Merchant API key to redact (empty disables redaction).
	 * @return string
	 */
	public static function sanitize( \Throwable $e, string $api_key = '' ): string {
		$class   = ( new \ReflectionClass( $e ) )->getShortName();
		$message = $e->getMessage();

		if ( '' !== $api_key ) {
			$message = str_replace( $api_key, self::REDACTED_TOKEN, $message );
		}

		if ( strlen( $message ) > self::MAX_MESSAGE_CHARS ) {
			$message = substr( $message, 0, self::MAX_MESSAGE_CHARS );
		}

		return $class . ': ' . $message;
	}
}
