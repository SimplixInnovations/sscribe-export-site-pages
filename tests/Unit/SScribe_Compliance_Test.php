<?php
declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Compliance_Test extends TestCase {

	private string $zip_path = '';

	protected function tearDown(): void {
		if ( '' !== $this->zip_path && is_file( $this->zip_path ) ) {
			unlink( $this->zip_path );
		}
		parent::tearDown();
	}

	public function test_is_enabled_accepts_truthy_option_values_only(): void {
		$this::assertTrue( \SScribe_Compliance::is_enabled( array( 'sscribe_compliance_mode' => '1' ) ) );
		$this::assertTrue( \SScribe_Compliance::is_enabled( array( 'sscribe_compliance_mode' => 'yes' ) ) );
		$this::assertFalse( \SScribe_Compliance::is_enabled( array( 'sscribe_compliance_mode' => '0' ) ) );
		$this::assertFalse( \SScribe_Compliance::is_enabled( array() ) );
		$this::assertFalse( \SScribe_Compliance::is_enabled( array( 'sscribe_compliance_mode' => array( '1' ) ) ) );
	}

	public function test_retention_days_are_clamped(): void {
		$this::assertSame( 365, \SScribe_Compliance::retention_days() );

		$callback = static fn(): int => 999999;
		add_filter( 'sscribe_compliance_retention_days', $callback );
		try {
			$this::assertSame( 3650, \SScribe_Compliance::retention_days() );
		} finally {
			remove_filter( 'sscribe_compliance_retention_days', $callback );
		}

		$zero = static fn(): int => 0;
		add_filter( 'sscribe_compliance_retention_days', $zero );
		try {
			$this::assertSame( 1, \SScribe_Compliance::retention_days() );
		} finally {
			remove_filter( 'sscribe_compliance_retention_days', $zero );
		}
	}

	public function test_signature_round_trips_and_detects_tampering(): void {
		$manifest  = '{"schema":"sscribe-export-manifest/1","files":[]}';
		$signature = \SScribe_Compliance::sign( $manifest );

		$this::assertStringStartsWith( "sscribe-manifest-signature/1\nalgorithm: HMAC-SHA256\nkey_id: ", $signature );
		$this::assertTrue( \SScribe_Compliance::verify_signature( $manifest, $signature )['valid'] );
		$this::assertSame( 'signature_mismatch', \SScribe_Compliance::verify_signature( $manifest . ' ', $signature )['reason'] );
		$this::assertSame( 'malformed', \SScribe_Compliance::verify_signature( $manifest, 'nonsense' )['reason'] );

		$foreign = preg_replace( '/key_id: [a-f0-9]+/', 'key_id: 0000000000000000', $signature );
		$this::assertSame( 'key_mismatch', \SScribe_Compliance::verify_signature( $manifest, (string) $foreign )['reason'] );
	}

	public function test_signing_key_is_stable_and_not_autoloaded(): void {
		$first  = \SScribe_Compliance::signing_key();
		$second = \SScribe_Compliance::signing_key();

		$this::assertSame( 32, strlen( $first ) );
		$this::assertSame( $first, $second );
		$this::assertSame( 'no', $GLOBALS['sscribe_test_option_autoload'][ \SScribe_Compliance::KEY_OPTION ] ?? 'no' );
	}

	public function test_provenance_rows_follow_display_order_and_skip_blanks(): void {
		$rows = \SScribe_Compliance::provenance_rows(
			array(
				'exported_by'    => 'auditor',
				'source_url'     => 'https://example.org/p/',
				'content_sha256' => '',
				'post_id'        => 9,
			)
		);

		$this::assertSame(
			array(
				array( 'label' => 'Source URL', 'value' => 'https://example.org/p/' ),
				array( 'label' => 'Post ID', 'value' => '9' ),
				array( 'label' => 'Exported by', 'value' => 'auditor' ),
			),
			$rows
		);
	}

	public function test_manifest_block_records_exporter_environment_and_key_id(): void {
		$block = \SScribe_Compliance::manifest_block( 0 );

		$this::assertTrue( $block['enabled'] );
		$this::assertSame( 0, $block['exported_by']['id'] );
		$this::assertSame( 'system', $block['exported_by']['login'] );
		$this::assertSame( PHP_VERSION, $block['environment']['php'] );
		$this::assertSame( 365, $block['retention_days'] );
		$this::assertSame( \SScribe_Compliance::key_id( \SScribe_Compliance::signing_key() ), $block['signature']['key_id'] );
	}

	public function test_verify_archive_reports_missing_and_mismatched_files(): void {
		$this->zip_path = sys_get_temp_dir() . '/sscribe-verify-' . bin2hex( random_bytes( 4 ) ) . '.zip';
		$manifest       = array(
			'schema' => 'sscribe-export-manifest/1',
			'files'  => array(
				array( 'path' => 'ok.txt', 'sha256' => hash( 'sha256', 'good' ) ),
				array( 'path' => 'changed.txt', 'sha256' => hash( 'sha256', 'original' ) ),
				array( 'path' => 'gone.txt', 'sha256' => hash( 'sha256', 'x' ) ),
			),
		);
		$manifest_json  = (string) wp_json_encode( $manifest );

		$zip = new \ZipArchive();
		$this::assertTrue( $zip->open( $this->zip_path, \ZipArchive::CREATE ) );
		$zip->addFromString( 'manifest.json', $manifest_json );
		$zip->addFromString( 'ok.txt', 'good' );
		$zip->addFromString( 'changed.txt', 'tampered' );
		$zip->addFromString( 'manifest.sig', \SScribe_Compliance::sign( $manifest_json ) );
		$zip->close();

		$report = \SScribe_Compliance::verify_archive( $this->zip_path );

		$this::assertFalse( $report['ok'] );
		$this::assertSame( 3, $report['files'] );
		$this::assertSame( array( 'changed.txt' ), $report['mismatched'] );
		$this::assertSame( array( 'gone.txt' ), $report['missing'] );
		$this::assertSame( 'valid', $report['signature'] );
		$this::assertSame( 'not_found', \SScribe_Compliance::verify_archive( $this->zip_path . '.missing' )['error'] );
	}
}
