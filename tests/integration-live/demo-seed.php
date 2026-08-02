<?php

/**
 * Seeds presentable demo data for the Plugin Store screenshots.
 *
 * The dev site's fixtures are named for the suites that made them — WIZSMOKE
 * Consultation, Wizard Smoke — which is fine for testing and unusable in a
 * public listing. This lays down a small, plausible salon on top: one location,
 * two practitioners, three services, a weekly schedule, and bookings spread
 * across the statuses so the index and the reports have something to show.
 *
 * Usage (from the project root):
 *   ddev exec php plugins/slots/tests/integration-live/demo-seed.php seed
 *   ddev exec php plugins/slots/tests/integration-live/demo-seed.php clean
 *
 * Everything it creates is titled with the DEMO_TAG prefix, so `clean` removes
 * exactly what `seed` made and nothing else.
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
use craft\helpers\StringHelper;

const DEMO_TAG = 'Aurora';

$command = $argv[1] ?? 'seed';
$elements = Craft::$app->getElements();

/** Remove everything a previous seed left behind, bookings first. */
function clean(): int
{
    $elements = Craft::$app->getElements();
    $removed = 0;

    // Anything booked against a demo service counts as demo data, however it
    // got there — capturing the screenshots books through the wizard, and that
    // booking is what blocks the service from being deleted afterwards.
    $demoServiceIds = Service::find()->siteId('*')->status(null)->title(DEMO_TAG . '*')->ids();
    foreach (Reservation::find()->siteId('*')->status(null)->all() as $r) {
        $isDemo = str_starts_with((string)$r->userEmail, 'demo+')
            || in_array((int)$r->serviceId, array_map('intval', $demoServiceIds), true);
        if ($isDemo) {
            Craft::$app->getDb()->createCommand()
                ->delete('{{%slots_payments}}', ['reservationId' => $r->id])->execute();
            $elements->deleteElement($r, true);
            $removed++;
        }
    }
    // Services refuse deletion while bookings reference them, so they go last.
    foreach ([Employee::class, Schedule::class, Service::class, Location::class] as $cls) {
        foreach ($cls::find()->siteId('*')->title(DEMO_TAG . '*')->all() as $e) {
            $elements->deleteElement($e, true);
            $removed++;
        }
    }
    return $removed;
}

if ($command === 'clean') {
    echo "removed " . clean() . " demo element(s)\n";
    exit(0);
}

clean();

$location = new Location();
$location->title = DEMO_TAG . ' Studio';
$location->timezone = 'Europe/Zurich';
foreach (['address' => 'Bahnhofstrasse 12', 'city' => 'Zürich', 'postalCode' => '8001'] as $attr => $value) {
    if (property_exists($location, $attr)) {
        $location->$attr = $value;
    }
}
$elements->saveElement($location);

$schedule = new Schedule();
$schedule->title = DEMO_TAG . ' Opening Hours';
$hours = [];
for ($d = 1; $d <= 5; $d++) {
    $hours[$d] = ['enabled' => true, 'start' => '09:00', 'end' => '18:00', 'breakStart' => '12:30', 'breakEnd' => '13:30', 'capacity' => null];
}
$hours[6] = ['enabled' => true, 'start' => '10:00', 'end' => '15:00', 'breakStart' => null, 'breakEnd' => null, 'capacity' => null];
$hours[7] = ['enabled' => false];
$schedule->workingHours = $hours;
$elements->saveElement($schedule);

$practitioners = [];
foreach (['Mara Feldman', 'Jonas Roth'] as $name) {
    $e = new Employee();
    $e->title = DEMO_TAG . ' — ' . $name;
    $e->email = 'demo+' . strtolower(str_replace(' ', '.', $name)) . '@example.com';
    if (property_exists($e, 'locationId')) {
        $e->locationId = $location->id;
    }
    $elements->saveElement($e);
    $practitioners[] = $e;

    Craft::$app->getDb()->createCommand()->insert('{{%slots_employee_schedule_assignments}}', [
        'employeeId' => $e->id, 'scheduleId' => $schedule->id, 'sortOrder' => 1,
        'dateCreated' => date('Y-m-d H:i:s'), 'dateUpdated' => date('Y-m-d H:i:s'),
        'uid' => StringHelper::UUID(),
    ])->execute();
}

