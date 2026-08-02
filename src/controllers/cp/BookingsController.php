<?php

namespace anvildev\slots\controllers\cp;

use anvildev\slots\contracts\ReservationInterface;
use anvildev\slots\controllers\traits\HandlesExceptionsTrait;
use anvildev\slots\controllers\traits\JsonResponseTrait;
use anvildev\slots\elements\Employee;
use anvildev\slots\elements\Location;
use anvildev\slots\elements\Service;
use anvildev\slots\factories\ReservationFactory;
use anvildev\slots\helpers\CsvHelper;
use anvildev\slots\helpers\FormFieldHelper;
use anvildev\slots\records\PaymentRecord;
use anvildev\slots\records\ReservationRecord;
use anvildev\slots\services\BookingService;
use anvildev\slots\services\PaymentService;
use anvildev\slots\Slots;
use Craft;
use craft\helpers\App;
use craft\web\Controller;
use craft\web\Response;
use yii\web\NotFoundHttpException;

class BookingsController extends Controller
{
    use JsonResponseTrait;
    use HandlesExceptionsTrait;

    private BookingService $bookingService;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        in_array($action->id, ['index', 'view', 'edit', 'export'], true)
            ? $this->requirePermission('slots-viewBookings')
            : $this->requirePermission('slots-manageBookings');

