<?php
/**
 * SScribe Debug Tab Template
 *
 * @package SScribe_Export_Site_Pages
 * @subpackage Admin/Partials
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sscribe_debug_settings       = SScribe_Settings::get_debug_settings();
$sscribe_log_levels           = array(
	SScribe_Settings::LEVEL_ALL,
	SScribe_Settings::LEVEL_DEBUG,
	SScribe_Settings::LEVEL_INFO,
	SScribe_Settings::LEVEL_NOTICE,
	SScribe_Settings::LEVEL_WARNING,
	SScribe_Settings::LEVEL_ERROR,
	SScribe_Settings::LEVEL_CRITICAL,
);
$sscribe_show_wp_debug_notice = ( defined( 'WP_DEBUG' ) && WP_DEBUG );
?>

<?php if ( $sscribe_show_wp_debug_notice ) : ?>
	<div class="sscribe-debug-wp-debug-notice">
		<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
			<path d="M8 0a8 8 0 1 0 0 16A8 8 0 0 0 8 0zm.75 4.5a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0zM7.25 7h1.5v5h-1.5V7z"/>
		</svg>
		<span><?php esc_html_e( 'WP_DEBUG is enabled. Debug logs may contain sensitive information. Disable on production sites.', 'sscribe-export-site-pages' ); ?></span>
	</div>
<?php endif; ?>

<div class="sscribe-debug-master" id="sscribe-debug-root">
	<div class="sscribe-debug-header">
		<div class="sscribe-debug-title-row">
			<h2><?php esc_html_e( 'Debug Console', 'sscribe-export-site-pages' ); ?></h2>
			<button type="button" class="sscribe-button sscribe-button-icon sscribe-btn-sm" id="sscribe-debug-help-btn" aria-label="<?php esc_attr_e( 'Help', 'sscribe-export-site-pages' ); ?>" aria-controls="sscribe-debug-help-content" aria-expanded="false">
				<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'info', 16 ) ); ?>
			</button>
		</div>
		<p class="sscribe-debug-description">
			<?php esc_html_e( 'View detailed export logs, toggle debug mode, and manage rotated log files.', 'sscribe-export-site-pages' ); ?>
		</p>
	</div>

	<div class="sscribe-debug-settings-card">
		<div class="sscribe-debug-settings-grid">
			<div class="sscribe-debug-toggle-section">
				<label class="sscribe-debug-toggle-label">
					<span class="sscribe-toggle-switch">
						<input type="checkbox" id="sscribe-debug-enabled" role="switch" aria-checked="<?php echo esc_attr( $sscribe_debug_settings['debug_enabled'] ? 'true' : 'false' ); ?>" <?php checked( $sscribe_debug_settings['debug_enabled'] ); ?>>
						<span class="sscribe-toggle-slider" aria-hidden="true"></span>
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
				<button type="button" class="sscribe-button sscribe-button-primary" id="sscribe-debug-save-settings" aria-describedby="sscribe-debug-save-feedback">
					<?php esc_html_e( 'Save Settings', 'sscribe-export-site-pages' ); ?>
				</button>
				<span class="sscribe-debug-save-feedback" id="sscribe-debug-save-feedback" aria-live="polite"></span>
			</div>
		</div>
	</div>

	<div class="sscribe-debug-controls-card">
		<div class="sscribe-debug-controls-row">
			<div class="sscribe-debug-filter">
				<label for="sscribe-debug-filter-level"><?php esc_html_e( 'Log level', 'sscribe-export-site-pages' ); ?></label>
				<select id="sscribe-debug-filter-level" class="sscribe-select">
					<option value="ALL"><?php esc_html_e( 'All Levels', 'sscribe-export-site-pages' ); ?></option>
					<option value="AUDIT"><?php esc_html_e( 'Audit', 'sscribe-export-site-pages' ); ?></option>
					<?php foreach ( $sscribe_log_levels as $sscribe_level ) : ?>
						<?php
						if ( SScribe_Settings::LEVEL_ALL === $sscribe_level ) {
							continue;
						}
						?>
						<option value="<?php echo esc_attr( $sscribe_level ); ?>"><?php echo esc_html( $sscribe_level ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="sscribe-debug-session-filter">
				<label for="sscribe-debug-session-id"><?php esc_html_e( 'Session ID', 'sscribe-export-site-pages' ); ?></label>
				<input type="text" id="sscribe-debug-session-id" class="sscribe-input" placeholder="<?php esc_attr_e( 'e.g. abc123de or full ID', 'sscribe-export-site-pages' ); ?>" maxlength="64" aria-describedby="sscribe-session-id-desc">
				<span id="sscribe-session-id-desc" class="screen-reader-text"><?php esc_html_e( 'Enter a full or partial session ID to filter logs. Matching is partial (contains).', 'sscribe-export-site-pages' ); ?></span>
			</div>
			<div class="sscribe-debug-search">
				<label for="sscribe-debug-search"><?php esc_html_e( 'Search logs', 'sscribe-export-site-pages' ); ?></label>
				<input type="text" id="sscribe-debug-search" class="sscribe-input" maxlength="200" placeholder="<?php esc_attr_e( 'Search logs...', 'sscribe-export-site-pages' ); ?>">
			</div>
		</div>
		<div class="sscribe-debug-refresh-row">
			<div class="sscribe-debug-refresh-mode">
				<span class="sscribe-debug-refresh-paused sscribe-hidden" id="sscribe-debug-refresh-paused" role="status" aria-live="polite" aria-atomic="true"><?php esc_html_e( 'Paused: tab inactive', 'sscribe-export-site-pages' ); ?></span>
				<label class="sscribe-radio-label">
					<input type="radio" name="sscribe_refresh_mode" value="auto" <?php checked( true, (bool) $sscribe_debug_settings['auto_refresh'] ); ?>>
					<span class="sscribe-radio-text"><?php esc_html_e( 'Auto-refresh (10s)', 'sscribe-export-site-pages' ); ?></span>
				</label>
				<label class="sscribe-radio-label">
					<input type="radio" name="sscribe_refresh_mode" value="manual" <?php checked( false, (bool) $sscribe_debug_settings['auto_refresh'] ); ?>>
					<span class="sscribe-radio-text"><?php esc_html_e( 'Manual refresh only', 'sscribe-export-site-pages' ); ?></span>
				</label>
			</div>
			<button type="button" class="sscribe-button sscribe-button-outline" id="sscribe-debug-refresh-btn">
				<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
					<path d="M13.65 2.35A8 8 0 1 0 16 8h-2a6 6 0 1 1-1.76-4.24L10 6h6V0l-2.35 2.35z"/>
				</svg>
				<span><?php esc_html_e( 'Refresh logs', 'sscribe-export-site-pages' ); ?></span>
			</button>
		</div>
	</div>

	<div class="sscribe-debug-stale-banner sscribe-hidden" id="sscribe-debug-stale-banner" role="status" aria-live="polite">
		<div class="sscribe-debug-stale-banner-icon" aria-hidden="true">
			<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'info', 18 ) ); ?>
		</div>
		<div class="sscribe-debug-stale-banner-text">
			<strong><?php esc_html_e( 'Showing previous logs', 'sscribe-export-site-pages' ); ?></strong>
			<span id="sscribe-debug-stale-banner-message"><?php esc_html_e( 'Debug mode is currently OFF. Entries below are from previous runs and will not update until debug mode is re-enabled.', 'sscribe-export-site-pages' ); ?></span>
		</div>
		<div class="sscribe-debug-stale-banner-actions">
			<button type="button" class="sscribe-button sscribe-button-primary sscribe-button-compact" id="sscribe-debug-enable-and-clear">
				<?php esc_html_e( 'Enable &amp; Continue', 'sscribe-export-site-pages' ); ?>
			</button>
		</div>
	</div>

	<div class="sscribe-debug-console-card" role="region" aria-label="<?php esc_attr_e( 'Debug log entries', 'sscribe-export-site-pages' ); ?>">
		<div class="sscribe-debug-console-header">
			<span class="sscribe-debug-console-title"><?php esc_html_e( 'Console Output', 'sscribe-export-site-pages' ); ?></span>
			<span class="sscribe-debug-console-count" id="sscribe-debug-entry-count" aria-live="polite" aria-atomic="true"><?php esc_html_e( 'Loading...', 'sscribe-export-site-pages' ); ?></span>
		</div>
		<div class="sscribe-debug-console-body" id="sscribe-debug-console-body">
			<div class="sscribe-debug-empty sscribe-hidden" id="sscribe-debug-empty">
				<svg width="48" height="48" viewBox="0 0 48 48" fill="none" aria-hidden="true">
					<rect x="8" y="12" width="32" height="28" rx="2" stroke="currentColor" stroke-width="2" fill="none" opacity="0.3"/>
					<path d="M8 18h32M16 24h8M16 30h16" stroke="currentColor" stroke-width="2" opacity="0.3"/>
				</svg>
				<p><?php esc_html_e( 'No log entries yet. Enable debug mode and run an export to see logs.', 'sscribe-export-site-pages' ); ?></p>
				<button type="button" class="sscribe-button sscribe-button-primary sscribe-button-compact sscribe-hidden" id="sscribe-debug-empty-enable" hidden>
					<?php esc_html_e( 'Enable Debug Logging', 'sscribe-export-site-pages' ); ?>
				</button>
			</div>
			<div class="sscribe-debug-entries" id="sscribe-debug-entries">
			</div>
		</div>
	</div>

	<div class="sscribe-debug-actions">
		<button type="button" class="sscribe-button sscribe-button-outline" id="sscribe-debug-export-btn">
			<?php
			esc_html_e( 'Export as JSON', 'sscribe-export-site-pages' );
			?>
			<span class="sscribe-export-btn-scope"><?php esc_html_e( '(all entries)', 'sscribe-export-site-pages' ); ?></span>
		</button>
		<button type="button" class="sscribe-button sscribe-button-danger" id="sscribe-debug-clear-btn" disabled>
			<?php esc_html_e( 'Clear Logs', 'sscribe-export-site-pages' ); ?>
		</button>
	</div>

	<details class="sscribe-debug-rotated" id="sscribe-debug-rotated-details">
		<summary>
			<span class="sscribe-debug-rotated-title"><?php esc_html_e( 'Rotated Logs', 'sscribe-export-site-pages' ); ?></span>
			<span class="sscribe-debug-rotated-hint"><?php esc_html_e( 'Click to expand', 'sscribe-export-site-pages' ); ?></span>
		</summary>
		<div class="sscribe-debug-rotated-body" id="sscribe-debug-rotated-body" aria-live="polite" aria-atomic="false">
			<div class="sscribe-debug-rotated-empty"><?php esc_html_e( 'No rotated log files.', 'sscribe-export-site-pages' ); ?></div>
		</div>
	</details>

	<div id="sscribe-debug-help-content" hidden>
		<h3 id="sscribe-debug-help-title"><?php esc_html_e( 'Debug Console Help', 'sscribe-export-site-pages' ); ?></h3>
		<p><?php esc_html_e( 'View detailed export logs, toggle debug mode, and manage rotated log files. Logs capture detailed information about export operations including processing steps, errors, and performance metrics.', 'sscribe-export-site-pages' ); ?></p>
		<h4><?php esc_html_e( 'Auto-refresh', 'sscribe-export-site-pages' ); ?></h4>
		<p><?php esc_html_e( 'When enabled, logs refresh automatically every 10 seconds. Manual mode gives you full control over when to refresh.', 'sscribe-export-site-pages' ); ?></p>
		<h4><?php esc_html_e( 'Filters', 'sscribe-export-site-pages' ); ?></h4>
		<p><?php esc_html_e( 'Use the filter dropdown, session ID input, or search box to narrow down log entries. Filtered views are reflected in the Export JSON button label.', 'sscribe-export-site-pages' ); ?></p>
		<h4><?php esc_html_e( 'Rotated Logs', 'sscribe-export-site-pages' ); ?></h4>
		<p><?php esc_html_e( 'Rotated log files are older logs that have been archived. Click to expand, then View, Export, or Delete individual files.', 'sscribe-export-site-pages' ); ?></p>
	</div>
</div>
