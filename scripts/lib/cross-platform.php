<?php
/**
 * Cross-platform process, download, and filesystem helpers for SScribe tooling.
 *
 * Single home for the OS-bridging logic shared by the canonical PHP
 * implementations (scripts/install-wp-tests.php,
 * scripts/uninstall-wp-tests.php, scripts/release-audit.php) so Windows
 * and Linux execute the same code paths instead of duplicated
 * shell/batch business logic.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( ! function_exists( 'sscribe_is_windows' ) ) {
	/**
	 * Whether the current OS is Windows.
	 *
	 * @return bool
	 */
	function sscribe_is_windows(): bool {
		return strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN';
	}
}

if ( ! function_exists( 'sscribe_repo_root' ) ) {
	/**
	 * Repository root derived from this file's location.
	 *
	 * @return string Absolute path with forward slashes.
	 */
	function sscribe_repo_root(): string {
		return str_replace( '\\', '/', dirname( __DIR__, 2 ) );
	}
}

if ( ! function_exists( 'sscribe_which' ) ) {
	/**
	 * Resolve a binary via PATH to a directly usable file.
	 *
	 * On Windows the result is normalized to a concrete file: when PATH
	 * resolution yields an extensionless name, PATHEXT-style siblings
	 * (.exe, .bat, .cmd, .ps1) are probed so callers never depend on
	 * shell fallback behavior.
	 *
	 * @param string $binary Binary name, e.g. 'composer'.
	 * @return string Absolute path, or the bare name when not found.
	 */
	function sscribe_which( string $binary ): string {
		$probe = sscribe_is_windows() ? 'where ' . $binary : 'command -v ' . $binary;
		$lines = array();
		$code  = 1;
		exec( $probe . ' 2>' . ( sscribe_is_windows() ? 'NUL' : '/dev/null' ), $lines, $code );
		$found = ( 0 === $code && isset( $lines[0] ) && '' !== trim( $lines[0] ) ) ? trim( $lines[0] ) : $binary;
		if ( sscribe_is_windows() && '' === pathinfo( $found, PATHINFO_EXTENSION ) ) {
			foreach ( array( '.exe', '.bat', '.cmd', '.ps1' ) as $ext ) {
				if ( is_file( $found . $ext ) ) {
					return $found . $ext;
				}
			}
		}
		return $found;
	}
}

if ( ! function_exists( 'sscribe_composer_cmd' ) ) {
	/**
	 * Composer invocation honoring COMPOSER_BINARY when set.
	 *
	 * @return string
	 */
	function sscribe_composer_cmd(): string {
		$override = getenv( 'COMPOSER_BINARY' );
		if ( is_string( $override ) && '' !== $override ) {
			return $override;
		}
		return sscribe_which( 'composer' );
	}
}

if ( ! function_exists( 'sscribe_npm_cmd' ) ) {
	/**
	 * npm invocation honoring NPM_BINARY when set.
	 *
	 * @return string
	 */
	function sscribe_npm_cmd(): string {
		$override = getenv( 'NPM_BINARY' );
		if ( is_string( $override ) && '' !== $override ) {
			return $override;
		}
		return sscribe_which( 'npm' );
	}
}

