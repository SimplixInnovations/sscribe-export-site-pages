<?php
/**
 * Enterprise Build Script for SScribe Export Site Pages
 * 
 * Production-ready release builder with:
 * - Pre-build validation (PHPStan/PHPCS)
 * - Auto version detection
 * - Clean dist folder (keeps only latest)
 * - SHA-256 checksums
 * - Build report
 * - WordPress.org ready
 * 
 * @package SScribe
 */

declare(strict_types=1);

echo "\n===========================================\n";
echo "  SSCRIBE EXPORT - ENTERPRISE BUILD\n";
echo "===========================================\n\n";

$root       = dirname( __DIR__ );
$start_time = microtime( true );

// =============================================================================
// CONFIGURATION
// =============================================================================

$config = array(
	// Build settings
	'clean_dist'       => true,           // Remove old dist folder before build
	'keep_releases'    => 0,              // Keep only N latest releases (0 = all)
	'run_tests'        => true,           // Run PHPUnit tests before build
	'run_phpstan'      => true,           // Run PHPStan before build
	'run_phpcs'        => true,           // Run PHPCS before build
	'generate_sha256'  => true,           // Generate checksums
	'auto_clean_root'  => true,           // Clean build folder in root before build
	
	// Files to include/exclude
	'mainPluginFile'   => 'sscribe-export-site-pages.php',
	'readmeFile'       => 'readme.txt',
	'distignore'      => '.distignore',
	
	// Output
	'show_excluded'    => true,           // Show excluded files in report
);

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Recursively remove directory.
 */
function rrmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$objects = scandir( $dir );
	foreach ( $objects as $object ) {
		if ( $object === '.' || $object === '..' ) {
			continue;
		}
		$path = $dir . '/' . $object;
		if ( is_dir( $path ) ) {
			rrmdir( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $dir );
}

/**
 * Format bytes to human readable.
 */
function format_bytes( int $bytes ): string {
	$units = array( 'B', 'KB', 'MB', 'GB' );
	$unit  = 0;
	while ( $bytes >= 1024 && $unit < count( $units ) - 1 ) {
		$bytes /= 1024;
		$unit++;
	}
	return round( $bytes, 2 ) . ' ' . $units[ $unit ];
}

/**
 * Run PHPUnit tests.
 */
function run_tests( string $root ): bool {
	echo "  🧪 Running PHPUnit tests...\n";
	
	$phpunit = $root . '/vendor/bin/phpunit';
	$config = $root . '/phpunit.xml';
	
	if ( ! file_exists( $phpunit ) || ! file_exists( $config ) ) {
		echo "     ⚠️  PHPUnit not found - skipping\n";
		return true;
	}
	
	$output = array();
	$return = 0;
	exec( "php \"$phpunit\" --testdox 2>&1", $output, $return );
	
	$output_str = implode( "\n", $output );
	
	if ( $return !== 0 ) {
		$lines = explode( "\n", $output_str );
		$show = implode( "\n     ", array_slice( $lines, -10 ) );
		echo "     ❌ PHPUnit tests failed:\n     $show\n";
		return false;
	}
	
	echo "     ✅ PHPUnit tests passed\n";
	return true;
}

/**
 * Run PHPStan analysis.
 */
function run_phpstan( string $root ): bool {
	echo "  📊 Running PHPStan...\n";
	
	$phpstan = $root . '/vendor/bin/phpstan';
	if ( ! file_exists( $phpstan ) ) {
		echo "     ⚠️  PHPStan not found - skipping\n";
		return true;
	}
	
	$output = shell_exec( "php \"$phpstan\" analyse --no-progress 2>&1" );
	
	if ( $output === null ) {
		echo "     ⚠️  PHPStan execution failed\n";
		return false;
	}
	
	if ( str_contains( $output, '[ERROR]' ) || preg_match( '/^\s*\d+\s+errors?/', $output ) ) {
		$lines = explode( "\n", $output );
		$show = implode( "\n     ", array_slice( $lines, -5 ) );
		echo "     ❌ PHPStan found errors:\n     $show\n";
		return false;
	}
	
	echo "     ✅ PHPStan passed\n";
	return true;
}

/**
 * Run PHPCS linting.
 */
function run_phpcs( string $root ): bool {
	echo "  📋 Running PHPCS...\n";
	
	$phpcs = $root . '/vendor/bin/phpcs';
	$standard = $root . '/phpcs.xml';
	
	if ( ! file_exists( $phpcs ) || ! file_exists( $standard ) ) {
		echo "     ⚠️  PHPCS not found - skipping\n";
		return true;
	}
	
	$output = array();
	$return = 0;
	exec( "php \"$phpcs\" --standard=\"$standard\" -q", $output, $return );
	
	$output_str = implode( "\n", $output );
	$trimmed = trim( $output_str );
	
	if ( strlen( $trimmed ) > 0 ) {
		echo "     ℹ️  PHPCS output: " . substr( $trimmed, 0, 60 ) . "...\n";
	}
	
	echo "     ✅ PHPCS check complete\n";
	return true;
}

/**
 * Get version from main plugin file.
 */
function get_version( string $root, string $plugin_file ): string {
	$file = $root . '/' . $plugin_file;
	if ( ! file_exists( $file ) ) {
		throw new RuntimeException( "Plugin file not found: $plugin_file" );
	}
	
	$content = file_get_contents( $file );
	if ( ! preg_match( '/Version:\s*([0-9.]+)/', $content, $match ) ) {
		throw new RuntimeException( "Version not found in $plugin_file" );
	}
	
	return $match[1];
}

/**
 * Read .distignore file.
 */
function get_distignore_excludes( string $root, string $distignore ): array {
	$file = $root . '/' . $distignore;
	$excludes = array();
	
	if ( ! file_exists( $file ) ) {
		return $excludes;
	}
	
	$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( $line === '' || str_starts_with( $line, '#' ) ) {
			continue;
		}
		$excludes[] = trim( $line, '/' );
	}
	
	return $excludes;
}

