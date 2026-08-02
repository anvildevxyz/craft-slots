<?php

/**
 * Refunds initiated by the plugin, against Stripe test mode.
 *
 * payments.sh covers a refund issued in the Stripe dashboard flowing back in.
 * This covers the other direction — PaymentService::refund() — plus the policy
 * clamping that decides how much a customer is actually owed, which is where
 * refund tiers finally do something. Nothing else exercises that: the tier maths
 * is pure until a real payment exists to refund against.
 *
 * Usage (from the project root):
 *   ddev exec php plugins/slots/tests/integration-live/refund-flow.php <serviceId>
 *
 * Seeds and cleans up after itself. Exits non-zero on any failure.
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\slots\elements\Reservation;
use anvildev\slots\elements\Service;
use anvildev\slots\records\PaymentRecord;
use anvildev\slots\records\ReservationRecord;
use anvildev\slots\services\PaymentService;
use anvildev\slots\Slots;

$serviceId = (int)($argv[1] ?? 0);
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

$service = $serviceId
    ? Service::find()->siteId('*')->id($serviceId)->one()
    : Service::find()->siteId('*')->one();
if (!$service) {
    fwrite(STDERR, "No service to test against\n");
    exit(1);
}

$stripe = new \Stripe\StripeClient(\craft\helpers\App::parseEnv(Slots::getInstance()->getSettings()->stripeSecretKey));

/** Place a confirmed booking with a real, paid Stripe intent behind it. */
function paidBooking(Service $service, int $amountMinor, string $currency, \Stripe\StripeClient $stripe, string $when): array
{
    $r = new Reservation();
    $r->userName = 'Refund Probe';
    $r->userEmail = 'refund@example.test';
    $r->bookingDate = (new DateTime($when))->format('Y-m-d');
    $r->startTime = '10:00';
    $r->endTime = '11:00';
    $r->status = ReservationRecord::STATUS_CONFIRMED;
    $r->serviceId = $service->id;
    $r->quantity = 1;
    Craft::$app->getElements()->saveElement($r, false);

    $intent = $stripe->paymentIntents->create([
        'amount' => $amountMinor,
        'currency' => strtolower($currency),
        'payment_method' => 'pm_card_visa',
        'confirm' => true,
        'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
    ]);

    $p = new PaymentRecord();
    $p->reservationId = $r->id;
    $p->gateway = 'stripe';
    $p->externalId = $intent->id;
    $p->status = PaymentRecord::STATUS_PAID;
    $p->amount = $amountMinor;
    $p->currency = $currency;
    $p->refundedAmount = 0;
    $p->save(false);

    return [$r, $p];
}

/**
 * Guard violations are thrown, not returned — PaymentService::resolveRefundAmount()
 * raises a RuntimeException whose message is a translation key, which the control
 * panel catches and shows. So a "refused" refund means an exception here.
 */
function refundRefused(callable $fn): array
{
    try {
        $r = $fn();
        return [$r->success === false, $r->error ?? 'it succeeded'];
    } catch (RuntimeException $e) {
        return [true, $e->getMessage()];
    }
}

$currency = Slots::getInstance()->getReports()->getCurrency();
echo "currency: {$currency}\n\n";

// Refunds are opt-in per service (allowRefund defaults to false, which resolves
// the policy to a flat 0%). Turn it on for the run and put it back afterwards.
$originalAllowRefund = $service->allowRefund;
$service->allowRefund = true;
Craft::$app->getElements()->saveElement($service, false);

// ---------------------------------------------------------------- full refund
echo "PaymentService::refund()\n";
[$r1, $p1] = paidBooking($service, 5000, $currency, $stripe, '+30 days');
$made[] = $r1->id;
$result = Slots::getInstance()->getPayments()->refund($r1);
$p1->refresh();
check('a full refund succeeds', $result->success, $result->error ?? '');
check('the record shows the whole amount refunded', (int)$p1->refundedAmount === 5000, "refundedAmount={$p1->refundedAmount}");
check('the record status becomes refunded', $p1->status === PaymentRecord::STATUS_REFUNDED, "status={$p1->status}");

// ------------------------------------------------------------- partial refund
[$r2, $p2] = paidBooking($service, 8000, $currency, $stripe, '+30 days');
$made[] = $r2->id;
$result = Slots::getInstance()->getPayments()->refund($r2, 3000);
$p2->refresh();
check('a partial refund succeeds', $result->success, $result->error ?? '');
check('only the requested part is refunded', (int)$p2->refundedAmount === 3000, "refundedAmount={$p2->refundedAmount}");
check('the record status becomes partiallyRefunded', $p2->status === PaymentRecord::STATUS_PARTIALLY_REFUNDED, "status={$p2->status}");

// -------------------------------------------------------- refunding past full
[$refused, $why] = refundRefused(fn() => Slots::getInstance()->getPayments()->refund($r2, 6000));
$p2->refresh();
check('refunding more than remains is refused', $refused, $why);
check('the refused attempt left the record untouched', (int)$p2->refundedAmount === 3000, "refundedAmount={$p2->refundedAmount}");

// ------------------------------------------------------------- policy clamping
echo "\nRefund tiers actually clamp the amount\n";
$originalTiers = $service->refundTiers;
$service->refundTiers = [['hoursBeforeStart' => 0, 'refundPercentage' => 25]];
Craft::$app->getElements()->saveElement($service, false);

[$r3, $p3] = paidBooking($service, 10000, $currency, $stripe, '+30 days');
$made[] = $r3->id;
$pct = Slots::getInstance()->getRefundPolicy()->calculateRefundPercentage($r3);
check('the 25% tier is resolved for the booking', $pct === 25, "percentage={$pct}");

$result = Slots::getInstance()->getPayments()->refund($r3);
$p3->refresh();
check('an unspecified refund is clamped to the tier', (int)$p3->refundedAmount === 2500, "refundedAmount={$p3->refundedAmount} (expected 2500 = 25% of 10000)");

[$refused, $why] = refundRefused(fn() => Slots::getInstance()->getPayments()->refund($r3, 9000));
check('asking for more than the policy allows is refused', $refused, $why);

$service->refundTiers = $originalTiers;
$service->allowRefund = $originalAllowRefund;
Craft::$app->getElements()->saveElement($service, false);

// ------------------------------------------------------------------- teardown
foreach ($made as $rid) {
    $el = Reservation::find()->siteId('*')->id($rid)->status(null)->one();
    if ($el) {
        Craft::$app->getElements()->deleteElement($el, true);
    }
}
Craft::$app->getDb()->createCommand()
    ->delete('{{%slots_payments}}', ['reservationId' => $made])->execute();

printf("\n%s\n", $failures === 0 ? 'All checks passed.' : "{$failures} check(s) FAILED.");
exit($failures === 0 ? 0 : 1);
