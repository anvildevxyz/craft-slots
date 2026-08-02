<?php

namespace anvildev\slots\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $employeeId
 * @property int $scheduleId
 * @property int $sortOrder
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class EmployeeScheduleAssignmentRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%slots_employee_schedule_assignments}}';
    }

    public function rules(): array
    {
        return [
            [['employeeId', 'scheduleId'], 'required'],
            [['employeeId', 'scheduleId', 'sortOrder'], 'integer'],
            [['sortOrder'], 'default', 'value' => 0],
        ];
    }
}