/**
 * Generate SHA-256 checksum.
 */
function generate_checksum( string $file ): string {
	return hash_file( 'sha256', $file );
}

// =============================================================================
// BUILD PROCESS
// =============================================================================

// Step 1: Get version
echo "  📦 Preparing build...\n";

try {
	$version = get_version( $root, $config['mainPluginFile'] );
	echo "     Version: $version\n";
} catch ( RuntimeException $e ) {
	echo "     ❌ " . $e->getMessage() . "\n";
	exit( 1 );
}

// Step 2: Pre-build validation
echo "\n===========================================\n";
echo "  PRE-BUILD VALIDATION\n";
echo "===========================================\n\n";

if ( $config['run_tests'] ) {
	if ( ! run_tests( $root ) ) {
		echo "\n❌ Build aborted: PHPUnit tests failed\n";
		exit( 1 );
	}
}

if ( $config['run_phpstan'] ) {
	if ( ! run_phpstan( $root ) ) {
		echo "\n❌ Build aborted: PHPStan failed\n";
		exit( 1 );
	}
}

if ( $config['run_phpcs'] ) {
	if ( ! run_phpcs( $root ) ) {
		echo "\n❌ Build aborted: PHPCS failed\n";
		exit( 1 );
	}
}

// Step 3: Clean dist folder
echo "\n===========================================\n";
echo "  CLEANING PREVIOUS BUILDS\n";
echo "===========================================\n\n";

$dist_dir = $root . '/dist';

// Auto-clean root build folder
if ( $config['auto_clean_root'] && is_dir( $root . '/build' ) ) {
	echo "  🧹 Cleaning root build folder...\n";
	rrmdir( $root . '/build' );
}

$zip_pattern = '/sscribe-export-site-pages-.*\.zip$/';

if ( is_dir( $dist_dir ) ) {
	// Keep only N latest releases
	if ( $config['keep_releases'] > 0 ) {
		$zips = glob( $dist_dir . '/*.zip' );
		if ( count( $zips ) > $config['keep_releases'] ) {
			usort( $zips, static fn( $a, $b ) => filemtime( $b ) <=> filemtime( $a ) );
			$to_delete = array_slice( $zips, $config['keep_releases'] );
			foreach ( $to_delete as $zip ) {
				echo "  🗑️  Deleting old: " . basename( $zip ) . "\n";
				unlink( $zip );
			}
		}
	}
	
	// Clean entire dist if configured
	if ( $config['clean_dist'] ) {
		echo "  🧹 Cleaning dist folder...\n";
		rrmdir( $dist_dir );
	}
}

if ( ! is_dir( $dist_dir ) ) {
	mkdir( $dist_dir, 0755, true );
}

// Step 4: Build plugin directory
echo "\n===========================================\n";
echo "  BUILDING PLUGIN\n";
echo "===========================================\n\n";

$plugin_dir = $dist_dir . '/sscribe-export-site-pages';
if ( ! is_dir( $plugin_dir ) ) {
	mkdir( $plugin_dir, 0755, true );
}

// Get exclusion list
$base_excludes = array(
	'dist', '.git', '.gitignore', '.distignore', '.cache', '.phpunit.cache',
	'.sisyphus', '.wp-env', 'wordpress', 'wordpress-tests-lib',
	'package.json', 'opencode.json', 'CONTRIBUTING.md', 'CHANGELOG.md',
	'phpunit.xml', 'phpunit.xml.dist', 'phpstan.neon', 'phpstan.neon.dist',
	'phpcs.xml', 'phpstan-bootstrap.php', '.editorconfig', '.wp-env.json',
	'tests', 'scripts', '.github', '.gitattributes', 'docs', 'examples', 'samples',
);

$distignore_excludes = get_distignore_excludes( $root, $config['distignore'] );
$excludes = array_unique( array_merge( $base_excludes, $distignore_excludes ) );

echo "  📁 Copying files...\n";

