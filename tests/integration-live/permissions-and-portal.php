<?php

/**
 * Staff scoping and the customer account portal.
 *
 * Neither is covered anywhere else, and both are the kind of thing that fails
 * quietly: a scope that returns everything looks identical to a scope that works
 * until you have two staff members, and a portal that skips its ownership check
 * looks fine until someone edits the id in the URL.
 *
 * Sets up two staff users linked to two employees, two customers with their own
 * bookings, then asserts what each may see — including that neither can reach
 * the other's booking by id.
 *
 * Usage (from the project root):
 *   ddev exec php plugins/slots/tests/integration-live/permissions-and-portal.php setup
 *   ddev exec php plugins/slots/tests/integration-live/permissions-and-portal.php assert
 *   ddev exec php plugins/slots/tests/integration-live/permissions-and-portal.php clean
 *
 * `setup` prints the ids the browser pass needs. Exits non-zero on any failure.
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\slots\elements\Employee;
use anvildev\slots\elements\Reservation;
use anvildev\slots\elements\Service;
use anvildev\slots\records\ReservationRecord;
use anvildev\slots\Slots;
use craft\elements\User;

const TAG = 'PERMPROBE';
const PASSWORD = 'PermProbe!2026#test';

$command = $argv[1] ?? 'assert';
$elements = Craft::$app->getElements();
$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, $detail !== '' ? " — {$detail}" : '');
}

function findUser(string $suffix): ?User
{
    return User::find()->status(null)->username(strtolower(TAG) . '-' . $suffix)->one();
}

function clean(): int
{
    $elements = Craft::$app->getElements();
    $n = 0;
    foreach (Reservation::find()->siteId('*')->status(null)->all() as $r) {
        if (str_contains((string)$r->userEmail, strtolower(TAG))) {
            $elements->deleteElement($r, true);
            $n++;
        }
    }
    foreach (Employee::find()->siteId('*')->status(null)->title(TAG . '*')->all() as $e) {
        $elements->deleteElement($e, true);
        $n++;
    }
    foreach (Service::find()->siteId('*')->status(null)->title(TAG . '*')->all() as $s) {
        $elements->deleteElement($s, true);
        $n++;
    }
    foreach (['staff-a', 'staff-b', 'customer-a', 'customer-b'] as $suffix) {
        if ($u = findUser($suffix)) {
            $elements->deleteElement($u, true);
            $n++;
        }
    }
    $group = Craft::$app->getUserGroups()->getGroupByHandle(strtolower(TAG) . 'Staff');
    if ($group) {
        Craft::$app->getUserGroups()->deleteGroupById($group->id);
        $n++;
    }
    return $n;
}

if ($command === 'clean') {
    echo 'removed ' . clean() . " probe record(s)\n";
    exit(0);
}

// ------------------------------------------------------------------- setup
if ($command === 'setup') {
    clean();

    // A group with the booking-viewing permissions but deliberately NOT
    // manageSettings — the point is to prove a limited role stays limited.
    // Reuse the group if a previous run left it behind: Craft will not delete a
    // group that still has project-config references, and failing setup over a
    // leftover is worse than adopting it.
    $group = Craft::$app->getUserGroups()->getGroupByHandle(strtolower(TAG) . 'Staff');
    if (!$group) {
        $group = new \craft\models\UserGroup();
        $group->name = TAG . ' Staff';
        $group->handle = strtolower(TAG) . 'Staff';
        if (!Craft::$app->getUserGroups()->saveGroup($group)) {
            fwrite(STDERR, 'group: ' . json_encode($group->getErrors()) . "\n");
            exit(1);
        }
    }
    // accessCp is Craft's own gate: without it a non-admin cannot reach /admin at
    // all, and the scoping below would never get a chance to be wrong.
    Craft::$app->getUserPermissions()->saveGroupPermissions($group->id, [
        // Three separate gates, and all three are needed: Craft's CP gate, Craft's
        // per-plugin gate (accessPlugin-<handle>, which it creates itself), and
        // the plugin's own permission tree.
        'accesscp', 'accessplugin-slots', 'slots-accessplugin', 'slots-viewbookings',
    ]);

    $mk = function (string $suffix, array $groupIds = []) use ($elements): User {
        $u = new User();
        $u->username = strtolower(TAG) . '-' . $suffix;
        $u->email = strtolower(TAG) . "-{$suffix}@example.test";
        $u->firstName = ucfirst(str_replace('-', ' ', $suffix));
        $u->lastName = 'Probe';
        $u->newPassword = PASSWORD;
        if (!$elements->saveElement($u)) {
            fwrite(STDERR, "user {$suffix}: " . json_encode($u->getErrors()) . "\n");
            exit(1);
        }
        Craft::$app->getUsers()->activateUser($u);
        if ($groupIds) {
            Craft::$app->getUsers()->assignUserToGroups($u->id, $groupIds);
        }
        return $u;
    };

    $staffA = $mk('staff-a', [$group->id]);
    $staffB = $mk('staff-b', [$group->id]);
    $custA = $mk('customer-a');
    $custB = $mk('customer-b');

    $service = new Service();
    $service->title = TAG . ' Service';
    $service->duration = 60;
    $service->price = 50;
    $elements->saveElement($service);

    // Each staff user owns exactly one employee record.
    $employees = [];
    foreach ([['a', $staffA], ['b', $staffB]] as [$letter, $user]) {
        $e = new Employee();
        $e->title = TAG . ' Employee ' . strtoupper($letter);
        $e->email = strtolower(TAG) . "-emp-{$letter}@example.test";
        $e->userId = $user->id;
        $elements->saveElement($e);
        $employees[$letter] = $e;
    }

    $book = function (string $letter, User $customer, int $daysOut) use ($service, $employees, $elements): Reservation {
        $r = new Reservation();
        $r->userName = $customer->firstName . ' ' . $customer->lastName;
        $r->userEmail = $customer->email;
        $r->userId = $customer->id;
        $r->bookingDate = (new DateTime("+{$daysOut} days"))->format('Y-m-d');
        $r->startTime = '10:00';
        $r->endTime = '11:00';
        $r->status = ReservationRecord::STATUS_CONFIRMED;
        $r->serviceId = $service->id;
        $r->employeeId = $employees[$letter]->id;
        $r->quantity = 1;
        $elements->saveElement($r, false);
        return $r;
    };

    $bookingA = $book('a', $custA, 5);
    $bookingB = $book('b', $custB, 6);

    echo json_encode([
        'staffA' => $staffA->username, 'staffB' => $staffB->username,
        'customerA' => $custA->username, 'customerB' => $custB->username,
        'password' => PASSWORD,
        'employeeA' => $employees['a']->id, 'employeeB' => $employees['b']->id,
        'bookingA' => $bookingA->id, 'bookingB' => $bookingB->id,
    ], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

// ------------------------------------------------------------------ assert
$permission = Slots::getInstance()->getPermission();
$staffA = findUser('staff-a');
$staffB = findUser('staff-b');
$custA = findUser('customer-a');
$custB = findUser('customer-b');
$empA = Employee::find()->siteId('*')->title(TAG . ' Employee A')->one();
$empB = Employee::find()->siteId('*')->title(TAG . ' Employee B')->one();

if (!$staffA || !$empA) {
    fwrite(STDERR, "Run `setup` first.\n");
    exit(1);
}

echo "Staff scoping\n";

$forA = array_map(fn($e) => (int)$e->id, $permission->getEmployeesForUser($staffA->id));
check('a staff user resolves to their own employee', $forA === [(int)$empA->id], json_encode($forA));

$forB = array_map(fn($e) => (int)$e->id, $permission->getEmployeesForUser($staffB->id));
check("…and not to the other staff member's", !in_array((int)$empA->id, $forB, true), json_encode($forB));

// Scoping is applied against the logged-in user, so impersonate for the query.
Craft::$app->getUser()->setIdentity($staffA);
$scoped = $permission->scopeReservationQuery(
    \anvildev\slots\factories\ReservationFactory::find()->siteId('*')
);
$visibleEmployeeIds = array_values(array_unique(array_map(
    fn($r) => (int)$r->getEmployeeId(),
    $scoped->all(),
)));
check(
    'the bookings query is scoped to that employee',
    $visibleEmployeeIds === [(int)$empA->id] || $visibleEmployeeIds === [],
    'employee ids visible to staff A: ' . json_encode($visibleEmployeeIds),
);
check(
    "…so the other employee's bookings are not returned",
    !in_array((int)$empB->id, $visibleEmployeeIds, true),
    'employee B present in staff A\'s results',
);

$isStaff = $permission->isStaffMember();
check('the staff user is recognised as staff', $isStaff === true, var_export($isStaff, true));

Craft::$app->getUser()->setIdentity(null);

echo "\nAccount portal ownership\n";

$bookingA = Reservation::find()->siteId('*')->status(null)->userEmail($custA->email)->one();
$bookingB = Reservation::find()->siteId('*')->status(null)->userEmail($custB->email)->one();

Craft::$app->getUser()->setIdentity($custA);
$own = \anvildev\slots\factories\ReservationFactory::find()->id($bookingA->id)->forCurrentUser()->one();
check('a customer can load their own booking', $own !== null);

$other = \anvildev\slots\factories\ReservationFactory::find()->id($bookingB->id)->forCurrentUser()->one();
check(
    "…and cannot load another customer's by id",
    $other === null,
    $other ? "leaked booking #{$other->id}" : '',
);

$mine = \anvildev\slots\factories\ReservationFactory::find()->forCurrentUser()->all();
$mineIds = array_map(fn($r) => (int)$r->getId(), $mine);
check(
    'my-bookings returns only the customer\'s own',
    !in_array((int)$bookingB->id, $mineIds, true),
    'ids: ' . json_encode($mineIds),
);
Craft::$app->getUser()->setIdentity(null);

printf("\n%s\n", $failures === 0 ? 'All checks passed.' : "{$failures} check(s) FAILED.");
exit($failures === 0 ? 0 : 1);
