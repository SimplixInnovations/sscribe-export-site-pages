<?php
/**
 * Run strauss with E_WARNING suppressed.
 *
 * brianhenryie/strauss 0.27.x reads `Stmt\Namespace_::$name` without
 * a null guard, but PHP-Parser v5 typed that property as Name|null.
 * Files using the global namespace (no `namespace ...;` declaration,
 * or the explicit `namespace { ... }` block syntax) trip a PHP 8.2+
 * E_WARNING on the read-on-null access. The prefixing still succeeds
 * (the visitor's only side-effect on a null-name namespace is
 * appending nothing to `$this->using`, which is harmless), so this
 * is a known false positive.
 *
 * We can't filter the warning via a custom set_error_handler because
 * Monolog\ErrorHandler and Composer\Util\ErrorHandler both install
 * their own handlers during vendor autoload and via lazy logger
 * setup, clobbering any outer filter. The robust fix is to drop
 * E_WARNING from the script's error_reporting so both Monolog and
 * Composer defer it as "not in error_reporting" without logging it.
 * (E_DEPRECATED is dropped the same way for the Symfony console
 * deprecation about Application::add() → Application::addCommand().)
 *
 * Once upstream strauss handles the null-name case, this script can
 * be deleted and `vendor:prefix` reverted to a direct
 * `php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/strauss`
 * invocation.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
	fwrite( STDERR, "vendor/autoload.php not found; run `composer install` first.\n" );
	exit( 1 );
}

// Match the CLI -d flag in composer.json so direct invocation also silences
// the false positive (and the Symfony 7.4 Application::add() deprecation).
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING );

require $autoload;

if ( ! class_exists( BrianHenryIE\Strauss\Console\Application::class ) ) {
	fwrite( STDERR,
		'You must set up the project dependencies, run the following commands:' . PHP_EOL
		. 'curl -s https://getcomposer.org/installer | php' . PHP_EOL
		. 'php composer.phar install' . PHP_EOL
	);
	exit( 1 );
}

$app = new BrianHenryIE\Strauss\Console\Application( '0.27.2' );
$app->run();
