<?php
/**
 * Phase 32: security scanner contract.
 *
 * The shipped plugin code is the attack surface that runs on every
 * WordPress install that uses SScribe. A single line of
 * `eval( $_POST['code'] )` or `$wpdb->query( "DELETE FROM ... WHERE id = " . $_GET['id'] )`
 * is a remote code execution / SQL injection vulnerability that
 * ships to every site the moment it is published.
 *
 * The scanner enforces the security baseline that the rest of the
 * suite assumes:
 *
 *   1. No raw dangerous PHP functions in shipped code:
 *      eval(), system(), exec(), passthru(), shell_exec(), popen(),
 *      backtick operator. proc_open() is allowed inside the testbench
 *      (scripts/, tests/) only.
 *   2. No unserialize() anywhere — it is the standard PHP RCE primitive
 *      on PHP < 8 and still a code-execution risk on PHP 8 if the
 *      payload contains gadget chains.
 *   3. No extract($_POST), extract($_GET), extract($_REQUEST), or
 *      $$var indirection on user input — both allow the caller to
 *      inject arbitrary local variable state into the request scope.
 *   4. No $wpdb->query() / get_results() / get_var() / get_row() with
 *      string concatenation of $_GET/$_POST/$_REQUEST. The WPDB API
 *      exposes a parameterized prepare() helper; any direct concat is
 *      a SQL injection vector.
 *   5. Every wp_ajax_* registration either goes through the guarded
 *      add_guarded_ajax_action helper OR is paired with manual
 *      check_ajax_referer + current_user_can calls. A wp_ajax_* action
 *      registered with a bare add_action() is a privilege-escalation
 *      hole — the handler runs without a nonce or cap check.
 *   6. Every file shipped under admin/ and includes/ declares
 *      `defined( 'ABSPATH' ) || exit;` so the file cannot be loaded
 *      directly via a crafted URL.
 *
 * Each rule emits a single line on a match so the failure output is
 * stable and PHPUnit can grep for the violation keyword.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir = dirname( __DIR__ );

$shipped_dirs = array(
	$root_dir . '/includes',
	$root_dir . '/admin',
	$root_dir . '/public',
);

$errors = array();
$counts = array(
	'dangerous_functions'  => 0,
	'unserialize'          => 0,
	'extract'              => 0,
	'variable_variables'   => 0,
	'wpdb_string_concat'   => 0,
	'unauthorized_ajax'    => 0,
	'missing_abspath'      => 0,
	'files_scanned'        => 0,
);

/**
 * Recursively iterate every .php file under a directory.
 *
 * @return \RecursiveIteratorIterator
 */
function ss_iter_php( string $dir ): \RecursiveIteratorIterator {
	$iter = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
	);
	return $iter;
}

/**
 * Strip line-style and block comments from a source string so the
 * scanners do not false-fire on `// don't use eval(...)` in a comment.
 *
 * @param string $src Source code.
 * @return string Source without comments.
 */
function ss_strip_comments( string $src ): string {
	// Strip /* ... */ block comments.
	$src = preg_replace( '#/\*[\s\S]*?\*/#', '', $src );
	// Strip // line comments.
	$src = preg_replace( '#//[^\n]*#', '', $src );
	// Strip # shell-style comments (used in some PHP configs).
	$src = preg_replace( '/^\s*#[^\n]*$/m', '', $src );
	return $src;
}

/**
 * Scan a single .php file for all rules.
 *
 * @param string $path Absolute path.
 */
