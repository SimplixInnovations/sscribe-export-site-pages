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
	 * Resolve a binary via PATH.
	 *
	 * @param string $binary Binary name, e.g. 'composer'.
	 * @return string Absolute path, or the bare name when not found.
	 */
	function sscribe_which( string $binary ): string {
		$probe = sscribe_is_windows() ? 'where ' . $binary : 'command -v ' . $binary;
		$lines = array();
		$code  = 1;
		exec( $probe . ' 2>' . ( sscribe_is_windows() ? 'NUL' : '/dev/null' ), $lines, $code );
		if ( 0 === $code && isset( $lines[0] ) && '' !== trim( $lines[0] ) ) {
			return trim( $lines[0] );
		}
		return $binary;
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

if ( ! function_exists( 'sscribe_run' ) ) {
	/**
	 * Run a command, optionally teeing output to a log file.
	 *
	 * Windows batch shims (.bat/.cmd) are executed via `cmd /c` so exit
	 * codes propagate; POSIX shells run the command directly.
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
		if ( sscribe_is_windows() && preg_match( '/\.(bat|cmd)(\s|$)/i', $command ) ) {
			$command = 'cmd /c ' . $command;
		}
		$start = hrtime( true );
		$spec  = array(
			0 => array( 'file', sscribe_is_windows() ? 'NUL' : '/dev/null', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$proc  = proc_open( $command, $spec, $pipes, $cwd, $env + $_SERVER );
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
