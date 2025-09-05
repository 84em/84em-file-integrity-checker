<?php
/**
 * Tests for Container (Dependency Injection)
 */

namespace EightyFourEM\FileIntegrityChecker\Tests\Unit;

use PHPUnit\Framework\TestCase;
use EightyFourEM\FileIntegrityChecker\Container;

class ContainerTest extends TestCase {
    private Container $container;

    protected function setUp(): void {
        $this->container = new Container();
    }

    public function testRegisterAndGetService(): void {
        $this->container->register( 'test.service', function () {
            return new \stdClass();
        } );

        $service = $this->container->get( 'test.service' );

        $this->assertInstanceOf( \stdClass::class, $service );
    }

    public function testServiceIsSingleton(): void {
        $this->container->register( 'test.service', function () {
            return new \stdClass();
        } );

        $service1 = $this->container->get( 'test.service' );
        $service2 = $this->container->get( 'test.service' );

        $this->assertSame( $service1, $service2, 'Container should return the same instance on subsequent calls' );
    }

    public function testRegisterSingleton(): void {
        $instance = new \stdClass();
        $this->container->singleton( 'test.singleton', $instance );

        $retrieved = $this->container->get( 'test.singleton' );

        $this->assertSame( $instance, $retrieved );
    }

    public function testHasService(): void {
        $this->container->register( 'test.service', function () {
            return new \stdClass();
        } );

        $this->assertTrue( $this->container->has( 'test.service' ) );
        $this->assertFalse( $this->container->has( 'nonexistent.service' ) );
    }

    public function testHasSingleton(): void {
        $this->container->singleton( 'test.singleton', new \stdClass() );

        $this->assertTrue( $this->container->has( 'test.singleton' ) );
    }

    public function testMakeCreatesNewInstance(): void {
        $this->container->register( 'test.service', function () {
            return new \stdClass();
        } );

        $instance1 = $this->container->make( 'test.service' );
        $instance2 = $this->container->make( 'test.service' );

        $this->assertNotSame( $instance1, $instance2, 'make() should create new instances each time' );
    }

    public function testDependencyInjection(): void {
        // Register a dependency
        $this->container->register( 'test.dependency', function () {
            $obj = new \stdClass();
            $obj->value = 'dependency';
            return $obj;
        } );

        // Register a service that depends on the first
        $this->container->register( 'test.service', function ( $container ) {
            $obj = new \stdClass();
            $obj->dependency = $container->get( 'test.dependency' );
            return $obj;
        } );

        $service = $this->container->get( 'test.service' );

        $this->assertInstanceOf( \stdClass::class, $service );
        $this->assertInstanceOf( \stdClass::class, $service->dependency );
        $this->assertEquals( 'dependency', $service->dependency->value );
    }

    public function testGetThrowsExceptionForUnregisteredService(): void {
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Service unregistered.service is not registered.' );

        $this->container->get( 'unregistered.service' );
    }

    public function testMakeThrowsExceptionForUnregisteredService(): void {
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Service unregistered.service is not registered.' );

        $this->container->make( 'unregistered.service' );
    }

    public function testCircularDependencyHandling(): void {
        // Register two services that depend on each other
        $this->container->register( 'service.a', function ( $container ) {
            $obj = new \stdClass();
            $obj->name = 'A';
            $obj->dependency = $container->get( 'service.b' );
            return $obj;
        } );

        $this->container->register( 'service.b', function ( $container ) {
            $obj = new \stdClass();
            $obj->name = 'B';
            $obj->dependency = $container->get( 'service.a' );
            return $obj;
        } );

        // This should cause a stack overflow or infinite recursion
        // In a production container, you'd want to detect and handle this
        $this->expectException( \Error::class );
        $this->container->get( 'service.a' );
    }

    public function testFactoryFunctionReceivesContainer(): void {
        $receivedContainer = null;
        
        $this->container->register( 'test.service', function ( $container ) use ( &$receivedContainer ) {
            $receivedContainer = $container;
            return new \stdClass();
        } );

        $this->container->get( 'test.service' );

        $this->assertSame( $this->container, $receivedContainer );
    }

    public function testMultipleRegistrationsOverwrite(): void {
        // Register initial service
        $this->container->register( 'test.service', function () {
            $obj = new \stdClass();
            $obj->version = 1;
            return $obj;
        } );

        // Register replacement service
        $this->container->register( 'test.service', function () {
            $obj = new \stdClass();
            $obj->version = 2;
            return $obj;
        } );

        $service = $this->container->get( 'test.service' );

        $this->assertEquals( 2, $service->version );
    }

    public function testSingletonOverridesFactory(): void {
        $this->container->register( 'test.service', function () {
            return new \stdClass();
        } );

        $singletonInstance = new \stdClass();
        $singletonInstance->isSingleton = true;
        
        $this->container->singleton( 'test.service', $singletonInstance );

        $service = $this->container->get( 'test.service' );

        $this->assertSame( $singletonInstance, $service );
        $this->assertTrue( $service->isSingleton );
    }
}