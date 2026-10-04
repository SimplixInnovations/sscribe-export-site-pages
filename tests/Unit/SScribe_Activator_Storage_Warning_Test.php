<?php
/**
 * Activation resilience regressions for private-storage failure.
 *
 * Live-site failure class (reference b174bc301c46): when no strict
 * private-storage candidate is usable, the resolver returned '' and the
 * activator hard-aborted with "Activation could not complete the required
 * setup". Activation must instead complete with a persistent admin warning,
 * and the hardened uploads fallback must provide a working directory on
 * hosts where nothing outside the web root is writable.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class SScribe_Activator_Storage_Warning_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['sscribe_test_options']             = array();
		$GLOBALS['sscribe_test_transients']          = array();
		$GLOBALS['sscribe_test_registered_settings'] = array();
		$GLOBALS['sscribe_test_scheduled_events']    = array();
		$GLOBALS['sscribe_test_db_tables']           = array(
			'wp_sscribe_audit_log'    => array(),
			'wp_sscribe_export_stats' => array(),
		);
	}

	private static function invoke( string $method, array $args = array() ) {
		$reflection = ( new \ReflectionClass( \SScribe_Private_Storage::class ) )->getMethod( $method );

		return $reflection->invoke( null, ...$args );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_activation_completes_with_warning_when_private_storage_is_unavailable(): void {
		// Explicit candidate configuration keeps the fail-closed posture and
		// disables the fallback, so this poisoned base resolves to '' — the
		// exact precondition of the live-site activation failure.
		$poison = static function (): array {
			return array( sys_get_temp_dir() . '/sscribe-missing-base-' . uniqid() );
		};
		add_filter( 'sscribe_private_storage_base_candidates', $poison );

		try {
			// The historical behavior here was a thrown RuntimeException that
			// dead-ended activation. Completing is the contract now.
			\SScribe_Activator::activate( false );
		} finally {
			remove_filter( 'sscribe_private_storage_base_candidates', $poison );
		}

		$warning = get_transient( 'sscribe_storage_warning' );
		$this->assertIsArray( $warning, 'A storage warning must be surfaced when no private directory resolves.' );
		$this->assertNotEmpty( $warning['message'] ?? '' );

		// The remainder of activation must have completed normally.
		$this->assertSame( SSCRIBE_VERSION, get_option( 'sscribe_version' ) );
		$this->assertSame( '1', get_transient( 'sscribe_activation_redirect' ) );
		$this->assertArrayHasKey( 'sscribe_cleanup_exports', $GLOBALS['sscribe_test_scheduled_events'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_activation_clears_storage_warning_when_storage_resolves(): void {
		set_transient(
			'sscribe_storage_warning',
			array(
				'message' => 'stale warning from a previous attempt',
				'time'    => gmdate( 'Y-m-d H:i:s \U\T\C' ),
			),
			300
		);

		\SScribe_Activator::activate( false );

		$this->assertFalse( get_transient( 'sscribe_storage_warning' ), 'A successful storage resolution must clear the warning.' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_hardened_uploads_fallback_creates_guarded_directory(): void {
		$uploads = wp_upload_dir();
		wp_mkdir_p( $uploads['basedir'] );

		$this::assertTrue( (bool) self::invoke( 'fallback_is_permitted' ), 'Stock discovery must permit the fallback.' );

		$path = (string) self::invoke( 'resolve_hardened_uploads_dir', array( true ) );
		$this->assertNotSame( '', $path, 'The fallback must resolve a directory under uploads.' );

		$normalized      = str_replace( '\\', '/', $path );
		$normalized_base = str_replace( '\\', '/', rtrim( (string) $uploads['basedir'], '/\\' ) );
		$this->assertStringStartsWith( $normalized_base . '/', $normalized );
		$this->assertStringContainsString( '/sscribe-export-site-pages/', $normalized );
		$this->assertFileExists( $path . '/.htaccess', 'The fallback tree must ship deny rules.' );
		$this->assertFileExists( $path . '/index.php', 'The fallback tree must ship a silent index guard.' );

		// Documented trade-off: the fallback lives under a web-served root and
		// is protected by unguessable naming plus deny rules instead.
		$this::assertFalse(
			(bool) self::invoke( 'is_outside_public_roots', array( $path ) ),
			'The hardened uploads fallback is deliberately inside a public root.'
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_explicit_configuration_never_silently_falls_back(): void {
		$uploads = wp_upload_dir();
		wp_mkdir_p( $uploads['basedir'] );
		define( 'SSCRIBE_PRIVATE_STORAGE_DIR', $uploads['basedir'] );

		$this::assertFalse( (bool) self::invoke( 'fallback_is_permitted' ), 'Explicit configuration must disable the fallback.' );
		$this::assertSame(
			'',
			(string) self::invoke( 'resolve_hardened_uploads_dir', array( true ) ),
			'An operator-chosen location must never be replaced with the fallback tree.'
		);
	}
}
