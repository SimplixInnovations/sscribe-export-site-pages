<?php
/**
 * Prefixed runtime shim : keeps third-party mPDF / PHPWord callsites
 * working under the release ZIP's SScribeVendor\* namespace.
 *
 * @see docs/extension-points.md for the public surface this preserves.
 *
 * Why this file exists
 * --------------------
 * The release ZIP runs each third-party library under a per-vendor
 * namespace prefix (composer-prefixed config renames
 * `PhpOffice\PhpWord\*` → `SScribeVendor\PhpOffice\PhpWord\*` and
 * `Mpdf\*` → `SScribeVendor\Mpdf\*`). The plugin's own code uses the
 * prefixed names everywhere.
 *
 * Third-party plugins, themes, and WordPress core itself occasionally
 * construct one of these classes under its ORIGINAL unprefixed name:
 *
 *   new \PhpOffice\PhpWord\PhpWord();
 *   new \Mpdf\Mpdf();
 *
 * Under PHP's class resolution, this triggers the autoloader looking
 * for `PhpOffice\PhpWord\PhpWord` (the unprefixed name). The
 * composer-generated PSR-4 autoloader only knows the prefixed
 * namespace, so the lookup fails with `Class "PhpOffice\PhpWord\
 * PhpWord" not found` : even though the SHIPPED library file lives at
 * `vendor-prefixed/phpoffice/phpword/src/PhpWord/PhpWord/PhpWord.php`.
 *
 * What this shim does
 * -------------------
 * 1. For the 12 commonly-aliased classes, eagerly `class_alias()` the
 *    prefixed source to the unprefixed name AS SOON AS the prefixed
 *    class has been loaded by the composer autoloader. This is the
 *    "first instantiation wins" path: production code that uses the
 *    prefixed names (the plugin's own exporter classes) warms the
 *    alias map, after which third-party code can use either name.
 *
 * 2. Register an `spl_autoload_register` fallback that, when the
 *    autoloader is asked for an unprefixed name AND the prefixed
 *    source has not yet been loaded, manually requires the prefixed
 *    file and creates the alias on the fly. This is the "cold path":
 *    a third-party plugin does `new \Mpdf\Mpdf()` as the very first
 *    reference, before the plugin itself has touched mPDF.
 *
 *    The fallback runs AFTER composer's autoloader (registered first,
 *    gets first refusal). It only fires if composer couldn't resolve
 *    the name : never on a name composer already knows.
 *
 * Failure modes
 * -------------
 * - The file path derived from $sscribe_unprefixed_to_prefixed is
 *   wrong (vendor renamed, class moved) → the is_file() check fails
 *   and we silently return; the next autoloader in the chain (or
 *   PHP's `__autoload` fatal) handles the request. No crash; just a
 *   missing-class error in the caller.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// composer-prefixed PSR-4 namespace map for the two libraries this shim
// covers. Hardcoded here (not loaded from vendor-prefixed/composer/) so
// the shim has no dependency on the composer's autoloader being
// functional : it must work even if the composer's autoload_psr4.php
// is corrupted or partial.
$sscribe_prefixed_aliases = array(
	'\SScribeVendor\PhpOffice\PhpWord\PhpWord'           => '\PhpOffice\PhpWord\PhpWord',
	'\SScribeVendor\PhpOffice\PhpWord\IOFactory'         => '\PhpOffice\PhpWord\IOFactory',
	'\SScribeVendor\PhpOffice\PhpWord\Settings'          => '\PhpOffice\PhpWord\Settings',
	'\SScribeVendor\PhpOffice\PhpWord\Style\Font'        => '\PhpOffice\PhpWord\Style\Font',
	'\SScribeVendor\PhpOffice\PhpWord\Style\TOC'         => '\PhpOffice\PhpWord\Style\TOC',
	'\SScribeVendor\PhpOffice\PhpWord\Style\ListItem'    => '\PhpOffice\PhpWord\Style\ListItem',
	'\SScribeVendor\PhpOffice\PhpWord\SimpleType\Jc'     => '\PhpOffice\PhpWord\SimpleType\Jc',
	'\SScribeVendor\PhpOffice\PhpWord\SimpleType\TblWidth' => '\PhpOffice\PhpWord\SimpleType\TblWidth',
	'\SScribeVendor\PhpOffice\PhpWord\Shared\Converter'  => '\PhpOffice\PhpWord\Shared\Converter',
	'\SScribeVendor\PhpOffice\PhpWord\Element\Section'   => '\PhpOffice\PhpWord\Element\Section',
	'\SScribeVendor\PhpOffice\PhpWord\Element\TextRun'   => '\PhpOffice\PhpWord\Element\TextRun',
	'\SScribeVendor\Mpdf\Mpdf'                            => '\Mpdf\Mpdf',
);

// Inverse map: unprefixed name → prefixed name. Used by the fallback
// autoloader to translate a third-party class request into the
// prefixed name we actually ship.
$sscribe_unprefixed_to_prefixed = array();
foreach ( $sscribe_prefixed_aliases as $sscribe_source => $sscribe_alias ) {
	$sscribe_unprefixed_to_prefixed[ ltrim( $sscribe_alias, '\\' ) ] = ltrim( $sscribe_source, '\\' );
}

// PSR-4 root paths for each leading namespace segment of the unprefixed
// names we alias. Matches the entries in vendor-prefixed/composer/
// autoload_psr4.php for the two libraries this shim covers. Keys are
// the namespace segments immediately under the root; values are the
// on-disk path roots relative to vendor-prefixed/.
$sscribe_root_for_prefix = array(
	'PhpOffice\\PhpWord' => 'phpoffice/phpword/src/PhpWord',
	'Mpdf'               => 'mpdf/mpdf/src',
);

// Resolve an unprefixed class name to the on-disk file under
// vendor-prefixed/ that contains the prefixed class. Returns null if
// the unprefixed name is not in the shim's alias map.
$sscribe_resolve_prefixed_file = static function ( string $unprefixed ) use ( &$sscribe_root_for_prefix, &$sscribe_unprefixed_to_prefixed ): ?string {
	if ( ! isset( $sscribe_unprefixed_to_prefixed[ $unprefixed ] ) ) {
		return null;
	}
	foreach ( $sscribe_root_for_prefix as $sscribe_prefix_part => $sscribe_path_root ) {
		$sscribe_prefix_with_sep = $sscribe_prefix_part . '\\';
		if ( 0 === strncmp( $unprefixed, $sscribe_prefix_with_sep, strlen( $sscribe_prefix_with_sep ) ) ) {
			$sscribe_relative = str_replace( '\\', '/', substr( $unprefixed, strlen( $sscribe_prefix_part ) ) );
			return rtrim( __DIR__ . '/../vendor-prefixed/' . $sscribe_path_root, '/' )
				. ( '' === $sscribe_relative ? '' : $sscribe_relative ) . '.php';
		}
	}
	return null;
};

// Eagerly create aliases for any prefixed class that is already loaded.
// Production code uses prefixed names first; once those classes are
// defined, the unprefixed names become available for third-party use
// without needing the fallback autoloader.
foreach ( $sscribe_prefixed_aliases as $sscribe_source => $sscribe_alias ) {
	if ( class_exists( $sscribe_source, false ) && ! class_exists( $sscribe_alias, false ) ) {
		class_alias( $sscribe_source, $sscribe_alias );
	}
}

// Fallback autoloader: registered AFTER the composer autoloader. Fires
// only if composer could not resolve the class. Translates the
// unprefixed request into a require of the prefixed file, then creates
// the alias. Without this, a third-party plugin that does
// `new \Mpdf\Mpdf()` before the plugin's own code has instantiated
// `\SScribeVendor\Mpdf\Mpdf` (and thus warmed the alias) hits
// `Class "Mpdf\Mpdf" not found`.
spl_autoload_register(
	static function ( string $sscribe_class_name ) use ( $sscribe_prefixed_aliases, $sscribe_unprefixed_to_prefixed, $sscribe_resolve_prefixed_file ): void {
		$sscribe_normalized          = ltrim( $sscribe_class_name, '\\' );
		$sscribe_unprefixed_target   = '\\' . $sscribe_normalized;

		// Bail if the request is for a prefixed name (composer autoloader
		// handles those) : if it reached us, composer gave up.
		if ( isset( $sscribe_prefixed_aliases[ $sscribe_unprefixed_target ] ) ) {
			return;
		}
		// Bail if the unprefixed name isn't in our alias map.
		if ( ! isset( $sscribe_unprefixed_to_prefixed[ $sscribe_normalized ] ) ) {
			return;
		}

		$sscribe_file = $sscribe_resolve_prefixed_file( $sscribe_normalized );
		if ( null === $sscribe_file || ! is_file( $sscribe_file ) ) {
			return;
		}

		require_once $sscribe_file;

		// class_exists($prefixed, false) : the prefixed class was just
		// declared by the require above. Create the alias for any
		// future request under the unprefixed name.
		$sscribe_prefixed_name = $sscribe_unprefixed_to_prefixed[ $sscribe_normalized ];
		if ( class_exists( $sscribe_prefixed_name, false ) && ! class_exists( $sscribe_unprefixed_target, false ) ) {
			class_alias( $sscribe_prefixed_name, ltrim( $sscribe_unprefixed_target, '\\' ) );
		}
	}
);
