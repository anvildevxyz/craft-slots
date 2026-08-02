<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\services\MutexFactory;
use anvildev\slots\tests\Support\TestCase;

class MutexFactoryTest extends TestCase
{
    public function testCanBeInstantiated(): void
    {
        $factory = new MutexFactory();
        $this->assertInstanceOf(MutexFactory::class, $factory);
    }

    public function testSupportedDriversReturnsArray(): void
    {
        $factory = new MutexFactory();
        $drivers = $factory->getSupportedDrivers();

        $this->assertIsArray($drivers);
        $this->assertCount(4, $drivers);
        $this->assertContains('auto', $drivers);
        $this->assertContains('file', $drivers);
        $this->assertContains('db', $drivers);
        $this->assertContains('redis', $drivers);
    }

    public function testGetMethodExists(): void
    {
        $factory = new MutexFactory();
        $this->assertTrue(method_exists($factory, 'get'));
    }

    public function testGetReturnsMutexInstance(): void
    {
        $this->requiresCraft();

        $factory = new MutexFactory();
        $mutex = $factory->get();
        $this->assertInstanceOf(\yii\mutex\Mutex::class, $mutex);
    }

    public function testGetReturnsCachedInstance(): void
    {
        $this->requiresCraft();

        $factory = new MutexFactory();
        $first = $factory->get();
        $second = $factory->get();
        $this->assertSame($first, $second);
    }

    public function testExtendsComponent(): void
    {
        $factory = new MutexFactory();
        $this->assertInstanceOf(\craft\base\Component::class, $factory);
    }
}
