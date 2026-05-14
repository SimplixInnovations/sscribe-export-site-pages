<?php
/**
 * Admin page display template — Single-Page SaaS Dashboard.
 *
 * Variables are passed from SScribe_Admin::render_admin_page():
 * - $sscribe_wpml_active    (bool) Whether WPML is active.
 * - $sscribe_languages       (array) Available WPML languages.
 * - $sscribe_total_pages_all (int) Total page count across all languages.
 * - $sscribe_status_counts   (array) Page counts by status.
 * - $sscribe_seo_plugins     (array) Active SEO plugins.
 * - $sscribe_recent_exports  (array) Recent export files.
 * - $sscribe_debug_info (array) Debug information (when SSCRIBE_DEBUG is enabled).
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sscribe_wpml_active     = $sscribe_wpml_active ?? false;
$sscribe_languages       = $sscribe_languages ?? array();
$sscribe_total_pages_all = $sscribe_total_pages_all ?? 0;
$sscribe_status_counts   = $sscribe_status_counts ?? array();
$sscribe_seo_plugins     = $sscribe_seo_plugins ?? array();
$sscribe_recent_exports  = $sscribe_recent_exports ?? array();
$sscribe_debug_info      = $sscribe_debug_info ?? array();
$sscribe_is_debug        = $sscribe_is_debug ?? false;
?>

<div class="sscribe-master-container">
	<header class="sscribe-hero">
		<div class="sscribe-hero-content">
			<div class="sscribe-hero-left">
				<div class="sscribe-hero-logo">
				<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is sanitized in get_icon() with esc_attr() on all dynamic attributes. ?>
				<?php echo SScribe_Helpers::get_icon( 'file-doc', 36, 'sscribe-logo-img' ); ?>
					<span class="screen-reader-text">SScribe</span>
				</div>
				<div>
					<h1 class="sscribe-hero-title">SScribe</h1>
					<p class="sscribe-hero-subtitle"><?php esc_html_e( 'Export every page into beautifully formatted documents with multilingual support, SEO meta, and secure ZIP download.', 'sscribe-export-site-pages' ); ?></p>
				</div>
			</div>
			<span class="sscribe-hero-version">v<?php echo esc_html( SSCRIBE_VERSION ); ?></span>
		</div>
	</header>

	<a href="#sscribe-main-content" class="sscribe-skip-link screen-reader-text">
		<?php esc_html_e( 'Skip to main content', 'sscribe-export-site-pages' ); ?>
	</a>

	<div id="sscribe-live-region" class="screen-reader-text" aria-live="polite" aria-atomic="true"></div>
	<div id="sscribe-alert-region" class="screen-reader-text" aria-live="assertive" aria-atomic="true"></div>

	<div class="sscribe-workspace sscribe-flat-workspace" id="sscribe-main-content" role="main">
		<nav class="sscribe-tabs-nav" aria-label="<?php esc_attr_e( 'Main Navigation', 'sscribe-export-site-pages' ); ?>">
			<button type="button" class="sscribe-tab-btn sscribe-tab-active" data-tab="export">
				<?php echo SScribe_Helpers::get_icon( 'settings', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'Export', 'sscribe-export-site-pages' ); ?>
			</button>
			<button type="button" class="sscribe-tab-btn" data-tab="history">
				<?php echo SScribe_Helpers::get_icon( 'clock', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'History', 'sscribe-export-site-pages' ); ?>
			</button>
			<button type="button" class="sscribe-tab-btn" data-tab="docs">
				<?php echo SScribe_Helpers::get_icon( 'info', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'Support', 'sscribe-export-site-pages' ); ?>
			</button>
		</nav>

		<div class="sscribe-tab-content sscribe-tab-active" id="sscribe-tab-export">



				<div class="sscribe-export-bar">
					<div id="sscribe-time-estimate" class="sscribe-time-estimate" aria-live="polite">
						<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
						<?php echo SScribe_Helpers::get_icon( 'clock', 13 ); ?>
						<span id="sscribe-time-estimate-text"><?php esc_html_e( 'Select options to see estimated time', 'sscribe-export-site-pages' ); ?></span>
					</div>
					<div class="sscribe-export-bar-actions">
						<button type="button" id="sscribe-preview-btn" class="sscribe-button sscribe-button-outline sscribe-btn-sm" disabled aria-describedby="sscribe-preview-btn-hint">
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
							<?php echo SScribe_Helpers::get_icon( 'eye', 15 ); ?>
							<span><?php esc_html_e( 'Preview', 'sscribe-export-site-pages' ); ?></span>
						</button>
						<span id="sscribe-preview-btn-hint" class="screen-reader-text"><?php esc_html_e( 'Preview what your export will look like before generating', 'sscribe-export-site-pages' ); ?></span>
						<button type="button" id="sscribe-export-btn" class="sscribe-button sscribe-button-primary sscribe-btn-lg" disabled aria-describedby="sscribe-export-btn-hint">
							
							<span id="sscribe-export-btn-text"><?php esc_html_e( 'Generate Package', 'sscribe-export-site-pages' ); ?></span>
						</button>
						<span id="sscribe-export-btn-hint" class="screen-reader-text"><?php esc_html_e( 'Start the export process for selected pages and format', 'sscribe-export-site-pages' ); ?></span>
					</div>
				</div>

				<div id="sscribe-preview-panel" class="sscribe-modal sscribe-hidden" role="dialog" aria-modal="true" aria-labelledby="sscribe-preview-title">
					<div class="sscribe-modal-content" role="document" style="max-width: 800px; max-height: 85vh;">
<div class="sscribe-modal-header">
						<h3 id="sscribe-preview-title">
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
							<?php echo SScribe_Helpers::get_icon( 'eye', 16 ); ?>
							<?php esc_html_e( 'Export Preview', 'sscribe-export-site-pages' ); ?>
						</h3>
						<button type="button" id="sscribe-preview-close" class="sscribe-modal-close" aria-label="Close preview"><span aria-hidden="true">&times;</span></button>
					</div>
					<div id="sscribe-preview-content" class="sscribe-modal-body">
						<div class="sscribe-preview-loading">
							<span class="sscribe-loading-spinner"></span>
							<span><?php esc_html_e( 'Generating preview...', 'sscribe-export-site-pages' ); ?></span>
						</div>
					</div>
				</div>
</div>
</div>
</section>

		<div id="sscribe-progress-area" class="sscribe-status-alert sscribe-status-processing sscribe-hidden" role="status" aria-live="polite" aria-labelledby="sscribe-status-text">
			<div class="sscribe-spinner" aria-hidden="true">
				<img src="<?php echo esc_url( SScribe_Helpers::icon_url( 'loader' ) ); ?>" width="40" height="40" alt="" class="sscribe-spinner-img">
			</div>
			<div class="sscribe-status-info">
				<h4 id="sscribe-status-text" class="sscribe-status-heading">
					<?php esc_html_e( 'Connecting & fetching pages...', 'sscribe-export-site-pages' ); ?>
				</h4>
				<p id="sscribe-current-page" class="sscribe-current-page" aria-live="polite"></p>
				<div class="sscribe-progress-tracker">
					<div class="sscribe-progress-bar-container">
						<div id="sscribe-progress-bar" class="sscribe-progress-bar-fill"
							role="progressbar"
							aria-valuemin="0"
							aria-valuemax="100"
							aria-valuenow="0"
							aria-labelledby="sscribe-status-text"></div>
					</div>
					<span id="sscribe-progress-text" class="sscribe-progress-percentage" aria-hidden="true">0%</span>
				</div>
				<div class="sscribe-progress-meta">
					<span id="sscribe-time-remaining" class="sscribe-time-remaining" aria-live="off"></span>
				</div>
				<button type="button" id="sscribe-cancel-btn" class="sscribe-button sscribe-button-cancel" aria-describedby="sscribe-cancel-hint">
					<?php esc_html_e( 'Cancel Export', 'sscribe-export-site-pages' ); ?>
				</button>
				<span id="sscribe-cancel-hint" class="screen-reader-text"><?php esc_html_e( 'Stop the current export process and discard progress', 'sscribe-export-site-pages' ); ?></span>
			</div>
		</div>

		<div id="sscribe-download-area" class="sscribe-status-alert sscribe-status-success sscribe-hidden" role="status" aria-live="polite">
			<div class="sscribe-status-icon" aria-hidden="true">
				<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
				<?php echo SScribe_Helpers::get_icon( 'check-circle', 32 ); ?>
			</div>
			<div class="sscribe-status-info">
				<h4 class="sscribe-status-heading">
					<?php esc_html_e( 'Export Completed Successfully', 'sscribe-export-site-pages' ); ?>
				</h4>
				<p class="sscribe-status-desc">
					<?php esc_html_e( 'All selected pages have been packaged into a ZIP archive.', 'sscribe-export-site-pages' ); ?>
				</p>
				<div class="sscribe-success-actions">
					<a id="sscribe-download-btn" href="#" class="sscribe-button sscribe-button-success" download aria-describedby="sscribe-download-hint">
						<img src="<?php echo esc_url( SScribe_Helpers::icon_url( 'download-package' ) ); ?>" width="18" height="18" alt="" aria-hidden="true">
						<?php esc_html_e( 'Download ZIP File', 'sscribe-export-site-pages' ); ?>
					</a>
					<span id="sscribe-download-hint" class="screen-reader-text"><?php esc_html_e( 'Download the exported ZIP file to your computer', 'sscribe-export-site-pages' ); ?></span>
					<button type="button" id="sscribe-retry-btn" class="sscribe-button sscribe-button-ghost" aria-describedby="sscribe-retry-hint">
						<?php esc_html_e( 'Start New Export', 'sscribe-export-site-pages' ); ?>
					</button>
					<span id="sscribe-retry-hint" class="screen-reader-text"><?php esc_html_e( 'Clear current export and start a new one', 'sscribe-export-site-pages' ); ?></span>
				</div>
			</div>
		</div>

		<div id="sscribe-error-area" class="sscribe-status-alert sscribe-status-error sscribe-hidden" role="alert" aria-live="assertive">
			<div class="sscribe-status-icon" aria-hidden="true">
				<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
				<?php echo SScribe_Helpers::get_icon( 'warning-circle', 32 ); ?>
			</div>
			<div class="sscribe-status-info">
				<h4 class="sscribe-status-heading">
					<?php esc_html_e( 'Export Failed', 'sscribe-export-site-pages' ); ?>
				</h4>
				<p id="sscribe-error-text" class="sscribe-status-desc"></p>
				<div id="sscribe-error-guidance" class="sscribe-error-guidance sscribe-hidden">
					<p id="sscribe-error-guidance-text" class="sscribe-guidance-text"></p>
				</div>
				<div class="sscribe-error-actions">
					<button type="button" id="sscribe-error-try-again" class="sscribe-button sscribe-button-secondary" aria-describedby="sscribe-try-again-hint">
						<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
						<?php echo SScribe_Helpers::get_icon( 'refresh-cw', 16 ); ?>
						<?php esc_html_e( 'Try Again', 'sscribe-export-site-pages' ); ?>
					</button>
					<span id="sscribe-try-again-hint" class="screen-reader-text"><?php esc_html_e( 'Attempt the export again', 'sscribe-export-site-pages' ); ?></span>
				</div>
			</div>
		</div>

		</div>

		<div class="sscribe-tab-content" id="sscribe-tab-history">
			<section class="sscribe-panel">
					<div class="sscribe-panel-header">
						<div class="sscribe-panel-title">
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
							<?php echo SScribe_Helpers::get_icon( 'clock', 20, 'sscribe-icon-img' ); ?>
							<h2><?php esc_html_e( 'Recent Exports', 'sscribe-export-site-pages' ); ?></h2>
						</div>
						<span class="sscribe-badge"><?php esc_html_e( 'Auto-deletes in 72 hours', 'sscribe-export-site-pages' ); ?></span>
					</div>
					<div class="sscribe-history-table" id="sscribe-history-table">
						<?php if ( ! empty( $sscribe_recent_exports ) ) : ?>
							<?php foreach ( $sscribe_recent_exports as $sscribe_export ) : ?>
								<div class="sscribe-history-row" data-filename="<?php echo esc_attr( $sscribe_export['filename'] ); ?>">
									<div class="sscribe-history-file">
										<div class="sscribe-file-icon">
											<?php if ( ! empty( $sscribe_export['flag_url'] ) ) : ?>
												<img src="<?php echo esc_url( $sscribe_export['flag_url'] ); ?>" alt="<?php echo esc_attr( $sscribe_export['lang_name'] ); ?>" class="sscribe-file-icon-img">
											<?php else : ?>
												<span class="sscribe-file-icon-text"><?php echo esc_html( strtoupper( substr( $sscribe_export['lang_code'], 0, 2 ) ) ); ?></span>
											<?php endif; ?>
										</div>
										<div class="sscribe-file-details">
											<strong><?php echo esc_html( $sscribe_export['filename'] ); ?></strong>
											<span>
												<?php echo esc_html( wp_date( ( get_option( 'date_format' ) ?: 'Y-m-d' ) . ' ' . ( get_option( 'time_format' ) ?: 'H:i' ), $sscribe_export['time'] ) ); ?>
												&mdash; <?php echo esc_html( size_format( $sscribe_export['size'] ) ); ?>
											</span>
										</div>
									</div>
									<div class="sscribe-history-actions">
										<a href="<?php echo esc_url( $sscribe_export['url'] ); ?>" class="sscribe-button sscribe-button-icon sscribe-button-sm" download title="<?php esc_attr_e( 'Download this export', 'sscribe-export-site-pages' ); ?>" aria-label="<?php esc_attr_e( 'Download this export', 'sscribe-export-site-pages' ); ?>">
											<img src="<?php echo esc_url( SScribe_Helpers::icon_url( 'download-file' ) ); ?>" width="16" height="16" alt="">
										</a>
										<?php /* translators: %s: export filename */ ?>
										<button type="button" class="sscribe-button sscribe-button-icon sscribe-button-sm sscribe-log-btn" data-filename="<?php echo esc_attr( $sscribe_export['filename'] ); ?>" title="<?php esc_attr_e( 'View export log', 'sscribe-export-site-pages' ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'View export log for %s', 'sscribe-export-site-pages' ), $sscribe_export['filename'] ) ); ?>">
											<img src="<?php echo esc_url( SScribe_Helpers::icon_url( 'file-log' ) ); ?>" width="16" height="16" alt="">
										</button>
										<button type="button" class="sscribe-button sscribe-button-icon sscribe-button-sm sscribe-button-danger sscribe-delete-btn" data-filename="<?php echo esc_attr( $sscribe_export['filename'] ); ?>" title="<?php esc_attr_e( 'Delete this export', 'sscribe-export-site-pages' ); ?>" aria-label="<?php esc_attr_e( 'Delete this export', 'sscribe-export-site-pages' ); ?>">
											<img src="<?php echo esc_url( SScribe_Helpers::icon_url( 'trash' ) ); ?>" width="16" height="16" alt="">
										</button>
									</div>
								</div>
							<?php endforeach; ?>
						<?php else : ?>
							<div class="sscribe-history-empty">
								<em><?php esc_html_e( 'Your recent export packages will appear here.', 'sscribe-export-site-pages' ); ?></em>
							</div>
						<?php endif; ?>
					</div>
				</section>
				<div class="sscribe-callout">
					<div class="sscribe-callout-header">
						<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
						<?php echo SScribe_Helpers::get_icon( 'info', 18 ); ?>
						<strong><?php esc_html_e( 'System Security Tips', 'sscribe-export-site-pages' ); ?></strong>
					</div>
					<div class="sscribe-callout-body">
						<p><?php esc_html_e( 'All exported ZIP archives are automatically purged from your server after 72 hours.', 'sscribe-export-site-pages' ); ?></p>
						<p><?php esc_html_e( 'Data generation happens in batched cycles to ensure reliable conversion without hitting PHP limits.', 'sscribe-export-site-pages' ); ?></p>
					</div>
				</div>
		</div>

		
