<?php

namespace NovaExpress\Tests\Unit;

use NovaExpress\Helpers\Barcode;
use PHPUnit\Framework\TestCase;

class BarcodeTest extends TestCase {

	public function test_empty_string_returns_empty(): void {
		$this->assertSame( '', Barcode::code128_svg( '' ) );
		$this->assertSame( '', Barcode::code128_svg( '   ' ) );
	}

	public function test_numeric_code_generates_valid_svg(): void {
		$svg = Barcode::code128_svg( '20451012345678' );
		$this->assertStringStartsWith( '<svg', $svg );
		$this->assertStringEndsWith( '</svg>', $svg );
		$this->assertStringContainsString( '<rect', $svg );
		$this->assertStringContainsString( 'viewBox="0 0', $svg );
	}

	public function test_odd_numeric_or_alphanumeric_generates_valid_svg(): void {
		$svg = Barcode::code128_svg( '20451012345' );
		$this->assertStringStartsWith( '<svg', $svg );
		$this->assertStringEndsWith( '</svg>', $svg );
	}
}
