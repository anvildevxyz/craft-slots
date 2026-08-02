<?php

namespace anvildev\slots\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * Junction table linking services to locations (many-to-many).
 *
 * @property int $id
 * @property int $serviceId
 * @property int $locationId
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class ServiceLocationRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%slots_service_locations}}';
    }

    public function getService(): ActiveQueryInterface
    {
        return $this->hasOne(\craft\records\Element::class, ['id' => 'serviceId']);
    }

    public function getLocation(): ActiveQueryInterface
    {
        return $this->hasOne(LocationRecord::class, ['id' => 'locationId']);
    }
}
