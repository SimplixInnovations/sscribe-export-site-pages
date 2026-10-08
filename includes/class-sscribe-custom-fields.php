<?php
/**
 * Custom field collection for exports.
 *
 * Reads Advanced Custom Fields groups, WooCommerce product data and plain
 * post meta into one normalized list that every exporter can render.
 *
 * @package SScribe_Export_Site_Pages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes structured fields attached to a post.
 */
final class SScribe_Custom_Fields {

	public const MODE_NONE = 'none';
	public const MODE_AUTO = 'auto';
	public const MODE_ALL  = 'all';

	public const SOURCE_ACF         = 'acf';
	public const SOURCE_WOOCOMMERCE = 'woocommerce';
	public const SOURCE_META        = 'meta';

	private const MAX_FIELDS       = 200;
	private const MAX_VALUE_LENGTH = 2000;
	private const MAX_LIST_ITEMS   = 100;
	private const MAX_DEPTH        = 4;

	/**
	 * Reduce any user-supplied mode to a known one.
	 *
	 * @param mixed $mode Raw mode.
	 * @return string
	 */
	public static function normalize_mode( $mode ): string {
		$mode = is_scalar( $mode ) ? strtolower( trim( (string) $mode ) ) : '';
		return in_array( $mode, array( self::MODE_NONE, self::MODE_AUTO, self::MODE_ALL ), true ) ? $mode : self::MODE_AUTO;
	}

	/**
	 * Human labels for the admin select and the CLI help.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			self::MODE_AUTO => __( 'ACF and WooCommerce fields when those plugins are active', 'sscribe-export-site-pages' ),
			self::MODE_ALL  => __( 'ACF, WooCommerce and all public custom fields', 'sscribe-export-site-pages' ),
			self::MODE_NONE => __( 'No custom fields', 'sscribe-export-site-pages' ),
		);
	}

	/**
	 * Whether Advanced Custom Fields can be read.
	 *
	 * @return bool
	 */
	public static function is_acf_active(): bool {
		return function_exists( 'get_field_objects' );
	}

	/**
	 * Whether WooCommerce product data can be read.
	 *
	 * @return bool
	 */
	public static function is_woocommerce_active(): bool {
		return function_exists( 'wc_get_product' );
	}

	/**
	 * Collect the fields of one post.
	 *
	 * Readers are injectable so the normalization can be tested without the
	 * plugins installed. Each reader receives the post id.
	 *
	 * @param int           $post_id     Post id.
	 * @param string        $mode        One of the MODE_* constants.
	 * @param callable|null $acf_reader  Returns ACF field objects, or null to detect.
	 * @param callable|null $woo_reader  Returns WooCommerce label/value pairs, or null to detect.
	 * @param callable|null $meta_reader Returns raw post meta, or null to use get_post_meta().
	 * @return list<array{key: string, label: string, type: string, value: string|list<string>, source: string}>
	 */
	public static function collect( int $post_id, string $mode, ?callable $acf_reader = null, ?callable $woo_reader = null, ?callable $meta_reader = null ): array {
		$mode = self::normalize_mode( $mode );
		if ( self::MODE_NONE === $mode || $post_id <= 0 ) {
			return array();
		}

		$fields = array();
		$seen   = array();

		if ( null !== $acf_reader || self::is_acf_active() ) {
			$objects = null !== $acf_reader ? $acf_reader( $post_id ) : get_field_objects( $post_id );
			foreach ( self::from_acf( is_array( $objects ) ? $objects : array() ) as $field ) {
				$fields[]               = $field;
				$seen[ $field['key'] ] = true;
			}
		}

		if ( null !== $woo_reader || self::is_woocommerce_active() ) {
			$product = null !== $woo_reader ? $woo_reader( $post_id ) : self::read_product( $post_id );
			foreach ( self::from_woocommerce( is_array( $product ) ? $product : array() ) as $field ) {
				$fields[] = $field;
			}
		}

		if ( self::MODE_ALL === $mode ) {
			$meta = null !== $meta_reader ? $meta_reader( $post_id ) : get_post_meta( $post_id );
			foreach ( self::from_meta( is_array( $meta ) ? $meta : array(), $seen ) as $field ) {
				$fields[] = $field;
			}
		}

		$fields = array_slice( $fields, 0, self::MAX_FIELDS );

		/**
		 * Filter the normalized custom fields of a post before export.
		 *
		 * @param array  $fields  Normalized fields.
		 * @param int    $post_id Post id.
		 * @param string $mode    Collection mode.
		 */
		$filtered = apply_filters( 'sscribe_page_fields', $fields, $post_id, $mode );

		return is_array( $filtered ) ? array_values( array_filter( $filtered, array( __CLASS__, 'is_field' ) ) ) : $fields;
	}

