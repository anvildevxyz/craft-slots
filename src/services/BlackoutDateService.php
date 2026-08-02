<?php

namespace anvildev\slots\services;

use anvildev\slots\records\BlackoutDateRecord;
use Craft;
use craft\base\Component;
use craft\db\Query;

/**
 * Manages blackout dates -- blocked date ranges during which bookings are unavailable.
 *
 * Blackouts can be scoped globally, per-employee, or per-location. The availability
 * system subtracts blackout periods when calculating open slots.
 */
class BlackoutDateService extends Component
{
    /**
     * Get all active blackout periods for a date with their associated employee/location IDs.
     * Returns all data in a single query using LEFT JOINs, eliminating N+1 queries.
     *
     * @return array Array of ['id' => int, 'locationIds' => int[], 'employeeIds' => int[]]
     */
    public function getBlackoutsForDate(string $date): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            Craft::warning("getBlackoutsForDate called with invalid date format: '{$date}' (expected Y-m-d)", __METHOD__);
            return [];
        }

        $rows = (new Query())
            ->select(['b.id AS blackoutId', 'bl.locationId', 'be.employeeId'])
            ->from(['b' => BlackoutDateRecord::tableName()])
            ->leftJoin(
                ['bl' => '{{%slots_blackout_dates_locations}}'],
                '[[bl.blackoutDateId]] = [[b.id]]'
            )
            ->leftJoin(
                ['be' => '{{%slots_blackout_dates_employees}}'],
                '[[be.blackoutDateId]] = [[b.id]]'
            )
            ->where(['b.isActive' => true])
            ->andWhere(['<=', 'b.startDate', $date])
            ->andWhere(['>=', 'b.endDate', $date])
            ->all();

        $blackouts = [];
        foreach ($rows as $row) {
            $id = (int) $row['blackoutId'];
            $blackouts[$id] ??= ['id' => $id, 'locationIds' => [], 'employeeIds' => []];

            if ($row['locationId'] !== null) {
                $blackouts[$id]['locationIds'][(int) $row['locationId']] = true;
            }
            if ($row['employeeId'] !== null) {
                $blackouts[$id]['employeeIds'][(int) $row['employeeId']] = true;
            }
        }

        return array_values(array_map(fn($b) => [
            'id' => $b['id'],
            'locationIds' => array_keys($b['locationIds']),
            'employeeIds' => array_keys($b['employeeIds']),
        ], $blackouts));
    }

    public function isDateBlackedOut(string $date, ?int $employeeId = null, ?int $locationId = null): bool
    {
        $blackouts = $this->getBlackoutsForDate($date);
        return !empty($blackouts) && $this->matchesAnyBlackout($blackouts, $employeeId, $locationId, $date);
    }

    /**
     * Check if any blackout applies to the given employee/location (in-memory matching).
     *
     * Global blackouts (no employee or location scope) always match. Scoped blackouts
     * match when the given employee/location overlaps with the blackout's scope. When
     * either identifier is null (unknown), scoped blackouts are conservatively treated
     * as matching — because the booking could be for any employee/location in the scope.
     *
     * @param array $blackouts Blackout records from getBlackoutsForDate()
     * @param int|null $employeeId Employee to check, or null (conservative: assumes match)
     * @param int|null $locationId Location to check, or null (conservative: assumes match)
     * @param string $date Used for log messages only; matching is done by the blackout date objects
     */
    public function matchesAnyBlackout(array $blackouts, ?int $employeeId, ?int $locationId, string $date = ''): bool
    {
        foreach ($blackouts as $blackout) {
            $allLocations = empty($blackout['locationIds']);
            $allEmployees = empty($blackout['employeeIds']);

            // Global blackout (no scoping) — always applies
            if ($allLocations && $allEmployees) {
                Craft::debug("Date $date is blacked out (global)", __METHOD__);
                return true;
            }

            // Location match: true if blackout has no location scope, if the given
            // location is in the blackout's list, or conservatively if locationId is
            // unknown (null) — because the booking could be at any of the scoped locations.
            $locMatch = $allLocations || ($locationId !== null && in_array($locationId, $blackout['locationIds']));
            if ($locationId === null && !$allLocations) {
                $locMatch = true;
                Craft::info("Conservative blackout match: locationId is null, treating location-scoped blackout as potentially applying for date {$date}", __METHOD__);
            }

            // Employee match: true if blackout has no employee scope, if the given
            // employee is in the blackout's list, or conservatively if employeeId is
            // unknown (null) — because the booking could be for any of the scoped employees.
            $empMatch = $allEmployees || ($employeeId !== null && in_array($employeeId, $blackout['employeeIds']));
            if ($employeeId === null && !$allEmployees) {
                $empMatch = true;
                Craft::info("Conservative blackout match: employeeId is null, treating employee-scoped blackout as potentially applying for date {$date}", __METHOD__);
            }

            if ($locMatch && $empMatch) {
                Craft::debug("Date $date is blacked out (Employee: $employeeId, Location: $locationId)", __METHOD__);
                return true;
            }
        }

        return false;
    }
}
