<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\events\AfterQuantityChangeEvent;
use anvildev\slots\events\BeforeQuantityChangeEvent;
use anvildev\slots\services\BookingService;
use anvildev\slots\tests\Support\TestCase;

class BookingServiceReduceQuantityTest extends TestCase
{
    public function testReduceQuantityEventConstantsDefined(): void
    {
        $this->assertIsString(BookingService::EVENT_BEFORE_QUANTITY_CHANGE);
        $this->assertEquals('beforeQuantityChange', BookingService::EVENT_BEFORE_QUANTITY_CHANGE);
        $this->assertIsString(BookingService::EVENT_AFTER_QUANTITY_CHANGE);
        $this->assertEquals('afterQuantityChange', BookingService::EVENT_AFTER_QUANTITY_CHANGE);
    }

    public function testBeforeQuantityChangeEventHasRequiredProperties(): void
    {
        $event = new BeforeQuantityChangeEvent();
        $this->assertObjectHasProperty('previousQuantity', $event);
        $this->assertObjectHasProperty('reduceBy', $event);
        $this->assertObjectHasProperty('newQuantity', $event);
        $this->assertObjectHasProperty('reason', $event);
        $this->assertObjectHasProperty('reservation', $event);
    }

    public function testAfterQuantityChangeEventHasRequiredProperties(): void
    {
        $event = new AfterQuantityChangeEvent();
        $this->assertObjectHasProperty('previousQuantity', $event);
        $this->assertObjectHasProperty('reduceBy', $event);
        $this->assertObjectHasProperty('newQuantity', $event);
        $this->assertObjectHasProperty('reason', $event);
        $this->assertObjectHasProperty('reservation', $event);
    }

    public function testReduceByZeroReturnsFalse(): void
    {
        $this->requiresCraft();
        $service = new BookingService();
        $this->assertFalse($service->reduceQuantity(999999, 0));
    }

    public function testReduceByNegativeReturnsFalse(): void
    {
        $this->requiresCraft();
        $service = new BookingService();
        $this->assertFalse($service->reduceQuantity(999999, -1));
    }
}
