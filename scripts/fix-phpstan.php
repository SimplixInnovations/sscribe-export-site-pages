<?php
/**
 * SScribe Fix PHPStan Script
 *
 * @package SScribe_Export_Site_Pages
 */
$f = file_get_contents( dirname( __DIR__ ) . '/phpstan.neon' );
$f = str_replace( 'did_action|wp_redirect)', 'did_action|wp_safe_redirect|wp_redirect)', $f );
file_put_contents( dirname( __DIR__ ) . '/phpstan.neon', $f );
echo "Done\n";
