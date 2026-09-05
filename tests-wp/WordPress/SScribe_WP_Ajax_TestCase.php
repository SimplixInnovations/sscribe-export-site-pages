<?php
/**
 * AJAX compat base class for SScribe WP-integration tests.
 *
 * Extends wp-phpunit's WP_Ajax_UnitTestCase so that `_handleAjax()` is
 * available. The only override is forwarding to {@see SScribe_WP_TestCase}
 * for PHPUnit 11 compat shims — but we can't multiply-inherit, so the
 * helper here just provides the getName() shim inline.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

abstract class SScribe_WP_Ajax_TestCase extends WP_Ajax_UnitTestCase {

	/**
	 * PHPUnit <= 9 signature: `getName( bool $with_args = true )`.
	 * PHPUnit 11 has `name()` instead.
	 *
	 * @param bool $with_args Whether to include the data-set suffix.
	 * @return string The current test method name.
	 */
	public function getName( bool $with_args = true ): string {
		$method = (string) $this->name();
		if ( ! $with_args ) {
			return $method;
		}
		$ds = '';
		if ( method_exists( $this, 'dataName' ) ) {
			$name = (string) $this->dataName();
			if ( '' !== $name ) {
				$ds = '(' . $name . ')';
			}
		}
		return '' === $ds ? $method : $method . $ds;
	}

	protected function checkRequirements(): void {
		\PHPUnit\Framework\TestCase::checkRequirements();
	}

	/**
	 * Dispatch an AJAX endpoint via `do_action('wp_ajax_…')` and capture
	 * the JSON response.
	 *
	 * The wp_die handler installed by {@see WP_Ajax_UnitTestCase::set_up()}
	 * collects buffered output into `$this->_last_response` and then throws
	 * {@see WPAjaxDieContinueException}. We swallow that — it's the normal
	 * termination path for `wp_send_json_*()` — and decode the captured
	 * JSON payload into a structured array.
	 *
	 * @param string $action The bare action name (without `wp_ajax_` prefix).
	 * @return array{0: bool, 1: array<string, mixed>, 2: string} Tuple of (success_flag, data, raw).
	 */
	protected function dispatch_ajax( string $action ): array {
		$this->_last_response = '';

		// Snapshot the output-buffer level so we can restore it after the
		// dispatch. PHPUnit 11 considers a test risky if tested code
		// (or its wp_die handler) closes output buffers it didn't open.
		// Some SScribe handlers — notably ajax_get_support_info via
		// SScribe_AJAX_Guard::log_cleaned_buffers() — call ob_clean() and
		// then wp_send_json_*() which calls wp_die → ob_get_clean(). The
		// net effect is that one extra buffer gets popped vs. what the
		// WP_Ajax_UnitTestCase::set_up() buffer started at, which PHPUnit
		// reports as "Test code or tested code closed output buffers
		// other than its own". Restoring the level here keeps the buffer
		// stack invariant for the next test in the suite.
		$start_ob_level = ob_get_level();

		try {
			$this->_handleAjax( $action );
		} catch ( \WPAjaxDieContinueException $e ) {
			// Normal termination — output already captured by dieHandler().
		} catch ( \WPAjaxDieStopException $e ) {
			// wp_die() with no prior output — guard returned an empty body.
		}

		// Re-open buffers the handler (or its wp_die cascade) closed so
		// the post-test buffer level matches the pre-dispatch snapshot.
		while ( ob_get_level() < $start_ob_level ) {
			ob_start();
		}
		// Drain any extra buffers the handler left behind so PHPUnit's
		// post-test ob-level check sees the same state as set_up() left.
		while ( ob_get_level() > $start_ob_level ) {
			ob_end_clean();
		}

		$raw = (string) $this->_last_response;
		if ( '' === $raw ) {
			return array( false, array(), $raw );
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array( false, array(), $raw );
		}

		$success = isset( $decoded['success'] ) && true === $decoded['success'];
		$data    = isset( $decoded['data'] ) && is_array( $decoded['data'] )
			? $decoded['data']
			: array();

		return array( $success, $data, $raw );
	}
}