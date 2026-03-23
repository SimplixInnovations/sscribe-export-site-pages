<?php
/**
 * Admin page display template — Premium UI with History.
 *
 * @package SScribe
 *
 * @var bool   $wpml_active    Whether WPML is active.
 * @var array  $languages      WPML languages array (enriched with page_count).
 * @var int    $total_pages_all Total pages across all languages.
 * @var array  $status_counts  Page counts per post status.
 * @var array  $seo_plugins    Active SEO plugins.
 * @var array  $recent_exports Array of recent ZIP exports.
 */

// Prevent direct access.
if (!defined('ABSPATH')) {
	exit;
}
?>

<div class="sscribe-master-container">
	<header class="sscribe-hero">
		<div class="sscribe-hero-glass"></div>
		<div class="sscribe-hero-content">
			<div class="sscribe-hero-left">
				<div class="sscribe-hero-logo">
					<svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M16 3L28 9V23L16 29L4 23V9L16 3Z" fill="#2DD4BF" fill-opacity="0.2" />
						<path d="M10 20C10 20 11.5 22 16 22C20.5 22 22 20 22 18C22 14 10 16 10 12C10 10 12 8 16 8C20 8 22 10 22 10" stroke="#2DD4BF" stroke-width="2.5" stroke-linecap="round" />
					</svg>
				</div>
				<div class="sscribe-hero-titles">
					<h1 class="sscribe-hero-title">SScribe</h1>
					<p class="sscribe-hero-subtitle">
						<?php esc_html_e( 'Export every page into beautifully formatted Word DOCX files with multilingual support, SEO meta, rich styling, and secure ZIP download.', 'sscribe-export-site-pages' ); ?>
					</p>
				</div>
			</div>
			<p class="sscribe-hero-version">v<?php echo esc_html( SSCRIBE_VERSION ); ?></p>
		</div>
	</header>

	<!-- ===== Main Workspace ===== -->
	<div class="sscribe-workspace">

		<!-- Top Section: Language Selection & Actions -->
		<section class="sscribe-panel sscribe-config-panel">
			<div class="sscribe-panel-header">
				<div class="sscribe-panel-title">
					<svg class="sscribe-icon" width="20" height="20" viewBox="0 0 24 24" fill="none"
						stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<circle cx="12" cy="12" r="10" />
						<line x1="2" y1="12" x2="22" y2="12" />
						<path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
					</svg>
					<h2><?php esc_html_e('Select Export Output', 'sscribe-export-site-pages'); ?></h2>
				</div>
			</div>

			<div class="sscribe-panel-body">
				<?php if ($wpml_active && !empty($languages)): ?>
					<p class="sscribe-description">
						<?php esc_html_e('Choose a language and post status. The plugin will export all matching pages into a professional DOCX archive.', 'sscribe-export-site-pages'); ?>
					</p>

					<!-- Language Selection -->
					<fieldset class="sscribe-fieldset">
						<legend class="sscribe-fieldset-legend">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<circle cx="12" cy="12" r="10"/>
								<line x1="2" y1="12" x2="22" y2="12"/>
								<path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
							</svg>
							<?php esc_html_e('Language', 'sscribe-export-site-pages'); ?>
						</legend>
						<div class="sscribe-language-cards">
							<label class="sscribe-lang-card-label sscribe-lang-card-all">
								<input type="radio" name="sscribe_language" value="" checked>
								<div class="sscribe-lang-card-inner">
									<div class="sscribe-lang-flag-wrapper">
										<div class="sscribe-lang-flag-placeholder sscribe-lang-flag-all">
											<svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none">
												<circle cx="12" cy="12" r="10"/>
												<line x1="2" y1="12" x2="22" y2="12"/>
												<path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
											</svg>
										</div>
									</div>
									<div class="sscribe-lang-meta">
										<span class="sscribe-lang-name"><?php esc_html_e('All Languages', 'sscribe-export-site-pages'); ?></span>
										<?php
										/* translators: %d: Number of pages */
										printf('<span class="sscribe-lang-count">%s</span>', esc_html(sprintf(__('%d Pages', 'sscribe-export-site-pages'), intval($total_pages_all))));
										?>
									</div>
									<div class="sscribe-lang-selector">
										<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2.5"
											fill="none" stroke-linecap="round" stroke-linejoin="round"
											class="sscribe-check-icon">
											<polyline points="20 6 9 17 4 12"></polyline>
										</svg>
									</div>
								</div>
							</label>
							<?php foreach ($languages as $sscribe_lang): ?>
								<label class="sscribe-lang-card-label">
									<input type="radio" name="sscribe_language" value="<?php echo esc_attr($sscribe_lang['code']); ?>">
									<div class="sscribe-lang-card-inner">
										<div class="sscribe-lang-flag-wrapper">
											<?php if (!empty($sscribe_lang['flag_url'])): ?>
												<img src="<?php echo esc_url($sscribe_lang['flag_url']); ?>" alt="<?php echo esc_attr($sscribe_lang['name']); ?>" class="sscribe-lang-flag">
											<?php else: ?>
												<div class="sscribe-lang-flag-placeholder">
													<?php echo esc_html(strtoupper(substr($sscribe_lang['code'], 0, 2))); ?>
												</div>
											<?php endif; ?>
										</div>
										<div class="sscribe-lang-meta">
											<span class="sscribe-lang-name"><?php echo esc_html( $sscribe_lang['name'] ); ?></span>
											<span class="sscribe-lang-count">
												<?php
												/* translators: %d: Number of pages */
												printf(esc_html__('%d Pages', 'sscribe-export-site-pages'), intval($sscribe_lang['page_count']));
												?>
											</span>
										</div>
										<div class="sscribe-lang-selector">
											<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2.5"
												fill="none" stroke-linecap="round" stroke-linejoin="round"
												class="sscribe-check-icon">
												<polyline points="20 6 9 17 4 12"></polyline>
											</svg>
										</div>
									</div>
								</label>
							<?php endforeach; ?>
						</div>
					</fieldset>

							<?php else: ?>
					<p class="sscribe-description">
						<?php
						/* translators: %d: Number of pages */
						printf(esc_html__('Ready to export %d pages into beautiful Word documents.', 'sscribe-export-site-pages'), intval($total_pages_all));
						?>
					</p>
				<?php endif; ?>

				<!-- Post Status Selection -->
				<fieldset class="sscribe-fieldset">
					<legend class="sscribe-fieldset-legend">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
							<polyline points="22 4 12 14.01 9 11.01"/>
						</svg>
						<?php esc_html_e('Page Status', 'sscribe-export-site-pages'); ?>
					</legend>
					<div class="sscribe-status-cards" id="sscribe-status-cards">
						<?php
						$sscribe_status_labels = array(
							'publish' => __('Published', 'sscribe-export-site-pages'),
							'draft'   => __('Draft', 'sscribe-export-site-pages'),
							'private' => __('Private', 'sscribe-export-site-pages'),
							'future'  => __('Scheduled', 'sscribe-export-site-pages'),
							'pending' => __('Pending', 'sscribe-export-site-pages'),
							'all'     => __('All Statuses', 'sscribe-export-site-pages'),
						);
						$sscribe_status_icons = array(
							'publish' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
							'draft'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
							'private' => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
							'future'  => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
							'pending' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/><line x1="8" y1="2" x2="8" y2="6"/>',
							'all'     => '<circle cx="12" cy="12" r="10"/><path d="M8 12h8"/>',
						);
						$sscribe_first = true;
						$sscribe_svg_allowed = array(
							'path'     => array( 'd' => true ),
							'polyline' => array( 'points' => true ),
							'circle'   => array( 'cx' => true, 'cy' => true, 'r' => true ),
							'rect'     => array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true ),
							'line'     => array( 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true ),
						);
						foreach ($sscribe_status_labels as $sscribe_status_key => $sscribe_status_label):
							$sscribe_count    = isset($status_counts[$sscribe_status_key]) ? intval($status_counts[$sscribe_status_key]) : 0;
							$sscribe_is_zero  = ( $sscribe_count === 0 );
							$sscribe_is_first = $sscribe_first && ! $sscribe_is_zero;
							if ( $sscribe_is_first ) { $sscribe_first = false; }
						?>
						<label class="sscribe-status-card-label<?php echo $sscribe_is_zero ? ' sscribe-status-disabled' : ''; ?>">
							<input type="radio" name="sscribe_post_status" value="<?php echo esc_attr($sscribe_status_key); ?>" <?php checked($sscribe_is_first); ?><?php echo $sscribe_is_zero ? ' disabled' : ''; ?>>
							<div class="sscribe-status-card-inner">
								<div class="sscribe-status-icon">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
										<?php echo wp_kses( $sscribe_status_icons[ $sscribe_status_key ], $sscribe_svg_allowed ); ?>
									</svg>
								</div>
								<div class="sscribe-status-meta">
									<span class="sscribe-status-name"><?php echo esc_html($sscribe_status_label); ?></span>
									<span class="sscribe-status-count" data-status="<?php echo esc_attr($sscribe_status_key); ?>"><?php echo esc_html( number_format_i18n( $sscribe_count ) ); ?></span>
								</div>
								<div class="sscribe-status-selector">
									<svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2.5"
										fill="none" stroke-linecap="round" stroke-linejoin="round"
										class="sscribe-check-icon">
										<polyline points="20 6 9 17 4 12"></polyline>
									</svg>
								</div>
							</div>
						</label>
						<?php endforeach; ?>
					</div>
				</fieldset>

				<div class="sscribe-action-row">
					<button type="button" id="sscribe-export-btn" class="sscribe-button sscribe-button-primary">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
							stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
							<polyline points="7 10 12 15 17 10"></polyline>
							<line x1="12" y1="15" x2="12" y2="3"></line>
						</svg>
						<?php esc_html_e('Generate Documentation Package', 'sscribe-export-site-pages'); ?>
					</button>
				</div>

				<!-- Live Progress Display -->
				<div id="sscribe-progress-area" class="sscribe-status-alert sscribe-status-processing sscribe-hidden">
					<div class="sscribe-spinner">
						<svg width="40" height="40" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
							<circle cx="50" cy="50" r="40" stroke="#E5E7EB" stroke-width="8" fill="none" />
							<path d="M50 10 a 40 40 0 0 1 40 40" stroke="#4A8263" stroke-width="8" fill="none"
								stroke-linecap="round">
								<animateTransform attributeName="transform" type="rotate" from="0 50 50" to="360 50 50"
									dur="1s" repeatCount="indefinite" />
							</path>
						</svg>
					</div>
					<div class="sscribe-status-info">
						<h4 id="sscribe-status-text" class="sscribe-status-heading">
							<?php esc_html_e('Connecting & fetching pages...', 'sscribe-export-site-pages'); ?>
						</h4>
						<p id="sscribe-current-page" class="sscribe-current-page"></p>
						<div class="sscribe-progress-tracker">
							<div class="sscribe-progress-bar-container">
								<div id="sscribe-progress-bar" class="sscribe-progress-bar-fill"></div>
							</div>
							<span id="sscribe-progress-text" class="sscribe-progress-percentage">0%</span>
						</div>
						<div class="sscribe-progress-meta">
							<span id="sscribe-time-remaining" class="sscribe-time-remaining"></span>
						</div>
						<button type="button" id="sscribe-cancel-btn" class="sscribe-button sscribe-button-cancel">
							<?php esc_html_e('Cancel Export', 'sscribe-export-site-pages'); ?>
						</button>
					</div>
				</div>

				<!-- Success Download Display -->
				<div id="sscribe-download-area" class="sscribe-status-alert sscribe-status-success sscribe-hidden">
					<div class="sscribe-status-icon">
						<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor"
							stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
							<polyline points="22 4 12 14.01 9 11.01" />
						</svg>
					</div>
					<div class="sscribe-status-info">
						<h4 class="sscribe-status-heading">
							<?php esc_html_e('Export Completed Successfully', 'sscribe-export-site-pages'); ?>
						</h4>
						<p class="sscribe-status-desc">
							<?php esc_html_e('All selected pages have been packaged into a ZIP archive containing individual DOCX files.', 'sscribe-export-site-pages'); ?>
						</p>
						<div class="sscribe-success-actions">
							<a id="sscribe-download-btn" href="#" class="sscribe-button sscribe-button-success" download>
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
									stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
									<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
									<polyline points="7 10 12 15 17 10" />
									<line x1="12" y1="15" x2="12" y2="3" />
								</svg>
								<?php esc_html_e('Download ZIP File', 'sscribe-export-site-pages'); ?>
							</a>
							<button type="button" id="sscribe-retry-btn" class="sscribe-button sscribe-button-ghost">
								<?php esc_html_e('Start New Export', 'sscribe-export-site-pages'); ?>
							</button>
						</div>
					</div>
				</div>

				<!-- Error Display -->
				<div id="sscribe-error-area" class="sscribe-status-alert sscribe-status-error sscribe-hidden">
					<div class="sscribe-status-icon">
						<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor"
							stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<circle cx="12" cy="12" r="10" />
							<line x1="15" y1="9" x2="9" y2="15" />
							<line x1="9" y1="9" x2="15" y2="15" />
						</svg>
					</div>
					<div class="sscribe-status-info">
						<h4 class="sscribe-status-heading">
							<?php esc_html_e('Export Failed', 'sscribe-export-site-pages'); ?>
						</h4>
						<p id="sscribe-error-text" class="sscribe-status-desc"></p>
						<div class="sscribe-error-actions">
							<button type="button" id="sscribe-error-try-again" class="sscribe-button sscribe-button-secondary">
								<?php esc_html_e('Try Again', 'sscribe-export-site-pages'); ?>
							</button>
						</div>
					</div>
				</div>
			</div>
		</section>

		<!-- Grid Layout: Left Columns (History + Features), Right Content (Sidebar) -->
		<div class="sscribe-grid-layout">

			<div class="sscribe-grid-main">

				<!-- History Section -->
				<section class="sscribe-panel">
					<div class="sscribe-panel-header">
						<div class="sscribe-panel-title">
							<svg class="sscribe-icon" width="20" height="20" viewBox="0 0 24 24" fill="none"
								stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<circle cx="12" cy="12" r="10" />
								<polyline points="12 6 12 12 16 14" />
							</svg>
							<h2><?php esc_html_e('Recent Exports', 'sscribe-export-site-pages'); ?></h2>
						</div>
						<span class="sscribe-badge"><?php esc_html_e('Auto-deletes in 1 hour', 'sscribe-export-site-pages'); ?></span>
					</div>
					<div class="sscribe-history-table">
						<?php if (!empty($recent_exports)): ?>
							<?php foreach ($recent_exports as $sscribe_export): ?>
								<div class="sscribe-history-row">
									<div class="sscribe-history-file">
										<div class="sscribe-file-icon">
											<?php if (!empty($sscribe_export['flag_url'])): ?>
												<img src="<?php echo esc_url($sscribe_export['flag_url']); ?>" alt="<?php echo esc_attr($sscribe_export['lang_name']); ?> flag" class="sscribe-file-icon-img">
											<?php else: ?>
												<span class="sscribe-file-icon-text"><?php echo esc_html(strtoupper(substr($sscribe_export['lang_code'], 0, 2))); ?></span>
											<?php endif; ?>
										</div>
										<div class="sscribe-file-details">
											<strong><?php echo esc_html($sscribe_export['filename']); ?></strong>
											<span>
												<?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $sscribe_export['time'])); ?>
												&mdash; <?php echo esc_html(size_format($sscribe_export['size'])); ?>
											</span>
										</div>
									</div>
									<a href="<?php echo esc_url($sscribe_export['url']); ?>"
										class="sscribe-button sscribe-button-outline sscribe-button-sm" download>
										<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
											stroke-width="2">
											<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
											<polyline points="7 10 12 15 17 10" />
											<line x1="12" y1="15" x2="12" y2="3" />
										</svg>
										<?php esc_html_e('Download', 'sscribe-export-site-pages'); ?>
									</a>
								</div>
							<?php endforeach; ?>
						<?php else: ?>
							<div class="sscribe-history-empty">
								<em><?php esc_html_e('Your recent export packages will appear here.', 'sscribe-export-site-pages'); ?></em>
							</div>
						<?php endif; ?>
					</div>
				</section>

				<!-- Features Section -->
				<section class="sscribe-panel">
					<div class="sscribe-panel-header">
						<div class="sscribe-panel-title">
							<svg class="sscribe-icon" width="20" height="20" viewBox="0 0 24 24" fill="none"
								stroke="currentColor" stroke-width="2">
								<line x1="8" y1="6" x2="21" y2="6"></line>
								<line x1="8" y1="12" x2="21" y2="12"></line>
								<line x1="8" y1="18" x2="21" y2="18"></line>
								<line x1="3" y1="6" x2="3.01" y2="6"></line>
								<line x1="3" y1="12" x2="3.01" y2="12"></line>
								<line x1="3" y1="18" x2="3.01" y2="18"></line>
							</svg>
							<h2><?php esc_html_e('What Each Document Includes', 'sscribe-export-site-pages'); ?></h2>
						</div>
					</div>
					<div class="sscribe-panel-body">
						<div class="sscribe-features-grid">
							<?php
							$sscribe_features = array(
								array(
									'title' => __('Page Title', 'sscribe-export-site-pages'),
									'desc' => __('Main H1 heading with proper styling', 'sscribe-export-site-pages'),
									'icon' => '<path d="M4 7V4h16v3M9 20h6M12 4v16"/>',
									'color' => '#2563EB',
									'bg' => '#DBEAFE',
								),
								array(
									'title' => __('Page Content', 'sscribe-export-site-pages'),
									'desc' => __('Full HTML content converted to Word', 'sscribe-export-site-pages'),
									'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
									'color' => '#059669',
									'bg' => '#D1FAE5',
								),
								array(
									'title' => __('SEO Metadata', 'sscribe-export-site-pages'),
									'desc' => __('Meta title, description, focus keyword', 'sscribe-export-site-pages'),
									'icon' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
									'color' => '#7C3AED',
									'bg' => '#EDE9FE',
								),
								array(
									'title' => __('URL & Permalink', 'sscribe-export-site-pages'),
									'desc' => __('Full page URL for reference', 'sscribe-export-site-pages'),
									'icon' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
									'color' => '#0891B2',
									'bg' => '#CFFAFE',
								),
								array(
									'title' => __('Author Info', 'sscribe-export-site-pages'),
									'desc' => __('Page author name', 'sscribe-export-site-pages'),
									'icon' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
									'color' => '#DC2626',
									'bg' => '#FEE2E2',
								),
								array(
									'title' => __('Dates', 'sscribe-export-site-pages'),
									'desc' => __('Published and modified dates', 'sscribe-export-site-pages'),
									'icon' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
									'color' => '#D97706',
									'bg' => '#FEF3C7',
								),
								array(
									'title' => __('Parent Page', 'sscribe-export-site-pages'),
									'desc' => __('Breadcrumb hierarchy', 'sscribe-export-site-pages'),
									'icon' => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
									'color' => '#0EA5E9',
									'bg' => '#E0F2FE',
								),
								array(
									'title' => __('Featured Image', 'sscribe-export-site-pages'),
									'desc' => __('Thumbnail when available', 'sscribe-export-site-pages'),
									'icon' => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
									'color' => '#EC4899',
									'bg' => '#FCE7F3',
								),
							);

						$sscribe_svg_allowed_features = array(
							'path'     => array( 'd' => true ),
							'polyline' => array( 'points' => true ),
							'circle'   => array( 'cx' => true, 'cy' => true, 'r' => true ),
							'rect'     => array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true ),
							'line'     => array( 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true ),
						);

						foreach ($sscribe_features as $sscribe_feature):
							?>
							<div class="sscribe-feature-item">
								<div class="sscribe-feature-icon" style="background: <?php echo esc_attr($sscribe_feature['bg']); ?>; color: <?php echo esc_attr($sscribe_feature['color']); ?>;">
									<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
										stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
										<?php echo wp_kses( $sscribe_feature['icon'], $sscribe_svg_allowed_features ); ?>
									</svg>
								</div>
								<div class="sscribe-feature-text">
									<h4><?php echo esc_html($sscribe_feature['title']); ?></h4>
									<p><?php echo esc_html($sscribe_feature['desc']); ?></p>
								</div>
							</div>
							<?php endforeach; ?>
						</div>
					</div>
				</section>
			</div>

			<!-- Right Sidebar -->
			<div class="sscribe-grid-sidebar">

				<section class="sscribe-panel">
					<div class="sscribe-panel-header">
						<div class="sscribe-panel-title">
							<svg class="sscribe-icon" width="18" height="18" viewBox="0 0 24 24" fill="none"
								stroke="currentColor" stroke-width="2">
								<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
								<polyline points="14 2 14 8 20 8" />
								<line x1="16" y1="13" x2="8" y2="13" />
								<line x1="16" y1="17" x2="8" y2="17" />
							</svg>
							<h3><?php esc_html_e('Document Format', 'sscribe-export-site-pages'); ?></h3>
						</div>
					</div>
					<div class="sscribe-panel-body sscribe-p-md">
						<ul class="sscribe-check-list">
							<li><?php esc_html_e('Standard Arial Typography', 'sscribe-export-site-pages'); ?></li>
							<li><?php esc_html_e('Letter (8.5 x 11 in) Layout', 'sscribe-export-site-pages'); ?></li>
							<li><?php esc_html_e('1-Inch Margins', 'sscribe-export-site-pages'); ?></li>
							<li><?php esc_html_e('Strict H1-H6 Hierarchies', 'sscribe-export-site-pages'); ?></li>
							<li><?php esc_html_e('Auto-Numerated Pages', 'sscribe-export-site-pages'); ?></li>
							<li><?php esc_html_e('Verified Word Compatibility', 'sscribe-export-site-pages'); ?></li>
						</ul>
					</div>
				</section>

				<section class="sscribe-panel">
					<div class="sscribe-panel-header">
						<div class="sscribe-panel-title">
							<svg class="sscribe-icon" width="18" height="18" viewBox="0 0 24 24" fill="none"
								stroke="currentColor" stroke-width="2">
								<circle cx="11" cy="11" r="8" />
								<line x1="21" y1="21" x2="16.65" y2="16.65" />
							</svg>
							<h3><?php esc_html_e('SEO Support Matrix', 'sscribe-export-site-pages'); ?></h3>
						</div>
					</div>
					<div class="sscribe-panel-body sscribe-p-md">
						<ul class="sscribe-check-list">
							<li><?php esc_html_e('Yoast SEO Premium & Free', 'sscribe-export-site-pages'); ?></li>
							<li><?php esc_html_e('Rank Math Pro & Free', 'sscribe-export-site-pages'); ?></li>
							<li><?php esc_html_e('All in One SEO (AIOSEO)', 'sscribe-export-site-pages'); ?></li>
							<li><?php esc_html_e('SEOPress', 'sscribe-export-site-pages'); ?></li>
							<li><?php esc_html_e('The SEO Framework', 'sscribe-export-site-pages'); ?></li>
						</ul>
					</div>
				</section>

				<div class="sscribe-callout">
					<div class="sscribe-callout-header">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
							stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<path d="M9 18h6" />
							<path d="M10 22h4" />
							<path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5A4.61 4.61 0 0 1 8.91 14" />
						</svg>
						<strong><?php esc_html_e('System Security Tips', 'sscribe-export-site-pages'); ?></strong>
					</div>
					<div class="sscribe-callout-body">
						<p><?php esc_html_e('To prevent server storage abuse, all exported ZIP archives are automatically purged from your server after 1 hour.', 'sscribe-export-site-pages'); ?></p>
						<p><?php esc_html_e('Data generation happens in batched cycles to ensure reliable conversion without hitting PHP max execution limits.', 'sscribe-export-site-pages'); ?></p>
					</div>
				</div>

			</div>
		</div>

	</div>
</div>
