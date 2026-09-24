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
$sscribe_total_posts_all = $sscribe_total_posts_all ?? 0;
$sscribe_total_either_all = $sscribe_total_either_all ?? ( $sscribe_total_pages_all + $sscribe_total_posts_all );
$sscribe_status_counts   = $sscribe_status_counts ?? array();
$sscribe_recent_exports  = $sscribe_recent_exports ?? array();
$sscribe_debug_info      = $sscribe_debug_info ?? array();
$sscribe_is_debug        = $sscribe_is_debug ?? false;
$sscribe_can_view_health = $sscribe_can_view_health ?? false;
$sscribe_step            = 1;
$sscribe_export_index    = $sscribe_export_index ?? array();
$sscribe_preflight_warnings = $sscribe_preflight_warnings ?? array();
$sscribe_selectable_types = $sscribe_selectable_types ?? array();

if ( ! function_exists( 'sscribe_humanize_export_filename' ) ) {
	/**
	 * Convert a machine export filename into a human-readable label.
	 *
	 * Example: "my-wordpress-website-2026-08-13-044146-en-pdf-3fd778.zip"
	 *          -> "My Wordpress Website English PDF - Aug 13, 2026"
	 *
	 * @param string $sscribe_filename Filename ending in .zip.
	 * @return string Human label, or the original stem on no match.
	 */
	function sscribe_humanize_export_filename( $sscribe_filename ) {
		if ( '' === $sscribe_filename ) {
			return '';
		}
		$sscribe_stem = preg_replace( '/\.zip$/i', '', $sscribe_filename );
		if ( ! is_string( $sscribe_stem ) ) {
			$sscribe_stem = '';
		}
		$sscribe_date_match = array();
		if ( preg_match( '/(\d{4}-\d{2}-\d{2})(?:[-T](\d{2})(\d{2})(\d{2}))?/', $sscribe_stem, $sscribe_date_match ) ) {
			$sscribe_stem = str_replace( $sscribe_date_match[0], '', $sscribe_stem );
		}
		$sscribe_stem = preg_replace( '/-[a-f0-9]{4,8}$/i', '', $sscribe_stem );
		$sscribe_stem = trim( preg_replace( '/-+/', '-', $sscribe_stem ), '-' );
		$sscribe_parts = array_filter( explode( '-', $sscribe_stem ) );
		$sscribe_langs = array(
			'en' => 'English',
			'de' => 'German',
			'fr' => 'French',
			'es' => 'Spanish',
			'it' => 'Italian',
			'pt' => 'Portuguese',
			'nl' => 'Dutch',
			'pl' => 'Polish',
			'ru' => 'Russian',
			'ja' => 'Japanese',
			'zh' => 'Chinese',
			'ar' => 'Arabic',
			'tr' => 'Turkish',
			'sv' => 'Swedish',
			'fi' => 'Finnish',
			'da' => 'Danish',
			'no' => 'Norwegian',
			'cs' => 'Czech',
			'el' => 'Greek',
			'he' => 'Hebrew',
			'hi' => 'Hindi',
			'id' => 'Indonesian',
			'ko' => 'Korean',
			'ro' => 'Romanian',
			'th' => 'Thai',
			'uk' => 'Ukrainian',
			'vi' => 'Vietnamese',
		);
		$sscribe_tokens = array(
			'all'     => 'all',
			'langs'   => 'languages',
			'formats' => 'formats',
			'pages'  => 'pages',
			'posts'  => 'posts',
			'and'    => '+',
		);
		$sscribe_out = array();
		foreach ( $sscribe_parts as $sscribe_part ) {
			$sscribe_lower = strtolower( $sscribe_part );
			if ( isset( $sscribe_langs[ $sscribe_lower ] ) ) {
				$sscribe_out[] = $sscribe_langs[ $sscribe_lower ];
				continue;
			}
			if ( isset( $sscribe_tokens[ $sscribe_lower ] ) ) {
				$sscribe_out[] = $sscribe_tokens[ $sscribe_lower ];
				continue;
			}
			$sscribe_out[] = ucfirst( $sscribe_lower );
		}
		$sscribe_label = implode( ' ', $sscribe_out );
		$sscribe_label = preg_replace( '/\s+/', ' ', $sscribe_label );
		$sscribe_label = str_replace(
			array( 'Pdf', 'Docx', 'Html', 'Md' ),
			array( 'PDF', 'DOCX', 'HTML', 'MD' ),
			$sscribe_label
		);
		if ( ! empty( $sscribe_date_match[1] ) ) {
			$sscribe_ts         = strtotime( $sscribe_date_match[1] );
			$sscribe_friendly   = $sscribe_ts ? gmdate( 'M j, Y', $sscribe_ts ) : $sscribe_date_match[1];
			$sscribe_label      = trim( $sscribe_label . ' - ' . $sscribe_friendly );
		}
		return '' !== $sscribe_label ? $sscribe_label : $sscribe_stem;
	}
}
?>

