<?php

namespace anvildev\slots\controllers;

use anvildev\slots\controllers\traits\BookingHelpersTrait;
use anvildev\slots\controllers\traits\HandlesExceptionsTrait;
use anvildev\slots\controllers\traits\JsonResponseTrait;
use anvildev\slots\elements\Service;
use anvildev\slots\factories\ReservationFactory;
use anvildev\slots\helpers\DateHelper;
use anvildev\slots\helpers\SiteHelper;
use anvildev\slots\Slots;
use Craft;
use craft\web\Controller;
use craft\web\Response;
use yii\web\BadRequestHttpException;

/**
 * Frontend availability/slot endpoints: time slots, calendar data, soft locks.
 */
class SlotController extends Controller
{
    use JsonResponseTrait;
    use HandlesExceptionsTrait;
    use BookingHelpersTrait;

    protected array|bool|int $allowAnonymous = [
        'get-available-slots',
        'get-availability-calendar',
        'create-lock',
        'extend-lock',
        'release-lock',
    ];

    public $enableCsrfValidation = true;

    private \anvildev\slots\services\AvailabilityService $availabilityService;

    public function init(): void
    {
        parent::init();
        $this->availabilityService = Slots::getInstance()->availability;
        $this->closeSession();
    }

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        return true;
    }

    public function actionGetAvailableSlots(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('slots_slots_throttle', 120)) {
            return $this->jsonError(Craft::t('slots', 'booking.rateLimitIP'), statusCode: 429);
        }

        $date = Craft::$app->request->getRequiredBodyParam('date');
        if (!$this->validateDate($date)) {
            throw new BadRequestHttpException(Craft::t('slots', 'booking.invalidDate'));
        }

        $quantity = $this->normalizeQuantity((int)(Craft::$app->request->getBodyParam('quantity') ?? 1));
        $employeeId = $this->normalizeId(Craft::$app->request->getBodyParam('employeeId'));
        $locationId = $this->normalizeId(Craft::$app->request->getBodyParam('locationId'));
        $serviceId = $this->normalizeId(Craft::$app->request->getBodyParam('serviceId'));

        // A reschedule asks "where else could this booking go?", so the booking
        // itself must not count as taken — otherwise its own slot, and on a
        // buffered service the slots its buffer overlaps, read as unavailable and
        // the panel offers times the reschedule then refuses. The caller proves
        // which reservation to discount with its signed manage token rather than a
        // raw id, so one customer cannot exclude another's booking.
        $manageToken = Craft::$app->request->getBodyParam('manageToken');
        $excludeReservationId = $manageToken
            ? ReservationFactory::findByToken($manageToken)?->getId()
            : null;

        return $this->jsonSuccess('', [
            'slots' => $this->availabilityService->getAvailableSlots(
                $date, $employeeId, $locationId, $serviceId, $quantity, null, null,
                excludeReservationId: $excludeReservationId,
            ),
        ]);
    }

    /**
     * Availability calendar data: which dates are bookable, blacked out, or have slots
     */
    public function actionGetAvailabilityCalendar(): Response
    {
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('slots_calendar_throttle', 120)) {
            return $this->jsonError(Craft::t('slots', 'booking.rateLimitIP'), statusCode: 429);
        }

        $request = Craft::$app->request;
        $startDate = $request->getParam('startDate', DateHelper::today());
        $endDate = $request->getParam('endDate', DateHelper::relativeDate('+90 days'));

        // Validate date formats
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            throw new BadRequestHttpException(Craft::t('slots', 'booking.invalidDate'));
        }

        $current = \DateTime::createFromFormat('Y-m-d', $startDate);
        $end = \DateTime::createFromFormat('Y-m-d', $endDate);

        if (!$current || !$end) {
            throw new BadRequestHttpException(Craft::t('slots', 'booking.invalidDate'));
        }

        // Cap date range to 90 days to prevent DoS
        $maxEnd = (clone $current)->add(new \DateInterval('P90D'));
        if ($end > $maxEnd) {
            $end = $maxEnd;
            $endDate = $end->format('Y-m-d');
        }

        $employeeId = $request->getParam('employeeId') ?: $request->getParam('entryId');
        $locationId = $request->getParam('locationId');
        $serviceId = $request->getParam('serviceId');
        $quantity = $this->normalizeQuantity((int)($request->getParam('quantity') ?? 1));

        $blackoutService = Slots::getInstance()->getBlackoutDate();
        $cache = Craft::$app->getCache();

        $site = SiteHelper::getSiteForRequest($request);
        $cacheKey = 'slots_avail_cal_' . md5(json_encode([
            $site->id, $startDate, $endDate, $employeeId, $locationId, $serviceId, $quantity,
        ]));

        $calendar = $cache->getOrSet($cacheKey, function() use ($current, $end, $employeeId, $locationId, $serviceId, $quantity, $blackoutService) {
            $calendar = [];

            while ($current <= $end) {
                $dateStr = $current->format('Y-m-d');
                $empId = $employeeId ? (int)$employeeId : null;
                $locId = $locationId ? (int)$locationId : null;

                $isBlackedOut = $blackoutService->isDateBlackedOut($dateStr, $empId, $locId);
                $hasSlots = !$isBlackedOut && !empty($this->availabilityService->getAvailableSlots(
                    $dateStr, $empId, $locId,
                    $serviceId ? (int)$serviceId : null,
                    $quantity,
                ));

                $calendar[$dateStr] = [
                    'hasAvailability' => $hasSlots,
                    'isBlackedOut' => $isBlackedOut,
                    'isBookable' => $hasSlots && !$isBlackedOut,
                ];

                $current->add(new \DateInterval('P1D'));
            }

            return $calendar;
        }, 300);

        return $this->jsonSuccess('', ['calendar' => $calendar]);
    }

    public function actionCreateLock(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('slots_lock_throttle', 30)) {
            return $this->jsonError(Craft::t('slots', 'booking.rateLimitIP'), statusCode: 429);
        }

        $request = Craft::$app->request;
        $date = $request->getRequiredBodyParam('date');
        $startTime = $request->getRequiredBodyParam('startTime');
        $serviceId = $request->getRequiredBodyParam('serviceId');

        if (!$this->validateDate($date)) {
            return $this->jsonError(Craft::t('slots', 'booking.invalidDate'));
        }

        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime)) {
            return $this->jsonError(Craft::t('slots', 'errors.invalidTime'));
        }

        $service = Service::findOne($serviceId);
        if (!$service) {
            return $this->jsonError(Craft::t('slots', 'errors.serviceNotFound'));
        }


        try {
            $totalDuration = $service->duration;
            $start = new \DateTime($date . ' ' . $startTime);
            $endTime = (clone $start)->add(new \DateInterval('PT' . $totalDuration . 'M'))->format('H:i');
        } catch (\Throwable $e) {
            Craft::error("Error calculating end time for lock: " . $e->getMessage(), __METHOD__);
            return $this->jsonError(Craft::t('slots', 'errors.invalidTime'));
        }

        $durationMinutes = Slots::getInstance()->getSettings()->softLockDurationMinutes ?? 5;
        $employeeId = $request->getBodyParam('employeeId');
        $locationId = $request->getBodyParam('locationId');

        $quantity = max(1, (int)($request->getBodyParam('quantity') ?? 1));
        $capacity = $request->getBodyParam('capacity');
        $capacity = $capacity !== null ? max(1, (int)$capacity) : null;

        $token = Slots::getInstance()->getSoftLock()->createLock([
            'date' => $date,
            'startTime' => $startTime,
            'endTime' => $endTime,
            'serviceId' => (int)$serviceId,
            'employeeId' => $employeeId ? (int)$employeeId : null,
            'locationId' => $locationId ? (int)$locationId : null,
            'quantity' => $quantity,
            'capacity' => $capacity,
        ], $durationMinutes);

        if ($token === false) {
            return $this->jsonError(Craft::t('slots', 'booking.slotReserved'));
        }

        return $this->jsonSuccess('', [
            'token' => $token,
            'expiresIn' => $durationMinutes * 60,
        ]);
    }

    public function actionExtendLock(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('slots_lock_throttle', 30)) {
            return $this->jsonError(Craft::t('slots', 'booking.rateLimitIP'), statusCode: 429);
        }

        $token = Craft::$app->request->getBodyParam('token');
        if (!is_string($token) || $token === '') {
            return $this->jsonError(Craft::t('slots', 'slot.noTokenProvided'));
        }

        $settings = Slots::getInstance()->getSettings();
        $durationMinutes = $settings->softLockDurationMinutes ?? 5;
        $maxLifetimeMinutes = $settings->softLockMaxLifetimeMinutes ?? 30;
        $softLockService = Slots::getInstance()->getSoftLock();
        $newExpiry = $softLockService->extendLock($token, $durationMinutes, $softLockService->getSessionHash(), $maxLifetimeMinutes);

        // A gone/expired lock returns 410 so the client can drop into its expired flow.
        if ($newExpiry === false) {
            return $this->jsonError(Craft::t('slots', 'booking.slotReserved'), statusCode: 410);
        }

        // Report the REAL remaining seconds: extendLock clamps the new expiry to
        // the lock's max lifetime, so near the ceiling the grant is shorter than
        // durationMinutes. Sending durationMinutes*60 would show a countdown that
        // outlives the lock and drop the slot mid-checkout with no warning.
        $expiresIn = max(0, $newExpiry->getTimestamp() - time());

        return $this->jsonSuccess('', [
            'token' => $token,
            'expiresIn' => $expiresIn,
        ]);
    }

    public function actionReleaseLock(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('slots_lock_throttle', 30)) {
            return $this->jsonError(Craft::t('slots', 'booking.rateLimitIP'), statusCode: 429);
        }

        $token = Craft::$app->request->getBodyParam('token');
        if (!$token) {
            return $this->jsonError(Craft::t('slots', 'slot.noTokenProvided'));
        }

        $softLockService = Slots::getInstance()->getSoftLock();
        return $this->jsonSuccess('', [
            'released' => $softLockService->releaseLock($token, $softLockService->getSessionHash()),
        ]);
    }
}
