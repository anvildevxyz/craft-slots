<?php

namespace anvildev\slots\tests\Unit\Records;

use anvildev\slots\records\BlackoutDateRecord;
use anvildev\slots\tests\Support\TestCase;
use Mockery;
use Mockery\MockInterface;

class BlackoutDateRecordTest extends TestCase
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
        $mock = Mockery::mock(BlackoutDateRecord::class)->makePartial();
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($mock, $props);
        return $mock;
    }

    // =========================================================================
    // Static methods
    // =========================================================================

    public function testTableNameReturnsCorrectTable(): void
    {
        $this->assertEquals('{{%slots_blackout_dates}}', BlackoutDateRecord::tableName());
    }

}
