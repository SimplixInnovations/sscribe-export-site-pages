<?php
/**
 * SScribe ZIP Handler Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Zip_Handler;

class SScribe_Zip_Handler_Test extends TestCase {
	private ?SScribe_Zip_Handler $handler;
	private string $test_export_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->handler        = new SScribe_Zip_Handler();
		$this->test_export_dir = sys_get_temp_dir() . '/sscribe-test-zip-' . uniqid();
	}

	protected function tearDown(): void {
		$this->handler = null;
		if ( is_dir( $this->test_export_dir ) ) {
			array_map( 'unlink', glob( $this->test_export_dir . '/**/*' ) ?: array() );
			@rmdir( $this->test_export_dir );
		}
		unset( $GLOBALS['sscribe_test_current_user'] );
		unset( $GLOBALS['sscribe_test_update_option_failure'] );
		parent::tearDown();
	}

	/**
	 * Extract a URL's query string into an associative array using only
	 * native PHP, no bootstrap stubs. Lets the dl_token test assert on
	 * the URL contents without coupling to wp_parse_url behavior.
	 *
	 * @param string $url Full URL.
	 * @return array<string,string>
	 */
	private function parse_query_params( string $url ): array {
		$query = (string) ( parse_url( $url, PHP_URL_QUERY ) ?? '' );
		if ( '' === $query ) {
			return array();
		}
		$params = array();
		parse_str( $query, $params );
		return $params;
	}

	public function test_handler_can_be_instantiated(): void {
		$this->assertInstanceOf( SScribe_Zip_Handler::class, $this->handler );
	}

	public function test_get_export_dir_returns_path(): void {
		$result = $this->handler->get_export_dir();
		$this->assertIsString( $result );
		$this->assertStringEndsWith( 'sscribe-exports', $result );
	}

	public function test_get_export_dir_creates_directory(): void {
		$result   = $this->handler->get_export_dir();
		$this->assertDirectoryExists( $result );

		if ( file_exists( $result . '/.htaccess' ) ) {
			$this->assertFileExists( $result . '/.htaccess' );
		}
		if ( file_exists( $result . '/index.html' ) ) {
			$this->assertFileExists( $result . '/index.html' );
		}
	}

	public function test_create_temp_dir_creates_directory(): void {
		$temp_dir = $this->handler->create_temp_dir();
		$this->assertIsString( $temp_dir );
		$this->assertDirectoryExists( $temp_dir );
		$this->assertStringContainsString( 'temp-', $temp_dir );
	}

	public function test_create_temp_dir_creates_unique_names(): void {
		$dir1 = $this->handler->create_temp_dir();
		$dir2 = $this->handler->create_temp_dir();
		$this->assertNotSame( $dir1, $dir2 );
	}

	public function test_delete_directory_removes_all(): void {

		$test_dir = $this->handler->create_temp_dir();
		file_put_contents( $test_dir . '/test.txt', 'content' );

		$this->assertDirectoryExists( $test_dir );

		$this->handler->delete_directory( $test_dir );

		$this->assertDirectoryDoesNotExist( $test_dir );
	}

	public function test_delete_directory_handles_missing_dir(): void {
		$method = new \ReflectionMethod( SScribe_Zip_Handler::class, 'delete_directory' );
		$result = $method->invoke( $this->handler, '/non-existent-dir' );

		$this->assertFalse( $result );
	}


	public function test_create_zip_renews_index_lock_before_archive_and_metadata_publication(): void {
		$source = (string) file_get_contents( SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-zip-handler.php' );

		$first_renew = strpos( $source, '$lock_manager->renew_lock' );
		$rename      = strpos( $source, '@rename( $tmp_zip, $zip_path )' );
		$this->assertNotFalse( $first_renew );
		$this->assertNotFalse( $rename );
		$this->assertLessThan(
			$rename,
			$first_renew,
			'ZIP publication must renew export-index ownership before the staging archive is renamed into its final path.'
		);

		$second_renew = strpos( $source, '$lock_manager->renew_lock', $first_renew + 1 );
		$row_save     = strpos( $source, 'update_option( $row_option' );
		$this->assertNotFalse( $second_renew );
		$this->assertNotFalse( $row_save );
		$this->assertLessThan(
			$row_save,
			$second_renew,
			'Export-index ownership must be renewed again immediately before row/index metadata publication.'
		);
	}

	public function test_zip_open_failure_releases_index_lock_before_return(): void {
		$source = (string) file_get_contents( SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-zip-handler.php' );
		$start  = strpos( $source, 'if ( $zip->open( $tmp_zip' );
		$this->assertNotFalse( $start );

		$return = strpos( $source, 'return false;', $start );
		$this->assertNotFalse( $return );
		$failure_branch = substr( $source, $start, $return - $start );

		$this->assertStringContainsString(
			'$lock_manager->release_lock( $lock_name, $lock_token );',
			$failure_branch,
			'An archive-open failure must not leak the already-acquired export-index lock until TTL expiry.'
		);
	}

	public function test_create_zip_returns_false_with_no_files(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$source_dir = $this->handler->create_temp_dir();
		$result      = $this->handler->create_zip( $source_dir, 'test-zip', array( 'docx' ) );

		$this->assertFalse( $result );
	}

	public function test_create_zip_succeeds_with_files(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$source_dir = $this->handler->create_temp_dir();
		// Files now live one level deeper, under the language subdir
		// (the batch processor writes to `$temp_dir/$LANG/page.ext`).
		wp_mkdir_p( $source_dir . '/EN' );
		file_put_contents( $source_dir . '/EN/P001-Test.docx', 'dummy content' );

		$result = $this->handler->create_zip( $source_dir, 'test-zip', array( 'docx' ) );

		$this->assertIsString( $result );
		$this->assertFileExists( $result );
		$this->assertStringEndsWith( '.zip', $result );

		$row = get_option( 'sscribe_export_row_' . md5( basename( $result ) ), array() );
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{32}$/',
			(string) ( $row['dl_token'] ?? '' ),
			'New export rows must carry a token before any download URL is rendered.'
		);
		$this->assertGreaterThan( 0, (int) ( $row['dl_token_at'] ?? 0 ) );

		if ( file_exists( $result ) ) {
			unlink( $result );
		}
		delete_option( 'sscribe_export_row_' . md5( basename( $result ) ) );
	}

	public function test_create_zip_fails_closed_when_export_row_cannot_persist(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$source_dir = $this->handler->create_temp_dir();
		wp_mkdir_p( $source_dir . '/EN' );
		file_put_contents( $source_dir . '/EN/P001-Test.docx', 'dummy content' );

		$zip_stem = 'metadata-write-failure-' . uniqid();
		$filename = $zip_stem . '.zip';
		$GLOBALS['sscribe_test_update_option_failure'] = 'sscribe_export_row_' . md5( $filename );

		$this->assertFalse( $this->handler->create_zip( $source_dir, $zip_stem, array( 'docx' ) ) );
		$this->assertFileDoesNotExist( $this->test_export_dir . '/' . $filename );
	}

	public function test_create_zip_normalizes_long_non_ascii_archive_name(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$source_dir = $this->handler->create_temp_dir();
		wp_mkdir_p( $source_dir . '/EN' );
		file_put_contents( $source_dir . '/EN/P001-Test.docx', 'dummy content' );

		$zip_path = $this->handler->create_zip( $source_dir, str_repeat( 'موقع طويل ', 80 ), array( 'docx' ) );

		$this->assertIsString( $zip_path );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}\.zip$/D', basename( $zip_path ) );
		$this->assertLessThanOrEqual( 204, strlen( basename( $zip_path ) ) );
		wp_delete_file( $zip_path );
	}

	public function test_delete_export_rechecks_owner_and_updates_index(): void {
		$export_dir = $this->handler->get_export_dir();
		$filename   = 'sscribe-delete-test-' . uniqid() . '.zip';
		$file_path  = $export_dir . '/' . $filename;
		file_put_contents( $file_path, 'zip-fixture' );
		update_option( 'sscribe_export_row_' . md5( $filename ), array( 'user_id' => 7 ), false );
		update_option( 'sscribe_export_index', array( $filename ), false );

		$this->assertFalse( $this->handler->delete_export( $filename, 8 ) );
		$this->assertFileExists( $file_path );
		$this->assertTrue( $this->handler->delete_export( $filename, 7 ) );
		$this->assertFileDoesNotExist( $file_path );
		$this->assertNull( $this->handler->get_export_entry( $filename ) );
		$this->assertNotContains( $filename, get_option( 'sscribe_export_index', array() ) );
	}

	public function test_create_zip_cleans_up_source(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$source_dir = $this->handler->create_temp_dir();
		wp_mkdir_p( $source_dir . '/EN' );
		file_put_contents( $source_dir . '/EN/P001-Test.docx', 'dummy content' );

		$this->assertDirectoryExists( $source_dir );

		$result = $this->handler->create_zip( $source_dir, 'test-zip', array( 'docx' ) );

		$this->assertDirectoryDoesNotExist( $source_dir );

		if ( is_string( $result ) && file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_create_zip_with_multiple_formats(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$source_dir = $this->handler->create_temp_dir();
		wp_mkdir_p( $source_dir . '/EN' );
		file_put_contents( $source_dir . '/EN/P001-Test.docx', 'docx content' );
		file_put_contents( $source_dir . '/EN/P001-Test.pdf', 'pdf content' );

		$zip_path = $this->handler->create_zip( $source_dir, 'test-multi', array( 'docx', 'pdf' ) );

		$this->assertIsString( $zip_path );
		$this->assertFileExists( $zip_path );

		if ( file_exists( $zip_path ) ) {
			unlink( $zip_path );
		}
	}

	/**
	 * Regression test for the per-language folder restructure: a
	 * multi-language export must place each page into `FORMAT/LANG/page.ext`
	 * inside the ZIP, with no filename-suffix gymnastics. The language
	 * is read from the parent directory name on disk, not from the
	 * filename.
	 */
	public function test_create_zip_groups_files_by_language_subdir(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$source_dir = $this->handler->create_temp_dir();
		wp_mkdir_p( $source_dir . '/AR' );
		wp_mkdir_p( $source_dir . '/EN' );
		file_put_contents( $source_dir . '/AR/P001-Arabic.docx', 'arabic content' );
		file_put_contents( $source_dir . '/AR/P002-Arabic.docx', 'arabic content 2' );
		file_put_contents( $source_dir . '/EN/P001-English.docx', 'english content' );

		$zip_path = $this->handler->create_zip( $source_dir, 'test-lang-groups', array( 'docx' ), true );
		$this->assertIsString( $zip_path );
		$this->assertFileExists( $zip_path );

		// Open the ZIP and assert the language subfolders survived.
		$zip = new \ZipArchive();
		$zip->open( $zip_path );
		$entries = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entries[] = $zip->getNameIndex( $i );
		}
		$zip->close();

		$this->assertContains( 'DOCX/AR/P001-Arabic.docx', $entries, 'AR page should be in DOCX/AR/' );
		$this->assertContains( 'DOCX/AR/P002-Arabic.docx', $entries, 'AR page 2 should be in DOCX/AR/' );
		$this->assertContains( 'DOCX/EN/P001-English.docx', $entries, 'EN page should be in DOCX/EN/' );

		// Filename must NOT carry a language suffix anymore.
		foreach ( $entries as $entry ) {
			$this->assertDoesNotMatchRegularExpression(
				'#-AR\.docx$#',
				$entry,
				'Filenames should no longer carry the -AR suffix: ' . $entry
			);
			$this->assertDoesNotMatchRegularExpression(
				'#-EN\.docx$#',
				$entry,
				'Filenames should no longer carry the -EN suffix: ' . $entry
			);
		}

		if ( file_exists( $zip_path ) ) {
			unlink( $zip_path );
		}
	}

	/**
	 * Regression test for Audit N-3: verify_zip_integrity() must NOT reject
	 * a valid multi-format ZIP where the first entry is a directory entry.
	 *
	 * The old check rejected any ZIP whose first statIndex() entry had size 0
	 * — but directory entries always have size 0 by design, so any multi-format
	 * export (DOCX/, PDF/, etc.) starting with a directory entry was falsely
	 * flagged as "truncated or corrupted".
	 */
	public function test_verify_zip_integrity_accepts_zip_starting_with_directory_entry(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$export_dir = $this->handler->get_export_dir();
		$zip_path   = $export_dir . '/sscribe-dir-entry-test-' . uniqid() . '.zip';

		// Build a ZIP whose first entry is an explicit directory entry,
		// followed by a real file. This is the exact shape multi-format
		// exports produce when DOCX/ or PDF/ directory entries come first.
		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
		$zip->addEmptyDir( 'DOCX' );
		$zip->addFromString( 'DOCX/P001-Test.docx', 'docx content' );
		$zip->close();

		$this->assertFileExists( $zip_path );

		$method = new \ReflectionMethod( SScribe_Zip_Handler::class, 'verify_zip_integrity' );
		$result = $method->invoke( $this->handler, $zip_path );

		$this->assertTrue(
			$result,
			'verify_zip_integrity() must accept a valid ZIP that begins with a directory entry (size 0).'
		);

		if ( file_exists( $zip_path ) ) {
			unlink( $zip_path );
		}
	}

	public function test_cleanup_expired_keeps_index_for_existing_zip_files(): void {
		$export_dir = $this->handler->get_export_dir();
		$filename   = 'sscribe-cleanup-test-' . uniqid() . '.zip';
		$file_path  = $export_dir . '/' . $filename;

		file_put_contents( $file_path, 'zip-fixture' );

		$row = array(
			'created_at' => time(),
			'user_id'    => 1,
			'formats'    => array( 'docx' ),
			'lang_code'  => '',
			'lang_name'  => '',
			'flag_url'   => '',
		);
		update_option( 'sscribe_export_row_' . md5( $filename ), $row, false );

		$index   = get_option( 'sscribe_export_index', array() );
		$index[] = $filename;
		update_option( 'sscribe_export_index', array_values( array_unique( $index ) ), false );

		delete_transient( 'sscribe_cron_exports_lock' );
		$this->handler->cleanup_expired();

		$updated_index = get_option( 'sscribe_export_index', array() );
		$this->assertContains( $filename, $updated_index );

		if ( file_exists( $file_path ) ) {
			unlink( $file_path );
		}
		$updated_index = array_values( array_filter( $updated_index, static function ( $b ) use ( $filename ) {
			return (string) $b !== $filename;
		} ) );
		update_option( 'sscribe_export_index', $updated_index, false );
		delete_option( 'sscribe_export_row_' . md5( $filename ) );
	}

	/**
	 * Regression for the H1 single-use download token invariant:
	 * rotate_dl_token() must mint a fresh 32-char hex string each call,
	 * persist it on the row, and never return the same token twice in
	 * a row. Without this, consume_dl_token() cannot gate replays.
	 */
	public function test_rotate_dl_token_returns_32_char_hex_and_persists(): void {
		$filename = 'sscribe-token-rotate-' . uniqid() . '.zip';
		update_option(
			'sscribe_export_row_' . md5( $filename ),
			array(
				'user_id' => 1,
			),
			false
		);

		$token_a = $this->handler->rotate_dl_token( $filename );
		$this->assertIsString( $token_a );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $token_a, 'rotate_dl_token must mint a 32-hex (16-byte) random token.' );

		$token_b = $this->handler->rotate_dl_token( $filename );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $token_b );
		$this->assertNotSame( $token_a, $token_b, 'Two successive rotates must produce distinct tokens.' );

		$row = get_option( 'sscribe_export_row_' . md5( $filename ), array() );
		$this->assertSame( $token_b, $row['dl_token'] ?? null, 'rotate_dl_token must persist the latest token on the row.' );
		$this->assertGreaterThan( 0, (int) ( $row['dl_token_at'] ?? 0 ), 'rotate_dl_token must stamp the row with a token-issue time.' );

		delete_option( 'sscribe_export_row_' . md5( $filename ) );
	}

	/**
	 * Regression for the H1 single-use download token invariant:
	 * consume_dl_token() must validate a presented token against the
	 * stored one with constant-time semantics and rotate the stored
	 * token immediately on success so a replay cannot succeed even if
	 * it races before the JS client gets the response.
	 */
	public function test_consume_dl_token_succeeds_then_rotates_so_replay_fails(): void {
		$filename = 'sscribe-token-consume-' . uniqid() . '.zip';
		$row      = array(
			'user_id' => 1,
		);
		update_option( 'sscribe_export_row_' . md5( $filename ), $row, false );

		$token = $this->handler->rotate_dl_token( $filename );
		$this->assertNotSame( '', $token );

		// First call with the correct presented token must succeed.
		$this->assertTrue(
			$this->handler->consume_dl_token( $filename, $token ),
			'First redemption of a freshly rotated token must succeed.'
		);

		// Same token presented again must fail because consume rotated
		// the stored token immediately on success.
		$this->assertFalse(
			$this->handler->consume_dl_token( $filename, $token ),
			'Replay of an already-consumed token must fail (single-use invariant).'
		);

		// The stored token must have moved on; capture it from the option.
		$row_after = get_option( 'sscribe_export_row_' . md5( $filename ), array() );
		$this->assertNotSame(
			$token,
			$row_after['dl_token'] ?? $token,
			'consume_dl_token must rotate the stored token on success.'
		);
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', (string) ( $row_after['dl_token'] ?? '' ) );

		delete_option( 'sscribe_export_row_' . md5( $filename ) );
	}

	/**
	 * Regression for the H1 single-use download token invariant:
	 * a missing or empty presented token must never match. Empty
	 * strings, malformed input, and tokens issued for a different
	 * row must all be rejected with false.
	 */
	public function test_consume_dl_token_rejects_empty_and_wrong_tokens(): void {
		$filename = 'sscribe-token-wrong-' . uniqid() . '.zip';
		update_option(
			'sscribe_export_row_' . md5( $filename ),
			array( 'user_id' => 1 ),
			false
		);
		$real_token = $this->handler->rotate_dl_token( $filename );
		$this->assertNotSame( '', $real_token );

		$this->assertFalse(
			$this->handler->consume_dl_token( $filename, '' ),
			'Empty presented token must be rejected.'
		);
		$this->assertFalse(
			$this->handler->consume_dl_token( $filename, 'not-the-token' ),
			'Wrong presented token must be rejected.'
		);
		// A token issued for one row must never accept a token meant for another.
		$other_filename = 'sscribe-token-other-' . uniqid() . '.zip';
		update_option(
			'sscribe_export_row_' . md5( $other_filename ),
			array( 'user_id' => 1 ),
			false
		);
		$other_token = $this->handler->rotate_dl_token( $other_filename );
		$this->assertFalse(
			$this->handler->consume_dl_token( $filename, $other_token ),
			'Token issued for a different row must be rejected.'
		);

		// The row token must not have rotated: empty/wrong calls leave the real token intact.
		$row_after = get_option( 'sscribe_export_row_' . md5( $filename ), array() );
		$this->assertSame(
			$real_token,
			$row_after['dl_token'] ?? null,
			'Failed consume attempts must not rotate the stored token.'
		);

		delete_option( 'sscribe_export_row_' . md5( $filename ) );
		delete_option( 'sscribe_export_row_' . md5( $other_filename ) );
	}

	/**
	 * Regression for the H1 single-use download token invariant:
	 * get_ajax_download_url() must embed the row's current token, and
	 * the URL must encode action=sscribe_download, file=, nonce=, and
	 * token=. The handler-side consume_dl_token() must then accept the
	 * URL-supplied token exactly once.
	 */
	public function test_get_ajax_download_url_embeds_a_single_use_token(): void {
		$filename = 'sscribe-token-url-' . uniqid() . '.zip';
		update_option(
			'sscribe_export_row_' . md5( $filename ),
			array( 'user_id' => 1 ),
			false
		);

		// The bootstrap stub mirrors WP: anonymous = not logged in.
		// Pin a real WP_User so the production-side auth check passes.
		$GLOBALS['sscribe_test_current_user'] = new \WP_User( 1 );

		$url = $this->handler->get_ajax_download_url( $filename );
		$this->assertNotSame( '', $url, 'get_ajax_download_url must produce a URL for a known row.' );
		$this->assertStringContainsString( 'action=sscribe_download', $url );
		$this->assertStringContainsString( 'file=' . rawurlencode( $filename ), $url );
		$this->assertStringContainsString( 'nonce=', $url );
		$this->assertStringContainsString( 'token=', $url );

		// Pull the embedded token out of the URL with native PHP parse_url
		// + parse_str so the assertion does not depend on any bootstrap
		// stub for URL parsing. rawurlencode of a 32-hex string is identity.
		$params = $this->parse_query_params( $url );
		$this->assertArrayHasKey( 'token', $params );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', (string) $params['token'] );

		// Round-trip: the server-side consume_dl_token() must accept the
		// URL-supplied token exactly once.
		$this->assertTrue(
			$this->handler->consume_dl_token( $filename, (string) $params['token'] ),
			'The URL-supplied token must be redeemable on first use.'
		);
		$this->assertFalse(
			$this->handler->consume_dl_token( $filename, (string) $params['token'] ),
			'The URL-supplied token must not be reusable after consumption.'
		);

		delete_option( 'sscribe_export_row_' . md5( $filename ) );
	}

	public function test_get_ajax_download_url_serializes_legacy_token_initialization(): void {
		$filename   = 'sscribe-token-legacy-' . uniqid() . '.zip';
		$option     = 'sscribe_export_row_' . md5( $filename );
		$lock_name  = 'download-' . md5( $filename );
		$lock       = new \SScribe_Export_Lock_Manager();
		$lock_token = $lock->acquire_lock( $lock_name, 30, 25 );

		update_option( $option, array( 'user_id' => 1 ), false );
		$GLOBALS['sscribe_test_current_user'] = new \WP_User( 1 );
		$this->assertIsString( $lock_token );
		$this->assertSame( '', $this->handler->get_ajax_download_url( $filename ) );
		$this->assertArrayNotHasKey( 'dl_token', get_option( $option, array() ) );

		$this->assertTrue( $lock->release_lock( $lock_name, $lock_token ) );
		$this->assertNotSame( '', $this->handler->get_ajax_download_url( $filename ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', (string) ( get_option( $option, array() )['dl_token'] ?? '' ) );

		delete_option( $option );
	}

	/**
	 * Rendering the same download in multiple admin views must not make
	 * an already-visible link stale before either link is redeemed.
	 */
	public function test_multiple_rendered_download_urls_keep_the_first_link_redeemable(): void {
		$filename = 'sscribe-token-multiple-views-' . uniqid() . '.zip';
		update_option(
			'sscribe_export_row_' . md5( $filename ),
			array( 'user_id' => 1 ),
			false
		);
		$GLOBALS['sscribe_test_current_user'] = new \WP_User( 1 );

		$first_url  = $this->handler->get_ajax_download_url( $filename );
		$second_url = $this->handler->get_ajax_download_url( $filename );
		$first      = $this->parse_query_params( $first_url );
		$second     = $this->parse_query_params( $second_url );

		$this->assertSame(
			$first['token'] ?? null,
			$second['token'] ?? null,
			'Rendering Recent Exports must not invalidate the success-view link.'
		);
		$this->assertTrue(
			$this->handler->consume_dl_token( $filename, (string) ( $first['token'] ?? '' ) ),
			'The first rendered link must remain redeemable until a download consumes it.'
		);

		$replacement_url = $this->handler->get_ajax_download_url( $filename );
		$replacement     = $this->parse_query_params( $replacement_url );
		$this->assertNotSame( $first['token'] ?? null, $replacement['token'] ?? null );
		$this->assertTrue(
			$this->handler->consume_dl_token( $filename, (string) ( $replacement['token'] ?? '' ) ),
			'A later admin render must expose the replacement token created after consumption.'
		);

		delete_option( 'sscribe_export_row_' . md5( $filename ) );
	}

	/**
	 * A concurrent redemption must fail while another request owns the
	 * per-export consume lock, without mutating the valid token.
	 */
	public function test_consume_dl_token_rejects_concurrent_redemption(): void {
		$filename   = 'sscribe-token-concurrent-' . uniqid() . '.zip';
		$option     = 'sscribe_export_row_' . md5( $filename );
		$lock_name  = 'download-' . md5( $filename );
		$lock       = new \SScribe_Export_Lock_Manager();
		$lock_token = $lock->acquire_lock( $lock_name, 30, 25 );

		update_option( $option, array( 'user_id' => 1 ), false );
		$token = $this->handler->rotate_dl_token( $filename );

		$this->assertIsString( $lock_token );
		$this->assertFalse( $this->handler->consume_dl_token( $filename, $token ) );
		$this->assertSame( $token, get_option( $option, array() )['dl_token'] ?? null );

		$this->assertTrue( $lock->release_lock( $lock_name, $lock_token ) );
		$this->assertTrue( $this->handler->consume_dl_token( $filename, $token ) );

		delete_option( $option );
	}

	/**
	 * Token redemption must fail closed when the replacement token cannot
	 * be persisted, leaving the original token available for a later retry.
	 */
	public function test_consume_dl_token_fails_closed_when_rotation_cannot_persist(): void {
		$filename = 'sscribe-token-write-failure-' . uniqid() . '.zip';
		$option   = 'sscribe_export_row_' . md5( $filename );

		update_option( $option, array( 'user_id' => 1 ), false );
		$token = $this->handler->rotate_dl_token( $filename );

		$GLOBALS['sscribe_test_update_option_failure'] = $option;
		$this->assertFalse( $this->handler->consume_dl_token( $filename, $token ) );
		$this->assertSame( $token, get_option( $option, array() )['dl_token'] ?? null );

		unset( $GLOBALS['sscribe_test_update_option_failure'] );
		$this->assertTrue( $this->handler->consume_dl_token( $filename, $token ) );

		delete_option( $option );
	}
}
