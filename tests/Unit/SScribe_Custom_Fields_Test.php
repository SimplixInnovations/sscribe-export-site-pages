<?php
declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Custom_Fields_Test extends TestCase {

	public function test_mode_normalization_falls_back_to_auto(): void {
		$this::assertSame( 'auto', \SScribe_Custom_Fields::normalize_mode( 'bogus' ) );
		$this::assertSame( 'none', \SScribe_Custom_Fields::normalize_mode( ' NONE ' ) );
		$this::assertSame( 'all', \SScribe_Custom_Fields::normalize_mode( 'all' ) );
		$this::assertSame( 'auto', \SScribe_Custom_Fields::normalize_mode( array() ) );
	}

	public function test_none_mode_collects_nothing_even_with_readers(): void {
		$fields = \SScribe_Custom_Fields::collect( 7, 'none', fn() => array( 'a' => array( 'label' => 'A', 'value' => 'x' ) ) );

		$this::assertSame( array(), $fields );
	}

	public function test_acf_objects_are_normalized_by_type(): void {
		$objects = array(
			'subtitle'  => array( 'name' => 'subtitle', 'label' => 'Subtitle', 'type' => 'text', 'value' => '  Hello <b>world</b>  ' ),
			'featured'  => array( 'name' => 'featured', 'label' => 'Featured', 'type' => 'true_false', 'value' => true ),
			'hidden'    => array( 'name' => 'hidden', 'label' => 'Hidden', 'type' => 'true_false', 'value' => false ),
			'hero'      => array( 'name' => 'hero', 'label' => 'Hero image', 'type' => 'image', 'value' => array( 'url' => 'https://example.org/hero.jpg', 'title' => 'Hero' ) ),
			'link'      => array( 'name' => 'link', 'label' => 'Link', 'type' => 'link', 'value' => array( 'url' => 'https://example.org/', 'title' => 'Site' ) ),
			'tags'      => array( 'name' => 'tags', 'label' => 'Tags', 'type' => 'checkbox', 'value' => array( 'one', 'two' ) ),
			'related'   => array( 'name' => 'related', 'label' => 'Related', 'type' => 'relationship', 'value' => array( (object) array( 'post_title' => 'Other page' ) ) ),
			'owner'     => array( 'name' => 'owner', 'label' => 'Owner', 'type' => 'user', 'value' => array( 'display_name' => 'Pat', 'user_email' => 'pat@example.org' ) ),
			'rows'      => array(
				'name'  => 'rows',
				'label' => 'Rows',
				'type'  => 'repeater',
				'value' => array(
					array( 'name' => 'Alpha', 'qty' => 2 ),
					array( 'name' => 'Beta', 'qty' => 0 ),
				),
			),
			'empty'     => array( 'name' => 'empty', 'label' => 'Empty', 'type' => 'text', 'value' => '' ),
			'bad shape' => 'not an object',
		);

		$fields = \SScribe_Custom_Fields::collect( 7, 'auto', fn() => $objects, fn() => array(), fn() => array() );
		$by_key = array_column( $fields, null, 'key' );

		$this::assertSame( 'Hello world', $by_key['subtitle']['value'] );
		$this::assertSame( 'Yes', $by_key['featured']['value'] );
		$this::assertSame( 'No', $by_key['hidden']['value'] );
		$this::assertSame( 'Hero (https://example.org/hero.jpg)', $by_key['hero']['value'] );
		$this::assertSame( 'Site (https://example.org/)', $by_key['link']['value'] );
		$this::assertSame( array( 'one', 'two' ), $by_key['tags']['value'] );
		$this::assertSame( array( 'Other page' ), $by_key['related']['value'] );
		$this::assertSame( 'Pat', $by_key['owner']['value'] );
		$this::assertSame( array( 'Name: Alpha, Qty: 2', 'Name: Beta, Qty: 0' ), $by_key['rows']['value'] );
		$this::assertArrayNotHasKey( 'empty', $by_key );
		$this::assertSame( 'acf', $by_key['subtitle']['source'] );
		$this::assertSame( 'image', $by_key['hero']['type'] );
	}

	public function test_woocommerce_product_data_becomes_labelled_fields(): void {
		$product = array(
			'sku'               => 'SKU-1',
			'type'              => 'simple',
			'regular_price'     => '20',
			'sale_price'        => '',
			'price'             => '20',
			'currency'          => 'USD',
			'stock_status'      => 'instock',
			'stock_quantity'    => 5,
			'weight'            => '',
			'dimensions'        => '10 x 20 x 30 cm',
			'short_description' => '<p>Short</p>',
			'categories'        => array( 'Shoes' ),
			'tags'              => array(),
			'attributes'        => array( 'Color' => array( 'Red', 'Blue' ), 'Size' => array() ),
		);

		$fields = \SScribe_Custom_Fields::collect( 9, 'auto', fn() => array(), fn() => $product, fn() => array() );
		$by_key = array_column( $fields, null, 'key' );

		$this::assertSame( 'SKU-1', $by_key['wc_sku']['value'] );
		$this::assertSame( '20 USD', $by_key['wc_regular_price']['value'] );
		$this::assertArrayNotHasKey( 'wc_sale_price', $by_key );
		$this::assertSame( '5', $by_key['wc_stock_quantity']['value'] );
		$this::assertSame( 'Short', $by_key['wc_short_description']['value'] );
		$this::assertSame( array( 'Shoes' ), $by_key['wc_categories']['value'] );
		$this::assertArrayNotHasKey( 'wc_tags', $by_key );
		$this::assertSame( array( 'Red', 'Blue' ), $by_key['wc_attribute_color']['value'] );
		$this::assertArrayNotHasKey( 'wc_attribute_size', $by_key );
		$this::assertSame( 'woocommerce', $by_key['wc_sku']['source'] );
	}

	public function test_all_mode_adds_public_meta_and_skips_private_and_acf_keys(): void {
		$meta = array(
			'_edit_lock'   => array( '1' ),
			'event_date'   => array( '2026-10-09' ),
			'subtitle'     => array( 'duplicate of acf' ),
			'speakers'     => array( 'Ann', 'Bob' ),
			'serialized'   => array( serialize( array( 'city' => 'Dubai', 'hall' => 'A' ) ) ),
			'blank'        => array( '' ),
		);
		$acf  = array( 'subtitle' => array( 'name' => 'subtitle', 'label' => 'Subtitle', 'type' => 'text', 'value' => 'From ACF' ) );

		$auto = \SScribe_Custom_Fields::collect( 3, 'auto', fn() => $acf, fn() => array(), fn() => $meta );
		$all  = \SScribe_Custom_Fields::collect( 3, 'all', fn() => $acf, fn() => array(), fn() => $meta );

		$this::assertCount( 1, $auto, 'auto mode must not read plain meta' );

		$by_key = array_column( $all, null, 'key' );
		$this::assertArrayNotHasKey( '_edit_lock', $by_key );
		$this::assertArrayNotHasKey( 'edit_lock', $by_key );
		$this::assertArrayNotHasKey( 'blank', $by_key );
		$this::assertSame( 'From ACF', $by_key['subtitle']['value'], 'ACF wins over the raw meta copy' );
		$this::assertSame( 'Event date', $by_key['event_date']['label'] );
		$this::assertSame( '2026-10-09', $by_key['event_date']['value'] );
		$this::assertSame( array( 'Ann', 'Bob' ), $by_key['speakers']['value'] );
		$this::assertSame( array( 'City: Dubai', 'Hall: A' ), $by_key['serialized']['value'] );
		$this::assertSame( 'meta', $by_key['speakers']['source'] );
	}

	public function test_long_values_and_deep_nesting_are_bounded(): void {
		$deep = array( 'a' => array( 'b' => array( 'c' => array( 'd' => array( 'e' => 'too deep' ) ) ) ) );
		$long = str_repeat( 'x', 5000 );

		$fields = \SScribe_Custom_Fields::collect(
			1,
			'auto',
			fn() => array(
				'deep' => array( 'name' => 'deep', 'label' => 'Deep', 'type' => 'group', 'value' => $deep ),
				'long' => array( 'name' => 'long', 'label' => 'Long', 'type' => 'text', 'value' => $long ),
			),
			fn() => array(),
			fn() => array()
		);
		$by_key = array_column( $fields, null, 'key' );

		$this::assertSame( 2000, strlen( $by_key['long']['value'] ) );
		$this::assertArrayNotHasKey( 'deep', $by_key, 'Values nested past the depth limit are dropped rather than expanded.' );
	}

	public function test_rows_flatten_lists_and_drop_empty_values(): void {
		$rows = \SScribe_Custom_Fields::rows(
			array(
				array( 'key' => 'a', 'label' => 'A', 'type' => 'text', 'value' => 'one', 'source' => 'meta' ),
				array( 'key' => 'b', 'label' => 'B', 'type' => 'checkbox', 'value' => array( 'x', 'y' ), 'source' => 'acf' ),
				array( 'key' => 'c', 'label' => 'C', 'type' => 'text', 'value' => '   ', 'source' => 'meta' ),
				'garbage',
			)
		);

		$this::assertSame(
			array(
				array( 'label' => 'A', 'value' => 'one' ),
				array( 'label' => 'B', 'value' => "x\ny" ),
			),
			$rows
		);
	}

	public function test_filter_can_replace_fields_but_invalid_entries_are_dropped(): void {
		$callback = static fn( array $fields ): array => array_merge(
			$fields,
			array(
				array( 'key' => 'extra', 'label' => 'Extra', 'type' => 'text', 'value' => 'added', 'source' => 'filter' ),
				array( 'label' => 'missing key' ),
			)
		);
		add_filter( 'sscribe_page_fields', $callback );
		try {
			$fields = \SScribe_Custom_Fields::collect( 5, 'auto', fn() => array(), fn() => array(), fn() => array() );
		} finally {
			remove_filter( 'sscribe_page_fields', $callback );
		}

		$this::assertCount( 1, $fields );
		$this::assertSame( 'extra', $fields[0]['key'] );
	}
}
