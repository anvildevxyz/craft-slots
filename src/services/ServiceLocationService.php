<?php

namespace anvildev\slots\services;

use anvildev\slots\elements\Location;
use anvildev\slots\records\ServiceLocationRecord;
use Craft;
use craft\base\Component;

/**
 * Manages the many-to-many relationship between services and locations.
 *
 * Allows services (especially employee-less ones using service-level schedules)
 * to be directly associated with locations, bypassing the Service → Employee → Location chain.
 */
class ServiceLocationService extends Component
{
    /**
     * Get all locations assigned to a service.
     *
     * @return Location[]
     */
    public function getLocationsForService(int $serviceId): array
    {
        $locationIds = ServiceLocationRecord::find()
            ->select('locationId')
            ->where(['serviceId' => $serviceId])
            ->column();

        if (empty($locationIds)) {
            return [];
        }

        return Location::find()->siteId('*')->id($locationIds)->all();
    }

    /**
     * Set the locations for a service (delete-all + re-insert).
     *
     * @param int[] $locationIds
     */
    public function setLocationsForService(int $serviceId, array $locationIds): bool
    {
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            ServiceLocationRecord::deleteAll(['serviceId' => $serviceId]);

            foreach ($locationIds as $locationId) {
                $record = new ServiceLocationRecord();
                $record->serviceId = $serviceId;
                $record->locationId = $locationId;
                if (!$record->save()) {
                    $transaction->rollBack();
                    return false;
                }
            }

            $transaction->commit();
            return true;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Craft::error("Failed to set locations for service {$serviceId}: " . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * Batch-load location IDs for multiple services (avoids N+1).
     *
     * @param int[] $serviceIds
     * @return array<int, int[]> Keyed by serviceId
     */
    public function getLocationIdMapForServices(array $serviceIds): array
    {
        if (empty($serviceIds)) {
            return [];
        }

        $rows = ServiceLocationRecord::find()
            ->select(['serviceId', 'locationId'])
            ->where(['serviceId' => $serviceIds])
            ->asArray()
            ->all();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['serviceId']][] = (int) $row['locationId'];
        }

        return $map;
    }
}
