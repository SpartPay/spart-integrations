<?php
/**
 * PHPStan bootstrap — defines WordPress constants that are provided at
 * runtime by WordPress core but are absent from the WP stubs package.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