        return true;
    }

    public function init(): void
    {
        parent::init();
        $this->bookingService = Slots::getInstance()->booking;
    }

    /**
     * The bookings index is a native Craft element index now.
     *
     * Everything the hand-rolled table used to do — status filters, search,
     * sorting, pagination, per-service and per-employee filtering, CSV export —
     * the Reservation element already declares as sources, sort options,
     * condition rules, actions and an exporter. Rebuilding them in a controller
     * meant a booking behaved like an element everywhere except the one screen
     * people actually use.
     *
     * Staff scoping is not applied here on purpose: Craft builds the index
     * query itself, so it is enforced in ReservationQuery::beforePrepare().
     */
    public function actionIndex(): Response
    {
        $user = Craft::$app->getUser()->getIdentity();

        return $this->renderTemplate('slots/bookings/_index', [
            'title' => Craft::t('slots', 'titles.bookings'),
            'canManage' => $user && ($user->admin || $user->can('slots-manageBookings')),
        ]);
    }

    public function actionView(int $id): Response
    {
        return $this->actionEdit($id);
    }

    public function actionEdit(?int $id = null): Response
    {
        $user = Craft::$app->getUser()->getIdentity();
        $canManage = $user->admin || $user->can('slots-manageBookings');

        if ($id) {
            $reservation = $this->findScopedReservation($id)
                ?? throw new NotFoundHttpException('Booking not found');
        } else {
            if (!$canManage) {
                throw new \yii\web\ForbiddenHttpException('User is not authorized to perform this action.');
            }
            $reservation = ReservationFactory::create([
                'siteId' => Craft::$app->request->getParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id,
            ]);
        }

        $settings = Slots::getInstance()->getSettings();

        $order = null;

        $canEditSessionNotes = $user->admin;
        if (!$canEditSessionNotes && $reservation->employeeId) {
            $employees = Slots::getInstance()->getPermission()->getEmployeesForCurrentUser();
            $canEditSessionNotes = in_array($reservation->employeeId, array_column($employees, 'id'), true);
        }

        return $this->renderTemplate('slots/bookings/edit', array_merge(
            [
                'reservation' => $reservation,
                'canManage' => $canManage,
                'canEditSessionNotes' => $canEditSessionNotes,
                'emailEnabled' => true,
                'currency' => Slots::getInstance()->reports->getCurrency(),
                'order' => $order,
                'payment' => $id ? $this->getPaymentPanel($reservation) : null,
            ],
            $this->getFormOptions()
        ));
    }

    /**
     * Direct-payment panel context for the booking edit screen (null if N/A):
     * amounts, gateway + external-ID link, and the policy-allowed refund.
     *
     * @return array<string, mixed>|null
     */
    private function getPaymentPanel(ReservationInterface $reservation): ?array
    {
        if (!Slots::getInstance()->getSettings()->isDirectPayment()) {
            return null;
        }
        /** @var PaymentRecord|null $record */
        $record = PaymentRecord::find()
            ->where(['reservationId' => $reservation->getId()])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->one();
        if (!$record) {
            return null;
        }

        $currency = $record->currency ?: Slots::getInstance()->reports->getCurrency();
        $captured = (int) $record->amount;
        $refunded = (int) ($record->refundedAmount ?? 0);

        // Policy-allowed remaining refund (minor units): a % of the captured amount,
        // net of prior refunds — mirrors PaymentService::resolveRefundAmount().
        $maxRefundMinor = 0;
        if (in_array($record->status, [PaymentRecord::STATUS_PAID, PaymentRecord::STATUS_PARTIALLY_REFUNDED], true)) {
            $pct = Slots::getInstance()->getRefundPolicy()->calculateRefundPercentage($reservation);
            $maxRefundMinor = max(0, min($captured - $refunded, (int) floor($captured * $pct / 100) - $refunded));
        }

        $user = Craft::$app->getUser()->getIdentity();
        $canRefund = $maxRefundMinor > 0 && $user !== null && ($user->admin || $user->can('slots-manageRefunds'));

        return [
            'status' => $record->status,
            'gateway' => $record->gateway,
            'externalId' => $record->externalId,
            'dashboardUrl' => $this->gatewayDashboardUrl($record),
            'currency' => $currency,
            'amount' => PaymentService::fromMinorUnits($captured, $currency),
            'refunded' => PaymentService::fromMinorUnits($refunded, $currency),
            'maxRefund' => PaymentService::fromMinorUnits($maxRefundMinor, $currency),
            'canRefund' => $canRefund,
        ];
    }

    /** Deep link to the payment in the gateway's dashboard (Stripe only for now). */
    private function gatewayDashboardUrl(PaymentRecord $record): ?string
    {
        if ($record->gateway !== 'stripe' || !$record->externalId) {
            return null;
        }
        $key = (string) App::parseEnv(Slots::getInstance()->getSettings()->stripeSecretKey);
        $segment = str_starts_with($key, 'sk_test_') ? 'test/' : '';
        return "https://dashboard.stripe.com/{$segment}payments/{$record->externalId}";
    }

    /**
     * Issue a direct-payment refund from the edit screen (gated by
     * `slots-manageRefunds`). Optional `amount` (major units); blank = policy max.
     */
    public function actionRefund(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('slots-manageRefunds');

        $request = Craft::$app->request;
        $reservation = $this->findScopedReservation((int) $request->getRequiredBodyParam('id'))
            ?? throw new NotFoundHttpException(Craft::t('slots', 'errors.bookingNotFound'));

        $amountParam = $request->getBodyParam('amount');
        $amountMinor = null;
        if ($amountParam !== null && $amountParam !== '') {
            // Convert with the payment's OWN currency (what the panel rendered),
            // not the install currency — they can differ for historical payments.
            /** @var PaymentRecord|null $record */
            $record = PaymentRecord::find()
                ->where(['reservationId' => $reservation->getId()])
                ->orderBy(['dateCreated' => SORT_DESC])
                ->one();
            $currency = $record?->currency ?: Slots::getInstance()->reports->getCurrency();
            $amountMinor = PaymentService::toMinorUnits((float) $amountParam, $currency);
        }

        try {
            $result = Slots::getInstance()->getPayments()->refund($reservation, $amountMinor);
            if (!$result->success) {
                Craft::$app->getSession()->setError($result->error ?: Craft::t('slots', 'payment.refundFailed'));
                return $this->redirectToPostedUrl();
            }
            Craft::$app->getSession()->setNotice(Craft::t('slots', 'payment.refundSucceeded'));
        } catch (\RuntimeException $e) {
            // Guard violations carry a translation key as their message.
            Craft::$app->getSession()->setError(Craft::t('slots', $e->getMessage()));
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }

        return $this->redirectToPostedUrl();
    }

    private function findScopedReservation(int $id): ?ReservationInterface
    {
        $query = ReservationFactory::find()->id($id);
        Slots::getInstance()->getPermission()->scopeReservationQuery($query);
        return $query->one();
    }

    private function getFormOptions(): array
    {
        $mapOptions = static fn(iterable $items, string $labelFn = 'title') => array_map(
            static fn($item) => ['value' => $item->id, 'label' => is_callable($labelFn) ? $labelFn($item) : $item->$labelFn],
            is_array($items) ? $items : iterator_to_array($items)
        );

        return [
            'statuses' => ReservationRecord::getStatuses(),
            'serviceOptions' => array_merge(
                [['value' => '', 'label' => Craft::t('slots', 'form.selectService')]],
                array_map(
                    static fn($s) => ['value' => $s->id, 'label' => $s->title . ($s->duration ? " ({$s->duration} min)" : '')],
                    Service::find()->orderBy('title')->all()
                )
            ),
            'employeeOptions' => array_merge(
                [['value' => '', 'label' => Craft::t('slots', 'form.noEmployee')]],
                $mapOptions(Employee::find()->siteId('*')->orderBy('title')->all())
            ),
            'locationOptions' => array_merge(
                [['value' => '', 'label' => Craft::t('slots', 'form.noLocation')]],
                $mapOptions(Location::find()->siteId('*')->orderBy('title')->all())
            ),
            'timezoneOptions' => array_merge(
                [['value' => '', 'label' => Craft::t('slots', 'form.selectTimezone')]],
                array_map(static fn($tz) => ['value' => $tz, 'label' => $tz], \DateTimeZone::listIdentifiers())
            ),
        ];
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->request;
        $id = $request->getBodyParam('id');

        $reservation = $id
            ? ($this->findScopedReservation((int)$id) ?? throw new NotFoundHttpException('Booking not found'))
            : ReservationFactory::create();

        $oldStatus = $id ? $reservation->status : null;

        $reservation->userName = strip_tags(trim($request->getRequiredBodyParam('userName')));
        $reservation->userEmail = strtolower(strip_tags(trim($request->getRequiredBodyParam('userEmail'))));
        $reservation->userPhone = ($phone = $request->getBodyParam('userPhone')) ? strip_tags(trim($phone)) : null;
        $reservation->bookingDate = FormFieldHelper::extractDateValue($request->getRequiredBodyParam('bookingDate'));
        $reservation->startTime = FormFieldHelper::extractTimeValue($request->getRequiredBodyParam('startTime'));
        $reservation->endTime = FormFieldHelper::extractTimeValue($request->getRequiredBodyParam('endTime'));
        $submittedStatus = $request->getRequiredBodyParam('status');
        $validStatuses = array_keys(ReservationRecord::getStatuses());
        if (!in_array($submittedStatus, $validStatuses, true)) {
            Craft::$app->getSession()->setError(Craft::t('slots', 'errors.invalidStatus'));
            return $this->renderTemplate('slots/bookings/edit', array_merge(
                ['reservation' => $reservation],
                $this->getFormOptions()
            ));
        }
        $reservation->status = $submittedStatus;
        $reservation->notes = ($notes = $request->getBodyParam('notes')) ? substr(strip_tags(trim($notes)), 0, 5000) : null;
        $reservation->quantity = (int)($request->getBodyParam('quantity') ?? 1);

        // Session notes — only allow setting if user is admin or assigned employee
        $user = Craft::$app->getUser()->getIdentity();
        $canEditSessionNotes = $user->admin;
        if (!$canEditSessionNotes && $reservation->employeeId) {
            $employees = Slots::getInstance()->getPermission()->getEmployeesForCurrentUser();
            $canEditSessionNotes = in_array($reservation->employeeId, array_column($employees, 'id'), true);
        }
        if ($canEditSessionNotes) {
            $sessionNotes = $request->getBodyParam('sessionNotes');
            $reservation->sessionNotes = $sessionNotes ? substr(strip_tags(trim($sessionNotes)), 0, 10000) : null;
        }

        $reservation->serviceId = ($v = $request->getBodyParam('serviceId')) ? (int)$v : null;
        $reservation->employeeId = ($v = $request->getBodyParam('employeeId')) ? (int)$v : null;
        $reservation->locationId = ($v = $request->getBodyParam('locationId')) ? (int)$v : null;
        $reservation->userTimezone = $request->getBodyParam('userTimezone') ?: null;

        if (method_exists($reservation, 'setFieldValuesFromRequest')) {
            $reservation->setFieldValuesFromRequest('fields');
        }

        $canSkipCheck = Craft::$app->getUser()->getIsAdmin() && (bool)$request->getBodyParam('skipAvailabilityCheck');

        // Mutex lock to prevent TOCTOU race between conflict check and save
        $mutex = Craft::$app->getMutex();
        $lockKey = 'slots-cp-save-' . $reservation->bookingDate . '-' . ($reservation->employeeId ?? 'any');
        if (!$mutex->acquire($lockKey, 10)) {
            Craft::$app->getSession()->setError(Craft::t('slots', 'errors.slotAlreadyBooked'));
            return $this->renderTemplate('slots/bookings/edit', array_merge(
                ['reservation' => $reservation],
                $this->getFormOptions()
            ));
        }

        try {
            if (!$canSkipCheck && $reservation->status === 'confirmed') {
                $conflictResponse = $this->checkForBookingConflicts($reservation, $id);
                if ($conflictResponse !== null) {
                    return $conflictResponse;
                }
            }

            if (!$reservation->save()) {
                Craft::$app->getSession()->setError(Craft::t('slots', 'messages.bookingNotSaved'));
                return $this->renderTemplate('slots/bookings/edit', array_merge(
                    ['reservation' => $reservation],
                    $this->getFormOptions()
                ));
            }
        } finally {
            $mutex->release($lockKey);
        }

        if ($oldStatus !== null && $oldStatus !== $reservation->status) {
            Slots::getInstance()->getAudit()->logStatusChange($reservation->id, $oldStatus, $reservation->status);
        }

        Craft::$app->getSession()->setNotice(Craft::t('slots', 'messages.bookingSaved'));
        return $this->redirect('slots/bookings');
    }

    private function checkForBookingConflicts($reservation, ?int $id): ?\yii\web\Response
    {
        $normTime = static fn(?string $t): string => $t ? substr($t, 0, 5) : '';

        if ($id) {
            $original = $this->findScopedReservation($id);
            if ($original &&
                $original->getBookingDate() === $reservation->bookingDate &&
                $normTime($original->getStartTime()) === $normTime($reservation->startTime) &&
                $normTime($original->getEndTime()) === $normTime($reservation->endTime) &&
                $original->getEmployeeId() === $reservation->employeeId
            ) {
                return null;
            }
        }

        $conflictQuery = ReservationFactory::find()->bookingDate($reservation->bookingDate)->status('confirmed');
        if ($reservation->employeeId) {
            $conflictQuery->employeeId($reservation->employeeId);
        }
        if ($id) {
            $conflictQuery->andWhere(['!=', 'id', $id]);
        }

        foreach ($conflictQuery->all() as $conflict) {
            $cStart = $normTime($conflict->getStartTime());
            $cEnd = $normTime($conflict->getEndTime());
            $rStart = $normTime($reservation->startTime);
            $rEnd = $normTime($reservation->endTime);
            if ($rStart < $cEnd && $rEnd > $cStart) {
                Craft::$app->getSession()->setError(Craft::t('slots', 'errors.slotAlreadyBooked', [
                    'date' => $reservation->bookingDate,
                    'time' => $reservation->startTime,
                ]));
                return $this->renderTemplate('slots/bookings/edit', array_merge(
                    ['reservation' => $reservation],
                    $this->getFormOptions()
                ));
            }
        }

        return null;
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $reservation = $this->findScopedReservation((int) Craft::$app->request->getRequiredBodyParam('id'))
            ?? throw new NotFoundHttpException(Craft::t('slots', 'errors.bookingNotFound'));

        $reservation->delete()
            ? Craft::$app->getSession()->setNotice(Craft::t('slots', 'messages.bookingDeleted'))
            : Craft::$app->getSession()->setError(Craft::t('slots', 'messages.bookingNotDeleted'));

        return $this->redirect('slots/bookings');
    }

    public function actionBulkDelete(): Response
    {
        $this->requirePostRequest();

        $ids = Craft::$app->request->getRequiredBodyParam('ids');
        if (!is_array($ids) || empty($ids)) {
            Craft::$app->getSession()->setError(Craft::t('slots', 'errors.noBookingsSelected'));
            return $this->redirect('slots/bookings');
        }

        $deleted = $failed = 0;
        foreach ($ids as $id) {
            $reservation = $this->findScopedReservation((int) $id);
            ($reservation && $reservation->delete()) ? $deleted++ : $failed++;
        }

        if ($deleted > 0) {
            Craft::$app->getSession()->setNotice(Craft::t('slots', 'messages.bookingsDeleted', ['count' => $deleted]));
        }
        if ($failed > 0) {
            Craft::$app->getSession()->setError(Craft::t('slots', 'messages.bookingsNotDeleted', ['count' => $failed]));
        }

        return $this->redirect('slots/bookings');
    }

    public function actionUpdateStatus(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->request;
        $reservation = $this->findScopedReservation((int) $request->getRequiredBodyParam('id'))
            ?? throw new NotFoundHttpException(Craft::t('slots', 'errors.bookingNotFound'));

        try {
            $submittedStatus = $request->getRequiredBodyParam('status');
            $validStatuses = array_keys(ReservationRecord::getStatuses());
            if (!in_array($submittedStatus, $validStatuses, true)) {
                if ($request->getAcceptsJson()) {
                    return $this->jsonError(Craft::t('slots', 'errors.invalidStatus'));
                }
                Craft::$app->getSession()->setError(Craft::t('slots', 'errors.invalidStatus'));
                return $this->redirectToPostedUrl();
            }

            $this->bookingService->updateReservation(
                $reservation->getId(),
                ['status' => $submittedStatus]
            );

            if ($request->getAcceptsJson()) {
                return $this->jsonSuccess(Craft::t('slots', 'messages.statusUpdated'));
            }
            Craft::$app->getSession()->setNotice(Craft::t('slots', 'messages.statusUpdated'));
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }

        return $this->redirectToPostedUrl();
    }

    public function actionResendConfirmation(): Response
    {
        $this->requirePostRequest();

        $reservation = $this->findScopedReservation((int) Craft::$app->request->getRequiredBodyParam('id'))
            ?? throw new NotFoundHttpException(Craft::t('slots', 'errors.bookingNotFound'));

        // Reset the idempotency flag so the CAS guard in SendBookingEmailJob allows re-sending
        Craft::$app->db->createCommand()->update(
            '{{%slots_reservations}}',
            ['notificationSent' => false],
            ['id' => $reservation->getId()],
        )->execute();

        Slots::getInstance()->getBookingNotification()->queueBookingEmail($reservation->getId(), 'confirmation');
        Craft::$app->getSession()->setNotice(Craft::t('slots', 'messages.emailSent'));

        return $this->redirectToPostedUrl();
    }

    public function actionExport(): Response
    {
        $request = Craft::$app->request;
        $keys = ['status', 'serviceId', 'employeeId', 'locationId', 'dateFrom', 'dateTo', 'userEmail'];
        $criteria = [];
        foreach ($keys as $k) {
            $criteria[$k] = $request->getParam($k);
        }

        $query = ReservationFactory::find();
        Slots::getInstance()->getPermission()->scopeReservationQuery($query);

        if (!empty($criteria['status'])) {
            $query->status($criteria['status']);
        }
        if (!empty($criteria['serviceId'])) {
            $query->serviceId((int) $criteria['serviceId']);
        }
        if (!empty($criteria['employeeId'])) {
            $query->employeeId((int) $criteria['employeeId']);
        }
        if (!empty($criteria['locationId'])) {
            $query->locationId((int) $criteria['locationId']);
        }
        if (!empty($criteria['dateFrom'])) {
            $query->andWhere(['>=', 'slots_reservations.bookingDate', $criteria['dateFrom']]);
        }
        if (!empty($criteria['dateTo'])) {
            $query->andWhere(['<=', 'slots_reservations.bookingDate', $criteria['dateTo']]);
        }
        if (!empty($criteria['userEmail'])) {
            $query->userEmail($criteria['userEmail']);
        }

        $query->orderBy(['bookingDate' => SORT_DESC, 'startTime' => SORT_DESC]);

        // Direct-payment provenance columns are added only when the install is in
        // direct mode; free/unpaid rows within it just leave those cells blank.
        $directMode = Slots::getInstance()->getSettings()->isDirectPayment();
        $currency = $directMode ? Slots::getInstance()->reports->getCurrency() : 'USD';

        $header = ['ID', 'Name', 'Email', 'Phone', 'Service', 'Employee', 'Location', 'Event', 'Date', 'Start Time', 'End Time', 'Quantity', 'Status', 'Notes', 'Created'];
        if ($directMode) {
            array_push($header, 'Payment Status', 'Gateway', 'External ID', 'Refunded');
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $header);

        foreach ($query->each(100) as $r) {
            $service = $r->serviceId ? Service::find()->id($r->serviceId)->one() : null;
            $employee = $r->employeeId ? Employee::find()->siteId('*')->id($r->employeeId)->one() : null;
            $location = $r->locationId ? Location::find()->siteId('*')->id($r->locationId)->one() : null;

            $row = [
                (string)$r->id,
                CsvHelper::sanitizeValue($r->userName ?? ''),
                CsvHelper::sanitizeValue($r->userEmail ?? ''),
                CsvHelper::sanitizeValue($r->userPhone ?? ''),
                CsvHelper::sanitizeValue($service->title ?? ''),
                CsvHelper::sanitizeValue($employee->title ?? ''),
                CsvHelper::sanitizeValue($location->title ?? ''),
                (string)($r->bookingDate ?? ''),
                (string)($r->startTime ?? ''),
                (string)($r->endTime ?? ''),
                (string)($r->quantity ?? 1),
                (string)$r->getStatusLabel(),
                CsvHelper::sanitizeValue($r->notes ?? ''),
                $r->dateCreated ? $r->dateCreated->format('Y-m-d H:i:s') : '',
            ];

            if ($directMode) {
                /** @var PaymentRecord|null $payment */
                $payment = PaymentRecord::find()
                    ->where(['reservationId' => $r->id])
                    ->orderBy(['dateCreated' => SORT_DESC])
                    ->one();
                if ($payment) {
                    $row[] = (string)$payment->status;
                    $row[] = (string)$payment->gateway;
                    $row[] = CsvHelper::sanitizeValue((string)$payment->externalId);
                    $row[] = number_format(PaymentService::fromMinorUnits((int)($payment->refundedAmount ?? 0), $currency), 2, '.', '');
                } else {
                    array_push($row, '', '', '', '');
                }
            }

            fputcsv($handle, $row);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $this->response->sendContentAsFile($content, 'bookings-' . date('Y-m-d') . '.csv', [
            'mimeType' => 'text/csv',
        ]);
    }
}
