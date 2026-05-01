<?php
/**
 * JS Minifier Script
 *
 * Minifies the admin JS file for production use.
 *
 * @package SScribe
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) && php_sapi_name() !== 'cli' ) {
	exit;
}

$input  = dirname( __DIR__ ) . '/admin/js/sscribe-admin.js';
$output = dirname( __DIR__ ) . '/admin/js/sscribe-admin.min.js';
$js     = file_get_contents( $input );

// Remove single-line comments (but preserve URLs like http://)
$js = preg_replace( '~(?<!:)//.*$~m', '', $js );

// Remove multi-line comments
$js = preg_replace( '~/\*[\s\S]*?\*/~', '', $js );

// Remove unnecessary whitespace (preserve necessary spaces)
$js = preg_replace( '/\s+/', ' ', $js );

// Remove spaces around operators and brackets (exclude () to preserve string literals)
$js = preg_replace( '/\s*([{};:,=\[\]<>&|!+\-*\/])\s*/', '$1', $js );

// Restore necessary spaces (keywords like return, var, etc.)
$keywords = array( 'return', 'var', 'let', 'const', 'if', 'else', 'for', 'while', 'function', 'new', 'typeof', 'instanceof' );
foreach ( $keywords as $kw ) {
	$js = preg_replace( '/\b' . $kw . '\(/', $kw . ' (', $js );
	$js = preg_replace( '/\)' . $kw . '\b/', ') ' . $kw, $js );
}

$js = trim( $js );
file_put_contents( $output, $js );
echo "Done: $output (" . strlen( $js ) . " bytes)\n";
