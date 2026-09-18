<?php
/**
 * SScribe Release Commit Script
 *
 * @package SScribe_Export_Site_Pages
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

$branch = trim( (string) shell_exec( 'git branch --show-current' ) );
if ( 'develop' !== $branch ) {
	echo "Error: release-commit must start on develop; current branch is '{$branch}'.\n";
	exit( 1 );
}

$plugin_source = (string) file_get_contents( $root_dir . '/sscribe-export-site-pages.php' );
if ( ! preg_match( "/define\\s*\\(\\s*['\"]SSCRIBE_VERSION['\"]\\s*,\\s*['\"]([0-9]+\\.[0-9]+\\.[0-9]+)['\"]/", $plugin_source, $version_match ) || $version_match[1] !== $new_version ) {
	echo "Error: requested release version does not match SSCRIBE_VERSION.\n";
	exit( 1 );
}

echo "Committing release v{$new_version}...\n";

exec( 'git add -A', $add_output, $add_exit );
if ( 0 !== $add_exit ) {
	echo "Error: git add failed.\n";
	exit( 1 );
}

$commit_msg = sprintf( 'chore: release v%s', $new_version );
exec( sprintf( 'git commit -m "%s"', $commit_msg ), $commit_output, $commit_exit );

if ( 0 !== $commit_exit ) {

	if ( ! empty( $commit_output ) && str_contains( implode( "\n", $commit_output ), 'nothing to commit' ) ) {
		echo "Nothing to commit - version already bumped?\n";
	} else {
		echo "Error: git commit failed.\n";
		exit( 1 );
	}
} else {
	echo "✓ Committed: {$commit_msg}\n";
}

exec( 'git push origin develop', $push_output, $push_exit );
if ( 0 !== $push_exit ) {
	echo "Error: git push develop failed.\n";
	exit( 1 );
}
echo "✓ Pushed to develop\n";

exec( 'git fetch origin', $fetch_output, $fetch_exit );
if ( 0 !== $fetch_exit ) {
	echo "Error: git fetch origin failed.\n";
	exit( 1 );
}

exec( 'git checkout main', $co_output, $co_exit );
if ( 0 !== $co_exit ) {
	echo "Error: git checkout main failed.\n";
	exit( 1 );
}

exec( 'git pull --ff-only origin main', $pull_output, $pull_exit );
if ( 0 !== $pull_exit ) {
	echo "Error: local main cannot fast-forward to origin/main.\n";
	exec( 'git checkout develop', $co_back_output, $co_back_exit );
	exit( 1 );
}

exec( 'git merge --ff-only origin/develop', $merge_output, $merge_exit );
if ( 0 !== $merge_exit ) {
	echo "Error: main cannot fast-forward to origin/develop; reconcile branch history before release.\n";
	exec( 'git checkout develop', $co_back_output, $co_back_exit );
	exit( 1 );
}

exec( 'git push origin main', $push_main_output, $push_main_exit );
if ( 0 !== $push_main_exit ) {
	echo "Error: git push main failed.\n";
	exit( 1 );
}
echo "✓ Fast-forwarded main to develop and pushed\n";

exec( 'git checkout develop', $co_dev_output, $co_dev_exit );
if ( 0 !== $co_dev_exit ) {
	echo "Warning: git checkout develop failed.\n";
}

if ( $create_tag ) {
	$tag = 'v' . $new_version;

	exec( sprintf( 'git ls-remote --exit-code --tags origin refs/tags/%s', $tag ), $existing_tag_output, $existing_tag_exit );
	if ( 0 === $existing_tag_exit ) {
		echo "Error: remote tag {$tag} already exists; release tags are immutable.\n";
		exit( 1 );
	}

	exec( sprintf( 'git tag -a %s -m "Release %s"', $tag, $tag ), $tag_output, $tag_exit );
	if ( 0 !== $tag_exit ) {
		echo "Error: could not create annotated tag {$tag}.\n";
		exit( 1 );
	}

	exec( sprintf( 'git push origin %s', $tag ), $tag_push_output, $tag_push_exit );
	if ( 0 !== $tag_push_exit ) {
		echo "Error: could not push tag {$tag}; local tag was not rewritten.\n";
		exit( 1 );
	}

	echo "✓ Annotated tag {$tag} created and pushed (triggers release workflow)\n";
}

echo "\n✓ Release v{$new_version} complete!\n";
exit( 0 );
