<?php

namespace anvildev\slots\services;

use anvildev\slots\elements\Service;
use anvildev\slots\factories\ReservationFactory;
use anvildev\slots\helpers\ElementQueryHelper;
use anvildev\slots\Slots;
use Craft;
use craft\base\Component;
use yii\db\Query;

class DashboardService extends Component
{
    /** @param int[]|null $staffEmployeeIds */
    public function getDashboardData(?array $staffEmployeeIds): array
    {
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $cacheKey = 'slots_dashboard_' . $siteId . '_' . md5(serialize($staffEmployeeIds));

        return Craft::$app->getCache()->getOrSet($cacheKey, function() use ($staffEmployeeIds) {
            return $this->buildDashboardData($staffEmployeeIds);
        }, 60);
    }

    /** @param int[]|null $staffEmployeeIds */
    private function buildDashboardData(?array $staffEmployeeIds): array
    {
        $permissionService = Slots::getInstance()->getPermission();
        $timezone = new \DateTimeZone(Craft::$app->getTimeZone());
        $now = new \DateTime('now', $timezone);
        $today = (clone $now)->setTime(0, 0, 0);
        $weekFromNow = (clone $today)->modify('+6 days');
        $lastWeekStart = (clone $today)->modify('-7 days');
        $lastWeekEnd = (clone $today)->modify('-1 day');

        // Next upcoming booking
        $nextBooking = $this->getNextUpcomingBooking($now, $staffEmployeeIds);

        // Today's bookings
        $todayBookings = $permissionService->scopeReservationQuery(
            ReservationFactory::find()->bookingDate($today->format('Y-m-d'))->status('confirmed')
        )->count();

        $bookingsSparkline = $this->getSparklineData($today, 7, 'bookings', $permissionService);
        $todaySparkline = $this->getSparklineData($today, 1, 'bookings', $permissionService);
        $todayAverage = count($todaySparkline) > 0 ? array_sum($todaySparkline) / count($todaySparkline) : 0;
        $todayVsAvg = $todayAverage > 0 ? (($todayBookings - $todayAverage) / $todayAverage) * 100 : 0;

        // Next 7 days
        $upcomingBookings = $permissionService->scopeReservationQuery(
            ReservationFactory::find()
                ->bookingDate(['and', '>= ' . $today->format('Y-m-d'), '<= ' . $weekFromNow->format('Y-m-d')])
                ->status('confirmed')
        )->count();

        $lastWeekBookings = $permissionService->scopeReservationQuery(
            ReservationFactory::find()
                ->bookingDate(['and', '>= ' . $lastWeekStart->format('Y-m-d'), '<= ' . $lastWeekEnd->format('Y-m-d')])
                ->status('confirmed')
        )->count();

        $weeklySparkline = $bookingsSparkline;
        $weeklyChange = $lastWeekBookings > 0 ? (($upcomingBookings - $lastWeekBookings) / $lastWeekBookings) * 100 : 0;

        // Repeat customers
        $repeatEmailsQuery = (new Query())
            ->select('userEmail')->from('{{%slots_reservations}}')
            ->where(['status' => 'confirmed']);
        if ($staffEmployeeIds !== null) {
            $repeatEmailsQuery->andWhere(['employeeId' => $staffEmployeeIds]);
        }
        $repeatEmailsQuery->groupBy('userEmail')->having('COUNT(*) >= 2');

        $repeatCustomers = (int) (new Query())->from(['repeat' => $repeatEmailsQuery])->count();
        $repeatCustomerSparkline = $this->getRepeatCustomerSparklineData($today, 7, $staffEmployeeIds);
        $lastWeekRepeatSparkline = $this->getRepeatCustomerSparklineData($lastWeekEnd, 7, $staffEmployeeIds);
        $lastWeekRepeatTotal = array_sum($lastWeekRepeatSparkline);
        $repeatCustomerChange = $lastWeekRepeatTotal > 0
            ? ((array_sum($repeatCustomerSparkline) - $lastWeekRepeatTotal) / $lastWeekRepeatTotal) * 100
            : 0;

        // Revenue
        $revenueMetrics = $this->calculateRevenueMetrics($today, $lastWeekStart, $lastWeekEnd, $permissionService);

        // Recent activity
        $recentActivity = $permissionService->scopeReservationQuery(
            ReservationFactory::find()->withRelations()->orderBy(['dateCreated' => SORT_DESC])->limit(10)
        )->all();


        return [
            'nextBooking' => $nextBooking,
            'todayBookings' => $todayBookings,
            'todaySparkline' => $todaySparkline,
            'todayVsAvg' => $todayVsAvg,
            'upcomingBookings' => $upcomingBookings,
            'weeklySparkline' => $weeklySparkline,
            'weeklyChange' => $weeklyChange,
            'repeatCustomers' => $repeatCustomers,
            'repeatCustomerSparkline' => $repeatCustomerSparkline,
            'repeatCustomerChange' => $repeatCustomerChange,
            'weeklyRevenue' => $revenueMetrics['thisWeekRevenue'],
            'revenueSparkline' => $revenueMetrics['sparkline'],
            'revenueChange' => $revenueMetrics['revenueChange'],
            'averageBookingValue' => $revenueMetrics['averageBookingValue'],
            'avgValueChange' => $revenueMetrics['avgValueChange'],
            'recentActivity' => $recentActivity,
            'popularServices' => $this->getPopularServices($permissionService->getStaffEmployeeIds()),
            'currency' => Slots::getInstance()->reports->getCurrency(),
        ];
    }

