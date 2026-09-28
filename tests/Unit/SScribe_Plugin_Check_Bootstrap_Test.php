<?php
/** Exercise bootstrap ordering in fresh processes, where constants cannot leak. */
declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/lib/build-workspace.php';

final class SScribe_Plugin_Check_Bootstrap_Test extends TestCase {

	public function test_active_plugin_owns_its_constant_without_duplicate_warning(): void {
		$this->run_bootstrap( true );
	}

	public function test_inactive_plugin_gets_fallback_after_wordpress_loads(): void {
		$this->run_bootstrap( false );
	}

	private function run_bootstrap( bool $active ): void {
		$root = sys_get_temp_dir() . '/sscribe-bootstrap-' . bin2hex( random_bytes( 6 ) );
		$plugin = $root . '/wp-content/plugins/plugin-check';
		mkdir( $plugin, 0700, true );
		file_put_contents( $plugin . '/cli.php', '<?php $GLOBALS["cli_loaded"] = true;' );
		$bootstrap = var_export( dirname( __DIR__, 2 ) . '/scripts/plugin-check-cli-bootstrap.php', true );
		$script = <<<'PHP'
<?php
error_reporting( E_ALL );
set_error_handler( static function ( $severity, $message ) { throw new RuntimeException( $message ); } );
class WP_CLI {
    public static array $hooks = array();
    public static function add_hook( $name, $callback ) { self::$hooks[$name][] = $callback; }
}
function switch_to_locale( $locale ) { $GLOBALS['report_locale'] = $locale; }
PHP;
		$script .= "\nrequire $bootstrap;\n";
		$script .= <<<'PHP'
if ( empty( $GLOBALS['cli_loaded'] ) || defined( 'WP_PLUGIN_CHECK_PLUGIN_DIR_PATH' ) ) {
    throw new RuntimeException( 'CLI must load early without taking ownership of the plugin constant.' );
}
PHP;
		if ( $active ) {
			// This is the unconditional define in Plugin Check's own plugin.php.
			$script .= "\ndefine( 'WP_PLUGIN_CHECK_PLUGIN_DIR_PATH', 'plugin-owned-path/' );\n";
		}
		$expected = var_export( $active ? 'plugin-owned-path/' : str_replace( '\\', '/', $plugin ) . '/', true );
		$script .= "\nforeach ( WP_CLI::\$hooks['after_wp_load'] as \$hook ) { \$hook(); }\n";
		$script .= "if ( WP_PLUGIN_CHECK_PLUGIN_DIR_PATH !== $expected || \$GLOBALS['report_locale'] !== 'en_US' ) { throw new RuntimeException( 'Wrong path or locale.' ); }\necho 'PASS';\n";
		file_put_contents( $root . '/run.php', $script );
		try {
			$proc = proc_open( array( PHP_BINARY, $root . '/run.php' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $root . '/output.log', 'w' ), 2 => array( 'redirect', 1 ) ), $pipes, $root, array_merge( getenv(), array( 'SSCRIBE_WP_ROOT' => $root ) ) );
			self::assertIsResource( $proc );
			fclose( $pipes[0] );
			$code = proc_close( $proc );
			$output = (string) file_get_contents( $root . '/output.log' );
			self::assertSame( 0, $code, $output );
			self::assertSame( 'PASS', $output );
		} finally {
			sscribe_remove_build_path( $root );
		}
	}
}
