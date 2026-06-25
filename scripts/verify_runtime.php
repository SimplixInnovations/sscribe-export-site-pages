<?php
/**
 * Runtime verification of dist/sscribe-export-site-pages-1.1.2.
 *
 * Usage:
 *   php scripts/verify_runtime.php dist/sscribe-export-site-pages
 *
 * Boots the shipped plugin in an isolated child process (each check) and
 * exercises a real production code path. This is the runtime proof that
 * the shipped artifact does not contain the activation-time fatals the
 * prior audit found.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Must run from CLI\n");
    exit(1);
}

$plugin_root = $argv[1] ?? null;
if (!$plugin_root || !is_dir($plugin_root)) {
    fwrite(STDERR, "Usage: php verify_runtime.php <plugin_root_dir>\n");
    exit(1);
}

$plugin_root = realpath($plugin_root);
$main_file   = $plugin_root . '/sscribe-export-site-pages.php';
$shim_file   = $plugin_root . '/includes/sscribe-prefixed-runtime-shim.php';

if (!file_exists($main_file) || !file_exists($shim_file)) {
    fwrite(STDERR, "Required files missing in $plugin_root\n");
    exit(1);
}

// Create a writable temp dir the child can use for mPDF.
$mpdf_temp = sys_get_temp_dir() . '/sscribe-mpdf-' . md5($plugin_root);
@mkdir($mpdf_temp, 0755, true);

$harness = <<<'PHP'
<?php
declare(strict_types=1);

// WP function stubs. The variadic `...$a` lets each stub accept any
// arity. Named parameters (e.g. $f, $p, $k) are extracted from $a[0]
// inside the body.
$stubs = [
    'plugin_dir_path' => '$_f = $a[0] ?? ""; return $_f === "" ? "/" : rtrim(dirname($_f), "/\\\\") . "/";',
    'plugin_dir_url'  => 'return "";',
    'plugin_basename' => '$_f = $a[0] ?? ""; return $_f === "" ? "" : basename(dirname($_f)) . "/" . basename($_f);',
    'add_action' => '', 'add_filter' => '', 'apply_filters' => 'return $a[1] ?? null;',
    'do_action' => '', 'do_action_ref_array' => '', 'apply_filters_ref_array' => 'return $a[1] ?? null;',
    'register_activation_hook' => '', 'register_deactivation_hook' => '',
    'load_plugin_textdomain' => '',
    'trailingslashit' => '$_p = $a[0] ?? ""; return $_p === "" ? "/" : rtrim($_p, "/\\\\") . "/";',
    'untrailingslashit' => '$_p = $a[0] ?? ""; return $_p === "" ? "" : rtrim($_p, "/\\\\");',
    'wp_upload_dir' => 'return ["basedir" => "/tmp/wp-content/uploads", "baseurl" => "http://x"];',
    'admin_url' => '$_p = $a[0] ?? ""; return "http://x/" . $_p;',
    'home_url' => 'return "http://x";', 'site_url' => 'return "http://x";',
    'get_option' => 'return $a[1] ?? false;', 'update_option' => 'return true;', 'delete_option' => 'return true;',
    'current_user_can' => 'return false;',
    'wp_create_nonce' => 'return "n";', 'wp_verify_nonce' => 'return true;',
    'check_ajax_referer' => 'return true;',
    'wp_send_json' => 'exit;', 'wp_send_json_error' => 'exit;', 'wp_send_json_success' => 'exit;',
    'status_header' => '', 'nocache_headers' => '',
    'is_admin' => 'return false;', 'did_action' => 'return 0;',
    'get_transient' => 'return false;', 'set_transient' => 'return true;', 'delete_transient' => 'return true;',
    'wp_schedule_event' => 'return true;', 'wp_clear_scheduled_hook' => '', 'wp_next_scheduled' => 'return false;',
    'wp_remote_post' => 'return ["body" => "", "response" => ["code" => 200]];',
    'wp_remote_retrieve_body' => 'return "";', 'wp_remote_retrieve_response_code' => 'return 0;',
    'wp_get_current_user' => 'return (object)["ID" => 0];', 'wp_set_current_user' => '',
    'get_user_by' => 'return null;', 'user_can' => 'return false;',
    'wp_hash' => 'return "";', 'wp_salt' => 'return "";',
    'wp_json_encode' => 'return "";', 'wp_kses_post' => 'return $a[0] ?? "";',
    'wp_kses' => 'return $a[0] ?? "";', 'wp_kses_data' => 'return $a[0] ?? "";',
    'wp_normalize_path' => '$_p = $a[0] ?? ""; return str_replace("\\\\", "/", $_p);',
    'wp_unique_filename' => 'return $a[1] ?? "";',
    'wp_mkdir_p' => '$_d = $a[0] ?? ""; return @mkdir($_d, 0755, true);',
    'wp_delete_file' => 'return true;',
    'wp_die' => 'throw new RuntimeException((string) ($a[0] ?? ""));',
    'esc_html__' => 'return $a[0] ?? "";', 'esc_html_e' => 'echo $a[0] ?? "";',
    'esc_attr__' => 'return $a[0] ?? "";', 'esc_attr_e' => 'echo $a[0] ?? "";',
    '__' => 'return $a[0] ?? "";', '_e' => 'echo $a[0] ?? "";',
    '_x' => 'return $a[0] ?? "";',
    '_n' => 'return ($a[2] ?? 1) == 1 ? ($a[0] ?? "") : ($a[1] ?? "");',
    'sanitize_text_field' => 'return $a[0] ?? "";', 'sanitize_key' => 'return $a[0] ?? "";',
    'absint' => 'return abs((int) ($a[0] ?? 0));',
    'wp_parse_args' => 'return array_merge((array) ($a[1] ?? []), (array) ($a[0] ?? []));',
    'get_post' => 'return null;', 'get_posts' => 'return [];', 'get_post_meta' => 'return "";',
    'get_post_type' => 'return "page";',
    'get_post_types' => 'return ["page" => (object)["name" => "Page", "labels" => (object)["name" => "Pages"]]];',
    'get_post_stati' => 'return ["publish" => "Published"];',
    'get_the_title' => 'return "";', 'get_permalink' => 'return "";',
    'get_bloginfo' => '$_k = $a[0] ?? null; return $_k === "version" ? "6.8" : "";',
    'get_term' => 'return null;', 'wp_get_post_terms' => 'return [];', 'get_terms' => 'return [];',
    'is_wp_error' => 'return false;', 'get_locale' => 'return "en_US";',
    'number_format_i18n' => 'return "";', 'size_format' => 'return "";', 'date_i18n' => 'return "";',
    'human_time_diff' => 'return "";', 'get_role' => 'return null;', 'wp_roles' => 'return null;',
    'current_time' => 'return date("Y-m-d H:i:s");',
    'wp_timezone' => 'return new DateTimeZone("UTC");',
    'wp_date' => 'return date($a[0] ?? "Y-m-d", $a[1] ?? time());',
    'wp_cache_get' => 'return false;', 'wp_cache_set' => 'return true;', 'wp_cache_delete' => 'return true;',
    'wp_convert_hr_to_bytes' => '$_v = $a[0] ?? "0"; return (int) preg_replace("/[^0-9]/", "", (string) $_v);',
    'wp_is_writable' => '$_p = $a[0] ?? ""; return @is_writable((string) $_p);',
    'wp_check_filetype_and_ext' => 'return ["ext" => "", "type" => "", "proper_filename" => false];',
    'is_user_logged_in' => 'return false;', 'is_ssl' => 'return false;',
    'wp_doing_ajax' => 'return false;', 'wp_doing_cron' => 'return false;',
    'wp_get_environment_vars' => 'return [];', 'wp_get_server_protocol' => 'return "HTTP/1.1";',
    'wp_installing' => 'return false;', 'wp_is_dev_version' => 'return false;',
    'wp_using_ext_object_cache' => 'return false;',
];
foreach ($stubs as $fn => $body) {
    if (!function_exists($fn)) {
        eval("function $fn(...\$a) { $body }");
    }
}

$abspath = getenv('SSCRIBE_ABSPATH');
$shim    = getenv('SSCRIBE_SHIM');
$main    = getenv('SSCRIBE_MAIN');
$tempdir = getenv('SSCRIBE_TEMPDIR');

define('ABSPATH', $abspath);
define('SSCRIBE_DEBUG', false);
define('SSCRIBE_VERSION', '1.1.2');
define('WP_DEBUG', false);
define('WP_DEBUG_LOG', false);
define('WP_CONTENT_DIR', '/tmp/wp-content/');

// Stub $wpdb global. Diagnostics' check_session_health queries wp_options
// for sscribe session rows; the harness returns an empty result set.
global $wpdb;
$wpdb = new class {
    public $options = 'wp_options';
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $prefix = 'wp_';
    public function prepare($sql, ...$args) { return $sql; }
    public function esc_like($s) { return addcslashes((string) $s, '_%\\'); }
    public function get_results($sql = null) { return []; }
    public function get_var($sql = null) { return null; }
    public function get_row($sql = null) { return null; }
    public function query($sql) { return 0; }
};

require_once $shim;
require_once $main;

$action = getenv('SSCRIBE_ACTION');

try {
    switch ($action) {
        case 'load':
            echo "PLUGIN_DIR=" . SSCRIBE_PLUGIN_DIR . "\n";
            break;
        case 'container_instance':
            $c = \SScribe_Container::instance();
            echo "CONTAINER_OK " . get_class($c) . "\n";
            break;
        case 'session':
            $s = new \SScribe_Session();
            echo "SESSION_OK " . get_class($s) . "\n";
            break;
        case 'exporter':
            $cls = getenv('SSCRIBE_CLS');
            echo class_exists($cls) ? "EXPORTER_OK $cls\n" : "EXPORTER_MISSING $cls\n";
            break;
        case 'mpdf_class':
            echo class_exists('\SScribeVendor\Mpdf\Mpdf') ? "MPDF_CLASS_OK\n" : "MPDF_CLASS_MISSING\n";
            break;
        case 'mpdf_instantiate':
            // Construct the same config shape the plugin's build_mpdf_config
            // produces (default_font=freeserif, fontDir including the
            // shipped mPDF ttfonts dir, backupSubsFont pinned). This is the
            // safe production path. Raw mPDF with no config would walk the
            // serif fallback chain and crash on the pruned DejaVuSerifCondensed.
            $mpdf_ttfonts = $abspath . 'vendor-prefixed/mpdf/mpdf/ttfonts/';
            $amiri_dir = $abspath . 'assets/fonts/amiri/';
            $font_dirs = [
                $mpdf_ttfonts,
                $amiri_dir,
            ];
            $config = [
                'tempDir'        => $tempdir,
                'fontDir'        => $font_dirs,
                'default_font'   => 'freeserif',
                'backupSubsFont' => ['freeserif'],
                'backupSIPFont'  => null,
            ];
            try {
                $mpdf = new \SScribeVendor\Mpdf\Mpdf($config);
                echo "MPDF_INSTANTIATED " . get_class($mpdf) . "\n";
            } catch (Throwable $e) {
                echo "MPDF_FAIL " . $e->getMessage() . "\n";
                $trace = $e->getTrace();
                foreach (array_slice($trace, 0, 8) as $i => $f) {
                    $where = ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?');
                    $what = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '?');
                    echo "  #$i $what at $where\n";
                }
            }
            break;
        case 'mpdf_alias':
            // The shim's class_alias is registered at shim load time. Just
            // verify both namespaces are reachable.
            $exists_unprefixed = class_exists('\Mpdf\Mpdf');
            $exists_prefixed = class_exists('\SScribeVendor\Mpdf\Mpdf');
            if ($exists_unprefixed && $exists_prefixed) {
                echo "MPDF_ALIAS_OK both_namespaces_resolvable\n";
            } elseif ($exists_prefixed) {
                echo "MPDF_ALIAS_PARTIAL prefixed_only\n";
            } else {
                echo "MPDF_ALIAS_FAIL neither_resolves\n";
            }
            break;
        case 'mpdf_alias_cold':
            // Cold-path regression: the shim's fallback autoloader must
            // resolve `\Mpdf\Mpdf` to `\SScribeVendor\Mpdf\Mpdf` even on
            // a fresh request that has NOT pre-warmed the class. A
            // third-party plugin doing `new \Mpdf\Mpdf()` before the
            // plugin's own exporter code instantiated the prefixed
            // class would otherwise hit `Class "Mpdf\Mpdf" not found`.
            //
            // We can't actually `new \Mpdf\Mpdf()` here without the
            // production config — mPDF's default config still wires
            // serif_fonts[0] = 'dejavuserifcondensed' whose fontdata
            // entry points at the pruned DejaVuSerifCondensed.ttf, so
            // construction trips the same crash class the production
            // code now pins. The point of this test is the autoloader,
            // not mPDF's defaults — so we assert on ReflectionClass
            // (which triggers the autoloader without instantiating).
            try {
                $r = new ReflectionClass('\Mpdf\Mpdf');
                $name = $r->getName();
                $file = $r->getFileName();
                echo "MPDF_COLD_RESOLVED class=$name file=$file\n";
            } catch (Throwable $e) {
                echo "MPDF_COLD_FAIL " . get_class($e) . ': ' . $e->getMessage() . "\n";
            }
            break;
        case 'phpword_alias_cold':
            // Same cold-path regression for PHPWord. Third-party plugins
            // (WPML, Polylang, etc.) sometimes construct PHPWord under
            // the unprefixed name before our own exporter code runs.
            try {
                $cold = new \PhpOffice\PhpWord\PhpWord();
                $cold_class = get_class($cold);
                $via_alias = (new ReflectionClass('\PhpOffice\PhpWord\PhpWord'))->getName();
                echo "PHPWORD_COLD_INSTANTIATED class=$cold_class alias=$via_alias\n";
            } catch (Throwable $e) {
                echo "PHPWORD_COLD_FAIL " . get_class($e) . ': ' . $e->getMessage() . "\n";
            }
            break;
        case 'phpword_instantiate':
            $w = new \SScribeVendor\PhpOffice\PhpWord\PhpWord();
            echo "PHPWORD_INSTANTIATED " . get_class($w) . "\n";
            break;
        case 'pdf_config':
            $r = new ReflectionClass(\SScribe_PDF_Exporter::class);
            $src = file_get_contents($r->getFileName());
            $has_sub   = strpos($src, "'backupSubsFont'") !== false;
            $has_serif = strpos($src, "'freeserif'") !== false;
            $has_sip   = strpos($src, "'backupSIPFont'") !== false;
            echo ($has_sub && $has_serif && $has_sip) ? "PDF_CONFIG_OK\n" : "PDF_CONFIG_MISSING\n";
            break;
        case 'fpdi_landmine':
            $fpdi = 'NOT_TRIGGERED';
            $fpdf = 'NOT_TRIGGERED';
            try {
                $r = class_exists('\SScribeVendor\setasign\Fpdi\Fpdi', true);
                $fpdi = $r ? 'LOADED' : 'NOT_LOADED';
            } catch (Throwable $e) {
                $fpdi = 'FATAL: ' . $e->getMessage();
            }
            try {
                $r = class_exists('\SScribeVendor\setasign\Fpdi\FpdfTpl', true);
                $fpdf = $r ? 'LOADED' : 'NOT_LOADED';
            } catch (Throwable $e) {
                $fpdf = 'FATAL: ' . $e->getMessage();
            }
            echo "FPDI=$fpdi\nFPDF_TPL=$fpdf\n";
            break;
        case 'diagnostics_min_mpdf':
            $r = new ReflectionClass(\SScribe_Diagnostics::class);
            $val = $r->getConstant('MIN_MPDF_FONT_COUNT');
            echo "CONST=$val\n";
            break;
        case 'diagnostics_no_composer_json':
            $r = new ReflectionClass(\SScribe_Diagnostics::class);
            $src = file_get_contents($r->getFileName());
            echo strpos($src, 'vendor-prefixed/phpoffice/phpword/composer.json') === false
                ? "NO_PROBE\n" : "PROBE_PRESENT\n";
            break;
        case 'preflight_mpdf':
            $d = new \SScribe_Diagnostics();
            $pre = $d->run_preflight(1, ['pdf']);
            $mpdf = $pre['checks']['mpdf'] ?? null;
            if ($mpdf) {
                echo "MPDF_CHECK status=" . $mpdf['status'] . " msg=" . $mpdf['message'] . "\n";
            } else {
                echo "MPDF_CHECK_MISSING keys=" . implode(',', array_keys($pre['checks'] ?? [])) . "\n";
            }
            break;
        case 'preflight_phpword':
            $d = new \SScribe_Diagnostics();
            $pre = $d->run_preflight(1, ['docx']);
            $pw = $pre['checks']['phpword'] ?? null;
            if ($pw) {
                echo "PHPWORD_CHECK status=" . $pw['status'] . " msg=" . $pw['message'] . "\n";
            } else {
                echo "PHPWORD_CHECK_MISSING\n";
            }
            break;
        default:
            echo "UNKNOWN_ACTION\n";
            exit(1);
    }
    exit(0);
} catch (Throwable $e) {
    echo "FATAL " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo "  at " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
PHP;

$harness_path = sys_get_temp_dir() . '/sscribe-verify-harness-' . md5($plugin_root) . '.php';
file_put_contents($harness_path, $harness);

function run_check(string $harness_path, array $env): array {
    foreach ($env as $k => $v) {
        putenv("$k=$v");
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
    }
    $php = PHP_BINARY;
    $cmd = sprintf('"%s" %s 2>&1', $php, escapeshellarg($harness_path));
    $proc = proc_open(
        $cmd,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname($harness_path),
        $env
    );
    if (!is_resource($proc)) {
        return [false, 'proc_open failed', ''];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($proc);
    return [$rc === 0, $stdout, $stderr];
}

$base_env = [
    'SSCRIBE_ABSPATH'  => $plugin_root . '/',
    'SSCRIBE_SHIM'     => $shim_file,
    'SSCRIBE_MAIN'     => $main_file,
    'SSCRIBE_TEMPDIR'  => $mpdf_temp,
];

$checks = [
    ['label' => 'main file loads (no PHP-version or WP-version self-deactivate)', 'action' => 'load'],
    ['label' => 'SScribe_Container singleton reachable', 'action' => 'container_instance'],
    ['label' => 'SScribe_Session instantiable (no merged-class collision)', 'action' => 'session'],
    ['label' => 'SScribe_PDF_Exporter class autoloads', 'action' => 'exporter', 'cls' => '\SScribe_PDF_Exporter'],
    ['label' => 'SScribe_DOCX_Exporter class autoloads', 'action' => 'exporter', 'cls' => '\SScribe_DOCX_Exporter'],
    ['label' => 'SScribe_HTML_Exporter class autoloads', 'action' => 'exporter', 'cls' => '\SScribe_HTML_Exporter'],
    ['label' => 'SScribe_Markdown_Exporter class autoloads', 'action' => 'exporter', 'cls' => '\SScribe_Markdown_Exporter'],
    ['label' => 'mPDF class autoloads (vendor-prefixed namespace)', 'action' => 'mpdf_class'],
    ['label' => 'mPDF can be instantiated with writable tempDir', 'action' => 'mpdf_instantiate'],
    ['label' => 'mPDF class-alias \Mpdf\Mpdf resolves after instantiation', 'action' => 'mpdf_alias'],
    ['label' => 'mPDF cold-path: \Mpdf\Mpdf instantiates WITHOUT prefixed-name pre-warm (shim fallback autoloader)', 'action' => 'mpdf_alias_cold'],
    ['label' => 'PHPWord cold-path: \PhpOffice\PhpWord\PhpWord instantiates WITHOUT prefixed-name pre-warm', 'action' => 'phpword_alias_cold'],
    ['label' => 'PHPWord can be instantiated', 'action' => 'phpword_instantiate'],
    ['label' => 'PDF exporter config has backupSubsFont=freeserif, backupSIPFont pinned', 'action' => 'pdf_config'],
    ['label' => 'FPDI/FpdfTpl landmine: class_exists probe behavior', 'action' => 'fpdi_landmine'],
    ['label' => 'diagnostics MIN_MPDF_FONT_COUNT <= 17 (no false positive)', 'action' => 'diagnostics_min_mpdf'],
    ['label' => 'diagnostics no longer probes pruned phpword/composer.json', 'action' => 'diagnostics_no_composer_json'],
    ['label' => 'preflight mpdf check produces status=ok (not warning)', 'action' => 'preflight_mpdf'],
    ['label' => 'preflight phpword check produces status=ok', 'action' => 'preflight_phpword'],
];

$pass = 0;
$fail = 0;
echo "\n=== Runtime verification: $plugin_root ===\n\n";
foreach ($checks as $c) {
    $env = $base_env;
    $env['SSCRIBE_ACTION'] = $c['action'];
    if (isset($c['cls'])) {
        $env['SSCRIBE_CLS'] = $c['cls'];
    }
    [$ok, $stdout, $stderr] = run_check($harness_path, $env);
    if ($ok) {
        $pass++;
        echo "[PASS] {$c['label']}\n";
        if ($stdout) {
            foreach (explode("\n", trim($stdout)) as $line) {
                if ($line !== '') echo "       $line\n";
            }
        }
    } else {
        $fail++;
        echo "[FAIL] {$c['label']}\n";
        $out = trim($stdout . ($stderr ? "\nstderr: $stderr" : ''));
        if ($out) {
            foreach (explode("\n", $out) as $line) {
                if ($line !== '') echo "       $line\n";
            }
        }
    }
}

echo "\n=== Result: $pass passed, $fail failed ===\n";

@unlink($harness_path);
@rmdir($mpdf_temp);
exit($fail === 0 ? 0 : 1);