if ( ! function_exists( 'sscribe_run_argv' ) ) {
	/**
	 * Run a command from an argument vector without shell command-string parsing.
	 *
	 * Windows launch rules:
	 *   - native executables: direct
	 *   - .bat/.cmd: cmd.exe /d /s /c
	 *   - .ps1: pwsh.exe, falling back to powershell.exe
	 *   - PHP proxy scripts / PHARs: PHP_BINARY
	 *
	 * Stdout and stderr go to temporary files instead of pipes so a verbose
	 * child cannot deadlock while the parent drains the other stream.
	 *
	 * @param array       $argv Argument vector; argv[0] is the executable.
	 * @param string|null $cwd  Working directory. Defaults to repo root.
	 * @param array       $env  Extra environment variables.
	 * @param string|null $log  Append combined output to this file.
	 * @return array{code:int,ms:int}
	 */
	function sscribe_run_argv( array $argv, ?string $cwd = null, array $env = array(), ?string $log = null ): array {
		if ( null === $cwd ) {
			$cwd = sscribe_repo_root();
		}
		if ( empty( $argv ) || ! is_string( $argv[0] ) || '' === $argv[0] ) {
			return array( 'code' => 127, 'ms' => 0 );
		}

		$exe = $argv[0];
		if ( sscribe_is_windows() ) {
			$lower = strtolower( $exe );
			if ( str_ends_with( $lower, '.ps1' ) ) {
				$runner = sscribe_resolve_powershell();
				if ( null === $runner ) {
					return array( 'code' => 127, 'ms' => 0 );
				}
				$argv = array_merge(
					array( $runner, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $exe ),
					array_slice( $argv, 1 )
				);
			} elseif ( str_ends_with( $lower, '.bat' ) || str_ends_with( $lower, '.cmd' ) ) {
				$argv = array_merge( array( 'cmd.exe', '/d', '/s', '/c', $exe ), array_slice( $argv, 1 ) );
			} elseif ( ! str_ends_with( $lower, '.exe' ) && ! is_executable( $exe ) ) {
				$argv = array_merge( array( PHP_BINARY, $exe ), array_slice( $argv, 1 ) );
			}
		}

		$start   = hrtime( true );
		$out_tmp = tempnam( sys_get_temp_dir(), 'sscribe-out-' );
		$err_tmp = tempnam( sys_get_temp_dir(), 'sscribe-err-' );
		if ( false === $out_tmp || false === $err_tmp ) {
			if ( is_string( $out_tmp ) ) { @unlink( $out_tmp ); }
			if ( is_string( $err_tmp ) ) { @unlink( $err_tmp ); }
			return array( 'code' => 127, 'ms' => (int) ( ( hrtime( true ) - $start ) / 1000000 ) );
		}

		$spec = array(
			0 => array( 'file', sscribe_is_windows() ? 'NUL' : '/dev/null', 'r' ),
			1 => array( 'file', $out_tmp, 'w' ),
			2 => array( 'file', $err_tmp, 'w' ),
		);
		$merged = getenv();
		if ( ! is_array( $merged ) ) {
			$merged = array();
		}
		foreach ( $env as $key => $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				$merged[ (string) $key ] = null === $value ? '' : (string) $value;
			}
		}

		$proc = proc_open( $argv, $spec, $pipes, $cwd, $merged );
		if ( ! is_resource( $proc ) ) {
			@unlink( $out_tmp );
			@unlink( $err_tmp );
			return array( 'code' => 127, 'ms' => (int) ( ( hrtime( true ) - $start ) / 1000000 ) );
		}
		$code = proc_close( $proc );
		$ms   = (int) ( ( hrtime( true ) - $start ) / 1000000 );
		$out  = (string) @file_get_contents( $out_tmp );
		$err  = (string) @file_get_contents( $err_tmp );
		@unlink( $out_tmp );
		@unlink( $err_tmp );

		if ( null !== $log ) {
			file_put_contents( $log, $out . $err, FILE_APPEND );
		}
		if ( getenv( 'SSCRIBE_TOOL_VERBOSE' ) ) {
			fwrite( STDOUT, $out );
			fwrite( STDERR, $err );
		}
		return array( 'code' => $code, 'ms' => $ms );
	}
}

if ( ! function_exists( 'sscribe_resolve_powershell' ) ) {
	/**
	 * Resolve PowerShell without routing .ps1 files through cmd.exe.
	 *
	 * @return string|null
	 */
	function sscribe_resolve_powershell(): ?string {
		foreach ( array( 'pwsh.exe', 'powershell.exe' ) as $candidate ) {
			$resolved = sscribe_which( $candidate );
			if ( $resolved !== $candidate || is_file( $resolved ) ) {
				return $resolved;
			}
		}
		return null;
	}
}

if ( ! function_exists( 'sscribe_run' ) ) {
	/**
	 * Run a shell command line (POSIX shells only).
	 *
	 * Prefer sscribe_run_argv(): string commands cannot run without a
	 * shell and therefore cannot guarantee exit-code fidelity on
	 * Windows. This wrapper is kept for POSIX-only contexts.
	 *
	 * @param string      $command Shell command line.
	 * @param string|null $cwd     Working directory. Defaults to repo root.
	 * @param array       $env     Extra environment variables.
	 * @param string|null $log     Append combined output to this file.
	 * @return array{code:int,ms:int} Exit code and wall time in milliseconds.
	 */
	function sscribe_run( string $command, ?string $cwd = null, array $env = array(), ?string $log = null ): array {
		if ( null === $cwd ) {
			$cwd = sscribe_repo_root();
		}
		$start = hrtime( true );
		$spec  = array(
			0 => array( 'file', '/dev/null', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$merged = getenv();
		if ( ! is_array( $merged ) ) {
			$merged = array();
		}
		foreach ( $env as $key => $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				$merged[ (string) $key ] = null === $value ? '' : (string) $value;
			}
		}
		$proc = proc_open( $command, $spec, $pipes, $cwd, $merged );
		if ( ! is_resource( $proc ) ) {
			return array(
				'code' => 127,
				'ms'   => (int) ( ( hrtime( true ) - $start ) / 1000000 ),
			);
		}
		$out = stream_get_contents( $pipes[1] );
		$err = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $proc );
		$ms   = (int) ( ( hrtime( true ) - $start ) / 1000000 );
		if ( null !== $log ) {
			file_put_contents( $log, (string) $out . (string) $err, FILE_APPEND );
		}
		if ( getenv( 'SSCRIBE_TOOL_VERBOSE' ) ) {
			fwrite( STDOUT, (string) $out );
			fwrite( STDERR, (string) $err );
		}
		return array(
			'code' => $code,
			'ms'   => $ms,
		);
	}
}

