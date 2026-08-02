<?php

namespace anvildev\slots\tests\Unit\Elements;

use anvildev\slots\elements\Reservation;
use anvildev\slots\tests\Support\TestCase;

class ReservationTest extends TestCase
{
    public function testStatusesIncludesNoShow(): void
    {
        $this->requiresCraft();
        $statuses = Reservation::statuses();
        $this->assertArrayHasKey('no_show', $statuses);
        $this->assertCount(4, $statuses);
    }

    public function testStatusesHasCorrectColors(): void
    {
        $this->requiresCraft();
        $statuses = Reservation::statuses();
        $this->assertIsArray($statuses['confirmed']);
        $this->assertEquals(\craft\enums\Color::Green, $statuses['confirmed']['color']);
        $this->assertIsArray($statuses['pending']);
        $this->assertEquals(\craft\enums\Color::Orange, $statuses['pending']['color']);
        $this->assertIsArray($statuses['cancelled']);
        $this->assertArrayNotHasKey('color', $statuses['cancelled']);
        $this->assertIsArray($statuses['no_show']);
        $this->assertEquals(\craft\enums\Color::Red, $statuses['no_show']['color']);
    }

    public function testMarkAsNoShowMethodExists(): void
    {
        $this->assertTrue(method_exists(Reservation::class, 'markAsNoShow'));
    }

    public function testDefineSourcesIncludesNoShow(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/elements/Reservation.php');
        $this->assertStringContainsString("'key' => 'no_show'", $source);
        $this->assertStringContainsString('STATUS_NO_SHOW', $source);
    }
}
