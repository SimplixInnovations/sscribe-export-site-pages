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
		$this->assertStringNotContainsString( '$toast.on(\'click.sscribe\'', $js );
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
		$this->assertStringContainsString( 'sscribe-phase-completed-label', $js );
		$this->assertStringContainsString( "\$completedLabel.prop('hidden', false)", $js );
		$php = (string) file_get_contents( self::root() . '/admin/partials/sscribe-admin-display.php' );
		$this->assertSame( 3, substr_count( $php, 'sscribe-phase-completed-label' ) );
		$this->assertStringContainsString( 'aria-current="step"', $php );
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
		$this->assertStringContainsString( '\'<h3 class="sscribe-preflight-section-title">\'', $js );
	}


	public function test_export_configuration_labels_are_semantic_headings(): void {
		$php = (string) file_get_contents( self::root() . '/admin/partials/sscribe-admin-display.php' );
		$this->assertSame( 4, substr_count( $php, '<h3 class="sscribe-section-title">' ) );
		$this->assertSame( 1, substr_count( $php, '<h3 class="sscribe-config-section-header">' ) );
		$this->assertStringNotContainsString( '<div class="sscribe-section-title">', $php );
		$this->assertStringNotContainsString( '<div class="sscribe-config-section-header">', $php );
	}


	public function test_dynamic_heading_fragments_never_cross_heading_levels(): void {
		$js = (string) file_get_contents( self::root() . '/admin/js/sscribe-admin.js' );
		$this->assertStringNotContainsString( "<h4>' + this.escapeHtml(strings.preview_sample_title || 'Sample:') + '</h3>", $js );
		$this->assertStringNotContainsString( "<h4>' + this.escapeHtml(strings.log_page_details || 'Page Details') + '</h3>", $js );
		$this->assertStringNotContainsString( "<h4>' + this.escapeHtml(strings.log_errors || 'Errors') + '</h3>", $js );
		$this->assertDoesNotMatchRegularExpression( '/<h4 class="sscribe-support-section-title"[^>]*>[\\s\\S]*?<\\/h3>/', $js );
	}

	public function test_debug_help_clone_cannot_duplicate_the_labelledby_id(): void {
		$js = (string) file_get_contents( self::root() . '/admin/js/sscribe-debug-console.js' );
		$this->assertStringContainsString( 'sscribe-debug-help-dialog-title', $js );
		$this->assertStringContainsString( "querySelectorAll('[id]')", $js );
		$this->assertStringNotContainsString( "headerTitle.id = 'sscribe-debug-help-title'", $js );
	}


	public function test_debug_help_captures_title_before_stripping_clone_ids(): void {
		$js = (string) file_get_contents( self::root() . '/admin/js/sscribe-debug-console.js' );
		$title_lookup = strpos( $js, "helpClone.querySelector('#sscribe-debug-help-title')" );
		$id_cleanup   = strpos( $js, "helpClone.querySelectorAll('[id]')" );
		$this->assertNotFalse( $title_lookup );
		$this->assertNotFalse( $id_cleanup );
		$this->assertLessThan( $id_cleanup, $title_lookup, 'Debug help title must be captured before clone IDs are stripped.' );
	}


	public function test_dismissed_onboarding_and_advisories_are_restored_on_init(): void {
		$js = (string) file_get_contents( self::root() . '/admin/js/sscribe-admin.js' );
		$this->assertStringContainsString( 'restoreDismissedAdvisories', $js );
		$this->assertStringContainsString( "getItem('sscribe_onboarding_dismissed')", $js );
		$this->assertStringContainsString( "getItem('sscribe_preflight_dismissed')", $js );
	}

	public function test_unavailable_download_link_is_not_keyboard_focusable(): void {
		$php = (string) file_get_contents( self::root() . '/admin/partials/sscribe-admin-display.php' );
		$js  = (string) file_get_contents( self::root() . '/admin/js/sscribe-admin.js' );
		$this->assertMatchesRegularExpression(
			'/id="sscribe-download-btn"[^>]*aria-disabled="true"[^>]*tabindex="-1"/',
			$php
		);
		$this->assertStringContainsString( "removeAttr('aria-disabled tabindex')", $js );
		$this->assertStringContainsString( "'aria-disabled': 'true'", $js );
	}

}
