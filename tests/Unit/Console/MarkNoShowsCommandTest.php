<?php

namespace anvildev\slots\tests\Unit\Console;

use anvildev\slots\console\controllers\BookingsController;
use anvildev\slots\tests\Support\TestCase;

class MarkNoShowsCommandTest extends TestCase
{
    public function testCommandOptionsIncludeGracePeriod(): void
    {
        $this->requiresCraft();
        $controller = new BookingsController('bookings', null);
        $options = $controller->options('mark-no-shows');
        $this->assertContains('gracePeriod', $options);
        $this->assertContains('dryRun', $options);
    }

    public function testDefaultGracePeriodIsThirtyMinutes(): void
    {
        $this->requiresCraft();
        $controller = new BookingsController('bookings', null);
        $this->assertEquals(30, $controller->gracePeriod);
    }

    public function testDefaultDryRunIsFalse(): void
    {
        $this->requiresCraft();
        $controller = new BookingsController('bookings', null);
        $this->assertFalse($controller->dryRun);
    }
}