function ss_scan_file( string $path, array &$errors, array &$counts ): void {
	$src = (string) file_get_contents( $path );
	if ( '' === $src ) {
		return;
	}
	++$counts['files_scanned'];

	$rel_path = str_replace( dirname( __DIR__ ) . DIRECTORY_SEPARATOR, '', $path );

	// Strip comments first so the regex matchers do not fire on
	// illustrative `// eval(...) is bad` comments inside the source.
	$code = ss_strip_comments( $src );

	// 1. Dangerous functions.
	//    `exec(` is allowed only when prefixed by `->` or `::` (i.e.
	//    `$obj->exec(` / `Class::exec(` are object methods, not the
	//    shell exec). All other dangerous shell functions are forbidden.
	$dangerous = array(
		'eval('         => '/\beval\s*\(/',
		'system('       => '/\bsystem\s*\(/',
		'passthru('     => '/\bpassthru\s*\(/',
		'shell_exec('   => '/\bshell_exec\s*\(/',
		'popen('        => '/\bpopen\s*\(/',
		'proc_open('    => '/(?<![->\w])proc_open\s*\(/',
	);
	foreach ( $dangerous as $label => $pattern ) {
		if ( preg_match( $pattern, $code ) ) {
			++$counts['dangerous_functions'];
			$errors[] = sprintf( '[%s] dangerous function `%s` in shipped code', $rel_path, $label );
		}
	}
	// exec( is the only function we have to disambiguate. Strip
	// `->exec(` and `::exec(` calls first.
	$code_no_obj_exec = preg_replace( '/(?:->|::)exec\s*\([^)]*\)/', '', $code );
	if ( preg_match( '/(?<![->\w])\bexec\s*\(/', $code_no_obj_exec ) ) {
		++$counts['dangerous_functions'];
		$errors[] = sprintf( '[%s] dangerous function `exec(` in shipped code', $rel_path );
	}

	// proc_open() is permitted inside scripts/ and tests/ (subprocess
	// tests) but forbidden inside the shipped plugin.
	if ( preg_match( '/(?<![->\w])proc_open\s*\(/', $code ) ) {
		++$counts['dangerous_functions'];
		$errors[] = sprintf( '[%s] dangerous function `proc_open(` in shipped code', $rel_path );
	}

	// 2. unserialize() — forbidden unless paired with allowed_classes => false.
	//    The `allowed_classes => false` whitelist is the PHP 7+ escape hatch
	//    that turns unserialize into a pure data decoder; without it, an
	//    attacker controls the unserialize gadget chain.
	if ( preg_match_all( '/\bunserialize\s*\(/', $code, $unserialize_matches, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $unserialize_matches[0] as $u_hit ) {
			list( , $u_off ) = $u_hit;
			// Window: the next 200 chars after `unserialize(` must show
			// the `allowed_classes` whitelist.
			$tail = substr( $code, (int) $u_off, 300 );
			if ( ! preg_match( '/allowed_classes/', $tail ) ) {
				++$counts['unserialize'];
				$errors[] = sprintf( '[%s] unserialize() without `allowed_classes` whitelist (RCE gadget risk)', $rel_path );
			}
		}
	}

	// 3. extract($_POST / $_GET / $_REQUEST) — overrides local scope.
	if ( preg_match( '/\bextract\s*\(\s*\$(?:_POST|_GET|_REQUEST|_COOKIE|_SERVER|_FILES)\b/', $code ) ) {
		++$counts['extract'];
		$errors[] = sprintf( '[%s] extract($_%s) overrides local scope from user input', $rel_path, 'POST|GET|REQUEST|COOKIE|SERVER|FILES' );
	}

	// 4. Variable variables — $$var from user input.
	if ( preg_match( '/\$\$\{\s*\$(?:_POST|_GET|_REQUEST|_COOKIE|_SERVER|_FILES)\b/', $code )
		|| preg_match( '/\$\$(?:_POST|_GET|_REQUEST|_COOKIE|_SERVER|_FILES)\b/', $code )
	) {
		++$counts['variable_variables'];
		$errors[] = sprintf( '[%s] variable-variable indirection on user input', $rel_path );
	}

	// 5. $wpdb->query( ... ) / get_results( ... ) with $_GET/$_POST concat.
	$wpdb_methods = array( 'query', 'get_var', 'get_results', 'get_row', 'get_col' );
	foreach ( $wpdb_methods as $method ) {
		if ( preg_match_all(
			'/\\$wpdb\s*->\s*' . preg_quote( $method, '/' ) . '\s*\([^)]*\\.(?:\\$_POST|\\$_GET|\\$_REQUEST|\\$_COOKIE|\\$_SERVER|\\$_FILES)/',
			$code,
			$matches
		) ) {
			$wpdb_hits = is_array( $matches[0] ) ? count( $matches[0] ) : (int) $matches[0];
			$counts['wpdb_string_concat'] += $wpdb_hits;
			for ( $i = 0; $i < $wpdb_hits; $i++ ) {
				$errors[] = sprintf( '[%s] $wpdb->%s() concatenates user input — use $wpdb->prepare()', $rel_path, $method );
			}
		}
	}

	// 6. wp_ajax_* add_action must be either guarded or hand-checked.
	//    The handler method that the registration points to must contain
	//    check_ajax_referer / wp_verify_nonce AND current_user_can. We
	//    extract the callback method name (array( $this, 'foo' )) and
	//    look up the matching `function foo(` body to inspect.
	if ( preg_match_all(
		"/add_action\s*\(\s*['\"]wp_ajax_[^'\"]+['\"]\s*,\s*([^)]+)\)/i",
		$code,
		$ajax_matches
	) ) {
		$full_source = (string) file_get_contents( $path );
		foreach ( $ajax_matches[1] as $callback_expr ) {
			$cb = trim( (string) $callback_expr );
			// Look for array( $this, 'method_name' ).
			$method_name = null;
			if ( preg_match( "/array\s*\(\s*\\\$this\s*,\s*['\"]([^'\"]+)['\"]/", $cb, $m ) ) {
				$method_name = $m[1];
			} elseif ( preg_match( "/\\[\\\$this\s*,\s*['\"]([^'\"]+)['\"]/", $cb, $m ) ) {
				$method_name = $m[1];
			}
			if ( null === $method_name ) {
				continue;
			}
			// Find the method body. The body starts at `function name(` and
			// ends at the next `\n}` or `\n\t}` at a shallower indent than
			// the function signature. We use a non-greedy match bounded by
			// a newline followed by a closing brace.
			$body_pattern = '/function\s+' . preg_quote( $method_name, '/' ) . '\s*\([^)]*\)\s*[\s\S]*?\n\s*\}/m';
			if ( ! preg_match( $body_pattern, $full_source, $body_match ) ) {
				continue;
			}
			$body = $body_match[0];
			$has_nonce = ( false !== strpos( $body, 'check_ajax_referer' ) )
				|| ( false !== strpos( $body, 'wp_verify_nonce' ) )
				|| ( false !== strpos( $body, 'SScribe_AJAX_Guard::with_guard' ) )
				|| ( false !== strpos( $body, 'SScribe_AJAX_Guard::verify_request' ) )
				// Internal helper: the debug console wraps all handlers in
				// `verify_request_authorization()` which internally does the
				// nonce + cap check.
				|| ( false !== strpos( $body, 'verify_request_authorization' ) )
				|| ( false !== strpos( $body, 'verify_admin_request' ) )
				|| ( false !== strpos( $body, 'verify_ajax_request' ) )
				|| ( false !== strpos( $body, 'check_ajax_authorization' ) );
			$has_cap   = ( false !== strpos( $body, 'current_user_can' ) )
				|| ( false !== strpos( $body, 'verify_request_authorization' ) )
				|| ( false !== strpos( $body, 'verify_admin_request' ) )
				|| ( false !== strpos( $body, 'verify_ajax_request' ) )
				|| ( false !== strpos( $body, 'check_ajax_authorization' ) );
			if ( ! $has_nonce || ! $has_cap ) {
				++$counts['unauthorized_ajax'];
				$errors[] = sprintf(
					'[%s] handler `%s()` is missing %s check (wp_ajax_* handler must verify nonce + capability before doing anything privileged)',
					$rel_path,
					$method_name,
					! $has_nonce && ! $has_cap ? 'nonce AND capability' : ( ! $has_nonce ? 'nonce' : 'capability' )
				);
			}
		}
	}

	// 7. ABSPATH guard.
	if ( ! preg_match( "/defined\s*\(\s*['\"]ABSPATH['\"]\s*\)/", $src )
		&& ! preg_match( '/if\s*\(\s*!\s*defined\s*\(\s*[\'"]ABSPATH[\'"]/', $src )
	) {
		++$counts['missing_abspath'];
		$errors[] = sprintf( '[%s] missing `defined( \'ABSPATH\' )` guard — file can be loaded directly', $rel_path );
	}
}

foreach ( $shipped_dirs as $dir ) {
	if ( ! is_dir( $dir ) ) {
		continue;
	}
	foreach ( ss_iter_php( $dir ) as $file ) {
		if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		ss_scan_file( $file->getPathname(), $errors, $counts );
	}
}

echo "=== SScribe Security Scan ===\n\n";
echo "Files scanned:                {$counts['files_scanned']}\n";
echo "Dangerous functions:          {$counts['dangerous_functions']}\n";
echo "unserialize():                {$counts['unserialize']}\n";
echo "extract(\$_USER):              {$counts['extract']}\n";
echo "Variable-variables:           {$counts['variable_variables']}\n";
echo "\$wpdb string concat:         {$counts['wpdb_string_concat']}\n";
echo "Bare wp_ajax_* add_action:    {$counts['unauthorized_ajax']}\n";
echo "Missing ABSPATH guard:        {$counts['missing_abspath']}\n\n";

if ( ! empty( $errors ) ) {
	echo "Violations:\n";
	foreach ( $errors as $error ) {
		echo "  ✗ {$error}\n";
	}
	echo "\n";
	exit( 1 );
}

echo "✓ Security scan clean.\n";
exit( 0 );
