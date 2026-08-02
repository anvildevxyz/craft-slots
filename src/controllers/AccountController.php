<?php

namespace anvildev\slots\controllers;

use anvildev\slots\controllers\traits\BookingHelpersTrait;
use anvildev\slots\controllers\traits\JsonResponseTrait;
use anvildev\slots\factories\ReservationFactory;
use anvildev\slots\Slots;
use Craft;
use craft\web\Controller;
use craft\web\Response;
use yii\web\NotFoundHttpException;

/**
 * Frontend account portal for logged-in users to manage their bookings.
 */
class AccountController extends Controller
{
    use JsonResponseTrait;
    use BookingHelpersTrait;

    protected array|bool|int $allowAnonymous = ['current-user'];

    public function beforeAction($action): bool
    {
        if ($action->id !== 'current-user') {
            $this->requireLogin();
        }
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $user = Craft::$app->getUser()->getIdentity();
        $today = date('Y-m-d');

        $upcoming = ReservationFactory::find()
            ->forCurrentUser()
            ->status(['confirmed', 'pending'])
            ->andWhere(['>=', 'slots_reservations.bookingDate', $today])
            ->orderBy('slots_reservations.bookingDate ASC, slots_reservations.startTime ASC')
            ->limit(5)
            ->all();

        $recent = ReservationFactory::find()
            ->forCurrentUser()
            ->orderBy('dateCreated DESC')
            ->limit(5)
            ->all();

        return $this->renderTemplate('slots/frontend/account/index', [
            'user' => $user,
            'upcoming' => $upcoming,
            'recent' => $recent,
            'stats' => [
                'total' => ReservationFactory::find()->forCurrentUser()->count(),
                'upcoming' => ReservationFactory::find()
                    ->forCurrentUser()
                    ->status(['confirmed', 'pending'])
                    ->andWhere(['>=', 'slots_reservations.bookingDate', $today])
                    ->count(),
                'completed' => ReservationFactory::find()
                    ->forCurrentUser()
                    ->andWhere(['<', 'slots_reservations.bookingDate', $today])
                    ->status(['confirmed'])
                    ->count(),
                'cancelled' => ReservationFactory::find()
                    ->forCurrentUser()
                    ->status(['cancelled'])
                    ->count(),
            ],
        ]);
    }

    public function actionBookings(): Response
    {
        return $this->renderTemplate('slots/frontend/account/bookings', [
            'user' => Craft::$app->getUser()->getIdentity(),
            'bookings' => ReservationFactory::find()
                ->forCurrentUser()
                ->orderBy('slots_reservations.bookingDate DESC, slots_reservations.startTime DESC')
                ->all(),
        ]);
    }

    public function actionUpcoming(): Response
    {
        return $this->renderTemplate('slots/frontend/account/upcoming', [
            'user' => Craft::$app->getUser()->getIdentity(),
            'bookings' => ReservationFactory::find()
                ->forCurrentUser()
                ->status(['confirmed', 'pending'])
                ->andWhere(['>=', 'slots_reservations.bookingDate', date('Y-m-d')])
                ->orderBy('slots_reservations.bookingDate ASC, slots_reservations.startTime ASC')
                ->all(),
        ]);
    }

    public function actionPast(): Response
    {
        return $this->renderTemplate('slots/frontend/account/past', [
            'user' => Craft::$app->getUser()->getIdentity(),
            'bookings' => ReservationFactory::find()
                ->forCurrentUser()
                ->andWhere(['<', 'slots_reservations.bookingDate', date('Y-m-d')])
                ->orderBy('slots_reservations.bookingDate DESC, slots_reservations.startTime DESC')
                ->all(),
        ]);
    }

    public function actionView(int $id): Response
    {
        $booking = ReservationFactory::find()->id($id)->forCurrentUser()->one();
        if (!$booking) {
            throw new NotFoundHttpException(Craft::t('slots', 'messages.bookingNotFoundAccount'));
        }

        return $this->renderTemplate('slots/frontend/account/view', [
            'user' => Craft::$app->getUser()->getIdentity(),
            'booking' => $booking,
        ]);
    }

    public function actionCancel(): Response
    {
        $this->requirePostRequest();

        $id = Craft::$app->getRequest()->getRequiredBodyParam('id');
        $booking = ReservationFactory::find()->id($id)->forCurrentUser()->one();

        if (!$booking) {
            throw new NotFoundHttpException(Craft::t('slots', 'messages.bookingNotFoundAccount'));
        }

        if (!$booking->canBeCancelled()) {
            Craft::$app->getSession()->setError(Craft::t('slots', 'messages.cannotCancelBooking'));
            return $this->redirectToPostedUrl();
        }

        try {
            Slots::getInstance()->booking->cancelReservation($booking->getId());
            Craft::$app->getSession()->setNotice(Craft::t('slots', 'messages.bookingCancelledAccount'));
        } catch (\Exception $e) {
            Craft::error("Failed to cancel booking: " . $e->getMessage(), __METHOD__);
            Craft::$app->getSession()->setError(Craft::t('slots', 'messages.cancelBookingFailed'));
        }

        return $this->redirectToPostedUrl();
    }

    public function actionCurrentUser(): Response
    {
        $user = Craft::$app->getUser()->getIdentity();
        $this->closeSession();

        if (!$user) {
            return $this->asJson(['loggedIn' => false]);
        }

        return $this->asJson([
            'loggedIn' => true,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->fullName ?? $user->username,
                'firstName' => $user->firstName ?? '',
                'lastName' => $user->lastName ?? '',
                'phone' => $user->phone ?? null,
            ],
        ]);
    }
}
