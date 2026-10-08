<?php
/**
 * Activation resilience and the live storage notice.
 *
 * Activation must complete even when private storage cannot be created, and
 * the administrator must then see a live notice on the plugin screens that
 * disappears as soon as the uploads directory is writable again.
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

	protected function tearDown(): void {
		unset( $GLOBALS['sscribe_test_current_screen'] );
		parent::tearDown();
	}

	private static function screen( string $id ): object {
		$screen     = new \stdClass();
		$screen->id = $id;

		return $screen;
	}

	private static function render_notice(): string {
		$admin = ( new \ReflectionClass( \SScribe_Admin::class ) )->newInstanceWithoutConstructor();
		ob_start();
		$admin->render_storage_notice();

		return (string) ob_get_clean();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_activation_completes_without_a_stored_warning_when_storage_is_unavailable(): void {
		$poison = static function (): array {
			return array( sys_get_temp_dir() . '/sscribe-missing-base-' . uniqid() );
		};
		add_filter( 'sscribe_private_storage_base_candidates', $poison );

		try {
			\SScribe_Activator::activate( false );
		} finally {
			remove_filter( 'sscribe_private_storage_base_candidates', $poison );
		}

		$this->assertFalse( get_transient( 'sscribe_storage_warning' ), 'The storage warning is computed live, never stored at activation.' );
		$this->assertSame( SSCRIBE_VERSION, get_option( 'sscribe_version' ) );
		$this->assertSame( '1', get_transient( 'sscribe_activation_redirect' ) );
		$this->assertArrayHasKey( 'sscribe_cleanup_exports', $GLOBALS['sscribe_test_scheduled_events'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_activation_removes_a_stale_storage_warning_transient(): void {
		set_transient(
			'sscribe_storage_warning',
			array(
				'message' => 'stale warning from a previous release',
				'time'    => gmdate( 'Y-m-d H:i:s \U\T\C' ),
			),
			300
		);

		\SScribe_Activator::activate( false );

		$this->assertFalse( get_transient( 'sscribe_storage_warning' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_uploads_default_creates_guarded_directory(): void {
		$uploads = wp_upload_dir();
		wp_mkdir_p( $uploads['basedir'] );

		$path = \SScribe_Private_Storage::get_export_dir();
		$this->assertNotSame( '', $path );
		$this->assertSame( 'uploads', \SScribe_Private_Storage::get_storage_mode() );

		$normalized      = str_replace( '\\', '/', $path );
		$normalized_base = str_replace( '\\', '/', (string) realpath( $uploads['basedir'] ) );
		$this->assertStringStartsWith( $normalized_base . '/sscribe-export-site-pages/', $normalized );
		$container = dirname( $path, 2 );
		foreach ( array( $path, $container ) as $guarded ) {
			$this->assertFileExists( $guarded . '/.htaccess' );
			$this->assertFileExists( $guarded . '/index.php' );
			$this->assertFileExists( $guarded . '/web.config' );
		}
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $path . '/.htaccess' ) );
		$this->assertStringContainsString( 'Deny from all', (string) file_get_contents( $path . '/.htaccess' ) );
		$this->assertStringContainsString( '<add accessType="Deny" users="*" />', (string) file_get_contents( $path . '/web.config' ) );
		$this->assertStringContainsString( '<handlers accessPolicy="None" />', (string) file_get_contents( $path . '/web.config' ) );

		\SScribe_Private_Storage::delete_owned_storage();
		$this->assertDirectoryDoesNotExist( dirname( $path ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_explicit_configuration_never_falls_back_to_uploads(): void {
		define( 'SSCRIBE_PRIVATE_STORAGE_DIR', sys_get_temp_dir() . '/sscribe-missing-override-' . uniqid() );

		$this->assertSame( 'override', \SScribe_Private_Storage::get_storage_mode() );
		$this->assertSame( '', \SScribe_Private_Storage::get_export_dir() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_live_notice_names_uploads_when_uploads_is_not_writable(): void {
		$unusable = sys_get_temp_dir() . '/sscribe-unwritable-uploads-' . uniqid();
		$upload   = static function () use ( $unusable ): array {
			return array(
				'basedir' => $unusable,
				'baseurl' => 'http://example.org/wp-content/uploads',
				'path'    => $unusable,
				'url'     => 'http://example.org/wp-content/uploads',
				'subdir'  => '',
				'error'   => false,
			);
		};
		add_filter( 'pre_upload_dir', $upload );
		$GLOBALS['sscribe_test_current_screen'] = self::screen( 'plugins' );

		$output = self::render_notice();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( esc_html( $unusable ), $output );
		$this->assertStringContainsString( 'Check permissions on your uploads directory.', $output );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_live_notice_is_silent_when_storage_resolves(): void {
		$uploads = wp_upload_dir();
		wp_mkdir_p( $uploads['basedir'] );
		$GLOBALS['sscribe_test_current_screen'] = self::screen( 'toplevel_page_sscribe-export' );

		$this->assertSame( '', self::render_notice() );
		\SScribe_Private_Storage::delete_owned_storage();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_live_notice_is_limited_to_plugin_screens(): void {
		define( 'SSCRIBE_PRIVATE_STORAGE_DIR', sys_get_temp_dir() . '/sscribe-missing-override-' . uniqid() );

		$GLOBALS['sscribe_test_current_screen'] = self::screen( 'dashboard' );
		$this->assertSame( '', self::render_notice() );

		$GLOBALS['sscribe_test_current_screen'] = self::screen( 'toplevel_page_sscribe-export' );
		$this->assertStringContainsString( 'SSCRIBE_PRIVATE_STORAGE_DIR', self::render_notice() );
	}
}
