<?php
/**
 * Phase 37: third-party license inventory contract.
 *
 * WordPress.org requires every GPL-2.0-or-later plugin to ship a
 * license inventory that proves the shipped third-party code is
 * license-compatible. WP.org reviewers specifically check mPDF
 * (GPL-2.0-only), PHPWord (LGPL-3.0-only), PSR/container (MIT),
 * fonts (OFL-1.1), and any other embedded dependency.
 *
 * This verifier walks the shipped dist tree (the same tree the
 * submission ZIP was packaged from) and:
 *
 *  1. Parses vendor-prefixed/composer/installed.php to enumerate
 *     every shipped package, its version, and its source reference.
 *  2. For each package, locates the shipped license file (LICENSE /
 *     COPYING / NOTICE / OFL.txt / LICENSE.txt) under the package
 *     directory and extracts the canonical SPDX identifier when
 *     present.
 *  3. Asserts every shipped package has a license file in its own
 *     directory — a stripped license is an automatic WP.org reject.
 *  4. Asserts every shipped package's license is GPL-2.0-or-later
 *     compatible. The plugin itself is GPL-2.0-or-later; a stronger
 *     dependency license (GPL-3.0-only, Apache-2.0) or a weaker
 *     license (MIT, BSD-2-clause, OFL) are all compatible. Only a
 *     proprietary / unknown license is a violation.
 *  5. Walks assets/fonts/ for any bundled font and confirms an
 *     OFL.txt (or equivalent) ships with the font family — the
 *     Amiri Arabic font is the only bundled font today.
 *  6. Emits a one-line summary per shipped component plus a single
 *     overall verdict. The PHPUnit test greps for the verdict and
 *     for specific license keywords.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir = dirname( __DIR__ );

$dist_dir  = $root_dir . '/dist/sscribe-export-site-pages';
$installed = $dist_dir . '/vendor-prefixed/composer/installed.php';

if ( ! is_dir( $dist_dir ) ) {
	fwrite( STDERR, "Dist tree {$dist_dir} is missing. Run `php scripts/build-release.php` first.\n" );
	exit( 1 );
}
if ( ! is_file( $installed ) ) {
	fwrite( STDERR, "vendor-prefixed/composer/installed.php is missing from the dist tree.\n" );
	exit( 1 );
}

$errors  = array();
$report  = array();

/**
 * SPDX-compatible licenses that are explicitly compatible with a
 * GPL-2.0-or-later plugin. The plugin declares `License: GPL v2 or
 * later` in its mainfile; a stronger copyleft (GPL-3.0-only,
 * LGPL-2.0-or-later, LGPL-3.0-or-later, AGPL) or a permissive license
 * (MIT, BSD-2/3-clause, Apache-2.0, ISC, Unlicense, MPL-2.0) is
 * compatible. OFL-1.1 (the font license) is also compatible.
 *
 * A proprietary / unknown license would be the only reject-worthy
 * outcome — but we treat any unrecognised license as a violation
 * so the verifier fails loudly until someone explicitly classifies
 * it (escalation per the spec's "If legal compatibility is
 * uncertain, escalate it instead of declaring it solved").
 */
$compatible_spdx = array(
	'GPL-2.0-only',
	'GPL-2.0-or-later',
	'GPL-3.0-only',
	'GPL-3.0-or-later',
	'LGPL-2.0-only',
	'LGPL-2.0-or-later',
	'LGPL-2.1-only',
	'LGPL-2.1-or-later',
	'LGPL-3.0-only',
	'LGPL-3.0-or-later',
	'MIT',
	'BSD-2-Clause',
	'BSD-3-Clause',
	'Apache-2.0',
	'ISC',
	'Unlicense',
	'MPL-2.0',
	'OFL-1.1',
	'OFL-1.1-no-RFN',
	'CC0-1.0',
	'CC-BY-3.0',
	'CC-BY-4.0',
	'PHP-3.0',
	'PHP-3.01',
);

/**
 * Runtime purpose map. The key is the composer package name as it
 * appears in vendor-prefixed/composer/installed.php. The value is a
 * short label describing what the package does in SScribe's runtime.
 */
