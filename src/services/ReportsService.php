<?php

namespace anvildev\slots\services;

use anvildev\slots\elements\Employee;
use anvildev\slots\elements\Location;
use anvildev\slots\elements\Service;
use anvildev\slots\factories\ReservationFactory;
use anvildev\slots\models\Settings;
use anvildev\slots\records\PaymentRecord;
use anvildev\slots\Slots;
use Craft;
use craft\base\Component;
use yii\caching\TagDependency;
use yii\db\Query;

/**
 * Central reporting and analytics service for the Slots plugin.
 * Aggregates reservation, revenue, utilization, and customer data
 * with staff-scoped permission filtering on all queries.
 */
class ReportsService extends Component
{
    public function getCurrency(): string
    {
        $settings = Slots::getInstance()->getSettings();

        if (!empty($settings->defaultCurrency) && $settings->defaultCurrency !== 'auto') {
            return $settings->defaultCurrency;
        }

        if (Craft::$app->plugins->isPluginEnabled('commerce')) {
            try {
                $pc = \craft\commerce\Plugin::getInstance()->getPaymentCurrencies()->getPrimaryPaymentCurrency();
                if ($pc) {
                    return $pc->iso;
                }
            } catch (\Exception) {
            }
        }

        return 'USD';
    }

    public function getRevenueData(?string $startDate, ?string $endDate, bool $includePreviousPeriod = false): array
    {
        $startDate ??= date('Y-m-01');
        $endDate ??= date('Y-m-t');
        $prev = $includePreviousPeriod ? '1' : '0';

        return $this->cachedReport("revenue_{$startDate}_{$endDate}_{$prev}", function() use ($startDate, $endDate, $includePreviousPeriod) {
            $total = $this->aggregateRevenueSum($startDate, $endDate);

            $previousTotal = null;
            $changePercent = null;

            if ($includePreviousPeriod) {
                $start = new \DateTime($startDate);
                $end = new \DateTime($endDate);
                $days = $start->diff($end)->days + 1;

                $prevEnd = (clone $start)->modify('-1 day');
                $prevStart = (clone $prevEnd)->modify('-' . ($days - 1) . ' days');

                $previousTotal = $this->aggregateRevenueSum($prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d'));
                $changePercent = $previousTotal > 0 ? (($total - $previousTotal) / $previousTotal) * 100 : 0.0;
            }

            $reservations = $this->buildReservationQuery($startDate, $endDate)->all();

            return compact('total', 'previousTotal', 'changePercent', 'reservations');
        });
    }

    /**
     * Revenue for a date range. Direct mode = money actually captured
     * (`amount − refundedAmount`) from the payments table; else catalog-priced.
     */
    private function aggregateRevenueSum(string $startDate, string $endDate): float
    {
        if (Slots::getInstance()->getSettings()->getPaymentMode() === Settings::PAYMENT_MODE_DIRECT) {
            return $this->aggregateDirectPaymentsSum($startDate, $endDate);
        }

        $query = (new Query())
            ->from('{{%slots_reservations}} r')
            ->leftJoin('{{%slots_services}} s', 'r.[[serviceId]] = s.[[id]]')
            ->where(['r.status' => 'confirmed'])
            ->andWhere(['between', 'r.bookingDate', $startDate, $endDate]);

        $staffIds = Slots::getInstance()->getPermission()->getStaffEmployeeIds();
        if ($staffIds !== null) {
            $query->andWhere(['r.employeeId' => $staffIds]);
        }

        return (float) $query->sum('COALESCE(s.price, 0) * r.quantity');
    }

    /** Direct-mode revenue: SUM(amount − refundedAmount) of captured rows, one currency. */
    private function aggregateDirectPaymentsSum(string $startDate, string $endDate): float
    {
        // Currency is install-wide (PRD §13). Sum only rows in the current
        // currency and convert once — so a stray legacy row in a different
        // currency can't be summed as raw minor units into the wrong total.
        $currency = $this->getCurrency();

        $query = (new Query())
            ->from('{{%slots_payments}} p')
            ->innerJoin('{{%slots_reservations}} r', 'p.[[reservationId]] = r.[[id]]')
            ->where(['r.status' => 'confirmed'])
            ->andWhere(['between', 'r.bookingDate', $startDate, $endDate])
            ->andWhere(['p.currency' => $currency])
            ->andWhere(['p.status' => [
                PaymentRecord::STATUS_PAID,
                PaymentRecord::STATUS_PARTIALLY_REFUNDED,
                PaymentRecord::STATUS_REFUNDED,
            ]]);

        $staffIds = Slots::getInstance()->getPermission()->getStaffEmployeeIds();
        if ($staffIds !== null) {
            $query->andWhere(['r.employeeId' => $staffIds]);
        }

        $minorUnits = (int) $query->sum('p.[[amount]] - COALESCE(p.[[refundedAmount]], 0)');

        return PaymentService::fromMinorUnits($minorUnits, $currency);
    }

    /** Shared logic for by-service, by-employee, by-location reports. */
    public function invalidateReportCaches(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), 'slots_reports');
    }

    private function cachedReport(string $key, callable $builder, int $ttl = 300): mixed
    {
        $cache = Craft::$app->getCache();
        $fullKey = 'slots_report_' . $key . '_' . md5(serialize(
            Slots::getInstance()->getPermission()->getStaffEmployeeIds()
        ));

        $result = $cache->get($fullKey);
        if ($result !== false) {
            return $result;
        }

        $result = $builder();
        $cache->set($fullKey, $result, $ttl, new TagDependency(['tags' => ['slots_reports']]));

        return $result;
    }

    /** @return \anvildev\slots\contracts\ReservationQueryInterface */
    private function buildReservationQuery(?string $startDate, ?string $endDate, ?string $status = 'confirmed'): \anvildev\slots\contracts\ReservationQueryInterface
    {
        $query = ReservationFactory::find();
        if ($status !== null) {
            $query->status($status);
        }
        if ($startDate && $endDate) {
            $query->bookingDate(['and', '>= ' . $startDate, '<= ' . $endDate]);
        }
        return Slots::getInstance()->getPermission()->scopeReservationQuery($query);
    }
}
