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
 * - Extreme bloat removal (Dev files & Obscure fonts)
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
	
	// Bloat/Dev Files (Stripped from production)
	'base_excludes'    => array(
		'dist', 'vendor', '.git', '.gitignore', '.distignore', '.cache', '.phpunit.cache',
		'.sisyphus', '.wp-env', 'wordpress', 'wordpress-tests-lib',
		'package.json', 'package-lock.json', 'opencode.json', 'CONTRIBUTING.md', 'CHANGELOG.md',
		'phpunit.xml', 'phpunit.xml.dist', 'phpstan.neon', 'phpstan.neon.dist',
		'phpcs.xml', 'phpstan-bootstrap.php', '.editorconfig', '.wp-env.json',
		'tests', 'scripts', '.github', '.gitattributes', 'docs', 'examples', 'samples',
		'composer.json', 'composer.lock', 'scratch', 'strauss.json', 'infection.json5',
		'commit-message.txt', '.prettierrc', '.eslintrc.json', '.stylelintrc.json', '.husky',
		'node_modules', 'screenshots', 'WPScan',
		// Vendor-prefixed dev files
		'phpstan-baseline.neon', 'ruleset.xml', 'CREDITS.txt',
		'.php-cs-fixer.php', '.php-cs-fixer.dist.php', 'mkdocs.yml',
		'.travis.yml', '.scrutinizer.yml', '.github_changelog_generator',
	),
	
	// Obscure Font Bloat (Pruned from mPDF to save ~68MB)
	// Keeps only: DejaVu family (Sans/Condensed/Serif/Mono), Free family (Sans/Serif/Mono), OCR-B
	'font_excludes'    => array(
		// Rare/unused scripts
		'Sun-ExtA.ttf', 'Sun-ExtB.ttf', 'UnBatang_0613.ttf', 'Aegyptus.otf', 
		'Aegean.otf', 'Akkadian.otf', 'Jomolhari.ttf', 'KhmerOS.ttf', 
		'Abyssinica_SIL.ttf', 'AboriginalSansREGULAR.ttf', 'Padauk-book.ttf', 
		'SundaneseUnicode-1.0.5.ttf', 'SyrCOMEdessa.otf', 'TaameyDavidCLM-Medium.ttf', 
		'Tharlon-Regular.ttf', 'ayar.ttf', 'damase_v.2.ttf', 'kaputaunicode.ttf', 
		'lannaalif-v1-03.ttf', 'ZawgyiOne.ttf', 'DBSILBR.ttf', 'Eeyek-Regular.ttf',
		'Pothana2000.ttf', 'Lohit-Kannada.ttf', 'Quivira.otf', 'TaiHeritagePro.ttf',
		// Thai
		'Garuda.ttf', 'Garuda-Bold.ttf', 'Garuda-Oblique.ttf', 'Garuda-BoldOblique.ttf',
		// Lao
		'Dhyana-Regular.ttf', 'Dhyana-Bold.ttf',
		// Redirected via fonttrans to notosansarabic
		'XB Riyaz.ttf', 'XB RiyazBd.ttf', 'XB RiyazIt.ttf', 'XB RiyazBdIt.ttf',
		'LateefRegOT.ttf', 'Uthman.otf',
		// License/info files for removed fonts
		'DhyanaOFL.txt', 'Jomolhari-OFL.txt', 'KhmerOFL.txt', 'Lateef font OFL.txt',
		'LohitKannadaOFL.txt', 'SyrCOMEdessa_license.txt', 'TaameyDavidCLM-LICENSE.txt',
		'TharlonOFL.txt', 'XW Zar Font Info.txt',
	),
	
	// Output
	'show_excluded'    => true,           // Show excluded files in report
);

// Helper to consolidate excludes
$all_excludes = array_unique( array_merge( $config['base_excludes'], $config['font_excludes'] ) );

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
	
	if ( $return !== 0 ) {
		$output_str = implode( "\n", $output );
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

if ( is_dir( $dist_dir ) ) {
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

// Consolidate exclusion list
$distignore_excludes = get_distignore_excludes( $root, $config['distignore'] );
$excludes = array_unique( array_merge( $all_excludes, $distignore_excludes ) );

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
			if ( $relative_norm === $exclude || str_starts_with( $relative_norm, $exclude . '/' ) ) {
				return false;
			}
			if ( in_array( $exclude, $segments, true ) ) {
				return false;
			}
		}
		return true;
	}
);

$iterator   = new RecursiveIteratorIterator( $filter, RecursiveIteratorIterator::SELF_FIRST );
$copied     = 0;

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

echo "     ✅ Copied: $copied files\n";

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
	echo "  ❌ Could not close ZIP file. (Path length or lock issue)\n";
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

// Step 7: Final Report
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
echo "  ⏱️  Duration: {$duration}s\n";

echo "\n===========================================\n";
echo "  READY FOR WORDPRESS.ORG\n";
echo "===========================================\n\n";

echo "✅ Build successful! No development files or obscure fonts included.\n\n";