<a class="sscribe-skip-link screen-reader-text" href="#sscribe-main-content"><?php esc_html_e( 'Skip to export configuration', 'sscribe-export-site-pages' ); ?></a>
<div class="sscribe-master-container sscribe-table-rule">
	<div class="sscribe-hero">
		<div class="sscribe-hero-content">
			<div class="sscribe-hero-left">
				<div class="sscribe-hero-logo">
				<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is sanitized in get_icon() with esc_attr() on all dynamic attributes.
				?>
				<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'file-doc', 36, 'sscribe-logo-img' ) ); ?>
					<span class="screen-reader-text">SScribe</span>
				</div>
				<div>
					<h1 class="sscribe-hero-title"><?php esc_html_e( 'SScribe Export', 'sscribe-export-site-pages' ); ?></h1>
					<p class="sscribe-hero-subtitle"><?php esc_html_e( 'Export every page into beautifully formatted documents with multilingual support, SEO meta, and secure ZIP download.', 'sscribe-export-site-pages' ); ?></p>
				</div>
			</div>
			<span class="sscribe-hero-version" title="<?php esc_attr_e( 'Plugin version', 'sscribe-export-site-pages' ); ?>">v<?php echo esc_html( SSCRIBE_VERSION ); ?></span>
		</div>
		<div class="sscribe-hero-stats" role="list">
			<span class="sscribe-hero-stat" role="listitem" title="<?php esc_attr_e( 'Total published pages available for export', 'sscribe-export-site-pages' ); ?>">
			<?php
			echo wp_kses_post( SScribe_Helpers::get_icon( 'file-text', 14 ) );
			?>
			<strong id="sscribe-stat-total-pages"><?php echo esc_html( number_format_i18n( $sscribe_total_pages_all ) ); ?></strong>
				<span class="sscribe-hero-stat-label"><?php echo esc_html( _n( 'page available', 'pages available', $sscribe_total_pages_all, 'sscribe-export-site-pages' ) ); ?></span>
			</span>
			<span class="sscribe-hero-stat" role="listitem" title="<?php esc_attr_e( 'Export packages generated in the last 72 hours', 'sscribe-export-site-pages' ); ?>">
			<?php
			echo wp_kses_post( SScribe_Helpers::get_icon( 'clock', 14 ) );
			?>
			<strong id="sscribe-stat-recent-exports"><?php echo esc_html( count( $sscribe_recent_exports ) ); ?></strong>
				<span class="sscribe-hero-stat-label"><?php echo esc_html( _n( 'recent export', 'recent exports', count( $sscribe_recent_exports ), 'sscribe-export-site-pages' ) ); ?></span>
			</span>
		</div>
	</div>

	<div id="sscribe-live-region" class="screen-reader-text" aria-live="polite" aria-atomic="true"></div>
	<div id="sscribe-alert-region" class="screen-reader-text" aria-live="assertive" aria-atomic="true"></div>

	<div id="sscribe-onboarding-banner" class="<?php echo esc_attr( 'sscribe-onboarding-banner' . ( empty( $sscribe_recent_exports ) ? '' : ' sscribe-hidden' ) ); ?>" role="region" aria-label="<?php esc_attr_e( 'First-run guide', 'sscribe-export-site-pages' ); ?>">
		<div class="sscribe-onboarding-inner">
			<div class="sscribe-onboarding-icon" aria-hidden="true">
				<?php
				echo wp_kses( SScribe_Helpers::get_icon_inline( 'download-package', 22 ), SScribe_Helpers::get_svg_kses_allowed_html() );
				?>
			</div>
			<div class="sscribe-onboarding-body">
				<h2 class="sscribe-onboarding-title"><?php esc_html_e( 'Export your first package in three steps', 'sscribe-export-site-pages' ); ?></h2>
				<p class="sscribe-onboarding-copy"><?php esc_html_e( 'Choose your content type below, pick a format, then click Generate Package. Use Preview to verify your selection before exporting.', 'sscribe-export-site-pages' ); ?></p>
				<ol class="sscribe-onboarding-steps">
					<li><?php esc_html_e( 'Pick what to export', 'sscribe-export-site-pages' ); ?></li>
					<li><?php esc_html_e( 'Pick a format', 'sscribe-export-site-pages' ); ?></li>
					<li><?php esc_html_e( 'Click Generate Package', 'sscribe-export-site-pages' ); ?></li>
				</ol>
			</div>
			<button type="button" id="sscribe-onboarding-dismiss" class="sscribe-button-icon sscribe-onboarding-close" aria-label="<?php esc_attr_e( 'Dismiss first-run guide', 'sscribe-export-site-pages' ); ?>">
				<span aria-hidden="true">&times;</span>
			</button>
		</div>
	</div>

	<div class="sscribe-workspace sscribe-flat-workspace" id="sscribe-main-content" tabindex="-1">
		<div id="sscribe-tab-announce" class="screen-reader-text" aria-live="polite" aria-atomic="true"></div>
		<nav class="sscribe-tabs-nav" role="tablist" aria-label="<?php esc_attr_e( 'Main Navigation', 'sscribe-export-site-pages' ); ?>" aria-orientation="horizontal">
			<button type="button" class="sscribe-tab-btn sscribe-tab-active" id="sscribe-tab-btn-export" data-tab="export" role="tab" aria-selected="true" aria-controls="sscribe-tab-export">
				<?php
				echo wp_kses_post( SScribe_Helpers::get_icon( 'settings', 16 ) );
				?>
				<?php esc_html_e( 'Export', 'sscribe-export-site-pages' ); ?>
			</button>
			<button type="button" class="sscribe-tab-btn" id="sscribe-tab-btn-history" data-tab="history" role="tab" aria-selected="false" aria-controls="sscribe-tab-history">
				<?php
				echo wp_kses_post( SScribe_Helpers::get_icon( 'clock', 16 ) );
				?>
				<?php esc_html_e( 'History', 'sscribe-export-site-pages' ); ?>
			</button>
			<?php if ( $sscribe_can_view_health ) : ?>
			<button type="button" class="sscribe-tab-btn" id="sscribe-tab-btn-support" data-tab="support" role="tab" aria-selected="false" aria-controls="sscribe-tab-support">
				<?php
				echo wp_kses_post( SScribe_Helpers::get_icon( 'info', 16 ) );
				?>
				<?php esc_html_e( 'Support', 'sscribe-export-site-pages' ); ?>
			</button>
			<?php endif; ?>
			<?php if ( $sscribe_is_debug ) : ?>
			<button type="button" class="sscribe-tab-btn" id="sscribe-tab-btn-debug" data-tab="debug" role="tab" aria-selected="false" aria-controls="sscribe-tab-debug">
				<?php
				echo wp_kses_post( SScribe_Helpers::get_icon( 'file-search', 16 ) );
				?>
				<?php esc_html_e( 'Debug', 'sscribe-export-site-pages' ); ?>
			</button>
			<?php endif; ?>
		</nav>

		<div class="sscribe-tab-content sscribe-tab-active" id="sscribe-tab-export" role="tabpanel" aria-labelledby="sscribe-tab-btn-export" aria-hidden="false" tabindex="0">

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
						<h3 class="sscribe-section-title">
							<span class="sscribe-step-badge">1</span>
							<?php esc_html_e( 'Content Type', 'sscribe-export-site-pages' ); ?></h3>
						<div class="sscribe-post-type-cards sscribe-cards-compact" id="sscribe-post-type-cards">
							<?php
							$sscribe_types_with_default = 0;
							foreach ( $sscribe_selectable_types as $sscribe_probe_row ) {
								if ( ! empty( $sscribe_probe_row['is_default'] ) ) {
									++$sscribe_types_with_default;
								}
							}
							$sscribe_type_is_first_non_any = true;
							foreach ( $sscribe_selectable_types as $sscribe_type_index => $sscribe_type_row ) :
								$sscribe_type_slug  = (string) ( $sscribe_type_row['slug'] ?? '' );
								$sscribe_type_label = (string) ( $sscribe_type_row['label'] ?? ucfirst( $sscribe_type_slug ) );
								$sscribe_type_icon  = (string) ( $sscribe_type_row['icon'] ?? 'file-text' );
								$sscribe_type_count = (int) ( $sscribe_type_row['count'] ?? 0 );
								$sscribe_type_is_any = ! empty( $sscribe_type_row['is_any'] );
								$sscribe_type_checked = ! $sscribe_type_is_any && ! empty( $sscribe_type_row['is_default'] );
								if ( $sscribe_type_is_first_non_any && ! $sscribe_type_is_any ) {
									// Cached rows without an is_default flag fall back
									// to the first card so exactly one stays selected.
									if ( 0 === $sscribe_types_with_default ) {
										$sscribe_type_checked = true;
									}
									$sscribe_type_is_first_non_any = false;
								}
								?>
								<label class="<?php echo esc_attr( 'sscribe-post-type-card' . ( $sscribe_type_is_any ? ' sscribe-post-type-card-any' : '' ) ); ?>">
									<input type="radio" name="sscribe_post_type" value="<?php echo esc_attr( $sscribe_type_slug ); ?>" <?php checked( $sscribe_type_checked ); ?>>
									<div class="sscribe-post-type-card-inner">
										<div class="sscribe-post-type-icon">
											<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon(). ?>
											<?php echo wp_kses_post( SScribe_Helpers::get_icon( $sscribe_type_icon, 22 ) ); ?>
										</div>
										<div class="sscribe-post-type-meta">
											<span class="sscribe-post-type-name"><?php echo esc_html( $sscribe_type_label ); ?></span>
											<span class="sscribe-post-type-count" data-sscribe-count-for="<?php echo esc_attr( $sscribe_type_slug ); ?>" data-count="<?php echo esc_attr( (string) $sscribe_type_count ); ?>"><?php echo esc_html( number_format_i18n( $sscribe_type_count ) ); ?></span>
										</div>
										<div class="sscribe-post-type-selector"></div>
									</div>
								</label>
							<?php endforeach; ?>
						</div>
					</div>

				<?php if ( $sscribe_wpml_active && ! empty( $sscribe_languages ) ) : ?>
					<?php ++$sscribe_step; ?>
					<div class="sscribe-config-section">
						<h3 class="sscribe-config-section-header">
							<span class="sscribe-step-badge"><?php echo esc_html( $sscribe_step ); ?></span>
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
							?>
							<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'globe', 15 ) ); ?>
							<span><?php esc_html_e( 'Language', 'sscribe-export-site-pages' ); ?></span>
						</h3>
						<div class="sscribe-language-cards-wrapper">
						<div class="sscribe-language-cards sscribe-cards-row" id="sscribe-language-cards">
							<label class="sscribe-lang-card-label sscribe-lang-card-all sscribe-lang-card-compact">
								<input type="radio" name="sscribe_language" value="__all__" checked>
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
									<div class="sscribe-lang-selector"></div>
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
										<div class="sscribe-lang-selector"></div>
									</div>
								</label>
							<?php endforeach; ?>
						</div>
						</div>
					</div>
					<?php endif; ?>

					<?php ++$sscribe_step; ?>
					<div class="sscribe-config-section">
						<h3 class="sscribe-section-title">
							<span class="sscribe-step-badge"><?php echo esc_html( $sscribe_step ); ?></span>
							<?php esc_html_e( 'Content Status', 'sscribe-export-site-pages' ); ?></h3>
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
							$sscribe_first                 = true;
							$sscribe_first_nonzero_key     = '';
							foreach ( $sscribe_status_labels as $sscribe_status_key => $sscribe_status_label ) :
								$sscribe_count    = isset( $sscribe_status_counts[ $sscribe_status_key ] ) ? intval( $sscribe_status_counts[ $sscribe_status_key ] ) : 0;
								$sscribe_is_zero  = ( 0 === $sscribe_count );
								$sscribe_is_first = $sscribe_first && ! $sscribe_is_zero;
								if ( $sscribe_is_first ) {
									$sscribe_first             = false;
									$sscribe_first_nonzero_key = $sscribe_status_key;
								}
								$sscribe_label_class = 'sscribe-status-card-label' . ( $sscribe_is_zero ? ' sscribe-status-disabled' : '' );
								?>
							<label class="<?php echo esc_attr( $sscribe_label_class ); ?>">
								<input type="radio" name="sscribe_post_status" value="<?php echo esc_attr( $sscribe_status_key ); ?>" <?php checked( $sscribe_is_first ); ?>
								<?php disabled( $sscribe_is_zero ); ?>
								aria-label="<?php echo esc_attr( sprintf( '%1$s, %2$d %3$s', $sscribe_status_label, $sscribe_count, _n( 'page', 'pages', $sscribe_count, 'sscribe-export-site-pages' ) ) ); ?>"
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
									<div class="sscribe-status-selector"></div>
								</div>
							</label>
							<?php endforeach; ?>
							<?php
							$sscribe_status_counts_first_key = $sscribe_first_nonzero_key;
							?>
						</div>
					</div>

					<?php ++$sscribe_step; ?>
					<div class="sscribe-config-section">
						<h3 class="sscribe-section-title">
							<span class="sscribe-step-badge"><?php echo esc_html( $sscribe_step ); ?></span>
							<?php esc_html_e( 'Export Format', 'sscribe-export-site-pages' ); ?></h3>
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
									<div class="sscribe-format-selector"></div>
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
									'desc'  => __( 'Plain text export', 'sscribe-export-site-pages' ),
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
									<div class="sscribe-format-selector"></div>
								</div>
							</label>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<div id="sscribe-format-options" class="sscribe-format-options sscribe-hidden">
					<div class="sscribe-format-options-inner">

						<div class="sscribe-format-option-panel" data-format="pdf" hidden>
							<h3 class="sscribe-format-option-title">
							<?php echo wp_kses( SScribe_Helpers::get_icon_inline( 'file-pdf', 14 ), SScribe_Helpers::get_svg_kses_allowed_html() ); ?>
								<?php esc_html_e( 'PDF Options', 'sscribe-export-site-pages' ); ?>
							</h3>
							<div class="sscribe-format-option-grid">
								<label class="sscribe-format-option-field">
									<span class="sscribe-format-option-label"><?php esc_html_e( 'Page size', 'sscribe-export-site-pages' ); ?></span>
									<select name="sscribe_pdf_page_size" id="sscribe-pdf-page-size">
										<option value="A4"><?php esc_html_e( 'A4 (210 × 297 mm)', 'sscribe-export-site-pages' ); ?></option>
										<option value="Letter"><?php esc_html_e( 'Letter (8.5 × 11 in)', 'sscribe-export-site-pages' ); ?></option>
										<option value="Legal"><?php esc_html_e( 'Legal (8.5 × 14 in)', 'sscribe-export-site-pages' ); ?></option>
										<option value="A3"><?php esc_html_e( 'A3 (297 × 420 mm)', 'sscribe-export-site-pages' ); ?></option>
									</select>
								</label>
								<label class="sscribe-format-option-field sscribe-format-option-checkbox">
									<input type="checkbox" name="sscribe_pdf_include_images" id="sscribe-pdf-include-images" value="1" checked>
									<span><?php esc_html_e( 'Embed images', 'sscribe-export-site-pages' ); ?></span>
								</label>
								<label class="sscribe-format-option-field sscribe-format-option-checkbox">
									<input type="checkbox" name="sscribe_pdf_include_page_numbers" id="sscribe-pdf-include-page-numbers" value="1" checked>
									<span><?php esc_html_e( 'Include page numbers', 'sscribe-export-site-pages' ); ?></span>
								</label>
							</div>
						</div>

						<div class="sscribe-format-option-panel" data-format="docx" hidden>
							<h3 class="sscribe-format-option-title">
							<?php echo wp_kses( SScribe_Helpers::get_icon_inline( 'file-doc', 14 ), SScribe_Helpers::get_svg_kses_allowed_html() ); ?>
								<?php esc_html_e( 'DOCX Options', 'sscribe-export-site-pages' ); ?>
							</h3>
							<div class="sscribe-format-option-grid">
								<label class="sscribe-format-option-field">
									<span class="sscribe-format-option-label"><?php esc_html_e( 'Template', 'sscribe-export-site-pages' ); ?></span>
									<select name="sscribe_docx_template" id="sscribe-docx-template">
										<option value="default"><?php esc_html_e( 'Default (with cover & TOC)', 'sscribe-export-site-pages' ); ?></option>
										<option value="minimal"><?php esc_html_e( 'Minimal (body only)', 'sscribe-export-site-pages' ); ?></option>
									</select>
								</label>
								<label class="sscribe-format-option-field sscribe-format-option-checkbox">
									<input type="checkbox" name="sscribe_docx_include_images" id="sscribe-docx-include-images" value="1" checked>
									<span><?php esc_html_e( 'Embed images', 'sscribe-export-site-pages' ); ?></span>
								</label>
								<label class="sscribe-format-option-field sscribe-format-option-checkbox">
									<input type="checkbox" name="sscribe_docx_include_toc" id="sscribe-docx-include-toc" value="1" checked>
									<span><?php esc_html_e( 'Include table of contents', 'sscribe-export-site-pages' ); ?></span>
								</label>
							</div>
						</div>

						<div class="sscribe-format-option-panel" data-format="markdown" hidden>
							<h3 class="sscribe-format-option-title">
							<?php echo wp_kses( SScribe_Helpers::get_icon_inline( 'file-md', 14 ), SScribe_Helpers::get_svg_kses_allowed_html() ); ?>
								<?php esc_html_e( 'Markdown Options', 'sscribe-export-site-pages' ); ?>
							</h3>
							<div class="sscribe-format-option-grid">
								<label class="sscribe-format-option-field sscribe-format-option-checkbox">
									<input type="checkbox" name="sscribe_md_include_frontmatter" id="sscribe-md-include-frontmatter" value="1" checked>
									<span><?php esc_html_e( 'Include YAML frontmatter', 'sscribe-export-site-pages' ); ?></span>
								</label>
								<label class="sscribe-format-option-field sscribe-format-option-checkbox">
									<input type="checkbox" name="sscribe_md_include_featured_image" id="sscribe-md-include-featured-image" value="1" checked>
									<span><?php esc_html_e( 'Include featured image', 'sscribe-export-site-pages' ); ?></span>
								</label>
								<label class="sscribe-format-option-field sscribe-format-option-checkbox">
									<input type="checkbox" name="sscribe_md_absolute_urls" id="sscribe-md-absolute-urls" value="1" checked>
									<span><?php esc_html_e( 'Use absolute image URLs', 'sscribe-export-site-pages' ); ?></span>
								</label>
							</div>
						</div>

						<div class="sscribe-format-option-panel" data-format="html" hidden>
							<h3 class="sscribe-format-option-title">
							<?php echo wp_kses( SScribe_Helpers::get_icon_inline( 'file-html', 14 ), SScribe_Helpers::get_svg_kses_allowed_html() ); ?>
								<?php esc_html_e( 'HTML Options', 'sscribe-export-site-pages' ); ?>
							</h3>
							<div class="sscribe-format-option-grid">
								<label class="sscribe-format-option-field sscribe-format-option-checkbox">
									<input type="checkbox" name="sscribe_html_include_css" id="sscribe-html-include-css" value="1" checked>
									<span><?php esc_html_e( 'Inline CSS styles', 'sscribe-export-site-pages' ); ?></span>
								</label>
