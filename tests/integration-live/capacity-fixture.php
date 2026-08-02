<?php

/**
 * Fixture CLI for the schedule-capacity browser E2E.
 *
 * Lets the Playwright spec (which runs on the host) drive database state inside
 * the container without embedding SQL in the test:
 *
 *   ddev exec php plugins/slots/tests/integration-live/capacity-fixture.php pick-date
 *   ddev exec php plugins/slots/tests/integration-live/capacity-fixture.php capacity 3
 *   ddev exec php plugins/slots/tests/integration-live/capacity-fixture.php seed 2 --date=2026-08-07
 *   ddev exec php plugins/slots/tests/integration-live/capacity-fixture.php reset
 *
 * Options are passed as flags, not environment variables — `ddev exec` does not
 * forward the caller's environment into the container, so an env-var date would
 * silently fall back to the default and seed the wrong day.
 *
 *   --date=YYYY-MM-DD   day to seed (default: today + 30)
 *   --service=<id>      service under test (default: 1236)
 *   --time=HH:MM        slot to fill (default: 09:00)
 *
 * `reset` clears the seeded bookings and restores the schedule's original
 * capacity, so the dev database is left exactly as it was found.
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\slots\Slots;
use anvildev\slots\elements\Service;

const MARKER = 'issue85-e2e';
/** Where the original per-day capacities are parked while the E2E runs. */
const BACKUP_PATH = '/tmp/slots-issue85-capacity-backup.json';

$db = Craft::$app->getDb();

$flags = [];
$positional = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m)) {
        $flags[$m[1]] = $m[2];
    } else {
        $positional[] = $arg;
    }
}

$command = $positional[0] ?? '';
$argument = $positional[1] ?? null;

$serviceId = (int)($flags['service'] ?? 1236);
$date = $flags['date'] ?? date('Y-m-d', strtotime('+30 days'));
$slotTime = $flags['time'] ?? '09:00';

$service = Service::find()->siteId('*')->id($serviceId)->status(null)->one();
if (!$service) {
    fwrite(STDERR, "Service {$serviceId} not found\n");
    exit(1);
}

// Only the capacity-mutating commands need a service-level schedule. `pick-date`
// is also used against employee-backed services, which have none — their staff
// carry their own schedules.
$scheduleId = (int)$db->createCommand(
    'SELECT scheduleId FROM {{%slots_service_schedule_assignments}} WHERE serviceId = :s ORDER BY sortOrder LIMIT 1',
    [':s' => $serviceId],
)->queryScalar();

if (!$scheduleId && in_array($command, ['capacity', 'reset'], true)) {
    fwrite(STDERR, "Service {$serviceId} has no assigned schedule to set a capacity on\n");
    exit(1);
}

$readWorkingHours = fn(): array => json_decode((string)$db->createCommand(
    'SELECT workingHours FROM {{%slots_schedules}} WHERE id = :id',
    [':id' => $scheduleId],
)->queryScalar(), true) ?: [];

// The availability calendar endpoint caches per-day bookability for 5 minutes,
// so every state change here has to drop it or the browser keeps seeing the
// day as it was before the fixture ran.
$flushCaches = function() {
    Craft::$app->getCache()->flush();
    Slots::getInstance()->getScheduleAssignment()->clearServiceScheduleCache();
    Slots::getInstance()->getAvailability()->clearSlotCache();
};

// workingHours is a JSON column — write arrays, never pre-encoded strings.
$writeWorkingHours = function(array $hours) use ($db, $scheduleId, $flushCaches) {
    $db->createCommand()->update('{{%slots_schedules}}', ['workingHours' => $hours], ['id' => $scheduleId])->execute();
    $flushCaches();
};

