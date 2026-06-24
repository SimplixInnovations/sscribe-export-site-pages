<?php
/**
 * End-to-end PDF render test for the shipped dist.
 *
 * Boots the plugin with WP stubs, then constructs the same mPDF config
 * the plugin's build_mpdf_config() produces, runs WriteHTML on content
 * containing a 'serif' CSS keyword, and verifies a valid PDF is written.
 *
 * The build_mpdf_config() function itself calls SScribe_Security::protect_directory
 * which requires WP's wp-admin/includes/file.php (a real WP runtime dep,
 * not a plugin issue). So we reconstruct the config in the harness, but
 * using the exact same logic, pins, and font directories the plugin ships.
 *
 * This is the runtime proof that the activation-time "Cannot find TTF
 * TrueType font file DejaVuSerifCondensed.ttf" crash is gone — because
 * the plugin's safe defaults pin default_font=freeserif and disable
 * backupSIPFont, neither the 'serif' CSS keyword nor out-of-range
 * characters can trigger the pruned DejaVu* chain.
 */
declare(strict_types=1);

$root = $argv[1] ?? null;
if (!$root) {
    fwrite(STDERR, "Usage: php verify_e2e.php <plugin_root>\n");
    exit(1);
}

$harness = <<<'PHP'
<?php
declare(strict_types=1);