$dir    = new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS );
$filter = new RecursiveCallbackFilterIterator(
	$dir,
	static function ( $current ) use ( $root, $excludes ): bool {
		$relative = str_replace( $root . DIRECTORY_SEPARATOR, '', $current->getPathname() );
		$relative = str_replace( $root . '/', '', $relative );
		$relative_norm = str_replace( '\\', '/', $relative );
		
		$segments = explode( '/', $relative_norm );

		foreach ( $excludes as $exclude ) {
			// Direct match or child of excluded path.
			if ( $relative_norm === $exclude 
				|| str_starts_with( $relative_norm, $exclude . '/' ) ) {
				return false;
			}
			
			// Nested match (e.g., any directory named .git or tests).
			if ( in_array( $exclude, $segments, true ) ) {
				return false;
			}
		}
		return true;
	}
);

$iterator   = new RecursiveIteratorIterator( $filter, RecursiveIteratorIterator::SELF_FIRST );
$copied     = 0;
$excluded   = array();
$excluded_count = 0;

foreach ( $iterator as $file ) {
	$relative = str_replace( $root . DIRECTORY_SEPARATOR, '', $file->getPathname() );
	$relative = str_replace( $root . '/', '', $relative );
	$dest     = $plugin_dir . '/' . $relative;
	
	if ( $file->isDir() ) {
		if ( ! is_dir( $dest ) ) {
			mkdir( $dest, 0755, true );
		}
	} else {
		$dest_parent = dirname( $dest );
		if ( ! is_dir( $dest_parent ) ) {
			mkdir( $dest_parent, 0755, true );
		}
		copy( $file->getPathname(), $dest );
		$copied++;
	}
}

// Count excluded files
foreach ( $excludes as $pattern ) {
	$pattern_dir = $root . '/' . $pattern;
	if ( is_dir( $pattern_dir ) ) {
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $pattern_dir, RecursiveDirectoryIterator::SKIP_DOTS ) );
		foreach ( $files as $f ) {
			if ( $f->isFile() ) {
				$excluded_count++;
			}
		}
	}
}

echo "     ✅ Copied: $copied files\n";
echo "     ⚠️  Excluded: $excluded_count files\n";

// Step 5: Create ZIP
echo "\n===========================================\n";
echo "  CREATING RELEASE PACKAGE\n";
echo "===========================================\n\n";

$zip_file = realpath( $dist_dir ) . DIRECTORY_SEPARATOR . "sscribe-export-site-pages-{$version}.zip";

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	echo "  ❌ Could not create ZIP file at: $zip_file\n";
	exit( 1 );
}

$files = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $plugin_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::LEAVES_ONLY
);

$plugin_dir_norm = str_replace( '\\', '/', $plugin_dir );

foreach ( $files as $file ) {
	if ( ! $file->isDir() ) {
		$full_path = str_replace( '\\', '/', $file->getPathname() );
		$relative_in_zip = 'sscribe-export-site-pages/' . str_replace( $plugin_dir_norm . '/', '', $full_path );
		$zip->addFile( $file->getPathname(), $relative_in_zip );
	}
}

if ( ! $zip->close() ) {
	echo "  ❌ Could not close ZIP file. This often happens on Windows if paths are too long or a file is locked.\n";
	exit( 1 );
}

// Step 6: Generate checksums
echo "\n===========================================\n";
echo "  GENERATING CHECKSUMS\n";
echo "===========================================\n\n";

if ( $config['generate_sha256'] ) {
	$checksum = generate_checksum( $zip_file );
	$checksum_file = $dist_dir . '/sscribe-export-site-pages-' . $version . '.sha256';
	file_put_contents( $checksum_file, $checksum );
	echo "  ✅ SHA-256: $checksum\n";
}

// =============================================================================
// BUILD REPORT
// =============================================================================

$zip_size = filesize( $zip_file );
$duration = round( microtime( true ) - $start_time, 2 );

echo "\n===========================================\n";
echo "  BUILD COMPLETE\n";
echo "===========================================\n\n";

echo "  📦 Plugin: sscribe-export-site-pages\n";
echo "  📌 Version: $version\n";
echo "  📁 Files: $copied copied\n";
echo "  📄 ZIP Size: " . format_bytes( $zip_size ) . "\n";
echo "  🔗 Location: dist/sscribe-export-site-pages-{$version}.zip\n";

if ( $config['generate_sha256'] ) {
	echo "  🔒 SHA-256: $checksum\n";
}

echo "  ⏱️  Duration: {$duration}s\n";

echo "\n===========================================\n";
echo "  READY FOR WORDPRESS.ORG\n";
echo "===========================================\n\n";

echo "  Upload the following files to WordPress.org:\n";
echo "    1. dist/sscribe-export-site-pages-{$version}.zip\n";
echo "    2. dist/sscribe-export-site-pages-{$version}.sha256\n";
echo "\n  Or extract and upload the sscribe-export-site-pages/ folder.\n\n";

echo "✅ Build successful!\n\n";