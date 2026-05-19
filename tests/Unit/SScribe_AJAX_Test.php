<?php

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_AJAX_Test extends TestCase {
	private $processor;

	protected function setUp(): void {
		parent::setUp();
		$this->processor = new \SScribe_Batch_Processor();
		$_POST = array();
	}

	protected function tearDown(): void {
		$this->processor = null;
		$_POST = array();
		parent::tearDown();
	}

	public function test_ajax_get_status_counts_requires_capability(): void {
		global $sscribe_test_current_user_can;
		$sscribe_test_current_user_can = false;

		try {
			ob_start();
			$this->processor->ajax_get_status_counts();
			ob_end_clean();
			$this->fail( 'Expected exception was not thrown' );
		} catch ( \RuntimeException $e ) {
			$output = ob_get_clean();
			$json   = json_decode( $output, true );
			$this->assertNotNull( $json );
			$this->assertFalse( $json['success'] );
		}
	}

	public function test_ajax_cancel_export_requires_capability(): void {
		global $sscribe_test_current_user_can;
		$sscribe_test_current_user_can = false;

		try {
			ob_start();
			$this->processor->ajax_cancel_export();
			ob_end_clean();
			$this->fail( 'Expected exception was not thrown' );
		} catch ( \RuntimeException $e ) {
			$output = ob_get_clean();
			$json   = json_decode( $output, true );
			$this->assertNotNull( $json );
			$this->assertFalse( $json['success'] );
		}
	}

	public function test_ajax_delete_export_requires_capability(): void {
		global $sscribe_test_current_user_can;
		$sscribe_test_current_user_can = false;

		try {
			ob_start();
			$this->processor->ajax_delete_export();
			ob_end_clean();
			$this->fail( 'Expected exception was not thrown' );
		} catch ( \RuntimeException $e ) {
			$output = ob_get_clean();
			$json   = json_decode( $output, true );
			$this->assertNotNull( $json );
			$this->assertFalse( $json['success'] );
		}
	}

	public function test_ajax_download_requires_capability(): void {
		global $sscribe_test_current_user_can;
		$sscribe_test_current_user_can = false;

		try {
			ob_start();
			$this->processor->ajax_download();
			ob_end_clean();
			$this->fail( 'Expected exception was not thrown' );
		} catch ( \RuntimeException $e ) {
			$output = ob_get_clean();
			$this->assertNotEmpty( $output );
		}
	}

	public function test_ajax_get_status_counts_validates_nonce(): void {
		global $sscribe_test_current_user_can;
		$sscribe_test_current_user_can = true;

		try {
			ob_start();
			$this->processor->ajax_get_status_counts();
			ob_end_clean();
			$this->fail( 'Expected exception was not thrown' );
		} catch ( \RuntimeException $e ) {
			$output = ob_get_clean();
			$json   = json_decode( $output, true );
			$this->assertNotNull( $json );
			$this->assertFalse( $json['success'] );
		}
	}

	public function test_ajax_cancel_export_requires_session_id(): void {
		global $sscribe_test_current_user_can;
		$sscribe_test_current_user_can = true;

		$_POST['nonce']      = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['session_id'] = '';

		try {
			ob_start();
			$this->processor->ajax_cancel_export();
			ob_end_clean();
		} catch ( \RuntimeException $e ) {
			$output = ob_get_clean();
			$json   = json_decode( $output, true );
			$this->assertNotNull( $json );
			$this->assertFalse( $json['success'] );
		}
	}

	public function test_ajax_delete_export_requires_filename(): void {
		global $sscribe_test_current_user_can;
		$sscribe_test_current_user_can = true;

		$_POST['nonce']    = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['filename'] = '';

		try {
			ob_start();
			$this->processor->ajax_delete_export();
			ob_end_clean();
		} catch ( \RuntimeException $e ) {
			$output = ob_get_clean();
			$json   = json_decode( $output, true );
			$this->assertNotNull( $json );
			$this->assertFalse( $json['success'] );
		}
	}

	public function test_ajax_download_validates_filename(): void {
		global $sscribe_test_current_user_can;
		$sscribe_test_current_user_can = true;

		$_POST['nonce']    = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['filename'] = '';

		try {
			ob_start();
			$this->processor->ajax_download();
			ob_end_clean();
			$this->fail( 'Expected exception was not thrown' );
		} catch ( \RuntimeException $e ) {
			$output = ob_get_clean();
			$this->assertNotEmpty( $output );
		}
	}

	public function test_ajax_download_rejects_path_traversal(): void {
		global $sscribe_test_current_user_can;
		$sscribe_test_current_user_can = true;

		$_POST['nonce']    = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['filename'] = '../wp-config.php';

		try {
			ob_start();
			$this->processor->ajax_download();
			ob_end_clean();
			$this->fail( 'Expected exception was not thrown' );
		} catch ( \RuntimeException $e ) {
			$output = ob_get_clean();
			$this->assertNotEmpty( $output );
		}
	}

	public function test_ajax_start_export_validates_post_status(): void {
		global $sscribe_test_current_user_can;
		$sscribe_test_current_user_can = true;

		$_POST['nonce']       = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['post_status'] = 'draft';
		$_POST['post_type']  = 'page';
		$_POST['formats']    = array( 'docx' );

		try {
			ob_start();
			$this->processor->ajax_start_export();
			ob_end_clean();
		} catch ( \RuntimeException $e ) {
			$output = ob_get_clean();
			$json   = json_decode( $output, true );
			$this->assertNotNull( $json );
			$this->assertFalse( $json['success'] );
		}
	}

	public function test_ajax_start_export_validates_post_type(): void {
		global $sscribe_test_current_user_can;
		$sscribe_test_current_user_can = true;

		$_POST['nonce']     = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['post_type']  = 'invalid_type';
		$_POST['formats']    = array( 'docx' );

		try {
			ob_start();
			$this->processor->ajax_start_export();
			ob_end_clean();
		} catch ( \RuntimeException $e ) {
			$output = ob_get_clean();
			$json   = json_decode( $output, true );
			$this->assertNotNull( $json );
			$this->assertFalse( $json['success'] );
		}
	}
}
