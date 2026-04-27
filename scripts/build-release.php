<?php
declare(strict_types=1);

$root     = dirname( __DIR__ );
$dist_dir = $root . '/dist/sscribe-export-site-pages';
$version  = '3.32.0';
$zip_file = $root . "/dist/sscribe-export-site-pages-{$version}.zip";

$ignore_file = $root . '/.distignore';
$excludes    = array(
	'dist', '.git', '.gitignore', '.distignore', '.cache', '.phpunit.cache',
	'.sisyphus', '.wp-env', 'wordpress', 'wordpress-tests-lib',
	'package.json', 'opencode.json', 'CONTRIBUTING.md', 'CHANGELOG.md',
	'phpunit.xml', 'phpunit.xml.dist', 'phpstan.neon', 'phpstan.neon.dist',
	'phpcs.xml', 'phpstan-bootstrap.php', '.editorconfig', '.wp-env.json',
);
if ( file_exists( $ignore_file ) ) {
	$lines = file( $ignore_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( '' === $line || str_starts_with( $line, '#' ) ) {
			continue;
		}
		$excludes[] = trim( $line, '/' );
	}
}
$excludes = array_unique( $excludes );

if ( is_dir( $root . '/dist' ) ) {
	rrmdir( $root . '/dist' );
}
mkdir( $dist_dir, 0755, true );

$dir    = new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS );
$filter = new RecursiveCallbackFilterIterator(
	$dir,
	static function ( $current ) use ( $root, $excludes ): bool {
		$relative = str_replace( $root . DIRECTORY_SEPARATOR, '', $current->getPathname() );
		$relative = str_replace( $root . '/', '', $relative );
		foreach ( $excludes as $exclude ) {
			if ( $relative === $exclude || str_starts_with( $relative, $exclude . DIRECTORY_SEPARATOR ) || str_starts_with( $relative, $exclude . '/' ) ) {
				return false;
			}
		}
		return true;
	}
);
$iterator = new RecursiveIteratorIterator( $filter, RecursiveIteratorIterator::SELF_FIRST );

$copied  = 0;
$skipped = 0;

foreach ( $iterator as $file ) {
	$relative = str_replace( $root . DIRECTORY_SEPARATOR, '', $file->getPathname() );
	$relative = str_replace( $root . '/', '', $relative );
	$dest     = $dist_dir . '/' . $relative;

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

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	echo "ERROR: Could not create ZIP file.\n";
	exit( 1 );
}

$files = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $dist_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ( $files as $file ) {
	if ( ! $file->isDir() ) {
		$raw_path      = str_replace( $dist_dir . '/', '', $file->getPathname() );
		$raw_path      = str_replace( $dist_dir . '\\', '', $raw_path );
		$relative_path = 'sscribe-export-site-pages/' . str_replace( '\\', '/', $raw_path );
		$zip->addFile( $file->getPathname(), $relative_path );
	}
}

if ( ! $zip->close() ) {
	echo "ERROR: Could not close ZIP file.\n";
	exit( 1 );
}

$zip_size = round( filesize( $zip_file ) / 1024, 2 );

echo "Build complete:\n";
echo "  Files copied: {$copied}\n";
echo "  Files skipped: {$skipped}\n";
echo "  ZIP: {$zip_file} ({$zip_size} KB)\n";

function rrmdir( string $dir ): void {
	if ( is_dir( $dir ) ) {
		$objects = scandir( $dir );
		foreach ( $objects as $object ) {
			if ( '.' !== $object && '..' !== $object ) {
				$path = $dir . '/' . $object;
				if ( is_dir( $path ) ) {
					rrmdir( $path );
				} else {
					unlink( $path );
				}
			}
		}
		rmdir( $dir );
	}
}
