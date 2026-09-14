<?php
/**
 * Coverage Clover path normalizer.
 *
 * PHPUnit records file paths in `clover.xml` relative to the working
 * directory of the test process. Different drivers, OSes, and CI
 * environments record those paths differently:
 *
 *   - Linux/macOS test runners typically produce paths relative to the
 *     project root, e.g. `includes/class-sscribe-security.php`.
 *   - Windows test runners (this project's primary local environment)
 *     produce absolute paths with the drive letter and backslashes,
 *     e.g. `C:\Users\Ahmed\Desktop\sscribe-export-site-pages\includes\class-sscribe-security.php`
 *     or the forward-slash variant
 *     `C:/Users/Ahmed/Desktop/sscribe-export-site-pages/includes/class-sscribe-security.php`.
 *
 * The release-critical modules (private-storage, filesystem, security,
 * audit-trail) are looked up in a map keyed by repository-relative
 * forward-slash paths. A naive `str_replace('\\', '/')` leaves the
 * drive-root prefix intact and every critical file is reported
 * "missing from the Clover report" — which is what the
 * scripts/verify-coverage-thresholds.php gate was doing before this
 * helper existed.
 *
 * The fix is centralised here so both the verifier and any future
 * tooling (manifest writers, dashboards, CI integrations) can rely on
 * a single, tested, OS-aware normalization contract.
 *
 * The helper is pure and side-effect-free. It is required from the
 * verifier and exercised directly by unit tests in
 * tests/Unit/SScribe_Coverage_Path_Normalizer_Test.php plus the
 * integration scenarios in
 * tests/Integration/SScribe_Coverage_Thresholds_Test.php.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

/**
 * Normalize a Clover-reported file path to a repository-relative
 * forward-slash path. The function is deliberately permissive about
 * input casing on Windows but strict about refusing paths that fall
 * outside the repository root — that refusal is what keeps the gate
 * honest when a test driver accidentally reports a path from a
 * different project (e.g. via a symlinked `vendor/`).
 *
 * Behavior, in order:
 *
 *   1. Normalize slash direction (Windows backslashes → forward slashes).
 *   2. Strip the repository-root prefix from absolute paths
 *      (Linux `/abs/path` and Windows `C:/abs/path`, case-insensitive
 *      on Windows).
 *   3. Return an empty string if the absolute path lies outside the
 *      repository — callers should treat this as "not in this project".
 *   4. Leave already-relative paths unchanged (only stripping leading
 *      `./` and leading `/` artifacts).
 *
 * @param string $path       Path as recorded in the Clover XML.
 * @param string $repo_root  Absolute path of the repository root,
 *                           used as the prefix to strip from absolute
 *                           Clover paths.
 *
 * @return string Repository-relative forward-slash path, or empty
 *                string when the input was absolute but outside the
 *                repository.
 */
function sscribe_normalize_clover_path( string $path, string $repo_root ): string {
	// 1. Normalize slash direction.
	$normalized = str_replace( '\\', '/', $path );
	$normalized_repo = str_replace( '\\', '/', $repo_root );
	$normalized_repo_trimmed = rtrim( $normalized_repo, '/' );

	// 1a. WSL bridge: PHPUnit running inside WSL sees the project at
	// `/mnt/<drive>/Users/Ahmed/Desktop/sscribe-export-site-pages/...`
	// but the verifier's repo_root is the Windows path
	// `C:\Users\Ahmed\Desktop\sscribe-export-site-pages`. Without
	// translating the WSL bridge to a Windows drive letter, every
	// Clover entry is treated as outside the repo and the gate
	// reports every critical file as missing. Rewrite the prefix so
	// the Windows-prefix-strip below can match.
	//
	// Both the file path AND the repo root must be normalized so the
	// prefix comparison succeeds.
	if ( preg_match( '#^/mnt/([a-zA-Z])/(.*)$#', $normalized, $wsl_matches ) ) {
		$normalized = strtoupper( $wsl_matches[1] ) . ':/' . $wsl_matches[2];
	}
	if ( preg_match( '#^/mnt/([a-zA-Z])/(.*)$#', $normalized_repo_trimmed, $wsl_matches_repo ) ) {
		$normalized_repo_trimmed = strtoupper( $wsl_matches_repo[1] ) . ':/' . $wsl_matches_repo[2];
	}

	// 2. Detect absolute path (Linux /foo, Windows C:/foo, Windows C:foo).
	$is_absolute = false;
	if ( strlen( $normalized ) > 0 && '/' === $normalized[0] ) {
		$is_absolute = true;
	} elseif ( strlen( $normalized ) >= 3
		&& ctype_alpha( $normalized[0] )
		&& ':' === $normalized[1]
		&& ( '/' === $normalized[2] || '\\' === $normalized[2] )
	) {
		$is_absolute = true;
	}

	if ( $is_absolute ) {
		// Windows is case-insensitive: compare lowercased prefixes.
		$cmp_path = strtolower( $normalized );
		$cmp_repo = strtolower( $normalized_repo_trimmed );
		if ( $cmp_repo !== '' && str_starts_with( $cmp_path, $cmp_repo . '/' ) ) {
			$relative = substr( $normalized, strlen( $normalized_repo_trimmed ) );
			return ltrim( $relative, '/' );
		}
		// Absolute path outside the repo: refuse to normalize so the
		// caller can report it as missing-from-repo rather than silently
		// matching a wrong critical file.
		return '';
	}

	// 3. Already-relative path: strip leading ./ and stray leading slashes.
	$relative = ltrim( $normalized, '/' );
	$relative = ltrim( $relative, './' );
	// Collapse repeated leading ./ (e.g. "././includes/...").
	while ( str_starts_with( $relative, './' ) ) {
		$relative = substr( $relative, 2 );
	}
	return $relative;
}