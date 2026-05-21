<?php
/**
 * SScribe Error Handler
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SScribe_Error {

	public const CATEGORY_SYSTEM     = 'system';
	public const CATEGORY_PERMISSION = 'permission';
	public const CATEGORY_RESOURCE   = 'resource';
	public const CATEGORY_CONTENT    = 'content';
	public const CATEGORY_EXPORT     = 'export';
	public const CATEGORY_NETWORK    = 'network';
	public const CATEGORY_VALIDATION = 'validation';

	public const SEVERITY_CRITICAL = 'critical';
	public const SEVERITY_ERROR    = 'error';
	public const SEVERITY_WARNING  = 'warning';
	public const SEVERITY_INFO     = 'info';

	/**
	 * Error code identifier.
	 *
	 * @var string
	 */
	private readonly string $code;

	/**
	 * Error category.
	 *
	 * @var string
	 */
	private readonly string $category;

	/**
	 * Error severity level.
	 *
	 * @var string
	 */
	private readonly string $severity;

	/**
	 * Human-readable error message.
	 *
	 * @var string
	 */
	private readonly string $message;

	/**
	 * Detailed error description.
	 *
	 * @var string
	 */
	private readonly string $details;

	/**
	 * User guidance for resolving the error.
	 *
	 * @var string
	 */
	private readonly string $guidance;

	/**
	 * Step-by-step fix instructions.
	 *
	 * @var array<string>
	 */
	private readonly array $fix_steps;

	/**
	 * Documentation URL for further reading.
	 *
	 * @var string|null
	 */
	private readonly ?string $doc_url;

	/**
	 * Additional error context.
	 *
	 * @var array
	 */
	private readonly array $context;

	/**
	 * Unix timestamp when the error was created.
	 *
	 * @var int
	 */
	private readonly int $timestamp;

	/**
	 * Initialize the error.
	 *
	 * @param string        $code      Error code identifier.
	 * @param string        $category  Error category.
	 * @param string        $severity  Error severity level.
	 * @param string        $message   Human-readable message.
	 * @param string        $details   Detailed description.
	 * @param string        $guidance  User guidance.
	 * @param array<string> $fix_steps Fix instructions.
	 * @param string|null   $doc_url   Documentation URL.
	 * @param array         $context   Additional context.
	 */
	public function __construct(
		string $code,
		string $category,
		string $severity,
		string $message,
		string $details = '',
		string $guidance = '',
		array $fix_steps = array(),
		?string $doc_url = null,
		array $context = array()
	) {
		$this->code      = $code;
		$this->category  = $category;
		$this->severity  = $severity;
		$this->message   = $message;
		$this->details   = $details;
		$this->guidance  = $guidance;
		$this->fix_steps = $fix_steps;
		$this->doc_url   = $doc_url;
		$this->context   = $context;
		$this->timestamp = time();
	}

	/**
	 * Create an error from a predefined template.
	 *
	 * @param string $code    Error code.
	 * @param array  $context Context for template interpolation.
	 * @return self
	 */
	public static function from_template( string $code, array $context = array() ): self {
		$templates = self::get_templates();

		if ( ! isset( $templates[ $code ] ) ) {
			return new self(
				'UNKNOWN_ERROR',
				self::CATEGORY_SYSTEM,
				self::SEVERITY_ERROR,
				'An unknown error occurred.',
				'',
				'Please contact support with the error details.',
				array(),
				null,
				$context
			);
		}

		$template = $templates[ $code ];

		$message  = self::interpolate( $template['message'], $context );
		$details  = self::interpolate( $template['details'] ?? '', $context );
		$guidance = self::interpolate( $template['guidance'] ?? '', $context );

		return new self(
			$code,
			$template['category'],
			$template['severity'],
			$message,
			$details,
			$guidance,
			$template['fix_steps'] ?? array(),
			$template['doc_url'] ?? null,
			$context
		);
	}

	/**
	 * Interpolate placeholders in a template string.
	 *
	 * @param string $template Template with {key} placeholders.
	 * @param array  $context  Values for interpolation.
	 * @return string
	 */
	public static function interpolate( string $template, array $context ): string {
		$replace = array();
		foreach ( $context as $key => $value ) {
			$replace[ '{' . $key . '}' ] = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
		}
		return strtr( $template, $replace );
	}

	/**
	 * Get all predefined error templates.
	 *
	 * @return array
	 */
	public static function get_templates(): array {
		return array(

			'SYSTEM_ZIP_EXTENSION_MISSING'      => array(
				'category'  => self::CATEGORY_SYSTEM,
				'severity'  => self::SEVERITY_CRITICAL,
				'message'   => __( 'ZIP extension is not available on this server.', 'sscribe-export-site-pages' ),
				'details'   => __( 'The ZipArchive PHP extension is required to create export packages.', 'sscribe-export-site-pages' ),
				'guidance'  => __( 'Contact your hosting provider to enable the ZipArchive extension.', 'sscribe-export-site-pages' ),
				'fix_steps' => array(
					__( 'Contact your hosting provider or system administrator.', 'sscribe-export-site-pages' ),
					__( 'Request enabling the ZipArchive PHP extension.', 'sscribe-export-site-pages' ),
					__( 'Restart the web server after the change.', 'sscribe-export-site-pages' ),
				),
			),

			'SYSTEM_DOM_EXTENSION_MISSING'      => array(
				'category'  => self::CATEGORY_SYSTEM,
				'severity'  => self::SEVERITY_CRITICAL,
				'message'   => __( 'DOM extension is not available on this server.', 'sscribe-export-site-pages' ),
				'details'   => __( 'The DOMDocument PHP extension is required for HTML parsing and PDF generation.', 'sscribe-export-site-pages' ),
				'guidance'  => __( 'Contact your hosting provider to enable the DOM extension.', 'sscribe-export-site-pages' ),
				'fix_steps' => array(
					__( 'Contact your hosting provider or system administrator.', 'sscribe-export-site-pages' ),
					__( 'Request enabling the DOM PHP extension in php.ini.', 'sscribe-export-site-pages' ),
				),
			),

			'SYSTEM_MBSTRING_MISSING'           => array(
				'category'  => self::CATEGORY_SYSTEM,
				'severity'  => self::SEVERITY_ERROR,
				'message'   => __( 'Multibyte string extension is not available.', 'sscribe-export-site-pages' ),
				'details'   => __( 'The mbstring extension is recommended for proper Unicode handling.', 'sscribe-export-site-pages' ),
				'guidance'  => __( 'Contact your hosting provider to enable mbstring for better Unicode support.', 'sscribe-export-site-pages' ),
				'fix_steps' => array(
					__( 'Contact your hosting provider.', 'sscribe-export-site-pages' ),
					__( 'Request enabling the mbstring PHP extension.', 'sscribe-export-site-pages' ),
				),
			),

			'RESOURCE_MEMORY_EXHAUSTED'         => array(
				'category'  => self::CATEGORY_RESOURCE,
				'severity'  => self::SEVERITY_CRITICAL,
				'message'   => __( 'Server memory limit reached. Cannot complete export.', 'sscribe-export-site-pages' ),
				'details'   => __( 'Memory usage: {memory_usage} / {memory_limit}. The export requires more memory than available.', 'sscribe-export-site-pages' ),
				'guidance'  => __( 'Try exporting fewer pages at once, or contact your host to increase PHP memory limit.', 'sscribe-export-site-pages' ),
				'fix_steps' => array(
					__( 'Export fewer pages by filtering by language or status.', 'sscribe-export-site-pages' ),
					__( 'Contact your hosting provider to increase memory_limit in php.ini.', 'sscribe-export-site-pages' ),
					__( 'Recommended: 256MB or higher for large exports.', 'sscribe-export-site-pages' ),
				),
			),

			'RESOURCE_DISK_SPACE_LOW'           => array(
				'category'  => self::CATEGORY_RESOURCE,
				'severity'  => self::SEVERITY_CRITICAL,
				'message'   => __( 'Insufficient disk space for export.', 'sscribe-export-site-pages' ),
				'details'   => __( 'Available: {disk_free}. Required: approximately {disk_required}.', 'sscribe-export-site-pages' ),
				'guidance'  => __( 'Free up disk space or export fewer pages.', 'sscribe-export-site-pages' ),
				'fix_steps' => array(
					__( 'Delete old export files from the history.', 'sscribe-export-site-pages' ),
					__( 'Clear WordPress cache and temporary files.', 'sscribe-export-site-pages' ),
					__( 'Contact your hosting provider to increase storage.', 'sscribe-export-site-pages' ),
				),
			),

			'RESOURCE_EXECUTION_TIMEOUT'        => array(
				'category'  => self::CATEGORY_RESOURCE,
				'severity'  => self::SEVERITY_ERROR,
				'message'   => __( 'Export timed out due to server limits.', 'sscribe-export-site-pages' ),
				'details'   => __( 'Max execution time: {max_time}s. The server terminated the export process.', 'sscribe-export-site-pages' ),
				'guidance'  => __( 'The export will continue in the background. Refresh the page to see progress.', 'sscribe-export-site-pages' ),
				'fix_steps' => array(
					__( 'Wait a few moments and refresh the page.', 'sscribe-export-site-pages' ),
					__( 'If the issue persists, export fewer pages at once.', 'sscribe-export-site-pages' ),
				),
			),

			'PERMISSION_DIRECTORY_NOT_WRITABLE' => array(
				'category'  => self::CATEGORY_PERMISSION,
				'severity'  => self::SEVERITY_CRITICAL,
				'message'   => __( 'Cannot write to export directory.', 'sscribe-export-site-pages' ),
				'details'   => __( 'Directory: {directory}. The web server cannot write files to this location.', 'sscribe-export-site-pages' ),
				'guidance'  => __( 'Contact your hosting provider to fix directory permissions.', 'sscribe-export-site-pages' ),
				'fix_steps' => array(
					__( 'Contact your hosting provider or system administrator.', 'sscribe-export-site-pages' ),
					__( 'Request setting permissions 755 for directories, 644 for files.', 'sscribe-export-site-pages' ),
					__( 'Ensure the web server user owns the uploads directory.', 'sscribe-export-site-pages' ),
				),
			),

			'PERMISSION_TEMP_DIRECTORY_FAILED'  => array(
				'category'  => self::CATEGORY_PERMISSION,
				'severity'  => self::SEVERITY_CRITICAL,
				'message'   => __( 'Failed to create temporary directory for export.', 'sscribe-export-site-pages' ),
				'details'   => __( 'Attempted path: {path}. Error: {error}', 'sscribe-export-site-pages' ),
				'guidance'  => __( 'Check that wp-content/uploads is writable by the web server.', 'sscribe-export-site-pages' ),
				'fix_steps' => array(
					__( 'Verify wp-content/uploads directory exists and is writable.', 'sscribe-export-site-pages' ),
					__( 'Check file permissions (should be 755).', 'sscribe-export-site-pages' ),
					__( 'Contact hosting support if the issue persists.', 'sscribe-export-site-pages' ),
				),
			),

			'CONTENT_PAGE_DATA_FAILED'          => array(
				'category'  => self::CATEGORY_CONTENT,
				'severity'  => self::SEVERITY_ERROR,
				'message'   => __( 'Failed to retrieve data for page "{title}" (ID: {page_id}).', 'sscribe-export-site-pages' ),
				'details'   => __( 'The page exists but data collection failed. This may indicate corrupted content or missing dependencies.', 'sscribe-export-site-pages' ),
				'guidance'  => __( 'Check if the page has unusual content or uses a page builder with issues.', 'sscribe-export-site-pages' ),
				'fix_steps' => array(
					__( 'Edit the page in WordPress admin to verify content.', 'sscribe-export-site-pages' ),
					__( 'If using a page builder, ensure it is properly configured.', 'sscribe-export-site-pages' ),
					__( 'Try publishing a simple test page to verify exports work.', 'sscribe-export-site-pages' ),
				),
			),

			'CONTENT_EMPTY_PAGE'                => array(
				'category'  => self::CATEGORY_CONTENT,
				'severity'  => self::SEVERITY_WARNING,
				'message'   => __( 'Page "{title}" (ID: {page_id}) has no content.', 'sscribe-export-site-pages' ),
				'details'   => __( 'The page content area is empty. This will result in a blank document.', 'sscribe-export-site-pages' ),
				'guidance'  => __( 'Add content to the page or exclude it from export.', 'sscribe-export-site-pages' ),
				'fix_steps' => array(
					__( 'Edit the page and add content.', 'sscribe-export-site-pages' ),
					__( 'Check if page builder content is properly saved.', 'sscribe-export-site-pages' ),
				),
			),

			'CONTENT_PAGE_NOT_FOUND'            => array(
				'category'  => self::CATEGORY_CONTENT,
				'severity'  => self::SEVERITY_ERROR,
				'message'   => __( 'Page with ID {page_id} no longer exists.', 'sscribe-export-site-pages' ),
				'details'   => __( 'The page was deleted during the export process.', 'sscribe-export-site-pages' ),
				'guidance'  => __( 'This page will be skipped. The export will continue.', 'sscribe-export-site-pages' ),
				'fix_steps' => array(
					__( 'This is informational - the page was deleted during export.', 'sscribe-export-site-pages' ),
					__( 'No action needed unless this was unexpected.', 'sscribe-export-site-pages' ),
				),
			),

			'EXPORT_DOCX_FAILED'                => array(
				'category'  => self::CATEGORY_EXPORT,
				'severity'  => self::SEVERITY_ERROR,
				'message'   => __( 'Failed to generate DOCX for page "{title}".', 'sscribe-export-site-pages' ),
				'details'   => __( 'Error: {error}. This may be due to complex content or memory limits.', 'sscribe-export-site-pages' ),
				'guidance'  => __( 'Try exporting other formats first. DOCX may struggle with very complex pages.', 'sscribe-export-site-pages' ),
				'fix_steps' => array(
					__( 'Try exporting without DOCX format.', 'sscribe-export-site-pages' ),
					__( 'Check if the page has extremely long content.', 'sscribe-export-site-pages' ),
					__( 'Contact support with the page ID for investigation.', 'sscribe-export-site-pages' ),
				),
			),

			'EXPORT_PDF_FAILED'                 => array(
				'category'  => self::CATEGORY_EXPORT,
				'severity'  => self::SEVERITY_ERROR,
				'message'   => __( 'Failed to generate PDF for page "{title}".', 'sscribe-export-site-pages' ),
				'details'   => __( 'Error: {error}. PDF generation requires DOM extension and sufficient memory.', 'sscribe-export-site-pages' ),
				'guidance'  => __( 'PDF generation is resource-intensive. Try other formats if this fails.', 'sscribe-export-site-pages' ),
				'fix_steps' => array(
					__( 'Ensure the DOM extension is enabled.', 'sscribe-export-site-pages' ),
					__( 'Try exporting without PDF format.', 'sscribe-export-site-pages' ),
					__( 'Check if the page has embedded media that might cause issues.', 'sscribe-export-site-pages' ),
				),
			),

			'EXPORT_ZIP_CREATION_FAILED'        => array(
				'category'  => self::CATEGORY_EXPORT,
				'severity'  => self::SEVERITY_CRITICAL,
				'message'   => __( 'Failed to create ZIP package for download.', 'sscribe-export-site-pages' ),
				'details'   => __( 'All pages were processed but the ZIP could not be created. Error: {error}', 'sscribe-export-site-pages' ),
				'guidance'  => __( 'Individual files may be available in the temp directory. Contact support.', 'sscribe-export-site-pages' ),
				'fix_steps' => array(
					__( 'Verify the ZipArchive extension is enabled.', 'sscribe-export-site-pages' ),
					__( 'Check disk space in the uploads directory.', 'sscribe-export-site-pages' ),
					__( 'Contact support with error details.', 'sscribe-export-site-pages' ),
				),
			),

			'VALIDATION_NO_PAGES_SELECTED'      => array(
				'category'  => self::CATEGORY_VALIDATION,
				'severity'  => self::SEVERITY_ERROR,
				'message'   => __( 'No pages match the selected criteria.', 'sscribe-export-site-pages' ),
				'details'   => __( 'Language: {language}, Status: {status}. No pages found with these filters.', 'sscribe-export-site-pages' ),
				'guidance'  => __( 'Adjust your filter criteria or create pages matching your selection.', 'sscribe-export-site-pages' ),
				'fix_steps' => array(
					__( 'Try selecting "All Languages" or "All Statuses".', 'sscribe-export-site-pages' ),
					__( 'Create pages in the selected language/status.', 'sscribe-export-site-pages' ),
					__( 'Check if WPML is properly configured if using language filters.', 'sscribe-export-site-pages' ),
				),
			),

			'VALIDATION_INVALID_LANGUAGE'       => array(
				'category'  => self::CATEGORY_VALIDATION,
				'severity'  => self::SEVERITY_ERROR,
				'message'   => __( 'Invalid language code: {language}.', 'sscribe-export-site-pages' ),
				'details'   => __( 'The selected language does not exist in your WPML configuration.', 'sscribe-export-site-pages' ),
				'guidance'  => __( 'Select a valid language from the available options.', 'sscribe-export-site-pages' ),
				'fix_steps' => array(
					__( 'Refresh the page to see available languages.', 'sscribe-export-site-pages' ),
					__( 'Verify WPML is properly configured.', 'sscribe-export-site-pages' ),
				),
			),

			'VALIDATION_INVALID_FORMAT'         => array(
				'category'  => self::CATEGORY_VALIDATION,
				'severity'  => self::SEVERITY_ERROR,
				'message'   => __( 'Invalid export format: {format}.', 'sscribe-export-site-pages' ),
				'details'   => __( 'Supported formats: DOCX, PDF, HTML, Markdown.', 'sscribe-export-site-pages' ),
				'guidance'  => __( 'Select at least one valid export format.', 'sscribe-export-site-pages' ),
				'fix_steps' => array(
					__( 'Select DOCX, PDF, HTML, or Markdown.', 'sscribe-export-site-pages' ),
					__( 'Refresh the page if format options are not showing.', 'sscribe-export-site-pages' ),
				),
			),

			'SESSION_EXPIRED'                   => array(
				'category'  => self::CATEGORY_SYSTEM,
				'severity'  => self::SEVERITY_ERROR,
				'message'   => __( 'Export session has expired.', 'sscribe-export-site-pages' ),
				'details'   => __( 'Session ID: {session_id}. Sessions expire after 72 hours of inactivity.', 'sscribe-export-site-pages' ),
				'guidance'  => __( 'Start a new export. Previous progress has been saved to the log.', 'sscribe-export-site-pages' ),
				'fix_steps' => array(
					__( 'Click "Generate Documentation Package" to start a new export.', 'sscribe-export-site-pages' ),
					__( 'Check the export log for details on what was completed.', 'sscribe-export-site-pages' ),
				),
			),

			'SESSION_LOCK_CONFLICT'             => array(
				'category'  => self::CATEGORY_SYSTEM,
				'severity'  => self::SEVERITY_WARNING,
				'message'   => __( 'Another export is in progress.', 'sscribe-export-site-pages' ),
				'details'   => __( 'Session: {session_id}. Wait for the current batch to complete.', 'sscribe-export-site-pages' ),
				'guidance'  => __( 'Wait a few seconds and try again. The system will retry automatically.', 'sscribe-export-site-pages' ),
				'fix_steps' => array(
					__( 'Wait 10-15 seconds for the current batch to complete.', 'sscribe-export-site-pages' ),
					__( 'If stuck, click "Clear Session" to reset.', 'sscribe-export-site-pages' ),
				),
			),

			'SESSION_CORRUPTED'                 => array(
				'category'  => self::CATEGORY_SYSTEM,
				'severity'  => self::SEVERITY_ERROR,
				'message'   => __( 'Export session data is corrupted.', 'sscribe-export-site-pages' ),
				'details'   => __( 'Missing: {missing_fields}. Cannot continue this export.', 'sscribe-export-site-pages' ),
				'guidance'  => __( 'Start a new export. This session cannot be recovered.', 'sscribe-export-site-pages' ),
				'fix_steps' => array(
					__( 'Click "Generate Documentation Package" to start fresh.', 'sscribe-export-site-pages' ),
					__( 'If this happens repeatedly, contact support.', 'sscribe-export-site-pages' ),
				),
			),

			'RATE_LIMIT_EXCEEDED'               => array(
				'category'  => self::CATEGORY_SYSTEM,
				'severity'  => self::SEVERITY_WARNING,
				'message'   => __( 'Too many requests. Please slow down.', 'sscribe-export-site-pages' ),
				'details'   => __( 'Rate limit: {limit} requests per {window} seconds.', 'sscribe-export-site-pages' ),
				'guidance'  => __( 'Wait a moment before trying again. This protects your server.', 'sscribe-export-site-pages' ),
				'fix_steps' => array(
					__( 'Wait 5-10 seconds before retrying.', 'sscribe-export-site-pages' ),
					__( 'The export will continue automatically.', 'sscribe-export-site-pages' ),
				),
			),
		);
	}

	/**
	 * Get the error code.
	 *
	 * @return string
	 */
	public function get_code(): string {
		return $this->code;
	}

	/**
	 * Get the error category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return $this->category;
	}

	/**
	 * Get the error severity.
	 *
	 * @return string
	 */
	public function get_severity(): string {
		return $this->severity;
	}

	/**
	 * Get the error message.
	 *
	 * @return string
	 */
	public function get_message(): string {
		return $this->message;
	}

	/**
	 * Get the error details.
	 *
	 * @return string
	 */
	public function get_details(): string {
		return $this->details;
	}

	/**
	 * Get the error guidance.
	 *
	 * @return string
	 */
	public function get_guidance(): string {
		return $this->guidance;
	}

	/**
	 * Get the fix steps.
	 *
	 * @return array<string>
	 */
	public function get_fix_steps(): array {
		return $this->fix_steps;
	}

	/**
	 * Get the documentation URL.
	 *
	 * @return string|null
	 */
	public function get_doc_url(): ?string {
		return $this->doc_url;
	}

	/**
	 * Get the error context.
	 *
	 * @return array
	 */
	public function get_context(): array {
		return $this->context;
	}

	/**
	 * Get the error timestamp.
	 *
	 * @return int
	 */
	public function get_timestamp(): int {
		return $this->timestamp;
	}

	/**
	 * Convert the error to an array.
	 *
	 * @param bool $include_details Include detailed fields.
	 * @return array
	 */
	public function to_array( bool $include_details = false ): array {
		$data = array(
			'code'     => $this->code,
			'category' => $this->category,
			'severity' => $this->severity,
			'message'  => $this->message,
			'guidance' => $this->guidance,
		);

		if ( ! empty( $this->fix_steps ) ) {
			$data['fix_steps'] = $this->fix_steps;
		}

		if ( $this->doc_url ) {
			$data['doc_url'] = $this->doc_url;
		}

		if ( $include_details ) {
			$data['details']   = $this->details;
			$data['context']   = $this->context;
			$data['timestamp'] = $this->timestamp;
		}

		return $data;
	}

	/**
	 * Convert the error to a string representation.
	 *
	 * @return string
	 */
	public function __toString(): string {
		$output = $this->message;

		if ( $this->guidance ) {
			$output .= "\n\n" . $this->guidance;
		}

		if ( ! empty( $this->fix_steps ) ) {
			$output .= "\n\n" . __( 'Steps to fix:', 'sscribe-export-site-pages' );
			foreach ( $this->fix_steps as $i => $step ) {
				$output .= "\n" . ( $i + 1 ) . '. ' . $step;
			}
		}

		return $output;
	}
}
