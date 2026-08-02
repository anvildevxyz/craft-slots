<?php

namespace anvildev\slots\records;

use craft\db\ActiveRecord;
use yii\db\JsonExpression;

/**
 * @property int $id
 * @property array|JsonExpression $workingHours
 * @property string|null $startDate
 * @property string|null $endDate
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class ScheduleRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%slots_schedules}}';
    }

    public function rules(): array
    {
        return [
            [['startDate', 'endDate'], 'date', 'format' => 'php:Y-m-d'],
            [['workingHours'], 'safe'],
        ];
    }

    public function beforeSave($insert): bool
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        if (is_array($this->workingHours)) {
            $this->workingHours = new JsonExpression($this->workingHours);
        } elseif (is_string($this->workingHours)) {
            $decoded = json_decode($this->workingHours, true);
            if ($decoded !== null) {
                $this->workingHours = new JsonExpression($decoded);
            }
        }

        return true;
    }

    public function afterFind(): void
    {
        parent::afterFind();

        if (is_string($this->workingHours)) {
            $this->workingHours = json_decode($this->workingHours, true) ?? [];
        }
    }
}