    private function calculateRevenueMetrics(
        \DateTime $today,
        \DateTime $lastWeekStart,
        \DateTime $lastWeekEnd,
        PermissionService $permissionService,
    ): array {
        $staffIds = $permissionService->getStaffEmployeeIds();
        $sparkline = $this->getSparklineData($today, 7, 'revenue', $permissionService);
        $thisWeekRevenue = array_sum($sparkline);

        $lastWeekRevenue = array_sum($this->getSparklineData($lastWeekEnd, 7, 'revenue', $permissionService));
        $revenueChange = $lastWeekRevenue > 0 ? (($thisWeekRevenue - $lastWeekRevenue) / $lastWeekRevenue) * 100 : 0;

        $averageBookingValue = $this->aggregateAverageBookingValue(null, null, $staffIds);

        $thisWeekAvgValue = $this->aggregateAverageBookingValue(
            $lastWeekStart->format('Y-m-d'), $today->format('Y-m-d'), $staffIds
        );

        $twoWeeksAgo = (clone $lastWeekStart)->modify('-7 days');
        $lastWeekAvgValue = $this->aggregateAverageBookingValue(
            $twoWeeksAgo->format('Y-m-d'), $lastWeekStart->format('Y-m-d'), $staffIds
        );

        return [
            'sparkline' => $sparkline,
            'thisWeekRevenue' => $thisWeekRevenue,
            'revenueChange' => $revenueChange,
            'averageBookingValue' => $averageBookingValue,
            'avgValueChange' => $lastWeekAvgValue > 0 ? (($thisWeekAvgValue - $lastWeekAvgValue) / $lastWeekAvgValue) * 100 : 0,
        ];
    }

    /** @param int[]|null $staffEmployeeIds */
    private function getNextUpcomingBooking(\DateTime $now, ?array $staffEmployeeIds): ?array
    {
        $today = $now->format('Y-m-d');
        $query = ReservationFactory::find()
            ->status('confirmed')->withRelations()
            ->andWhere(['or',
                ['and', ['bookingDate' => $today], ['>', 'startTime', $now->format('H:i')]],
                ['>', 'bookingDate', $today],
            ])
            ->orderBy(['bookingDate' => SORT_ASC, 'startTime' => SORT_ASC])
            ->limit(1);

        if ($staffEmployeeIds !== null) {
            $query->andWhere(['employeeId' => $staffEmployeeIds]);
        }

        $booking = $query->one();
        if (!$booking) {
            return null;
        }

        $diff = $now->diff(new \DateTime($booking->bookingDate . ' ' . $booking->startTime));
        $countdown = match (true) {
            $diff->days > 0 => $diff->days . 'd ' . $diff->h . 'h',
            $diff->h > 0 => $diff->h . 'h ' . $diff->i . 'm',
            default => $diff->i . 'm',
        };

        $serviceName = null;
        if ($booking->serviceId) {
            $serviceName = ElementQueryHelper::forAllSites(Service::find()->id($booking->serviceId))->one()?->title;
        }

        return [
            'id' => $booking->id,
            'customerName' => $booking->userName ?? $booking->userEmail ?? 'Guest',
            'customerEmail' => $booking->userEmail,
            'serviceName' => $serviceName ?? 'Appointment',
            'date' => $booking->bookingDate,
            'time' => $booking->startTime,
            'countdown' => $countdown,
            'isToday' => $booking->bookingDate === $today,
        ];
    }

