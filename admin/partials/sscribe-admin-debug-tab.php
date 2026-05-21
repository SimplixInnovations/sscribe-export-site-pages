<?php
/**
 * SScribe Debug Tab Template
 *
 * @package SScribe_Export_Site_Pages
 * @subpackage Admin/Partials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sscribe_debug_settings = SScribe_Settings::get_debug_settings();
$sscribe_log_levels     = array(
	SScribe_Settings::LEVEL_ALL,
	SScribe_Settings::LEVEL_DEBUG,
	SScribe_Settings::LEVEL_INFO,
	SScribe_Settings::LEVEL_NOTICE,
	SScribe_Settings::LEVEL_WARNING,
	SScribe_Settings::LEVEL_ERROR,
	SScribe_Settings::LEVEL_CRITICAL,
);
?>

<div class="sscribe-debug-master" id="sscribe-admin-wrap">
	<div class="sscribe-debug-header">
		<div class="sscribe-debug-title-row">
			<h2><?php esc_html_e( 'Debug Console', 'sscribe-export-site-pages' ); ?></h2>
			<button type="button" class="sscribe-button sscribe-button-icon" id="sscribe-debug-help-btn" aria-label="<?php esc_attr_e( 'Help', 'sscribe-export-site-pages' ); ?>">
				<span aria-hidden="true">?</span>
			</button>
		</div>
		<p class="sscribe-debug-description">
			<?php esc_html_e( 'View detailed export logs, toggle debug mode, and manage rotated log files.', 'sscribe-export-site-pages' ); ?>
		</p>
	</div>

	<div class="sscribe-debug-settings-card">
		<div class="sscribe-debug-settings-grid">
			<div class="sscribe-debug-toggle-section">
				<label class="sscribe-debug-toggle-label" for="sscribe-debug-enabled">
					<span class="sscribe-toggle-switch">
						<input type="checkbox" id="sscribe-debug-enabled" <?php checked( $sscribe_debug_settings['debug_enabled'] ); ?>>
						<span class="sscribe-toggle-slider"></span>
					</span>
					<span class="sscribe-toggle-text">
						<strong><?php esc_html_e( 'Enable Debug Logging', 'sscribe-export-site-pages' ); ?></strong>
						<small><?php esc_html_e( 'Captures detailed logs for all export operations', 'sscribe-export-site-pages' ); ?></small>
					</span>
				</label>
			</div>
			<div class="sscribe-debug-level-section">
				<label for="sscribe-debug-level"><?php esc_html_e( 'Log Level', 'sscribe-export-site-pages' ); ?></label>
				<select id="sscribe-debug-level" class="sscribe-select">
					<?php foreach ( $sscribe_log_levels as $sscribe_level ) : ?>
						<option value="<?php echo esc_attr( $sscribe_level ); ?>" <?php selected( $sscribe_debug_settings['log_level'], $sscribe_level ); ?>>
							<?php echo esc_html( $sscribe_level ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="sscribe-debug-save-section">
				<button type="button" class="sscribe-button sscribe-button-primary" id="sscribe-debug-save-settings">
					<?php esc_html_e( 'Save Settings', 'sscribe-export-site-pages' ); ?>
				</button>
				<span class="sscribe-debug-save-feedback" id="sscribe-debug-save-feedback"></span>
			</div>
		</div>
	</div>

	<div class="sscribe-debug-controls-card">
		<div class="sscribe-debug-controls-row">
			<div class="sscribe-debug-filter">
				<label for="sscribe-debug-filter-level"><?php esc_html_e( 'Filter:', 'sscribe-export-site-pages' ); ?></label>
				<select id="sscribe-debug-filter-level" class="sscribe-select">
					<option value="ALL"><?php esc_html_e( 'All Levels', 'sscribe-export-site-pages' ); ?></option>
					<option value="DEBUG">DEBUG</option>
					<option value="INFO">INFO</option>
					<option value="NOTICE">NOTICE</option>
					<option value="WARNING">WARNING</option>
					<option value="ERROR">ERROR</option>
					<option value="CRITICAL">CRITICAL</option>
				</select>
			</div>
			<div class="sscribe-debug-search">
				<label for="sscribe-debug-search" class="screen-reader-text"><?php esc_html_e( 'Search logs:', 'sscribe-export-site-pages' ); ?></label>
				<input type="text" id="sscribe-debug-search" class="sscribe-input" placeholder="<?php esc_attr_e( 'Search logs...', 'sscribe-export-site-pages' ); ?>">
			</div>
		</div>
		<div class="sscribe-debug-refresh-row">
			<div class="sscribe-debug-refresh-mode">
				<label class="sscribe-radio-label">
					<input type="radio" name="sscribe_refresh_mode" value="auto" <?php checked( $sscribe_debug_settings['auto_refresh'] ); ?>>
					<span class="sscribe-radio-text"><?php esc_html_e( 'Auto-refresh (10s)', 'sscribe-export-site-pages' ); ?></span>
				</label>
				<label class="sscribe-radio-label">
					<input type="radio" name="sscribe_refresh_mode" value="manual" <?php checked( ! $sscribe_debug_settings['auto_refresh'] ); ?>>
					<span class="sscribe-radio-text"><?php esc_html_e( 'Manual refresh only', 'sscribe-export-site-pages' ); ?></span>
				</label>
			</div>
			<button type="button" class="sscribe-button sscribe-button-icon" id="sscribe-debug-refresh-btn" aria-label="<?php esc_attr_e( 'Refresh logs', 'sscribe-export-site-pages' ); ?>">
				<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
					<path d="M13.65 2.35A8 8 0 1 0 16 8h-2a6 6 0 1 1-1.76-4.24L10 6h6V0l-2.35 2.35z"/>
				</svg>
			</button>
		</div>
	</div>

	<div class="sscribe-debug-console-card" role="log" aria-live="polite" aria-label="<?php esc_attr_e( 'Debug log entries', 'sscribe-export-site-pages' ); ?>">
		<div class="sscribe-debug-console-header">
			<span class="sscribe-debug-console-title"><?php esc_html_e( 'Console Output', 'sscribe-export-site-pages' ); ?></span>
			<span class="sscribe-debug-console-count" id="sscribe-debug-entry-count"></span>
		</div>
		<div class="sscribe-debug-console-body" id="sscribe-debug-console-body">
			<div class="sscribe-debug-empty" id="sscribe-debug-empty">
				<svg width="48" height="48" viewBox="0 0 48 48" fill="none" aria-hidden="true">
					<rect x="8" y="12" width="32" height="28" rx="2" stroke="currentColor" stroke-width="2" fill="none" opacity="0.3"/>
					<path d="M8 18h32M16 24h8M16 30h16" stroke="currentColor" stroke-width="2" opacity="0.3"/>
				</svg>
				<p><?php esc_html_e( 'No log entries yet. Enable debug mode and run an export to see logs.', 'sscribe-export-site-pages' ); ?></p>
			</div>
			<div class="sscribe-debug-entries" id="sscribe-debug-entries">
			</div>
		</div>
	</div>

	<div class="sscribe-debug-actions">
		<button type="button" class="sscribe-button sscribe-button-danger" id="sscribe-debug-clear-btn">
			<?php esc_html_e( 'Clear Logs', 'sscribe-export-site-pages' ); ?>
		</button>
		<button type="button" class="sscribe-button" id="sscribe-debug-export-btn">
			<?php esc_html_e( 'Export JSON', 'sscribe-export-site-pages' ); ?>
		</button>
	</div>

	<details class="sscribe-debug-rotated">
		<summary>
			<span class="sscribe-debug-rotated-title"><?php esc_html_e( 'Rotated Logs', 'sscribe-export-site-pages' ); ?></span>
			<span class="sscribe-debug-rotated-hint"><?php esc_html_e( 'Click to expand', 'sscribe-export-site-pages' ); ?></span>
		</summary>
		<div class="sscribe-debug-rotated-body" id="sscribe-debug-rotated-body">
			<div class="sscribe-debug-rotated-empty"><?php esc_html_e( 'No rotated log files.', 'sscribe-export-site-pages' ); ?></div>
		</div>
	</details>
</div>
