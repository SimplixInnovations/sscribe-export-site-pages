<?php
/**
 * SScribe Session Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Session_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_options']    = array();
		$GLOBALS['sscribe_test_transients'] = array();
		$this->reset_active_session_cache();
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_options']    = array();
		$GLOBALS['sscribe_test_transients'] = array();
		$this->reset_active_session_cache();
		parent::tearDown();
	}

	public function test_create_session(): void {
		$session = new \SScribe_Session();
		$id      = $session->create( array( 'page_ids' => array( 1, 2, 3 ), 'total' => 3, 'processed' => 0 ) );

		$this->assertNotEmpty( $id );
		$this->assertEquals( 16, strlen( $id ) );
	}

	public function test_get_session(): void {
		$session = new \SScribe_Session();
		$id      = $session->create( array( 'page_ids' => array( 1, 2, 3 ), 'total' => 3, 'processed' => 0 ) );

		$data = $session->get( $id );

		$this->assertIsArray( $data );
		$this->assertEquals( array( 1, 2, 3 ), $data['page_ids'] );
		$this->assertEquals( 3, $data['total'] );
	}

	public function test_update_session(): void {
		$session = new \SScribe_Session();
		$id      = $session->create( array( 'page_ids' => array( 1, 2, 3 ), 'processed' => 0 ) );

		$session->update( $id, array( 'processed' => 2 ) );

		$data = $session->get( $id );
		$this->assertEquals( 2, $data['processed'] );
	}

	public function test_delete_session(): void {
		$session = new \SScribe_Session();
		$id      = $session->create( array( 'page_ids' => array( 1, 2, 3 ), 'processed' => 0 ) );

		$result = $session->delete( $id );

		$this->assertTrue( $result );
		$this->assertNull( $session->get( $id ) );
	}

	public function test_large_session_data(): void {
		$large_page_ids = range( 1, 1000 );

		$session = new \SScribe_Session();
		$id      = $session->create( array( 'page_ids' => $large_page_ids, 'total' => 1000, 'processed' => 0 ) );

		$data = $session->get( $id );

		$this->assertCount( 1000, $data['page_ids'] );
	}

	public function test_validate_integrity(): void {
		$session = new \SScribe_Session();
		$id      = $session->create( array( 'page_ids' => array( 1, 2, 3 ), 'total' => 3, 'processed' => 0, 'user_id' => 1 ) );

		$this->assertTrue( $session->validate( $id ) );
	}

	public function test_short_session_id_returns_null(): void {
		$session = new \SScribe_Session();

		
		
		$data = $session->get( 'nonexistent-id' );

		$this->assertNull( $data );
	}

	public function test_valid_length_nonexistent_session_returns_null(): void {
		$session = new \SScribe_Session();

		
		
		$data = $session->get( 'a1b2c3d4e5f67890' );

		$this->assertNull( $data );
	}

	public function test_get_storage_type(): void {
		$session = new \SScribe_Session();

		$this->assertEquals( 'database-json', $session->get_storage_type() );
	}

	public function test_has_active_session_ignores_stale_cached_session_id(): void {
		$session = new \SScribe_Session();

		set_transient( 'sscribe_active_sid_42', 'deadbeefdeadbeef', 300 );

		$this->assertFalse( $session->has_active_session( 42 ) );
		$this->assertSame( '0', get_transient( 'sscribe_active_sid_42' ) );
	}

	private function reset_active_session_cache(): void {
		$property = new \ReflectionProperty( \SScribe_Session::class, 'active_session_cache' );
		$property->setValue( null, array() );
	}
}
