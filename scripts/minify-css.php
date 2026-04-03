<?php
/**
 * CSS Minifier Script
 *
 * Minifies the admin CSS file for production use.
 *
 * @package SScribe
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) && php_sapi_name() !== 'cli' ) {
	exit;
}

$input  = dirname( __DIR__ ) . '/admin/css/sscribe-admin.css';
$output = dirname( __DIR__ ) . '/admin/css/sscribe-admin.min.css';
$css    = file_get_contents( $input );
$css    = preg_replace( '/\/\*[^*]*\*+(?:[^\/*][^\/*]*\*+)*\//', '', $css );
$css    = preg_replace( '/\s+/', ' ', $css );
$css    = preg_replace( '/\s*([{};:,>~+])\s*/', '$1', $css );
$css    = preg_replace( '/;}/', '}', $css );
$css    = trim( $css );
file_put_contents( $output, $css );
echo "Done: $output (" . strlen( $css ) . " bytes)\n";