	/**
	 * Flatten fields into label/value text rows for table renderers.
	 *
	 * @param array $fields Normalized fields.
	 * @return list<array{label: string, value: string}>
	 */
	public static function rows( array $fields ): array {
		$rows = array();
		foreach ( $fields as $field ) {
			if ( ! self::is_field( $field ) ) {
				continue;
			}
			$value = $field['value'];
			$text  = wp_strip_all_tags( is_array( $value ) ? implode( "\n", $value ) : (string) $value );
			if ( '' === trim( $text ) ) {
				continue;
			}
			$rows[] = array(
				'label' => (string) $field['label'],
				'value' => $text,
			);
		}
		return $rows;
	}

	/**
	 * Whether a value has the normalized field shape.
	 *
	 * @param mixed $field Candidate.
	 * @return bool
	 */
	public static function is_field( $field ): bool {
		return is_array( $field )
			&& isset( $field['key'], $field['label'], $field['type'], $field['source'] )
			&& is_string( $field['key'] ) && '' !== $field['key']
			&& is_string( $field['label'] )
			&& array_key_exists( 'value', $field )
			&& ( is_string( $field['value'] ) || is_array( $field['value'] ) );
	}

	/**
	 * Turn ACF field objects into normalized fields.
	 *
	 * @param array $objects Output of get_field_objects().
	 * @return list<array{key: string, label: string, type: string, value: string|list<string>, source: string}>
	 */
	private static function from_acf( array $objects ): array {
		$fields = array();
		foreach ( $objects as $name => $object ) {
			if ( ! is_array( $object ) ) {
				continue;
			}
			$key   = self::clean_key( (string) ( $object['name'] ?? $name ) );
			$type  = self::clean_key( (string) ( $object['type'] ?? 'text' ) );
			$label = self::clean_text( (string) ( $object['label'] ?? $key ) );
			if ( '' === $key ) {
				continue;
			}
			$value = self::normalize_value( $object['value'] ?? '', $type, $object );
			if ( self::is_empty_value( $value ) ) {
				continue;
			}
			$fields[] = array(
				'key'    => $key,
				'label'  => '' !== $label ? $label : $key,
				'type'   => '' !== $type ? $type : 'text',
				'value'  => $value,
				'source' => self::SOURCE_ACF,
			);
		}
		return $fields;
	}

