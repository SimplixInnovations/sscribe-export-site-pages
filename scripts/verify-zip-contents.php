<?php
/**
 * Verify ZIP contents for production readiness.
 * Run: php scripts/verify-zip-contents.php
 */
// Find the most recent dist ZIP file dynamically (same pattern as list-zip.php and check-wp-org.php).
$dist_files = glob( dirname( __DIR__ ) . '/dist/sscribe-export-site-pages-*.zip' );
if ( empty( $dist_files ) ) {
	fwrite( STDERR, "No dist ZIP found. Run: composer build\n" );
	exit( 1 );
}
usort( $dist_files, fn( $a, $b ) => filemtime( $b ) - filemtime( $a ) );
$zip_file = $dist_files[0];

$zip = new ZipArchive();
if ($zip->open($zip_file) !== true) {
    fwrite(STDERR, "Failed to open ZIP\n");
    exit(1);
}

$totalSize = 0;
$fontCount = 0;
$fontFiles = [];
$bloatCount = 0;
$bloatFiles = [];
$devCount = 0;
$devFiles = [];
$vendorPrefixedEntries = [];
$criticalFound = [
    'plugin_php' => false,
    'readme_txt' => false,
    'admin_dir' => false,
    'includes_dir' => false,
    'assets_fonts' => false,
    'languages_dir' => false,
];

for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    $name = $stat['name'];
    $size = $stat['size'];
    $totalSize += $size;

    if (str_ends_with($name, '/sscribe-export-site-pages.php')) $criticalFound['plugin_php'] = true;
    if (str_ends_with($name, '/readme.txt')) $criticalFound['readme_txt'] = true;
    if (str_starts_with($name, 'sscribe-export-site-pages/admin/')) $criticalFound['admin_dir'] = true;
    if (str_starts_with($name, 'sscribe-export-site-pages/includes/')) $criticalFound['includes_dir'] = true;
    if (str_contains($name, 'assets/fonts/')) $criticalFound['assets_fonts'] = true;
    if (str_starts_with($name, 'sscribe-export-site-pages/languages/')) $criticalFound['languages_dir'] = true;

    // Font tracking
    if (str_contains($name, 'ttfonts/')) {
        $fontCount++;
        $fontFiles[] = basename($name);
    }

    // Bloat fonts check
    $bloatPatterns = ['Sun-Ext', 'UnBatang', 'XB Riyaz', 'Lateef', 'Uthman', 'Garuda', 'Dhyana', 'KhmerOS', 'ayar.ttf', 'Padauk', 'Tharlon', 'Zawgyi', 'Abyssinica', 'Aboriginal', 'Jomolhari', 'Sundanese', 'TaiHeritage', 'Aegean', 'Aegyptus', 'Akkadian', 'Quivira', 'Eeyek', 'lannaalif', 'DBSILBR', 'SyrCOM', 'TaameyDavid', 'kaputa', 'Lohit', 'Pothana2000', 'damase'];
    foreach ($bloatPatterns as $bp) {
        if (str_contains($name, $bp)) {
            $bloatCount++;
            $bloatFiles[] = $name;
            break;
        }
    }

    // Dev files check
    $devPatterns = ['.php-cs', 'phpstan', 'phpunit.xml', 'phpcs.xml', 'ruleset.xml', 'psalm.xml', 'CREDITS.txt', '.travis.yml', '.scrutinizer.yml', 'mkdocs.yml', 'github_changelog', '/tests/', '/docs/', 'node_modules', 'coverage'];
    foreach ($devPatterns as $dp) {
        if (str_contains($name, $dp)) {
            $devCount++;
            $devFiles[] = $name;
            break;
        }
    }

    // Vendor-prefixed entries for debug
    if (str_contains($name, 'vendor-prefixed/')) {
        $vendorPrefixedEntries[] = $name;
    }
}

echo "=== CRITICAL FILES ===\n";
foreach ($criticalFound as $k => $v) {
    echo "  " . ($v ? 'PASS' : 'FAIL') . ": $k\n";
}

echo "\n=== ttfONTS IN ZIP: $fontCount files ===\n";
foreach ($fontFiles as $f) {
    echo "  $f\n";
}

echo "\n=== BLOAT FONTS CHECK ===\n";
echo $bloatCount === 0 ? "  PASS: Zero bloat fonts\n" : "  FAIL: Found $bloatCount bloat files\n";
foreach ($bloatFiles as $f) echo "    $f\n";

echo "\n=== DEV FILES CHECK ===\n";
echo $devCount === 0 ? "  PASS: Zero dev files\n" : "  WARN: Found $devCount dev files\n";
foreach ($devFiles as $f) echo "    $f\n";

echo "\n=== VENDOR-PREFIXED CONTENTS (sample) ===\n";
$vendors = [];
foreach ($vendorPrefixedEntries as $e) {
    $parts = explode('/', $e);
    if (count($parts) >= 3) {
        $vendor = $parts[1];
        $package = $parts[2] ?? '';
        $key = $vendor . '/' . $package;
        if (!isset($vendors[$key])) $vendors[$key] = 0;
        $vendors[$key]++;
    }
}
foreach ($vendors as $k => $c) {
    echo "  $k: $c files\n";
}

echo "\n=== SUMMARY ===\n";
echo "  Entries: " . $zip->numFiles . "\n";
echo "  Size (uncompressed): " . round($totalSize / 1048576, 2) . " MB\n";
echo "  Size (compressed, filesystem): " . round(filesize($zip_file) / 1048576, 2) . " MB\n";

$zip->close();

// Check all critical
$allPass = true;
foreach ($criticalFound as $k => $v) {
    if (!$v) { $allPass = false; break; }
}
if ($bloatCount > 0) $allPass = false;

echo "\n" . ($allPass ? "ALL CHECKS PASSED" : "SOME CHECKS FAILED") . "\n";
exit($allPass ? 0 : 1);
