<?php
/**
 * Phase 24: Upgrade Notice CI.
 *
 * WordPress.org surfaces the readme.txt Upgrade Notice section to
 * every site admin when the plugin is updated. A missing, stale, or
 * malformed notice means a real bug fix or migration step ships
 * without being seen — operators click "update details" looking for
 * the security fix that prompted the update, see nothing, and assume
 * nothing changed.
 *
 * This script enforces the WP.org contract:
 *
 *   1. The readme.txt MUST contain an `== Upgrade Notice ==` section.
 *   2. The current Stable tag (= readme.txt "Stable tag") MUST have
 *      an entry there, with at least 50 characters of body text. WP.org
 *      hides notices shorter than that because they do not communicate
 *      anything actionable.
 *   3. The entry body MUST be plain prose. No HTML tags, no markdown
 *      links, no fenced code blocks — WordPress.org renders it as
 *      flat text and any tag shows up literally.
 *   4. The entry body MUST be a single paragraph (no blank-line
 *      separators). The WP.org renderer treats each blank-line group
 *      as a new block, which fragments the message.
 *   5. Every Upgrade Notice entry version MUST also exist as a
 *      Changelog `= X.Y.Z =` header, otherwise the notice references
 *      a release that no longer exists.
 *   6. The Stable tag entry's first sentence MUST NOT be empty.
 *
 * Exits 0 on success, 1 on any violation. Prints a clear report so
 * CI logs show exactly what needs to change.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir = dirname( __DIR__ );

$readme_path = $root_dir . '/readme.txt';
if ( ! is_file( $readme_path ) ) {
	fwrite( STDERR, "readme.txt not found at {$readme_path}\n" );
	exit( 1 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$readme_content = (string) file_get_contents( $readme_path );

$stable_tag = '';
if ( preg_match( '/^Stable tag:\s*([^\s]+)/m', $readme_content, $stable_match ) ) {
	$stable_tag = trim( (string) $stable_match[1] );
}
if ( '' === $stable_tag ) {
	fwrite( STDERR, "Could not find 'Stable tag:' line in readme.txt\n" );
	exit( 1 );
}

$errors = array();

// 1. The Upgrade Notice section MUST exist.
$upgrade_section = '';
if ( preg_match( '/^== Upgrade Notice ==\s*$(.*?)(?=^== |\z)/sm', $readme_content, $scope_match ) ) {
	$upgrade_section = (string) $scope_match[1];
}
if ( '' === $upgrade_section ) {
	$errors[] = 'readme.txt is missing the `== Upgrade Notice ==` section. WP.org shows this to every admin during update; an empty section means the update message is invisible.';
}

// 2-6. Per-version checks within the Upgrade Notice scope.
$entries = array();
if ( '' !== $upgrade_section ) {
	if ( preg_match_all( '/^= (\d+\.\d+\.\d+(?:\.\d+)?) =\s*$(.+?)(?=^= \d+\.\d+\.\d+|\z)/sm', $upgrade_section, $entry_matches, PREG_SET_ORDER ) ) {
		foreach ( $entry_matches as $m ) {
			$entries[ trim( $m[1] ) ] = (string) $m[2];
		}
	}
}

// Changelog scope — used to validate the upgrade notice entries
// don't reference versions that no longer exist.
$changelog_versions = array();
if ( preg_match( '/^== Changelog ==\s*$(.*?)(?=^== |\z)/sm', $readme_content, $cl_match ) ) {
	$changelog_scope = (string) $cl_match[1];
	if ( preg_match_all( '/^= (\d+\.\d+\.\d+(?:\.\d+)?) =/m', $changelog_scope, $cl_versions ) ) {
		$changelog_versions = array_map( 'trim', $cl_versions[1] );
	}
}

// Per-version validation.
foreach ( $entries as $version => $body ) {
	// 5. Each upgrade notice version must also exist in the Changelog.
	if ( ! in_array( $version, $changelog_versions, true ) ) {
		$errors[] = sprintf(
			'Upgrade Notice references version %s which is not present in the Changelog. Either remove the entry or add a Changelog `= %s =` header.',
			$version,
			$version
		);
	}

	$trimmed = trim( $body );

	// 2. Body must be at least 50 chars.
	if ( strlen( $trimmed ) < 50 ) {
		$errors[] = sprintf(
			'Upgrade Notice entry for %s is %d chars long; WP.org hides notices shorter than 50 chars. Expand the message to describe what changed and any required action.',
			$version,
			strlen( $trimmed )
		);
	}

	// 3. No HTML tags.
	if ( preg_match( '/<[a-zA-Z][^>]*>/', $trimmed ) ) {
		$errors[] = sprintf(
			'Upgrade Notice entry for %s contains an HTML tag. WP.org renders the notice as flat text; tags appear literally. Replace with plain prose.',
			$version
		);
	}

	// 3. No markdown links / images.
	if ( preg_match( '/!?\[[^\]]+\]\([^)]+\)/', $trimmed ) ) {
		$errors[] = sprintf(
			'Upgrade Notice entry for %s contains a Markdown link or image. WP.org renders the notice as flat text; replace with plain prose.',
			$version
		);
	}

	// 4. Single paragraph (no blank-line separators).
	if ( preg_match( '/\R\R+/', $trimmed ) ) {
		$errors[] = sprintf(
			'Upgrade Notice entry for %s contains a blank-line paragraph break. WP.org fragments the notice across multiple blocks. Collapse to a single paragraph.',
			$version
		);
	}

	// 6. The first sentence must not be empty.
	$first_sentence = '';
	if ( preg_match( '/^([^\.\!\?]+)/', $trimmed, $first_match ) ) {
		$first_sentence = trim( (string) $first_match[1] );
	}
	if ( '' === $first_sentence ) {
		$errors[] = sprintf(
			'Upgrade Notice entry for %s has an empty first sentence. Open with what changed in this release.',
			$version
		);
	}
}

// The Stable tag entry must exist in the Upgrade Notice section.
if ( ! isset( $entries[ $stable_tag ] ) && '' !== $upgrade_section ) {
	$errors[] = sprintf(
		'Stable tag is %s but there is no Upgrade Notice entry for it. WP.org will display no message during the update; add a `= %s =` block under `== Upgrade Notice ==`.',
		$stable_tag,
		$stable_tag
	);
}

echo "=== SScribe Upgrade Notice Verification ===\n\n";
echo "Stable tag: {$stable_tag}\n";
echo 'Upgrade notice versions: ' . ( '' === $upgrade_section ? '(none)' : implode( ', ', array_keys( $entries ) ) ) . "\n";
echo 'Changelog versions: ' . ( '' === $changelog_versions ? '(none)' : implode( ', ', $changelog_versions ) ) . "\n\n";

if ( ! empty( $errors ) ) {
	echo "Errors:\n";
	foreach ( $errors as $error ) {
		echo "  ✗ {$error}\n";
	}
	echo "\n";
	exit( 1 );
}

echo "✓ Upgrade Notice contract holds.\n";
exit( 0 );
