<?php

namespace anvildev\slots\controllers\cp;

use anvildev\slots\controllers\traits\JsonResponseTrait;
use anvildev\slots\factories\ReservationFactory;
use anvildev\slots\records\ReservationRecord;
use anvildev\slots\Slots;
use Craft;
use craft\web\Controller;
use craft\web\Response;

class CalendarViewController extends Controller
{
    use JsonResponseTrait;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission($action->id === 'reschedule' ? 'slots-manageBookings' : 'slots-viewCalendar');
        return true;
    }

    public function actionMonth(): mixed
    {
        $permissionService = Slots::getInstance()->getPermission();
        $request = Craft::$app->request;
        $year = max(2000, min(2100, (int)$request->getParam('year', date('Y'))));
        $month = max(1, min(12, (int)$request->getParam('month', date('m'))));

        $startDate = new \DateTime("{$year}-{$month}-01");
        $endDate = (clone $startDate)->modify('last day of this month');

        $reservations = $permissionService->scopeReservationQuery(
            ReservationFactory::find()
                ->bookingDate(['and', '>= ' . $startDate->format('Y-m-d'), '<= ' . $endDate->format('Y-m-d')])
                ->withRelations()
                ->orderBy(['bookingDate' => SORT_ASC, 'startTime' => SORT_ASC])
        )->all();

        $reservationsByDate = [];
        $bookingCounts = [];
        foreach ($reservations as $reservation) {
            $date = $reservation->bookingDate;
            $reservationsByDate[$date][] = $reservation;
            $bookingCounts[$date] = ($bookingCounts[$date] ?? 0) + 1;
        }

        return $this->renderTemplate('slots/calendar/month', [
            'year' => $year,
            'month' => $month,
            'reservations' => $reservationsByDate,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'selectedDate' => $startDate,
            'bookingCounts' => $bookingCounts,
        ]);
    }

    public function actionWeek(): mixed
    {
        $permissionService = Slots::getInstance()->getPermission();
        $date = Craft::$app->request->getParam('date', date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $currentDate = \DateTime::createFromFormat('Y-m-d', $date) ?: new \DateTime();

        $dayOfWeek = $currentDate->format('N');
        $startDate = (clone $currentDate)->modify('-' . ($dayOfWeek - 1) . ' days');
        $endDate = (clone $startDate)->modify('+6 days');

        $reservations = $permissionService->scopeReservationQuery(
            ReservationFactory::find()
                ->bookingDate(['and', '>= ' . $startDate->format('Y-m-d'), '<= ' . $endDate->format('Y-m-d')])
                ->withRelations()
                ->orderBy(['bookingDate' => SORT_ASC, 'startTime' => SORT_ASC])
        )->all();

        // Initialize week days
        $reservationsByDay = [];
        for ($i = 0; $i < 7; $i++) {
            $day = (clone $startDate)->modify("+{$i} days");
            $reservationsByDay[$day->format('Y-m-d')] = ['date' => $day, 'reservations' => []];
        }

        $bookingCounts = [];
        foreach ($reservations as $reservation) {
            $d = $reservation->bookingDate;
            if (isset($reservationsByDay[$d])) {
                $reservationsByDay[$d]['reservations'][] = $reservation;
            }
            $bookingCounts[$d] = ($bookingCounts[$d] ?? 0) + 1;
        }

        // Month-level booking counts for mini calendar
        $monthBookingCounts = $this->getMonthBookingCounts($currentDate, $permissionService);

        return $this->renderTemplate('slots/calendar/week', [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'reservationsByDay' => $reservationsByDay,
            'selectedDate' => $currentDate,
            'bookingCounts' => $monthBookingCounts,
        ]);
    }

    public function actionDay(): mixed
    {
        $permissionService = Slots::getInstance()->getPermission();
        $date = Craft::$app->request->getParam('date', date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $selectedDate = \DateTime::createFromFormat('Y-m-d', $date) ?: new \DateTime();

        $reservations = $permissionService->scopeReservationQuery(
            ReservationFactory::find()
                ->bookingDate($selectedDate->format('Y-m-d'))
                ->withRelations()
                ->orderBy(['startTime' => SORT_ASC])
        )->all();

        // Create hourly slots 8 AM - 8 PM
        $hourlySlots = array_fill_keys(
            array_map(fn($h) => sprintf('%02d:00', $h), range(8, 20)),
            []
        );

        foreach ($reservations as $reservation) {
            if (isset($reservation->startTime)) {
                $hourKey = sprintf('%02d:00', (int) explode(':', $reservation->startTime)[0]);
                if (isset($hourlySlots[$hourKey])) {
                    $hourlySlots[$hourKey][] = $reservation;
                }
            }
        }

        return $this->renderTemplate('slots/calendar/day', [
            'date' => $selectedDate,
            'hourlySlots' => $hourlySlots,
            'selectedDate' => $selectedDate,
            'bookingCounts' => $this->getMonthBookingCounts($selectedDate, $permissionService),
        ]);
    }

    public function actionGetColorForStatus(): Response
    {
        $status = Craft::$app->request->getParam('status', 'pending');
        $colors = [
            'confirmed' => '#10b981',
            'pending' => '#f59e0b',
            'cancelled' => '#ef4444',
            'completed' => '#3b82f6',
            'no-show' => '#6b7280',
        ];

        return $this->jsonSuccess('', [
            'status' => $status,
            'color' => $colors[$status] ?? '#9ca3af',
        ]);
    }

    public function actionReschedule(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->request;
        $reservationId = (int) $request->getBodyParam('reservationId');
        $query = ReservationFactory::find()->id($reservationId);
        Slots::getInstance()->getPermission()->scopeReservationQuery($query);
        $reservation = $query->one();

        if (!$reservation) {
            return $this->jsonError('Reservation not found');
        }
        if ($reservation->status === ReservationRecord::STATUS_CANCELLED) {
            return $this->jsonError('Cannot reschedule a cancelled booking');
        }

        $newDate = $request->getBodyParam('newDate');
        if (!$newDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate) || !strtotime($newDate)) {
            return $this->jsonError('Invalid date format. Expected Y-m-d.');
        }

        $newStartTime = $request->getBodyParam('newStartTime');
        if ($newStartTime && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $newStartTime)) {
            return $this->jsonError('Invalid time format. Expected HH:MM.');
        }

        $updateData = ['bookingDate' => $newDate];
        if ($newStartTime) {
            $updateData['startTime'] = $newStartTime;
            $oldStartTime = $reservation->startTime;
            $oldEndTime = $reservation->endTime;
            if (isset($oldEndTime)) {
                $duration = (new \DateTime($oldStartTime))->diff(new \DateTime($oldEndTime));
                $updateData['endTime'] = (clone (new \DateTime($newStartTime)))->add($duration)->format('H:i');
            }
        }

        try {
            $updated = Slots::getInstance()->booking->updateReservation($reservationId, $updateData);

            return $this->jsonSuccess('', [
                'reservation' => [
                    'id' => $updated->getId(),
                    'bookingDate' => $updated->getBookingDate(),
                    'startTime' => $updated->getStartTime(),
                    'endTime' => $updated->getEndTime(),
                ],
            ]);
        } catch (\anvildev\slots\exceptions\BookingConflictException $e) {
            return $this->jsonError($e->getMessage());
        } catch (\anvildev\slots\exceptions\BookingException $e) {
            return $this->jsonError($e->getMessage());
        }
    }

    private function getMonthBookingCounts(\DateTime $date, $permissionService): array
    {
        $monthStart = (clone $date)->modify('first day of this month');
        $monthEnd = (clone $date)->modify('last day of this month');
        $monthReservations = $permissionService->scopeReservationQuery(
            ReservationFactory::find()
                ->bookingDate(['and', '>= ' . $monthStart->format('Y-m-d'), '<= ' . $monthEnd->format('Y-m-d')])
        )->all();

        $counts = [];
        foreach ($monthReservations as $r) {
            $d = $r->getBookingDate();
            $counts[$d] = ($counts[$d] ?? 0) + 1;
        }
        return $counts;
    }
}
