<?php
/**
 * PHPStan-only API stub for the Strauss-prefixed TCPDF runtime class.
 *
 * The production class is generated from tecnickcom/tcpdf during the
 * Composer/Strauss build and is intentionally not committed to the source
 * tree. PHPStan analyzes this declaration only; it is excluded from the
 * WordPress.org release package.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! class_exists( 'SScribeVendor_TCPDF', false ) ) {
	class SScribeVendor_TCPDF {
		public function __construct(
			string $orientation = 'P',
			string $unit = 'mm',
			string|array $format = 'A4',
			bool $unicode = true,
			string $encoding = 'UTF-8',
			bool $diskcache = false,
			bool $pdfa = false
		) {}

		public function setTitle( string $title ): void {}
		public function setAuthor( string $author ): void {}
		public function setCreator( string $creator ): void {}
		public function setSubject( string $subject ): void {}
		public function setKeywords( string $keywords ): void {}
		public function setPrintHeader( bool $value ): void {}
		public function setPrintFooter( bool $value ): void {}
		public function SetMargins( float $left, float $top, float $right = -1, bool $keepmargins = false ): void {}
		public function SetHeaderMargin( float $margin ): void {}
		public function SetFooterMargin( float $margin ): void {}
		public function SetAutoPageBreak( bool $auto, float $margin = 0 ): void {}
		public function setRTL( bool $enable, bool $resetx = true ): void {}
		public function SetFont(
			string $family,
			string $style = '',
			float $size = 0,
			string $fontfile = '',
			bool|string $subset = 'default'
		): string {}

		public function AddPage(
			string $orientation = '',
			string|array $format = '',
			bool $keepmargins = false,
			bool $tocpage = false
		): void {}

		public function writeHTML(
			string $html,
			bool $ln = true,
			bool $fill = false,
			bool $reseth = false,
			bool $cell = false,
			string $align = ''
		): void {}

		public function Output( string $name = 'doc.pdf', string $dest = 'I' ): string {}
	}
}
