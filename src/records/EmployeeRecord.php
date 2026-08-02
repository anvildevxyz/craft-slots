<?php

namespace anvildev\slots\records;

use craft\db\ActiveRecord;
use yii\db\JsonExpression;

/**
 * @property int $id
 * @property int|null $userId
 * @property int|null $locationId
 * @property string|null $email
 * @property array|JsonExpression|null $workingHours
 * @property array|JsonExpression|null $serviceIds
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class EmployeeRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%slots_employees}}';
    }

    public function rules(): array
    {
        return [
            [['userId', 'locationId'], 'integer'],
            [['email'], 'email', 'skipOnEmpty' => true],
            [['email'], 'string', 'max' => 255],
            [['email', 'workingHours', 'serviceIds'], 'safe'],
        ];
    }

    public function beforeSave($insert): bool
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        foreach (['workingHours', 'serviceIds'] as $attr) {
            if (is_array($this->$attr)) {
                $this->$attr = new JsonExpression($this->$attr);
            } elseif (is_string($this->$attr)) {
                $decoded = json_decode($this->$attr, true);
                if ($decoded !== null) {
                    $this->$attr = new JsonExpression($decoded);
                }
            }
        }

        return true;
    }
}