if ( ! function_exists( 'sscribe_http_get' ) ) {
	/**
	 * Fetch a URL body, failing closed on transport or HTTP errors.
	 *
	 * Prefers the curl extension; falls back to file_get_contents with a
	 * stream context. Requires an HTTP 200 response.
	 *
	 * @param string $url Absolute URL.
	 * @return string Response body.
	 * @throws RuntimeException On any failure.
	 */
	function sscribe_http_get( string $url ): string {
		if ( function_exists( 'curl_init' ) ) {
			$ch = curl_init( $url );
			if ( false === $ch ) {
				throw new RuntimeException( "curl_init failed for {$url}" );
			}
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_MAXREDIRS      => 5,
					CURLOPT_CONNECTTIMEOUT => 30,
					CURLOPT_TIMEOUT        => 300,
					CURLOPT_USERAGENT      => 'SScribe-Tooling/1.0',
				)
			);
			$body = curl_exec( $ch );
			$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
			$err  = curl_error( $ch );
			curl_close( $ch );
			if ( false === $body || '' !== $err ) {
				throw new RuntimeException( "Download failed for {$url}: {$err}" );
			}
			if ( 200 !== $code ) {
				throw new RuntimeException( "Download failed for {$url}: HTTP {$code}" );
			}
			return $body;
		}
		$ctx  = stream_context_create(
			array(
				'http' => array(
					'timeout'       => 300,
					'ignore_errors' => true,
					'header'        => "User-Agent: SScribe-Tooling/1.0\r\n",
				),
			)
		);
		$body = @file_get_contents( $url, false, $ctx );
		if ( false === $body ) {
			throw new RuntimeException( "Download failed for {$url}: transport error (curl extension unavailable)" );
		}
		$status = 0;
		if ( isset( $http_response_header[0] ) && preg_match( '/\s(\d{3})\s/', $http_response_header[0], $m ) ) {
			$status = (int) $m[1];
		}
		if ( 200 !== $status ) {
			throw new RuntimeException( "Download failed for {$url}: HTTP {$status}" );
		}
		return $body;
	}
}

if ( ! function_exists( 'sscribe_download_file' ) ) {
	/**
	 * Download a URL to a local file, failing closed on empty results.
	 *
	 * @param string $url  Absolute URL.
	 * @param string $dest Local file path (parent must exist).
	 * @throws RuntimeException On any failure.
	 */
	function sscribe_download_file( string $url, string $dest ): void {
		$body = sscribe_http_get( $url );
		if ( '' === $body ) {
			throw new RuntimeException( "Download failed for {$url}: empty response body" );
		}
		if ( false === file_put_contents( $dest, $body ) ) {
			throw new RuntimeException( "Cannot write download to {$dest}" );
		}
	}
}

if ( ! function_exists( 'sscribe_rmdir' ) ) {
	/**
	 * Recursively delete a directory tree; missing paths are a no-op.
	 *
	 * @param string $dir Directory path.
	 */
	function sscribe_rmdir( string $dir ): void {
		if ( ! is_dir( $dir ) && ! is_link( $dir ) ) {
			return;
		}
		if ( is_link( $dir ) ) {
			@unlink( $dir );
			return;
		}
		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				sscribe_rmdir( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}
}

if ( ! function_exists( 'sscribe_copy_tree' ) ) {
	/**
	 * Recursively copy a tree, skipping VCS metadata and composer manifests.
	 *
	 * Mirrors the rsync/cp fallback in bin/install-wp-tests.sh: `.git`
	 * directories and `composer.json` files are never copied.
	 *
	 * @param string $src Source directory.
	 * @param string $dst Destination directory (created as needed).
	 * @throws RuntimeException When the copy cannot complete.
	 */
	function sscribe_copy_tree( string $src, string $dst ): void {
		if ( ! is_dir( $dst ) && ! mkdir( $dst, 0777, true ) && ! is_dir( $dst ) ) {
			throw new RuntimeException( "Cannot create directory {$dst}" );
		}
		$items = scandir( $src );
		if ( false === $items ) {
			throw new RuntimeException( "Cannot read directory {$src}" );
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item || '.git' === $item || 'composer.json' === $item ) {
				continue;
			}
			$s = $src . '/' . $item;
			$d = $dst . '/' . $item;
			if ( is_dir( $s ) && ! is_link( $s ) ) {
				sscribe_copy_tree( $s, $d );
			} elseif ( is_file( $s ) ) {
				if ( ! copy( $s, $d ) ) {
					throw new RuntimeException( "Cannot copy {$s} to {$d}" );
				}
			}
		}
	}
}

if ( ! function_exists( 'sscribe_log' ) ) {
	/**
	 * Print a namespaced tooling log line.
	 *
	 * @param string $tag  Bracket label without brackets, e.g. 'install-wp-tests'.
	 * @param string $text Message.
	 */
	function sscribe_log( string $tag, string $text ): void {
		fwrite( STDOUT, "[{$tag}] {$text}\n" );
	}
}

if ( ! function_exists( 'sscribe_fail' ) ) {
	/**
	 * Print an error to STDERR and exit non-zero (fail closed).
	 *
	 * @param string $text Message.
	 * @param int    $code Exit code.
	 * @return never
	 */
	function sscribe_fail( string $text, int $code = 1 ): void {
		fwrite( STDERR, "ERROR: {$text}\n" );
		exit( $code );
	}
}