$runtime_purpose = array(
	'mpdf/mpdf'                  => 'PDF rendering engine (HTML+CSS to PDF)',
	'mpdf/psr-http-message-shim' => 'PSR-7 HTTP message shim used by mPDF',
	'mpdf/psr-log-aware-trait'   => 'PSR-3 logging trait used by mPDF',
	'myclabs/deep-copy'          => 'Deep object cloning helper (PHPUnit dev dependency that Strauss keeps)',
	'paragonie/random_compat'    => 'Random_bytes polyfill (PHP < 7) — kept for old-PHP consumers',
	'phpoffice/math'             => 'Float-precision math used by PHPWord',
	'phpoffice/phpword'          => 'DOCX rendering engine (Word XML)',
	'psr/container'              => 'PSR-11 dependency injection container interface',
	'psr/http-message'           => 'PSR-7 HTTP message interfaces',
	'psr/log'                    => 'PSR-3 logger interface',
	'setasign/fpdi'              => 'FPDI PDF import library used by mPDF for PDF imports',
);

/**
 * Source URL map. The key is the composer package name; the value is
 * the canonical public source URL a reviewer would point at to verify
 * the version shipped.
 */
$source_url_map = array(
	'mpdf/mpdf'                  => 'https://github.com/mpdf/mpdf',
	'mpdf/psr-http-message-shim' => 'https://github.com/mpdf/psr-http-message-shim',
	'mpdf/psr-log-aware-trait'   => 'https://github.com/mpdf/psr-log-aware-trait',
	'myclabs/deep-copy'          => 'https://github.com/myclabs/DeepCopy',
	'paragonie/random_compat'    => 'https://github.com/paragonie/random_compat',
	'phpoffice/math'             => 'https://github.com/PHPOffice/Math',
	'phpoffice/phpword'          => 'https://github.com/PHPOffice/PHPWord',
	'psr/container'              => 'https://github.com/php-fig/container',
	'psr/http-message'           => 'https://github.com/php-fig/http-message',
	'psr/log'                    => 'https://github.com/php-fig/log',
	'setasign/fpdi'              => 'https://github.com/Setasign/FPDI',
);

/**
 * License-detect helpers.
 */

/**
 * Scan a directory for a license-bearing file (LICENSE, COPYING,
 * NOTICE, OFL.txt). Returns the basename of the file if found,
 * null otherwise.
 */
$find_license_file = static function ( string $package_dir ): ?string {
	if ( ! is_dir( $package_dir ) ) {
		return null;
	}
	$candidates = array(
		'LICENSE',
		'LICENSE.txt',
		'LICENSE.md',
		'LICENSE.LESSER.txt',
		'COPYING',
		'COPYING.LESSER',
		'COPYING.LESSER.txt',
		'COPYING.txt',
		'NOTICE',
		'NOTICE.txt',
		'NOTICE.md',
		'OFL.txt',
	);
	foreach ( $candidates as $candidate ) {
		if ( is_file( $package_dir . '/' . $candidate ) ) {
			return $candidate;
		}
	}
	return null;
};

/**
 * Try to extract the SPDX license identifier from a license file's
 * first ~40 lines. Returns the SPDX id as a string or null if no
 * identifier was found.
 */
$extract_spdx = static function ( string $license_path ): ?string {
	if ( ! is_file( $license_path ) ) {
		return null;
	}
	$head = (string) file_get_contents( $license_path, false, null, 0, 4096 );

	// First pass: explicit SPDX-License-Identifier header.
	if ( preg_match( '/SPDX-License-Identifier:\s*([A-Za-z0-9.\-+ ()]+)/', $head, $m ) ) {
		return trim( $m[1] );
	}

	// Second pass: heuristic keyword sniffing for known licenses.
	$sniffs = array(
		'GPL-3.0-only'         => '/GNU\s+General\s+Public\s+License\s+version\s+3/i',
		'GPL-2.0-only'         => '/GNU\s+General\s+Public\s+License\s+version\s+2/i',
		'LGPL-3.0-only'        => '/GNU\s+Lesser\s+General\s+Public\s+License\s+version\s+3/i',
		'LGPL-2.1-only'        => '/GNU\s+Lesser\s+General\s+Public\s+License\s+version\s+2\.1/i',
		'LGPL-2.0-only'        => '/GNU\s+Lesser\s+General\s+Public\s+License\s+version\s+2\b/i',
		'MIT'                  => '/Permission\s+is\s+hereby\s+granted,\s+free\s+of\s+charge,\s+to\s+any\s+person\s+obtaining\s+a\s+copy\s+of\s+this\s+software\s+and\s+associated\s+documentation\s+files/i',
		'BSD-3-Clause'         => '/Redistributions\s+in\s+binary\s+form\s+must\s+reproduce[\s\S]*?Neither\s+the\s+name\s+of/i',
		'BSD-2-Clause'         => '/Redistributions\s+in\s+binary\s+form\s+must\s+reproduce[\s\S]*?without\s+modification/i',
		'Apache-2.0'           => '/Apache\s+License,\s+Version\s+2\.0/i',
		'MPL-2.0'              => '/Mozilla\s+Public\s+License,\s+v\.\s*2\.0/i',
		'OFL-1.1'              => '/SIL\s+Open\s+Font\s+License,\s+Version\s+1\.1/i',
	);
	foreach ( $sniffs as $spdx => $pattern ) {
		if ( preg_match( $pattern, $head ) ) {
			return $spdx;
		}
	}

	return null;
};

