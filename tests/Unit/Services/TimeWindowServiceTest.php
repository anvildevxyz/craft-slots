<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\services\TimeWindowService;
use anvildev\slots\tests\Support\TestCase;

class TimeWindowServiceTest extends TestCase
{
    private TimeWindowService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TimeWindowService();
    }

    public function testMinutesToTimeHandlesMidnightBoundary(): void
    {
        $this->assertEquals('24:00', $this->service->minutesToTime(1440));
    }

    public function testMinutesToTimeThrowsOnOverflow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->minutesToTime(1500);
    }

    public function testMinutesToTimeHandlesNormalValues(): void
    {
        $this->assertEquals('09:30', $this->service->minutesToTime(570));
        $this->assertEquals('00:00', $this->service->minutesToTime(0));
        $this->assertEquals('23:59', $this->service->minutesToTime(1439));
    }

    public function testMinutesToTimeThrowsOnNegativeValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->minutesToTime(-10);
    }

    public function testTimeToMinutesBasicConversion(): void
    {
        $this->assertEquals(570, $this->service->timeToMinutes('09:30'));
        $this->assertEquals(0, $this->service->timeToMinutes('00:00'));
        $this->assertEquals(1439, $this->service->timeToMinutes('23:59'));
    }

    public function testMergeOverlappingWindows(): void
    {
        $windows = [
            ['start' => '09:00', 'end' => '12:00'],
            ['start' => '11:00', 'end' => '14:00'],
        ];
        $merged = $this->service->mergeWindows($windows);
        $this->assertCount(1, $merged);
        $this->assertEquals('09:00', $merged[0]['start']);
        $this->assertEquals('14:00', $merged[0]['end']);
    }

    public function testSubtractWindowSplitsRange(): void
    {
        $windows = [['start' => '09:00', 'end' => '17:00']];
        $result = $this->service->subtractWindow($windows, '12:00', '13:00');
        $this->assertCount(2, $result);
        $this->assertEquals('09:00', $result[0]['start']);
        $this->assertEquals('12:00', $result[0]['end']);
        $this->assertEquals('13:00', $result[1]['start']);
        $this->assertEquals('17:00', $result[1]['end']);
    }

    public function testAddMinutesBasic(): void
    {
        $this->assertEquals('10:00', $this->service->addMinutes('09:30', 30));
    }
}
