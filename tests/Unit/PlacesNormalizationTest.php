<?php
namespace NovaExpress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NovaExpress\Ttn\TtnManager;

final class PlacesNormalizationTest extends TestCase {

    public function testFormatCm(): void {
        $ref = new \ReflectionClass( TtnManager::class );
        $method = $ref->getMethod( 'format_cm' );
        $method->setAccessible( true );

        $this->assertSame( '40', $method->invoke( null, 40.0 ) );
        $this->assertSame( '40.5', $method->invoke( null, 40.5 ) );
        $this->assertSame( '15.2', $method->invoke( null, 15.24 ) );
    }

    public function testNormalizePlacesFallback(): void {
        $ref = new \ReflectionClass( TtnManager::class );
        $method = $ref->getMethod( 'normalize_places' );
        $method->setAccessible( true );

        $manager = $ref->newInstanceWithoutConstructor();
        $order = new \WC_Order( '100,00 грн.', 333 );

        // Empty raw places should return default place based on order
        $places = $method->invoke( $manager, array(), $order );

        $this->assertCount( 1, $places );
        $this->assertSame( 0.5, $places[0]['weight'] );
        $this->assertSame( 10.0, $places[0]['width'] );
        $this->assertSame( 10.0, $places[0]['height'] );
        $this->assertSame( 10.0, $places[0]['length'] );
    }

    public function testNormalizePlacesCustomInput(): void {
        $ref = new \ReflectionClass( TtnManager::class );
        $method = $ref->getMethod( 'normalize_places' );
        $method->setAccessible( true );

        $manager = $ref->newInstanceWithoutConstructor();
        $order = new \WC_Order( '100,00 грн.', 333 );

        $input = array(
            array(
                'weight' => '2.5',
                'width'  => '20',
                'height' => '15',
                'length' => '30',
            ),
        );

        $places = $method->invoke( $manager, $input, $order );

        $this->assertCount( 1, $places );
        $this->assertSame( 2.5, $places[0]['weight'] );
        $this->assertSame( 20.0, $places[0]['width'] );
        $this->assertSame( 15.0, $places[0]['height'] );
        $this->assertSame( 30.0, $places[0]['length'] );
    }
}