<label class="sscribe-format-option-field sscribe-format-option-checkbox">
										<input type="checkbox" name="sscribe_html_responsive_images" id="sscribe-html-responsive-images" value="1" checked>
										<span><?php esc_html_e( 'Responsive image markup', 'sscribe-export-site-pages' ); ?></span>
									</label>
								</div>
							</div>

					</div>
				</div>

				<?php ++$sscribe_step; ?>
				<div class="sscribe-config-section sscribe-config-section-summary" id="sscribe-config-section-summary">
<h3 class="sscribe-section-title">
						<?php esc_html_e( 'Export Summary', 'sscribe-export-site-pages' ); ?>
					</h3>
					<div class="sscribe-config-summary-row">
						<div class="sscribe-config-summary" id="sscribe-config-summary" aria-live="polite" aria-label="<?php esc_attr_e( 'Selected export configuration', 'sscribe-export-site-pages' ); ?>">
							<span class="sscribe-summary-chip sscribe-summary-post-type" id="sscribe-summary-post-type"><?php esc_html_e( 'Pages', 'sscribe-export-site-pages' ); ?></span>
							<span class="sscribe-summary-sep" aria-hidden="true">·</span>
							<span class="sscribe-summary-chip sscribe-summary-status" id="sscribe-summary-status">
								<?php

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
							<span class="sscribe-summary-chip sscribe-summary-language" id="sscribe-summary-language"><?php esc_html_e( 'All languages', 'sscribe-export-site-pages' ); ?></span>
							<?php endif; ?>
							<span class="sscribe-summary-sep" aria-hidden="true">·</span>
							<span class="sscribe-summary-chip sscribe-summary-format" id="sscribe-summary-format"><?php esc_html_e( 'All formats', 'sscribe-export-site-pages' ); ?></span>
							<?php
								$sscribe_initial_chip = isset( $sscribe_status_counts[ $sscribe_status_counts_first_key ] )
									? (int) $sscribe_status_counts[ $sscribe_status_counts_first_key ]
									: 0;
							if ( $sscribe_initial_chip < 1 ) {
								foreach ( $sscribe_status_counts as $sscribe_ck => $sscribe_cv ) {
									if ( $sscribe_cv > 0 ) {
										$sscribe_status_counts_first_key = $sscribe_ck;
										$sscribe_initial_chip            = (int) $sscribe_cv;
										break;
									}
								}
							}
							?>
							<?php
								/* translators: %d: page count. */
								$sscribe_chip_aria = sprintf( _n( '%d page selected', '%d pages selected', $sscribe_initial_chip, 'sscribe-export-site-pages' ), $sscribe_initial_chip );
								$sscribe_chip_text = number_format_i18n( $sscribe_initial_chip ) . ' ' . _n( 'page', 'pages', $sscribe_initial_chip, 'sscribe-export-site-pages' );
							?>
							<span class="sscribe-summary-divider" aria-hidden="true"></span>
							<span class="sscribe-summary-chip sscribe-summary-pages" id="sscribe-summary-pages" aria-label="<?php echo esc_attr( $sscribe_chip_aria ); ?>"><?php echo esc_html( $sscribe_chip_text ); ?></span>
							<span class="sscribe-summary-sep" aria-hidden="true">·</span>
							<span class="sscribe-summary-chip sscribe-summary-time" id="sscribe-summary-time" aria-label="<?php esc_attr_e( 'Estimated time not yet available. Run Preview to compute.', 'sscribe-export-site-pages' ); ?>"><?php esc_html_e( 'Run Preview for ETA', 'sscribe-export-site-pages' ); ?></span>
						</div>
						<div class="sscribe-config-summary-preference">
							<label class="sscribe-preference-toggle" for="sscribe-auto-download-toggle">
								<input type="checkbox" id="sscribe-auto-download-toggle" name="sscribe_auto_download_pref" value="1">
								<span><?php esc_html_e( 'Auto-download when complete', 'sscribe-export-site-pages' ); ?></span>
							</label>
						</div>
					</div>
				</div>

				<?php if ( ! empty( $sscribe_preflight_warnings ) ) : ?>
				<div class="sscribe-preflight-warnings" role="status" aria-live="polite" aria-label="<?php esc_attr_e( 'Pre-export advisories', 'sscribe-export-site-pages' ); ?>">
					<?php foreach ( $sscribe_preflight_warnings as $sscribe_warning ) : ?>
						<?php
						$sscribe_w_severity = isset( $sscribe_warning['severity'] ) ? sanitize_html_class( (string) $sscribe_warning['severity'] ) : 'info';
						$sscribe_w_icon     = isset( $sscribe_warning['icon'] ) ? sanitize_key( (string) $sscribe_warning['icon'] ) : 'info';
						$sscribe_w_code     = isset( $sscribe_warning['code'] ) ? sanitize_key( (string) $sscribe_warning['code'] ) : '';
						?>
						<div class="sscribe-preflight-warning sscribe-preflight-warning-<?php echo esc_attr( $sscribe_w_severity ); ?>" data-warning-code="<?php echo esc_attr( $sscribe_w_code ); ?>">
							<span class="sscribe-preflight-warning-icon" aria-hidden="true">
								<?php echo wp_kses_post( SScribe_Helpers::get_icon( $sscribe_w_icon, 16 ) ); ?>
							</span>
							<div class="sscribe-preflight-warning-body">
								<p class="sscribe-preflight-warning-message"><?php echo esc_html( (string) ( $sscribe_warning['message'] ?? '' ) ); ?></p>
								<?php if ( ! empty( $sscribe_warning['detail'] ) ) : ?>
									<p class="sscribe-preflight-warning-detail"><?php echo esc_html( (string) $sscribe_warning['detail'] ); ?></p>
								<?php endif; ?>
							</div>
							<button type="button" class="sscribe-preflight-warning-dismiss" data-warning-code="<?php echo esc_attr( $sscribe_w_code ); ?>" aria-label="<?php esc_attr_e( 'Dismiss this advisory', 'sscribe-export-site-pages' ); ?>">
								<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'x', 14 ) ); ?>
							</button>
						</div>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>

				<div class="sscribe-export-bar">
					<div class="sscribe-export-bar-actions">
						<button type="button" id="sscribe-preview-btn" class="sscribe-button sscribe-button-outline sscribe-btn-sm" disabled aria-describedby="sscribe-preview-btn-hint" title="<?php esc_attr_e( 'Ctrl+Shift+P (Cmd+Shift+P on Mac)', 'sscribe-export-site-pages' ); ?>">
							<span><?php esc_html_e( 'Preview', 'sscribe-export-site-pages' ); ?></span>
						</button>
						<span id="sscribe-preview-btn-hint" class="screen-reader-text"><?php esc_html_e( 'Preview what your export will look like before generating', 'sscribe-export-site-pages' ); ?></span>
						<button type="button" id="sscribe-export-btn" class="sscribe-button sscribe-button-primary sscribe-btn-lg" disabled aria-describedby="sscribe-export-btn-hint" title="<?php esc_attr_e( 'Ctrl+Shift+E (Cmd+Shift+E on Mac)', 'sscribe-export-site-pages' ); ?>">
							<span id="sscribe-export-btn-text"><?php esc_html_e( 'Generate Package', 'sscribe-export-site-pages' ); ?></span>
						</button>
						<span id="sscribe-export-btn-hint" class="screen-reader-text"><?php esc_html_e( 'Start the export process for selected pages and format', 'sscribe-export-site-pages' ); ?></span>
					</div>
					<div class="sscribe-export-bar-status">
						<span id="sscribe-export-disabled-reason" class="sscribe-export-disabled-reason" aria-live="polite"></span>
						<noscript>
							<p class="sscribe-noscript-notice"><?php esc_html_e( 'JavaScript is required for export functionality. Please enable JavaScript in your browser.', 'sscribe-export-site-pages' ); ?></p>
						</noscript>
					</div>
				</div>

				<div id="sscribe-preview-panel" class="sscribe-modal sscribe-hidden" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="sscribe-preview-title" aria-describedby="sscribe-preview-desc" hidden>
					<div class="sscribe-modal-content sscribe-modal-content-preview" role="document">
						<div class="sscribe-modal-header">
							<h3 id="sscribe-preview-title">
							<?php echo wp_kses( SScribe_Helpers::get_icon_inline( 'eye', 16 ), SScribe_Helpers::get_svg_kses_allowed_html() ); ?>
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
							<button type="button" id="sscribe-preview-dismiss-btn" class="sscribe-button sscribe-button-outline">
								<?php esc_html_e( 'Close', 'sscribe-export-site-pages' ); ?>
							</button>
						<button type="button" id="sscribe-preview-start-btn" class="sscribe-button sscribe-button-primary">
							<?php esc_html_e( 'Start Export', 'sscribe-export-site-pages' ); ?>
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
					<div class="sscribe-phase-step sscribe-phase-active" data-phase="fetching" role="listitem" aria-current="step">
						<span class="sscribe-phase-dot" aria-hidden="true"></span>
						<span class="sscribe-phase-label"><?php esc_html_e( 'Reading pages', 'sscribe-export-site-pages' ); ?></span><span class="screen-reader-text sscribe-phase-state"><?php esc_html_e( 'Current step', 'sscribe-export-site-pages' ); ?></span>
						<?php
						echo wp_kses( SScribe_Helpers::get_icon_inline( 'check', 12, 'sscribe-phase-check' ), SScribe_Helpers::get_svg_kses_allowed_html() );
						?>
					</div>
					<span class="sscribe-phase-connector" aria-hidden="true"></span>
					<div class="sscribe-phase-step" data-phase="processing" role="listitem">
						<span class="sscribe-phase-dot" aria-hidden="true"></span>
						<span class="sscribe-phase-label"><?php esc_html_e( 'Generating files', 'sscribe-export-site-pages' ); ?></span><span class="screen-reader-text sscribe-phase-state"></span>
						<?php
						echo wp_kses( SScribe_Helpers::get_icon_inline( 'check', 12, 'sscribe-phase-check' ), SScribe_Helpers::get_svg_kses_allowed_html() );
						?>
					</div>
					<span class="sscribe-phase-connector" aria-hidden="true"></span>
					<div class="sscribe-phase-step" data-phase="packaging" role="listitem">
						<span class="sscribe-phase-dot" aria-hidden="true"></span>
						<span class="sscribe-phase-label"><?php esc_html_e( 'Packaging ZIP', 'sscribe-export-site-pages' ); ?></span><span class="screen-reader-text sscribe-phase-state"></span>
						<?php
						echo wp_kses( SScribe_Helpers::get_icon_inline( 'check', 12, 'sscribe-phase-check' ), SScribe_Helpers::get_svg_kses_allowed_html() );
						?>
					</div>
				</div>
				<h4 id="sscribe-status-text" class="sscribe-status-heading">
					<?php esc_html_e( 'Reading pages from WordPress...', 'sscribe-export-site-pages' ); ?>
				</h4>
				<p id="sscribe-current-page" class="sscribe-current-page"></p>
				<div class="sscribe-progress-tracker">
					<div class="sscribe-progress-bar-container">
						<div id="sscribe-progress-bar" class="sscribe-progress-bar-fill"
							role="progressbar"
							aria-valuemin="0"
							aria-valuemax="100"
							aria-valuenow="0"
							aria-valuetext="<?php esc_attr_e( 'Starting...', 'sscribe-export-site-pages' ); ?>"></div>
					</div>
					<span id="sscribe-progress-text" class="sscribe-progress-percentage">0%</span>
				</div>
				<div class="sscribe-progress-meta">
					<span id="sscribe-time-remaining" class="sscribe-time-remaining"></span>
				</div>
				<div class="sscribe-progress-actions">
					<button type="button" id="sscribe-cancel-btn" class="sscribe-button sscribe-button-cancel" aria-describedby="sscribe-cancel-hint">
						<?php esc_html_e( 'Cancel Export', 'sscribe-export-site-pages' ); ?>
					</button>
				</div>
				<span id="sscribe-cancel-hint" class="screen-reader-text"><?php esc_html_e( 'Stop the current export process and discard progress', 'sscribe-export-site-pages' ); ?></span>
			</div>
		</div>

		<div id="sscribe-download-area" class="sscribe-status-alert sscribe-status-success sscribe-hidden" role="alert" aria-live="assertive">
			<div class="sscribe-status-icon" aria-hidden="true">
				<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG sanitized in get_icon().
				?>
				<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'check-circle', 32 ) ); ?>
			</div>
			<div class="sscribe-status-info">
				<h4 class="sscribe-status-heading">
					<?php esc_html_e( 'Your package is ready', 'sscribe-export-site-pages' ); ?>
				</h4>
				<p class="sscribe-status-desc">
					<?php esc_html_e( 'All selected pages have been packaged into a ZIP archive. The file is also available in the History tab for the next 72 hours.', 'sscribe-export-site-pages' ); ?>
				</p>
				<dl class="sscribe-success-meta" id="sscribe-success-meta">
					<div class="sscribe-success-meta-item">
						<dt><?php esc_html_e( 'Pages', 'sscribe-export-site-pages' ); ?></dt>
						<dd id="sscribe-success-pages">-</dd>
					</div>
					<div class="sscribe-success-meta-item">
						<dt><?php esc_html_e( 'Formats', 'sscribe-export-site-pages' ); ?></dt>
						<dd id="sscribe-success-formats">-</dd>
					</div>
					<div class="sscribe-success-meta-item">
						<dt><?php esc_html_e( 'File size', 'sscribe-export-site-pages' ); ?></dt>
						<dd id="sscribe-success-size">-</dd>
					</div>
					<div class="sscribe-success-meta-item">
						<dt><?php esc_html_e( 'Generated', 'sscribe-export-site-pages' ); ?></dt>
						<dd id="sscribe-success-time">-</dd>
					</div>
				</dl>
				<div class="sscribe-success-actions">
					<a id="sscribe-download-btn" class="sscribe-button sscribe-button-success" download aria-disabled="true" aria-describedby="sscribe-download-hint">
						<span><?php esc_html_e( 'Download ZIP', 'sscribe-export-site-pages' ); ?></span>
					</a>
					<span id="sscribe-download-hint" class="screen-reader-text"><?php esc_html_e( 'Download the exported ZIP file to your computer', 'sscribe-export-site-pages' ); ?></span>
					<button type="button" id="sscribe-view-history-btn" class="sscribe-button sscribe-button-outline" aria-describedby="sscribe-view-history-hint">
						<?php echo wp_kses( SScribe_Helpers::get_icon_inline( 'clock', 16 ), SScribe_Helpers::get_svg_kses_allowed_html() ); ?>
						<span><?php esc_html_e( 'View in History', 'sscribe-export-site-pages' ); ?></span>
					</button>
					<span id="sscribe-view-history-hint" class="screen-reader-text"><?php esc_html_e( 'Open the History tab to see this and past exports', 'sscribe-export-site-pages' ); ?></span>
					<button type="button" id="sscribe-new-export-btn" class="sscribe-button sscribe-button-secondary" aria-describedby="sscribe-new-export-hint">
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
					<?php esc_html_e( 'Export failed', 'sscribe-export-site-pages' ); ?>
				</h4>
				<p id="sscribe-error-text" class="sscribe-status-desc"></p>
				<div id="sscribe-error-guidance" class="sscribe-error-guidance sscribe-hidden">
					<p id="sscribe-error-guidance-text" class="sscribe-guidance-text"></p>
				</div>
				<div class="sscribe-error-actions">
					<button type="button" id="sscribe-error-try-again" class="sscribe-button sscribe-button-secondary" aria-describedby="sscribe-try-again-hint">
						<?php echo wp_kses( SScribe_Helpers::get_icon_inline( 'refresh-cw', 16 ), SScribe_Helpers::get_svg_kses_allowed_html() ); ?>
						<span><?php esc_html_e( 'Try Again', 'sscribe-export-site-pages' ); ?></span>
					</button>
					<button type="button" id="sscribe-error-change-config" class="sscribe-button sscribe-button-outline">
						<?php esc_html_e( 'Change Configuration', 'sscribe-export-site-pages' ); ?>
					</button>
					<button type="button" id="sscribe-error-toggle-details" class="sscribe-button sscribe-button-ghost sscribe-button-toggle-details sscribe-hidden" aria-expanded="false" aria-controls="sscribe-error-technical-details" hidden>
						<?php echo wp_kses( SScribe_Helpers::get_icon_inline( 'chevron-down', 14 ), SScribe_Helpers::get_svg_kses_allowed_html() ); ?>
						<span id="sscribe-error-toggle-details-label"><?php esc_html_e( 'Show technical details', 'sscribe-export-site-pages' ); ?></span>
					</button>
					<span id="sscribe-try-again-hint" class="screen-reader-text"><?php esc_html_e( 'Attempt the export again', 'sscribe-export-site-pages' ); ?></span>
				</div>
				<div id="sscribe-error-technical-details" class="sscribe-debug-details sscribe-hidden" hidden>
					<pre class="sscribe-debug-pre" aria-label="<?php esc_attr_e( 'Technical error details', 'sscribe-export-site-pages' ); ?>"></pre>
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
						<div class="sscribe-panel-header-meta">
							<span class="sscribe-badge sscribe-badge-info" title="<?php esc_attr_e( 'Files are auto-deleted 72 hours after creation to keep private storage clean.', 'sscribe-export-site-pages' ); ?>"><?php esc_html_e( 'Auto-deletes in 72 hours', 'sscribe-export-site-pages' ); ?></span>
						</div>
					</div>
					<?php if ( ! empty( $sscribe_recent_exports ) ) : ?>
					<div class="sscribe-history-toolbar">
						<label class="sscribe-search-field">
							<span class="screen-reader-text"><?php esc_html_e( 'Filter exports', 'sscribe-export-site-pages' ); ?></span>
							<?php
							echo wp_kses_post( SScribe_Helpers::get_icon( 'search', 14 ) );
							?>
							<input type="search" id="sscribe-history-search" placeholder="<?php esc_attr_e( 'Filter by name or size...', 'sscribe-export-site-pages' ); ?>" autocomplete="off">
						</label>
						<p class="sscribe-bulk-hint" id="sscribe-bulk-hint">
							<?php
							echo wp_kses_post( SScribe_Helpers::get_icon( 'info', 13 ) );
							?>
							<span><?php esc_html_e( 'Tip: select multiple files to bulk download or delete.', 'sscribe-export-site-pages' ); ?></span>
						</p>
					</div>
					<div class="sscribe-bulk-bar" id="sscribe-bulk-bar" data-active="false">
						<div class="sscribe-bulk-left">
							<label class="sscribe-bulk-select-all">
								<input type="checkbox" id="sscribe-bulk-select-all" aria-label="<?php esc_attr_e( 'Select all visible exports', 'sscribe-export-site-pages' ); ?>">
								<span class="sscribe-check-visual"></span>
								<span class="sscribe-bulk-select-all-label"><?php esc_html_e( 'Select all', 'sscribe-export-site-pages' ); ?></span>
							</label>
							<span class="sscribe-bulk-count" id="sscribe-bulk-count" aria-live="polite">0 <?php esc_html_e( 'selected', 'sscribe-export-site-pages' ); ?></span>
						</div>
						<div class="sscribe-bulk-actions">
							<button type="button" class="sscribe-button sscribe-button-sm sscribe-button-outline" id="sscribe-bulk-download-btn" disabled title="<?php esc_attr_e( 'Download selected exports', 'sscribe-export-site-pages' ); ?>" aria-label="<?php esc_attr_e( 'Download selected exports', 'sscribe-export-site-pages' ); ?>">
								<span><?php esc_html_e( 'Download', 'sscribe-export-site-pages' ); ?></span>
							</button>
							<button type="button" class="sscribe-button sscribe-button-sm sscribe-button-danger" id="sscribe-bulk-delete-btn" disabled title="<?php esc_attr_e( 'Delete selected exports', 'sscribe-export-site-pages' ); ?>" aria-label="<?php esc_attr_e( 'Delete selected exports', 'sscribe-export-site-pages' ); ?>">
								<span><?php esc_html_e( 'Delete', 'sscribe-export-site-pages' ); ?></span>
							</button>
						</div>
					</div>
					<?php endif; ?>
					<div class="sscribe-history-skeleton sscribe-hidden" id="sscribe-history-skeleton">
						<div class="sscribe-history-skeleton-row"><span class="sscribe-skeleton sscribe-skeleton-icon"></span><span class="sscribe-skeleton sscribe-skeleton-title"></span></div>
						<div class="sscribe-history-skeleton-row"><span class="sscribe-skeleton sscribe-skeleton-icon"></span><span class="sscribe-skeleton sscribe-skeleton-title sscribe-skeleton-title-short"></span></div>
						<div class="sscribe-history-skeleton-row"><span class="sscribe-skeleton sscribe-skeleton-icon"></span><span class="sscribe-skeleton sscribe-skeleton-title sscribe-skeleton-title-long"></span></div>
					</div>
					<div class="sscribe-history-table" id="sscribe-history-table">
						<?php if ( ! empty( $sscribe_recent_exports ) ) : ?>
							<table class="sscribe-history-table-element">
								<caption class="screen-reader-text"><?php esc_html_e( 'Recent export packages', 'sscribe-export-site-pages' ); ?></caption>
								<thead>
									<tr>
										<th scope="col" class="sscribe-history-col-check"><span class="screen-reader-text"><?php esc_html_e( 'Select', 'sscribe-export-site-pages' ); ?></span></th>
										<th scope="col" class="sscribe-history-col-file"><?php esc_html_e( 'Export', 'sscribe-export-site-pages' ); ?></th>
										<th scope="col" class="sscribe-history-col-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'sscribe-export-site-pages' ); ?></span></th>
									</tr>
								</thead>
								<tbody>
							<?php foreach ( $sscribe_recent_exports as $sscribe_export_row_index => $sscribe_export ) : ?>
								<?php
								$sscribe_date_fmt = sanitize_text_field( (string) get_option( 'date_format', 'Y-m-d' ) );
								if ( ! $sscribe_date_fmt ) {
									$sscribe_date_fmt = 'Y-m-d';
								}
								$sscribe_time_fmt = sanitize_text_field( (string) get_option( 'time_format', 'H:i' ) );
								if ( ! $sscribe_time_fmt ) {
									$sscribe_time_fmt = 'H:i';
								}
								$sscribe_expiry_ts = isset( $sscribe_export['time'] ) ? (int) $sscribe_export['time'] + ( 72 * HOUR_IN_SECONDS ) : 0;
								$sscribe_human     = $sscribe_expiry_ts > 0 ? human_time_diff( time(), $sscribe_expiry_ts ) : '';
								?>
								<tr class="sscribe-history-row" data-filename="<?php echo esc_attr( $sscribe_export['filename'] ); ?>" aria-rowindex="<?php echo (int) ( $sscribe_export_row_index + 1 ); ?>">
									<td class="sscribe-history-cell-check">
										<label class="sscribe-history-check-label">
											<input type="checkbox" class="sscribe-history-check" value="<?php echo esc_attr( $sscribe_export['filename'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: export filename */ __( 'Select export %s', 'sscribe-export-site-pages' ), $sscribe_export['filename'] ) ); ?>">
											<span class="sscribe-check-visual"></span>
										</label>
									</td>
									<td class="sscribe-history-cell-file">
										<div class="sscribe-history-file">
											<div class="sscribe-file-icon">
												<?php if ( ! empty( $sscribe_export['flag_url'] ) ) : ?>
													<img src="<?php echo esc_url( $sscribe_export['flag_url'] ); ?>" alt="<?php echo esc_attr( $sscribe_export['lang_name'] ); ?>" class="sscribe-file-icon-img">
												<?php else : ?>
													<span class="sscribe-file-icon-text"><?php echo esc_html( strtoupper( substr( $sscribe_export['lang_code'], 0, 2 ) ) ); ?></span>
												<?php endif; ?>
											</div>
											<div class="sscribe-file-details">
												<?php
												$sscribe_human_label = sscribe_humanize_export_filename( $sscribe_export['filename'] );
												?>
												<strong class="sscribe-history-filename" title="<?php echo esc_attr( $sscribe_human_label ); ?>"><?php echo esc_html( $sscribe_export['filename'] ); ?></strong>
												<?php if ( '' !== $sscribe_human_label && strtolower( $sscribe_human_label ) !== strtolower( $sscribe_export['filename'] ) ) : ?>
													<span class="sscribe-file-human-label"><?php echo esc_html( $sscribe_human_label ); ?></span>
												<?php endif; ?>
												<span class="sscribe-file-meta">
													<?php echo esc_html( wp_date( $sscribe_date_fmt . ' ' . $sscribe_time_fmt, $sscribe_export['time'] ) ); ?>
													<span class="sscribe-meta-sep" aria-hidden="true">·</span>
													<span class="sscribe-file-size"><?php echo esc_html( size_format( $sscribe_export['size'] ) ); ?></span>
													<?php if ( '' !== $sscribe_human ) : ?>
													<span class="sscribe-meta-sep" aria-hidden="true">·</span>
													<span class="sscribe-file-retention" title="<?php esc_attr_e( 'Time until this file is auto-deleted', 'sscribe-export-site-pages' ); ?>">
														<?php
														/* translators: %s: human time difference */
														echo esc_html( sprintf( __( 'expires in %s', 'sscribe-export-site-pages' ), $sscribe_human ) );
														?>
													</span>
													<?php endif; ?>
												</span>
											</div>
										</div>
									</td>
									<td class="sscribe-history-cell-actions">
										<div class="sscribe-history-actions">
											<a href="<?php echo esc_url( $sscribe_export['url'] ); ?>" class="sscribe-button sscribe-button-outline sscribe-button-sm" download title="<?php esc_attr_e( 'Download this export', 'sscribe-export-site-pages' ); ?>" aria-label="<?php esc_attr_e( 'Download this export', 'sscribe-export-site-pages' ); ?>">
												<?php esc_html_e( 'Download', 'sscribe-export-site-pages' ); ?>
											</a>
											<?php /* translators: %s: export filename */ ?>
											<button type="button" class="sscribe-button sscribe-button-outline sscribe-button-sm sscribe-log-btn" data-filename="<?php echo esc_attr( $sscribe_export['filename'] ); ?>" title="<?php esc_attr_e( 'View export log', 'sscribe-export-site-pages' ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'View export log for %s', 'sscribe-export-site-pages' ), $sscribe_export['filename'] ) ); ?>">
												<?php esc_html_e( 'Log', 'sscribe-export-site-pages' ); ?>
											</button>
											<button type="button" class="sscribe-button sscribe-button-outline sscribe-button-sm sscribe-button-danger sscribe-delete-btn" data-filename="<?php echo esc_attr( $sscribe_export['filename'] ); ?>" title="<?php esc_attr_e( 'Delete this export', 'sscribe-export-site-pages' ); ?>" aria-label="<?php esc_attr_e( 'Delete this export', 'sscribe-export-site-pages' ); ?>">
												<?php esc_html_e( 'Delete', 'sscribe-export-site-pages' ); ?>
											</button>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
								</tbody>
							</table>
							<?php if ( ! empty( $sscribe_export_index ) && count( $sscribe_export_index ) > 10 ) : ?>
							<div class="sscribe-history-notice">
								<p>
									<?php
									/* translators: %d: number of additional exports */
									echo esc_html( sprintf( _n( '%d more export available in your archive.', '%d more exports available in your archive.', count( $sscribe_export_index ) - 10, 'sscribe-export-site-pages' ), count( $sscribe_export_index ) - 10 ) );
									?>
								</p>
							</div>
							<?php endif; ?>
						<?php else : ?>
							<div class="sscribe-history-empty" id="sscribe-history-empty">
								<div class="sscribe-empty-icon" aria-hidden="true">
									<?php
									echo wp_kses_post( SScribe_Helpers::get_icon( 'download-package', 48 ) );
									?>
								</div>
								<h3 class="sscribe-empty-title"><?php esc_html_e( 'No exports yet', 'sscribe-export-site-pages' ); ?></h3>
								<p class="sscribe-empty-copy"><?php esc_html_e( 'Your generated packages will appear here. They auto-delete 72 hours after creation.', 'sscribe-export-site-pages' ); ?></p>
								<button type="button" class="sscribe-button sscribe-button-primary" id="sscribe-empty-start-export-btn">
									<span><?php esc_html_e( 'Export your first package', 'sscribe-export-site-pages' ); ?></span>
								</button>
							</div>
						<?php endif; ?>
					</div>
				</section>
			</div>

			<?php if ( $sscribe_can_view_health ) : ?>
			<div class="sscribe-tab-content" id="sscribe-tab-support" role="tabpanel" aria-labelledby="sscribe-tab-btn-support" aria-hidden="true" tabindex="-1" hidden>
				<div class="sscribe-support-master">
					<div class="sscribe-support-sidebar">

						<div class="sscribe-support-section">
							<div class="sscribe-support-section-header">
								<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'info', 20 ) ); ?>
								<h2><?php esc_html_e( 'System Diagnostics', 'sscribe-export-site-pages' ); ?></h2>
							</div>
							<p class="sscribe-support-section-copy"><?php esc_html_e( 'Generate a redacted environment snapshot. Share this securely with Simplix Innovations support to help us diagnose and resolve issues faster. The snapshot never includes passwords, license keys, or private post content.', 'sscribe-export-site-pages' ); ?></p>

							<div class="sscribe-support-actions-vertical">
								<button type="button" class="sscribe-button sscribe-button-primary" id="sscribe-support-copy-btn" disabled>
									<span><?php esc_html_e( 'Copy to Clipboard', 'sscribe-export-site-pages' ); ?></span>
								</button>
								<button type="button" class="sscribe-button sscribe-button-outline" id="sscribe-support-refresh-btn">
									<?php echo wp_kses_post( SScribe_Helpers::get_icon( 'refresh-cw', 16 ) ); ?>
									<span><?php esc_html_e( 'Refresh Data', 'sscribe-export-site-pages' ); ?></span>
								</button>
							</div>
							<p class="sscribe-support-feedback sscribe-hidden" id="sscribe-support-feedback" aria-live="polite"></p>
						</div>
					</div>

					<div class="sscribe-support-main">
						<div class="sscribe-support-panel" data-support-card aria-busy="false">
							<div class="sscribe-support-panel-header">
								<h3><?php esc_html_e( 'System snapshot', 'sscribe-export-site-pages' ); ?></h3>
								<span class="sscribe-support-panel-meta"><?php esc_html_e( 'Redacted environment report', 'sscribe-export-site-pages' ); ?></span>
							</div>
							<div class="sscribe-support-copy-wrap">
								<label class="screen-reader-text" for="sscribe-support-copy-text"><?php esc_html_e( 'Support information text', 'sscribe-export-site-pages' ); ?></label>
								<textarea id="sscribe-support-copy-text" class="sscribe-support-copy-text" readonly inputmode="none" placeholder="<?php esc_attr_e( 'Click Refresh Data on the left to generate a redacted environment snapshot you can copy to share with support.', 'sscribe-export-site-pages' ); ?>"></textarea>
							</div>
							<div id="sscribe-support-grid" class="sscribe-support-grid sscribe-support-grid-empty" aria-live="polite" aria-busy="false">
								<div class="sscribe-support-empty">
								<span class="sscribe-support-empty-icon" aria-hidden="true">
									<svg width="96" height="96" viewBox="0 0 96 96" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
										<rect x="16" y="10" width="50" height="68" rx="7" fill="var(--ss-surface)" stroke="var(--ss-border-strong)" stroke-width="1.5"/>
										<rect x="23" y="20" width="36" height="3.5" rx="1.75" fill="var(--ss-text-tertiary)" opacity="0.35"/>
										<rect x="23" y="29" width="36" height="3.5" rx="1.75" fill="var(--ss-text-tertiary)" opacity="0.35"/>
										<rect x="23" y="38" width="24" height="3.5" rx="1.75" fill="var(--ss-text-tertiary)" opacity="0.35"/>
										<rect x="23" y="47" width="36" height="3.5" rx="1.75" fill="var(--ss-brand)" opacity="0.55"/>
										<rect x="23" y="56" width="30" height="3.5" rx="1.75" fill="var(--ss-text-tertiary)" opacity="0.35"/>
										<circle cx="70" cy="68" r="18" fill="var(--ss-brand)" opacity="0.12"/>
										<circle cx="70" cy="68" r="12" fill="var(--ss-brand)"/>
										<path d="M64.5 68l3.8 3.8 6.7-6.7" stroke="var(--ss-text-inverse)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
									</svg>
								</span>
									<p class="sscribe-support-empty-title"><?php esc_html_e( 'No snapshot loaded yet', 'sscribe-export-site-pages' ); ?></p>
									<p class="sscribe-support-empty-copy"><?php esc_html_e( 'The snapshot is generated locally on demand and never includes passwords, license keys, or private content.', 'sscribe-export-site-pages' ); ?></p>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
			<?php endif; ?>

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
			<h3 id="sscribe-log-modal-title">
			<?php echo wp_kses( SScribe_Helpers::get_icon_inline( 'file-log', 16 ), SScribe_Helpers::get_svg_kses_allowed_html() ); ?>
				<?php esc_html_e( 'Export Log', 'sscribe-export-site-pages' ); ?>
			</h3>
			<button type="button" class="sscribe-modal-close" id="sscribe-modal-close" aria-label="<?php esc_attr_e( 'Close modal', 'sscribe-export-site-pages' ); ?>">
				<span aria-hidden="true">&times;</span>
			</button>
		</div>
		<div class="sscribe-modal-body" id="sscribe-log-content" aria-live="polite">
			<div class="sscribe-log-loading">
				<img src="<?php echo esc_url( SScribe_Helpers::icon_url( 'loader' ) ); ?>" width="24" height="24" alt="" class="sscribe-spinner-img" aria-hidden="true">
				<span><?php esc_html_e( 'Loading log...', 'sscribe-export-site-pages' ); ?></span>
			</div>
		</div>
		<div class="sscribe-modal-footer">
			<button type="button" class="sscribe-button sscribe-button-outline sscribe-modal-close-btn" data-close-modal="sscribe-log-modal"><?php esc_html_e( 'Close', 'sscribe-export-site-pages' ); ?></button>
		</div>
		<span id="sscribe-log-modal-desc" class="screen-reader-text"><?php esc_html_e( 'Export log details showing processing information for this export', 'sscribe-export-site-pages' ); ?></span>
	</div>
