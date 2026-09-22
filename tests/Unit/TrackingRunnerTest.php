<?php

namespace NovaExpress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NovaExpress\Tracking\TrackingRunner;

class TrackingRunnerTest extends TestCase {

    public function test_looks_deleted_returns_true_for_deletion_keywords(): void {
        $this->assertTrue( TrackingRunner::looks_deleted( array( 'Status' => 'Номер не знайдено' ) ) );
        $this->assertTrue( TrackingRunner::looks_deleted( array( 'Status' => 'ТТН видалено відправником' ) ) );
        $this->assertTrue( TrackingRunner::looks_deleted( array( 'Status' => 'Замовлення скасовано' ) ) );
        $this->assertTrue( TrackingRunner::looks_deleted( array( 'Status' => 'Not found in system' ) ) );
        $this->assertTrue( TrackingRunner::looks_deleted( array( 'StatusCode' => 'not found' ) ) );
    }

    public function test_looks_deleted_returns_false_for_normal_or_empty_statuses(): void {
        $this->assertFalse( TrackingRunner::looks_deleted( array( 'Status' => 'Посилка прямує до відділення' ) ) );
        $this->assertFalse( TrackingRunner::looks_deleted( array( 'Status' => 'Відправлення отримано' ) ) );
        $this->assertFalse( TrackingRunner::looks_deleted( array( 'Status' => '' ) ) );
        $this->assertFalse( TrackingRunner::looks_deleted( array() ) );
    }
}
