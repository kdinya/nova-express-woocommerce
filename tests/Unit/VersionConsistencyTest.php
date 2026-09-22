<?php

namespace NovaExpress\Tests\Unit;

use PHPUnit\Framework\TestCase;

class VersionConsistencyTest extends TestCase {

    private string $root_dir;

    protected function setUp(): void {
        parent::setUp();
        $this->root_dir = dirname( __DIR__, 2 );
    }

    public function test_plugin_header_matches_nvx_version_constant(): void {
        $main_file = $this->root_dir . '/wc-nova-express.php';
        $this->assertFileExists( $main_file );

        $content = file_get_contents( $main_file );

        // Extract header version
        $has_header = preg_match( '/^[ \t\/*#@]*Version:\s*([0-9\.]+)/mi', $content, $header_matches );
        $this->assertSame( 1, $has_header, 'wc-nova-express.php must define a valid "Version: YYYY.MM.DD" in plugin header' );
        $header_version = trim( $header_matches[1] );

        // Extract NVX_VERSION constant
        $has_constant = preg_match( "/define\(\s*['\"]NVX_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\);/", $content, $const_matches );
        $this->assertSame( 1, $has_constant, 'wc-nova-express.php must define NVX_VERSION constant' );
        $const_version = trim( $const_matches[1] );

        $this->assertSame(
            $header_version,
            $const_version,
            sprintf( 'Plugin header Version (%s) must match NVX_VERSION constant (%s)', $header_version, $const_version )
        );
    }

    public function test_readme_stable_tag_matches_plugin_version(): void {
        $readme_file = $this->root_dir . '/readme.txt';
        if ( ! file_exists( $readme_file ) ) {
            $this->markTestSkipped( 'readme.txt not found' );
        }

        $content = file_get_contents( $readme_file );
        $has_tag = preg_match( '/Stable tag:\s*([0-9\.]+)/i', $content, $tag_matches );
        if ( 1 === $has_tag ) {
            $stable_tag = trim( $tag_matches[1] );

            $main_content = file_get_contents( $this->root_dir . '/wc-nova-express.php' );
            preg_match( "/define\(\s*['\"]NVX_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\);/", $main_content, $const_matches );
            $const_version = trim( $const_matches[1] ?? '' );

            $this->assertSame(
                $const_version,
                $stable_tag,
                sprintf( 'readme.txt Stable tag (%s) must match NVX_VERSION (%s)', $stable_tag, $const_version )
            );
        }
    }

    public function test_version_format_is_calver(): void {
        $main_file = $this->root_dir . '/wc-nova-express.php';
        $content   = file_get_contents( $main_file );

        preg_match( "/define\(\s*['\"]NVX_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\);/", $content, $matches );
        $version = $matches[1] ?? '';

        // CalVer pattern YYYY.MM.N or YYYY.M.N (e.g. 2026.09.09 or 2026.9.7)
        $this->assertMatchesRegularExpression(
            '/^20\d{2}\.\d{1,2}\.\d+$/',
            $version,
            'Version must follow Calendar Versioning format YYYY.MM.N (e.g. 2026.09.09)'
        );
    }
}
