<?php
/**
 * Unit tests for SScribe_Session class.
 *
 * @package SScribe
 */

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Session_Test extends TestCase
{
    private $test_dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->test_dir = sys_get_temp_dir() . '/sscribe-test-' . uniqid();
        mkdir($this->test_dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->test_dir);
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*');
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            is_dir($file) ? $this->recursiveDelete($file) : unlink($file);
        }
        rmdir($dir);
    }

    public function test_create_session(): void
    {
        $session = new \SScribe_Session($this->test_dir);
        $id = $session->create(array('page_ids' => array(1, 2, 3), 'total' => 3, 'processed' => 0));

        $this->assertNotEmpty($id);
        $this->assertEquals(16, strlen($id));
    }

    public function test_get_session(): void
    {
        $session = new \SScribe_Session($this->test_dir);
        $id = $session->create(array('page_ids' => array(1, 2, 3), 'total' => 3, 'processed' => 0));

        $data = $session->get($id);

        $this->assertIsArray($data);
        $this->assertEquals(array(1, 2, 3), $data['page_ids']);
        $this->assertEquals(3, $data['total']);
    }

    public function test_update_session(): void
    {
        $session = new \SScribe_Session($this->test_dir);
        $id = $session->create(array('page_ids' => array(1, 2, 3), 'processed' => 0));

        $session->update($id, array('processed' => 2));

        $data = $session->get($id);
        $this->assertEquals(2, $data['processed']);
    }

    public function test_delete_session(): void
    {
        $session = new \SScribe_Session($this->test_dir);
        $id = $session->create(array('page_ids' => array(1, 2, 3), 'processed' => 0));

        $result = $session->delete($id);

        $this->assertTrue($result);
        $this->assertNull($session->get($id));
    }

    public function test_large_session_data(): void
    {
        $large_page_ids = range(1, 1000);

        $session = new \SScribe_Session($this->test_dir);
        $id = $session->create(array('page_ids' => $large_page_ids, 'total' => 1000, 'processed' => 0));

        $data = $session->get($id);

        $this->assertCount(1000, $data['page_ids']);
    }

    public function test_validate_integrity(): void
    {
        $session = new \SScribe_Session($this->test_dir);
        $id = $session->create(array('page_ids' => array(1, 2, 3), 'total' => 3, 'processed' => 0));

        $this->assertTrue($session->validate($id));
    }

    public function test_invalid_session_returns_null(): void
    {
        $session = new \SScribe_Session($this->test_dir);

        $data = $session->get('nonexistent-id');

        $this->assertNull($data);
    }
}
