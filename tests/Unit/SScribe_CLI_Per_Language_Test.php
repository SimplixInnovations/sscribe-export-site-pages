<?php
/**
 * Per-language CLI export tests.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_CLI_Command;
use SScribe_Page_Collector;

final class SScribe_CLI_Per_Language_Test extends TestCase {

	private function collector( bool $active, array $languages ): SScribe_Page_Collector {
		return new class( $active, $languages ) extends SScribe_Page_Collector {
			/**
			 * @param bool  $active    Whether a multilingual plugin is active.
			 * @param array $languages Language rows.
			 */
			public function __construct( private bool $active, private array $languages ) {
				parent::__construct();
			}

			public function is_multilingual_active(): bool {
				return $this->active;
			}

			public function get_languages(): array {
				return $this->languages;
			}
		};
	}

	public function test_returns_empty_without_a_multilingual_plugin(): void {
		$this->assertSame( array(), SScribe_CLI_Command::languages_for_run( $this->collector( false, array( array( 'code' => 'en' ) ) ) ) );
	}

	public function test_returns_unique_sanitized_codes_in_order(): void {
		$collector = $this->collector(
			true,
			array(
				array( 'code' => 'en', 'name' => 'English' ),
				array( 'code' => 'AR', 'name' => 'Arabic' ),
				array( 'code' => 'en' ),
				array( 'name' => 'no code' ),
				'junk',
			)
		);

		$this->assertSame( array( 'en', 'ar' ), SScribe_CLI_Command::languages_for_run( $collector ) );
	}

	public function test_job_from_args_ignores_per_language_flag(): void {
		$job = SScribe_CLI_Command::job_from_args( array( 'per-language' => true, 'formats' => 'docx' ) );

		$this->assertSame( '', $job->language );
		$this->assertSame( array( 'docx' ), $job->formats );
	}
}
