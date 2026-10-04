<?php
/**
 * SScribe multisite activation and multisite uninstall unit test
 *
 * Pins the network-wide provisioning and cleanup contracts:
 *
 *   - includes/class-sscribe-activator.php:100-101 (activate( true ) routes
 *     to activate_network_wide() on multisite)
 *   - includes/class-sscribe-activator.php:133-168 (activate_network_wide():
 *     paginated get_sites() walk with switch_to_blog()/restore_current_blog()
 *     around a per-site activate_single_site())
 *   - includes/class-sscribe-activator.php:207-219 (activate_single_site()
 *     provisions sscribe_version / sscribe_schema_version per site)
 *   - uninstall.php:165-193 (the multisite uninstall loop: paginated
 *     get_sites() walk, switch_to_blog() + $sscribe_cleanup_site() wrapped in
 *     try/finally restore_current_blog())
 *
 * The unit bootstrap already provides the multisite surface (is_multisite,
 * get_sites, switch_to_blog, restore_current_blog, get_current_blog_id) via
 * the $GLOBALS['sscribe_test_*'] knobs, so these tests run entirely against
 * the stubs: per-site option provisioning is observed through a recording
 * ArrayObject installed as $GLOBALS['sscribe_test_option_autoload'] (every
 * update_option() call with an explicit autoload flag writes through it),
 * and per-site uninstall runs are observed through a recording WP_Role
 * whose remove_cap() captures the current blog id.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Multisite_Activation_Uninstall_Test extends TestCase {

	/** @var callable|null */
	private $options_recorder = null;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_options']          = array();
		$GLOBALS['sscribe_test_transients']       = array();
		$GLOBALS['sscribe_test_registered_settings'] = array();
		$GLOBALS['sscribe_test_scheduled_events'] = array();
		$GLOBALS['sscribe_test_db_tables']        = array(
			'wp_sscribe_audit_log'    => array(),
			'wp_sscribe_export_stats' => array(),
		);
		$GLOBALS['sscribe_test_is_multisite']     = true;
		$GLOBALS['sscribe_test_blog_id']          = 1;
		$GLOBALS['sscribe_test_blog_stack']       = array();

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'sscribe-export-site-pages/sscribe-export-site-pages.php' );
		}
	}

	protected function tearDown(): void {
		$this->options_recorder = null;
		unset(
			$GLOBALS['sscribe_test_option_autoload'],
			$GLOBALS['sscribe_test_options'],
			$GLOBALS['sscribe_test_transients'],
			$GLOBALS['sscribe_test_registered_settings'],
			$GLOBALS['sscribe_test_scheduled_events'],
			$GLOBALS['sscribe_test_db_tables'],
			$GLOBALS['sscribe_test_is_multisite'],
			$GLOBALS['sscribe_test_sites'],
			$GLOBALS['sscribe_test_blog_id'],
			$GLOBALS['sscribe_test_blog_stack'],
			$GLOBALS['sscribe_test_role_overrides']
		);
		parent::tearDown();
	}

	/**
	 * Install a recorder as the option-autoload scratchpad. Every
	 * update_option() call with an explicit autoload flag writes through
	 * $GLOBALS['sscribe_test_option_autoload'], so the recorder observes
	 * which blog context each option provisioning ran under.
	 */
	private function install_option_write_recorder(): void {
		$GLOBALS['sscribe_test_option_autoload'] = new class() extends \ArrayObject {
			/** @var array<int, array{0: string, 1: int}> */
			public array $writes = array();

			public function offsetSet( $key, $value ): void {
				$this->writes[] = array( (string) $key, \get_current_blog_id() );
				parent::offsetSet( $key, $value );
			}
		};
	}

	/**
	 * @return array<int, int> Blog ids recorded for the given option key.
	 */
	private function option_write_blog_ids( string $option ): array {
		$recorder = $GLOBALS['sscribe_test_option_autoload'] ?? null;
		if ( ! ( $recorder instanceof \ArrayObject ) || ! isset( $recorder->writes ) ) {
			return array();
		}
		$ids = array();
		foreach ( $recorder->writes as $write ) {
			if ( $write[0] === $option ) {
				$ids[] = $write[1];
			}
		}
		return $ids;
	}

	// ==================================================================
	// Network activation — per-site provisioning + balanced switch/restore.
	// ==================================================================

	public function test_network_activation_provisions_options_per_site_and_balances_switch_restore(): void {
		$GLOBALS['sscribe_test_sites'] = array( 1, 2, 3 );
		$this->install_option_write_recorder();

		\SScribe_Activator::activate( true );

		$this->assertSame(
			array( 1, 2, 3 ),
			$this->option_write_blog_ids( 'sscribe_version' ),
			'activate_single_site() must provision sscribe_version once per site, inside that site\'s switch_to_blog() context.'
		);
		$this->assertSame(
			array( 1, 2, 3 ),
			$this->option_write_blog_ids( 'sscribe_schema_version' ),
			'The schema version must also be recorded per site during network activation.'
		);

		$this->assertSame( SSCRIBE_VERSION, get_option( 'sscribe_version', null ) );
		$this->assertSame( 1, get_current_blog_id(), 'Network activation must restore the original blog context.' );
		$this->assertSame(
			array(),
			$GLOBALS['sscribe_test_blog_stack'],
			'Every switch_to_blog() must have exactly one matching restore_current_blog().'
		);
	}

	public function test_network_activation_paginates_sites_beyond_the_first_page(): void {
		$site_ids = range( 1, 105 );
		$GLOBALS['sscribe_test_sites'] = $site_ids;
		$this->install_option_write_recorder();

		\SScribe_Activator::activate( true );

		$this->assertSame(
			$site_ids,
			$this->option_write_blog_ids( 'sscribe_version' ),
			'activate_network_wide() must walk every page of get_sites() results and provision each site.'
		);
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['sscribe_test_blog_stack'] );
	}

	public function test_network_activation_ignores_network_flag_on_single_site(): void {
		$GLOBALS['sscribe_test_is_multisite'] = false;
		$GLOBALS['sscribe_test_sites']        = array( 1, 2, 3 );
		$this->install_option_write_recorder();

		\SScribe_Activator::activate( true );

		$this->assertSame(
			array( 1 ),
			$this->option_write_blog_ids( 'sscribe_version' ),
			'Outside multisite, activate( true ) must provision only the current site and never walk get_sites().'
		);
	}

	// ==================================================================
	// Multisite uninstall — per-site cleanup + balanced switch/restore.
	// ==================================================================

	/**
	 * Evaluate the procedural uninstall.php tail (the $sscribe_cleanup_site
	 * closure plus the multisite dispatch loop) under the stub surface.
	 * Mirrors the source-extraction pattern of Uninstall_Test.
	 */
	private function run_uninstall_tail(): void {
		$source = (string) file_get_contents( SSCRIBE_PLUGIN_DIR . 'uninstall.php' );
		$start  = strpos( $source, '$sscribe_cleanup_site = static function' );
		$this->assertNotFalse( $start, 'uninstall.php must define the per-site cleanup closure.' );

		$tail = substr( $source, $start );
		$this->assertStringContainsString( 'if ( is_multisite() )', $tail );
		$this->assertStringContainsString( 'switch_to_blog( $sscribe_blog_id );', $tail );
		$this->assertStringContainsString( 'restore_current_blog();', $tail );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.eval_like_eval -- Test sandbox: executes the uninstall tail under stubbed globals.
		eval( $tail );
	}

	public function test_multisite_uninstall_cleans_every_site_with_balanced_switch_restore(): void {
		$site_ids = range( 1, 120 );
		$GLOBALS['sscribe_test_sites'] = $site_ids;

		$role = new class() {
			/** @var array<int, int> */
			public array $removed_on_blogs = array();

			public function has_cap( string $capability ): bool {
				unset( $capability );
				return true;
			}

			public function remove_cap( string $capability ): void {
				unset( $capability );
				$this->removed_on_blogs[] = \get_current_blog_id();
			}
		};
		$GLOBALS['sscribe_test_role_overrides'] = array( 'administrator' => $role );
		update_option( 'sscribe_version', SSCRIBE_VERSION, false );

		$this->run_uninstall_tail();

		$cleaned_blogs = array_values( array_unique( $role->removed_on_blogs ) );
		sort( $cleaned_blogs );

		$this->assertSame(
			$site_ids,
			$cleaned_blogs,
			'The uninstall loop must run the per-site cleanup once per site across every get_sites() page (120 sites > the 100-per-page batch).'
		);
		$this->assertCount(
			2 * count( $site_ids ),
			$role->removed_on_blogs,
			'Each site\'s cleanup must revoke both plugin capabilities.'
		);

		$this->assertSame( 1, get_current_blog_id(), 'The uninstall loop must restore the original blog context.' );
		$this->assertSame(
			array(),
			$GLOBALS['sscribe_test_blog_stack'],
			'Every switch_to_blog() in the uninstall loop must pair with restore_current_blog().'
		);
		$this->assertArrayNotHasKey(
			'sscribe_version',
			$GLOBALS['sscribe_test_options'],
			'Per-site cleanup must delete the plugin options.'
		);
	}

	public function test_multisite_uninstall_restores_blog_context_when_site_cleanup_throws(): void {
		$GLOBALS['sscribe_test_sites'] = range( 1, 80 );

		$role = new class() {
			public function has_cap( string $capability ): bool {
				unset( $capability );
				return true;
			}

			public function remove_cap( string $capability ): void {
				unset( $capability );
				if ( 55 === \get_current_blog_id() ) {
					throw new \RuntimeException( 'site cleanup exploded' );
				}
			}
		};
		$GLOBALS['sscribe_test_role_overrides'] = array( 'administrator' => $role );

		try {
			$this->run_uninstall_tail();
			$this->fail( 'Expected the site-55 cleanup failure to propagate' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'site cleanup exploded', $e->getMessage() );
		}

		$this->assertSame(
			1,
			get_current_blog_id(),
			'restore_current_blog() must live in a finally block: a failing site cleanup must still restore the blog context.'
		);
		$this->assertSame( array(), $GLOBALS['sscribe_test_blog_stack'] );
	}
}
