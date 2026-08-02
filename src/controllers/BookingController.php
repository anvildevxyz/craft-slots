<?php

namespace anvildev\slots\controllers;

use anvildev\slots\controllers\traits\BookingHelpersTrait;
use anvildev\slots\controllers\traits\HandlesExceptionsTrait;
use anvildev\slots\controllers\traits\JsonResponseTrait;
use anvildev\slots\elements\Service;
use anvildev\slots\helpers\SiteHelper;
use anvildev\slots\models\forms\BookingForm;
use anvildev\slots\records\ReservationRecord;
use anvildev\slots\services\BookingSecurityService;
use anvildev\slots\services\BookingService;
use anvildev\slots\Slots;
use Craft;
use craft\web\Controller;
use craft\web\Response;

/**
 * Frontend booking creation with security checks and direct Stripe payments.
 */
class BookingController extends Controller
{
    use JsonResponseTrait;
    use HandlesExceptionsTrait;
    use BookingHelpersTrait;

    protected array|bool|int $allowAnonymous = ['create-booking'];
    public $enableCsrfValidation = true;

    private BookingService $bookingService;
    private BookingSecurityService $securityService;

    public function init(): void
    {
        parent::init();
        $this->bookingService = Slots::getInstance()->booking;
        $this->securityService = Slots::getInstance()->bookingSecurity;
    }

    public function actionCreateBooking(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->request;

        // Resolve site context for multi-site support (action URLs bypass Craft's site routing)
        SiteHelper::getSiteForRequest($request);

        $form = new BookingForm();
        $ipAddress = $request->userIP ?? null;

        // Populate form - support both internal and JS/frontend field names
        $form->userName = $request->getBodyParam('customerName') ?? $request->getBodyParam('userName');
        $form->userEmail = $request->getBodyParam('customerEmail') ?? $request->getBodyParam('userEmail');
        $form->userPhone = $request->getBodyParam('customerPhone') ?? $request->getBodyParam('userPhone');
        $form->userTimezone = $request->getBodyParam('userTimezone') ?: Craft::$app->getTimeZone();
        $form->bookingDate = $request->getBodyParam('date') ?? $request->getBodyParam('bookingDate');
        $form->startTime = $request->getBodyParam('time') ?? $request->getBodyParam('startTime');
        $form->endTime = $request->getBodyParam('endTime') ?? $request->getBodyParam('end_time');
        $form->notes = $request->getBodyParam('notes') ?? $request->getBodyParam('customerNotes');
        $form->serviceId = $request->getBodyParam('serviceId') ? (int)$request->getBodyParam('serviceId') : null;
        $form->employeeId = $request->getBodyParam('employeeId') ? (int)$request->getBodyParam('employeeId') : null;
        $form->locationId = $request->getBodyParam('locationId') ? (int)$request->getBodyParam('locationId') : null;
        $form->quantity = $request->getBodyParam('quantity') ? (int)$request->getBodyParam('quantity') : 1;

        $honeypotFieldName = $this->securityService->getHoneypotFieldName();
        $honeypotValue = $honeypotFieldName ? $request->getBodyParam($honeypotFieldName) : null;
        if ($honeypotFieldName) {
            $form->honeypot = $honeypotValue;
        }

        $form->captchaToken = $request->getBodyParam('captchaToken');

        $securityResult = $this->securityService->validateRequest($ipAddress, $form->captchaToken, $honeypotValue);
        if (!$securityResult['valid']) {
            // Honeypot: return fake success so bots think the submission worked
            if (($securityResult['errorType'] ?? null) === BookingSecurityService::RESULT_SPAM_DETECTED) {
                if ($request->getAcceptsJson()) {
                    return $this->asJson(['success' => true, 'message' => Craft::t('slots', 'booking.confirmed')]);
                }
                Craft::$app->session->setNotice(Craft::t('slots', 'booking.confirmed'));
                return $this->redirectToPostedUrl();
            }

            if ($request->getAcceptsJson()) {
                $errorType = $securityResult['errorType'] ?? null;
                $isRateLimit = in_array($errorType, [BookingSecurityService::RESULT_RATE_LIMITED, BookingSecurityService::RESULT_IP_BLOCKED], true);
                return $this->jsonError($securityResult['error'], statusCode: $isRateLimit ? 429 : 200);
            }
            Craft::$app->session->setError($securityResult['error']);
            return $this->redirectToPostedUrl();
        }

        $softLockToken = $request->getBodyParam('softLockToken');

        // Auto-calculate end time from service duration
        if (!$form->endTime && $form->startTime && $form->serviceId && $form->bookingDate) {
            $service = Service::findOne($form->serviceId);
            if ($service) {
                try {
                    $totalDuration = $service->duration;
                    $start = new \DateTime($form->bookingDate . ' ' . $form->startTime);
                    $form->endTime = (clone $start)->add(new \DateInterval('PT' . $totalDuration . 'M'))->format('H:i');
                } catch (\Throwable $e) {
                    Craft::error("Error calculating end time: " . $e->getMessage(), __METHOD__);
                }
            }
        }

        if (!$form->validate()) {
            Craft::error("Booking validation failed: " . json_encode($form->getErrors()) . " | serviceId: {$form->serviceId}, employeeId: {$form->employeeId}, date: " . ($form->bookingDate ?? ''), __METHOD__);
            if ($request->getAcceptsJson()) {
                return $this->jsonError(Craft::t('slots', 'booking.validationError'), 'validation', $form->getErrors());
            }
            Craft::$app->session->setError(Craft::t('slots', 'booking.validateInput'));
            return $this->redirectToPostedUrl($form);
        }

        $data = $form->getReservationData();
        if ($softLockToken) {
            $data['softLockToken'] = $softLockToken;
        }

        // Calculate total price
        $settings = Slots::getInstance()->getSettings();
        $service = $form->serviceId ? Service::findOne($form->serviceId) : null;
        $totalPrice = $service ? (float)$service->price : 0;


        $useDirectPayment = $settings->isDirectPayment() && $totalPrice > 0;

        // Paid bookings start pending — they are confirmed by the gateway
        // webhook, so they must NOT be created confirmed-for-free.
        if ($useDirectPayment) {
            $data['status'] = ReservationRecord::STATUS_PENDING;
        }

        $currentUser = Craft::$app->getUser()->getIdentity();
        if ($currentUser) {
            $data['userId'] = $currentUser->id;
        }

        try {
            $reservation = $this->bookingService->createReservation($data);


            if ($request->getAcceptsJson()) {
                $reservationData = [
                    'id' => $reservation->getId(),
                    'formattedDateTime' => $reservation->getFormattedDateTime(),
                    'status' => $reservation->getStatusLabel(),
                ];
                // Direct payment: the booking is pending and must be
                // paid in-page. Expose the confirmation token so the wizard can call
                // `payment/create`, and flag that a payment step is required.
                if ($useDirectPayment) {
                    $reservationData['token'] = $reservation->getConfirmationToken();
                    return $this->jsonSuccess(Craft::t('slots', 'booking.created'), [
                        'reservation' => $reservationData,
                        'paymentRequired' => true,
                    ]);
                }
                return $this->jsonSuccess(Craft::t('slots', 'booking.created'), [
                    'reservation' => $reservationData,
                ]);
            }
            Craft::$app->session->setNotice(Craft::t('slots', 'booking.confirmed'));
            return $this->redirectToPostedUrl();
        } catch (\Throwable $e) {
            return $this->handleException($e, $form);
        }
    }
}
