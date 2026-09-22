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
        $this->assertStringContainsString( '22.09.2026 12:00', $display );

        $row_with_scan = array(
            'carrier_status_code' => '7',
            'carrier_status_text' => 'Прибув у відділення',
            'tracking_details'    => json_encode( array( 'DateScan' => '21.09.2026 14:30:00' ) ),
        );
        $display_scan = Formatting::format_ttn_status_display( $row_with_scan );
        $this->assertSame( '[7] Прибув у відділення (21.09.2026 14:30)', $display_scan );

        $display_no_time = Formatting::format_ttn_status_display( $row_with_status, false );
        $this->assertSame( '[7] Прибув у відділення', $display_no_time );
    }

    public function testApplyOrderTemplatePlaceholders(): void {
        $order = new \WC_Order( '180,00 грн.', 1234 );
        $order->set_meta_data( '_nvx_city_name', 'Львів' );
        $order->set_meta_data( '_nvx_warehouse_label', 'Відділення №5' );
        $order->set_meta_data( '_custom_track_ref', 'REF-999' );

        $template = 'Замовлення #{order_number}: {customer_name}, тел {phone}, місто {city_name}, склад {warehouse}, сума {order_total} {currency}';
        $result = Formatting::apply_order_template( $template, $order );

        $this->assertStringContainsString( 'Замовлення #1234:', $result );
        $this->assertStringContainsString( 'Тарас Шевченко', $result );
        $this->assertStringContainsString( '0671234567', $result );
        $this->assertStringContainsString( 'місто Львів', $result );
        $this->assertStringContainsString( 'склад Відділення №5', $result );
        $this->assertStringContainsString( '180 UAH', $result );

        // Test {meta:...}
        $meta_template = 'Ref: {meta:_custom_track_ref}';
        $this->assertSame( 'Ref: REF-999', Formatting::apply_order_template( $meta_template, $order ) );
    }

    public function testResolveAdditionalInfo(): void {
        $order = new \WC_Order( '180,00 грн.', 555 );
        $template = 'Доставка до {customer_name} (замовлення #{order_number})';

        // Filter contains matches
        $res_matched = Formatting::resolve_additional_info( $template, $order, 'Шевченко' );
        $this->assertStringContainsString( 'Доставка до Тарас Шевченко', $res_matched );

        // Filter contains does not match
        $res_unmatched = Formatting::resolve_additional_info( $template, $order, 'Франко' );
        $this->assertSame( '', $res_unmatched );
    }
}
