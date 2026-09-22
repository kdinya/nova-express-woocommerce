<?php

namespace NovaExpress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NovaExpress\Api\NovaPoshtaClient;
use NovaExpress\Api\Exception\NovaPoshtaApiException;

class NovaPoshtaContractTest extends TestCase {

    private string $fixtures_dir;

    protected function setUp(): void {
        parent::setUp();
        $this->fixtures_dir = dirname( __DIR__ ) . '/Fixtures/NovaPoshta';
    }

    public function test_tracking_success_fixture_parses_status_and_code(): void {
        $json = file_get_contents( $this->fixtures_dir . '/tracking_success.json' );
        $data = json_decode( $json, true );

        $this->assertTrue( $data['success'] );
        $this->assertNotEmpty( $data['data'] );
        $doc = $data['data'][0];

        $this->assertSame( '20450000000001', $doc['Number'] );
        $this->assertSame( '7', $doc['StatusCode'] );
        $this->assertSame( 'Прибув у відділення', $doc['Status'] );
        $this->assertSame( 'Київ', $doc['CityRecipient'] );
    }

    public function test_tracking_error_fixture_contains_errors(): void {
        $json = file_get_contents( $this->fixtures_dir . '/tracking_error.json' );
        $data = json_decode( $json, true );

        $this->assertFalse( $data['success'] );
        $this->assertContains( 'Document number is not found', $data['errors'] );
    }

    public function test_internet_document_success_parses_doc_number_and_ref(): void {
        $json = file_get_contents( $this->fixtures_dir . '/internet_document_success.json' );
        $data = json_decode( $json, true );

        $this->assertTrue( $data['success'] );
        $doc = $data['data'][0];

        $this->assertSame( '20450000000002', $doc['IntDocNumber'] );
        $this->assertSame( '1c7414bf-03f6-11e7-8ba7-005056887b8d', $doc['Ref'] );
        $this->assertSame( 85.0, (float) $doc['CostOnSite'] );
    }

    public function test_humanize_error_explains_postomat_max_param_size(): void {
        $json = file_get_contents( $this->fixtures_dir . '/internet_document_postomat_limit_error.json' );
        $data = json_decode( $json, true );

        $raw_error = $data['errors'][0];

        $reflection = new \ReflectionClass( NovaPoshtaClient::class );
        $method     = $reflection->getMethod( 'humanize_error' );
        $method->setAccessible( true );

        $humanized = $method->invoke( null, $raw_error );

        $this->assertStringContainsString( 'max param value size', $humanized );
        $this->assertStringContainsString( 'поштомат', $humanized );
        $this->assertStringContainsString( 'габарити', $humanized );
    }

    public function test_warehouses_and_postomats_fixture(): void {
        $json = file_get_contents( $this->fixtures_dir . '/warehouses_success.json' );
        $data = json_decode( $json, true );

        $this->assertTrue( $data['success'] );
        $this->assertCount( 2, $data['data'] );

        $branch   = $data['data'][0];
        $postomat = $data['data'][1];

        $this->assertSame( '1', $branch['Number'] );
        $this->assertSame( 1100, $branch['TotalMaxWeightAllowed'] );

        $this->assertSame( '35001', $postomat['Number'] );
        $this->assertSame( 20, $postomat['TotalMaxWeightAllowed'] );
    }
}
