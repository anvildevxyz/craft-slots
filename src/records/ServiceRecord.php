<?php

namespace anvildev\slots\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $propagationMethod
 * @property string|null $description
 * @property int|null $duration
 * @property int|null $bufferBefore
 * @property int|null $bufferAfter
 * @property float|null $price
 * @property bool $allowCancellation
 * @property int|null $cancellationPolicyHours
 * @property bool $allowRefund
 * @property int|null $minTimeBeforeBooking
 * @property int|null $timeSlotLength
 * @property bool $customerLimitEnabled
 * @property int|null $customerLimitCount
 * @property string|null $customerLimitPeriod
 * @property string|null $customerLimitPeriodType
 * @property array|string|null $availabilitySchedule
 * @property string|null $refundTiers
 * @property int|null $taxCategoryId
 * @property string|null $deletedAt
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class ServiceRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%slots_services}}';
    }

    public function beforeSave($insert): bool
    {
        if (is_array($this->availabilitySchedule)) {
            $this->availabilitySchedule = json_encode($this->availabilitySchedule);
        }
        return parent::beforeSave($insert);
    }

    public function afterFind(): void
    {
        parent::afterFind();
        if (is_string($this->availabilitySchedule)) {
            $this->availabilitySchedule = json_decode($this->availabilitySchedule, true);
        }
    }

    public function rules(): array
    {
        return [
            [['description'], 'string'],
            [['duration', 'bufferBefore', 'bufferAfter'], 'integer', 'min' => 0],
            [['price'], 'number', 'min' => 0],
            [['cancellationPolicyHours'], 'integer', 'min' => 0],
            [['minTimeBeforeBooking'], 'integer', 'min' => 0],
            [['timeSlotLength'], 'integer', 'min' => 5],
            [['customerLimitEnabled'], 'boolean'],
            [['customerLimitCount'], 'integer', 'min' => 1],
            [['customerLimitPeriod', 'customerLimitPeriodType'], 'string', 'max' => 20],
            [['availabilitySchedule'], 'safe'],
        ];
    }
}
