<?php
namespace NovaExpress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NovaExpress\Automation\RuleEngine;

final class RuleEngineDedupTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['_mock_wp_options'] = array();
    }

    public function testAlreadyProcessedDeduplication(): void {
        $ref = new \ReflectionClass( RuleEngine::class );
        $method = $ref->getMethod( 'already_processed' );
        $method->setAccessible( true );

        $engine = $ref->newInstanceWithoutConstructor();

        $order = new \WC_Order( '200,00 грн.', 777 );
        $waybill_row = array( 'waybill_number' => '20450000000001' );
        $event = array(
            'event'                => 'status_changed',
            'previous_status_code' => '1',
            'status_code'          => '7',
        );

        // First execution -> false (not processed yet)
        $is_dup1 = $method->invoke( $engine, $order, $waybill_row, $event );
        $this->assertFalse( $is_dup1, 'First event should not be marked as already processed' );

        // Second execution with same parameters -> true (deduplicated)
        $is_dup2 = $method->invoke( $engine, $order, $waybill_row, $event );
        $this->assertTrue( $is_dup2, 'Duplicate event within TTL should be marked as already processed' );

        // Different status code -> false (not duplicate)
        $event_different_status = array(
            'event'                => 'status_changed',
            'previous_status_code' => '7',
            'status_code'          => '9',
        );
        $is_dup3 = $method->invoke( $engine, $order, $waybill_row, $event_different_status );
        $this->assertFalse( $is_dup3, 'Different status event should not be deduplicated' );

        // Different order -> false (not duplicate)
        $order_other = new \WC_Order( '200,00 грн.', 888 );
        $is_dup4 = $method->invoke( $engine, $order_other, $waybill_row, $event );
        $this->assertFalse( $is_dup4, 'Event for different order should not be deduplicated' );
    }
}
