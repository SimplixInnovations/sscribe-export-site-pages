<?php
declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Success_Summary_Responsive_Test extends TestCase {

	private function admin_css(): string {
		$path = __DIR__ . '/../../admin/css/sscribe-admin.css';
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_completion_metrics_reflow_after_the_desktop_grid_rule(): void {
		$css      = $this->admin_css();
		$base_pos = strpos( $css, ".sscribe-success-meta {\n\tdisplay: grid;" );

		$this->assertNotFalse( $base_pos, 'The desktop completion-summary grid must exist.' );

		$responsive_css = substr( $css, (int) $base_pos );
		$this->assertMatchesRegularExpression(
			'/@media \(width <= 782px\).*?\.sscribe-success-meta\s*\{.*?grid-template-columns:\s*repeat\(2,\s*minmax\(0,\s*1fr\)\)/s',
			$responsive_css,
			'The tablet rule must follow and override the four-column desktop grid.'
		);
		$this->assertMatchesRegularExpression(
			'/@media \(width <= 480px\).*?\.sscribe-success-meta\s*\{.*?grid-template-columns:\s*minmax\(0,\s*1fr\).*?\.sscribe-success-meta-item\s*\{.*?display:\s*grid.*?grid-template-columns:\s*minmax\(0,\s*auto\)\s+minmax\(0,\s*1fr\)/s',
			$responsive_css,
			'Phone metrics must become one overflow-safe label/value row per metric.'
		);
	}

	public function test_phone_labels_values_and_actions_cannot_force_horizontal_overflow(): void {
		$css      = $this->admin_css();
		$tablet_at = strrpos( $css, '@media (width <= 782px)' );
		$phone_at = strrpos( $css, '@media (width <= 480px)' );

		$this->assertNotFalse( $tablet_at, 'The final tablet breakpoint must exist.' );
		$this->assertNotFalse( $phone_at, 'The final phone breakpoint must exist.' );
		$tablet_css = substr( $css, (int) $tablet_at, (int) $phone_at - (int) $tablet_at );
		$phone_css = substr( $css, (int) $phone_at );
		$this->assertMatchesRegularExpression(
			'/\.sscribe-success-meta-item dt,\s*\.sscribe-success-meta-item dd\s*\{.*?overflow-wrap:\s*anywhere/s',
			$tablet_css,
			'Long translated completion labels and values must wrap inside the responsive grid.'
		);
		$this->assertMatchesRegularExpression(
			'/\.sscribe-success-meta-item dd\s*\{.*?min-inline-size:\s*0.*?overflow-wrap:\s*anywhere/s',
			$phone_css,
			'Long completion values must wrap inside their metric row.'
		);
		$this->assertMatchesRegularExpression(
			'/\.sscribe-success-actions\s*\{.*?display:\s*grid.*?grid-template-columns:\s*minmax\(0,\s*1fr\).*?inline-size:\s*100%/s',
			$phone_css,
			'Completion actions must stack in one bounded full-width column on phones.'
		);
		$this->assertMatchesRegularExpression(
			'/\.sscribe-success-actions \.sscribe-button\s*\{.*?min-inline-size:\s*0.*?white-space:\s*normal.*?overflow-wrap:\s*anywhere/s',
			$phone_css,
			'Translated completion actions must wrap without widening the viewport.'
		);
	}
}
