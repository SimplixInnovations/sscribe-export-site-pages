<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
$sscribe_prefixed_aliases = array(
	'\SScribeVendor\PhpOffice\PhpWord\PhpWord' => '\PhpOffice\PhpWord\PhpWord',
	'\SScribeVendor\PhpOffice\PhpWord\IOFactory' => '\PhpOffice\PhpWord\IOFactory',
	'\SScribeVendor\PhpOffice\PhpWord\Settings' => '\PhpOffice\PhpWord\Settings',
	'\SScribeVendor\PhpOffice\PhpWord\Style\Font' => '\PhpOffice\PhpWord\Style\Font',
	'\SScribeVendor\PhpOffice\PhpWord\Style\TOC' => '\PhpOffice\PhpWord\Style\TOC',
	'\SScribeVendor\PhpOffice\PhpWord\Style\ListItem' => '\PhpOffice\PhpWord\Style\ListItem',
	'\SScribeVendor\PhpOffice\PhpWord\SimpleType\Jc' => '\PhpOffice\PhpWord\SimpleType\Jc',
	'\SScribeVendor\PhpOffice\PhpWord\SimpleType\TblWidth' => '\PhpOffice\PhpWord\SimpleType\TblWidth',
	'\SScribeVendor\PhpOffice\PhpWord\Shared\Converter' => '\PhpOffice\PhpWord\Shared\Converter',
	'\SScribeVendor\PhpOffice\PhpWord\Element\Section' => '\PhpOffice\PhpWord\Element\Section',
	'\SScribeVendor\PhpOffice\PhpWord\Element\TextRun' => '\PhpOffice\PhpWord\Element\TextRun',
	'\SScribeVendor\Mpdf\Mpdf' => '\Mpdf\Mpdf',
);
foreach ( $sscribe_prefixed_aliases as $sscribe_source => $sscribe_alias ) {
	if ( class_exists( $sscribe_source ) && ! class_exists( $sscribe_alias ) ) {
		class_alias( $sscribe_source, $sscribe_alias );
	}
}
