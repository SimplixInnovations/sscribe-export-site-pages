<?php
/**
 * File-based session storage for export operations.
 *
 * Replaces transient-based storage to avoid issues with caching plugins
 * (Redis, Memcached, WP Rocket, LightSpeed) that may clear or corrupt
 * large transients during export operations.
 *
 * Uses file locking (flock) to prevent race conditions during concurrent
 * read-modify-write operations.
 *
 * @package SScribe
 */

if (!defined('ABSPATH')) {
    exit;
}

class SScribe_Session
{
    private string $storage_dir;
    private string $session_prefix = 'sscribe-session-';
    private string $lock_suffix = '.lock';
    private $logger;

    public function __construct(?string $storage_dir = null)
    {
        if ($storage_dir === null) {
            $upload_dir = wp_upload_dir();
            $storage_dir = trailingslashit($upload_dir['basedir']) . 'sscribe-sessions/';
        }
        $this->storage_dir = trailingslashit($storage_dir);
        $this->ensure_directory_exists();
        $this->logger = new SScribe_Logger(defined('SSCRIBE_DEBUG') && SSCRIBE_DEBUG);
    }

    private function ensure_directory_exists(): void
    {
        if (!is_dir($this->storage_dir)) {
            wp_mkdir_p($this->storage_dir);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents($this->storage_dir . '.htaccess', 'Deny from all');
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents($this->storage_dir . 'index.html', '');
        }
    }

    public function create(array $data): string
    {
        $session_id = sanitize_key(wp_generate_password(16, false));
        $session_id = strtolower($session_id);

        $data['created_at'] = time();
        $data['session_id'] = $session_id;

        $file_path = $this->get_file_path($session_id);
        $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $this->logger->error('Failed to encode session data to JSON', array(
                'json_error' => json_last_error_msg(),
            ));
            return '';
        }

        $tmp_path = $file_path . '.tmp';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        $result = file_put_contents($tmp_path, $json, LOCK_EX);

        if ($result === false) {
            $this->logger->error('Failed to write session file', array(
                'tmp_path' => $tmp_path,
            ));
            return '';
        }

        if (!rename($tmp_path, $file_path)) {
            $this->logger->error('Failed to rename session file', array(
                'tmp_path' => $tmp_path,
                'file_path' => $file_path,
            ));
            $this->cleanup_tmp_file($tmp_path);
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

        $handle = fopen($file_path, 'r');
        if ($handle === false) {
            return null;
        }

        if (!flock($handle, LOCK_SH)) {
            fclose($handle);
            return null;
        }

        $content = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

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
        $session_id = sanitize_key($session_id);

        if (empty($session_id) || strlen($session_id) !== 16) {
            return false;
        }

        $file_path = $this->get_file_path($session_id);
        $lock_path = $file_path . $this->lock_suffix;

        if (!file_exists($file_path)) {
            return false;
        }

        $lock_handle = fopen($lock_path, 'c');
        if ($lock_handle === false) {
            $this->logger->error('Failed to create lock file', array(
                'lock_path' => $lock_path,
            ));
            return false;
        }

        if (!flock($lock_handle, LOCK_EX)) {
            $this->logger->error('Failed to acquire lock', array(
                'lock_path' => $lock_path,
            ));
            fclose($lock_handle);
            return false;
        }

        try {
            $existing = $this->get_with_lock($file_path);
            if ($existing === null) {
                $this->logger->error('Failed to read existing session', array(
                    'file_path' => $file_path,
                ));
                return false;
            }

            $merged = array_merge($existing, $data);
            $merged['updated_at'] = time();

            $json = wp_json_encode($merged, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                $this->logger->error('Failed to encode session data', array(
                    'json_error' => json_last_error_msg(),
                ));
                return false;
            }

            $tmp_path = $file_path . '.tmp';
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            $result = file_put_contents($tmp_path, $json, LOCK_EX);

            if ($result === false) {
                $this->logger->error('Failed to write updated session file', array(
                    'tmp_path' => $tmp_path,
                ));
                $this->cleanup_tmp_file($tmp_path);
                return false;
            }

            if (!rename($tmp_path, $file_path)) {
                $this->cleanup_tmp_file($tmp_path);
                return false;
            }

            return true;
        } finally {
            flock($lock_handle, LOCK_UN);
            fclose($lock_handle);
        }
    }

    private function get_with_lock(string $file_path): ?array
    {
        $handle = fopen($file_path, 'r');
        if ($handle === false) {
            return null;
        }

        $content = stream_get_contents($handle);
        fclose($handle);

        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $data;
    }

    public function delete(string $session_id): bool
    {
        $session_id = sanitize_key($session_id);
        $file_path = $this->get_file_path($session_id);
        $lock_path = $file_path . $this->lock_suffix;

        if (!file_exists($file_path)) {
            return false;
        }

        $lock_handle = fopen($lock_path, 'c');
        if ($lock_handle !== false) {
            flock($lock_handle, LOCK_EX);
        }

        try {
            if (function_exists('wp_delete_file')) {
                wp_delete_file($file_path);
            } else {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink($file_path);
            }

            $this->cleanup_tmp_file($file_path . '.tmp');

            return !file_exists($file_path);
        } finally {
            if ($lock_handle !== false) {
                flock($lock_handle, LOCK_UN);
                fclose($lock_handle);
            }
        }
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
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $data = json_decode($content, true);

            if (isset($data['created_at']) && ($now - $data['created_at']) > $max_age_seconds) {
                if (function_exists('wp_delete_file')) {
                    wp_delete_file($file);
                    if (!file_exists($file)) {
                        $deleted++;
                    }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                } elseif (@unlink($file)) {
                    $deleted++;
                }

                $lock_file = $file . $this->lock_suffix;
                if (file_exists($lock_file)) {
                    @unlink($lock_file);
                }
            }
        }

        $tmp_files = glob($this->storage_dir . '*.tmp');
        if ($tmp_files !== false) {
            foreach ($tmp_files as $tmp_file) {
                @unlink($tmp_file);
            }
        }

        return $deleted;
    }

    private function cleanup_tmp_file(string $path): void
    {
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    private function get_file_path(string $session_id): string
    {
        return $this->storage_dir . $this->session_prefix . $session_id . '.json';
    }

    public function get_storage_dir(): string
    {
        return $this->storage_dir;
    }

    public function has_active_session(int $user_id): bool
    {
        $files = glob($this->storage_dir . $this->session_prefix . '*.json');
        
        if ($files === false || empty($files)) {
            return false;
        }

        $now = time();
        $max_age = 300;

        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }
            
            $data = json_decode($content, true);
            
            if (!is_array($data)) {
                continue;
            }

            if (isset($data['user_id']) && (int) $data['user_id'] === $user_id) {
                if (isset($data['created_at']) && ($now - $data['created_at']) < $max_age) {
                    if (isset($data['processed']) && isset($data['total'])) {
                        $processed = (int) $data['processed'];
                        $total = (int) $data['total'];
                        if ($processed < $total && empty($data['cancelled'])) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }
}
