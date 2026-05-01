<?php
/**
 * Automated release commit script.
 *
 * Commits the version bump and optionally creates a tag.
 * Usage: php scripts/release-commit.php X.Y.Z [--tag]
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' . PHP_EOL );
}

if ( $argc < 2 ) {
	echo "Usage: php scripts/release-commit.php <version> [--tag]\n";
	echo "Example: php scripts/release-commit.php X.Y.Z --tag\n";
	exit( 1 );
}

$new_version = $argv[1];
$create_tag  = in_array( '--tag', $argv, true );

if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $new_version ) ) {
	echo "Error: Version must be in format major.minor.patch (e.g., X.Y.Z)\n";
	exit( 1 );
}

$root_dir = dirname( __DIR__ );

echo "Committing release v{$new_version}...\n";

// Stage all changes.
exec( 'git add -A', $add_output, $add_exit );
if ( 0 !== $add_exit ) {
	echo "Error: git add failed.\n";
	exit( 1 );
}

// Commit.
$commit_msg = sprintf( 'chore: release v%s', $new_version );
exec( sprintf( 'git commit -m "%s"', $commit_msg ), $commit_output, $commit_exit );

if ( 0 !== $commit_exit ) {
	// Check if "nothing to commit".
	if ( ! empty( $commit_output ) && str_contains( implode( "\n", $commit_output ), 'nothing to commit' ) ) {
		echo "Nothing to commit — version already bumped?\n";
	} else {
		echo "Error: git commit failed.\n";
		exit( 1 );
	}
} else {
	echo "✓ Committed: {$commit_msg}\n";
}

// Push to develop.
exec( 'git push origin develop', $push_output, $push_exit );
if ( 0 !== $push_exit ) {
	echo "Error: git push develop failed.\n";
	exit( 1 );
}
echo "✓ Pushed to develop\n";

// Merge to main and push.
exec( 'git checkout main', $co_output, $co_exit );
exec( 'git merge develop', $merge_output, $merge_exit );
exec( 'git push origin main', $push_main_output, $push_main_exit );
exec( 'git checkout develop', $co_dev_output, $co_dev_exit );

if ( 0 === $push_main_exit ) {
	echo "✓ Merged to main and pushed\n";
}

// Create tag if requested.
if ( $create_tag ) {
	$tag = 'v' . $new_version;
	exec( sprintf( 'git tag -a %s -m "Release %s"', $tag, $tag ), $tag_output, $tag_exit );
	if ( 0 === $tag_exit ) {
		exec( sprintf( 'git push origin %s', $tag ), $tag_push_output, $tag_push_exit );
		if ( 0 === $tag_push_exit ) {
			echo "✓ Tag {$tag} created and pushed (triggers release workflow)\n";
		}
	}
}

echo "\n✓ Release v{$new_version} complete!\n";
exit( 0 );

