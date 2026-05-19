<?php

$files = array(
	__DIR__ . '/../vendor/phpoffice/phpword/src/PhpWord/Style.php',
	__DIR__ . '/../vendor-prefixed/phpoffice/phpword/src/PhpWord/Style.php',
);

$search  = <<<'PHP'
    public static function getStyle($styleName)
    {
        if (isset(self::$styles[$styleName])) {
            return self::$styles[$styleName];
        }

        return null;
    }
PHP;

$replace = <<<'PHP'
    public static function getStyle($styleName)
    {
        if (null === $styleName) {
            return null;
        }
        if (isset(self::$styles[$styleName])) {
            return self::$styles[$styleName];
        }

        return null;
    }
PHP;

$fixed = 0;
foreach ( $files as $path ) {
	if ( ! file_exists( $path ) ) {
		echo "SKIP (not found): {$path}\n";
		continue;
	}

	$content = file_get_contents( $path );
	if ( false === $content ) {
		echo "ERROR (read): {$path}\n";
		continue;
	}

	if ( str_contains( $content, 'null === $styleName' ) ) {
		echo "OK (already patched): {$path}\n";
		++$fixed;
		continue;
	}

	$updated = str_replace( $search, $replace, $content );
	if ( $updated === $content ) {
		echo "ERROR (pattern not found): {$path}\n";
		continue;
	}

	if ( false === file_put_contents( $path, $updated ) ) {
		echo "ERROR (write): {$path}\n";
		continue;
	}

	echo "PATCHED: {$path}\n";
	++$fixed;
}

echo "\nDone. {$fixed} file(s) patched.\n";
exit( 0 );
