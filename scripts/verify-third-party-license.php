<?php
/**
 * Third-party runtime license inventory and compatibility gate.
 *
 * The release tree is authoritative for what ships; composer.lock is
 * authoritative for Composer package SPDX declarations. Every shipped package
 * must retain its upstream license text, and the complete shipped runtime
 * license set must admit one compatible distribution path with SScribe's
 * GPL-2.0-or-later license.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir  = dirname( __DIR__ );
$dist_dir  = $root_dir . '/dist/sscribe-export-site-pages';
$installed = $dist_dir . '/vendor-prefixed/composer/installed.php';
$lock_path = $root_dir . '/composer.lock';

if ( ! is_dir( $dist_dir ) ) {
	fwrite( STDERR, "Dist tree {$dist_dir} is missing. Run the release build first.\n" );
	exit( 1 );
}
if ( ! is_file( $installed ) ) {
	fwrite( STDERR, "vendor-prefixed/composer/installed.php is missing from the dist tree.\n" );
	exit( 1 );
}
if ( ! is_file( $lock_path ) ) {
	fwrite( STDERR, "composer.lock is missing; dependency-license provenance cannot be verified.\n" );
	exit( 1 );
}

$lock = json_decode( (string) file_get_contents( $lock_path ), true );
if ( ! is_array( $lock ) ) {
	fwrite( STDERR, "composer.lock is not valid JSON.\n" );
	exit( 1 );
}

$lock_meta = array();
foreach ( array_merge( (array) ( $lock['packages'] ?? array() ), (array) ( $lock['packages-dev'] ?? array() ) ) as $package ) {
	if ( ! is_array( $package ) || empty( $package['name'] ) ) {
		continue;
	}
	$name = (string) $package['name'];
	$lock_meta[ $name ] = array(
		'licenses'   => array_values( array_map( 'strval', (array) ( $package['license'] ?? array() ) ) ),
		'source_url' => isset( $package['source']['url'] ) ? preg_replace( '/\.git$/', '', (string) $package['source']['url'] ) : '',
	);
}

$individually_compatible = array(
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
);

$v3_path_licenses = array(
	'GPL-3.0-only',
	'GPL-3.0-or-later',
	'LGPL-3.0-only',
	'LGPL-3.0-or-later',
	'Apache-2.0',
);

$runtime_purpose = array(
	'phpoffice/math'     => 'Float-precision math used by PHPWord',
	'phpoffice/phpword'  => 'DOCX rendering engine',
	'psr/container'      => 'PSR-11 dependency injection container interface',
	'tecnickcom/tcpdf'   => 'PDF rendering engine with Unicode and RTL support',
);

$find_license_file = static function ( string $package_dir ): ?string {
	foreach (
		array(
			'LICENSE',
			'LICENSE.txt',
			'LICENSE.TXT',
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
		) as $candidate
	) {
		if ( is_file( $package_dir . '/' . $candidate ) ) {
			return $candidate;
		}
	}
	return null;
};

$extract_spdx = static function ( string $license_path ): ?string {
	if ( ! is_file( $license_path ) ) {
		return null;
	}
	$head = (string) file_get_contents( $license_path, false, null, 0, 8192 );
	if ( preg_match( '/SPDX-License-Identifier:\s*([A-Za-z0-9.\-+]+)/', $head, $match ) ) {
		return trim( $match[1] );
	}
	$sniffs = array(
		'LGPL-3.0-or-later' => '/Lesser General Public License[\s\S]{0,800}either version 3[\s\S]{0,300}any later version/i',
		'GPL-3.0-or-later'  => '/General Public License[\s\S]{0,800}either version 3[\s\S]{0,300}any later version/i',
		'LGPL-3.0-only'     => '/GNU\s+LESSER\s+GENERAL\s+PUBLIC\s+LICENSE[\s\S]{0,200}Version\s+3/i',
		'GPL-3.0-only'      => '/GNU\s+GENERAL\s+PUBLIC\s+LICENSE[\s\S]{0,200}Version\s+3/i',
		'LGPL-2.1-only'     => '/GNU\s+LESSER\s+GENERAL\s+PUBLIC\s+LICENSE[\s\S]{0,200}Version\s+2\.1/i',
		'GPL-2.0-only'      => '/GNU\s+GENERAL\s+PUBLIC\s+LICENSE[\s\S]{0,200}Version\s+2/i',
		'MIT'               => '/Permission\s+is\s+hereby\s+granted,\s+free\s+of\s+charge/i',
		'Apache-2.0'        => '/Apache\s+License[\s,]+Version\s+2\.0/i',
		'MPL-2.0'           => '/Mozilla\s+Public\s+License,?\s+v?\.?\s*2\.0/i',
		'OFL-1.1'           => '/SIL\s+OPEN\s+FONT\s+LICENSE[\s\S]{0,200}Version\s+1\.1/i',
	);
	foreach ( $sniffs as $spdx => $pattern ) {
		if ( preg_match( $pattern, $head ) ) {
			return $spdx;
		}
	}
	return null;
};

$installed_data = include $installed;
if ( ! is_array( $installed_data ) || ! isset( $installed_data['versions'] ) || ! is_array( $installed_data['versions'] ) ) {
	fwrite( STDERR, "vendor-prefixed/composer/installed.php did not return a versions array.\n" );
	exit( 1 );
}

$errors            = array();
$report            = array();
$shipped_licenses  = array();
$package_count     = 0;
$license_missing   = 0;
$license_unknown   = 0;

echo "=== SScribe Third-Party License Inventory ===\n\n";
echo "Plugin license: GPL-2.0-or-later\n";
echo str_pad( 'PACKAGE', 32 ) . str_pad( 'VERSION', 14 ) . str_pad( 'LICENSE', 22 ) . "FILE\n";
echo str_repeat( '-', 100 ) . "\n";

foreach ( $installed_data['versions'] as $name => $meta ) {
	if ( 'simplix-innovations/sscribe-export-site-pages' === $name ) {
		continue;
	}
	$install_path = isset( $meta['install_path'] ) ? (string) $meta['install_path'] : '';
	$version      = isset( $meta['pretty_version'] ) ? (string) $meta['pretty_version'] : (string) ( $meta['version'] ?? 'unknown' );
	if ( '' === $install_path || ! is_dir( $install_path ) ) {
		continue;
	}

	++$package_count;
	$license_file = $find_license_file( $install_path );
	$declared     = (array) ( $lock_meta[ $name ]['licenses'] ?? array() );
	$detected     = null !== $license_file ? $extract_spdx( $install_path . '/' . $license_file ) : null;

	if ( null === $license_file ) {
		$errors[] = "[{$name}] no license file shipped alongside the bundled source.";
		++$license_missing;
	} elseif ( null === $detected ) {
		$errors[] = "[{$name}] SPDX identifier could not be determined from the shipped license text.";
		++$license_unknown;
	}

	if ( empty( $declared ) ) {
		$errors[] = "[{$name}] composer.lock has no SPDX license declaration.";
		++$license_unknown;
		$license_display = $detected ?? 'unknown';
	} else {
		$known = array_values( array_intersect( $declared, $individually_compatible ) );
		if ( empty( $known ) ) {
			$errors[] = "[{$name}] declared license(s) " . implode( ', ', $declared ) . ' are not classified as GPL-compatible for this release.';
			++$license_unknown;
		}
		$license_display = implode( ' OR ', $declared );
		foreach ( $declared as $license ) {
			if ( in_array( $license, $individually_compatible, true ) ) {
				$shipped_licenses[ $name ][] = $license;
			}
		}
	}

	echo str_pad( (string) $name, 32 )
		. str_pad( $version, 14 )
		. str_pad( $license_display, 22 )
		. (string) $license_file . "\n";

	$report[] = array(
		'name'            => (string) $name,
		'version'         => $version,
		'reference'       => isset( $meta['reference'] ) ? substr( (string) $meta['reference'], 0, 12 ) : '',
		'source_url'      => (string) ( $lock_meta[ $name ]['source_url'] ?? '' ),
		'license'         => $license_display,
		'license_file'    => $license_file,
		'license_detected'=> $detected,
		'modified'        => 'yes (Strauss namespace/class prefix)',
		'runtime_purpose' => $runtime_purpose[ $name ] ?? 'transitive runtime dependency',
	);
}

$gpl2_only_packages = array();
$v3_path_packages   = array();
foreach ( $shipped_licenses as $name => $licenses ) {
	if ( in_array( 'GPL-2.0-only', $licenses, true ) ) {
		$gpl2_only_packages[] = $name;
	}
	if ( ! empty( array_intersect( $licenses, $v3_path_licenses ) ) ) {
		$v3_path_packages[] = $name;
	}
}
if ( ! empty( $gpl2_only_packages ) && ! empty( $v3_path_packages ) ) {
	$errors[] = 'Runtime copyleft conflict: GPL-2.0-only package(s) ['
		. implode( ', ', $gpl2_only_packages )
		. '] are combined with package(s) requiring a GPLv3-compatible path ['
		. implode( ', ', $v3_path_packages )
		. '].';
}

$fonts_root = $dist_dir . '/assets/fonts';
if ( is_dir( $fonts_root ) ) {
	foreach ( (array) glob( $fonts_root . '/*', GLOB_ONLYDIR ) as $font_dir ) {
		$font_files = glob( $font_dir . '/*.{ttf,otf,woff,woff2}', GLOB_BRACE );
		if ( empty( $font_files ) ) {
			continue;
		}
		$family       = basename( $font_dir );
		$license_file = $find_license_file( $font_dir );
		$spdx         = null !== $license_file ? $extract_spdx( $font_dir . '/' . $license_file ) : null;
		if ( null === $license_file ) {
			$errors[] = "[fonts/{$family}] no font license file ships with the font family.";
		} elseif ( null === $spdx || ! in_array( $spdx, $individually_compatible, true ) ) {
			$errors[] = "[fonts/{$family}] font license is missing or not classified as compatible.";
		}
		$report[] = array(
			'name'            => $family . ' (font family)',
			'version'         => 'n/a',
			'reference'       => '',
			'source_url'      => 'https://fonts.google.com/specimen/Amiri',
			'license'         => (string) $spdx,
			'license_file'    => $license_file,
			'license_detected'=> $spdx,
			'modified'        => 'no',
			'runtime_purpose' => 'Bundled document font',
		);
	}
}

$inventory_path = $root_dir . '/dist/third-party-license-inventory.json';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Build-time artifact generation.
file_put_contents(
	$inventory_path,
	json_encode(
		array(
			'plugin_license' => 'GPL-2.0-or-later',
			'generated_at'   => gmdate( 'c' ),
			'packages'       => $report,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	)
);

echo "\nPackages inventoried: {$package_count}\n";
echo "License files missing: {$license_missing}\n";
echo "License compatibility unknowns: {$license_unknown}\n";
echo "Inventory persisted to: {$inventory_path}\n\n";

if ( ! empty( $errors ) ) {
	echo "Errors:\n";
	foreach ( $errors as $error ) {
		echo "  - {$error}\n";
	}
	exit( 1 );
}

echo "Third-party license inventory holds. Every shipped package retains its license text and the combined runtime license set is compatible with SScribe's GPL-2.0-or-later distribution.\n";
exit( 0 );
