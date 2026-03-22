<?php
/**
 * Admin page display template — Premium UI with History.
 *
 * @package SScribe
 *
 * @var bool   $wpml_active    Whether WPML is active.
 * @var array  $languages      WPML languages array (enriched with page_count).
 * @var int    $total_pages    Total number of pages.
 * @var array  $seo_plugins    Active SEO plugins.
 * @var array  $recent_exports Array of recent ZIP exports.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="sscribe-master-container">
	<header class="sscribe-hero" style="background: radial-gradient(ellipse at 15% 50%, rgba(45,212,191,0.1) 0%, transparent 60%), radial-gradient(ellipse at 85% 50%, rgba(99,102,241,0.08) 0%, transparent 60%), #0B1120; color: #fff; border-radius: 12px; padding: 40px; display: flex; align-items: center; justify-content: center; overflow: hidden; position: relative; min-height: 220px; font-family: 'Manrope', -apple-system, sans-serif; margin-bottom: 24px;">

		<!-- Frosted Glass Container -->
		<div style="background: rgba(255, 255, 255, 0.04); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 16px; padding: 40px 48px; text-align: center; position: relative; z-index: 2; width: 70%; max-width: 600px; box-shadow: 0 4px 40px rgba(0, 0, 0, 0.1);">

			<div style="display: flex; justify-content: center; margin-bottom: 20px;">
				<div class="sscribe-logo-box" style="width: 64px; height: 64px; border: 2px solid rgba(45, 212, 191, 0.5); border-radius: 16px; display: flex; align-items: center; justify-content: center; background: rgba(45, 212, 191, 0.1);">
					<svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M16 3L28 9V23L16 29L4 23V9L16 3Z" fill="#2DD4BF" fill-opacity="0.2" />
						<path d="M10 20C10 20 11.5 22 16 22C20.5 22 22 20 22 18C22 14 10 16 10 12C10 10 12 8 16 8C20 8 22 10 22 10" stroke="#2DD4BF" stroke-width="2.5" stroke-linecap="round" />
					</svg>
				</div>
			</div>

			<h1 style="margin:0 0 16px 0; font-size: 42px; font-weight: 700; color: #fff; letter-spacing: -0.5px;">SScribe</h1>
			
			<p style="margin:0 0 24px 0; font-size: 16px; color: #94A3B8; text-wrap: balance; line-height: 1.6;">
				Export every page into beautifully formatted Word DOCX files with multilingual support, SEO meta, rich styling, and secure ZIP download.
			</p>

			<p style="margin:0; font-size: 14px; color: #64748B;">
				v<?php echo esc_html( SSCRIBE_VERSION ); ?>
			</p>
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
						<path
							d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
					</svg>
					<h2><?php esc_html_e( 'Select Export Output', 'sscribe-export-site-pages' ); ?></h2>
				</div>
			</div>

			<div class="sscribe-panel-body">
				<?php if ( $wpml_active && ! empty( $languages ) ) : ?>
					<p class="sscribe-description">
						<?php esc_html_e( 'Choose a language. The plugin will export all published pages for the selected language into a professional DOCX archive.', 'sscribe-export-site-pages' ); ?>
					</p>

					<div class="sscribe-language-cards">
						<?php foreach ( $languages as $lang ) : ?>
							<label class="sscribe-lang-card-label">
								<input type="radio" name="sscribe_language" value="<?php echo esc_attr( $lang['code'] ); ?>"
									<?php checked( $lang, reset( $languages ) ); ?>>
								<div class="sscribe-lang-card-inner">
									<div class="sscribe-lang-flag-wrapper">
										<?php if ( ! empty( $lang['flag_url'] ) ) : ?>
											<img src="<?php echo esc_url( $lang['flag_url'] ); ?>" alt="flag"
												class="sscribe-lang-flag">
										<?php else : ?>
											<div class="sscribe-lang-flag-placeholder">
												<?php echo esc_html( strtoupper( substr( $lang['code'], 0, 2 ) ) ); ?></div>
										<?php endif; ?>
									</div>
									<div class="sscribe-lang-meta">
										<span
											class="sscribe-lang-name"><?php echo esc_html( $lang['translated_name'] ); ?></span>
										<span class="sscribe-lang-count">
											<?php
											printf(
												/* translators: %d: number of pages */
												esc_html__( '%d Pages', 'sscribe-export-site-pages' ),
												intval( $lang['page_count'] )
											);
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
				<?php else : ?>
					<p class="sscribe-description">
						<?php
						printf(
							/* translators: %d: number of pages */
							esc_html__( 'Ready to export %d pages into beautiful Word documents.', 'sscribe-export-site-pages' ),
							intval( $total_pages )
						);
						?>
					</p>
				<?php endif; ?>

				<div class="sscribe-action-row">
					<button type="button" id="sscribe-export-btn" class="sscribe-button sscribe-button-primary">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
							stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
							<polyline points="7 10 12 15 17 10"></polyline>
							<line x1="12" y1="15" x2="12" y2="3"></line>
						</svg>
						<?php esc_html_e( 'Generate Documentation Package', 'sscribe-export-site-pages' ); ?>
					</button>
				</div>

				<!-- Live Progress Display -->
				<div id="sscribe-progress-area" class="sscribe-status-alert sscribe-status-processing"
					style="display: none;">
					<div class="sscribe-spinner">
						<!-- Custom CSS Animated Spinner -->
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
							<?php esc_html_e( 'Connecting & fetching pages...', 'sscribe-export-site-pages' ); ?></h4>
						<div class="sscribe-progress-tracker">
							<div class="sscribe-progress-bar-container">
								<div id="sscribe-progress-bar" class="sscribe-progress-bar-fill"></div>
							</div>
							<span id="sscribe-progress-text" class="sscribe-progress-percentage">0%</span>
						</div>
					</div>
				</div>

				<!-- Success Download Display -->
				<div id="sscribe-download-area" class="sscribe-status-alert sscribe-status-success"
					style="display: none;">
					<div class="sscribe-status-icon">
						<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor"
							stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
							<polyline points="22 4 12 14.01 9 11.01" />
						</svg>
					</div>
					<div class="sscribe-status-info">
						<h4 class="sscribe-status-heading">
							<?php esc_html_e( 'Export Completed Successfully', 'sscribe-export-site-pages' ); ?></h4>
						<p class="sscribe-status-desc">
							<?php esc_html_e( 'All selected pages have been packaged into a ZIP archive containing individual DOCX files.', 'sscribe-export-site-pages' ); ?>
						</p>
						<div class="sscribe-success-actions">
							<a id="sscribe-download-btn" href="#" class="sscribe-button sscribe-button-success"
								download>
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
									stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
									<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
									<polyline points="7 10 12 15 17 10" />
									<line x1="12" y1="15" x2="12" y2="3" />
								</svg>
								<?php esc_html_e( 'Download ZIP File', 'sscribe-export-site-pages' ); ?>
							</a>
							<button type="button" id="sscribe-retry-btn" class="sscribe-button sscribe-button-ghost">
								<?php esc_html_e( 'Start New Export', 'sscribe-export-site-pages' ); ?>
							</button>
						</div>
					</div>
				</div>

				<!-- Error Display -->
				<div id="sscribe-error-area" class="sscribe-status-alert sscribe-status-error" style="display: none;">
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
							<?php esc_html_e( 'Export Failed', 'sscribe-export-site-pages' ); ?></h4>
						<p id="sscribe-error-text" class="sscribe-status-desc"></p>
						<div class="sscribe-error-actions">
							<button type="button" id="sscribe-error-try-again"
								class="sscribe-button sscribe-button-secondary">
								<?php esc_html_e( 'Try Again', 'sscribe-export-site-pages' ); ?>
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
							<h2><?php esc_html_e( 'Recent Exports', 'sscribe-export-site-pages' ); ?></h2>
						</div>
						<span
							class="sscribe-badge"><?php esc_html_e( 'Auto-deletes in 1 hour', 'sscribe-export-site-pages' ); ?></span>
					</div>
					<div class="sscribe-history-table">
						<?php if ( ! empty( $recent_exports ) ) : ?>
							<?php foreach ( $recent_exports as $export ) : ?>
								<div class="sscribe-history-row">
									<div class="sscribe-history-file">
										<div class="sscribe-file-icon" style="display:flex;align-items:center;justify-content:center;width:40px;height:40px;background:#F8FAFC;border-radius:8px;">
											<?php if ( ! empty( $export['flag_url'] ) ) : ?>
												<img src="<?php echo esc_url( $export['flag_url'] ); ?>" alt="<?php echo esc_attr( $export['lang_name'] ); ?> flag" style="width:24px;border-radius:2px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
											<?php else : ?>
												<div style="color:#64748B;font-size:12px;font-weight:700;text-transform:uppercase;">
													<?php echo esc_html( strtoupper( substr( $export['lang_code'], 0, 2 ) ) ); ?>
												</div>
											<?php endif; ?>
										</div>
										<div class="sscribe-file-details">
											<strong><?php echo esc_html( $export['filename'] ); ?></strong>
											<span>
												<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $export['time'] ) ); ?>
												&mdash; <?php echo esc_html( size_format( $export['size'] ) ); ?>
											</span>
										</div>
									</div>
									<a href="<?php echo esc_url( $export['url'] ); ?>"
										class="sscribe-button sscribe-button-outline sscribe-button-sm" download>
										<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
											stroke-width="2">
											<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
											<polyline points="7 10 12 15 17 10" />
											<line x1="12" y1="15" x2="12" y2="3" />
										</svg>
										<?php esc_html_e( 'Download', 'sscribe-export-site-pages' ); ?>
									</a>
								</div>
							<?php endforeach; ?>
						<?php else : ?>
							<div class="sscribe-history-row" style="justify-content: center; padding: 40px; color: #64748B;">
								<em><?php esc_html_e( 'Your recent export packages will appear here.', 'sscribe-export-site-pages' ); ?></em>
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
							<h2><?php esc_html_e( 'What Each Document Includes', 'sscribe-export-site-pages' ); ?></h2>
						</div>
					</div>
					<div class="sscribe-panel-body">
						<div class="sscribe-features-grid">

							<!-- Defining all 15 features from UX screenshot -->
							<?php
							$allowed_svg = array(
								'path'     => array(
									'd'               => true,
									'fill'            => true,
									'fill-opacity'    => true,
									'stroke'          => true,
									'stroke-width'    => true,
									'stroke-linecap'  => true,
									'stroke-linejoin' => true,
								),
								'circle'   => array(
									'cx'           => true,
									'cy'           => true,
									'r'            => true,
									'fill'         => true,
									'stroke'       => true,
									'stroke-width' => true,
								),
								'line'     => array(
									'x1'           => true,
									'x2'           => true,
									'y1'           => true,
									'y2'           => true,
									'stroke'       => true,
									'stroke-width' => true,
								),
								'polyline' => array(
									'points'          => true,
									'fill'            => true,
									'stroke'          => true,
									'stroke-width'    => true,
									'stroke-linecap'  => true,
									'stroke-linejoin' => true,
								),
								'rect'     => array(
									'x'            => true,
									'y'            => true,
									'width'        => true,
									'height'       => true,
									'rx'           => true,
									'ry'           => true,
									'fill'         => true,
									'stroke'       => true,
									'stroke-width' => true,
								),
							);

							$features = array(
								array(
									'title' => __( 'Page Title', 'sscribe-export-site-pages' ),
									'desc'  => __( 'Main H1 heading with proper styling', 'sscribe-export-site-pages' ),
									'icon'  => '<path d="M4 7V4h16v3M9 20h6M12 4v16"/>',
									'color' => '#2563EB',
									'bg'    => '#DBEAFE',
								),
								array(
									'title' => __( 'Page Content', 'sscribe-export-site-pages' ),
									'desc'  => __( 'Full HTML content converted to Word', 'sscribe-export-site-pages' ),
									'icon'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
									'color' => '#059669',
									'bg'    => '#D1FAE5',
								),
								array(
									'title' => __( 'SEO Metadata', 'sscribe-export-site-pages' ),
									'desc'  => __( 'Meta title, description, focus keyword', 'sscribe-export-site-pages' ),
									'icon'  => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
									'color' => '#7C3AED',
									'bg'    => '#EDE9FE',
								),
								array(
									'title' => __( 'URL & Permalink', 'sscribe-export-site-pages' ),
									'desc'  => __( 'Full page URL for reference', 'sscribe-export-site-pages' ),
									'icon'  => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
									'color' => '#0891B2',
									'bg'    => '#CFFAFE',
								),
								array(
									'title' => __( 'Author Info', 'sscribe-export-site-pages' ),
									'desc'  => __( 'Page author name', 'sscribe-export-site-pages' ),
									'icon'  => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
									'color' => '#DC2626',
									'bg'    => '#FEE2E2',
								),
								array(
									'title' => __( 'Dates', 'sscribe-export-site-pages' ),
									'desc'  => __( 'Published and modified dates', 'sscribe-export-site-pages' ),
									'icon'  => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
									'color' => '#D97706',
									'bg'    => '#FEF3C7',
								),
								array(
									'title' => __( 'Parent Page', 'sscribe-export-site-pages' ),
									'desc'  => __( 'Breadcrumb hierarchy', 'sscribe-export-site-pages' ),
									'icon'  => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
									'color' => '#0EA5E9',
									'bg'    => '#E0F2FE',
								),
								array(
									'title' => __( 'Featured Image', 'sscribe-export-site-pages' ),
									'desc'  => __( 'Thumbnail when available', 'sscribe-export-site-pages' ),
									'icon'  => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
									'color' => '#EC4899',
									'bg'    => '#FCE7F3',
								),
							);

							foreach ( $features as $feature ) :
								?>
								<div class="sscribe-feature-item">
									<div class="sscribe-feature-icon"
										style="color: <?php echo esc_attr( $feature['color'] ); ?>; background: <?php echo esc_attr( $feature['bg'] ); ?>;">
										<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
											stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
											<?php echo wp_kses( $feature['icon'], $allowed_svg ); ?>
										</svg>
									</div>
									<div class="sscribe-feature-text">
										<h4><?php echo esc_html( $feature['title'] ); ?></h4>
										<p><?php echo esc_html( $feature['desc'] ); ?></p>
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
								<polyline points="10 9 9 9 8 9" />
							</svg>
							<h3><?php esc_html_e( 'Document Format', 'sscribe-export-site-pages' ); ?></h3>
						</div>
					</div>
					<div class="sscribe-panel-body sscribe-p-md">
						<ul class="sscribe-check-list">
							<li><?php esc_html_e( 'Standard Arial Typography', 'sscribe-export-site-pages' ); ?></li>
							<li><?php esc_html_e( 'Letter (8.5 × 11 in) Layout', 'sscribe-export-site-pages' ); ?></li>
							<li><?php esc_html_e( '1-Inch Margin Margins', 'sscribe-export-site-pages' ); ?></li>
							<li><?php esc_html_e( 'Strict H1-H6 Hierarchies', 'sscribe-export-site-pages' ); ?></li>
							<li><?php esc_html_e( 'Auto-Numerated Pages', 'sscribe-export-site-pages' ); ?></li>
							<li><?php esc_html_e( 'Verified Word Compatibility', 'sscribe-export-site-pages' ); ?></li>
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
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
							stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<path d="M9 18h6" />
							<path d="M10 22h4" />
							<path
								d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5A4.61 4.61 0 0 1 8.91 14" />
						</svg>
						<strong><?php esc_html_e( 'System Security Tips', 'sscribe-export-site-pages' ); ?></strong>
					</div>
					<div class="sscribe-callout-body">
						<p><?php esc_html_e( 'To prevent server storage abuse, all exported ZIP archives are automatically purged from your server after 1 hour.', 'sscribe-export-site-pages' ); ?>
						</p>
						<p><?php esc_html_e( 'Data generation happens in batched cycles to ensure reliable conversion without hitting PHP max execution limits.', 'sscribe-export-site-pages' ); ?>
						</p>
					</div>
				</div>

			</div>
		</div>

	</div>
</div>
