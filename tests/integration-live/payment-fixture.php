<?php

/**
 * Seeds a `pending` reservation for the direct-payment suite.
 *
 * payments.sh used to INSERT straight into `slots_reservations`. That stopped
 * working when reservations became first-class Craft elements: a raw row has no
 * `elements` entry, so `ReservationFactory::findById()` — which every payment
 * endpoint authorises through — cannot see it, and `payment/create` answers 403.
 * The suite was failing on its own fixture rather than on the code under test.
 *
 * Usage (from the project root):
 *   ddev exec php plugins/slots/tests/integration-live/payment-fixture.php \
 *       <serviceId> <email> [staleMinutes]
 *
 * `staleMinutes` backdates `dateCreated` so the pending-payment garbage
 * collector treats the booking as abandoned; omit it for a fresh one.
 *
 * Prints {"id":…,"token":…} and exits non-zero if the reservation would not save.
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\slots\elements\Reservation;
use anvildev\slots\elements\Service;
use anvildev\slots\records\ReservationRecord;

$serviceId = (int)($argv[1] ?? 0);
$email = (string)($argv[2] ?? '');
$staleMinutes = (int)($argv[3] ?? 0);

if ($serviceId <= 0 || $email === '') {
    fwrite(STDERR, "Usage: payment-fixture.php <serviceId> <email> [staleMinutes]\n");
    exit(1);
}

$service = Service::find()->siteId('*')->id($serviceId)->one();
if (!$service) {
    fwrite(STDERR, "No service #{$serviceId}\n");
    exit(1);
}

$reservation = new Reservation();
$reservation->userName = 'Payments Probe';
$reservation->userEmail = $email;
$reservation->bookingDate = '2026-09-20';
$reservation->startTime = '10:00';
$reservation->endTime = '11:00';
$reservation->status = ReservationRecord::STATUS_PENDING;
$reservation->serviceId = $service->id;
$reservation->quantity = 1;

// Validation off: the point is to land a pending booking at a fixed date, which
// availability rules would not necessarily allow.
if (!Craft::$app->getElements()->saveElement($reservation, false)) {
    fwrite(STDERR, 'Could not seed: ' . json_encode($reservation->getErrors()) . "\n");
    exit(1);
}

if ($staleMinutes > 0) {
    // The collector filters on slots_reservations.dateCreated, so that is the
    // column that has to look old.
    $stale = (new DateTime("-{$staleMinutes} minutes"))->format('Y-m-d H:i:s');
    Craft::$app->getDb()->createCommand()
        ->update('{{%slots_reservations}}', ['dateCreated' => $stale], ['id' => $reservation->id])
        ->execute();
}

echo json_encode([
    'id' => (int)$reservation->id,
    'token' => $reservation->confirmationToken,
]) . "\n";
exit(0);
