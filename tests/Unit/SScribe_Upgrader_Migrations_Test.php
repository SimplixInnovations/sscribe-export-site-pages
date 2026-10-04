<?php
/**
 * SScribe Upgrader legacy-migration unit test
 *
 * Pins the two legacy migration branches of
 * SScribe_Upgrader::run_migrations() (includes/class-sscribe-upgrader.php):
 *
 *   - :161-180 $from_version < 1.1.1 renames the legacy
 *     sscribe_export_metrics['formats'] keys (docx|pdf|html|markdown) to
 *     their *_page successors, preserving pre-existing *_page values and
 *     staying idempotent across repeated runs.
 *   - :182-184 $from_version < 2.0.0 must run migrate_legacy_storage() and
 *     throw when it fails. The maybe_upgrade() wrapper
 *     (includes/class-sscribe-upgrader.php:74-77) must then record the
 *     failure state in sscribe_upgrade_last_error and NOT advance
 *     sscribe_schema_version.
 *
 * run_migrations() is private static, so the tests drive it through
 * ReflectionMethod with fixture options. The migrate_legacy_storage()
 * failure is produced without touching production code: the pre_upload_dir
 * filter surface exposed by the wp_upload_dir() bootstrap stub points the
 * legacy storage root at a fixture directory, and a planted regular file at
 * the migration destination makes the subdirectory move fail cleanly
 * (wp_mkdir_p() collision short-circuit), which migrate_legacy_storage()
 * reports as failure.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Upgrader_Migrations_Test extends TestCase {

	private string $legacy_base = '';

	private string $planted_collision = '';

	/** @var callable|null */
	private $pre_upload_dir_filter = null;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_options']    = array();
		$GLOBALS['sscribe_test_transients'] = array();
		$GLOBALS['sscribe_test_is_admin']   = true;
		$GLOBALS['sscribe_test_doing_ajax'] = false;
		$GLOBALS['sscribe_test_doing_cron'] = false;

		$this->legacy_base = sys_get_temp_dir() . '/sscribe-upgrader-legacy-' . uniqid();
		mkdir( $this->legacy_base );

		$base                        = $this->legacy_base;
		$this->pre_upload_dir_filter = static function ( $pre ) use ( $base ) {
			unset( $pre );
			return array(
				'basedir' => $base,
				'baseurl' => 'http://example.org/wp-content/uploads',
				'path'    => $base,
				'url'     => 'http://example.org/wp-content/uploads',
				'subdir'  => '',
				'error'   => false,
			);
		};
		add_filter( 'pre_upload_dir', $this->pre_upload_dir_filter );
	}

	protected function tearDown(): void {
		if ( null !== $this->pre_upload_dir_filter ) {
			remove_filter( 'pre_upload_dir', $this->pre_upload_dir_filter );
			$this->pre_upload_dir_filter = null;
		}
		if ( '' !== $this->planted_collision && file_exists( $this->planted_collision ) ) {
			@unlink( $this->planted_collision );
		}
		$this->planted_collision = '';
		if ( '' !== $this->legacy_base && is_dir( $this->legacy_base ) ) {
			$this->remove_tree( $this->legacy_base );
		}
		$this->legacy_base = '';
		$GLOBALS['sscribe_test_options']    = array();
		$GLOBALS['sscribe_test_transients'] = array();
		unset(
			$GLOBALS['sscribe_test_is_admin'],
			$GLOBALS['sscribe_test_doing_ajax'],
			$GLOBALS['sscribe_test_doing_cron']
		);
		parent::tearDown();
	}

	private function remove_tree( string $dir ): void {
		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->remove_tree( $path );
				continue;
			}
			@unlink( $path );
		}
		@rmdir( $dir );
	}

	/**
	 * Build a fixture where migrate_legacy_storage() deterministically fails
	 * with no PHP warnings: the legacy logs directory contains a `sub`
	 * directory whose migration destination is already occupied by a regular
	 * file, so move_directory_contents() short-circuits on the wp_mkdir_p()
	 * collision and reports failure.
	 */
	private function plant_failing_legacy_logs_tree(): void {
		mkdir( $this->legacy_base . '/sscribe-logs' );
		mkdir( $this->legacy_base . '/sscribe-logs/sub' );
		file_put_contents( $this->legacy_base . '/sscribe-logs/sub/artifact.txt', 'legacy' );

		$target = \SScribe_Private_Storage::get_subdirectory( 'logs' );
		$this->assertNotSame( '', $target, 'The private logs subdirectory must resolve for the fixture to be valid.' );

		$this->planted_collision = $target . '/sub';
		file_put_contents( $this->planted_collision, 'collision' );
	}

	private function invoke_run_migrations( string $from_version ): void {
		$method = new \ReflectionMethod( \SScribe_Upgrader::class, 'run_migrations' );
		$method->invoke( null, $from_version );
	}

	private function seed_legacy_metrics(): void {
		update_option(
			'sscribe_export_metrics',
			array(
				'formats' => array(
					'docx'       => 5,
					'pdf'        => 2,
					'html'       => 1,
					'markdown'   => 7,
					'docx_page'  => 99,
					'custom'     => 3,
				),
				'total' => 42,
			),
			false
		);
	}

	// ==================================================================
	// $from_version < 1.1.1 — metrics key rename.
	// ==================================================================

	public function test_run_migrations_renames_legacy_metrics_format_keys_to_page_keys(): void {
		$this->seed_legacy_metrics();

		$this->invoke_run_migrations( '1.1.0' );

		$metrics = get_option( 'sscribe_export_metrics', array() );
		$this->assertIsArray( $metrics );
		$formats = $metrics['formats'];

		$this->assertArrayNotHasKey( 'docx', $formats, 'The legacy docx key must be renamed away.' );
		$this->assertArrayNotHasKey( 'pdf', $formats, 'The legacy pdf key must be renamed away.' );
		$this->assertArrayNotHasKey( 'html', $formats, 'The legacy html key must be renamed away.' );
		$this->assertArrayNotHasKey( 'markdown', $formats, 'The legacy markdown key must be renamed away.' );

		$this->assertSame( 2, $formats['pdf_page'], 'pdf must migrate to pdf_page with its count.' );
		$this->assertSame( 1, $formats['html_page'], 'html must migrate to html_page with its count.' );
		$this->assertSame( 7, $formats['markdown_page'], 'markdown must migrate to markdown_page with its count.' );

		$this->assertSame(
			99,
			$formats['docx_page'],
			'A pre-existing docx_page value must be preserved: the rename never overwrites it.'
		);
		$this->assertSame( 3, $formats['custom'], 'Unknown non-bare format keys must be left untouched.' );
		$this->assertSame( 42, $metrics['total'], 'Metrics outside the formats array must be left untouched.' );
	}

	public function test_run_migrations_metrics_rename_is_idempotent(): void {
		$this->seed_legacy_metrics();

		$this->invoke_run_migrations( '1.1.0' );
		$after_first = get_option( 'sscribe_export_metrics', array() );

		$this->invoke_run_migrations( '1.1.0' );
		$after_second = get_option( 'sscribe_export_metrics', array() );

		$this->assertSame(
			$after_first,
			$after_second,
			'Re-running the pre-1.1.1 migration must not duplicate, drop, or re-rename metrics keys.'
		);
	}

	public function test_run_migrations_does_not_touch_metrics_from_1_1_1(): void {
		$this->seed_legacy_metrics();
		$before = get_option( 'sscribe_export_metrics', array() );

		$this->invoke_run_migrations( '1.1.1' );

		$this->assertSame(
			$before,
			get_option( 'sscribe_export_metrics', array() ),
			'The metrics rename must be gated to versions strictly below 1.1.1.'
		);
	}

	// ==================================================================
	// $from_version < 2.0.0 — private storage migration failure.
	// ==================================================================

	public function test_run_migrations_throws_when_private_storage_migration_fails(): void {
		$this->plant_failing_legacy_logs_tree();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Failed while migrating export artifacts to private storage.' );

		$this->invoke_run_migrations( '1.5.0' );
	}

	public function test_maybe_upgrade_records_failure_and_keeps_schema_version_when_storage_migration_fails(): void {
		$this->plant_failing_legacy_logs_tree();
		update_option( 'sscribe_schema_version', '1.5.0', false );

		\SScribe_Upgrader::maybe_upgrade();

		$this->assertSame(
			'1.5.0',
			get_option( 'sscribe_schema_version', null ),
			'A failed legacy-storage migration must NOT advance the schema version.'
		);

		$error = get_option( 'sscribe_upgrade_last_error' );
		$this->assertIsArray( $error, 'The failed migration must record sscribe_upgrade_last_error.' );
		$this->assertSame( 'The database upgrade did not complete and will be retried.', $error['message'] ?? '' );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{12}$/', (string) ( $error['reference'] ?? '' ) );
		$this->assertSame( 1, get_option( 'sscribe_upgrade_failures', 0 ) );
		$this->assertGreaterThan( time(), (int) get_option( 'sscribe_upgrade_next_attempt', 0 ) );
	}
}
