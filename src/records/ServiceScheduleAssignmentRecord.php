<?php

namespace anvildev\slots\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $serviceId
 * @property int $scheduleId
 * @property int $sortOrder
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class ServiceScheduleAssignmentRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%slots_service_schedule_assignments}}';
    }

    public function rules(): array
    {
        return [
            [['serviceId', 'scheduleId'], 'required'],
            [['serviceId', 'scheduleId', 'sortOrder'], 'integer'],
            [['sortOrder'], 'default', 'value' => 0],
        ];
    }
}
