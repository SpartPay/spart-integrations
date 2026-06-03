<?php
/**
 * Tiny helpers shared between router.php and scenarios.php.
 *
 * Invoked from inside the PHP built-in server — NOT loaded by composer.
 */

declare(strict_types=1);

/**
 * Send a JSON response and terminate the script.
 *
 * @param int                  $status HTTP status code.
 * @param array<string, mixed> $body   Response payload.
 */
function stub_send_json( int $status, array $body ): void {
	http_response_code( $status );
	header( 'Content-Type: application/json' );
	echo json_encode( $body, JSON_UNESCAPED_SLASHES );
	exit;
}

/**
 * Read and decode the request body as JSON; returns [] on empty/invalid input.
 *
 * @return array<string, mixed>
 */
function stub_read_json_body(): array {
	$raw = (string) file_get_contents( 'php://input' );
	if ( '' === $raw ) {
		return array();
	}
	$decoded = json_decode( $raw, true );
	return is_array( $decoded ) ? $decoded : array();
}

/**
 * Spart's `Result<T>` envelope for successful intent creation.
 *
 * @return array<string, mixed>
 */
function stub_success_envelope( string $intent_short_id, string $checkout_url ): array {
	return array(
		'isSuccessful' => true,
		'value'        => array(
			'intentShortId' => $intent_short_id,
			'checkoutUrl'   => $checkout_url,
		),
		'error'        => null,
	);
}

/**
 * Spart's failure envelope.
 *
 * @param string $code           Machine-readable discriminator code, e.g. 'validation.failed'.
 * @param string $message        Human-readable explanation.
 * @param list<string>|null $details Optional list of validation messages exposed via additionalData.
 * @return array<string, mixed>
 */
function stub_failure_envelope( string $code, string $message, ?array $details = null ): array {
	return array(
		'isSuccessful' => false,
		'value'        => null,
		'error'        => array(
			'code'           => $code,
			'message'        => $message,
			'additionalData' => $details,
		),
	);
}