    private function getSparklineData(\DateTime $endDate, int $days, string $type, PermissionService $permissionService): array
    {
        $startDate = (clone $endDate)->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        $endDateStr = $endDate->format('Y-m-d');

        $query = (new Query())
            ->from('{{%slots_reservations}} r')
            ->where(['r.status' => 'confirmed'])
            ->andWhere(['between', 'r.bookingDate', $startDate, $endDateStr])
            ->groupBy('r.bookingDate');

        $staffIds = $permissionService->getStaffEmployeeIds();
        if ($staffIds !== null) {
            $query->andWhere(['r.employeeId' => $staffIds]);
        }

        if ($type === 'bookings') {
            $query->select(['r.bookingDate', 'cnt' => 'COUNT(*)']);
        } else {
            $query->leftJoin('{{%slots_services}} s', 'r.[[serviceId]] = s.[[id]]')
                ->select([
                    'r.bookingDate',
                    'cnt' => 'COALESCE(SUM(COALESCE(s.[[price]], 0) * r.[[quantity]]), 0)',
                ]);
        }

        $rows = $query->all();
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['bookingDate']] = (float) $row['cnt'];
        }

        // Fill in all days, including those with zero
        $data = [];
        $cursor = (clone $endDate)->modify('-' . ($days - 1) . ' days');
        while (count($data) < $days) {
            $date = $cursor->format('Y-m-d');
            $data[] = $indexed[$date] ?? 0;
            $cursor->modify('+1 day');
        }

        return $data;
    }

    /** @param int[]|null $staffEmployeeIds */
    private function getRepeatCustomerSparklineData(\DateTime $endDate, int $days, ?array $staffEmployeeIds = null): array
    {
        $repeatEmailsQuery = (new Query())
            ->select('userEmail')->from('{{%slots_reservations}}')
            ->where(['status' => 'confirmed']);
        if ($staffEmployeeIds !== null) {
            $repeatEmailsQuery->andWhere(['employeeId' => $staffEmployeeIds]);
        }
        $repeatEmailsQuery->groupBy('userEmail')->having('COUNT(*) >= 2');

        $startDate = (clone $endDate)->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        $endDateStr = $endDate->format('Y-m-d');

        $dayQuery = (new Query())
            ->select(['bookingDate', 'cnt' => 'COUNT(DISTINCT [[userEmail]])'])
            ->from('{{%slots_reservations}}')
            ->where(['status' => 'confirmed'])
            ->andWhere(['between', 'bookingDate', $startDate, $endDateStr])
            ->andWhere(['in', 'userEmail', $repeatEmailsQuery]);
        if ($staffEmployeeIds !== null) {
            $dayQuery->andWhere(['employeeId' => $staffEmployeeIds]);
        }
        $rows = $dayQuery->groupBy('bookingDate')->all();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['bookingDate']] = (int) $row['cnt'];
        }

        $data = [];
        $cursor = (clone $endDate)->modify('-' . ($days - 1) . ' days');
        while (count($data) < $days) {
            $date = $cursor->format('Y-m-d');
            $data[] = $indexed[$date] ?? 0;
            $cursor->modify('+1 day');
        }

        return $data;
    }

    /** @param int[]|null $staffEmployeeIds */
    private function getPopularServices(?array $staffEmployeeIds): array
    {
        $query = (new Query())
            ->select(['r.serviceId', 'cnt' => 'COUNT(*)'])
            ->from('{{%slots_reservations}} r')
            ->where(['r.status' => 'confirmed'])
            ->andWhere(['not', ['r.serviceId' => null]])
            ->groupBy('r.serviceId')
            ->orderBy(['cnt' => SORT_DESC])
            ->limit(3);

        if ($staffEmployeeIds !== null) {
            $query->andWhere(['r.employeeId' => $staffEmployeeIds]);
        }

        $rows = $query->all();
        $popular = [];
        foreach ($rows as $row) {
            $service = ElementQueryHelper::forAllSites(Service::find()->id($row['serviceId']))->one();
            if ($service) {
                $popular[] = ['name' => $service->title, 'bookings' => (int) $row['cnt']];
            }
        }
        return $popular;
    }

    /** @param int[]|null $staffEmployeeIds */
    private function aggregateAverageBookingValue(?string $startDate, ?string $endDate, ?array $staffEmployeeIds): float
    {
        $query = (new Query())
            ->from('{{%slots_reservations}} r')
            ->leftJoin('{{%slots_services}} s', 'r.[[serviceId]] = s.[[id]]')
            ->where(['r.status' => 'confirmed']);

        if ($startDate && $endDate) {
            $query->andWhere(['between', 'r.bookingDate', $startDate, $endDate]);
        }
        if ($staffEmployeeIds !== null) {
            $query->andWhere(['r.employeeId' => $staffEmployeeIds]);
        }

        return (float) $query->average('COALESCE(s.price, 0) * r.quantity');
    }
}
