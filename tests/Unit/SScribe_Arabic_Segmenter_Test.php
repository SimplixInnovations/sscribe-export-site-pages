<?php
/**
 * SScribe Arabic Segmenter Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Arabic_Segmenter_Test extends TestCase {

	public function test_count_words_english_default(): void {
		$this->assertEquals( 4, \SScribe_Arabic_Segmenter::count_words( 'Hello world test here' ) );
	}

	public function test_count_words_english_explicit(): void {
		$this->assertEquals( 3, \SScribe_Arabic_Segmenter::count_words( 'One two three', 'en' ) );
	}

	public function test_count_words_arabic(): void {
		$text = 'مرحبا بالعالم';
		$this->assertEquals( 2, \SScribe_Arabic_Segmenter::count_words( $text, 'ar' ) );
	}

	public function test_count_words_persian(): void {
		$text = 'سلام دنیا';
		$this->assertEquals( 2, \SScribe_Arabic_Segmenter::count_words( $text, 'fa' ) );
	}

	public function test_count_words_urdu(): void {
		$text = 'ہیلو دنیا';
		$this->assertEquals( 2, \SScribe_Arabic_Segmenter::count_words( $text, 'ur' ) );
	}

	public function test_count_words_empty(): void {
		$this->assertEquals( 0, \SScribe_Arabic_Segmenter::count_words( '' ) );
	}

	public function test_count_words_only_tags(): void {
		$this->assertEquals( 0, \SScribe_Arabic_Segmenter::count_words( '<p></p>' ) );
	}

	public function test_count_words_strips_html(): void {
		$count = \SScribe_Arabic_Segmenter::count_words( '<p>Hello <b>world</b></p>' );
		$this->assertEquals( 2, $count );
	}

	public function test_count_words_arabic_strips_diacritics(): void {
		$with_tashkeel = 'مَرْحَباً بِالْعالَمِ';
		$this->assertEquals( 2, \SScribe_Arabic_Segmenter::count_words( $with_tashkeel, 'ar' ) );
	}

	public function test_count_words_with_punctuation(): void {
		$this->assertEquals( 3, \SScribe_Arabic_Segmenter::count_words( 'Hello, world! Test.' ) );
	}

	public function test_get_reading_time_english(): void {
		$text = implode( ' ', array_fill( 0, 400, 'word' ) );
		$time = \SScribe_Arabic_Segmenter::get_reading_time( $text, 'en' );
		$this->assertEquals( 2, $time );
	}

	public function test_get_reading_time_arabic(): void {
		$text = implode( ' ', array_fill( 0, 138, 'كلمة' ) );
		$time = \SScribe_Arabic_Segmenter::get_reading_time( $text, 'ar' );
		$this->assertEquals( 1, $time );
	}

	public function test_get_reading_time_minimum_one(): void {
		$time = \SScribe_Arabic_Segmenter::get_reading_time( 'short', 'en' );
		$this->assertEquals( 1, $time );
	}

	public function test_get_reading_time_large_text(): void {
		$text = implode( ' ', array_fill( 0, 1000, 'word' ) );
		$time = \SScribe_Arabic_Segmenter::get_reading_time( $text, 'en' );
		$this->assertEquals( 5, $time );
	}
}