$stubs = [
    'plugin_dir_path' => '$_f = $a[0] ?? ""; return rtrim(dirname($_f), "/\\\\") . "/";',
    'plugin_dir_url'  => 'return "";',
    'plugin_basename' => '$_f = $a[0] ?? ""; return basename(dirname($_f)) . "/" . basename($_f);',
    'add_action' => '', 'add_filter' => '', 'apply_filters' => 'return $a[1] ?? null;',
    'do_action' => '', 'do_action_ref_array' => '',
    'register_activation_hook' => '', 'register_deactivation_hook' => '',
    'load_plugin_textdomain' => '',
    'trailingslashit' => '$_p = $a[0] ?? ""; return rtrim($_p, "/\\\\") . "/";',
    'untrailingslashit' => '$_p = $a[0] ?? ""; return rtrim($_p, "/\\\\");',
    'wp_upload_dir' => 'return ["basedir" => "/tmp/wp-content/uploads", "baseurl" => "http://x"];',
    'admin_url' => 'return "http://x/" . ($a[0] ?? "");',
    'home_url' => 'return "http://x";', 'site_url' => 'return "http://x";',
    'get_option' => 'return $a[1] ?? false;', 'update_option' => 'return true;', 'delete_option' => 'return true;',
    'current_user_can' => 'return true;',
    'wp_create_nonce' => 'return "n";', 'wp_verify_nonce' => 'return true;',
    'check_ajax_referer' => 'return true;',
    'wp_send_json' => 'exit;', 'wp_send_json_error' => 'exit;', 'wp_send_json_success' => 'exit;',
    'status_header' => '', 'nocache_headers' => '',
    'is_admin' => 'return false;', 'did_action' => 'return 0;',
    'get_transient' => 'return false;', 'set_transient' => 'return true;', 'delete_transient' => 'return true;',
    'wp_schedule_event' => 'return true;', 'wp_clear_scheduled_hook' => '', 'wp_next_scheduled' => 'return false;',
    'wp_remote_post' => 'return ["body" => "", "response" => ["code" => 200]];',
    'wp_remote_retrieve_body' => 'return "";', 'wp_remote_retrieve_response_code' => 'return 0;',
    'wp_get_current_user' => 'return (object)["ID" => 1];', 'wp_set_current_user' => '',
    'get_user_by' => 'return null;', 'user_can' => 'return true;',
    'wp_hash' => 'return "";', 'wp_salt' => 'return "";',
    'wp_json_encode' => 'return "";',
    'wp_kses_post' => 'return (string) ($a[0] ?? "");',
    'wp_strip_all_tags' => 'return strip_tags((string) ($a[0] ?? ""));',
    'wp_normalize_path' => '$_p = $a[0] ?? ""; return str_replace("\\\\", "/", $_p);',
    'wp_unique_filename' => 'return $a[1] ?? "";',
    'wp_mkdir_p' => '$_d = $a[0] ?? ""; return @mkdir($_d, 0755, true);',
    'wp_delete_file' => 'return true;',
    'wp_is_writable' => 'return @is_writable((string) ($a[0] ?? ""));',
    'wp_die' => 'throw new RuntimeException((string) ($a[0] ?? ""));',
    'esc_html__' => 'return $a[0] ?? "";', 'esc_html_e' => 'echo $a[0] ?? "";',
    'esc_html'   => 'return htmlspecialchars((string) ($a[0] ?? ""), ENT_QUOTES, "UTF-8");',
    'esc_attr__' => 'return $a[0] ?? "";', 'esc_attr_e' => 'echo $a[0] ?? "";',
    'esc_attr'   => 'return htmlspecialchars((string) ($a[0] ?? ""), ENT_QUOTES, "UTF-8");',
    'esc_url'    => 'return (string) ($a[0] ?? "");',
    'esc_textarea' => 'return htmlspecialchars((string) ($a[0] ?? ""), ENT_QUOTES, "UTF-8");',
    '__' => 'return $a[0] ?? "";', '_e' => 'echo $a[0] ?? "";',
    '_x' => 'return $a[0] ?? "";',
    '_n' => 'return ($a[2] ?? 1) == 1 ? ($a[0] ?? "") : ($a[1] ?? "");',
    'sanitize_text_field' => 'return $a[0] ?? "";', 'sanitize_key' => 'return $a[0] ?? "";',
    'absint' => 'return abs((int) ($a[0] ?? 0));',
    'wp_parse_args' => 'return array_merge((array) ($a[1] ?? []), (array) ($a[0] ?? []));',
    'wp_unslash' => 'return $a[0] ?? "";',
    'get_post' => 'return null;', 'get_posts' => 'return [];', 'get_post_meta' => 'return "";',
    'get_post_type' => 'return "page";',
    'get_post_types' => 'return ["page" => (object)["name" => "Page", "labels" => (object)["name" => "Pages"]]];',
    'get_post_stati' => 'return ["publish" => "Published"];',
    'get_the_title' => 'return "Test Page";', 'get_permalink' => 'return "http://x/test";',
    'get_bloginfo' => 'return $a[0] === "version" ? "6.8" : "";',
    'get_term' => 'return null;', 'wp_get_post_terms' => 'return [];', 'get_terms' => 'return [];',
    'is_wp_error' => 'return false;', 'get_locale' => 'return "en_US";',
    'number_format_i18n' => 'return "";', 'size_format' => 'return "";', 'date_i18n' => 'return "";',
    'human_time_diff' => 'return "";', 'get_role' => 'return null;', 'wp_roles' => 'return null;',
    'current_time' => 'return date("Y-m-d H:i:s");',
    'wp_timezone' => 'return new DateTimeZone("UTC");',
    'wp_date' => 'return date($a[0] ?? "Y-m-d", $a[1] ?? time());',
    'wp_cache_get' => 'return false;', 'wp_cache_set' => 'return true;', 'wp_cache_delete' => 'return true;',
    'wp_convert_hr_to_bytes' => '
        $_v = strtolower(trim((string) ($a[0] ?? "0")));
        if ($_v === "" || $_v === "-1") return -1;
        if (preg_match("/^(\d+)\s*([kmgt]?)$/", $_v, $_m)) {
            $_n = (int) $_m[1]; $_u = $_m[2];
            switch($_u) { case "k": $_n *= 1024; break; case "m": $_n *= 1048576; break; case "g": $_n *= 1073741824; break; case "t": $_n *= 1099511627776; break; }
            return $_n;
        }
        return (int) $_v;',
    'is_user_logged_in' => 'return true;', 'is_ssl' => 'return false;',
    'wp_doing_ajax' => 'return false;', 'wp_doing_cron' => 'return false;',
    'wp_check_filetype_and_ext' => 'return ["ext" => "pdf", "type" => "application/pdf", "proper_filename" => false];',
];
foreach ($stubs as $fn => $body) {
    if (!function_exists($fn)) {
        eval("function $fn(...\$a) { $body }");
    }
}

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