echo "=== SScribe Third-Party License Inventory ===\n\n";
echo "Plugin license:   GPL-2.0-or-later\n";
echo "Dist tree:        {$dist_dir}\n";
echo "Inventory source: {$installed}\n\n";

// 1. Parse installed.php and enumerate shipped packages.
$installed_data = include $installed;
if ( ! is_array( $installed_data ) || ! isset( $installed_data['versions'] ) || ! is_array( $installed_data['versions'] ) ) {
	fwrite( STDERR, "vendor-prefixed/composer/installed.php did not return a `versions` array.\n" );
	exit( 1 );
}

$package_count = 0;
$license_missing = 0;
$license_unknown = 0;

echo str_pad( 'PACKAGE', 32 ) . str_pad( 'VERSION', 12 ) . str_pad( 'LICENSE', 16 ) . str_pad( 'FILE', 18 ) . 'PURPOSE' . "\n";
echo str_repeat( '-', 110 ) . "\n";

foreach ( $installed_data['versions'] as $name => $meta ) {
	// Skip the root plugin entry — it's our own package.
	if ( 'simplix-innovations/sscribe-export-site-pages' === $name ) {
		continue;
	}

	$install_path = isset( $meta['install_path'] ) ? (string) $meta['install_path'] : '';
	$version      = isset( $meta['pretty_version'] ) ? (string) $meta['pretty_version'] : (string) ( $meta['version'] ?? 'unknown' );
	$reference    = isset( $meta['reference'] ) ? substr( (string) $meta['reference'], 0, 8 ) : '';

	// Resolve the on-disk directory. Strauss prefixes namespaces and
	// moves packages to vendor-prefixed/<vendor>/<package>/, so the
	// install_path is __DIR__/../<vendor>/<package>. If the directory
	// does not exist on disk, the build script pruned it (typically
	// because it's an empty/polyfill package that's not actually
	// shipped). In that case we still log it in the inventory but
	// mark it as not-shipped and skip the license check — the build
	// legitimately removes the dependency.
	$resolved_dir      = $install_path;
	$license_basename  = null;
	$license_spdx      = null;
	$shipped           = false;
	if ( '' !== $resolved_dir && is_dir( $resolved_dir ) ) {
		$shipped          = true;
		$license_basename = $find_license_file( $resolved_dir );
		if ( null !== $license_basename ) {
			$license_spdx = $extract_spdx( $resolved_dir . '/' . $license_basename );
		}
	}
	if ( ! $shipped ) {
		// Pruned by the build (e.g. paragonie/random_compat is a
		// PHP<7 polyfill that nothing actually loads). Not a violation.
		$report[] = array(
			'name'           => $name,
			'version'        => $version,
			'reference'      => $reference,
			'source_url'     => isset( $source_url_map[ $name ] ) ? $source_url_map[ $name ] : '',
			'license'        => isset( $source_url_map[ $name ] ) ? 'n/a (pruned by build)' : 'n/a',
			'license_file'   => null,
			'modified'       => 'n/a (not shipped)',
			'runtime_purpose' => 'pruned by build — not present in dist tree',
		);
		continue;
	}

	$package_count++;

	$purpose = isset( $runtime_purpose[ $name ] ) ? $runtime_purpose[ $name ] : '(not classified — escalate)';
	$source  = isset( $source_url_map[ $name ] ) ? $source_url_map[ $name ] : '';

	// 2. Assert the package has a license file. A stripped license is
	//    an automatic WP.org reject.
	if ( null === $license_basename ) {
		$errors[] = "[{$name}] no license file shipped under package directory. WP.org requires every bundled dependency to ship its LICENSE / COPYING / NOTICE alongside the source.";
		$license_missing++;
		$license_display = 'MISSING';
	} else {
		$license_display = $license_spdx ?? 'unknown';
		if ( null === $license_spdx ) {
			$errors[] = "[{$name}] license file ({$license_basename}) shipped but SPDX identifier could not be determined. WP.org reviewers cannot verify compatibility without an SPDX tag — escalate to legal.";
			$license_unknown++;
		} elseif ( ! in_array( $license_spdx, $compatible_spdx, true ) ) {
			$errors[] = "[{$name}] license ({$license_spdx}) is not on the GPL-2.0-or-later compatible list. Verify against authoritative sources and escalate before shipping.";
			$license_unknown++;
		}
	}

	$row = str_pad( $name, 32 )
		. str_pad( $version, 12 )
		. str_pad( $license_display, 16 )
		. str_pad( (string) $license_basename, 18 )
		. $purpose;
	echo $row . "\n";

	$report[] = array(
		'name'           => $name,
		'version'        => $version,
		'reference'      => $reference,
		'source_url'     => $source,
		'license'        => $license_display,
		'license_file'   => $license_basename,
		'modified'       => 'yes (Strauss namespace prefix)',
		'runtime_purpose' => $purpose,
	);
}

