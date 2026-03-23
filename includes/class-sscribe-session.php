<?php
/**
 * File-based session storage for export operations.
 *
 * Replaces transient-based storage to avoid issues with caching plugins
 * (Redis, Memcached, WP Rocket, LightSpeed) that may clear or corrupt
 * large transients during export operations.
 *
 * @package SScribe
 */

if (!defined('ABSPATH')) {
    exit;
}

class SScribe_Session
{
    private $storage_dir;
    private $session_prefix = 'sscribe-session-';

    public function __construct(string $storage_dir = null)
    {
        if ($storage_dir === null) {
            $upload_dir = wp_upload_dir();
            $storage_dir = trailingslashit($upload_dir['basedir']) . 'sscribe-sessions/';
        }
        $this->storage_dir = trailingslashit($storage_dir);
        $this->ensure_directory_exists();
    }

    private function ensure_directory_exists(): void
    {
        if (!is_dir($this->storage_dir)) {
            wp_mkdir_p($this->storage_dir);
            file_put_contents($this->storage_dir . '.htaccess', 'Deny from all');
            file_put_contents($this->storage_dir . 'index.html', '');
        }
    }

    public function create(array $data): string
    {
        $session_id = wp_generate_password(16, false);
        $session_id = strtolower($session_id);

        $data['created_at'] = time();
        $data['session_id'] = $session_id;

        $file_path = $this->get_file_path($session_id);
        $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return '';
        }

        $result = file_put_contents($file_path, $json, LOCK_EX);

        if ($result === false) {
            return '';
        }

        return $session_id;
    }

    public function get(string $session_id): ?array
    {
        $session_id = sanitize_key($session_id);

        if (empty($session_id) || strlen($session_id) !== 16) {
            return null;
        }

        $file_path = $this->get_file_path($session_id);

        if (!file_exists($file_path)) {
            return null;
        }

        $content = file_get_contents($file_path);

        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $data;
    }

    public function update(string $session_id, array $data): bool
    {
        $existing = $this->get($session_id);

        if ($existing === null) {
            return false;
        }

        $merged = array_merge($existing, $data);
        $merged['updated_at'] = time();

        $file_path = $this->get_file_path($session_id);
        $json = wp_json_encode($merged, JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return false;
        }

        $result = file_put_contents($file_path, $json, LOCK_EX);

        return $result !== false;
    }

    public function delete(string $session_id): bool
    {
        $session_id = sanitize_key($session_id);
        $file_path = $this->get_file_path($session_id);

        if (!file_exists($file_path)) {
            return false;
        }

        if (function_exists('wp_delete_file')) {
            return wp_delete_file($file_path);
        }

        return unlink($file_path);
    }

    public function validate(string $session_id): bool
    {
        $data = $this->get($session_id);

        if ($data === null) {
            return false;
        }

        $required_keys = array('page_ids', 'total', 'processed');

        foreach ($required_keys as $key) {
            if (!isset($data[$key])) {
                return false;
            }
        }

        if (!is_array($data['page_ids'])) {
            return false;
        }

        if (count($data['page_ids']) !== (int) $data['total']) {
            return false;
        }

        return true;
    }

    public function cleanup_expired(int $max_age_seconds = 14400): int
    {
        $files = glob($this->storage_dir . $this->session_prefix . '*.json');
        $deleted = 0;
        $now = time();

        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $data = json_decode($content, true);

            if (isset($data['created_at']) && ($now - $data['created_at']) > $max_age_seconds) {
                if (function_exists('wp_delete_file')) {
                    if (wp_delete_file($file)) {
                        $deleted++;
                    }
                } elseif (unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    private function get_file_path(string $session_id): string
    {
        return $this->storage_dir . $this->session_prefix . $session_id . '.json';
    }

    public function get_storage_dir(): string
    {
        return $this->storage_dir;
    }
}
