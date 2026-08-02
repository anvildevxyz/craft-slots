<?php

/**
 * Live integration check for issue #74 — a soft lock must consume capacity
 * units on a multi-capacity (group) slot, not blank the whole slot.
 *
 * Runs the REAL AvailabilityService against the REAL database (there is no
 * in-process Craft test harness, so this behaviour is otherwise only source-
 * scan-tested). It inserts an active soft lock, then invokes the slot soft-lock
 * filter with a capacity-10 group slot and a single-capacity slot at the same
 * time, and asserts the group slot survives with one seat consumed while the
 * single-capacity slot is still fully removed. Self-cleaning.
 *
 * Usage (from the Craft project root, DDEV):
 *   ddev exec php plugins/slots/tests/integration-live/soft-lock-capacity.php
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\slots\Slots;
use anvildev\slots\services\AvailabilityService;

$db = Craft::$app->getDb();
$serviceId = (int) (getenv('SERVICE_ID') ?: 9433);
$date = '2026-08-01';
$token = 'na74-' . uniqid();

$db->createCommand()->insert('{{%slots_soft_locks}}', [
    'token' => $token,
    'sessionHash' => str_repeat('a', 64),
    'serviceId' => $serviceId,
    'employeeId' => null,
    'locationId' => null,
    'date' => $date,
    'endDate' => $date,
    'startTime' => '10:00:00',
    'endTime' => '10:30:00',
    'quantity' => 1,
    'expiresAt' => gmdate('Y-m-d H:i:s', time() + 86400),
    'dateCreated' => gmdate('Y-m-d H:i:s'),
    'dateUpdated' => gmdate('Y-m-d H:i:s'),
    'uid' => \craft\helpers\StringHelper::UUID(),
])->execute();

$pass = 0;
$fail = 0;
$ok = function(string $msg) use (&$pass) {
    echo "  \u{2713} {$msg}\n";
    $pass++;
};
$bad = function(string $msg) use (&$fail) {
    echo "  \u{2717} {$msg}\n";
    $fail++;
};

try {
    $found = Slots::getInstance()->getSoftLock()->getActiveSoftLocksForDate($date, $serviceId, null);
    count($found) >= 1 ? $ok('active soft lock is retrievable') : $bad('inserted soft lock was not found (check expiresAt/tz)');

    $svc = new AvailabilityService();
    $slots = [
        ['time' => '10:00', 'endTime' => '10:30', 'employeeId' => null, 'locationId' => null, 'availableCapacity' => 10, 'capacity' => 10],
        ['time' => '10:00', 'endTime' => '10:30', 'employeeId' => null, 'locationId' => null, 'availableCapacity' => null, 'capacity' => 1],
    ];
    $ref = new ReflectionMethod($svc, 'filterSoftLockedSlots');
    $ref->setAccessible(true);
    $result = $ref->invoke($svc, $slots, $date, $serviceId, null, null);

    $groupCap = null;
    $singlePresent = false;
    foreach ($result as $s) {
        if (($s['capacity'] ?? null) === 10) {
            $groupCap = $s['availableCapacity'];
        }
        if (($s['capacity'] ?? null) === 1) {
            $singlePresent = true;
        }
    }
    $groupCap === 9
        ? $ok('capacity-10 group slot survives a single lock with availableCapacity=9')
        : $bad('group slot capacity wrong, expected 9 got ' . var_export($groupCap, true));
    !$singlePresent
        ? $ok('single-capacity slot is still fully removed by a lock')
        : $bad('single-capacity slot should have been removed');
} finally {
    $db->createCommand()->delete('{{%slots_soft_locks}}', ['token' => $token])->execute();
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