	/**
	 * Read the product data WooCommerce exposes for a post.
	 *
	 * @param int $post_id Post id.
	 * @return array<string, mixed> Label-keyed values, empty when not a product.
	 */
	private static function read_product( int $post_id ): array {
		$product = wc_get_product( $post_id );
		if ( ! $product instanceof WC_Product ) {
			return array();
		}
		$data = array(
			'sku'               => (string) $product->get_sku(),
			'type'              => (string) $product->get_type(),
			'regular_price'     => (string) $product->get_regular_price(),
			'sale_price'        => (string) $product->get_sale_price(),
			'price'             => (string) $product->get_price(),
			'currency'          => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
			'stock_status'      => (string) $product->get_stock_status(),
			'stock_quantity'    => $product->get_stock_quantity(),
			'weight'            => (string) $product->get_weight(),
			'dimensions'        => function_exists( 'wc_format_dimensions' ) ? (string) wc_format_dimensions( $product->get_dimensions( false ) ) : '',
			'short_description' => (string) $product->get_short_description(),
			'categories'        => self::term_names( $post_id, 'product_cat' ),
			'tags'              => self::term_names( $post_id, 'product_tag' ),
			'attributes'        => array(),
		);
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}
			$name    = function_exists( 'wc_attribute_label' ) ? (string) wc_attribute_label( $attribute->get_name() ) : (string) $attribute->get_name();
			$options = $attribute->is_taxonomy()
				? array_map( static fn( $term ): string => isset( $term->name ) && is_string( $term->name ) ? $term->name : '', $attribute->get_terms() )
				: array_map( 'strval', $attribute->get_options() );
			$data['attributes'][ $name ] = array_values( array_filter( $options ) );
		}
		return $data;
	}

	/**
	 * Term names of a post in one taxonomy.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $taxonomy Taxonomy.
	 * @return list<string>
	 */
	private static function term_names( int $post_id, string $taxonomy ): array {
		if ( ! function_exists( 'wp_get_post_terms' ) ) {
			return array();
		}
		$terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
		return is_array( $terms ) ? array_values( array_map( 'strval', $terms ) ) : array();
	}

	/**
	 * Turn WooCommerce product data into normalized fields.
	 *
	 * @param array<string, mixed> $product Product data from read_product() or a reader.
	 * @return list<array{key: string, label: string, type: string, value: string|list<string>, source: string}>
	 */
	private static function from_woocommerce( array $product ): array {
		if ( array() === $product ) {
			return array();
		}
		$currency = self::clean_text( (string) ( $product['currency'] ?? '' ) );
		$money    = static function ( $amount ) use ( $currency ): string {
			$amount = is_scalar( $amount ) ? trim( (string) $amount ) : '';
			return '' === $amount ? '' : trim( $amount . ' ' . $currency );
		};
		$labels = array(
			'sku'               => array( __( 'SKU', 'sscribe-export-site-pages' ), 'text', $product['sku'] ?? '' ),
			'type'              => array( __( 'Product type', 'sscribe-export-site-pages' ), 'text', $product['type'] ?? '' ),
			'regular_price'     => array( __( 'Regular price', 'sscribe-export-site-pages' ), 'price', $money( $product['regular_price'] ?? '' ) ),
			'sale_price'        => array( __( 'Sale price', 'sscribe-export-site-pages' ), 'price', $money( $product['sale_price'] ?? '' ) ),
			'price'             => array( __( 'Price', 'sscribe-export-site-pages' ), 'price', $money( $product['price'] ?? '' ) ),
			'stock_status'      => array( __( 'Stock status', 'sscribe-export-site-pages' ), 'text', $product['stock_status'] ?? '' ),
			'stock_quantity'    => array( __( 'Stock quantity', 'sscribe-export-site-pages' ), 'number', $product['stock_quantity'] ?? '' ),
			'weight'            => array( __( 'Weight', 'sscribe-export-site-pages' ), 'text', $product['weight'] ?? '' ),
			'dimensions'        => array( __( 'Dimensions', 'sscribe-export-site-pages' ), 'text', $product['dimensions'] ?? '' ),
			'short_description' => array( __( 'Short description', 'sscribe-export-site-pages' ), 'wysiwyg', $product['short_description'] ?? '' ),
			'categories'        => array( __( 'Categories', 'sscribe-export-site-pages' ), 'taxonomy', $product['categories'] ?? array() ),
			'tags'              => array( __( 'Tags', 'sscribe-export-site-pages' ), 'taxonomy', $product['tags'] ?? array() ),
		);
		$fields = array();
		foreach ( $labels as $key => $spec ) {
			$value = self::normalize_value( $spec[2], $spec[1] );
			if ( self::is_empty_value( $value ) ) {
				continue;
			}
			$fields[] = array(
				'key'    => 'wc_' . $key,
				'label'  => $spec[0],
				'type'   => $spec[1],
				'value'  => $value,
				'source' => self::SOURCE_WOOCOMMERCE,
			);
		}
		foreach ( (array) ( $product['attributes'] ?? array() ) as $name => $options ) {
			$value = self::normalize_value( $options, 'select' );
			$key   = self::clean_key( 'wc_attribute_' . (string) $name );
			if ( '' === $key || self::is_empty_value( $value ) ) {
				continue;
			}
			$fields[] = array(
				'key'    => $key,
				'label'  => self::clean_text( (string) $name ),
				'type'   => 'select',
				'value'  => $value,
				'source' => self::SOURCE_WOOCOMMERCE,
			);
		}
		return $fields;
	}

	/**
	 * Turn public post meta into normalized fields.
	 *
	 * Keys starting with an underscore are private by WordPress convention
	 * and never exported. Keys already reported by ACF are skipped.
	 *
	 * @param array               $meta Raw get_post_meta() map.
	 * @param array<string, bool> $seen Keys already collected.
	 * @return list<array{key: string, label: string, type: string, value: string|list<string>, source: string}>
	 */
	private static function from_meta( array $meta, array $seen ): array {
		$fields = array();
		foreach ( $meta as $key => $values ) {
			$key = is_string( $key ) ? $key : '';
			if ( '' === $key || str_starts_with( $key, '_' ) || isset( $seen[ $key ] ) ) {
				continue;
			}
			$clean_key = self::clean_key( $key );
			if ( '' === $clean_key ) {
				continue;
			}
			$values = is_array( $values ) ? $values : array( $values );
			$items  = array();
			foreach ( $values as $raw ) {
				$raw = is_string( $raw ) && function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $raw ) : $raw;
				$one = self::normalize_value( $raw, 'meta' );
				if ( self::is_empty_value( $one ) ) {
					continue;
				}
				foreach ( (array) $one as $piece ) {
					$items[] = (string) $piece;
				}
			}
			if ( array() === $items ) {
				continue;
			}
			$fields[] = array(
				'key'    => $clean_key,
				'label'  => self::label_from_key( $key ),
				'type'   => 'meta',
				'value'  => 1 === count( $items ) ? $items[0] : array_slice( $items, 0, self::MAX_LIST_ITEMS ),
				'source' => self::SOURCE_META,
			);
		}
		return $fields;
	}

	/**
	 * Reduce any field value to a string or a list of strings.
	 *
	 * @param mixed  $value  Raw value.
	 * @param string $type   Field type hint.
	 * @param array  $object Field definition when known.
	 * @param int    $depth  Current nesting depth.
	 * @return string|list<string>
	 */
	public static function normalize_value( $value, string $type = 'text', array $object = array(), int $depth = 0 ) {
		if ( null === $value || '' === $value ) {
			return '';
		}
		if ( is_bool( $value ) || 'true_false' === $type ) {
			return (bool) $value ? __( 'Yes', 'sscribe-export-site-pages' ) : __( 'No', 'sscribe-export-site-pages' );
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			if ( in_array( $type, array( 'image', 'file', 'gallery' ), true ) && function_exists( 'wp_get_attachment_url' ) ) {
				$url = wp_get_attachment_url( (int) $value );
				return is_string( $url ) ? self::clean_text( $url ) : (string) $value;
			}
			if ( in_array( $type, array( 'post_object', 'relationship', 'page_link' ), true ) && function_exists( 'get_the_title' ) ) {
				return self::clean_text( (string) get_the_title( (int) $value ) );
			}
			return (string) $value;
		}
		if ( is_string( $value ) ) {
			$text = in_array( $type, array( 'wysiwyg', 'meta', 'textarea' ), true ) ? wp_strip_all_tags( $value, true ) : $value;
			return self::clean_text( $text );
		}
		if ( $depth >= self::MAX_DEPTH ) {
			return '';
		}
		if ( is_object( $value ) ) {
			return self::normalize_object( $value );
		}
		if ( ! is_array( $value ) ) {
			return '';
		}
		if ( isset( $value['url'] ) && is_string( $value['url'] ) ) {
			$title = isset( $value['title'] ) && is_string( $value['title'] ) ? self::clean_text( $value['title'] ) : '';
			$url   = self::clean_text( $value['url'] );
			return '' !== $title && $title !== $url ? $title . ' (' . $url . ')' : $url;
		}
		if ( isset( $value['display_name'] ) && is_string( $value['display_name'] ) ) {
			return self::clean_text( $value['display_name'] );
		}
		if ( isset( $value['address'] ) && is_string( $value['address'] ) ) {
			return self::clean_text( $value['address'] );
		}
		if ( array_is_list( $value ) ) {
			$items = array();
			foreach ( array_slice( $value, 0, self::MAX_LIST_ITEMS ) as $item ) {
				$one = self::normalize_value( $item, self::item_type( $type ), $object, $depth + 1 );
				$one = is_array( $one ) ? implode( ', ', array_filter( $one, static fn( $piece ): bool => '' !== $piece ) ) : $one;
				if ( '' !== $one ) {
					$items[] = $one;
				}
			}
			return $items;
		}
		$lines = array();
		foreach ( array_slice( $value, 0, self::MAX_LIST_ITEMS, true ) as $sub_key => $sub_value ) {
			$one = self::normalize_value( $sub_value, 'text', array(), $depth + 1 );
			$one = is_array( $one ) ? implode( ', ', $one ) : $one;
			if ( '' === $one ) {
				continue;
			}
			$lines[] = self::label_from_key( (string) $sub_key ) . ': ' . $one;
		}
		return $lines;
	}

	/**
	 * Represent WordPress objects by their human name.
	 *
	 * @param object $value Object value.
	 * @return string
	 */
	private static function normalize_object( object $value ): string {
		if ( isset( $value->post_title ) ) {
			return self::clean_text( (string) $value->post_title );
		}
		if ( isset( $value->name ) && is_string( $value->name ) ) {
			return self::clean_text( $value->name );
		}
		if ( isset( $value->display_name ) && is_string( $value->display_name ) ) {
			return self::clean_text( $value->display_name );
		}
		if ( method_exists( $value, '__toString' ) ) {
			return self::clean_text( (string) $value );
		}
		return '';
	}

	/**
	 * Element type for list items of a typed field.
	 *
	 * @param string $type Parent field type.
	 * @return string
	 */
	private static function item_type( string $type ): string {
		return match ( $type ) {
			'gallery' => 'image',
			'relationship', 'post_object', 'page_link' => 'post_object',
			default => 'text',
		};
	}

	/**
	 * Whether a normalized value carries nothing to show.
	 *
	 * @param string|array $value Normalized value.
	 * @return bool
	 */
	private static function is_empty_value( $value ): bool {
		return is_array( $value ) ? array() === $value : '' === trim( (string) $value );
	}

	/**
	 * Single-line, length-capped text.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function clean_text( string $text ): string {
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $text, true ) : strip_tags( $text );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? $text );
		return SScribe_Helpers::mb_substr( $text, 0, self::MAX_VALUE_LENGTH );
	}

	/**
	 * Safe identifier for a field key.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	private static function clean_key( string $key ): string {
		$key = strtolower( trim( $key ) );
		$key = preg_replace( '/[^a-z0-9_\-]+/', '_', $key ) ?? '';
		return trim( substr( $key, 0, 100 ), '_' );
	}

	/**
	 * Readable label from a meta key such as "event_start_date".
	 *
	 * @param string $key Meta key.
	 * @return string
	 */
	private static function label_from_key( string $key ): string {
		$label = trim( str_replace( array( '_', '-' ), ' ', $key ) );
		return '' === $label ? $key : ucfirst( $label );
	}
}
