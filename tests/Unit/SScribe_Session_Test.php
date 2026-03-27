<?php
/**
 * Unit tests for SScribe_Session class.
 *
 * @package SScribe
 */

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Session_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_options'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_options'] = array();
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
		$id      = $session->create( array( 'page_ids' => array( 1, 2, 3 ), 'total' => 3, 'processed' => 0 ) );

		$this->assertTrue( $session->validate( $id ) );
	}

	public function test_invalid_session_returns_null(): void {
		$session = new \SScribe_Session();

		$data = $session->get( 'nonexistent-id' );

		$this->assertNull( $data );
	}

	public function test_get_storage_type(): void {
		$session = new \SScribe_Session();

		$this->assertEquals( 'database', $session->get_storage_type() );
	}
}
