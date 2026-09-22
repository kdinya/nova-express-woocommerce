<?php

namespace NovaExpress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NovaExpress\Updater\GitHubUpdater;

class GitHubUpdaterTest extends TestCase {

    private GitHubUpdater $updater;
    private string $plugin_file;

    protected function setUp(): void {
        parent::setUp();
        $this->plugin_file = dirname( __DIR__, 2 ) . '/wc-nova-express.php';
        $this->updater     = new GitHubUpdater( $this->plugin_file, 'kdinya', 'nova-express-woocommerce', '2026.09.09' );
        $GLOBALS['_mock_transients'] = array();
    }

    public function test_get_download_package_prioritizes_named_zip_asset(): void {
        $reflection = new \ReflectionClass( $this->updater );
        $method     = $reflection->getMethod( 'get_download_package' );
        $method->setAccessible( true );

        $release = array(
            'zipball_url' => 'https://api.github.com/repos/kdinya/nova-express-woocommerce/zipball/v2026.09.10',
            'assets'      => array(
                array(
                    'name'                 => 'source.tar.gz',
                    'browser_download_url' => 'https://github.com/kdinya/nova-express-woocommerce/releases/download/v2026.09.10/source.tar.gz',
                ),
                array(
                    'name'                 => 'wc-nova-express.zip',
                    'browser_download_url' => 'https://github.com/kdinya/nova-express-woocommerce/releases/download/v2026.09.10/wc-nova-express.zip',
                ),
            ),
        );

        $package = $method->invoke( $this->updater, $release );
        $this->assertSame( 'https://github.com/kdinya/nova-express-woocommerce/releases/download/v2026.09.10/wc-nova-express.zip', $package );
    }

    public function test_get_download_package_returns_empty_if_no_assets(): void {
        $reflection = new \ReflectionClass( $this->updater );
        $method     = $reflection->getMethod( 'get_download_package' );
        $method->setAccessible( true );

        $release = array(
            'zipball_url' => 'https://api.github.com/repos/kdinya/nova-express-woocommerce/zipball/v2026.09.10',
            'assets'      => array(),
        );

        $package = $method->invoke( $this->updater, $release );
        $this->assertSame( '', $package );
    }

    public function test_check_for_update_ignores_when_transient_checked_is_empty(): void {
        $transient = new \stdClass();
        $result    = $this->updater->check_for_update( $transient );
        $this->assertSame( $transient, $result );
        $this->assertFalse( isset( $result->response ) );
    }

    public function test_check_for_update_populates_response_when_remote_version_is_higher(): void {
        // Mock get_latest_release via cached transient
        $mock_release = array(
            'tag_name'    => 'v2026.09.10',
            'zipball_url' => 'https://example.com/download.zip',
            'assets'      => array(
                array(
                    'name'                 => 'wc-nova-express.zip',
                    'browser_download_url' => 'https://github.com/kdinya/nova-express-woocommerce/releases/download/v2026.09.10/wc-nova-express.zip',
                ),
            ),
        );
        set_transient( 'nvx_github_latest_release', $mock_release, 3600 );

        $transient = new \stdClass();
        $transient->checked = array( 'wc-nova-express/wc-nova-express.php' => '2026.09.09' );
        $transient->response = array();

        $result = $this->updater->check_for_update( $transient );

        $slug = plugin_basename( $this->plugin_file );
        $this->assertArrayHasKey( $slug, $result->response );
        $this->assertSame( '2026.09.10', $result->response[ $slug ]->new_version );
        $this->assertSame( 'https://github.com/kdinya/nova-express-woocommerce/releases/download/v2026.09.10/wc-nova-express.zip', $result->response[ $slug ]->package );
    }

    public function test_check_for_update_does_not_add_response_when_versions_are_equal(): void {
        $mock_release = array(
            'tag_name'    => 'v2026.09.09',
            'zipball_url' => 'https://example.com/download.zip',
            'assets'      => array(),
        );
        set_transient( 'nvx_github_latest_release', $mock_release, 3600 );

        $transient = new \stdClass();
        $transient->checked = array( 'wc-nova-express/wc-nova-express.php' => '2026.09.09' );
        $transient->response = array();

        $result = $this->updater->check_for_update( $transient );

        $slug = plugin_basename( $this->plugin_file );
        $this->assertArrayNotHasKey( $slug, $result->response );
    }
}
