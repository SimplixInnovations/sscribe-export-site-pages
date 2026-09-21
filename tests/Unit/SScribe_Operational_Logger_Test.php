<?php
/**
 * SScribe Operational Logger Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Operational_Logger_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\SScribe_Operational_Logger::reset_for_testing();
	}

	protected function tearDown(): void {
		\SScribe_Operational_Logger::reset_for_testing();
		parent::tearDown();
	}

	public function test_record_rejects_disallowed_levels(): void {
		$this->assertFalse( \SScribe_Operational_Logger::record( 'info', 'should be dropped' ) );
		$this->assertFalse( \SScribe_Operational_Logger::record( 'debug', 'should be dropped' ) );
		$this->assertFalse( \SScribe_Operational_Logger::record( 'warning', 'should be dropped' ) );
		$this->assertFalse( \SScribe_Operational_Logger::record( '', 'should be dropped' ) );
	}

	public function test_record_accepts_error_level(): void {
		$this->assertTrue( \SScribe_Operational_Logger::record( 'error', 'something broke' ) );
		$this->assertTrue( \SScribe_Operational_Logger::record( 'ERROR', 'uppercase accepted' ) );
	}

	public function test_record_accepts_critical_level(): void {
		$this->assertTrue( \SScribe_Operational_Logger::record( 'critical', 'hard fail' ) );
	}

	public function test_sanitize_context_strips_deny_keys(): void {
		$out = \SScribe_Operational_Logger::sanitize_context( array(
			'nonce'          => 'abc123',
			'password'       => 'secret',
			'token'          => 'tok-1',
			'cookie'         => 'sid=xyz',
			'request_body'   => '<full payload>',
			'payload'        => 'lots of data',
			'file_content'   => 'private bytes',
			'user_id'        => 7,
		) );

		$this->assertArrayNotHasKey( 'nonce', $out );
		$this->assertArrayNotHasKey( 'password', $out );
		$this->assertArrayNotHasKey( 'token', $out );
		$this->assertArrayNotHasKey( 'cookie', $out );
		$this->assertArrayNotHasKey( 'request_body', $out );
		$this->assertArrayNotHasKey( 'payload', $out );
		$this->assertArrayNotHasKey( 'file_content', $out );
		$this->assertArrayHasKey( 'user_id', $out );
		$this->assertSame( 7, $out['user_id'] );
	}

	public function test_sanitize_context_strips_compound_sensitive_keys(): void {
		$out = \SScribe_Operational_Logger::sanitize_context(
			array(
				'category'             => 'network',
				'api_token'            => 'tok-secret',
				'client_secret_value'  => 'client-secret',
				'authorization_header' => 'Bearer abc',
				'database_password'    => 'pw',
				'session_cookie_name'  => 'sid=xyz',
			)
		);

		$this::assertSame( 'network', $out['category'] ?? null );
		$this::assertArrayNotHasKey( 'api_token', $out );
		$this::assertArrayNotHasKey( 'client_secret_value', $out );
		$this::assertArrayNotHasKey( 'authorization_header', $out );
		$this::assertArrayNotHasKey( 'database_password', $out );
		$this::assertArrayNotHasKey( 'session_cookie_name', $out );
	}

	public function test_sanitize_context_strips_invalid_keys(): void {
		$out = \SScribe_Operational_Logger::sanitize_context( array(
			'good_key'      => 'value',
			'1bad-key'      => 'no-leading-digit-hyphens',
			'has spaces'    => 'nope',
			'superLongKeyNameThatExceedsSixtyFourCharactersAndShouldBeRejectedWithoutQuestion' => 'nope',
		) );

		$this->assertArrayHasKey( 'good_key', $out );
		$this->assertArrayNotHasKey( '1bad-key', $out );
		$this->assertArrayNotHasKey( 'has spaces', $out );
		$this->assertArrayNotHasKey(
			'superLongKeyNameThatExceedsSixtyFourCharactersAndShouldBeRejectedWithoutQuestion',
			$out
		);
	}

	public function test_sanitize_context_bounds_string_values(): void {
		$out = \SScribe_Operational_Logger::sanitize_context( array(
			'long_string' => str_repeat( 'a', 500 ),
		) );

		$this->assertArrayHasKey( 'long_string', $out );
		$this->assertLessThanOrEqual( 204, strlen( $out['long_string'] ) );
	}

	public function test_sanitize_context_strips_control_characters(): void {
		$out = \SScribe_Operational_Logger::sanitize_context( array(
			'message' => "before\x00\x01after",
		) );
		$this->assertSame( 'beforeafter', $out['message'] );
	}

	public function test_sanitize_context_rejects_large_arrays(): void {
		$out = \SScribe_Operational_Logger::sanitize_context( array(
			'too_big' => range( 1, 50 ),
		) );
		$this->assertArrayNotHasKey( 'too_big', $out );
	}

	public function test_sanitize_context_accepts_small_arrays(): void {
		$out = \SScribe_Operational_Logger::sanitize_context( array(
			'nested' => array(
				'keep' => 'value',
				'nonce' => 'drop me',
			),
		) );
		$this->assertArrayHasKey( 'nested', $out );
		$this->assertSame( 'value', $out['nested']['keep'] );
		$this->assertArrayNotHasKey( 'nonce', $out['nested'] );
	}

	public function test_record_does_not_require_debug_toggle(): void {
		$GLOBALS['sscribe_test_logging_enabled'] = false;
		$this->assertTrue( \SScribe_Operational_Logger::record( 'error', 'production failure' ) );
		$this->assertTrue( \SScribe_Operational_Logger::record( 'critical', 'production crash' ) );
	}

	public function test_record_buffers_entries(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertTrue( \SScribe_Operational_Logger::record( 'error', 'event ' . $i ) );
		}
	}

	public function test_sanitize_message_bounds_length(): void {
		$out = \SScribe_Operational_Logger::sanitize_context( array(
			'msg' => str_repeat( 'x', 1000 ),
		) );
		$this->assertLessThanOrEqual( 204, strlen( $out['msg'] ) );
	}
	public function test_prune_retains_five_rotated_copies_plus_current_log(): void {
		$dir = sys_get_temp_dir() . '/sscribe-ops-prune-' . bin2hex( random_bytes( 6 ) );
		$this->assertTrue( mkdir( $dir, 0700, true ) );

		$current = $dir . '/sscribe_ops_2099-01-01.log';
		file_put_contents( $current, "current\n" );
		for ( $i = 1; $i <= 7; $i++ ) {
			file_put_contents( $dir . '/sscribe_ops_2098-12-31_00-00-0' . $i . '-abcdef.log', "rotated\n" );
		}

		$prune = \Closure::bind(
			static function ( string $path ): void {
				\SScribe_Operational_Logger::prune( $path );
			},
			null,
			\SScribe_Operational_Logger::class
		);

		try {
			$prune( $current );
			$files = glob( $dir . '/sscribe_ops_*.log' );
			$this->assertIsArray( $files );
			$this->assertCount( 6, $files, 'Retention means five rotated copies plus the current live log.' );
			$this->assertContains( $current, $files );
		} finally {
			foreach ( (array) glob( $dir . '/*' ) as $path ) {
				@unlink( $path );
			}
			@rmdir( $dir );
		}
	}

	public function test_operational_shutdown_hook_flushes_only_and_does_not_recapture_unscoped_fatals(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/includes/class-sscribe-operational-logger.php'
		);

		$this->assertStringNotContainsString(
			'error_get_last',
			$source,
			'Fatal attribution belongs exclusively to SScribe_Fatal_Handler so unrelated WordPress/plugin fatals are not logged as SScribe incidents.'
		);
		$this->assertStringContainsString( 'public static function flush_on_shutdown(): void', $source );
		$this->assertStringContainsString( 'self::flush();', $source );
	}

	public function test_shutdown_recursion_lock_is_class_scoped_not_dynamic_global(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/includes/class-sscribe-operational-logger.php'
		);

		$this->assertStringNotContainsString(
			'$GLOBALS[ $lock_key ]',
			$source,
			'Plugin Check rejects the dynamic global shutdown lock; use class-scoped state instead.'
		);
		$this->assertMatchesRegularExpression(
			'/private static bool \\$shutdown_flush_in_progress\\s*=\\s*false;/',
			$source,
			'Shutdown recursion protection must remain explicit class-scoped state.'
		);
	}

}