<div class="sscribe-tab-content" id="sscribe-tab-docs">
	<div class="sscribe-support-master">
		<div class="sscribe-support-sidebar">
			<div class="sscribe-support-header">
				<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'life-buoy', 28 ) ); ?>
				<h2><?php esc_html_e( 'System Diagnostics', 'sscribe-export-site-pages' ); ?></h2>
				<p><?php esc_html_e( 'Generate a redacted environment snapshot. Share this securely with Simplixi support to help us diagnose and resolve issues faster.', 'sscribe-export-site-pages' ); ?></p>
			</div>
			
			<div class="sscribe-support-actions-vertical">
				<button type="button" class="sscribe-button sscribe-button-primary" id="sscribe-support-copy-btn" disabled>
					<?php esc_html_e( 'Copy to Clipboard', 'sscribe-export-site-pages' ); ?>
				</button>
				<button type="button" class="sscribe-button sscribe-button-outline" id="sscribe-support-refresh-btn">
					<?php esc_html_e( 'Refresh Data', 'sscribe-export-site-pages' ); ?>
				</button>
			</div>
			<p class="sscribe-support-feedback sscribe-hidden" id="sscribe-support-feedback" aria-live="polite"></p>
		</div>

		<div class="sscribe-support-main">
			<div class="sscribe-support-terminal" data-support-card>
				<div class="sscribe-terminal-header">
					<span class="sscribe-dot sscribe-dot-red"></span>
					<span class="sscribe-dot sscribe-dot-yellow"></span>
					<span class="sscribe-dot sscribe-dot-green"></span>
					<span class="sscribe-terminal-title">system-report.log</span>
				</div>
				<div class="sscribe-support-copy-wrap">
					<label class="screen-reader-text" for="sscribe-support-copy-text"><?php esc_html_e( 'Support information text', 'sscribe-export-site-pages' ); ?></label>
					<textarea id="sscribe-support-copy-text" class="sscribe-support-copy-text" readonly></textarea>
				</div>
				<div class="sscribe-hidden" id="sscribe-support-grid" aria-live="polite"></div>
			</div>
		</div>
	</div>
