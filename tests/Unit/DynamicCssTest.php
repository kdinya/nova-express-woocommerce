<?php

namespace NovaExpress\Tests\Unit;

use NovaExpress\Admin\Assets;
use PHPUnit\Framework\TestCase;

class DynamicCssTest extends TestCase {
	public function test_generate_dynamic_css_is_public_and_returns_css(): void {
		$css = Assets::generate_dynamic_css( '#7CB342' );
		$this->assertStringContainsString( '--nvx-primary: #7CB342', $css );
		$this->assertStringContainsString( '--nvx-header-gradient:', $css );
		$this->assertStringContainsString( ':root', $css );
	}

	public function test_generate_dynamic_css_handles_invalid_and_short_hex(): void {
		$css3 = Assets::generate_dynamic_css( '#abc' );
		$this->assertStringContainsString( '--nvx-primary: #aabbcc', $css3 );

		$css_invalid = Assets::generate_dynamic_css( 'invalid' );
		$this->assertStringContainsString( '--nvx-primary: #7cb342', $css_invalid );
	}
}
