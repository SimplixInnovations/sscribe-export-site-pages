<?php
/**
 * SScribe Export Site Pages - Admin Display Template
 *
 * @package SScribe_Export_Site_Pages
 * @subpackage Admin/Partials
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sscribe_wpml_active     = $sscribe_wpml_active ?? false;
$sscribe_languages       = $sscribe_languages ?? array();
$sscribe_total_pages_all = $sscribe_total_pages_all ?? 0;
$sscribe_status_counts   = $sscribe_status_counts ?? array();
$sscribe_recent_exports  = $sscribe_recent_exports ?? array();
$sscribe_debug_info      = $sscribe_debug_info ?? array();
$sscribe_is_debug        = $sscribe_is_debug ?? false;
$sscribe_step            = 1;
$sscribe_export_index    = $sscribe_export_index ?? array();
?>

<div class="sscribe-master-container">
	<header class="sscribe-hero">
		<div class="sscribe-hero-content">
			<div class="sscribe-hero-left">
				<div class="sscribe-hero-logo">
				<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is sanitized in get_icon() with esc_attr() on all dynamic attributes.
				?>
				<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'file-doc', 36, 'sscribe-logo-img' ) ); ?>
					<span class="screen-reader-text">SScribe</span>
				</div>
				<div>
					<h1 class="sscribe-hero-title">SScribe</h1>
					<p class="sscribe-hero-subtitle"><?php esc_html_e( 'Export every page into beautifully formatted documents with multilingual support, SEO meta, and secure ZIP download.', 'sscribe-export-site-pages' ); ?></p>
				</div>
			</div>
			<span class="sscribe-hero-version">v<?php echo esc_html( SSCRIBE_VERSION ); ?></span>
		</div>
		<div class="sscribe-hero-stats">
			<span class="sscribe-hero-stat">
			<?php
			echo wp_kses_post( SScribe_Helpers::get_icon( 'file-text', 12 ) );
			?>
			<strong id="sscribe-stat-total-pages"><?php echo esc_html( number_format_i18n( $sscribe_total_pages_all ) ); ?></strong> <?php esc_html_e( 'pages', 'sscribe-export-site-pages' ); ?></span>
			<span class="sscribe-hero-stat">
			<?php
			echo wp_kses_post( SScribe_Helpers::get_icon( 'clock', 12 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
			?>
			<strong id="sscribe-stat-recent-exports"><?php echo esc_html( count( $sscribe_recent_exports ) ); ?></strong> <?php esc_html_e( 'exports', 'sscribe-export-site-pages' ); ?></span>
		</div>
	</header>

	<a href="#sscribe-main-content" class="sscribe-skip-link screen-reader-text">
		<?php esc_html_e( 'Skip to main content', 'sscribe-export-site-pages' ); ?>
	</a>

	<div id="sscribe-live-region" class="screen-reader-text" aria-live="polite" aria-atomic="true"></div>
	<div id="sscribe-alert-region" class="screen-reader-text" aria-live="assertive" aria-atomic="true"></div>

	<div class="sscribe-workspace sscribe-flat-workspace" id="sscribe-main-content" role="main">
		<nav class="sscribe-tabs-nav" role="tablist" aria-label="<?php esc_attr_e( 'Main Navigation', 'sscribe-export-site-pages' ); ?>" aria-orientation="horizontal">
			<button type="button" class="sscribe-tab-btn sscribe-tab-active" id="sscribe-tab-btn-export" data-tab="export" role="tab" aria-selected="true" aria-controls="sscribe-tab-export">
				<?php
				echo wp_kses_post( SScribe_Helpers::get_icon( 'settings', 16 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				<?php esc_html_e( 'Export', 'sscribe-export-site-pages' ); ?>
			</button>
			<button type="button" class="sscribe-tab-btn" id="sscribe-tab-btn-history" data-tab="history" role="tab" aria-selected="false" aria-controls="sscribe-tab-history">
				<?php
				echo wp_kses_post( SScribe_Helpers::get_icon( 'clock', 16 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				<?php esc_html_e( 'History', 'sscribe-export-site-pages' ); ?>
			</button>
			<button type="button" class="sscribe-tab-btn" id="sscribe-tab-btn-docs" data-tab="docs" role="tab" aria-selected="false" aria-controls="sscribe-tab-docs">
				<?php
				echo wp_kses_post( SScribe_Helpers::get_icon( 'info', 16 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				<?php esc_html_e( 'Support', 'sscribe-export-site-pages' ); ?>
			</button>
			<?php if ( $sscribe_is_debug ) : ?>
			<button type="button" class="sscribe-tab-btn" id="sscribe-tab-btn-debug" data-tab="debug" role="tab" aria-selected="false" aria-controls="sscribe-tab-debug">
				<?php
				echo wp_kses_post( SScribe_Helpers::get_icon( 'file-search', 16 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				<?php esc_html_e( 'Debug', 'sscribe-export-site-pages' ); ?>
			</button>
			<?php endif; ?>
		</nav>

		<div class="sscribe-tab-content sscribe-tab-active" id="sscribe-tab-export" role="tabpanel" aria-labelledby="sscribe-tab-btn-export" aria-hidden="false">

<section class="sscribe-panel sscribe-config-panel">
			<div class="sscribe-panel-header">
				<div class="sscribe-panel-title">
					<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
					?>
					<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'settings', 20, 'sscribe-icon-img' ) ); ?>
					<h2><?php esc_html_e( 'Export Configuration', 'sscribe-export-site-pages' ); ?></h2>
				</div>
			</div>

			<div class="sscribe-panel-body sscribe-flat-body">
				<div class="sscribe-config-grid">
					<div class="sscribe-config-section">
						<div class="sscribe-section-title">
							<span class="sscribe-step-badge">1</span>
							<?php esc_html_e( 'Content Type', 'sscribe-export-site-pages' ); ?></div>
						<div class="sscribe-post-type-cards sscribe-cards-compact" id="sscribe-post-type-cards">
							<label class="sscribe-post-type-card">
								<input type="radio" name="sscribe_post_type" value="page" checked>
								<div class="sscribe-post-type-card-inner">
									<div class="sscribe-post-type-icon">
										<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
										?>
										<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'file-text', 22 ) ); ?>
									</div>
									<div class="sscribe-post-type-meta">
										<span class="sscribe-post-type-name"><?php esc_html_e( 'Pages', 'sscribe-export-site-pages' ); ?></span>
										<span class="sscribe-post-type-count" id="sscribe-page-count"><?php echo esc_html( number_format_i18n( $sscribe_total_pages_all ) ); ?></span>
									</div>
									<div class="sscribe-post-type-selector">
										<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
										?>
										<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'check', 16, 'sscribe-check-icon' ) ); ?>
									</div>
								</div>
							</label>
							<label class="sscribe-post-type-card">
								<input type="radio" name="sscribe_post_type" value="post">
								<div class="sscribe-post-type-card-inner">
									<div class="sscribe-post-type-icon">
										<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
										?>
										<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'article', 22 ) ); ?>
									</div>
									<div class="sscribe-post-type-meta">
										<span class="sscribe-post-type-name"><?php esc_html_e( 'Posts', 'sscribe-export-site-pages' ); ?></span>
										<span class="sscribe-post-type-count" id="sscribe-post-count">—</span>
									</div>
									<div class="sscribe-post-type-selector">
										<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
										?>
										<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'check', 16, 'sscribe-check-icon' ) ); ?>
									</div>
								</div>
							</label>
							<label class="sscribe-post-type-card">
								<input type="radio" name="sscribe_post_type" value="any">
								<div class="sscribe-post-type-card-inner">
									<div class="sscribe-post-type-icon">
										<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
										?>
										<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'copy', 22 ) ); ?>
									</div>
									<div class="sscribe-post-type-meta">
										<span class="sscribe-post-type-name"><?php esc_html_e( 'Both', 'sscribe-export-site-pages' ); ?></span>
										<span class="sscribe-post-type-count" id="sscribe-both-count">—</span>
									</div>
									<div class="sscribe-post-type-selector">
										<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
										?>
										<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'check', 16, 'sscribe-check-icon' ) ); ?>
									</div>
								</div>
							</label>
						</div>
					</div>

				<?php if ( $sscribe_wpml_active && ! empty( $sscribe_languages ) ) : ?>
					<?php ++$sscribe_step; ?>
					<div class="sscribe-config-section">
						<div class="sscribe-config-section-header">
							<span class="sscribe-step-badge"><?php echo esc_html( $sscribe_step ); ?></span>
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
							?>
							<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'globe', 15 ) ); ?>
							<span><?php esc_html_e( 'Language', 'sscribe-export-site-pages' ); ?></span>
						</div>
						<div class="sscribe-language-cards-wrapper">
						<div class="sscribe-language-cards sscribe-cards-row" id="sscribe-language-cards">
							<label class="sscribe-lang-card-label sscribe-lang-card-all sscribe-lang-card-compact">
								<input type="radio" name="sscribe_language" value="" checked>
								<div class="sscribe-lang-card-inner">
									<div class="sscribe-lang-flag-wrapper">
										<div class="sscribe-lang-flag-placeholder sscribe-lang-flag-all">
											<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
											?>
											<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'globe', 16 ) ); ?>
										</div>
									</div>
									<div class="sscribe-lang-meta">
										<span class="sscribe-lang-name"><?php esc_html_e( 'All', 'sscribe-export-site-pages' ); ?></span>
										<span class="sscribe-lang-count"><?php echo esc_html( number_format_i18n( $sscribe_total_pages_all ) ); ?></span>
									</div>
									<div class="sscribe-lang-selector">
										<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
										?>
										<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'check', 14, 'sscribe-check-icon' ) ); ?>
									</div>
								</div>
							</label>
							<?php foreach ( $sscribe_languages as $sscribe_lang ) : ?>
								<label class="sscribe-lang-card-label sscribe-lang-card-compact">
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
											<span class="sscribe-lang-count"><?php echo esc_html( number_format_i18n( $sscribe_lang['page_count'] ) ); ?></span>
										</div>
										<div class="sscribe-lang-selector">
											<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
											?>
											<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'check', 14, 'sscribe-check-icon' ) ); ?>
										</div>
									</div>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>

					<?php ++$sscribe_step; ?>
					<div class="sscribe-config-section">
						<div class="sscribe-section-title">
							<span class="sscribe-step-badge"><?php echo esc_html( $sscribe_step ); ?></span>
							<?php esc_html_e( 'Content Status', 'sscribe-export-site-pages' ); ?></div>
						<div class="sscribe-status-cards sscribe-cards-row" id="sscribe-status-cards">
							<?php
							$sscribe_status_labels = array(
								'publish' => __( 'Published', 'sscribe-export-site-pages' ),
								'draft'   => __( 'Draft', 'sscribe-export-site-pages' ),
								'private' => __( 'Private', 'sscribe-export-site-pages' ),
								'future'  => __( 'Scheduled', 'sscribe-export-site-pages' ),
								'pending' => __( 'Pending', 'sscribe-export-site-pages' ),
								'all'     => __( 'All', 'sscribe-export-site-pages' ),
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
							<label class="<?php echo esc_attr( $sscribe_label_class ); ?>"<?php echo $sscribe_is_zero ? ' aria-disabled="true"' : ''; ?>>
								<input type="radio" name="sscribe_post_status" value="<?php echo esc_attr( $sscribe_status_key ); ?>" <?php checked( $sscribe_is_first ); ?>
								<?php
								echo $sscribe_is_zero ? ' disabled' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute string.
								?>
								>
								<div class="sscribe-status-card-inner">
									<div class="sscribe-status-icon">
										<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
										?>
										<?php echo wp_kses_post( SScribe_Helpers::get_icon( $sscribe_status_icons[ $sscribe_status_key ], 16 ) ); ?>
									</div>
									<div class="sscribe-status-meta">
										<span class="sscribe-status-name"><?php echo esc_html( $sscribe_status_label ); ?></span>
										<span class="sscribe-status-count" data-status="<?php echo esc_attr( $sscribe_status_key ); ?>"><?php echo esc_html( number_format_i18n( $sscribe_count ) ); ?></span>
									</div>
									<div class="sscribe-status-selector">
										<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
										?>
										<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'check', 14, 'sscribe-check-icon' ) ); ?>
									</div>
								</div>
							</label>
							<?php endforeach; ?>
						</div>
					</div>

					<?php ++$sscribe_step; ?>
					<div class="sscribe-config-section">
						<div class="sscribe-section-title">
							<span class="sscribe-step-badge"><?php echo esc_html( $sscribe_step ); ?></span>
							<?php esc_html_e( 'Export Format', 'sscribe-export-site-pages' ); ?></div>
						<div class="sscribe-format-cards sscribe-cards-row" id="sscribe-format-cards">
							<label class="sscribe-format-card-label sscribe-format-all">
								<input type="radio" name="sscribe_format" value="all" checked>
								<div class="sscribe-format-card-inner">
									<div class="sscribe-format-icon">
										<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
										?>
										<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'download-package', 20 ) ); ?>
									</div>
									<div class="sscribe-format-meta">
										<span class="sscribe-format-name"><?php esc_html_e( 'All Formats', 'sscribe-export-site-pages' ); ?></span>
										<span class="sscribe-format-desc"><?php esc_html_e( 'DOCX + PDF + HTML + MD', 'sscribe-export-site-pages' ); ?></span>
									</div>
									<div class="sscribe-format-selector">
										<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
										?>
										<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'check', 14, 'sscribe-check-icon' ) ); ?>
									</div>
								</div>
							</label>
							<?php
							$sscribe_formats = array(
								'docx'     => array(
									'label' => __( 'DOCX', 'sscribe-export-site-pages' ),
									'icon'  => 'file-doc',
									'desc'  => __( 'Editable Word document', 'sscribe-export-site-pages' ),
								),
								'pdf'      => array(
									'label' => __( 'PDF', 'sscribe-export-site-pages' ),
									'icon'  => 'file-pdf',
									'desc'  => __( 'Print-ready document', 'sscribe-export-site-pages' ),
								),
								'html'     => array(
									'label' => __( 'HTML', 'sscribe-export-site-pages' ),
									'icon'  => 'file-html',
									'desc'  => __( 'Single-page HTML file', 'sscribe-export-site-pages' ),
								),
								'markdown' => array(
									'label' => __( 'Markdown', 'sscribe-export-site-pages' ),
									'icon'  => 'file-md',
									'desc'  => __( 'Portable markdown text', 'sscribe-export-site-pages' ),
								),
							);
							foreach ( $sscribe_formats as $sscribe_format_key => $sscribe_format_data ) :
								?>
							<label class="sscribe-format-card-label">
								<input type="radio" name="sscribe_format" value="<?php echo esc_attr( $sscribe_format_key ); ?>">
								<div class="sscribe-format-card-inner">
									<div class="sscribe-format-icon">
										<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
										?>
										<?php echo wp_kses_post( SScribe_Helpers::get_icon( $sscribe_format_data['icon'], 20 ) ); ?>
									</div>
									<div class="sscribe-format-meta">
										<span class="sscribe-format-name"><?php echo esc_html( $sscribe_format_data['label'] ); ?></span>
										<span class="sscribe-format-desc"><?php echo esc_html( $sscribe_format_data['desc'] ); ?></span>
									</div>
									<div class="sscribe-format-selector">
										<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
										?>
										<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'check', 14, 'sscribe-check-icon' ) ); ?>
									</div>
								</div>
							</label>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<div class="sscribe-export-bar">
					<div class="sscribe-config-summary" id="sscribe-config-summary" aria-live="polite">
						<span class="sscribe-summary-label"><?php esc_html_e( 'Config', 'sscribe-export-site-pages' ); ?>:</span>
						<span class="sscribe-summary-chip sscribe-summary-post-type" id="sscribe-summary-post-type"><?php esc_html_e( 'Pages', 'sscribe-export-site-pages' ); ?></span>
						<span class="sscribe-summary-sep" aria-hidden="true">·</span>
						<span class="sscribe-summary-chip sscribe-summary-status" id="sscribe-summary-status">
							<?php
							// Detect which status is actually pre-selected by PHP, rather than hardcoding 'Published'.
							$sscribe_selected_status_label = __( 'Published', 'sscribe-export-site-pages' );
							$sscribe_first_found           = true;
							foreach ( $sscribe_status_labels as $sscribe_s_key => $sscribe_s_label ) {
								$sscribe_s_count = isset( $sscribe_status_counts[ $sscribe_s_key ] ) ? intval( $sscribe_status_counts[ $sscribe_s_key ] ) : 0;
								if ( $sscribe_first_found && $sscribe_s_count > 0 ) {
									$sscribe_selected_status_label = $sscribe_s_label;
									$sscribe_first_found           = false;
								}
							}
							echo esc_html( $sscribe_selected_status_label );
							?>
						</span>
						<?php if ( $sscribe_wpml_active && ! empty( $sscribe_languages ) ) : ?>
						<span class="sscribe-summary-sep" aria-hidden="true">·</span>
						<span class="sscribe-summary-chip sscribe-summary-language" id="sscribe-summary-language"><?php esc_html_e( 'All', 'sscribe-export-site-pages' ); ?></span>
						<?php endif; ?>
						<span class="sscribe-summary-sep" aria-hidden="true">·</span>
						<span class="sscribe-summary-chip sscribe-summary-format" id="sscribe-summary-format"><?php esc_html_e( 'All', 'sscribe-export-site-pages' ); ?></span>
						<span class="sscribe-summary-sep-em" aria-hidden="true">|</span>
						<span class="sscribe-summary-chip sscribe-summary-pages" id="sscribe-summary-pages">—</span>
						<span class="sscribe-summary-sep" aria-hidden="true">·</span>
						<span class="sscribe-summary-chip sscribe-summary-time" id="sscribe-summary-time"><?php esc_html_e( 'See Preview', 'sscribe-export-site-pages' ); ?></span>
					</div>
					<div class="sscribe-export-bar-actions">
						<button type="button" id="sscribe-preview-btn" class="sscribe-button sscribe-button-outline sscribe-btn-sm" disabled aria-describedby="sscribe-preview-btn-hint">
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
							?>
							<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'eye', 15 ) ); ?>
							<span><?php esc_html_e( 'Preview', 'sscribe-export-site-pages' ); ?></span>
						</button>
						<span id="sscribe-preview-btn-hint" class="screen-reader-text"><?php esc_html_e( 'Preview what your export will look like before generating', 'sscribe-export-site-pages' ); ?></span>
						<button type="button" id="sscribe-export-btn" class="sscribe-button sscribe-button-primary sscribe-btn-lg" disabled aria-describedby="sscribe-export-btn-hint">
							<span id="sscribe-export-btn-text"><?php esc_html_e( 'Generate Package', 'sscribe-export-site-pages' ); ?></span>
						</button>
						<span id="sscribe-export-btn-hint" class="screen-reader-text"><?php esc_html_e( 'Start the export process for selected pages and format', 'sscribe-export-site-pages' ); ?></span>
						<span id="sscribe-export-disabled-reason" class="sscribe-export-disabled-reason" aria-live="polite"></span>
					</div>
				</div>

				<div id="sscribe-preview-panel" class="sscribe-modal sscribe-hidden" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="sscribe-preview-title" aria-describedby="sscribe-preview-desc" hidden>
					<div class="sscribe-modal-content sscribe-modal-content-preview" role="document">
						<div class="sscribe-modal-header">
							<h3 id="sscribe-preview-title">
								<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
								?>
								<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'eye', 16 ) ); ?>
								<?php esc_html_e( 'Export Readiness', 'sscribe-export-site-pages' ); ?>
							</h3>
							<button type="button" id="sscribe-preview-close" class="sscribe-modal-close" aria-label="<?php esc_attr_e( 'Close preview', 'sscribe-export-site-pages' ); ?>">
								<span aria-hidden="true">&times;</span>
							</button>
						</div>
						<div id="sscribe-preview-content" class="sscribe-modal-body">
							<div class="sscribe-preview-loading">
								<span class="sscribe-loading-spinner"></span>
								<span><?php esc_html_e( 'Generating preview...', 'sscribe-export-site-pages' ); ?></span>
							</div>
						</div>
						<div class="sscribe-modal-footer sscribe-preview-footer">
							<button type="button" id="sscribe-preview-start-btn" class="sscribe-button sscribe-button-primary">
								<?php esc_html_e( 'Start Export', 'sscribe-export-site-pages' ); ?>
							</button>
							<button type="button" id="sscribe-preview-dismiss-btn" class="sscribe-button sscribe-button-outline">
								<?php esc_html_e( 'Close', 'sscribe-export-site-pages' ); ?>
							</button>
						</div>
						<span id="sscribe-preview-desc" class="screen-reader-text"><?php esc_html_e( 'Export readiness preview showing selected configuration and expected output', 'sscribe-export-site-pages' ); ?></span>
					</div>
				</div>
		</section>

		<div id="sscribe-progress-area" class="sscribe-status-alert sscribe-status-processing sscribe-hidden" role="status" aria-live="polite" aria-labelledby="sscribe-status-text">
			<div class="sscribe-spinner" aria-hidden="true">
				<img src="<?php echo esc_url( SScribe_Helpers::icon_url( 'loader' ) ); ?>" width="40" height="40" alt="" class="sscribe-spinner-img">
			</div>
			<div class="sscribe-status-info">
				<div class="sscribe-phase-steps" role="list" aria-label="<?php esc_attr_e( 'Export phases', 'sscribe-export-site-pages' ); ?>">
					<div class="sscribe-phase-step sscribe-phase-active" data-phase="fetching" role="listitem">
						<span class="sscribe-phase-dot"></span>
						<span class="sscribe-phase-label"><?php esc_html_e( 'Fetching', 'sscribe-export-site-pages' ); ?></span>
						<?php
						echo wp_kses_post( SScribe_Helpers::get_icon( 'check', 12, 'sscribe-phase-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
						?>
					</div>
					<span class="sscribe-phase-connector" aria-hidden="true"></span>
					<div class="sscribe-phase-step" data-phase="processing" role="listitem">
						<span class="sscribe-phase-dot"></span>
						<span class="sscribe-phase-label"><?php esc_html_e( 'Processing', 'sscribe-export-site-pages' ); ?></span>
						<?php
						echo wp_kses_post( SScribe_Helpers::get_icon( 'check', 12, 'sscribe-phase-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
						?>
					</div>
					<span class="sscribe-phase-connector" aria-hidden="true"></span>
					<div class="sscribe-phase-step" data-phase="packaging" role="listitem">
						<span class="sscribe-phase-dot"></span>
						<span class="sscribe-phase-label"><?php esc_html_e( 'Packaging', 'sscribe-export-site-pages' ); ?></span>
						<?php
						echo wp_kses_post( SScribe_Helpers::get_icon( 'check', 12, 'sscribe-phase-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
						?>
					</div>
				</div>
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
							aria-valuetext="<?php esc_attr_e( 'Starting...', 'sscribe-export-site-pages' ); ?>"
							aria-labelledby="sscribe-status-text"></div>
					</div>
					<span id="sscribe-progress-text" class="sscribe-progress-percentage" aria-hidden="true">0%</span>
				</div>
				<div class="sscribe-progress-meta">
					<span id="sscribe-time-remaining" class="sscribe-time-remaining" aria-live="polite"></span>
				</div>
				<button type="button" id="sscribe-cancel-btn" class="sscribe-button sscribe-button-cancel" aria-describedby="sscribe-cancel-hint">
					<?php esc_html_e( 'Cancel Export', 'sscribe-export-site-pages' ); ?>
				</button>
				<span id="sscribe-cancel-hint" class="screen-reader-text"><?php esc_html_e( 'Stop the current export process and discard progress', 'sscribe-export-site-pages' ); ?></span>
			</div>
		</div>

		<div id="sscribe-download-area" class="sscribe-status-alert sscribe-status-success sscribe-hidden" role="status" aria-live="polite">
			<div class="sscribe-status-icon" aria-hidden="true">
				<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
				?>
				<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'check-circle', 32 ) ); ?>
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
						<?php esc_html_e( 'Download ZIP File', 'sscribe-export-site-pages' ); ?>
					</a>
					<span id="sscribe-download-hint" class="screen-reader-text"><?php esc_html_e( 'Download the exported ZIP file to your computer', 'sscribe-export-site-pages' ); ?></span>
					<button type="button" id="sscribe-new-export-btn" class="sscribe-button sscribe-button-ghost" aria-describedby="sscribe-new-export-hint">
						<?php esc_html_e( 'Start New Export', 'sscribe-export-site-pages' ); ?>
					</button>
					<span id="sscribe-new-export-hint" class="screen-reader-text"><?php esc_html_e( 'Clear current export and start a new one', 'sscribe-export-site-pages' ); ?></span>
				</div>
			</div>
		</div>

		<div id="sscribe-error-area" class="sscribe-status-alert sscribe-status-error sscribe-hidden" role="alert" aria-live="assertive">
			<div class="sscribe-status-icon" aria-hidden="true">
				<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
				?>
				<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'warning-circle', 32 ) ); ?>
			</div>
			<div class="sscribe-status-info">
				<h4 class="sscribe-status-heading">
					<?php esc_html_e( 'Export Failed', 'sscribe-export-site-pages' ); ?>
				</h4>
				<p id="sscribe-error-text" class="sscribe-status-desc"></p>
				<div id="sscribe-error-guidance" class="sscribe-error-guidance sscribe-hidden">
					<p id="sscribe-error-guidance-text" class="sscribe-guidance-text"></p>
				</div>
				<div id="sscribe-error-technical-details" class="sscribe-debug-details sscribe-hidden">
					<pre class="sscribe-debug-pre" aria-label="<?php esc_attr_e( 'Technical error details', 'sscribe-export-site-pages' ); ?>"></pre>
				</div>
				<div class="sscribe-error-actions">
					<button type="button" id="sscribe-error-try-again" class="sscribe-button sscribe-button-secondary" aria-describedby="sscribe-try-again-hint">
						<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
						?>
						<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'refresh-cw', 16 ) ); ?>
						<?php esc_html_e( 'Try Again', 'sscribe-export-site-pages' ); ?>
					</button>
					<span id="sscribe-try-again-hint" class="screen-reader-text"><?php esc_html_e( 'Attempt the export again', 'sscribe-export-site-pages' ); ?></span>
				</div>
			</div>
		</div>

		</div>

		<div class="sscribe-tab-content" id="sscribe-tab-history" role="tabpanel" aria-labelledby="sscribe-tab-btn-history" aria-hidden="true" tabindex="-1" hidden>
				<section class="sscribe-panel">
					<div class="sscribe-panel-header">
						<div class="sscribe-panel-title">
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
							?>
							<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'clock', 20, 'sscribe-icon-img' ) ); ?>
							<h2><?php esc_html_e( 'Recent Exports', 'sscribe-export-site-pages' ); ?></h2>
						</div>
						<span class="sscribe-badge"><?php esc_html_e( 'Auto-deletes in 72 hours', 'sscribe-export-site-pages' ); ?></span>
					</div>
					<div class="sscribe-bulk-bar sscribe-hidden" id="sscribe-bulk-bar">
						<div class="sscribe-bulk-left">
							<label class="sscribe-bulk-select-all">
								<input type="checkbox" id="sscribe-bulk-select-all" aria-label="<?php esc_attr_e( 'Select all exports', 'sscribe-export-site-pages' ); ?>">
								<span class="sscribe-check-visual"></span>
							</label>
							<span class="sscribe-bulk-count" id="sscribe-bulk-count">0 <?php esc_html_e( 'selected', 'sscribe-export-site-pages' ); ?></span>
						</div>
						<div class="sscribe-bulk-actions">
							<button type="button" class="sscribe-button sscribe-button-sm sscribe-button-outline" id="sscribe-bulk-download-btn" title="<?php esc_attr_e( 'Download selected exports', 'sscribe-export-site-pages' ); ?>" aria-label="<?php esc_attr_e( 'Download selected exports', 'sscribe-export-site-pages' ); ?>"><?php esc_html_e( 'Download', 'sscribe-export-site-pages' ); ?></button>
							<button type="button" class="sscribe-button sscribe-button-sm sscribe-button-danger" id="sscribe-bulk-delete-btn" title="<?php esc_attr_e( 'Delete selected exports', 'sscribe-export-site-pages' ); ?>" aria-label="<?php esc_attr_e( 'Delete selected exports', 'sscribe-export-site-pages' ); ?>"><?php esc_html_e( 'Delete', 'sscribe-export-site-pages' ); ?></button>
						</div>
					</div>
					<div class="sscribe-history-skeleton sscribe-hidden" id="sscribe-history-skeleton">
						<div class="sscribe-history-skeleton-row"><span class="sscribe-skeleton sscribe-skeleton-icon"></span><span class="sscribe-skeleton sscribe-skeleton-title"></span></div>
						<div class="sscribe-history-skeleton-row"><span class="sscribe-skeleton sscribe-skeleton-icon"></span><span class="sscribe-skeleton sscribe-skeleton-title" style="width:140px;"></span></div>
						<div class="sscribe-history-skeleton-row"><span class="sscribe-skeleton sscribe-skeleton-icon"></span><span class="sscribe-skeleton sscribe-skeleton-title" style="width:200px;"></span></div>
					</div>
					<div class="sscribe-history-table" id="sscribe-history-table">
						<?php if ( ! empty( $sscribe_recent_exports ) ) : ?>
							<?php foreach ( $sscribe_recent_exports as $sscribe_export ) : ?>
								<div class="sscribe-history-row" data-filename="<?php echo esc_attr( $sscribe_export['filename'] ); ?>">
									<label class="sscribe-history-check-label">
										<input type="checkbox" class="sscribe-history-check" value="<?php echo esc_attr( $sscribe_export['filename'] ); ?>">
										<span class="sscribe-check-visual"></span>
									</label>
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
												<?php
												$sscribe_date_fmt = sanitize_text_field( (string) get_option( 'date_format', 'Y-m-d' ) );
												if ( ! $sscribe_date_fmt ) {
													$sscribe_date_fmt = 'Y-m-d';
												}
												$sscribe_time_fmt = sanitize_text_field( (string) get_option( 'time_format', 'H:i' ) );
												if ( ! $sscribe_time_fmt ) {
													$sscribe_time_fmt = 'H:i';
												}
												echo esc_html( wp_date( $sscribe_date_fmt . ' ' . $sscribe_time_fmt, $sscribe_export['time'] ) );
												?>
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
								<svg class="sscribe-empty-illustration" width="64" height="64" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
									<rect x="8" y="12" width="48" height="40" rx="4" stroke="currentColor" stroke-width="2" fill="none" opacity="0.3"/>
									<path d="M8 20h48" stroke="currentColor" stroke-width="2" opacity="0.3"/>
									<rect x="14" y="28" width="20" height="3" rx="1.5" fill="currentColor" opacity="0.2"/>
									<rect x="14" y="34" width="14" height="3" rx="1.5" fill="currentColor" opacity="0.15"/>
									<rect x="14" y="40" width="17" height="3" rx="1.5" fill="currentColor" opacity="0.1"/>
								</svg>
								<em><?php esc_html_e( 'Your recent export packages will appear here.', 'sscribe-export-site-pages' ); ?></em>
							</div>
							<?php if ( ! empty( $sscribe_export_index ) && count( $sscribe_export_index ) > 10 ) : ?>
							<div class="sscribe-history-notice">
								<p><?php esc_html_e( 'Showing 10 most recent exports.', 'sscribe-export-site-pages' ); ?></p>
							</div>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				</section>
			</div>

			<div class="sscribe-tab-content" id="sscribe-tab-docs" role="tabpanel" aria-labelledby="sscribe-tab-btn-docs" aria-hidden="true" tabindex="-1" hidden>
				<div class="sscribe-support-master">
					<div class="sscribe-support-sidebar">
						<div class="sscribe-support-header">
							<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'info', 28 ) ); ?>
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
								<textarea id="sscribe-support-copy-text" class="sscribe-support-copy-text" readonly inputmode="none"></textarea>
							</div>
							<div class="sscribe-hidden" id="sscribe-support-grid" aria-live="polite"></div>
						</div>
					</div>
				</div>
			</div>

			<?php if ( $sscribe_is_debug ) : ?>
			<div class="sscribe-tab-content" id="sscribe-tab-debug" role="tabpanel" aria-labelledby="sscribe-tab-btn-debug" aria-hidden="true" tabindex="-1" hidden>
				<?php require_once SSCRIBE_PLUGIN_DIR . 'admin/partials/sscribe-admin-debug-tab.php'; ?>
			</div>
			<?php endif; ?>

		</div><!-- .sscribe-workspace -->

	<div id="sscribe-toast-container" class="sscribe-toast-container" aria-live="polite" aria-relevant="additions removals"></div>

	<div id="sscribe-log-modal" class="sscribe-modal sscribe-hidden" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="sscribe-log-modal-title" aria-describedby="sscribe-log-modal-desc" hidden>
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

</div><!-- .sscribe-master-container -->