</div>

<div id="sscribe-log-modal" class="sscribe-modal sscribe-hidden" role="dialog" aria-modal="true" aria-labelledby="sscribe-log-modal-title" aria-describedby="sscribe-log-modal-desc">
	<div class="sscribe-modal-content" role="document">
		<div class="sscribe-modal-header">
			<h3 id="sscribe-log-modal-title"><?php esc_html_e( 'Export Log', 'sscribe-export-site-pages' ); ?></h3>
			<button type="button" class="sscribe-modal-close" id="sscribe-modal-close" aria-label="<?php echo esc_attr__( 'Close modal', 'sscribe-export-site-pages' ); ?>">
				<span aria-hidden="true">&times;</span>
			</button>
		</div>
		<div class="sscribe-modal-body" id="sscribe-log-content" aria-live="polite">
			<div class="sscribe-log-loading">
				<img src="<?php echo esc_url( SScribe_Helpers::icon_url( 'loader' ) ); ?>" width="24" height="24" alt="" class="sscribe-spinner-img" aria-hidden="true">
				<span><?php esc_html_e( 'Loading log...', 'sscribe-export-site-pages' ); ?></span>
			</div>
		</div>
		<span id="sscribe-log-modal-desc" class="screen-reader-text"><?php esc_html_e( 'Export log details showing processing information for this export', 'sscribe-export-site-pages' ); ?></span>
	</div>
</div>
