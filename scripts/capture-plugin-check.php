<?php
/** Capture Plugin Check against a fresh extraction of the exact release ZIP. */
declare( strict_types=1 );
require_once __DIR__ . '/lib/cross-platform.php';
$root = dirname( __DIR__ );
$zip_path = $argv[1] ?? '';
$wp_path = $argv[2] ?? '';
if ( ! is_file( $zip_path ) || ! is_dir( $wp_path ) ) {
	sscribe_fail( 'Usage: php scripts/capture-plugin-check.php RELEASE.zip ISOLATED_WP_DIRECTORY' );
}
$git = static function ( string $args ) use ( $root ): string {
	$output = array();
	$code = 1;
	exec( 'git -C ' . escapeshellarg( $root ) . ' ' . $args, $output, $code );
	if ( 0 !== $code ) { sscribe_fail( 'Cannot establish source identity.' ); }
	return trim( implode( "\n", $output ) );
};
$sha = $git( 'rev-parse HEAD' );
if ( '' !== $git( 'status --porcelain --untracked-files=no' ) ) { sscribe_fail( 'Commit tracked changes before capture.' ); }
$zip_hash = hash_file( 'sha256', $zip_path );
$evidence_dir = $root . '/dist/evidence';
if ( ! is_dir( $evidence_dir ) ) { mkdir( $evidence_dir, 0755, true ); }
$report = $evidence_dir . '/plugin-check.log';
$evidence = $evidence_dir . '/plugin-check-evidence.json';
// Remove stale identity before starting: an interrupted command cannot reuse it.
if ( is_file( $evidence ) ) { unlink( $evidence ); }
file_put_contents( $report, '' );
$temp = sys_get_temp_dir() . '/sscribe-plugin-check-' . bin2hex( random_bytes( 12 ) );
mkdir( $temp, 0700 );
// Fail closed if capture throws before the triage verifier runs.
$verification = array( 'code' => 1 );
try {
	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_path ) ) { throw new RuntimeException( 'Cannot open ZIP.' ); }
	$seen = array();
	for ( $i = 0; $i < $zip->numFiles; ++$i ) {
		$name = $zip->getNameIndex( $i );
		if ( ! is_string( $name ) || ! str_starts_with( $name, 'sscribe-export-site-pages/' ) || preg_match( '~(^/|:|\\\\|[\x00-\x1f]|(^|/)\.\.?(/|$)|//)~', $name ) ) {
			throw new RuntimeException( 'Unsafe or unexpected ZIP member.' );
		}
		if ( isset( $seen[ $name ] ) ) { throw new RuntimeException( 'Duplicate ZIP member.' ); }
		$seen[ $name ] = true;
		$zip->getExternalAttributesIndex( $i, $opsys, $attrs );
		if ( 0120000 === ( ( $attrs >> 16 ) & 0170000 ) ) { throw new RuntimeException( 'ZIP symlinks are forbidden.' ); }
	}
	if ( ! $zip->extractTo( $temp ) ) { throw new RuntimeException( 'ZIP extraction failed.' ); }
	$zip->close();
	$plugin = $temp . '/sscribe-export-site-pages';
	if ( ! is_file( $plugin . '/sscribe-export-site-pages.php' ) ) { throw new RuntimeException( 'Missing plugin entrypoint.' ); }
	$wp_root = realpath( $wp_path );
	$command = array( sscribe_which( 'wp' ), '--path=' . $wp_root, '--require=' . __DIR__ . '/plugin-check-cli-bootstrap.php', 'plugin', 'check', $plugin, '--slug=sscribe-export-site-pages', '--format=json', '--include-experimental' );
	$result = sscribe_run_argv( $command, $root, array( 'SSCRIBE_WP_ROOT' => $wp_root ), $report );
	if ( $sha !== $git( 'rev-parse HEAD' ) || '' !== $git( 'status --porcelain --untracked-files=no' ) || $zip_hash !== hash_file( 'sha256', $zip_path ) ) {
		throw new RuntimeException( 'Source or ZIP changed during capture.' );
	}
	file_put_contents( $evidence, json_encode( array( 'source_sha' => $sha, 'zip_sha256' => $zip_hash, 'report_sha256' => hash_file( 'sha256', $report ), 'exit_code' => $result['code'], 'command' => $command ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	putenv( 'SSCRIBE_RELEASE_CERTIFICATION=1' );
	putenv( 'SSCRIBE_SOURCE_SHA=' . $sha );
	putenv( 'SSCRIBE_RELEASE_ZIP=' . realpath( $zip_path ) );
	putenv( 'SSCRIBE_PLUGIN_CHECK_REPORT=' . $report );
	putenv( 'SSCRIBE_PLUGIN_CHECK_EVIDENCE=' . $evidence );
	putenv( 'SSCRIBE_TOOL_VERBOSE=1' );
	$verification = sscribe_run_argv( array( PHP_BINARY, $root . '/scripts/verify-plugin-check-triage.php' ), $root, array( 'SSCRIBE_TOOL_VERBOSE' => '1' ) );
} finally {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $temp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $iterator as $item ) { $item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() ); }
	rmdir( $temp );
}
exit( $verification['code'] );
