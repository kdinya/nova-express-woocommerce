<?php

namespace NovaExpress\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ReleasePackagingTest extends TestCase {

    private string $root_dir;

    protected function setUp(): void {
        parent::setUp();
        $this->root_dir = dirname( __DIR__, 2 );
    }

    public function test_gitattributes_file_exists(): void {
        $gitattributes = $this->root_dir . '/.gitattributes';
        $this->assertFileExists( $gitattributes, '.gitattributes must exist to exclude dev files from release archives' );

        $content = file_get_contents( $gitattributes );
        $this->assertStringContainsString( '/tests export-ignore', $content );
        $this->assertStringContainsString( '/.github export-ignore', $content );
    }

    public function test_entrypoint_file_is_present(): void {
        $main_file = $this->root_dir . '/wc-nova-express.php';
        $this->assertFileExists( $main_file );
        $this->assertFileExists( $this->root_dir . '/includes/Updater/GitHubUpdater.php' );
        $this->assertFileExists( $this->root_dir . '/assets/js/admin-settings.js' );
        $this->assertFileExists( $this->root_dir . '/assets/css/admin.css' );
    }
}
