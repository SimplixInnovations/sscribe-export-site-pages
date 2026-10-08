<?php
/**
 * Stubs for optional third-party plugins SScribe integrates with.
 *
 * Advanced Custom Fields and WooCommerce are detected at runtime with
 * function_exists(); these signatures only serve static analysis.
 *
 * @package SScribe_Export_Site_Pages
 */

// phpcs:ignoreFile

/**
 * @param int|string|false $post_id
 * @return array<string, array<string, mixed>>|false
 */
function get_field_objects( $post_id = false, bool $format_value = true, bool $load_value = true ) {
	return false;
}

class WC_Product {
	public function get_sku( string $context = 'view' ): string {
		return '';
	}

	public function get_type(): string {
		return '';
	}

	public function get_regular_price( string $context = 'view' ): string {
		return '';
	}

	public function get_sale_price( string $context = 'view' ): string {
		return '';
	}

	public function get_price( string $context = 'view' ): string {
		return '';
	}

	public function get_stock_status( string $context = 'view' ): string {
		return '';
	}

	/**
	 * @return int|null
	 */
	public function get_stock_quantity( string $context = 'view' ) {
		return null;
	}

	public function get_weight( string $context = 'view' ): string {
		return '';
	}

	/**
	 * @return array<string, string>
	 */
	public function get_dimensions( bool $formatted = true ): array {
		return array();
	}

	public function get_short_description( string $context = 'view' ): string {
		return '';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_attributes( string $context = 'view' ): array {
		return array();
	}
}

class WC_Product_Attribute {
	public function get_name(): string {
		return '';
	}

	public function is_taxonomy(): bool {
		return false;
	}

	/**
	 * @return array<int, object>
	 */
	public function get_terms(): array {
		return array();
	}

	/**
	 * @return array<int, string>
	 */
	public function get_options(): array {
		return array();
	}
}

/**
 * @param mixed $the_product
 * @return WC_Product|false|null
 */
function wc_get_product( $the_product = false, array $deprecated = array() ) {
	return false;
}

function get_woocommerce_currency(): string {
	return '';
}

/**
 * @param array<string, string> $dimensions
 */
function wc_format_dimensions( array $dimensions ): string {
	return '';
}

function wc_attribute_label( string $name, $product = '' ): string {
	return '';
}