$clearBookings = function() use ($db, $flushCaches, $serviceId, $date) {
    // Delete the elements, not the rows. Deleting from slots_reservations alone
    // leaves an orphan elements row behind for every seeded booking, and those
    // accumulate silently across runs.
    $ids = $db->createCommand(
        'SELECT [[id]] FROM {{%slots_reservations}} WHERE [[userEmail]] LIKE :marker',
        [':marker' => MARKER . '%'],
    )->queryColumn();

    $deleted = 0;
    foreach ($ids as $id) {
        if (Craft::$app->getElements()->deleteElementById((int)$id, null, null, true)) {
            $deleted++;
        }
    }

    // Selecting a slot in the wizard takes a soft lock, and a held seat legitimately
    // counts against remaining capacity. Release the ones for the day under test so
    // a previous scenario's selection doesn't leak into the next one's arithmetic.
    $db->createCommand()->delete('{{%slots_soft_locks}}', ['serviceId' => $serviceId, 'date' => $date])->execute();

    $flushCaches();
    return $deleted;
};

switch ($command) {
    case 'pick-date':
        // The browser can only reach days the availability calendar marks bookable:
        // inside its 90-day horizon, not blacked out, and actually carrying slots.
        // The probe slot must also be untouched — the dev database already holds
        // bookings that would otherwise be mistaken for the ones seeded here.
        $plugin = Slots::getInstance();
        for ($offset = 7; $offset <= 85; $offset++) {
            $candidate = date('Y-m-d', strtotime("+{$offset} days"));
            if ($plugin->getBlackoutDate()->isDateBlackedOut($candidate, null, null)) {
                continue;
            }

            $plugin->getAvailability()->clearSlotCache();
            $candidateSlots = $plugin->getAvailability()->getAvailableSlots($candidate, null, null, $serviceId);

            // `--time=any` just wants a day that has availability at all, which is
            // what employee-backed services need — they keep their own shifts and
            // may never open at the group service's probe time.
            if ($slotTime === 'any') {
                if (!empty($candidateSlots)) {
                    echo $candidate;
                    exit(0);
                }
                continue;
            }

            foreach ($candidateSlots as $slot) {
                if (($slot['time'] ?? '') !== $slotTime) {
                    continue;
                }
                if ($slot['availableCapacity'] !== null && $slot['availableCapacity'] === $slot['maxCapacity']) {
                    echo $candidate;
                    exit(0);
                }
            }
        }
        fwrite(STDERR, "No date with an unbooked {$slotTime} slot for service {$serviceId} within the calendar horizon\n");
        exit(1);

    case 'capacity':
        if (!file_exists(BACKUP_PATH)) {
            file_put_contents(BACKUP_PATH, json_encode($readWorkingHours()));
        }
        $capacity = $argument === 'null' ? null : (int)$argument;
        $hours = $readWorkingHours();
        foreach (array_keys($hours) as $day) {
            $hours[$day]['capacity'] = $capacity;
        }
        $writeWorkingHours($hours);
        echo "capacity set to " . var_export($capacity, true) . " on schedule {$scheduleId}\n";
        break;

    case 'break':
        // Sets or clears the daily break on the probe schedule. A spec that
        // asserts a slot is *missing* has to put the break there itself —
        // otherwise it only passes on a site that happens to have one, and
        // fails everywhere else for a reason that isn't a bug.
        if (!file_exists(BACKUP_PATH)) {
            file_put_contents(BACKUP_PATH, json_encode($readWorkingHours()));
        }
        $hours = $readWorkingHours();
        foreach (array_keys($hours) as $day) {
            if ($argument === 'off') {
                $hours[$day]['breakStart'] = null;
                $hours[$day]['breakEnd'] = null;
            } else {
                [$from, $to] = array_pad(explode('-', (string)$argument), 2, null);
                $hours[$day]['breakStart'] = $from;
                $hours[$day]['breakEnd'] = $to;
            }
        }
        $writeWorkingHours($hours);
        echo 'break ' . ($argument === 'off' ? 'cleared' : "set to {$argument}") . " on schedule {$scheduleId}\n";
        break;

    case 'seed':
        $count = max(1, (int)($argument ?? 1));
        $startTime = $slotTime;
        $endTime = date('H:i:s', strtotime($startTime) + (int)($service->duration ?? 60) * 60);
        // Reservations are Craft elements, so their primary key has to be an
        // `elements` row. A raw insert here used to work and now trips the
        // foreign key — saving the element creates both halves and keeps the
        // seeded bookings identical to ones a customer would make.
        for ($i = 0; $i < $count; $i++) {
            $reservation = new \anvildev\slots\elements\Reservation();
            $reservation->userName = 'Issue 85 E2E';
            $reservation->userEmail = MARKER . '-' . $i . '@example.test';
            $reservation->bookingDate = $date;
            $reservation->startTime = $startTime . ':00';
            $reservation->endTime = $endTime;
            $reservation->status = 'confirmed';
            $reservation->serviceId = $serviceId;
            $reservation->quantity = 1;
            $reservation->confirmationToken = MARKER . bin2hex(random_bytes(16));

            if (!Craft::$app->getElements()->saveElement($reservation, false)) {
                fwrite(STDERR, "Failed to seed booking: " . json_encode($reservation->getErrors()) . "\n");
                exit(1);
            }
        }
        $flushCaches();
        echo "seeded {$count} booking(s) at {$startTime} on {$date}\n";
        break;

    case 'throttle':
        // The anti-bot rule allows one booking per IP every few seconds. A browser
        // suite books repeatedly from one address, so the throttle refuses bookings
        // for reasons that have nothing to do with capacity. Toggled off for a run
        // and back on afterwards.
        $enabled = $argument !== 'off';
        $db->createCommand()->update('{{%slots_settings}}', ['enableTimeBasedLimits' => $enabled ? 1 : 0], ['id' => 1])->execute();
        $flushCaches();
        echo 'time-based booking limits ' . ($enabled ? 'enabled' : 'disabled') . "\n";
        break;

    case 'ratelimit':
        // Separate from `throttle`: that one is the few-second anti-bot delay,
        // this is the per-IP booking cap (10 by default). A suite that books a
        // dozen times from one address exhausts it midway and the remaining
        // bookings 400 for a reason that has nothing to do with what is tested.
        $enabled = $argument !== 'off';
        $db->createCommand()->update('{{%slots_settings}}', ['enableRateLimiting' => $enabled ? 1 : 0], ['id' => 1])->execute();
        $flushCaches();
        echo 'per-IP rate limiting ' . ($enabled ? 'enabled' : 'disabled') . "\n";
        break;

    case 'count':
        // Live bookings on the probe slot, however they were made — the browser
        // suite uses this to assert an invariant rather than infer one from the UI.
        echo (int)$db->createCommand(
            'SELECT COALESCE(SUM(quantity), 0) FROM {{%slots_reservations}}'
            . ' WHERE bookingDate = :d AND startTime = :t AND serviceId = :s AND status <> :c',
            [':d' => $date, ':t' => $slotTime . ':00', ':s' => $serviceId, ':c' => 'cancelled'],
        )->queryScalar();
        break;

    case 'clear':
        echo 'cleared ' . $clearBookings() . " booking(s)\n";
        break;

    case 'reset':
        $cleared = $clearBookings();
        if (file_exists(BACKUP_PATH)) {
            $writeWorkingHours(json_decode((string)file_get_contents(BACKUP_PATH), true) ?: []);
            unlink(BACKUP_PATH);
            echo "restored original schedule capacity\n";
        }
        echo "cleared {$cleared} booking(s)\n";
        break;

    default:
        fwrite(STDERR, "Usage: capacity-fixture.php pick-date | capacity <n|null> | break <from-to|off> | seed <count> | throttle <on|off> | ratelimit <on|off> | clear | reset"
            . " [--date=YYYY-MM-DD] [--service=<id>] [--time=HH:MM]\n");
        exit(1);
}
