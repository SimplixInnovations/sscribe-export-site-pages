<?php
/**
 * Mock WordPress upgrade functions for testing.
 *
 * @package SScribe_Export_Site_Pages
 */

/**
 * Mock dbDelta function.
 *
 * @param string $sql SQL query.
 * @param bool   $execute Whether to execute.
 * @return array
 */
function dbDelta( string $sql, bool $execute = true ): array {
	return array();
}
