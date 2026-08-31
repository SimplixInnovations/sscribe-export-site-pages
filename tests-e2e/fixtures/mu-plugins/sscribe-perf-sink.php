<?php
/**
 * Plugin Name: SScribe Perf Sink (M3 only — never ships)
 * Description: Hooks sscribe_after_export_page → writes JSONL into
 *              wp-content/uploads/sscribe-perf/{session}.jsonl so the
 *              Playwright test thread can fetch() the samples across the
 *              WP-Playground WASM sandbox boundary.
 *
 * Loaded via blueprint.json's mu-plugins step. Lives in tests-e2e/, not
 * in the shipped plugin.
 */

add_action( 'sscribe_after_export_page', function (
    $page_id,
    $formats,
    $export_success,
    $elapsed_ms = null,
    $peak_mem_bytes = null
) {
    if ( empty( $_REQUEST['sscribe_perf_session'] ) ) {
        return;
    }
    $session = sanitize_key( wp_unslash( $_REQUEST['sscribe_perf_session'] ) );
    if ( '' === $session ) {
        return;
    }
    $dir = WP_CONTENT_DIR . '/uploads/sscribe-perf';
    if ( ! is_dir( $dir ) ) {
        wp_mkdir_p( $dir );
    }
    $line = wp_json_encode( array(
        'page_id'        => (int) $page_id,
        'formats'        => (array) $formats,
        'success'        => (bool) $export_success,
        'elapsed_ms'     => is_numeric( $elapsed_ms ) ? (float) $elapsed_ms : null,
        'peak_mem_bytes' => is_numeric( $peak_mem_bytes ) ? (int) $peak_mem_bytes : null,
        'ts'             => microtime( true ),
    ) ) . "\n";
    // LOCK_EX is a no-op on WP-Playground (the fs-ext stub), but harmless.
    file_put_contents( "$dir/$session.jsonl", $line, FILE_APPEND | LOCK_EX );
}, 10, 5 );