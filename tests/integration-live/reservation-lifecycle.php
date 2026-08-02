<?php

/**
 * Trash, restore and inspect one reservation against a live install.
 *
 * A companion to the lifecycle browser smoke, which needs to exercise Craft's
 * soft-delete path — something no HTTP request from the control panel exposes
 * on its own. It exists as a file rather than an inline `php -r` because a
 * one-liner full of `$element` and `$app` is at the mercy of the shell that
 * carries it, and a mangled command that quietly does nothing reads exactly
 * like a passing test.
 *
 * Usage (from the project root):
 *   ddev exec php plugins/slots/tests/integration-live/reservation-lifecycle.php trash <id>
 *   ddev exec php plugins/slots/tests/integration-live/reservation-lifecycle.php restore <id>
 *   ddev exec php plugins/slots/tests/integration-live/reservation-lifecycle.php state <id>
 *   ddev exec php plugins/slots/tests/integration-live/reservation-lifecycle.php seed <hoursFromNow> <email> [quantity] [serviceId]
 *
 * Exits non-zero on anything unexpected, so a caller that ignores output still
 * notices.
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

$command = $argv[1] ?? '';

// `seed` takes an offset and an address rather than an id — it is what creates one.
if ($command === 'seed') {
    $hoursFromNow = (float)($argv[2] ?? 2);
    $email = (string)($argv[3] ?? '');
    $quantity = max(1, (int)($argv[4] ?? 1));
    $serviceId = (int)($argv[5] ?? 0);

    if ($email === '') {
        fwrite(STDERR, "Usage: reservation-lifecycle.php seed <hoursFromNow> <email>\n");
        exit(1);
    }

    $service = $serviceId
        ? \anvildev\slots\elements\Service::find()->siteId('*')->id($serviceId)->one()
        : \anvildev\slots\elements\Service::find()->siteId('*')->one();
    if (!$service) {
        fwrite(STDERR, "No service to book against\n");
        exit(1);
    }

    $when = new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone()));
    $when->modify(sprintf('%+d minutes', (int)round($hoursFromNow * 60)));
    $end = (clone $when)->modify('+' . (int)($service->duration ?? 60) . ' minutes');

    // Placed directly rather than booked through the wizard: the point is to
    // land a booking at a chosen distance from now, which availability would
    // not let us choose.
    $reservation = new \anvildev\slots\elements\Reservation();
    $reservation->userName = 'Reminder Probe';
    $reservation->userEmail = $email;
    $reservation->bookingDate = $when->format('Y-m-d');
    $reservation->startTime = $when->format('H:i');
    $reservation->endTime = $end->format('H:i');
    $reservation->status = \anvildev\slots\records\ReservationRecord::STATUS_CONFIRMED;
    $reservation->serviceId = $service->id;
    $reservation->quantity = $quantity;
    $reservation->emailReminder24hSent = false;
    $reservation->emailReminder1hSent = false;

    if (!Craft::$app->getElements()->saveElement($reservation, false)) {
        fwrite(STDERR, 'Could not seed: ' . json_encode($reservation->getErrors()) . "\n");
        exit(1);
    }

    echo json_encode([
        'id' => (int)$reservation->id,
        'bookingDate' => $reservation->bookingDate,
        'startTime' => $reservation->startTime,
        'quantity' => (int)$reservation->quantity,
        'token' => $reservation->confirmationToken,
    ]) . "\n";
    exit(0);
}

$id = (int)($argv[2] ?? 0);

if ($id <= 0) {
    fwrite(STDERR, "Usage: reservation-lifecycle.php trash|restore|state <reservationId>\n");
    exit(1);
}

$find = static fn(bool $trashed) => \anvildev\slots\elements\Reservation::find()
    ->siteId('*')
    ->status(null)
    ->trashed($trashed)
    ->id($id)
    ->one();

/** Row present, slot key held, and whether the element is in the trash. */
$state = static function() use ($id): array {
    $row = (new \craft\db\Query())
        ->select(['activeSlotKey', 'status', 'emailReminder24hSent', 'quantity'])
        ->from('{{%slots_reservations}}')
        ->where(['id' => $id])
        ->one();

    return [
        'row' => $row !== false && $row !== null ? 1 : 0,
        'slotKey' => $row['activeSlotKey'] ?? null,
        'status' => $row['status'] ?? null,
        'reminderSent' => isset($row['emailReminder24hSent']) ? (int)$row['emailReminder24hSent'] : null,
        'quantity' => isset($row['quantity']) ? (int)$row['quantity'] : null,
        'dateDeleted' => (new \craft\db\Query())
            ->select(['dateDeleted'])
            ->from('{{%elements}}')
            ->where(['id' => $id])
            ->scalar() ?: null,
    ];
};

$report = static function(array $s): void {
    echo json_encode([
        'row' => $s['row'],
        'slotKey' => $s['slotKey'],
        'status' => $s['status'],
        'reminderSent' => $s['reminderSent'],
        'quantity' => $s['quantity'],
        'trashed' => $s['dateDeleted'] !== null ? 1 : 0,
    ]) . "\n";
};

switch ($command) {
    case 'state':
        $report($state());
        break;

    case 'trash':
        $element = $find(false);
        if (!$element) {
            fwrite(STDERR, "Reservation {$id} not found (already trashed?)\n");
            exit(1);
        }
        if (!Craft::$app->getElements()->deleteElement($element)) {
            fwrite(STDERR, "Could not trash reservation {$id}\n");
            exit(1);
        }
        $report($state());
        break;

    case 'restore':
        $element = $find(true);
        if (!$element) {
            fwrite(STDERR, "No trashed reservation {$id} to restore\n");
            exit(1);
        }
        if (!Craft::$app->getElements()->restoreElement($element)) {
            fwrite(STDERR, "Could not restore reservation {$id}\n");
            exit(1);
        }
        $report($state());
        break;

    default:
        fwrite(STDERR, "Unknown command '{$command}'\n");
        exit(1);
}
