<?php
/**
 * WordPress admin notice compliance regressions.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Admin_Notice_Compliance_Test extends TestCase {
	public function test_bootstrap_notices_use_modern_wordpress_notice_classes(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/sscribe-export-site-pages.php' );
		$this->assertStringNotContainsString( '<div class="error">', $src );
		$this->assertGreaterThanOrEqual( 4, substr_count( $src, 'notice notice-error' ) );
		$this->assertStringContainsString(
			'<div class="notice notice-error is-dismissible"><p><strong>%1$s</strong> %2$s</p>%3$s</div>',
			$src
		);
	}
}
