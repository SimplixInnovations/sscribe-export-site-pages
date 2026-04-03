<?php
/**
 * Admin page display template — Premium UI with History.
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

	<!-- Skip link for keyboard navigation -->
	<a href="#sscribe-main-content" class="sscribe-skip-link screen-reader-text">
		<?php esc_html_e( 'Skip to main content', 'sscribe-export-site-pages' ); ?>
	</a>

	<!-- Live region for screen reader announcements -->
	<div id="sscribe-live-region" class="screen-reader-text" aria-live="polite" aria-atomic="true"></div>
	
	<!-- Alert region for critical announcements -->
	<div id="sscribe-alert-region" class="screen-reader-text" aria-live="assertive" aria-atomic="true"></div>

	<div class="sscribe-workspace" id="sscribe-main-content" role="main">
		<section class="sscribe-panel sscribe-config-panel">
			<div class="sscribe-panel-header">
				<div class="sscribe-panel-title">
					<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
					<?php echo SScribe_Helpers::get_icon( 'settings', 20, 'sscribe-icon-img' ); ?>
					<h2><?php esc_html_e( 'Select Export Output', 'sscribe-export-site-pages' ); ?></h2>
				</div>
			</div>

			<div class="sscribe-panel-body">
				<?php if ( $sscribe_wpml_active && ! empty( $sscribe_languages ) ) : ?>
					<p class="sscribe-description">
						<?php esc_html_e( 'Choose a language and page status. The plugin will export all matching pages into a professional document archive.', 'sscribe-export-site-pages' ); ?>
					</p>
				<?php else : ?>
					<p class="sscribe-description">
						<?php
						/* translators: %d: Number of pages. */
						echo esc_html( sprintf( __( 'Ready to export %d pages into beautiful documents.', 'sscribe-export-site-pages' ), intval( $sscribe_total_pages_all ) ) );
						?>
					</p>
				<?php endif; ?>

				<!-- Wizard step indicators -->
				<nav class="sscribe-wizard-steps" aria-label="<?php esc_attr_e( 'Export wizard progress', 'sscribe-export-site-pages' ); ?>">
					<span class="sscribe-wizard-step active" data-step="1" aria-current="step">
						<span class="screen-reader-text"><?php esc_html_e( 'Step 1 of 3:', 'sscribe-export-site-pages' ); ?></span> 
						<?php esc_html_e( 'Language', 'sscribe-export-site-pages' ); ?>
					</span>
					<span class="sscribe-wizard-step" data-step="2">
						<span class="screen-reader-text"><?php esc_html_e( 'Step 2 of 3:', 'sscribe-export-site-pages' ); ?></span> 
						<?php esc_html_e( 'Page Status', 'sscribe-export-site-pages' ); ?>
					</span>
					<span class="sscribe-wizard-step" data-step="3">
						<span class="screen-reader-text"><?php esc_html_e( 'Step 3 of 3:', 'sscribe-export-site-pages' ); ?></span> 
						<?php esc_html_e( 'Export Format', 'sscribe-export-site-pages' ); ?>
					</span>
				</nav>

				<!-- Step 1: Language -->
				<div class="sscribe-wizard-panel sscribe-wizard-panel-active" data-step="1">
					<?php if ( $sscribe_wpml_active && ! empty( $sscribe_languages ) ) : ?>
					<fieldset class="sscribe-fieldset">
						<legend class="sscribe-fieldset-legend">
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
							<?php echo SScribe_Helpers::get_icon( 'globe', 16, 'sscribe-legend-icon' ); ?>
							<?php esc_html_e( 'Language', 'sscribe-export-site-pages' ); ?>
						</legend>
						<div class="sscribe-language-cards" id="sscribe-language-cards">
							<label class="sscribe-lang-card-label sscribe-lang-card-all">
								<input type="radio" name="sscribe_language" value="" checked>
								<div class="sscribe-lang-card-inner">
									<div class="sscribe-lang-flag-wrapper">
										<div class="sscribe-lang-flag-placeholder sscribe-lang-flag-all">
											<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
											<?php echo SScribe_Helpers::get_icon( 'globe', 20 ); ?>
										</div>
									</div>
									<div class="sscribe-lang-meta">
										<span class="sscribe-lang-name"><?php esc_html_e( 'All Languages', 'sscribe-export-site-pages' ); ?></span>
										<?php
										/* translators: %d: Number of pages. */
										printf( '<span class="sscribe-lang-count">%s</span>', esc_html( sprintf( __( '%d Pages', 'sscribe-export-site-pages' ), intval( $sscribe_total_pages_all ) ) ) );
										?>
									</div>
									<div class="sscribe-lang-selector">
										<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
										<?php echo SScribe_Helpers::get_icon( 'check', 18, 'sscribe-check-icon' ); ?>
									</div>
								</div>
							</label>
							<?php foreach ( $sscribe_languages as $sscribe_lang ) : ?>
								<label class="sscribe-lang-card-label">
									<input type="radio" name="sscribe_language" value="<?php echo esc_attr( $sscribe_lang['code'] ); ?>">
									<div class="sscribe-lang-card-inner">
										<div class="sscribe-lang-flag-wrapper">
											<?php if ( ! empty( $sscribe_lang['flag_url'] ) ) : ?>
												<img src="<?php echo esc_url( $sscribe_lang['flag_url'] ); ?>" alt="<?php echo esc_attr( $sscribe_lang['name'] ); ?>" class="sscribe-lang-flag">
											<?php else : ?>
												<div class="sscribe-lang-flag-placeholder">
													<?php echo esc_html( strtoupper( substr( $sscribe_lang['code'], 0, 2 ) ) ); ?>
												</div>
											<?php endif; ?>
										</div>
										<div class="sscribe-lang-meta">
											<span class="sscribe-lang-name"><?php echo esc_html( $sscribe_lang['name'] ); ?></span>
											<span class="sscribe-lang-count">
												<?php
												/* translators: %d: Number of pages. */
												echo esc_html( sprintf( __( '%d Pages', 'sscribe-export-site-pages' ), intval( $sscribe_lang['page_count'] ) ) );
												?>
											</span>
										</div>
										<div class="sscribe-lang-selector">
											<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
											<?php echo SScribe_Helpers::get_icon( 'check', 18, 'sscribe-check-icon' ); ?>
										</div>
									</div>
								</label>
							<?php endforeach; ?>
						</div>
					</fieldset>
					<?php else : ?>
					<p class="sscribe-description">
						<?php
						/* translators: %d: Number of pages. */
						echo esc_html( sprintf( __( 'Ready to export %d pages into beautiful documents.', 'sscribe-export-site-pages' ), intval( $sscribe_total_pages_all ) ) );
						?>
					</p>
					<?php endif; ?>
					<div class="sscribe-wizard-nav">
						<span></span>
						<button type="button" class="sscribe-button sscribe-wizard-next" data-next="2"><?php esc_html_e( 'Continue', 'sscribe-export-site-pages' ); ?> &rarr;</button>
					</div>
				</div>

				<!-- Step 2: Status -->
				<div class="sscribe-wizard-panel" data-step="2">
					<fieldset class="sscribe-fieldset">
						<legend class="sscribe-fieldset-legend">
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
							<?php echo SScribe_Helpers::get_icon( 'check-circle', 16, 'sscribe-legend-icon' ); ?>
							<?php esc_html_e( 'Page Status', 'sscribe-export-site-pages' ); ?>
						</legend>
						<div class="sscribe-status-cards" id="sscribe-status-cards">
							<?php
							$sscribe_status_labels = array(
								'publish' => __( 'Published', 'sscribe-export-site-pages' ),
								'draft'   => __( 'Draft', 'sscribe-export-site-pages' ),
								'private' => __( 'Private', 'sscribe-export-site-pages' ),
								'future'  => __( 'Scheduled', 'sscribe-export-site-pages' ),
								'pending' => __( 'Pending', 'sscribe-export-site-pages' ),
								'all'     => __( 'All Statuses', 'sscribe-export-site-pages' ),
							);
							$sscribe_status_icons  = array(
								'publish' => 'check-circle',
								'draft'   => 'file-text',
								'private' => 'warning-circle',
								'future'  => 'clock',
								'pending' => 'clock',
								'all'     => 'list',
							);
							$sscribe_first         = true;
							foreach ( $sscribe_status_labels as $sscribe_status_key => $sscribe_status_label ) :
								$sscribe_count    = isset( $sscribe_status_counts[ $sscribe_status_key ] ) ? intval( $sscribe_status_counts[ $sscribe_status_key ] ) : 0;
								$sscribe_is_zero  = ( 0 === $sscribe_count );
								$sscribe_is_first = $sscribe_first && ! $sscribe_is_zero;
								if ( $sscribe_is_first ) {
									$sscribe_first = false;
								}
								$sscribe_label_class = 'sscribe-status-card-label' . ( $sscribe_is_zero ? ' sscribe-status-disabled' : '' );
								?>
							<label class="<?php echo esc_attr( $sscribe_label_class ); ?>">
								<input type="radio" name="sscribe_post_status" value="<?php echo esc_attr( $sscribe_status_key ); ?>" <?php checked( $sscribe_is_first ); ?><?php echo $sscribe_is_zero ? ' disabled' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute string. ?>>
								<div class="sscribe-status-card-inner">
									<div class="sscribe-status-icon">
										<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
										<?php echo SScribe_Helpers::get_icon( $sscribe_status_icons[ $sscribe_status_key ], 18 ); ?>
									</div>
									<div class="sscribe-status-meta">
										<span class="sscribe-status-name"><?php echo esc_html( $sscribe_status_label ); ?></span>
										<span class="sscribe-status-count" data-status="<?php echo esc_attr( $sscribe_status_key ); ?>"><?php echo esc_html( number_format_i18n( $sscribe_count ) ); ?></span>
									</div>
									<div class="sscribe-status-selector">
										<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
										<?php echo SScribe_Helpers::get_icon( 'check', 16, 'sscribe-check-icon' ); ?>
									</div>
								</div>
							</label>
							<?php endforeach; ?>
						</div>
					</fieldset>
					<div class="sscribe-wizard-nav">
						<button type="button" class="sscribe-button sscribe-wizard-back" data-prev="1">&larr; <?php esc_html_e( 'Back', 'sscribe-export-site-pages' ); ?></button>
						<button type="button" class="sscribe-button sscribe-wizard-next" data-next="3"><?php esc_html_e( 'Continue', 'sscribe-export-site-pages' ); ?> &rarr;</button>
					</div>
				</div>

				<!-- Step 3: Format -->
				<div class="sscribe-wizard-panel" data-step="3">
					<fieldset class="sscribe-fieldset">
						<legend class="sscribe-fieldset-legend">
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
							<?php echo SScribe_Helpers::get_icon( 'file-text', 16, 'sscribe-legend-icon' ); ?>
							<?php esc_html_e( 'Export Format', 'sscribe-export-site-pages' ); ?>
						</legend>
						<div class="sscribe-format-cards" id="sscribe-format-cards">
							<label class="sscribe-format-card-label sscribe-format-all">
								<input type="radio" name="sscribe_format" value="all" checked>
								<div class="sscribe-format-card-inner">
									<div class="sscribe-format-icon">
										<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
										<?php echo SScribe_Helpers::get_icon( 'download-package', 24 ); ?>
									</div>
									<div class="sscribe-format-meta">
										<span class="sscribe-format-name"><?php esc_html_e( 'All Formats', 'sscribe-export-site-pages' ); ?></span>
										<span class="sscribe-format-desc"><?php esc_html_e( 'DOCX, PDF, HTML, Markdown', 'sscribe-export-site-pages' ); ?></span>
									</div>
									<div class="sscribe-format-selector">
										<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
										<?php echo SScribe_Helpers::get_icon( 'check', 16, 'sscribe-check-icon' ); ?>
									</div>
								</div>
							</label>
							<?php
							$sscribe_formats = array(
								'docx'     => array(
									'label' => __( 'Word Document (DOCX)', 'sscribe-export-site-pages' ),
									'icon'  => 'file-doc',
									'desc'  => __( '~1.2 seconds per page', 'sscribe-export-site-pages' ),
								),
								'pdf'      => array(
									'label' => __( 'PDF Document', 'sscribe-export-site-pages' ),
									'icon'  => 'file-pdf',
									'desc'  => __( '~8 seconds per page', 'sscribe-export-site-pages' ),
								),
								'html'     => array(
									'label' => __( 'HTML Page', 'sscribe-export-site-pages' ),
									'icon'  => 'file-html',
									'desc'  => __( '~1 second per page', 'sscribe-export-site-pages' ),
								),
								'markdown' => array(
									'label' => __( 'Markdown', 'sscribe-export-site-pages' ),
									'icon'  => 'file-md',
									'desc'  => __( '~0.5 seconds per page', 'sscribe-export-site-pages' ),
								),
							);
							foreach ( $sscribe_formats as $sscribe_format_key => $sscribe_format_data ) :
								?>
							<label class="sscribe-format-card-label">
								<input type="radio" name="sscribe_format" value="<?php echo esc_attr( $sscribe_format_key ); ?>">
								<div class="sscribe-format-card-inner">
									<div class="sscribe-format-icon">
										<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
										<?php echo SScribe_Helpers::get_icon( $sscribe_format_data['icon'], 24 ); ?>
									</div>
									<div class="sscribe-format-meta">
										<span class="sscribe-format-name"><?php echo esc_html( $sscribe_format_data['label'] ); ?></span>
										<span class="sscribe-format-desc"><?php echo esc_html( $sscribe_format_data['desc'] ); ?></span>
									</div>
									<div class="sscribe-format-selector">
										<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
										<?php echo SScribe_Helpers::get_icon( 'check', 16, 'sscribe-check-icon' ); ?>
									</div>
								</div>
							</label>
							<?php endforeach; ?>
						</div>
					</fieldset>
					<div class="sscribe-wizard-nav">
						<button type="button" class="sscribe-button sscribe-wizard-back" data-prev="2">&larr; <?php esc_html_e( 'Back', 'sscribe-export-site-pages' ); ?></button>
						<div class="sscribe-wizard-generate-wrap">
							<div id="sscribe-time-estimate" class="sscribe-time-estimate" aria-live="polite">
								<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
								<?php echo SScribe_Helpers::get_icon( 'clock', 14 ); ?>
								<span id="sscribe-time-estimate-text"><?php esc_html_e( 'Select options to see estimated time', 'sscribe-export-site-pages' ); ?></span>
							</div>
							<button type="button" id="sscribe-preview-btn" class="sscribe-button sscribe-button-outline" disabled aria-describedby="sscribe-preview-btn-hint">
								<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
								<?php echo SScribe_Helpers::get_icon( 'eye', 18 ); ?>
								<span><?php esc_html_e( 'Preview', 'sscribe-export-site-pages' ); ?></span>
							</button>
							<span id="sscribe-preview-btn-hint" class="screen-reader-text"><?php esc_html_e( 'Preview what your export will look like before generating', 'sscribe-export-site-pages' ); ?></span>
							<button type="button" id="sscribe-export-btn" class="sscribe-button sscribe-button-primary" disabled aria-describedby="sscribe-export-btn-hint">
								<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
								<?php echo SScribe_Helpers::get_icon( 'download-package', 20 ); ?>
								<span id="sscribe-export-btn-text"><?php esc_html_e( 'Generate Documentation Package', 'sscribe-export-site-pages' ); ?></span>
							</button>
							<span id="sscribe-export-btn-hint" class="screen-reader-text"><?php esc_html_e( 'Start the export process for selected pages and format', 'sscribe-export-site-pages' ); ?></span>
						</div>
					</div>

					<!-- Export Preview Panel -->
					<div id="sscribe-preview-panel" class="sscribe-preview-panel sscribe-hidden" role="region" aria-labelledby="sscribe-preview-title">
						<div class="sscribe-preview-header">
							<h4 id="sscribe-preview-title">
								<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
								<?php echo SScribe_Helpers::get_icon( 'eye', 16 ); ?>
								<?php esc_html_e( 'Export Preview', 'sscribe-export-site-pages' ); ?>
							</h4>
							<button type="button" id="sscribe-preview-close" class="sscribe-preview-close" aria-label="<?php esc_attr_e( 'Close preview', 'sscribe-export-site-pages' ); ?>">&times;</button>
						</div>
						<div id="sscribe-preview-content" class="sscribe-preview-content">
							<div class="sscribe-preview-loading">
								<span class="sscribe-loading-spinner"></span>
								<span><?php esc_html_e( 'Generating preview...', 'sscribe-export-site-pages' ); ?></span>
							</div>
						</div>
					</div>
				</div>

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
							<div class="sscribe-progress-bar-container" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-labelledby="sscribe-progress-text">
								<div id="sscribe-progress-bar" class="sscribe-progress-bar-fill"></div>
							</div>
							<span id="sscribe-progress-text" class="sscribe-progress-percentage">0%</span>
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

				<div id="sscribe-download-area" class="sscribe-status-alert sscribe-status-success ssscribe-hidden" role="status" aria-live="polite">
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

				<div id="sscribe-error-area" class="sscribe-status-alert sscribe-status-error ssscribe-hidden" role="alert" aria-live="assertive">
					<div class="sscribe-status-icon" aria-hidden="true">
						<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
						<?php echo SScribe_Helpers::get_icon( 'warning-circle', 32 ); ?>
					</div>
					<div class="sscribe-status-info">
						<h4 class="sscribe-status-heading">
							<?php esc_html_e( 'Export Failed', 'sscribe-export-site-pages' ); ?>
						</h4>
						<p id="sscribe-error-text" class="sscribe-status-desc"></p>
						<div id="sscribe-error-guidance" class="sscribe-error-guidance ssscribe-hidden">
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
		</section>

		<div class="sscribe-grid-layout">
			<div class="sscribe-grid-main">
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
												<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $sscribe_export['time'] ) ); ?>
												&mdash; <?php echo esc_html( size_format( $sscribe_export['size'] ) ); ?>
											</span>
										</div>
									</div>
									<div class="sscribe-history-actions">
										<a href="<?php echo esc_url( $sscribe_export['url'] ); ?>" class="sscribe-button sscribe-button-outline sscribe-button-sm" download>
											<img src="<?php echo esc_url( SScribe_Helpers::icon_url( 'download-file' ) ); ?>" width="14" height="14" alt="">
											<?php esc_html_e( 'Download', 'sscribe-export-site-pages' ); ?>
										</a>
										<button type="button" class="sscribe-button sscribe-button-outline sscribe-button-sm sscribe-log-btn" data-filename="<?php echo esc_attr( $sscribe_export['filename'] ); ?>">
											<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
											<?php echo SScribe_Helpers::get_icon( 'file-log', 14 ); ?>
											<?php esc_html_e( 'Log', 'sscribe-export-site-pages' ); ?>
										</button>
										<button type="button" class="sscribe-button sscribe-button-outline sscribe-button-sm sscribe-delete-btn" data-filename="<?php echo esc_attr( $sscribe_export['filename'] ); ?>">
											<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
											<?php echo SScribe_Helpers::get_icon( 'trash', 14, 'sscribe-delete-icon' ); ?>
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

				<section class="sscribe-panel">
					<div class="sscribe-panel-header">
						<div class="sscribe-panel-title">
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
							<?php echo SScribe_Helpers::get_icon( 'list-checks', 20, 'sscribe-icon-img' ); ?>
							<h2><?php esc_html_e( 'What Each Document Includes', 'sscribe-export-site-pages' ); ?></h2>
						</div>
					</div>
					<div class="sscribe-panel-body">
						<div class="sscribe-features-grid">
							<?php
							$sscribe_features = array(
								array(
									'title'   => __( 'Page Title', 'sscribe-export-site-pages' ),
									'desc'    => __( 'Main H1 heading with proper styling', 'sscribe-export-site-pages' ),
									'icon'    => 'file-text',
									'feature' => 'page-title',
								),
								array(
									'title'   => __( 'Page Content', 'sscribe-export-site-pages' ),
									'desc'    => __( 'Full HTML content converted to documents', 'sscribe-export-site-pages' ),
									'icon'    => 'file-doc',
									'feature' => 'page-content',
								),
								array(
									'title'   => __( 'SEO Metadata', 'sscribe-export-site-pages' ),
									'desc'    => __( 'Meta title, description, focus keyword', 'sscribe-export-site-pages' ),
									'icon'    => 'search',
									'feature' => 'seo-metadata',
								),
								array(
									'title'   => __( 'URL & Permalink', 'sscribe-export-site-pages' ),
									'desc'    => __( 'Full page URL for reference', 'sscribe-export-site-pages' ),
									'icon'    => 'link',
									'feature' => 'url-permalink',
								),
								array(
									'title'   => __( 'Author Info', 'sscribe-export-site-pages' ),
									'desc'    => __( 'Page author name', 'sscribe-export-site-pages' ),
									'icon'    => 'user',
									'feature' => 'author-info',
								),
								array(
									'title'   => __( 'Dates', 'sscribe-export-site-pages' ),
									'desc'    => __( 'Published and modified dates', 'sscribe-export-site-pages' ),
									'icon'    => 'calendar',
									'feature' => 'dates',
								),
								array(
									'title'   => __( 'Parent Page', 'sscribe-export-site-pages' ),
									'desc'    => __( 'Breadcrumb hierarchy', 'sscribe-export-site-pages' ),
									'icon'    => 'list',
									'feature' => 'parent-page',
								),
								array(
									'title'   => __( 'Featured Image', 'sscribe-export-site-pages' ),
									'desc'    => __( 'Thumbnail when available', 'sscribe-export-site-pages' ),
									'icon'    => 'image',
									'feature' => 'featured-image',
								),
							);

							foreach ( $sscribe_features as $sscribe_feature ) :
								?>
							<div class="sscribe-feature-item">
								<div class="sscribe-feature-icon" data-feature="<?php echo esc_attr( $sscribe_feature['feature'] ); ?>">
									<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
									<?php echo SScribe_Helpers::get_icon( $sscribe_feature['icon'], 20 ); ?>
								</div>
								<div class="sscribe-feature-text">
									<h4><?php echo esc_html( $sscribe_feature['title'] ); ?></h4>
									<p><?php echo esc_html( $sscribe_feature['desc'] ); ?></p>
								</div>
							</div>
							<?php endforeach; ?>
						</div>
					</div>
				</section>
			</div>

			<div class="sscribe-grid-sidebar">
				<section class="sscribe-panel">
					<div class="sscribe-panel-header">
						<div class="sscribe-panel-title">
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
							<?php echo SScribe_Helpers::get_icon( 'file-doc', 18, 'sscribe-icon-img' ); ?>
							<h3><?php esc_html_e( 'Document Format', 'sscribe-export-site-pages' ); ?></h3>
						</div>
					</div>
					<div class="sscribe-panel-body sscribe-p-md">
						<ul class="sscribe-check-list">
							<li><?php esc_html_e( 'Standard Arial Typography', 'sscribe-export-site-pages' ); ?></li>
							<li><?php esc_html_e( 'Letter (8.5 x 11 in) Layout', 'sscribe-export-site-pages' ); ?></li>
							<li><?php esc_html_e( '1-Inch Margins', 'sscribe-export-site-pages' ); ?></li>
							<li><?php esc_html_e( 'Strict H1-H6 Hierarchies', 'sscribe-export-site-pages' ); ?></li>
							<li><?php esc_html_e( 'Auto-Numerated Pages', 'sscribe-export-site-pages' ); ?></li>
							<li><?php esc_html_e( 'Verified Word Compatibility', 'sscribe-export-site-pages' ); ?></li>
						</ul>
					</div>
				</section>

				<section class="sscribe-panel">
					<div class="sscribe-panel-header">
						<div class="sscribe-panel-title">
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
							<?php echo SScribe_Helpers::get_icon( 'search', 18, 'sscribe-icon-img' ); ?>
							<h3><?php esc_html_e( 'SEO Support Matrix', 'sscribe-export-site-pages' ); ?></h3>
						</div>
					</div>
					<div class="sscribe-panel-body sscribe-p-md">
						<ul class="sscribe-check-list">
							<li><?php esc_html_e( 'Yoast SEO Premium & Free', 'sscribe-export-site-pages' ); ?></li>
							<li><?php esc_html_e( 'Rank Math Pro & Free', 'sscribe-export-site-pages' ); ?></li>
							<li><?php esc_html_e( 'All in One SEO (AIOSEO)', 'sscribe-export-site-pages' ); ?></li>
							<li><?php esc_html_e( 'SEOPress', 'sscribe-export-site-pages' ); ?></li>
							<li><?php esc_html_e( 'The SEO Framework', 'sscribe-export-site-pages' ); ?></li>
						</ul>
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
		</div>
	</div>
</div>

<div id="sscribe-log-modal" class="sscribe-modal ssscribe-hidden" role="dialog" aria-modal="true" aria-labelledby="sscribe-log-modal-title" aria-describedby="sscribe-log-modal-desc">
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
