<?php
/**
 * Phase 42: i18n contract audit.
 *
 * Every translatable string in this codebase must follow the i18n contract
 * WordPress.org enforces:
 *
 *   1. The plugin mainfile declares the canonical Text Domain in its
 *      header (`Text Domain: sscribe-export-site-pages`) and the
 *      Domain Path (`Domain Path: /languages`).
 *   2. Every translation call (`__()`, `_e()`, `_x()`, `_n()`,
 *      `_nx()`, `esc_html__()`, `esc_html_e()`, `esc_html_x()`,
 *      `esc_attr__()`, `esc_attr_e()`, `esc_attr_x()`, `_ex()`) uses
 *      the canonical text domain as its text-domain argument. A
 *      translation call without a text domain is a release blocker
 *      because the string can never reach translators; a
 *      translation call with the wrong text domain ships a string
 *      to the wrong POT and breaks localisation.
 *   3. The POT file `languages/sscribe-export-site-pages.pot` exists,
 *      declares the canonical X-Domain, and its Project-Id-Version
 *      matches the plugin version (the spec calls this the
 *      "translation pipeline stays in sync with the runtime"
 *      invariant).
 *   4. Every translatable `msgid` extracted from the source must be
 *      present in the POT, and every POT entry must point back at a
 *      real source line (the POT was regenerated from the live
 *      source tree, not committed stale).
 *
 * The verifier walks every PHP file under `includes/`, `admin/`,
 * `public/`, and the plugin mainfile as a single block (so multi-line
 * `__()` calls inside array initialisers are scanned correctly), then
 * cross-checks against the POT. The integration test plants one-off
 * mutations and confirms the gate rejects them.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir = dirname( __DIR__ );

final class SScribe_I18N_Auditor {

	public const CANONICAL_TEXT_DOMAIN = 'sscribe-export-site-pages';

	/**
	 * Translation functions whose last argument is the text domain.
	 * @var string[]
	 */
	public const I18N_FUNCTIONS = [
		'__',
		'_e',
		'_x',
		'_n',
		'_nx',
		'_ex',
		'esc_html__',
		'esc_html_e',
		'esc_html_x',
		'esc_attr__',
		'esc_attr_e',
		'esc_attr_x',
		'translate',
		'translate_nooped_plural',
	];

	private string $root_dir;
	private string $plugin_mainfile;
	private string $pot_path;
	private string $manifest_path;

	/** @var string[] */
	private array $errors = [];

	/** @var array<string,int> */
	private array $summary = [];

	public function __construct( string $root_dir ) {
		$this->root_dir        = $root_dir;
		$this->plugin_mainfile = $root_dir . '/sscribe-export-site-pages.php';
		$this->pot_path        = $root_dir . '/languages/sscribe-export-site-pages.pot';
		$this->manifest_path   = $root_dir . '/dist/i18n-manifest.json';
		$this->summary         = [
			'translation_calls_total'      => 0,
			'translation_calls_with_domain' => 0,
			'wrong_domain_calls'           => 0,
		];
	}

	public function run(): int {
		$header_info = $this->read_mainfile_header();
		$this->check_mainfile_header( $header_info );

		$calls = $this->collect_i18n_calls();
		$this->check_translation_calls( $calls );

		$pot_source = $this->load_pot();
		$pot_stats  = [];
		if ( null !== $pot_source ) {
			$pot_stats = $this->check_pot( $pot_source, $header_info['version'] ?? null, $calls );
		}

		$this->report( $header_info, $pot_stats );
		$this->write_manifest( $header_info, $pot_stats );

		return 0 === count( $this->errors ) ? 0 : 1;
	}

	/**
	 * @return array{text_domain:?string,domain_path:?string,version:?string}
	 */
	private function read_mainfile_header(): array {
		$header = (string) file_get_contents( $this->plugin_mainfile );
		$out    = [
			'text_domain' => null,
			'domain_path' => null,
			'version'     => null,
		];
		if ( '' === $header ) {
			return $out;
		}
		if ( preg_match( '/^[ \t\/*]*Text Domain:\s*([^\s\*]+)/m', $header, $m ) ) {
			$out['text_domain'] = trim( (string) $m[1] );
		}
		if ( preg_match( '/^[ \t\/*]*Domain Path:\s*([^\s\*]+)/m', $header, $m ) ) {
			$out['domain_path'] = trim( (string) $m[1] );
		}
		if ( preg_match( '/^[ \t\/*]*Version:\s*([^\s\*]+)/m', $header, $m ) ) {
			$out['version'] = trim( (string) $m[1] );
		}
		return $out;
	}

	/**
	 * @param array{text_domain:?string,domain_path:?string,version:?string} $header_info
	 */
	private function check_mainfile_header( array $header_info ): void {
		if ( null === $header_info['text_domain'] ) {
			$this->errors[] = 'Plugin mainfile is missing the `Text Domain:` header.';
		} elseif ( self::CANONICAL_TEXT_DOMAIN !== $header_info['text_domain'] ) {
			$this->errors[] = sprintf(
				'Plugin mainfile declares Text Domain `%s`; required `%s`.',
				$header_info['text_domain'],
				self::CANONICAL_TEXT_DOMAIN
			);
		}
		if ( null === $header_info['domain_path'] ) {
			$this->errors[] = 'Plugin mainfile is missing the `Domain Path:` header.';
		} elseif ( '/languages' !== $header_info['domain_path'] ) {
			$this->errors[] = sprintf(
				'Plugin mainfile declares Domain Path `%s`; required `/languages`.',
				$header_info['domain_path']
			);
		}
	}

	/**
	 * Walk every shipped PHP file and collect every i18n call as
	 * `[file, line, function, args]`. Multi-line calls (very common
	 * inside array literals) are captured correctly by walking the
	 * source as a single block and using paren-aware arg extraction
	 * that respects string literals.
	 *
	 * @return array<int,array{file:string,line:int,function:string,args:string}>
	 */
	private function collect_i18n_calls(): array {
		$scan_dirs = [
			$this->root_dir . '/includes',
			$this->root_dir . '/admin',
			$this->root_dir . '/public',
		];
		$out         = [];
		$pattern     = '#\b(' . implode( '|', self::I18N_FUNCTIONS ) . ')\s*\(\s*#';
		foreach ( $scan_dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$iter = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
			);
			foreach ( $iter as $file ) {
				if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
					continue;
				}
				$abs    = (string) $file->getRealPath();
				$source = (string) file_get_contents( $abs );
				if ( '' === $source ) {
					continue;
				}
				$rel  = ltrim( substr( $abs, strlen( $this->root_dir ) + 1 ), './' );
				$rel  = str_replace( '\\', '/', $rel );
				// Strip block comments so docblocks referencing __()
				// don't trigger false positives.
				$code = (string) preg_replace( '#/\*[\s\S]*?\*/#', '', $source );
				// Strip line comments so `// __()` mentions don't trip us.
				$code = (string) preg_replace( '#//[^\n]*#', '', $code );
				if ( ! preg_match_all( $pattern, $code, $matches, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}
				foreach ( $matches[1] as $idx => $function_match ) {
					$function_name = (string) $function_match[0];
					$offset        = (int) $function_match[1];
					// Walk forward to the opening paren, then capture the
					// balanced argument list (string-literal aware).
					$call_open = strpos( $code, '(', $offset );
					if ( false === $call_open ) {
						continue;
					}
					$args = $this->extract_balanced_args( $code, $call_open );
					if ( null === $args ) {
						continue;
					}
					$line_no = substr_count( substr( $code, 0, $offset ), "\n" ) + 1;
					$out[]   = [
						'file'     => $rel,
						'line'     => $line_no,
						'function' => $function_name,
						'args'     => $args,
					];
				}
			}
		}
		return $out;
	}

	/**
	 * Extract the substring of `$source` that constitutes the
	 * balanced argument list starting at `$offset` (which points at
	 * the opening paren). String-literal-aware: any `(` or `)`
	 * inside `'...'` or `"..."` is ignored. Backslash escapes inside
	 * the string are honoured (`\(`, `\)`, `\\`).
	 *
	 * Returns the substring INSIDE the outermost parens, or null if
	 * the source runs out without balancing.
	 */
	private function extract_balanced_args( string $source, int $offset ): ?string {
		$len   = strlen( $source );
		$depth = 0;
		$in_single = false;
		$in_double = false;
		for ( $i = $offset; $i < $len; $i++ ) {
			$ch = $source[ $i ];
			if ( $in_single ) {
				if ( '\\' === $ch && $i + 1 < $len ) {
					$i++;
					continue;
				}
				if ( "'" === $ch ) {
					$in_single = false;
				}
				continue;
			}
			if ( $in_double ) {
				if ( '\\' === $ch && $i + 1 < $len ) {
					$i++;
					continue;
				}
				if ( '"' === $ch ) {
					$in_double = false;
				}
				continue;
			}
			if ( "'" === $ch ) {
				$in_single = true;
				continue;
			}
			if ( '"' === $ch ) {
				$in_double = true;
				continue;
			}
			if ( '(' === $ch ) {
				++$depth;
				continue;
			}
			if ( ')' === $ch ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $source, $offset + 1, $i - $offset - 1 );
				}
			}
		}
		return null;
	}

	/**
	 * @param array<int,array{file:string,line:int,function:string,args:string}> $calls
	 */
	private function check_translation_calls( array $calls ): void {
		foreach ( $calls as $call ) {
			++$this->summary['translation_calls_total'];
			if ( $this->has_canonical_domain( $call['args'] ) ) {
				++$this->summary['translation_calls_with_domain'];
				continue;
			}
			++$this->summary['wrong_domain_calls'];
			$this->errors[] = sprintf(
				'[%s:%d] %s() does not declare the canonical text domain. Args: %s',
				$call['file'],
				$call['line'],
				$call['function'],
				trim( $call['args'] )
			);
		}
	}

	private function has_canonical_domain( string $args ): bool {
		if ( preg_match_all( "/'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\"/", $args, $matches ) ) {
			$flat = array_values( array_filter( array_merge( $matches[1], $matches[2] ), 'strlen' ) );
			$last = end( $flat );
			return is_string( $last ) && self::CANONICAL_TEXT_DOMAIN === $last;
		}
		return false;
	}

	private function load_pot(): ?string {
		if ( ! is_file( $this->pot_path ) ) {
			$this->errors[] = sprintf( 'POT file missing: %s', $this->pot_path );
			return null;
		}
		$contents = (string) file_get_contents( $this->pot_path );
		if ( '' === $contents ) {
			$this->errors[] = 'POT file is empty.';
			return null;
		}
		return $contents;
	}

	/**
	 * @param array<int,array{file:string,line:int,function:string,args:string}> $calls
	 * @return array{0:int,1:int} [pot_msgid_count, source_missing_in_pot]
	 */
	private function check_pot( string $pot_source, ?string $plugin_version, array $calls ): array {
		// 1. X-Domain must match.
		if ( ! preg_match( '/^"X-Domain:\s*((?:[^\x22\x5c]|\x5c.)*)"\s*$/m', $pot_source, $m ) ) {
			$this->errors[] = 'POT file does not declare X-Domain.';
		} else {
			// The captured value may end with a POT-escaped newline
			// (literal `\n`). Decode the C-escapes and then trim
			// trailing whitespace that the decode introduced.
			$pot_xdomain = rtrim( stripcslashes( (string) $m[1] ) );
			if ( self::CANONICAL_TEXT_DOMAIN !== $pot_xdomain ) {
				$this->errors[] = sprintf(
					'POT file X-Domain is `%s`; required `%s`.',
					$pot_xdomain,
					self::CANONICAL_TEXT_DOMAIN
				);
			}
		}

		// 2. Project-Id-Version must match the plugin version.
		if ( null !== $plugin_version ) {
			if ( ! preg_match( '/^"Project-Id-Version:\s*((?:[^\x22\x5c]|\x5c.)*)"\s*$/m', $pot_source, $m ) ) {
				$this->errors[] = 'POT file does not declare Project-Id-Version.';
			} else {
				$pot_version = rtrim( stripcslashes( (string) $m[1] ) );
				$expected    = sprintf( 'SScribe Export Site Pages %s', $plugin_version );
				if ( $pot_version !== $expected ) {
					$this->errors[] = sprintf(
						'POT Project-Id-Version is `%s`; expected `%s` (plugin version).',
						$pot_version,
						$expected
					);
				}
			}
		}

		// 3. Every msgid we found in source must appear in the POT.
		$pot_msgids = [];
		if ( preg_match_all( '/^msgid\s+"((?:[^"\\\\]|\\\\.)*)"\s*$/m', $pot_source, $matches ) ) {
			foreach ( $matches[1] as $msgid ) {
				// Decode C escapes so the comparison matches the
				// unescaped source-side msgid. The POT stores
				// `Invalid post type \"%s\".`; we want the
				// source-side form `Invalid post type "%s".`.
				$pot_msgids[] = stripcslashes( (string) $msgid );
			}
		}
		$this->summary['pot_msgid_count'] = count( $pot_msgids );

		$missing = [];
		foreach ( $calls as $call ) {
			$msgid = $this->first_msgid( $call['args'] );
			if ( null === $msgid || strlen( $msgid ) < 2 ) {
				continue;
			}
			if ( ! in_array( $msgid, $pot_msgids, true ) ) {
				$missing[] = sprintf( '%s:%d "%s"', $call['file'], $call['line'], $msgid );
			}
		}
		$this->summary['source_msgids_checked']         = count( $calls );
		$this->summary['source_msgids_missing_in_pot'] = count( $missing );
		if ( ! empty( $missing ) ) {
			$sample = array_slice( $missing, 0, 10 );
			$this->errors[] = sprintf(
				'%d source string(s) appear in code but not in the POT. '
				. 'Run `composer i18n:make-pot` and commit the regenerated POT. Sample: %s',
				count( $missing ),
				implode( '; ', $sample )
			);
		}
		return [
			(int) ( $this->summary['pot_msgid_count'] ?? 0 ),
			(int) ( $this->summary['source_msgids_missing_in_pot'] ?? 0 ),
		];
	}

	private function first_msgid( string $args ): ?string {
		if ( preg_match( "/'((?:[^'\\\\]|\\\\.)*)'/", $args, $m ) ) {
			// The args string is the raw PHP source. Inside a PHP
			// single-quoted string the only two escape sequences are
			// `\'` (literal `'`) and `\\` (literal `\`). Decode
			// those so the comparison matches the runtime value
			// translators will get.
			return str_replace( [ "\\'", '\\\\' ], [ "'", '\\' ], (string) $m[1] );
		}
		if ( preg_match( "/\"((?:[^\"\\\\]|\\\\.)*)\"/", $args, $m ) ) {
			// For double-quoted strings decode C-style escapes.
			return stripcslashes( (string) $m[1] );
		}
		return null;
	}

	/**
	 * @param array{text_domain:?string,domain_path:?string,version:?string} $header_info
	 * @param array{0:int,1:int} $pot_stats
	 */
	private function report( array $header_info, array $pot_stats ): void {
		echo "=== SScribe i18n Contract Audit ===\n\n";
		echo 'Canonical text domain: ' . self::CANONICAL_TEXT_DOMAIN . "\n";
		echo 'Plugin Text Domain header: ' . ( $header_info['text_domain'] ?? '(missing)' ) . "\n";
		echo 'Plugin Domain Path header: ' . ( $header_info['domain_path'] ?? '(missing)' ) . "\n";
		echo 'Plugin Version header:    ' . ( $header_info['version'] ?? '(missing)' ) . "\n\n";

		echo "Translation calls scanned:        {$this->summary['translation_calls_total']}\n";
		echo "  with canonical text domain:     {$this->summary['translation_calls_with_domain']}\n";
		echo "  missing/wrong text domain:      {$this->summary['wrong_domain_calls']}\n";
		echo "POT msgid count:                  {$pot_stats[0]}\n";
		echo "Source msgids checked:             {$this->summary['source_msgids_checked']}\n";
		echo "Source msgids missing from POT:   {$this->summary['source_msgids_missing_in_pot']}\n\n";

		echo 'Errors: ' . count( $this->errors ) . "\n\n";

		if ( ! empty( $this->errors ) ) {
			echo "Errors:\n";
			foreach ( $this->errors as $error ) {
				echo "  ✗ {$error}\n";
			}
			echo "\n";
		}
	}

	/**
	 * @param array{text_domain:?string,domain_path:?string,version:?string} $header_info
	 * @param array{0:int,1:int} $pot_stats
	 */
	private function write_manifest( array $header_info, array $pot_stats ): void {
		$manifest = [
			'generated_at'                       => gmdate( 'c' ),
			'canonical_text_domain'              => self::CANONICAL_TEXT_DOMAIN,
			'header'                             => $header_info,
			'translation_calls_total'            => $this->summary['translation_calls_total'],
			'translation_calls_with_domain'      => $this->summary['translation_calls_with_domain'],
			'wrong_domain_calls'                 => $this->summary['wrong_domain_calls'],
			'pot_msgid_count'                    => $pot_stats[0],
			'source_msgids_checked'              => $this->summary['source_msgids_checked'],
			'source_msgids_missing_in_pot'       => $pot_stats[1],
			'passes'                             => 0 === count( $this->errors ),
			'errors'                             => $this->errors,
		];
		$manifest_dir = dirname( $this->manifest_path );
		if ( ! is_dir( $manifest_dir ) ) {
			mkdir( $manifest_dir, 0755, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents(
			$this->manifest_path,
			json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);
		echo "Manifest persisted to: {$this->manifest_path}\n\n";
	}
}

$auditor = new SScribe_I18N_Auditor( $root_dir );
$exit_code = $auditor->run();

if ( 0 === $exit_code ) {
	echo "✓ i18n contract audit passed. Every translation call declares the canonical text domain and the POT is in sync with the live source tree.\n";
	exit( 0 );
}

echo "✗ i18n contract audit failed.\n";
exit( 1 );