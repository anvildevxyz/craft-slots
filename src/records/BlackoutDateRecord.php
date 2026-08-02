<?php

namespace anvildev\slots\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $title
 * @property string $startDate
 * @property string $endDate
 * @property bool $isActive
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class BlackoutDateRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%slots_blackout_dates}}';
    }

    public function rules(): array
    {
        return [
            [['title', 'startDate', 'endDate'], 'required'],
            [['title'], 'string', 'max' => 255],
            [['startDate', 'endDate'], 'date', 'format' => 'php:Y-m-d'],
            [['isActive'], 'boolean'],
            [['isActive'], 'default', 'value' => true],
        ];
    }
}
