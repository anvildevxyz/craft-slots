<?php

namespace anvildev\slots\tests\Unit\Records;

use anvildev\slots\records\EmployeeRecord;
use anvildev\slots\records\EmployeeScheduleAssignmentRecord;
use anvildev\slots\records\LocationRecord;
use anvildev\slots\records\ScheduleRecord;
use anvildev\slots\records\ServiceRecord;
use anvildev\slots\records\ServiceScheduleAssignmentRecord;
use anvildev\slots\records\SettingsRecord;
use anvildev\slots\records\SoftLockRecord;
use anvildev\slots\tests\Support\TestCase;

/**
 * Tests for records with minimal pure logic (tableName only).
 * Instance methods on these records require DB access.
 */
class SimpleRecordsTest extends TestCase
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

    // =========================================================================
    // tableName() for all simple records
    // =========================================================================

    public function testEmployeeRecordTableName(): void
    {
        $this->assertEquals('{{%slots_employees}}', EmployeeRecord::tableName());
    }

    public function testServiceRecordTableName(): void
    {
        $this->assertEquals('{{%slots_services}}', ServiceRecord::tableName());
    }

    public function testLocationRecordTableName(): void
    {
        $this->assertEquals('{{%slots_locations}}', LocationRecord::tableName());
    }

    public function testScheduleRecordTableName(): void
    {
        $this->assertEquals('{{%slots_schedules}}', ScheduleRecord::tableName());
    }

    public function testSettingsRecordTableName(): void
    {
        $this->assertEquals('{{%slots_settings}}', SettingsRecord::tableName());
    }

    public function testSoftLockRecordTableName(): void
    {
        $this->assertEquals('{{%slots_soft_locks}}', SoftLockRecord::tableName());
    }

    public function testEmployeeScheduleAssignmentRecordTableName(): void
    {
        $this->assertEquals('{{%slots_employee_schedule_assignments}}', EmployeeScheduleAssignmentRecord::tableName());
    }

    public function testServiceScheduleAssignmentRecordTableName(): void
    {
        $this->assertEquals('{{%slots_service_schedule_assignments}}', ServiceScheduleAssignmentRecord::tableName());
    }
}
