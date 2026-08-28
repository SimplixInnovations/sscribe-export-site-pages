<?php
declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_History_Responsive_Test extends TestCase {

	private function admin_css(): string {
		$path = __DIR__ . '/../../admin/css/sscribe-admin.css';
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	private function project_file( string $relative_path ): string {
		$path = __DIR__ . '/../../' . $relative_path;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_history_rows_reflow_before_the_desktop_table_can_overflow(): void {
		$css          = $this->admin_css();
		$desktop_at   = strpos( $css, '#sscribe-history-table .sscribe-history-row {' );
		$responsive_at = strrpos( $css, '@media (width <= 1000px)' );

		$this->assertNotFalse( $desktop_at, 'The semantic desktop History table rule must exist.' );
		$this->assertNotFalse( $responsive_at, 'History must reflow before narrow WordPress admin layouts clip its actions.' );
		$this->assertGreaterThan( $desktop_at, $responsive_at, 'The responsive card rules must override the desktop table rules.' );

		$responsive_css = substr( $css, (int) $responsive_at );
		$this->assertMatchesRegularExpression(
			'/#sscribe-history-table \.sscribe-history-row\s*\{.*?display:\s*grid.*?grid-template-columns:\s*32px\s+minmax\(0,\s*1fr\).*?width:\s*100%.*?min-width:\s*0/s',
			$responsive_css,
			'Each narrow History row must become a bounded two-column record card.'
		);
		$this->assertMatchesRegularExpression(
			'/#sscribe-history-table \.sscribe-history-cell-actions\s*\{.*?grid-column:\s*1\s*\/\s*-1/s',
			$responsive_css,
			'History actions must occupy their own full-width card row.'
		);
	}

	public function test_history_content_and_actions_wrap_inside_the_card(): void {
		$css           = $this->admin_css();
		$responsive_at = strrpos( $css, '@media (width <= 1000px)' );

		$this->assertNotFalse( $responsive_at, 'The History card breakpoint must exist.' );
		$responsive_css = substr( $css, (int) $responsive_at );
		$this->assertMatchesRegularExpression(
			'/\.sscribe-file-details strong,.*?\.sscribe-file-details span,.*?\.sscribe-file-human-label\s*\{.*?white-space:\s*normal.*?overflow-wrap:\s*anywhere/s',
			$responsive_css,
			'Long filenames and translated metadata must wrap inside the card.'
		);
		$this->assertMatchesRegularExpression(
			'/#sscribe-history-table \.sscribe-history-actions\s*\{.*?flex-wrap:\s*wrap.*?width:\s*100%/s',
			$responsive_css,
			'History actions must wrap inside their bounded row.'
		);
		$this->assertMatchesRegularExpression(
			'/#sscribe-history-table \.sscribe-history-actions (?:a|a,).*?button\s*\{.*?min-width:\s*0.*?white-space:\s*normal.*?overflow-wrap:\s*anywhere/s',
			$responsive_css,
			'Long translated action labels must not widen the History card.'
		);
	}

	public function test_dynamic_history_rows_keep_accessible_localized_labels(): void {
		$javascript = $this->project_file( 'admin/js/sscribe-admin.js' );
		$php        = $this->project_file( 'admin/class-sscribe-admin.php' );

		$this->assertStringContainsString( 'strings.select_export_label', $javascript );
		$this->assertMatchesRegularExpression(
			'/class="sscribe-history-check".*?aria-label=".*?selectExportLabel/s',
			$javascript,
			'Dynamically refreshed History checkboxes must retain an export-specific accessible name.'
		);
		foreach ( array( 'select_export_label', 'download_label', 'log_label', 'delete_label' ) as $key ) {
			$this->assertStringContainsString( "'{$key}'", $php, "The {$key} History string must be localized for JavaScript." );
		}
	}
}