echo "\n";

// 3. Walk bundled fonts and assert OFL.txt ships with the family.
$fonts_root = $dist_dir . '/assets/fonts';
if ( is_dir( $fonts_root ) ) {
	$font_dirs = glob( $fonts_root . '/*', GLOB_ONLYDIR );
	foreach ( $font_dirs as $font_dir ) {
		$family     = basename( $font_dir );
		$font_files = glob( $font_dir . '/*.{ttf,otf,woff,woff2}', GLOB_BRACE );
		if ( empty( $font_files ) ) {
			continue;
		}
		$license_basename = $find_license_file( $font_dir );
		$license_spdx     = null;
		if ( null !== $license_basename ) {
			$license_spdx = $extract_spdx( $font_dir . '/' . $license_basename );
		}
		echo str_pad( $family . ' (font)', 32 )
			. str_pad( 'n/a', 12 )
			. str_pad( (string) $license_spdx, 16 )
			. str_pad( (string) $license_basename, 18 )
			. "Bundled font family\n";
		if ( null === $license_basename ) {
			$errors[] = "[fonts/{$family}] font family has no license file. WP.org reviewers require an OFL.txt (or compatible) shipped alongside any bundled font.";
		} elseif ( null === $license_spdx || ! in_array( $license_spdx, $compatible_spdx, true ) ) {
			$errors[] = "[fonts/{$family}] license file ({$license_basename}) does not carry a compatible SPDX identifier (got: " . var_export( $license_spdx, true ) . ').';
		}
		$report[] = array(
			'name'           => $family . ' (font family)',
			'version'        => 'n/a',
			'reference'      => '',
			'source_url'     => 'https://fonts.google.com/specimen/Amiri',
			'license'        => (string) $license_spdx,
			'license_file'   => (string) $license_basename,
			'modified'       => 'no',
			'runtime_purpose' => 'PDF font (Arabic, Latin)',
		);
	}
	echo "\n";
}

echo "Packages inventoried: {$package_count}\n";
echo "License files missing: {$license_missing}\n";
echo "License compatibility unknowns: {$license_unknown}\n\n";

// Persist the inventory as JSON for the agent report / CI artifact.
$inventory_path = $root_dir . '/dist/third-party-license-inventory.json';
$inventory_dir  = dirname( $inventory_path );
if ( ! is_dir( $inventory_dir ) ) {
	mkdir( $inventory_dir, 0755, true );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents(
	$inventory_path,
	json_encode(
		array(
			'plugin_license'  => 'GPL-2.0-or-later',
			'generated_at'    => gmdate( 'c' ),
			'packages'        => $report,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	)
);
echo "Inventory persisted to: {$inventory_path}\n\n";

if ( ! empty( $errors ) ) {
	echo "Errors:\n";
	foreach ( $errors as $error ) {
		echo "  ✗ {$error}\n";
	}
	echo "\n";
	exit( 1 );
}

echo "✓ Third-party license inventory holds. Every shipped package has a license file with a GPL-2.0-or-later compatible SPDX identifier.\n";
exit( 0 );
