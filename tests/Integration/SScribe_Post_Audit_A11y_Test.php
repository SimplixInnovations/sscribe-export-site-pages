<?php
/**
 * Accessibility/UX regressions found by the final first-party audit.
 *
 * These checks intentionally pin behavior that the broad axe/static scans
 * did not previously exercise.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Post_Audit_A11y_Test extends TestCase {

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	public function test_inactive_tabpanels_are_hidden_before_javascript_initializes(): void {
		$php = (string) file_get_contents( self::root() . '/admin/partials/sscribe-admin-display.php' );
		foreach ( array( 'history', 'support', 'debug' ) as $tab ) {
			$this->assertMatchesRegularExpression(
				'/id="sscribe-tab-' . preg_quote( $tab, '/' ) . '"[^>]*aria-hidden="true"[^>]*\bhidden\b/',
				$php,
				"Inactive {$tab} tab must be removed from the initial focus/accessibility tree."
			);
		}
	}

	public function test_toasts_render_a_real_keyboard_operable_dismiss_button(): void {
		$js = (string) file_get_contents( self::root() . '/admin/js/sscribe-admin.js' );
		$this->assertStringContainsString( "addClass('sscribe-toast-dismiss')", $js );
		$this->assertStringContainsString( "sscribe_data.strings.dismiss_notification", $js );
		$this->assertStringNotContainsString( "$toast.on('click.sscribe'", $js );
	}

	public function test_preflight_banner_restores_focus_for_every_exit_path(): void {
		$js = (string) file_get_contents( self::root() . '/admin/js/sscribe-admin.js' );
		$this->assertStringContainsString( 'const preflightReturnFocus', $js );
		$this->assertStringContainsString( 'restorePreflightFocus', $js );
		$this->assertGreaterThanOrEqual(
			3,
			substr_count( $js, 'restorePreflightFocus();' ),
			'Proceed, cancel, and close must all restore focus after removing the preflight banner.'
		);
	}

	public function test_delete_confirmation_hint_has_a_real_anchor(): void {
		$php = (string) file_get_contents( self::root() . '/admin/partials/sscribe-admin-display.php' );
		$js  = (string) file_get_contents( self::root() . '/admin/js/sscribe-admin.js' );
		$this->assertStringContainsString( 'sscribe-history-filename', $php );
		$this->assertStringContainsString( ".find('.sscribe-history-filename')", $js );
	}

	public function test_progress_stepper_exposes_current_step_semantically(): void {
		$js = (string) file_get_contents( self::root() . '/admin/js/sscribe-admin.js' );
		$this->assertStringContainsString( "attr('aria-current', 'step')", $js );
		$this->assertStringContainsString( "removeAttr('aria-current')", $js );
	}

	public function test_debug_refresh_and_transient_feedback_are_live_regions(): void {
		$php = (string) file_get_contents( self::root() . '/admin/partials/sscribe-admin-debug-tab.php' );
		$js  = (string) file_get_contents( self::root() . '/admin/js/sscribe-debug-console.js' );
		$this->assertMatchesRegularExpression(
			'/id="sscribe-debug-refresh-paused"[^>]*role="status"[^>]*aria-live="polite"/',
			$php
		);
		$this->assertStringNotContainsString( 'sscribe-debug-console-card" role="log"', $php );
		$this->assertStringContainsString( 'sscribe-feedback sscribe-feedback-success" role="status" aria-live="polite"', $js );
		$this->assertStringContainsString( 'sscribe-feedback sscribe-feedback-error" role="alert"', $js );
		$this->assertStringContainsString( 'sscribe-rotated-error" role="alert"', $js );
	}

	public function test_preflight_dynamic_headings_do_not_skip_levels(): void {
		$js = (string) file_get_contents( self::root() . '/admin/js/sscribe-admin.js' );
		$this->assertStringContainsString( "'<h2>'", $js );
		$this->assertStringContainsString( "'<h3 class="sscribe-preflight-section-title">'", $js );
	}
}
