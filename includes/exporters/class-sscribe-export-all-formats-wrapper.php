<?php
/**
 * SScribe Export All Formats Wrapper
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Export all formats" wrapper.
 *
 * Convenience dispatcher that fans an export out to every supported
 * format with per-format error isolation. A failure in one format
 * never aborts the others : each format's outcome is returned in
 * the result array keyed by format string.
 *
 * The wrapper is a pure utility: it does not own session state, file
 * handles, or retry logic. The existing SScribe_Batch_Processor's
 * dispatch_formats() continues to own the production path; this
 * wrapper is a lighter, easier-to-test entry point for callers that
 * want a single "give me everything" call.
 */
class SScribe_Export_All_Formats_Wrapper {

	/**
	 * Export a single page to every supported (or explicitly-listed) format.
	 *
	 * @param array      $page_data      Page content + metadata.
	 * @param string     $output_dir     Output directory for the produced files.
	 * @param int        $index          1-based page index.
	 * @param int        $total          Total page count.
	 * @param array|null $formats        Optional format whitelist. Defaults to
	 *                                   every format returned by
	 *                                   SScribe_Export_Format::get_supported_formats().
	 * @param array      $format_options Optional per-format options map piped
	 *                                   through `sscribe_export_options_{$format}`
	 *                                   then forwarded via each exporter's
	 *                                   apply_format_options() hook (same contract
	 *                                   as SScribe_Batch_Processor::dispatch_formats()).
	 *                                   Pass an empty array (the default) to keep
	 *                                   the historical "no options" behaviour.
	 * @return array<string, array{success: bool, result: SScribe_Result, error: ?string}>
	 *                Per-format results keyed by format string.
	 */
	public static function export_page(
		array $page_data,
		string $output_dir,
		int $index = 1,
		int $total = 1,
		?array $formats = null,
		array $format_options = array()
	): array {
		if ( null === $formats || empty( $formats ) ) {
			$formats = array_keys( SScribe_Export_Format::get_supported_formats() );
		}

		$normalized_formats = array();
		foreach ( array_slice( $formats, 0, 10 ) as $format ) {
			if ( is_scalar( $format ) ) {
				$normalized_formats[] = strtolower( sanitize_key( (string) $format ) );
			}
		}
		$formats = array_values( array_unique( array_filter( $normalized_formats ) ) );

		$results = array();

		if ( '' === $output_dir || ! is_dir( $output_dir ) || ! wp_is_writable( $output_dir ) ) {
			$shared_failure = SScribe_Result::failure(
				__( 'Cannot export because the output directory is unavailable.', 'sscribe-export-site-pages' ),
				array( 'error_category' => 'output_dir_unavailable' )
			);

			foreach ( $formats as $format ) {
				$results[ $format ] = array(
					'success' => false,
					'result'  => $shared_failure,
					'error'   => $shared_failure->get_error(),
				);
			}

			return $results;
		}

		foreach ( $formats as $format ) {
			$format = (string) $format;

			if ( ! SScribe_Exporter_Factory::is_supported( $format ) ) {
				$results[ $format ] = array(
					'success' => false,
					'result'  => SScribe_Result::failure(
						sprintf( 'Unsupported export format: %s', $format )
					),
					'error'   => sprintf( 'Unsupported export format: %s', $format ),
				);
				continue;
			}

			try {
				$exporter = SScribe_Exporter_Factory::create( $format );

				// Per-format options piping. Mirrors the batch processor
				// contract so callers using the wrapper get the same
				// sscribe_export_options_{$format} hook and per-exporter
				// apply_format_options() plumbing the production path uses.
				// Without this, exporter format_options reads (e.g. the
				// Markdown exporter's frontmatter / featured_image /
				// absolute_urls toggles) silently fall back to defaults
				// instead of honouring the caller's options map.
				//
				// The wrapper is intentionally more conservative than the
				// production batch-processor: when the caller passed an
				// empty $format_options map we skip BOTH the filter and
				// apply_format_options(). The batch-processor always calls
				// apply_format_options() so the exporter can install its
				// built-in defaults. The wrapper is a preview path, not a
				// production export, so it does not need that step.
				if ( ! empty( $format_options ) ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Per-format dynamic hook, mirrors SScribe_Batch_Processor.
					$page_id                   = isset( $page_data['id'] ) && is_numeric( $page_data['id'] ) ? absint( $page_data['id'] ) : 0;
					$format_options_for_format = apply_filters( "sscribe_export_options_{$format}", $format_options, $page_id, 'wrapper' );
					if ( method_exists( $exporter, 'apply_format_options' ) ) {
						$exporter->apply_format_options( is_array( $format_options_for_format ) ? $format_options_for_format : array() );
					}
				}

				$result = $exporter->export( $page_data, $output_dir, $index, $total );
			} catch ( \Throwable $e ) {

				if ( class_exists( 'SScribe_Logger', false ) ) {
					SScribe_Logger::instance()->error(
						sprintf( 'All-formats wrapper: %s export failed', $format ),
						array(
							'format'    => $format,
							'exception' => get_class( $e ),
							'message'   => $e->getMessage(),
							'file'      => basename( (string) $e->getFile() ) . ':' . $e->getLine(),
						)
					);
				}
				$result = SScribe_Result::failure(
					__( 'The requested format could not be generated.', 'sscribe-export-site-pages' ),
					array( 'error_category' => 'format_generation' )
				);
			}

			$results[ $format ] = array(
				'success' => $result->is_success(),
				'result'  => $result,
				'error'   => $result->is_failure() ? $result->get_error() : null,
			);
		}

		return $results;
	}

	/**
	 * Get a flat list of formats that succeeded in a wrapper result set.
	 *
	 * Convenience for callers that want to ask "which formats produced
	 * output" without walking the full result map.
	 *
	 * @param array $results Wrapper output from export_page().
	 * @return array<string> Format strings that returned success.
	 */
	public static function successful_formats( array $results ): array {
		$successes = array();
		foreach ( $results as $format => $entry ) {
			if ( ! empty( $entry['success'] ) ) {
				$successes[] = (string) $format;
			}
		}
		return $successes;
	}

	/**
	 * Get a flat list of formats that failed in a wrapper result set.
	 *
	 * @param array $results Wrapper output from export_page().
	 * @return array<string, string> Map of failed format → error message.
	 */
	public static function failed_formats( array $results ): array {
		$failures = array();
		foreach ( $results as $format => $entry ) {
			if ( empty( $entry['success'] ) ) {
				$failures[ (string) $format ] = (string) ( $entry['error'] ?? 'unknown error' );
			}
		}
		return $failures;
	}
}
