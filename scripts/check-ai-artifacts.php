<?php
/**
 * AI-artifact scanner.
 *
 * Fails the build/CI if any shipped source file (or the dist tree
 * if --dist is passed) contains AI-typical punctuation, phrasing,
 * or unicode anomalies. The patterns here enforce the
 * wp-org-ai-artifact-audit rule without relying on manual review.
 *
 * Scans .php, .js, .css, .pot, .txt, .md files. Skips vendor/,
 * vendor-prefixed/, node_modules/, tests/, stubs/, scripts/,
 * docs/, .github/, dist/, build/, coverage/, .cache/,
 * languages/ (compiled POT, regenerated from source).
 *
 * Usage:
 *   php scripts/check-ai-artifacts.php          # scan working tree
 *   php scripts/check-ai-artifacts.php --dist   # scan dist/ tree only
 *
 * Exit code: 0 on clean, 1 on any match (with full report on stderr).
 *
 * @package SScribe
 */

declare(strict_types=1);

/**
 * Run the scanner.
 *
 * @return int Exit code (0 = clean, 1 = match found).
 */
function sscribe_check_ai_artifacts_main(): int {
	global $argv;

	$root = dirname( __DIR__ );

	$dist_only = in_array( '--dist', $argv, true );
	$scan_root = $dist_only ? $root . '/dist' : $root;

	if ( $dist_only && ! is_dir( $scan_root ) ) {
		fwrite( STDERR, "[check-ai-artifacts] dist/ not found; skipping.\n" );
		return 0;
	}

	// Patterns that should NEVER appear in shipped source.
	// Each pattern is either a literal string (case-insensitive substring)
	// or a regex (when prefixed with 'regex:').
	$patterns = array(
		// Em-dash and en-dash — the classic LLM tell.
		'EM_DASH'         => "\xE2\x80\x94",  // U+2014
		'EN_DASH'         => "\xE2\x80\x93",  // U+2013
		// Curly quotes — sometimes used by AI, rare in code.
		'CURLY_QUOTE_LEFT'  => "\xE2\x80\x98", // U+2018
		'CURLY_QUOTE_RIGHT' => "\xE2\x80\x99", // U+2019
		'CURLY_DQUOTE_LEFT' => "\xE2\x80\x9C", // U+201C
		'CURLY_DQUOTE_RIGHT'=> "\xE2\x80\x9D", // U+201D
		// Horizontal ellipsis char (three dots) — should be '...' in code.
		'ELLIPSIS_CHAR'   => "\xE2\x80\xA6",  // U+2026
		// Zero-width space and friends — invisible AI/encoding artifacts.
		'ZWSP'            => "\xE2\x80\x8B",  // U+200B
		'ZWNJ'            => "\xE2\x80\x8C",  // U+200C
		'ZWJ'             => "\xE2\x80\x8D",  // U+200D
		// LLM phrasings (case-insensitive substring).
		'LLM_DELVE'       => 'regex:/delve\s+into/i',
		'LLM_LETS_DIVE'   => 'regex:/let\'?s\s+dive/i',
		'LLM_CERTAINLY'   => 'regex:/\b[Cc]ertainly[,!\.]/',
		'LLM_WORTH_NOTING'=> 'regex:/it\'?s\s+worth\s+noting/i',
		'LLM_GAME_CHANGER'=> 'regex:/game[- ]changer/i',
		'LLM_NAVIGATE'    => 'regex:/navigate\s+the\s+(complexities|landscape|nuances)\s+of/i',
		'LLM_IN_TODAY'    => 'regex:/in\s+today\'?s\s+(digital\s+)?(landscape|world|era)/i',
	);

	// Skip directories at any depth.
	$skip_dirs = array(
		'vendor', 'vendor-prefixed', 'node_modules', 'tests', 'stubs',
		'scripts', 'docs', '.github', 'dist', 'build', 'coverage',
		'.cache', '.phpunit.cache', '.playwright-mcp', '.opencode', '.impeccable',
		'.audit',
		'.aider', '.claude', '.cursor', '.windsurf', '.continue',
		'.codeium', '.idea', '.vscode', 'screenshots',
	);

	// File extensions to scan.
	$extensions = array( 'php', 'js', 'css', 'pot', 'txt', 'md' );

	$matches = array();
	$files_scanned = 0;

	$rii = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator(
			$scan_root,
			RecursiveDirectoryIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
		),
		RecursiveIteratorIterator::LEAVES_ONLY
	);

	foreach ( $rii as $file ) {
		/** @var SplFileInfo $file */
		if ( ! $file->isFile() ) {
			continue;
		}

		// Skip any file under a skipped directory.
		$relative_path = str_replace( $scan_root . DIRECTORY_SEPARATOR, '', $file->getPathname() );
		$path_parts    = explode( DIRECTORY_SEPARATOR, $relative_path );
		$skip          = false;
		foreach ( $path_parts as $part ) {
			if ( in_array( $part, $skip_dirs, true ) ) {
				$skip = true;
				break;
			}
		}
		if ( $skip ) {
			continue;
		}

		$ext = strtolower( $file->getExtension() );
		if ( ! in_array( $ext, $extensions, true ) ) {
			continue;
		}

		++$files_scanned;
		$content = file_get_contents( $file->getPathname() );
		if ( false === $content ) {
			continue;
		}

		foreach ( $patterns as $name => $pattern ) {
			if ( str_starts_with( $pattern, 'regex:' ) ) {
				$regex = substr( $pattern, 6 );
				if ( preg_match( $regex, $content ) ) {
					$matches[] = array(
						'file'    => $relative_path,
						'pattern' => $name,
						'match'   => $pattern,
					);
				}
			} else {
				if ( false !== strpos( $content, $pattern ) ) {
					$matches[] = array(
						'file'    => $relative_path,
						'pattern' => $name,
						'match'   => bin2hex( $pattern ),
					);
				}
			}
		}
	}

	if ( empty( $matches ) ) {
		printf( "[check-ai-artifacts] Clean: scanned %d files.\n", $files_scanned );
		return 0;
	}

	fwrite( STDERR, "[check-ai-artifacts] FAIL: found " . count( $matches ) . " AI-artifact match(es) in " . count( array_unique( array_column( $matches, 'file' ) ) ) . " file(s):\n" );
	foreach ( $matches as $m ) {
		fprintf( STDERR, "  [%s] %s\n", $m['pattern'], $m['file'] );
	}
	fwrite( STDERR, "\nFix the source (em-dash -> '-'; en-dash -> '-' or ' - '; curly quotes -> straight; LLM phrasings -> rewrite).\n" );
	return 1;
}

exit( sscribe_check_ai_artifacts_main() );
