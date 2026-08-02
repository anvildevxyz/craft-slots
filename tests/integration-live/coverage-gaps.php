<?php

/**
 * The parts of the plugin nothing else exercises.
 *
 * Three areas the browser suites and payments.sh both miss:
 *
 *   1. Multi-site. Service is the only localized element; everything else is
 *      global and must be queried with siteId('*'). A query that forgets that
 *      returns nothing from a non-primary site, which looks like empty data
 *      rather than a bug.
 *   2. Availability rules beyond one schedule shape — slot length independent of
 *      duration, the minimum notice period, and the booking-window ceiling.
 *   3. Capacity limits at their boundary, where off-by-one lives.
 *
 * Usage (from the project root):
 *   ddev exec php plugins/slots/tests/integration-live/coverage-gaps.php
 *
 * Seeds and cleans up after itself. Exits non-zero on any failure.
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\slots\elements\Employee;
use anvildev\slots\elements\Location;
use anvildev\slots\elements\Reservation;
use anvildev\slots\elements\Schedule;
use anvildev\slots\elements\Service;
use anvildev\slots\records\ReservationRecord;
use anvildev\slots\Slots;

$failures = 0;
$made = [];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, $detail !== '' ? " — {$detail}" : '');
}

$elements = Craft::$app->getElements();
$sites = Craft::$app->getSites()->getAllSites();
$primary = Craft::$app->getSites()->getPrimarySite();
$secondary = null;
foreach ($sites as $s) {
    if ($s->id !== $primary->id) {
        $secondary = $s;
        break;
    }
}

// ============================================================ 1. multi-site
echo "Multi-site (" . count($sites) . " sites: " . implode(', ', array_map(fn($s) => $s->handle, $sites)) . ")\n";

$svc = new Service();
$svc->title = 'GAPS Localized Service';
$svc->duration = 60;
$svc->price = 10;
$svc->siteId = $primary->id;
$elements->saveElement($svc);
$made[] = $svc;

check('a service saves on the primary site', Service::find()->siteId($primary->id)->id($svc->id)->one() !== null);

if ($secondary) {
    // propagationMethod defaults to None, so a service belongs to the site it was
    // created on until an author says otherwise. Both halves of that matter.
    check(
        "with propagation None it stays off '{$secondary->handle}'",
        Service::find()->siteId($secondary->id)->id($svc->id)->one() === null,
        'it propagated anyway',
    );

    $svc->propagationMethod = \craft\enums\PropagationMethod::All;
    $elements->saveElement($svc);

    $onSecondary = Service::find()->siteId($secondary->id)->id($svc->id)->one();
    check(
        "with propagation All it reaches '{$secondary->handle}'",
        $onSecondary !== null,
        $onSecondary ? "title=\"{$onSecondary->title}\"" : 'still not found',
    );

    if ($onSecondary) {
        // Localized means the title is per-site: editing one must not overwrite
        // the other, which is exactly what propagation bugs break.
        $onSecondary->title = 'GAPS Localized Service (FR)';
        $elements->saveElement($onSecondary);
        $reloadPrimary = Service::find()->siteId($primary->id)->id($svc->id)->one();
        check(
            'a per-site title edit does not leak to the other site',
            $reloadPrimary->title === 'GAPS Localized Service',
            "primary title is now \"{$reloadPrimary->title}\"",
        );
    }
}

// Global elements must be reachable regardless of the site being queried from.
$loc = new Location();
$loc->title = 'GAPS Location';
$elements->saveElement($loc);
$made[] = $loc;

$emp = new Employee();
$emp->title = 'GAPS Employee';
$elements->saveElement($emp);
$made[] = $emp;

$fromSecondary = $secondary
    ? Location::find()->siteId('*')->id($loc->id)->one()
    : null;
check(
    'a non-localized Location is found with siteId(*)',
    $secondary ? $fromSecondary !== null : true,
    $secondary ? '' : '(single-site install, skipped)',
);
check('a non-localized Employee is found with siteId(*)', Employee::find()->siteId('*')->id($emp->id)->one() !== null);

// ================================================== 2. availability rules
echo "\nAvailability rules\n";

$sched = new Schedule();
$sched->title = 'GAPS Schedule';
$hours = [];
for ($d = 1; $d <= 7; $d++) {
    $hours[$d] = ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'breakStart' => null, 'breakEnd' => null, 'capacity' => null];
}
$sched->workingHours = $hours;
$elements->saveElement($sched);
$made[] = $sched;

Craft::$app->getDb()->createCommand()->insert('{{%slots_service_schedule_assignments}}', [
    'serviceId' => $svc->id, 'scheduleId' => $sched->id, 'sortOrder' => 1,
    'dateCreated' => date('Y-m-d H:i:s'), 'dateUpdated' => date('Y-m-d H:i:s'),
    'uid' => \craft\helpers\StringHelper::UUID(),
])->execute();

$availability = Slots::getInstance()->getAvailability();
$far = (new DateTime('+20 days'))->format('Y-m-d');

$slots = $availability->getAvailableSlots($far, null, null, $svc->id, 1);
check('a 60-minute service on 09:00–17:00 yields 8 hourly slots', count($slots) === 8, 'got ' . count($slots));

// Slot length independent of duration: 30-minute starts for a 60-minute service.
$svc->timeSlotLength = 30;
$elements->saveElement($svc, false);
$availability->clearSlotCache();
$slots30 = $availability->getAvailableSlots($far, null, null, $svc->id, 1);
$starts = array_map(fn($s) => $s['time'], $slots30);
check(
    'timeSlotLength drives the grid independently of duration',
    count($slots30) > count($slots) && in_array('09:30', $starts, true),
    count($slots30) . ' slots, includes 09:30: ' . (in_array('09:30', $starts, true) ? 'yes' : 'no'),
);
$svc->timeSlotLength = null;

// Minimum notice: nothing inside the notice window may be offered.
$svc->minTimeBeforeBooking = 48;
$elements->saveElement($svc, false);
$availability->clearSlotCache();
$soon = (new DateTime('+1 day'))->format('Y-m-d');
$soonSlots = $availability->getAvailableSlots($soon, null, null, $svc->id, 1);
check('minTimeBeforeBooking=48h removes tomorrow', $soonSlots === [], count($soonSlots) . ' slots offered');
$stillFar = $availability->getAvailableSlots($far, null, null, $svc->id, 1);
check('…while a date beyond the notice window still books', $stillFar !== [], count($stillFar) . ' slots');
$svc->minTimeBeforeBooking = null;
$elements->saveElement($svc, false);
$availability->clearSlotCache();

// Booking-window ceiling.
$settings = Slots::getInstance()->getSettings();
$originalMax = $settings->maximumAdvanceBookingDays;
Craft::$app->getDb()->createCommand()
    ->update('{{%slots_settings}}', ['maximumAdvanceBookingDays' => 10])->execute();
Craft::$app->getDb()->createCommand()->delete('{{%slots_settings}}', '0=1')->execute();
$availability->clearSlotCache();
Slots::getInstance()->getSettings()->maximumAdvanceBookingDays = 10;
$beyond = (new DateTime('+40 days'))->format('Y-m-d');
$beyondSlots = $availability->getAvailableSlots($beyond, null, null, $svc->id, 1);
check('maximumAdvanceBookingDays=10 blocks a date 40 days out', $beyondSlots === [], count($beyondSlots) . ' slots offered');
Craft::$app->getDb()->createCommand()
    ->update('{{%slots_settings}}', ['maximumAdvanceBookingDays' => $originalMax])->execute();
// Settings are memoized per request, so the in-memory copy has to go back too —
// otherwise every later check runs against a 10-day booking window.
Slots::getInstance()->getSettings()->maximumAdvanceBookingDays = $originalMax;
$availability->clearSlotCache();

// ========================================================== 3. capacity
echo "\nCapacity at the boundary\n";

$capHours = [];
for ($d = 1; $d <= 7; $d++) {
    $capHours[$d] = ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'breakStart' => null, 'breakEnd' => null, 'capacity' => 3];
}
$sched->workingHours = $capHours;
$elements->saveElement($sched, false);
$availability->clearSlotCache();

$capDate = (new DateTime('+21 days'))->format('Y-m-d');
$capSlots = $availability->getAvailableSlots($capDate, null, null, $svc->id, 1);
$first = $capSlots[0] ?? null;
check('a capacity-3 schedule reports 3 seats', ($first['availableCapacity'] ?? null) === 3, 'availableCapacity=' . var_export($first['availableCapacity'] ?? null, true));

// Take two of the three seats.
$r = new Reservation();
$r->userName = 'GAPS Group';
$r->userEmail = 'gaps@example.test';
$r->bookingDate = $capDate;
$r->startTime = '09:00';
$r->endTime = '10:00';
$r->status = ReservationRecord::STATUS_CONFIRMED;
$r->serviceId = $svc->id;
$r->quantity = 2;
$elements->saveElement($r, false);
$made[] = $r;
$availability->clearSlotCache();

$afterSlots = $availability->getAvailableSlots($capDate, null, null, $svc->id, 1);
$sameSlot = null;
foreach ($afterSlots as $s) {
    if (substr($s['time'], 0, 5) === '09:00') {
        $sameSlot = $s;
    }
}
check('two seats taken leaves one', ($sameSlot['availableCapacity'] ?? null) === 1, 'availableCapacity=' . var_export($sameSlot['availableCapacity'] ?? null, true));
check('the last seat is still bookable', $availability->isSlotAvailable($capDate, '09:00', '10:00', null, null, $svc->id, 1), 'quantity 1 refused');
check('one seat more than remains is refused', !$availability->isSlotAvailable($capDate, '09:00', '10:00', null, null, $svc->id, 2), 'quantity 2 allowed with 1 seat left');

// ========================================================== teardown
foreach (array_reverse($made) as $el) {
    $elements->deleteElement($el, true);
}

printf("\n%s\n", $failures === 0 ? 'All checks passed.' : "{$failures} check(s) FAILED.");
exit($failures === 0 ? 0 : 1);
