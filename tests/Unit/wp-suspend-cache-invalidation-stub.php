<?php
/**
 * Stub of WordPress's wp_suspend_cache_invalidation() for unit tests.
 *
 * The SScribe_Batch_Step_Handler trait calls wp_suspend_cache_invalidation()
 * on both edges of the per-page batch loop. The unit bootstrap stubs most
 * of the WordPress function surface but not this one, and the trait resolves
 * it unqualified from the GLOBAL namespace, so the fallback lives here in a
 * global-namespace file (same pattern as wp-filesystem-base-stub.php).
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( ! function_exists( 'wp_suspend_cache_invalidation' ) ) {
	function wp_suspend_cache_invalidation( $sscribe_suspended = true ) {
		$GLOBALS['sscribe_test_cache_invalidation_suspended'] = (bool) $sscribe_suspended;
		return (bool) $sscribe_suspended;
	}
}