</div>

<div id="sscribe-confirm-modal" class="sscribe-modal sscribe-hidden" role="alertdialog" aria-modal="true" aria-hidden="true" aria-labelledby="sscribe-confirm-title" aria-describedby="sscribe-confirm-desc" hidden>
	<div class="sscribe-modal-content sscribe-modal-content-confirm" role="document">
		<div class="sscribe-modal-header sscribe-confirm-header">
			<div class="sscribe-confirm-icon" aria-hidden="true">
				<?php
				echo wp_kses_post( SScribe_Helpers::get_icon( 'warning-circle', 22 ) );
				?>
			</div>
			<div class="sscribe-confirm-titles">
				<h3 id="sscribe-confirm-title"><?php esc_html_e( 'Confirm action', 'sscribe-export-site-pages' ); ?></h3>
				<p id="sscribe-confirm-desc" class="sscribe-confirm-desc"><?php esc_html_e( 'Are you sure?', 'sscribe-export-site-pages' ); ?></p>
			</div>
		</div>
		<div id="sscribe-confirm-body" class="sscribe-modal-body sscribe-confirm-body"></div>
		<div class="sscribe-modal-footer sscribe-confirm-footer">
			<button type="button" id="sscribe-confirm-cancel" class="sscribe-button sscribe-button-outline">
				<?php esc_html_e( 'Cancel', 'sscribe-export-site-pages' ); ?>
			</button>
			<button type="button" id="sscribe-confirm-proceed" class="sscribe-button sscribe-button-danger">
				<?php esc_html_e( 'Proceed', 'sscribe-export-site-pages' ); ?>
			</button>
		</div>
	</div>
</div>

</div><!-- .sscribe-master-container -->
