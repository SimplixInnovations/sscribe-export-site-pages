<?php
/**
 * Normalize Strauss's generated Composer autoloader suffix.
 *
 * Strauss 0.28.1 generates a fresh random 32-hex suffix when creating a new
 * vendor-prefixed/autoload.php tree. That makes otherwise identical clean
 * release builds byte-different. SScribe replaces only the generated
 * ComposerAutoloaderInit... / ComposerStaticInit... class suffix with one stable,
 * plugin-unique identifier after Strauss completes.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( "This script must be run from the command line.\n" );
}

$root          = dirname( __DIR__ );
$target_root   = $root . '/vendor-prefixed';
$stable_suffix = 'SScribeExportSitePages';

$files = array(
	$target_root . '/autoload.php',
	$target_root . '/composer/autoload_real.php',
	$target_root . '/composer/autoload_static.php',
);

foreach ( $files as $path ) {
	if ( ! is_file( $path ) ) {
		fwrite( STDERR, "ERROR: expected Strauss autoloader file is missing: {$path}\n" );
		exit( 1 );
	}
}

/**
 * @return array<string, string>
 */
$read_sources = static function ( array $paths ): array {
	$sources = array();

	foreach ( $paths as $path ) {
		$content = file_get_contents( $path );
		if ( false === $content ) {
			throw new RuntimeException( "Unable to read {$path}." );
		}
		$sources[ $path ] = $content;
	}

	return $sources;
};

/**
 * @return list<string>
 */
$discover_suffixes = static function ( array $sources ): array {
	$suffixes = array();

	foreach ( $sources as $content ) {
		if ( preg_match_all( '/\\bComposer(?:AutoloaderInit|StaticInit)([A-Za-z0-9_]+)\\b/', $content, $matches ) ) {
			foreach ( $matches[1] as $suffix ) {
				$suffixes[ (string) $suffix ] = true;
			}
		}
	}

	return array_keys( $suffixes );
};

try {
	$sources  = $read_sources( $files );
	$suffixes = $discover_suffixes( $sources );
} catch ( Throwable $e ) {
	fwrite( STDERR, "ERROR: {$e->getMessage()}\n" );
	exit( 1 );
}

if ( 1 !== count( $suffixes ) ) {
	fwrite(
		STDERR,
		'ERROR: expected exactly one Strauss Composer autoloader suffix across the generated files; found '
		. count( $suffixes ) . ': ' . implode( ', ', $suffixes ) . "\n"
	);
	exit( 1 );
}

$current_suffix = $suffixes[0];

if ( $stable_suffix === $current_suffix ) {
	echo "[strauss-autoload] Stable suffix already present: {$stable_suffix}\n";
	exit( 0 );
}

if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $current_suffix ) ) {
	fwrite(
		STDERR,
		"ERROR: refusing to rewrite unexpected Strauss suffix '{$current_suffix}'. "
		. "Expected a 32-character lowercase hexadecimal value or '{$stable_suffix}'.\n"
	);
	exit( 1 );
}

$replacements = array(
	'ComposerAutoloaderInit' . $current_suffix => 'ComposerAutoloaderInit' . $stable_suffix,
	'ComposerStaticInit' . $current_suffix     => 'ComposerStaticInit' . $stable_suffix,
);

$changed = 0;
foreach ( $sources as $path => $content ) {
	$updated = strtr( $content, $replacements );
	if ( $updated === $content ) {
		continue;
	}

	if ( false === file_put_contents( $path, $updated ) ) {
		fwrite( STDERR, "ERROR: unable to write normalized autoloader file: {$path}\n" );
		exit( 1 );
	}
	++$changed;
}

if ( 3 !== $changed ) {
	fwrite(
		STDERR,
		"ERROR: expected all three generated autoloader files to change; changed {$changed}. "
		. "Strauss/Composer output shape may have changed.\n"
	);
	exit( 1 );
}

try {
	$normalized_sources  = $read_sources( $files );
	$normalized_suffixes = $discover_suffixes( $normalized_sources );
} catch ( Throwable $e ) {
	fwrite( STDERR, "ERROR: {$e->getMessage()}\n" );
	exit( 1 );
}

if ( array( $stable_suffix ) !== $normalized_suffixes ) {
	fwrite(
		STDERR,
		'ERROR: normalized Strauss autoloader files do not contain exactly the stable suffix. Found: '
		. implode( ', ', $normalized_suffixes ) . "\n"
	);
	exit( 1 );
}

foreach ( $normalized_sources as $path => $content ) {
	if ( false !== strpos( $content, $current_suffix ) ) {
		fwrite( STDERR, "ERROR: random Strauss suffix remains in {$path}.\n" );
		exit( 1 );
	}
}

echo "[strauss-autoload] Normalized random suffix {$current_suffix} -> {$stable_suffix} in {$changed} files.\n";
exit( 0 );
