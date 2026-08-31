<?php
/**
 * WordPress test-suite configuration.
 *
 * Copied into tests-wp/_wordpress-tests-lib/wp-tests-config.php by
 * bin/install-wp-tests.sh. Both MySQL/MariaDB credentials and the SQLite
 * drop-in path are supported.
 *
 * For SQLite (default for this project): the SQLite Database Integration
 * plugin ships a wp-content/db.php drop-in that intercepts wpdb and routes
 * queries through the SQLite3 PHP extension. DB_HOST is intentionally set
 * to a localhost placeholder so the real WP load process picks up the
 * drop-in before db.php connects; the drop-in ignores DB_HOST/DB_NAME.
 *
 * If you want to use real MySQL/MariaDB instead, set DB_HOST/DB_USER/
 * DB_PASSWORD/DB_NAME env vars before invoking phpunit.
 */

// phpcs:disable WordPress.PHP.NoSilencedErrors -- Test bootstrap runs before WP.

// Path to WordPress core (parent of wp-load.php). Override with WP_CORE_DIR env.
$wp_core_dir = getenv( 'WP_CORE_DIR' );
if ( ! $wp_core_dir ) {
	// This file lives at tests-wp/_wordpress-tests-lib/wp-tests-config.php
	// and WordPress core lives at tests-wp/_wordpress/.
	$wp_core_dir = dirname( __DIR__ ) . '/_wordpress';
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $wp_core_dir . '/' );
}

if ( ! defined( 'WP_TEST_CORE_DIR' ) ) {
	define( 'WP_TEST_CORE_DIR', $wp_core_dir );
}

// Test database credentials. When the SQLite drop-in is in place, these
// values are largely ignored but must still be present and parseable.
define( 'DB_NAME', getenv( 'WP_TEST_DB_NAME' ) ?: 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_TEST_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TEST_DB_PASSWORD' ) ?: '' );
define( 'DB_HOST', getenv( 'WP_TEST_DB_HOST' ) ?: 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

// Test URLs / domain used by the WP test suite.
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_TESTS_NETWORK_TITLE', 'Test Network' );

// Table prefix.
$table_prefix = getenv( 'WP_TEST_TABLE_PREFIX' ) ?: 'wp_';

// wp-phpunit/bootstrap.php also defines WP_INSTALLING and DISABLE_WP_CRON.
// We omit them here to avoid "Constant already defined" warnings; the
// wp-phpunit bootstrap owns these values.