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

		try {
			$this->_handleAjax( $action );
		} catch ( \WPAjaxDieContinueException $e ) {
			// Normal termination — output already captured by dieHandler().
		} catch ( \WPAjaxDieStopException $e ) {
			// wp_die() with no prior output — guard returned an empty body.
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