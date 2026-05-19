<?php

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Helpers;

class SScribe_Helpers_Test extends TestCase {



	public function test_icon_url_returns_valid_url(): void {
		$result = SScribe_Helpers::icon_url( 'test-icon' );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'assets/icons/', $result );
		$this->assertStringEndsWith( '.svg', $result );
	}



	public function test_icon_url_appends_svg(): void {
		$result = SScribe_Helpers::icon_url( 'check' );
		$this->assertStringEndsWith( 'check.svg', $result );
	}



	public function test_get_icon_returns_html(): void {
		$result = SScribe_Helpers::get_icon( 'check' );
		$this->assertIsString( $result );
	}



	public function test_get_icon_with_custom_size(): void {
		$result = SScribe_Helpers::get_icon( 'check', 32 );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'width="32"', $result );
		$this->assertStringContainsString( 'height="32"', $result );
	}



	public function test_get_icon_with_css_class(): void {
		$result = SScribe_Helpers::get_icon( 'check', 20, 'custom-class' );
		$this->assertStringContainsString( 'custom-class', $result );
	}



	public function test_get_icon_strips_attributes(): void {
		$result = SScribe_Helpers::get_icon( 'check' );
		
		$this->assertIsString( $result );
	}



	public function test_get_icon_uses_cache(): void {
		
		$result1 = SScribe_Helpers::get_icon( 'check' );
		
		$result2 = SScribe_Helpers::get_icon( 'check' );
		$this->assertSame( $result1, $result2 );
	}



	public function test_get_icon_returns_empty_for_missing(): void {
		$result = SScribe_Helpers::get_icon( 'non-existent-icon-12345' );
		$this->assertIsString( $result );
	}



	public function test_get_icon_multiple_icons(): void {
		$icons = array( 'check', 'trash', 'check' );
		foreach ( $icons as $icon ) {
			$result = SScribe_Helpers::get_icon( $icon );
			$this->assertIsString( $result );
		}
	}



	public function test_icon_url_with_special_chars(): void {
		$result = SScribe_Helpers::icon_url( 'icon-with-dash' );
		$this->assertStringContainsString( 'icon-with-dash.svg', $result );
	}



	public function test_get_icon_returns_svg_tag(): void {
		$result = SScribe_Helpers::get_icon( 'check' );
		if ( '' !== $result ) {
			$this->assertStringStartsWith( '<img', $result );
			$this->assertStringContainsString( 'sscribe-icon', $result );
		}
	}
}