$abspath = getenv('SSCRIBE_ABSPATH');
$shim    = getenv('SSCRIBE_SHIM');
$main    = getenv('SSCRIBE_MAIN');
$outdir  = getenv('SSCRIBE_OUTDIR');

define('ABSPATH', $abspath);
define('SSCRIBE_DEBUG', false);
define('SSCRIBE_VERSION', '1.1.1');
define('WP_DEBUG', false);
define('WP_DEBUG_LOG', false);
define('WP_CONTENT_DIR', '/tmp/wp-content/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
define('MONTH_IN_SECONDS', 2592000);
define('YEAR_IN_SECONDS', 31536000);

require_once $shim;
require_once $main;

// Reconstruct the exact config the plugin's build_mpdf_config produces,
// minus the WP_Filesystem-protected directory init. The exporter's
// critical safety pins (default_font=freeserif, backupSubsFont=freeserif,
// backupSIPFont=null) are preserved verbatim.
$font_dir  = $abspath . 'assets/fonts/';
$amiri_dir = $font_dir . 'amiri/';

$default_config      = ( new \SScribeVendor\Mpdf\Config\ConfigVariables() )->getDefaults();
$default_font_config = ( new \SScribeVendor\Mpdf\Config\FontVariables() )->getDefaults();
$font_dirs = $default_config['fontDir'];
$font_data = $default_font_config['fontdata'];

$amiri_available = is_dir( $amiri_dir ) && file_exists( $amiri_dir . 'Amiri-Regular.ttf' );

$is_rtl = false; // English page in this test
$rtl_arabic_font = $amiri_available ? 'amiri' : 'freeserif';
$config = array(
    'fontDir'         => array_merge( $font_dirs, array( $amiri_dir ) ),
    'fontdata'        => array_replace(
        $font_data,
        array(
            'freesans' => array(
                'R'  => 'DejaVuSans.ttf',
                'B'  => 'DejaVuSans-Bold.ttf',
                'I'  => 'DejaVuSans-Oblique.ttf',
                'BI' => 'DejaVuSans-BoldOblique.ttf',
            ),
            'freemono' => array(
                'R'  => 'DejaVuSansMono.ttf',
                'B'  => 'DejaVuSansMono-Bold.ttf',
                'I'  => 'DejaVuSansMono-Oblique.ttf',
                'BI' => 'DejaVuSansMono-BoldOblique.ttf',
            ),
            'dejavuserifcondensed' => array(
                'R'  => 'DejaVuSerif.ttf',
                'B'  => 'DejaVuSerif-Bold.ttf',
                'I'  => 'DejaVuSerif-Italic.ttf',
                'BI' => 'DejaVuSerif-BoldItalic.ttf',
            ),
            'dejavusanscondensed' => array(
                'R'  => 'DejaVuSans.ttf',
                'B'  => 'DejaVuSans-Bold.ttf',
                'I'  => 'DejaVuSans-Oblique.ttf',
                'BI' => 'DejaVuSans-BoldOblique.ttf',
            ),
            'sun-exta' => array(
                'R'  => 'DejaVuSans.ttf',
                'B'  => 'DejaVuSans-Bold.ttf',
                'I'  => 'DejaVuSans-Oblique.ttf',
                'BI' => 'DejaVuSans-BoldOblique.ttf',
            ),
            'sun-extb' => array(
                'R'  => 'DejaVuSans.ttf',
                'B'  => 'DejaVuSans-Bold.ttf',
                'I'  => 'DejaVuSans-Oblique.ttf',
                'BI' => 'DejaVuSans-BoldOblique.ttf',
            ),
        )
    ),
    'backupSubsFont'  => array( 'freeserif' ),
    'backupSIPFont'   => null,
    'isRemoteEnabled' => true,
    'fonttrans'       => array(
        'serif'           => $is_rtl ? $rtl_arabic_font : 'freeserif',
        'sans-serif'      => $is_rtl ? $rtl_arabic_font : 'freesans',
        'monospace'       => 'freemono',
        'times'           => $is_rtl ? $rtl_arabic_font : 'freeserif',
        'times new roman' => $is_rtl ? $rtl_arabic_font : 'freeserif',
        'georgia'         => $is_rtl ? $rtl_arabic_font : 'freeserif',
        'palatino'        => $is_rtl ? $rtl_arabic_font : 'freeserif',
        'cambria'         => $is_rtl ? $rtl_arabic_font : 'freeserif',
        'garamond'        => $is_rtl ? $rtl_arabic_font : 'freeserif',
        'bookman'         => $is_rtl ? $rtl_arabic_font : 'freeserif',
        'arial'           => $is_rtl ? $rtl_arabic_font : 'freesans',
        'helvetica'       => $is_rtl ? $rtl_arabic_font : 'freesans',
        'verdana'         => $is_rtl ? $rtl_arabic_font : 'freesans',
        'tahoma'          => $is_rtl ? $rtl_arabic_font : 'freesans',
        'trebuchet'       => $is_rtl ? $rtl_arabic_font : 'freesans',
        'trebuchet ms'    => $is_rtl ? $rtl_arabic_font : 'freesans',
        'lucida'          => $is_rtl ? $rtl_arabic_font : 'freesans',
        'lucida sans'     => $is_rtl ? $rtl_arabic_font : 'freesans',
        'courier'         => 'freemono',
        'courier new'     => 'freemono',
        'monaco'          => 'freemono',
        'consolas'        => 'freemono',
    ),
    'mode'            => 'utf-8',
    'default_font'    => $is_rtl ? $rtl_arabic_font : 'freeserif',
    'useOTL'          => 0xFF,
    'useKashida'      => 75,
    'OTLhelper'       => true,
    'autoArabic'      => true,
    'autoScriptToLang'=> true,
    'autoLangToFont'  => true,
    'orientation'     => 'P',
    'margin_left'     => 15,
    'margin_right'    => 15,
    'margin_top'      => 15,
    'margin_bottom'   => 15,
    'tempDir'         => $outdir,
    'debug'           => false,
);

echo "DEFAULT_FONT=" . $config['default_font'] . "\n";
echo "BACKUP_SUBS=" . json_encode($config['backupSubsFont']) . "\n";
echo "BACKUP_SIP=" . var_export($config['backupSIPFont'], true) . "\n";
echo "AMIRI_AVAILABLE=" . ($amiri_available ? 'YES' : 'NO') . "\n";
echo "FONT_DIR_COUNT=" . count($config['fontDir']) . "\n";

try {
$mpdf = new \SScribeVendor\Mpdf\Mpdf($config);
    echo "MPDF_INSTANTIATED " . get_class($mpdf) . "\n";

    // Simulate the most common English/Arabic page content. Test the
    // distinct crash paths that the 2026-06-24 DejaVuSansCondensed
    // report and the latent DejaVuSerifCondensed / Sun-ExtA paths
    // could trigger:
    //   1. Plain HTML, no font-family (default freeserif)
    //   2. font-family: serif (walks serif_fonts chain)
    //   3. font-family: sans-serif (walks sans_fonts chain)
    //   4. font-family: arial/times/georgia/helvetica/verdana/tahoma
    //   5. font-family: monospace / courier / courier new
    //   6. CJK characters (triggers sun-exta via autoLangToFont)
    //   7. Cyrillic (covered by DejaVuSans)
    //   8. Greek (covered by DejaVuSans)
    //   9. Special chars (em-dash, copyright, snowman)
    $html = '<h1>Heading</h1>'
          . '<p>Default text — \u{2014} &mdash; &copy; &#x2603;</p>'
          . '<p style="font-family: serif;">serif keyword</p>'
          . '<p style="font-family: sans-serif;">sans keyword</p>'
          . '<p style="font-family: arial;">arial</p>'
          . '<p style="font-family: helvetica;">helvetica</p>'
          . '<p style="font-family: verdana;">verdana</p>'
          . '<p style="font-family: tahoma;">tahoma</p>'
          . '<p style="font-family: georgia;">georgia</p>'
          . '<p style="font-family: times;">times</p>'
          . '<p style="font-family: times new roman;">times new roman</p>'
          . '<p style="font-family: palatino;">palatino</p>'
          . '<p style="font-family: cambria;">cambria</p>'
          . '<p style="font-family: garamond;">garamond</p>'
          . '<p style="font-family: monospace;">monospace</p>'
          . '<p style="font-family: courier;">courier</p>'
          . '<p style="font-family: courier new;">courier new</p>'
          . '<p style="font-family: consolas;">consolas</p>'
          . '<p>CJK: &#x4e2d;&#x6587; &#x65e5;&#x672c;&#x8a9e;</p>'
          . '<p>Cyrillic: \u{041f}\u{0440}\u{0438}\u{0432}\u{0435}\u{0442}</p>'
          . '<p>Greek: \u{0393}\u{03b5}\u{03b9}\u{03ac} \u{03c3}\u{03bf}\u{03c5}</p>'
          . '<p>Arabic: \u{0627}\u{0644}\u{0633}\u{0644}\u{0627}\u{0645} \u{0639}\u{0644}\u{064a}\u{0643}\u{0645}</p>'
          . '<p>Page 2 content here. The end.</p>';
    $mpdf->WriteHTML($html);
    echo "MPDF_WRITEHTML_OK\n";

    $outfile = $outdir . '/e2e-output.pdf';
    $mpdf->Output($outfile, 'F');
    echo "MPDF_OUTPUT_OK\n";

    if (file_exists($outfile)) {
        $size = filesize($outfile);
        $head = file_get_contents($outfile, false, null, 0, 8);
        $is_pdf = str_starts_with($head, "%PDF-");
        echo "FILE_EXISTS size=$size is_pdf=" . ($is_pdf ? 'YES' : 'NO') . "\n";
        if ($is_pdf) {
            echo "E2E_PASS\n";
            exit(0);
        } else {
            echo "E2E_FAIL not_a_pdf head=" . bin2hex($head) . "\n";
            exit(1);
        }
    } else {
        echo "E2E_FAIL no_output_file\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo "E2E_FATAL " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo "  at " . $e->getFile() . ":" . $e->getLine() . "\n";
    $trace = $e->getTrace();
    foreach (array_slice($trace, 0, 6) as $i => $f) {
        $where = ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?');
        $what = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '?');
        echo "  #$i $what at $where\n";
    }
    exit(1);
}
PHP;

$harness_path = sys_get_temp_dir() . '/sscribe-e2e-harness-' . md5($root) . '.php';
file_put_contents($harness_path, $harness);

$outdir = sys_get_temp_dir() . '/sscribe-e2e-out-' . md5($root);
@mkdir($outdir, 0755, true);
$shim = $root . '/includes/sscribe-prefixed-runtime-shim.php';
$main = $root . '/sscribe-export-site-pages.php';

$env = [
    'SSCRIBE_ABSPATH' => $root . '/',
    'SSCRIBE_SHIM'    => $shim,
    'SSCRIBE_MAIN'    => $main,
    'SSCRIBE_OUTDIR'  => $outdir,
    'PATH'            => getenv('PATH'),
];
foreach ($env as $k => $v) {
    putenv("$k=$v");
}

$php = PHP_BINARY;
$cmd = sprintf('"%s" %s 2>&1', $php, escapeshellarg($harness_path));
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($harness_path), $env);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$rc = proc_close($proc);

echo "=== E2E PDF render: $root ===\n\n";
echo $stdout;
if ($stderr) echo "stderr: $stderr\n";
echo "\nexit_code=$rc\n";

@unlink($harness_path);
exit($rc);