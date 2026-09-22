<?php
namespace NovaExpress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NovaExpress\Helpers\Formatting;

final class FormattingTest extends TestCase {

    public function testNormalizePhone(): void {
        $cases = array(
            '+38 (067) 123-45-67' => '380671234567',
            '0671234567'          => '380671234567',
            '380671234567'        => '380671234567',
            '80671234567'         => '380671234567',
            '+380 (50) 999 88 77' => '380509998877',
            ''                    => '',
        );

        foreach ( $cases as $input => $expected ) {
            $this->assertSame(
                $expected,
                Formatting::normalize_phone( $input ),
                "Failed normalize_phone for: {$input}"
            );
        }
    }

    public function testCleanOrderTotal(): void {
        $mock_order = new \WC_Order( '180,00&nbsp;грн.' );
        $this->assertSame( '180,00 грн.', Formatting::clean_order_total( $mock_order ) );

        $mock_order_spaces = new \WC_Order( " 180,00   грн. \n" );
        $this->assertSame( '180,00 грн.', Formatting::clean_order_total( $mock_order_spaces ) );
    }

    public function testFormatTtnStatusDisplay(): void {
        $row_empty = array();
        $this->assertSame( 'Очікує опитування', Formatting::format_ttn_status_display( $row_empty ) );

        $row_with_status = array(
            'carrier_status_code' => '7',
            'carrier_status_text' => 'Прибув у відділення',
            'last_polled_at'      => '2026-09-22 12:00:00',
        );
        $display = Formatting::format_ttn_status_display( $row_with_status );
        $this->assertStringContainsString( '[7]', $display );
        $this->assertStringContainsString( 'Прибув у відділення', $display );
    }
}
