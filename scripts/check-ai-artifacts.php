<?php
/**
 * Release artifact leakage scanner.
 *
 * Detects invisible source anomalies and accidentally committed assistant/
 * workspace artifacts without pretending punctuation or writing style can
 * reliably identify how code was authored.
 *
 * Usage:
 *   php scripts/check-ai-artifacts.php
 *   php scripts/check-ai-artifacts.php --dist
 *   php scripts/check-ai-artifacts.php --root /path/to/fixture
 *
 * @package SScribe
 */

declare(strict_types=1);

/**
 * Parse a value following a CLI option.
 */
function sscribe_scan_option_value(array $args, string $option): ?string {
	$index = array_search($option, $args, true);
	if (false === $index || ! isset($args[$index + 1]) || ! is_string($args[$index + 1])) {
		return null;
	}
	$value = trim($args[$index + 1]);
	return '' === $value ? null : $value;
}

/**
 * Run the release leakage scanner.
 *
 * @return int Exit code (0 = clean, 1 = leakage/anomaly found).
 */
function sscribe_check_ai_artifacts_main(): int {
	global $argv;

	$project_root = dirname(__DIR__);
	$args         = is_array($argv) ? $argv : array();
	$dist_only    = in_array('--dist', $args, true);
	$custom_root  = sscribe_scan_option_value($args, '--root');
	$scan_root    = $custom_root ?: ($dist_only ? $project_root . '/dist' : $project_root);
	$scan_root    = rtrim($scan_root, '/\\');

	if (! is_dir($scan_root)) {
		fwrite(STDERR, "[release-leakage] FAIL: scan root does not exist: {$scan_root}\n");
		return 1;
	}

	$assistant_workspaces = array(
		'.aider',
		'.claude',
		'.codeium',
		'.continue',
		'.cursor',
		'.impeccable',
		'.mimosa',
		'.omo',
		'.opencode',
		'.playwright-mcp',
		'.windsurf',
	);

	$skip_dirs = array(
		'.cache',
		'.git',
		'.github',
		'.idea',
		'.phpunit.cache',
		'.vscode',
		'build',
		'coverage',
		'dist',
		'docs',
		'languages',
		'node_modules',
		'scripts',
		'stubs',
		'tests',
		'tests-e2e',
		'tests-wp',
		'vendor',
		'vendor-prefixed',
	);

	$content_patterns = array(
		'ZERO_WIDTH_SPACE'      => "\xE2\x80\x8B", // U+200B.
		'ZERO_WIDTH_NON_JOINER' => "\xE2\x80\x8C", // U+200C.
		'ZERO_WIDTH_JOINER'     => "\xE2\x80\x8D", // U+200D.
		'ASSISTANT_BOILERPLATE' => 'regex:/\bas\s+an\s+(?:ai|artificial\s+intelligence)\s+language\s+model\b/i',
	);

	$extensions = array('php', 'js', 'css', 'pot', 'txt', 'md', 'json', 'xml', 'yml', 'yaml');
	$matches    = array();
	$scanned    = 0;
	$seen_workspace = array();

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($scan_root, RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::LEAVES_ONLY
	);

	foreach ($iterator as $file) {
		/** @var SplFileInfo $file */
		if (! $file->isFile()) {
			continue;
		}

		$relative = str_replace($scan_root . DIRECTORY_SEPARATOR, '', $file->getPathname());
		$parts    = preg_split('~[\\\\/]~', $relative) ?: array();

		$workspace_hit = null;
		foreach ($parts as $part) {
			if (in_array($part, $assistant_workspaces, true)) {
				$workspace_hit = $part;
				break;
			}
		}
		if (null !== $workspace_hit) {
			if (! isset($seen_workspace[$workspace_hit])) {
				$matches[] = array('file' => $relative, 'pattern' => 'ASSISTANT_WORKSPACE');
				$seen_workspace[$workspace_hit] = true;
			}
			continue;
		}

		$skip = false;
		foreach ($parts as $part) {
			if (in_array($part, $skip_dirs, true)) {
				$skip = true;
				break;
			}
		}
		if ($skip) {
			continue;
		}

		$extension = strtolower($file->getExtension());
		if (! in_array($extension, $extensions, true)) {
			continue;
		}

		++$scanned;
		$content = file_get_contents($file->getPathname());
		if (false === $content) {
			continue;
		}

		foreach ($content_patterns as $name => $pattern) {
			$found = false;
			if (str_starts_with($pattern, 'regex:')) {
				$found = 1 === preg_match(substr($pattern, 6), $content);
			} else {
				$found = false !== strpos($content, $pattern);
			}
			if ($found) {
				$matches[] = array('file' => $relative, 'pattern' => $name);
			}
		}
	}

	if (empty($matches)) {
		printf("[release-leakage] Clean: scanned %d files.\n", $scanned);
		return 0;
	}

	fwrite(
		STDERR,
		'[release-leakage] FAIL: found ' . count($matches) . ' release-integrity issue(s) in ' .
		count(array_unique(array_column($matches, 'file'))) . " file(s):\n"
	);
	foreach ($matches as $match) {
		fprintf(STDERR, "  [%s] %s\n", $match['pattern'], $match['file']);
	}
	fwrite(
		STDERR,
		"Remove committed assistant/workspace artifacts or invisible source characters before release.\n"
	);
	return 1;
}

exit(sscribe_check_ai_artifacts_main());
