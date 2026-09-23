<?php

namespace NovaExpress\Tests\Unit;

use NovaExpress\Admin\LabelPrint;
use PHPUnit\Framework\TestCase;

class LabelPrintTest extends TestCase {

	public function test_get_np_print_url_uses_waybill_number_not_guid(): void {
		$waybill = '20450189416569';
		$api_key = 'test-api-key-12345';

		$url_zebra = LabelPrint::get_np_print_url( 'np_100x100', $waybill, $api_key );
		$this->assertSame(
			'https://my.novaposhta.ua/orders/printMarking100x100/orders[]/20450189416569/type/pdf/apiKey/test-api-key-12345/zebra',
			$url_zebra
		);

		$url_85 = LabelPrint::get_np_print_url( 'np_85x85', $waybill, $api_key );
		$this->assertSame(
			'https://my.novaposhta.ua/orders/printMarking85x85/orders[]/20450189416569/type/pdf8/apiKey/test-api-key-12345',
			$url_85
		);

		$url_doc = LabelPrint::get_np_print_url( 'np_document', $waybill, $api_key );
		$this->assertSame(
			'https://my.novaposhta.ua/orders/printDocument/orders[]/20450189416569/type/pdf/apiKey/test-api-key-12345',
			$url_doc
		);
	}

	public function test_get_np_print_url_strips_spaces(): void {
		$waybill = ' 2045 0189 4165 69 ';
		$api_key = 'key';

		$url = LabelPrint::get_np_print_url( 'np_100x100', $waybill, $api_key );
		$this->assertStringContainsString( '/orders[]/20450189416569/', $url );
	}

	public function test_get_np_print_url_returns_empty_on_invalid_format_or_empty_inputs(): void {
		$this->assertSame( '', LabelPrint::get_np_print_url( 'unknown', '20450189416569', 'key' ) );
		$this->assertSame( '', LabelPrint::get_np_print_url( 'np_100x100', '', 'key' ) );
		$this->assertSame( '', LabelPrint::get_np_print_url( 'np_100x100', '20450189416569', '' ) );
	}
}
