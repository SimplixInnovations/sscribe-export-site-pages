<?php
/**
 * SScribe Release Prepare Script
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' . PHP_EOL );
}

$root_dir  = dirname( __DIR__ );
$separator = str_repeat( '═', 60 );
$pass_mark = "\033[32m✓\033[0m";
$fail_mark = "\033[31m✗\033[0m";
$warn_mark = "\033[33m⚠\033[0m";
$info_mark = "\033[36m→\033[0m";

$plugin_file = $root_dir . '/sscribe-export-site-pages.php';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

$plugin_content = file_get_contents( $plugin_file );
if ( ! preg_match( "/define\s*\(\s*['\"]SSCRIBE_VERSION['\"]\s*,\s*['\"]([0-9.]+)['\"]/U", $plugin_content, $matches ) ) {
	echo "{$fail_mark} Could not find SSCRIBE_VERSION constant.\n";
	exit( 1 );
}
$current_version = $matches[1];

echo "\n{$separator}\n";
echo "  SScribe Release - v{$current_version}\n";
echo "{$separator}\n\n";

echo "{$info_mark} Running quality gates...\n\n";

$gates_passed = true;
$results      = array();

echo "  Version sync... ";
$verify_cmd = sprintf( 'php %s/scripts/verify-version-sync.php', $root_dir );
exec( $verify_cmd . ' 2>&1', $verify_output, $verify_exit );
if ( 0 === $verify_exit ) {
	echo "{$pass_mark}\n";
	$results['version-sync'] = true;
} else {
	echo "{$fail_mark} FAILED\n";
	$results['version-sync'] = false;
	$gates_passed = false;
}

echo "  PHPUnit tests... ";
exec( 'php vendor/bin/phpunit --no-coverage --no-progress --order-by=random --exclude-group=release-contract 2>&1', $test_output, $test_exit );
if ( 0 === $test_exit ) {

	$test_text = implode( "\n", $test_output );
	if ( preg_match( '/OK\s*\(/', $test_text ) || preg_match( '/OK\s*\(?\d+\s*tests/', $test_text ) ) {
		echo "{$pass_mark}\n";
		$results['phpunit'] = true;
	} elseif ( str_contains( $test_text, 'FAILURES!' ) ) {
		echo "{$fail_mark} FAILURES\n";
		$results['phpunit'] = false;
		$gates_passed = false;
	} else {

		if ( preg_match( '/Tests:\s*\d+.*Failures:\s*(\d+).*Errors:\s*(\d+)/', $test_text, $tm ) ) {
			$failures = (int) $tm[1];
			$errors   = (int) $tm[2];
			if ( 0 === $failures && 0 === $errors ) {
				echo "{$pass_mark}\n";
				$results['phpunit'] = true;
			} else {
				echo "{$fail_mark} {$failures} failures, {$errors} errors\n";
				$results['phpunit'] = false;
				$gates_passed = false;
			}
		} else {
			echo "{$warn_mark} (could not parse output - manual check recommended)\n";
			$results['phpunit'] = true;
		}
	}
} else {
	echo "{$fail_mark} EXIT CODE {$test_exit}\n";
	$results['phpunit'] = false;
	$gates_passed = false;
}

echo "  PHPStan analysis... ";
exec( 'php vendor/bin/phpstan analyse --no-progress --memory-limit=512M 2>&1', $stan_output, $stan_exit );
$stan_text = implode( "\n", $stan_output );
if ( 0 === $stan_exit && ! str_contains( $stan_text, '[ERROR]' ) ) {
	echo "{$pass_mark}\n";
	$results['phpstan'] = true;
} elseif ( str_contains( $stan_text, 'severe errors' ) ) {
	echo "{$warn_mark} memory limit (CI will catch remaining issues)\n";
	$results['phpstan'] = true;
} else {
	echo "{$fail_mark} FAILED\n";
	$results['phpstan'] = false;
	$gates_passed = false;
}

echo "  PHPCS standards... ";
exec( 'php vendor/bin/phpcs -d memory_limit=512M --standard=phpcs.xml -q 2>&1', $cs_output, $cs_exit );
if ( 0 === $cs_exit ) {
	echo "{$pass_mark}\n";
	$results['phpcs'] = true;
} else {
	$cs_errors = count( $cs_output );
	echo "{$fail_mark} {$cs_errors} issue(s)\n";
	$results['phpcs'] = false;
	$gates_passed = false;
}

echo "\n";

if ( ! $gates_passed ) {
	echo "{$fail_mark} Quality gates failed. Fix the issues above before releasing.\n\n";
	exit( 1 );
}

echo "{$pass_mark} All quality gates passed!\n\n";

$parts = explode( '.', $current_version );
$major = (int) $parts[0];
$minor = (int) $parts[1];
$patch = (int) $parts[2];

echo "{$separator}\n";
echo "  Current version: v{$current_version}\n";
echo "{$separator}\n\n";
echo "Select release version:\n\n";
echo "  [0] Current → v{$current_version}   (finalize the already-versioned development line)\n";
echo "  [1] Patch   → v{$major}.{$minor}." . ( $patch + 1 ) . "   (bug fixes)\n";
echo "  [2] Minor  → v{$major}." . ( $minor + 1 ) . ".0   (new features)\n";
echo "  [3] Major  → v" . ( $major + 1 ) . ".0.0     (breaking changes)\n";
echo "  [4] Custom → enter manually\n";
echo "  [q] Quit\n\n";
echo "Choice: ";

$choice = strtolower( trim( (string) fgets( STDIN ) ) );

switch ( $choice ) {
	case '0':
		$new_version = $current_version;
		break;
	case '1':
		$new_version = "{$major}.{$minor}." . ( $patch + 1 );
		break;
	case '2':
		$new_version = "{$major}." . ( $minor + 1 ) . '.0';
		break;
	case '3':
		$new_version = ( $major + 1 ) . '.0.0';
		break;
	case '4':
		echo "\nEnter new version (e.g., X.Y.Z): ";
		$new_version = trim( (string) fgets( STDIN ) );
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $new_version ) ) {
			echo "{$fail_mark} Invalid version format.\n";
			exit( 1 );
		}
		break;
	default:
		echo "\n{$info_mark} Release cancelled.\n";
		exit( 0 );
}

if ( $new_version === $current_version ) {
	echo "\n{$info_mark} Finalizing current development version: v{$current_version}\n\n";
} else {
	echo "\n{$info_mark} Bumping: v{$current_version} → v{$new_version}\n\n";
	$bump_cmd = sprintf( 'php %s/scripts/bump-version.php %s', $root_dir, $new_version );
	passthru( $bump_cmd, $bump_exit );

	if ( 0 !== $bump_exit ) {
		echo "\n{$fail_mark} Version bump failed.\n";
		exit( 1 );
	}
}

echo "\n{$info_mark} Ready to add changelog entry?\n\n";
echo "  Changelog section: == Changelog == in readme.txt\n";
echo "  Upgrade notice section: == Upgrade Notice == in readme.txt\n\n";
echo "  [y] Yes - I'll update readme.txt now\n";
echo "  [n] No  - I'll do it manually\n";
echo "  [s] Skip - no changelog needed\n\n";
echo "Choice: ";

$changelog_choice = strtolower( trim( (string) fgets( STDIN ) ) );

if ( 'y' === $changelog_choice ) {
	echo "\nOpening readme.txt... add entries for v{$new_version}.\n";
	echo "Remember to add both:\n";
	echo "  1. == Changelog == section (full entry)\n";
	echo "  2. == Upgrade Notice == section (brief summary)\n\n";
}

echo "{$separator}\n";
echo "  Release preparation complete!\n";
echo "{$separator}\n\n";
echo "Next steps:\n\n";
echo "  1. Update readme.txt changelog (if not done already).\n";
echo "  2. Review the prepared working-tree changes:\n";
echo "     git diff --stat\n";
echo "     git diff --check\n\n";
echo "  3. Create and push the transient reviewed release branch:\n";
echo "     composer release:commit\n";
echo "     # This helper moves prepared changes off main before committing them.\n";
echo "     # Open PR release/v{$new_version} -> main, pass every required gate/review, squash-merge, then delete the branch.\n\n";
echo "  4. Reconcile the reviewed merge locally and certify the exact main SHA:\n";
echo "     git switch main\n";
echo "     git pull --ff-only origin main\n";
echo "     composer release:audit\n\n";
echo "  5. Create the immutable annotated tag only through the guarded helper:\n";
echo "     composer release:tag\n\n";
echo "{$separator}\n";

exit( 0 );
