<?php
/**
 * Stub of WordPress's WP_Filesystem_Base for unit tests.
 *
 * The SScribe_Filesystem class is declared with a `?WP_Filesystem_Base $fs`
 * property and uses `instanceof WP_Filesystem_Base` checks to decide
 * whether to delegate file IO to WordPress's filesystem abstraction.
 * In production WP_Filesystem_Base is loaded from WordPress's
 * `wp-admin/includes/class-wp-filesystem-base.php`; the unit test
 * bootstrap does not load WordPress, so we declare a minimal abstract
 * stub and a concrete child class here. The child class is what tests
 * instantiate and inject via reflection. This file lives in the GLOBAL
 * namespace (no `namespace` statement at all) so that production code
 * that references `\WP_Filesystem_Base` and the SScribe_Test_WP_Filesystem
 * injection target resolve correctly under PHP's namespace rules.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

abstract class WP_Filesystem_Base {
	public array $calls = array();
	public function put_contents( $file, $content, $mode = 0600 ) { $this->calls[] = array( 'put_contents', $file, $content, $mode ); return true; }
	public function get_contents( $file ) { $this->calls[] = array( 'get_contents', $file ); return 'wpfs-get-contents'; }
	public function delete( $file ) { $this->calls[] = array( 'delete', $file ); return true; }
	public function mkdir( $path, $mode = 0700 ) { $this->calls[] = array( 'mkdir', $path, $mode ); return true; }
	public function exists( $path ) { $this->calls[] = array( 'exists', $path ); return true; }
	public function is_dir( $path ) { $this->calls[] = array( 'is_dir', $path ); return true; }
	public function is_writable( $path ) { $this->calls[] = array( 'is_writable', $path ); return true; }
	public function dirlist( $path ) { $this->calls[] = array( 'dirlist', $path ); return array( 'synthetic.txt' => array( 'name' => 'synthetic.txt' ) ); }
	public function copy( $source, $destination, $overwrite = false, $mode = 0600 ) { $this->calls[] = array( 'copy', $source, $destination, $overwrite, $mode ); return true; }
	public function move( $source, $destination, $overwrite = false ) { $this->calls[] = array( 'move', $source, $destination, $overwrite ); return true; }
	public function chmod( $file, $mode = 0600 ) { $this->calls[] = array( 'chmod', $file, $mode ); return true; }
}

final class SScribe_Test_WP_Filesystem extends WP_Filesystem_Base {
	/**
	 * When non-null, overrides the default boolean return value of the
	 * matching method name (e.g. 'put_contents' => false to simulate a
	 * failed write through the WP_Filesystem API). The call is still
	 * recorded in $calls so tests can assert the delegation happened.
	 *
	 * @var array<string, mixed>
	 */
	public array $failures = array();

	public function put_contents( $file, $content, $mode = 0600 ) {
		$this->calls[] = array( 'put_contents', $file, $content, $mode );
		return array_key_exists( 'put_contents', $this->failures ) ? $this->failures['put_contents'] : true;
	}

	public function chmod( $file, $mode = 0600 ) {
		$this->calls[] = array( 'chmod', $file, $mode );
		return array_key_exists( 'chmod', $this->failures ) ? $this->failures['chmod'] : true;
	}

	public function get_contents( $file ) {
		$this->calls[] = array( 'get_contents', $file );
		return array_key_exists( 'get_contents', $this->failures ) ? $this->failures['get_contents'] : 'wpfs-get-contents';
	}

	public function delete( $file ) {
		$this->calls[] = array( 'delete', $file );
		return array_key_exists( 'delete', $this->failures ) ? $this->failures['delete'] : true;
	}

	public function mkdir( $path, $mode = 0700 ) {
		$this->calls[] = array( 'mkdir', $path, $mode );
		return array_key_exists( 'mkdir', $this->failures ) ? $this->failures['mkdir'] : true;
	}

	public function exists( $path ) {
		$this->calls[] = array( 'exists', $path );
		return array_key_exists( 'exists', $this->failures ) ? $this->failures['exists'] : true;
	}

	public function is_dir( $path ) {
		$this->calls[] = array( 'is_dir', $path );
		return array_key_exists( 'is_dir', $this->failures ) ? $this->failures['is_dir'] : true;
	}

	public function is_writable( $path ) {
		$this->calls[] = array( 'is_writable', $path );
		return array_key_exists( 'is_writable', $this->failures ) ? $this->failures['is_writable'] : true;
	}

	public function dirlist( $path ) {
		$this->calls[] = array( 'dirlist', $path );
		return array_key_exists( 'dirlist', $this->failures ) ? $this->failures['dirlist'] : array( 'synthetic.txt' => array( 'name' => 'synthetic.txt' ) );
	}

	public function copy( $source, $destination, $overwrite = false, $mode = 0600 ) {
		$this->calls[] = array( 'copy', $source, $destination, $overwrite, $mode );
		return array_key_exists( 'copy', $this->failures ) ? $this->failures['copy'] : true;
	}

	public function move( $source, $destination, $overwrite = false ) {
		$this->calls[] = array( 'move', $source, $destination, $overwrite );
		return array_key_exists( 'move', $this->failures ) ? $this->failures['move'] : true;
	}
}
