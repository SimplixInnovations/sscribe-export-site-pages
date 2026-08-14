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

		$this->assertEquals( 'encrypted-json', $session->get_storage_type() );
	}

	public function test_rotate_signing_key_promotes_current_to_previous(): void {
		$original_key  = str_repeat( 'a', 64 );
		$GLOBALS['sscribe_test_options']['sscribe_session_signing_key'] = $original_key;
		$GLOBALS['sscribe_test_options']['sscribe_session_signing_key_prev'] = '';

		$session = new \SScribe_Session();

		$this->assertTrue( $session->rotate_signing_key() );

		$this->assertSame( $original_key, $GLOBALS['sscribe_test_options']['sscribe_session_signing_key_prev'] );
		$this->assertNotSame( $original_key, $GLOBALS['sscribe_test_options']['sscribe_session_signing_key'] );
		$this->assertNotEmpty( $GLOBALS['sscribe_test_options']['sscribe_session_signing_key'] );
		$this->assertGreaterThan( 0, (int) ( $GLOBALS['sscribe_test_options']['sscribe_session_signing_key_prev_rotated_at'] ?? 0 ) );
	}

	public function test_session_signed_with_old_key_remains_valid_after_rotation(): void {
		$original_key = str_repeat( 'b', 64 );
		$GLOBALS['sscribe_test_options']['sscribe_session_signing_key'] = $original_key;
		$GLOBALS['sscribe_test_options']['sscribe_session_signing_key_prev'] = '';

		$session = new \SScribe_Session();
		$id      = $session->create( array( 'page_ids' => array( 7, 8, 9 ), 'total' => 3, 'processed' => 0 ) );
		$this->assertNotEmpty( $id );

		$this->assertTrue( $session->rotate_signing_key() );

		$data = $session->get( $id );

		$this->assertIsArray( $data );
		$this->assertSame( array( 7, 8, 9 ), $data['page_ids'] );
		$this->assertSame( 3, $data['total'] );
	}

	public function test_decode_session_value_rejects_payload_without_signature(): void {
		$session = new \SScribe_Session();

		$payload = wp_json_encode( array( 'page_ids' => array( 1 ), 'total' => 1 ) );

		$this->assertNull( $session->decode_session_value( $payload, 'known-session-id' ) );
	}

	public function test_decode_session_value_rejects_payload_with_mismatched_signature(): void {
		$session = new \SScribe_Session();

		$payload = wp_json_encode(
			array(
				'page_ids' => array( 1 ),
				'total'    => 1,
				'_sig'     => str_repeat( '0', 64 ),
			)
		);

		$this->assertNull( $session->decode_session_value( $payload, 'known-session-id' ) );
	}

	public function test_decode_session_value_accepts_payload_with_valid_signature(): void {
		$session   = new \SScribe_Session();
		$session_id = 'known-session-id';
		$signed     = $session->create( array( 'page_ids' => array( 4, 5 ), 'total' => 2, 'processed' => 0 ) );

		$raw = $GLOBALS['sscribe_test_options'][ 'sscribe_session_' . $signed ] ?? '';
		$this->assertNotEmpty( $raw );

		$decoded = $session->decode_session_value( $raw, $signed );

		$this->assertIsArray( $decoded );
		$this->assertSame( array( 4, 5 ), $decoded['page_ids'] );
	}

	private function reset_active_session_cache(): void {
		$property = new \ReflectionProperty( \SScribe_Session::class, 'active_session_cache' );
		$property->setValue( null, array() );
	}
}
