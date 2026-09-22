<?php
namespace NovaExpress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NovaExpress\Ttn\TtnManager;

final class TtnLockTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['_mock_wp_options'] = array();
    }

    public function testAcquireAndReleaseLockWithToken(): void {
        $ref = new \ReflectionClass( TtnManager::class );
        $acquire = $ref->getMethod( 'acquire_lock' );
        $acquire->setAccessible( true );
        $release = $ref->getMethod( 'release_lock' );
        $release->setAccessible( true );

        $manager = $ref->newInstanceWithoutConstructor();

        $lock_key = 'test_order_lock_123';

        // 1. First acquire succeeds and returns a string token
        $token1 = $acquire->invoke( $manager, $lock_key, 60 );
        $this->assertIsString( $token1 );
        $this->assertNotEmpty( $token1 );

        // 2. Second acquire on same active key fails (returns null)
        $token2 = $acquire->invoke( $manager, $lock_key, 60 );
        $this->assertNull( $token2 );

        // 3. Release with WRONG token does not delete the lock
        $release->invoke( $manager, $lock_key, 'wrong-token-999' );
        $token3 = $acquire->invoke( $manager, $lock_key, 60 );
        $this->assertNull( $token3, 'Lock should not be released with invalid token' );

        // 4. Release with CORRECT token successfully unlocks
        $release->invoke( $manager, $lock_key, $token1 );
        $token4 = $acquire->invoke( $manager, $lock_key, 60 );
        $this->assertIsString( $token4, 'Lock should be acquirable after owner release' );

        // Cleanup
        $release->invoke( $manager, $lock_key, $token4 );
    }

    public function testLockExpirationAllowsNewAcquisition(): void {
        $ref = new \ReflectionClass( TtnManager::class );
        $acquire = $ref->getMethod( 'acquire_lock' );
        $acquire->setAccessible( true );

        $manager = $ref->newInstanceWithoutConstructor();
        $lock_key = 'expired_lock_456';

        // Set expired lock directly in options
        $GLOBALS['_mock_wp_options'][ $lock_key ] = array(
            'token'   => 'old-token',
            'expires' => time() - 10,
        );

        // Should successfully acquire because previous lock expired
        $token = $acquire->invoke( $manager, $lock_key, 60 );
        $this->assertIsString( $token );
    }
}
