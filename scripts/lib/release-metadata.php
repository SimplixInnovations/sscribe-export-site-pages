<?php
/** Canonical identity for our root package in Composer's generated metadata. */
declare( strict_types=1 );

/**
 * Replace only our root record; retain the dependency section byte-for-byte.
 *
 * Composer caches the checkout SHA and branch at install time. That identity
 * can be stale even after Strauss regenerates vendor-prefixed. A release uses
 * the plugin version and current source SHA, independent of install history.
 * The locked Strauss layout is deliberately checked rather than guessed.
 */
function sscribe_release_composer_metadata( string $contents, string $version, string $sha ): string {
	if ( ! preg_match( '/\A[0-9]+\.[0-9]+\.[0-9]+\z/', $version ) || ! preg_match( '/\A[0-9a-f]{40}\z/', $sha ) ) {
		throw new RuntimeException( 'Invalid release version or full source SHA.' );
	}
	$pattern = "/\A<\?php return array \(\r?\n  'root' =>\s*array \((.*?)\r?\n  \),\r?\n(?=  'versions' =>)/s";
	if ( 1 !== preg_match( $pattern, $contents, $matches )
		|| 1 !== preg_match( "/'name' => 'simplix-innovations\/sscribe-export-site-pages',/", $matches[1] ) ) {
		throw new RuntimeException( 'Unexpected Composer root metadata; rebuild locked dependencies and inspect installed.php.' );
	}
	$root = "<?php return array (\n  'root' =>\n  array (\n"
		. "    'name' => 'simplix-innovations/sscribe-export-site-pages',\n"
		. "    'pretty_version' => '$version',\n    'version' => '$version.0',\n"
		. "    'reference' => '$sha',\n    'type' => 'wordpress-plugin',\n"
		. "    'install_path' => __DIR__ . '/../',\n    'aliases' => array (),\n    'dev' => false,\n  ),\n";
	return $root . substr( $contents, strlen( $matches[0] ) );
}

/** Update the staged generated metadata only; never touch the checkout's tree. */
function sscribe_write_release_composer_metadata( string $path, string $version, string $sha ): void {
	$contents = @file_get_contents( $path );
	if ( false === $contents ) {
		throw new RuntimeException( 'Cannot read release Composer metadata: ' . $path );
	}
	$normalized = sscribe_release_composer_metadata( $contents, $version, $sha );
	if ( strlen( $normalized ) !== @file_put_contents( $path, $normalized, LOCK_EX ) ) {
		throw new RuntimeException( 'Cannot write release Composer metadata: ' . $path );
	}
}
