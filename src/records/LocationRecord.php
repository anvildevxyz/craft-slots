<?php

namespace anvildev\slots\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string|null $timezone
 * @property string|null $addressLine1
 * @property string|null $addressLine2
 * @property string|null $locality
 * @property string|null $administrativeArea
 * @property string|null $postalCode
 * @property string|null $countryCode
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class LocationRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%slots_locations}}';
    }

    public function rules(): array
    {
        return [
            [['timezone', 'addressLine1', 'addressLine2', 'locality', 'administrativeArea', 'postalCode', 'countryCode'], 'string'],
        ];
    }
}
