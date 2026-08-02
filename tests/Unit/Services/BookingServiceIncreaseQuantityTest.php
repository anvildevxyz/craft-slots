<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\events\AfterQuantityChangeEvent;
use anvildev\slots\events\BeforeQuantityChangeEvent;
use anvildev\slots\services\BookingService;
use anvildev\slots\tests\Support\TestCase;

class BookingServiceIncreaseQuantityTest extends TestCase
{
    public function testIncreaseQuantityMethodExists(): void
    {
        $service = new BookingService();
        $this->assertTrue(method_exists($service, 'increaseQuantity'));
    }

    public function testIncreaseQuantityRejectsZeroIncrease(): void
    {
        $service = \Mockery::mock(BookingService::class)->makePartial();
        $result = $service->increaseQuantity(1, 0);
        $this->assertFalse($result);
    }

    public function testIncreaseQuantityRejectsNegativeIncrease(): void
    {
        $service = \Mockery::mock(BookingService::class)->makePartial();
        $result = $service->increaseQuantity(1, -1);
        $this->assertFalse($result);
    }

    public function testIncreaseQuantityReturnsFalseForNonexistentReservation(): void
    {
        $service = \Mockery::mock(BookingService::class)->makePartial();
        $service->shouldReceive('getReservationById')->with(999)->andReturn(null);
        $result = $service->increaseQuantity(999, 1);
        $this->assertFalse($result);
    }

    public function testBeforeQuantityChangeEventHasIncreaseByProperty(): void
    {
        $event = new BeforeQuantityChangeEvent();
        $this->assertObjectHasProperty('increaseBy', $event);
        $this->assertEquals(0, $event->increaseBy);
    }

    public function testAfterQuantityChangeEventHasIncreaseByProperty(): void
    {
        $event = new AfterQuantityChangeEvent();
        $this->assertObjectHasProperty('increaseBy', $event);
        $this->assertEquals(0, $event->increaseBy);
    }

    public function testQuantityChangeEventConstantsExist(): void
    {
        $this->assertIsString(BookingService::EVENT_BEFORE_QUANTITY_CHANGE);
        $this->assertEquals('beforeQuantityChange', BookingService::EVENT_BEFORE_QUANTITY_CHANGE);
        $this->assertIsString(BookingService::EVENT_AFTER_QUANTITY_CHANGE);
        $this->assertEquals('afterQuantityChange', BookingService::EVENT_AFTER_QUANTITY_CHANGE);
    }
}
