<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface SScribe_Exporter_Interface {

	public function export( array $page_data, string $output_dir, int $index = 0, int $total = 0 ): SScribe_Result;

	public function get_extension(): string;

	public function get_mime_type(): string;
}
