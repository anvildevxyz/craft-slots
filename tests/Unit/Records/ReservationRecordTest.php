<?php

namespace anvildev\slots\tests\Unit\Records;

use anvildev\slots\records\ReservationRecord;
use anvildev\slots\tests\Support\TestCase;
use Mockery;
use Mockery\MockInterface;

class ReservationRecordTest extends TestCase
{
    /**
     * @beforeClass
     */
    public static function defineCraftStub(): void
    {
        if (!class_exists('Craft', false)) {
            eval('class Craft extends \yii\BaseYii {}');
        }
    }

    private function makeRecord(array $props = []): MockInterface
    {
        $mock = Mockery::mock(ReservationRecord::class)->makePartial();
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($mock, $props);
        return $mock;
    }

    // =========================================================================
    // Static methods & constants
    // =========================================================================

    public function testTableNameReturnsCorrectTable(): void
    {
        $this->assertEquals('{{%slots_reservations}}', ReservationRecord::tableName());
    }

    public function testStatusConstants(): void
    {
        $this->assertEquals('pending', ReservationRecord::STATUS_PENDING);
        $this->assertEquals('confirmed', ReservationRecord::STATUS_CONFIRMED);
        $this->assertEquals('cancelled', ReservationRecord::STATUS_CANCELLED);
    }

    public function testNoShowStatusConstant(): void
    {
        $this->assertEquals('no_show', ReservationRecord::STATUS_NO_SHOW);
    }

    // =========================================================================
    // getStatuses()
    // =========================================================================

    public function testGetStatusesReturnsAllFourStatuses(): void
    {
        $statuses = ReservationRecord::getStatuses();
        $this->assertCount(4, $statuses);
        $this->assertArrayHasKey('pending', $statuses);
        $this->assertArrayHasKey('confirmed', $statuses);
        $this->assertArrayHasKey('cancelled', $statuses);
        $this->assertArrayHasKey('no_show', $statuses);
    }

    // =========================================================================
    // sessionNotes property
    // =========================================================================

    public function testSessionNotesPropertyExists(): void
    {
        $record = $this->makeRecord(['sessionNotes' => 'Patient reported improvement']);
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $attrs = $ref->getValue($record);
        $this->assertEquals('Patient reported improvement', $attrs['sessionNotes']);
    }

}