$catalogue = [
    ['Deep Tissue Massage', 60, 120.00, 10, 10],
    ['Sports Therapy', 45, 95.00, 5, 5],
    ['First Consultation', 30, 45.00, 0, 5],
];
$services = [];
foreach ($catalogue as [$title, $duration, $price, $before, $after]) {
    $s = new Service();
    $s->title = DEMO_TAG . ' ' . $title;
    $s->description = 'A ' . strtolower($title) . ' with one of our practitioners.';
    $s->duration = $duration;
    $s->price = $price;
    $s->bufferBefore = $before;
    $s->bufferAfter = $after;
    $s->timeSlotLength = $duration;
    $s->allowCancellation = true;
    $s->cancellationPolicyHours = 24;
    $s->allowRefund = true;
    $s->refundTiers = [
        ['hoursBeforeStart' => 48, 'refundPercentage' => 100],
        ['hoursBeforeStart' => 24, 'refundPercentage' => 50],
    ];
    $elements->saveElement($s);
    $services[] = $s;

    Craft::$app->getDb()->createCommand()->insert('{{%slots_service_schedule_assignments}}', [
        'serviceId' => $s->id, 'scheduleId' => $schedule->id, 'sortOrder' => 1,
        'dateCreated' => date('Y-m-d H:i:s'), 'dateUpdated' => date('Y-m-d H:i:s'),
        'uid' => StringHelper::UUID(),
    ])->execute();
}

// A spread of bookings so the index, the calendar and the report all have shape.
$people = [
    ['Elena Vasquez', 'confirmed', '+3 days', '10:00', 0],
    ['Thomas Brandt', 'confirmed', '+3 days', '14:00', 1],
    ['Priya Raman', 'confirmed', '+4 days', '09:00', 0],
    ['Andreas Keller', 'pending', '+4 days', '15:00', 2],
    ['Sofia Lindqvist', 'confirmed', '+5 days', '11:00', 1],
    ['Marcus Chen', 'cancelled', '+5 days', '16:00', 2],
    ['Hannah Weber', 'confirmed', '+6 days', '10:00', 0],
    ['Liam O’Connor', 'confirmed', '-4 days', '14:00', 1],
    ['Ines Moreau', 'no_show', '-6 days', '11:00', 2],
    ['David Okafor', 'confirmed', '-8 days', '15:00', 0],
];

$made = 0;
foreach ($people as [$name, $status, $when, $time, $svcIndex]) {
    $service = $services[$svcIndex];
    $day = (new DateTime($when))->format('Y-m-d');
    $start = new DateTime("{$day} {$time}");
    $end = (clone $start)->modify('+' . $service->duration . ' minutes');

    $r = new Reservation();
    $r->userName = $name;
    $r->userEmail = 'demo+' . strtolower(explode(' ', $name)[0]) . '@example.com';
    $r->userPhone = '+41 44 555 0' . str_pad((string)($made + 10), 3, '0', STR_PAD_LEFT);
    $r->bookingDate = $day;
    $r->startTime = $start->format('H:i');
    $r->endTime = $end->format('H:i');
    $r->status = $status;
    $r->serviceId = $service->id;
    $r->employeeId = $practitioners[$made % 2]->id;
    $r->quantity = 1;
    // The studio is in Zürich; leaving the server's zone here shows a Toronto
    // timezone on a Swiss booking in the control panel.
    $r->userTimezone = 'Europe/Zurich';
    $elements->saveElement($r, false);
    $made++;
}

printf(
    "seeded: 1 location, %d practitioners, %d services, 1 schedule, %d bookings\n",
    count($practitioners),
    count($services),
    $made,
);
printf("first service id: %d\n", $services[0]->id);
