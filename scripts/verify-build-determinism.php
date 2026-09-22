<?php
/**
 * Verify that two clean release builds produce byte-identical ZIP archives.
 *
 * This is the executable form of release invariant #5. Each pass deletes the
 * generated dependency/prefix/dist trees, reinstalls the locked Composer
 * toolchain, regenerates the prefixed runtime dependencies, and builds the
 * canonical release ZIP. If the ZIP hashes differ, the verifier reports
 * whether staged file content or ZIP metadata drifted.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	exit( "This script must be run from the command line.\n" );
}

$root = dirname( __DIR__ );
$plugin_file = $root . '/sscribe-export-site-pages.php';
$plugin_source = is_file( $plugin_file ) ? (string) file_get_contents( $plugin_file ) : '';
if ( ! preg_match( "/define\\s*\\(\\s*['\"]SSCRIBE_VERSION['\"]\\s*,\\s*['\"]([^'\"]+)['\"]/", $plugin_source, $version_match ) ) {
	fwrite( STDERR, "ERROR: unable to resolve SSCRIBE_VERSION.\n" );
	exit( 1 );
}
$version = (string) $version_match[1];

$run = static function ( string $command, string $label ) use ( $root ): void {
	echo "\n== {$label} ==\n";
	$previous = getcwd();
	if ( false === chdir( $root ) ) {
		throw new RuntimeException( 'Unable to enter repository root.' );
	}
	try {
		passthru( $command, $exit_code );
	} finally {
		if ( false !== $previous ) {
			chdir( $previous );
		}
	}
	if ( 0 !== $exit_code ) {
		throw new RuntimeException( "{$label} failed with exit code {$exit_code}." );
	}
};

$remove_tree = static function ( string $path ) use ( $root ): void {
	$root_real = realpath( $root );
	if ( false === $root_real ) {
		throw new RuntimeException( 'Repository root cannot be resolved.' );
	}

	if ( is_link( $path ) || is_file( $path ) ) {
		if ( ! @unlink( $path ) && file_exists( $path ) ) {
			throw new RuntimeException( "Unable to remove {$path}." );
		}
		return;
	}
	if ( ! is_dir( $path ) ) {
		return;
	}

	$allowed = array( 'vendor', 'vendor-prefixed', 'dist' );
	if ( ! in_array( basename( $path ), $allowed, true ) || dirname( $path ) !== $root ) {
		throw new RuntimeException( "Refusing to remove unexpected path {$path}." );
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		$item_path = $item->getPathname();
		if ( $item->isLink() || $item->isFile() ) {
			if ( ! @unlink( $item_path ) && file_exists( $item_path ) ) {
				throw new RuntimeException( "Unable to remove file {$item_path}." );
			}
		} elseif ( $item->isDir() && ! @rmdir( $item_path ) && is_dir( $item_path ) ) {
			throw new RuntimeException( "Unable to remove directory {$item_path}." );
		}
	}
	if ( ! @rmdir( $path ) && is_dir( $path ) ) {
		throw new RuntimeException( "Unable to remove directory {$path}." );
	}
};

$file_map = static function ( string $dir ): array {
	$map = array();
	if ( ! is_dir( $dir ) ) {
		return $map;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		$path = $file->getPathname();
		$rel = str_replace( '\\', '/', substr( $path, strlen( $dir ) + 1 ) );
		$hash = hash_file( 'sha256', $path );
		if ( false === $hash ) {
			throw new RuntimeException( "Unable to hash staged file {$path}." );
		}
		$map[ $rel ] = array(
			'sha256' => $hash,
			'size'   => (int) $file->getSize(),
		);
	}
	ksort( $map, SORT_STRING );
	return $map;
};

$zip_map = static function ( string $zip_path ): array {
	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_path ) ) {
		throw new RuntimeException( "Unable to open {$zip_path}." );
	}
	$map = array();
	for ( $i = 0; $i < $zip->numFiles; ++$i ) {
		$stat = $zip->statIndex( $i );
		if ( false === $stat || ! isset( $stat['name'] ) ) {
			$zip->close();
			throw new RuntimeException( "Unable to inspect ZIP entry #{$i}." );
		}
		$row = array(
			'crc'         => (int) ( $stat['crc'] ?? 0 ),
			'size'        => (int) ( $stat['size'] ?? 0 ),
			'comp_size'   => (int) ( $stat['comp_size'] ?? 0 ),
			'comp_method' => (int) ( $stat['comp_method'] ?? -1 ),
			'mtime'       => (int) ( $stat['mtime'] ?? 0 ),
		);
		if ( method_exists( $zip, 'getExternalAttributesIndex' ) ) {
			$opsys = 0;
			$attrs = 0;
			if ( $zip->getExternalAttributesIndex( $i, $opsys, $attrs ) ) {
				$row['opsys'] = (int) $opsys;
				$row['attrs'] = (int) $attrs;
			}
		}
		$map[ (string) $stat['name'] ] = $row;
	}
	$zip->close();
	ksort( $map, SORT_STRING );
	return $map;
};

$describe_diff = static function ( array $first, array $second, string $label ): void {
	$keys = array_values( array_unique( array_merge( array_keys( $first ), array_keys( $second ) ) ) );
	sort( $keys, SORT_STRING );
	$shown = 0;
	foreach ( $keys as $key ) {
		$a = $first[ $key ] ?? null;
		$b = $second[ $key ] ?? null;
		if ( $a === $b ) {
			continue;
		}
		echo "DIFF {$label}: {$key}\n";
		echo '  pass1=' . json_encode( $a, JSON_UNESCAPED_SLASHES ) . "\n";
		echo '  pass2=' . json_encode( $b, JSON_UNESCAPED_SLASHES ) . "\n";
		++$shown;
		if ( $shown >= 50 ) {
			echo "  ...diff output capped at 50 entries...\n";
			break;
		}
	}
	if ( 0 === $shown ) {
		echo "No {$label} differences detected.\n";
	}
};

$build_pass = static function ( int $pass ) use (
	$root,
	$version,
	$run,
	$remove_tree,
	$file_map,
	$zip_map
): array {
	echo "\n===========================================\n";
	echo " CLEAN REPRODUCIBILITY BUILD PASS {$pass}\n";
	echo "===========================================\n";

	foreach ( array( 'vendor', 'vendor-prefixed', 'dist' ) as $generated ) {
		$remove_tree( $root . DIRECTORY_SEPARATOR . $generated );
	}

	$run(
		'composer install --no-interaction --no-progress --optimize-autoloader',
		"Pass {$pass}: install locked Composer dependencies"
	);
	$run( 'composer vendor:prefix', "Pass {$pass}: generate prefixed vendor tree" );
	$run(
		escapeshellarg( PHP_BINARY ) . ' scripts/build-release.php --skip-validation',
		"Pass {$pass}: build canonical release ZIP"
	);

	$zip_path = $root . "/dist/sscribe-export-site-pages-{$version}.zip";
	$stage_dir = $root . '/dist/sscribe-export-site-pages';
	if ( ! is_file( $zip_path ) ) {
		throw new RuntimeException( "Pass {$pass}: expected ZIP missing at {$zip_path}." );
	}

	$hash = hash_file( 'sha256', $zip_path );
	if ( false === $hash ) {
		throw new RuntimeException( "Pass {$pass}: unable to hash release ZIP." );
	}
	$size = filesize( $zip_path );
	if ( false === $size ) {
		throw new RuntimeException( "Pass {$pass}: unable to stat release ZIP." );
	}

	$result = array(
		'sha256'   => $hash,
		'zip_size' => (int) $size,
		'files'    => $file_map( $stage_dir ),
		'zip_meta' => $zip_map( $zip_path ),
	);
	echo "PASS {$pass} SHA-256: {$hash}\n";
	echo "PASS {$pass} ZIP bytes: {$result['zip_size']}\n";
	echo 'PASS ' . $pass . ' staged files: ' . count( $result['files'] ) . "\n";
	return $result;
};

try {
	$first = $build_pass( 1 );
	$second = $build_pass( 2 );
} catch ( Throwable $e ) {
	fwrite( STDERR, "\nBUILD DETERMINISM ERROR: {$e->getMessage()}\n" );
	exit( 1 );
}

echo "\n=== SScribe Clean Build Determinism ===\n";
echo "Version: {$version}\n";
echo "Pass 1: {$first['sha256']} ({$first['zip_size']} bytes)\n";
echo "Pass 2: {$second['sha256']} ({$second['zip_size']} bytes)\n";

if ( $first['sha256'] !== $second['sha256'] ) {
	echo "\nERROR: two clean builds produced different ZIP bytes.\n";
	$describe_diff( $first['files'], $second['files'], 'staged-file' );
	$describe_diff( $first['zip_meta'], $second['zip_meta'], 'zip-metadata' );
	exit( 1 );
}

if ( $first['files'] !== $second['files'] ) {
	echo "\nERROR: ZIP hashes matched but staged file maps differ.\n";
	$describe_diff( $first['files'], $second['files'], 'staged-file' );
	exit( 1 );
}

if ( $first['zip_meta'] !== $second['zip_meta'] ) {
	echo "\nERROR: ZIP hashes matched but ZIP metadata maps differ.\n";
	$describe_diff( $first['zip_meta'], $second['zip_meta'], 'zip-metadata' );
	exit( 1 );
}

echo "✓ Two clean builds are byte-identical and release invariant #5 is proven.\n";
exit( 0 );
