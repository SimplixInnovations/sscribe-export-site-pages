<?php
/** Isolated build inputs and narrowly owned release outputs. */
declare( strict_types=1 );

/** Evidence is part of success: a failed or partial write must fail the command. */
function sscribe_write_build_json( string $path, array $payload ): void {
	$json = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	if ( strlen( $json ) !== @file_put_contents( $path, $json, LOCK_EX ) ) {
		throw new RuntimeException( 'Cannot persist build evidence: ' . $path );
	}
}

/** Run Git without shell parsing, preserving failures for the caller. */
function sscribe_build_git( string $directory, array $arguments ): string {
	$process = proc_open(
		array_merge( array( 'git', '-C', $directory ), $arguments ),
		array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'redirect', 1 ) ),
		$pipes
	);
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Cannot start Git.' );
	}
	fclose( $pipes[0] );
	$output = (string) stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	$code = proc_close( $process );
	if ( 0 !== $code ) {
		throw new RuntimeException( 'Git failed: ' . trim( $output ) );
	}
	return trim( $output );
}

/** Remove a generated path without following links or retaining directory handles. */
function sscribe_remove_build_path( string $path ): void {
	if ( is_link( $path ) || is_file( $path ) ) {
		if ( ! is_link( $path ) && ! chmod( $path, 0600 ) ) {
			throw new RuntimeException( 'Cannot make generated file writable: ' . $path );
		}
		if ( ! unlink( $path ) ) {
			throw new RuntimeException( 'Cannot remove generated file: ' . $path );
		}
		return;
	}
	if ( ! is_dir( $path ) ) {
		return;
	}
	$entries = scandir( $path );
	if ( false === $entries ) {
		throw new RuntimeException( 'Cannot enumerate generated directory: ' . $path );
	}
	foreach ( $entries as $entry ) {
		if ( '.' !== $entry && '..' !== $entry ) {
			sscribe_remove_build_path( $path . '/' . $entry );
		}
	}
	if ( ! rmdir( $path ) ) {
		throw new RuntimeException( 'Cannot remove generated directory: ' . $path );
	}
}

/** Preserve all evidence and previous versions while replacing this candidate's outputs. */
function sscribe_clean_release_output( string $dist, string $version ): void {
	if ( is_link( $dist ) || ( file_exists( $dist ) && ! is_dir( $dist ) ) || ! preg_match( '/\A[0-9A-Za-z][0-9A-Za-z.+-]*\z/', $version ) ) {
		throw new RuntimeException( 'Unsafe release output path or version.' );
	}
	foreach ( array( 'sscribe-export-site-pages', 'sscribe-export-site-pages-' . $version . '.zip', 'sscribe-export-site-pages-' . $version . '.sha256' ) as $name ) {
		sscribe_remove_build_path( $dist . '/' . $name );
	}
}

/** Copy committed source into an owned directory; never clean the caller's checkout. */
function sscribe_create_build_workspace( string $source ): array {
	$sha = sscribe_build_git( $source, array( 'rev-parse', 'HEAD' ) );
	if ( ! preg_match( '/\A[0-9a-f]{40}\z/', $sha ) ) {
		throw new RuntimeException( 'Cannot resolve full source SHA.' );
	}
	if ( '' !== sscribe_build_git( $source, array( 'status', '--porcelain', '--untracked-files=no' ) ) ) {
		throw new RuntimeException( 'Commit tracked changes before checking build determinism.' );
	}
	$directory = sys_get_temp_dir() . '/sscribe-determinism-' . bin2hex( random_bytes( 12 ) );
	if ( ! mkdir( $directory, 0700 ) ) {
		throw new RuntimeException( 'Cannot create isolated build directory.' );
	}
	$root = $directory . '/source';
	try {
		// No shared objects or hard links: subsequent cleanup cannot alter the source repo.
		sscribe_build_git( $source, array( '-c', 'core.autocrlf=false', 'clone', '--quiet', '--no-hardlinks', '--no-checkout', '--', $source, $root ) );
		sscribe_build_git( $root, array( '-c', 'core.autocrlf=false', 'checkout', '--quiet', '--detach', $sha ) );
	} catch ( Throwable $error ) {
		sscribe_remove_build_path( $directory );
		throw $error;
	}
	return array( 'directory' => $directory, 'root' => $root, 'source_sha' => $sha );
}
