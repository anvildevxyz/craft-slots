<?php

namespace anvildev\slots\services;

use anvildev\slots\factories\ReservationFactory;
use anvildev\slots\records\PaymentRecord;
use anvildev\slots\records\ReservationRecord;
use anvildev\slots\Slots;
use Craft;
use craft\db\Query;
use yii\base\Component;

/**
 * Reads customers back out of the bookings they made.
 *
 * There is no customer table. A booking only ever needed a name, an email and a
 * phone number — no account, no signup — so a "customer" here is every booking
 * that shares an email address, aggregated on read. That keeps the promise that
 * a booking can be taken from a stranger, and it means this view is correct for
 * data that predates it.
 *
 * Email is the identity, compared case-insensitively: someone who books once as
 * `Ann@example.com` and again as `ann@example.com` is one person, and treating
 * them as two would split their history in half.
 */
class CustomerService extends Component
{
    public const SORTABLE = ['name', 'email', 'totalBookings', 'lastBooking', 'firstBooking'];

    /**
     * One page of customers, newest booking first by default.
     *
     * @return array{customers: list<array<string, mixed>>, total: int}
     */
    public function search(
        string $search = '',
        string $sort = 'lastBooking',
        string $dir = 'desc',
        int $limit = 50,
        int $offset = 0,
    ): array {
        if (!in_array($sort, self::SORTABLE, true)) {
            $sort = 'lastBooking';
        }
        $direction = strtolower($dir) === 'asc' ? SORT_ASC : SORT_DESC;

        $base = $this->baseQuery($search);

        $total = (int)(new Query())
            ->from(['grouped' => $this->summaryQuery($base)])
            ->count('*');

        if ($total === 0) {
            return ['customers' => [], 'total' => 0];
        }

        $rows = $this->summaryQuery($base)
            ->orderBy([$sort => $direction, 'emailKey' => SORT_ASC])
            ->limit(max(1, $limit))
            ->offset(max(0, $offset))
            ->all();

        $rows = $this->attachLatestDetails($rows);
        $rows = $this->attachSpend($rows);

        return ['customers' => $rows, 'total' => $total];
    }

    /**
     * A single customer's summary, or null when no booking carries that email.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $email): ?array
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        $base = $this->baseQuery('')->andWhere($this->emailMatches($email));

        $row = $this->summaryQuery($base)->one();
        if (!$row) {
            return null;
        }

        $rows = $this->attachSpend($this->attachLatestDetails([$row]));

        return $rows[0];
    }

    /**
     * Every booking this customer has made, most recent first.
     *
     * @return list<\anvildev\slots\contracts\ReservationInterface>
     */
    public function bookingsFor(string $email): array
    {
        $query = ReservationFactory::find()
            ->withRelations()
            ->orderBy(['bookingDate' => SORT_DESC, 'startTime' => SORT_DESC]);

        Slots::getInstance()->getPermission()->scopeReservationQuery($query);
        $query->andWhere($this->emailMatches($email, 'slots_reservations'));

        return $query->all();
    }

    /**
     * The Craft user this customer's bookings are linked to, if any.
     *
     * Bookings need no account, so most customers have none. A booking made
     * while logged in records the user id, which is what links the two.
     */
    public function linkedUser(?int $userId): ?\craft\elements\User
    {
        return $userId ? Craft::$app->getUsers()->getUserById($userId) : null;
    }

    /**
     * Reservations the current user is allowed to see, before grouping.
     */
    private function baseQuery(string $search): Query
    {
        $query = (new Query())->from(['r' => ReservationRecord::tableName()]);

        // Staff see only the employees they manage, matching the bookings index.
        $employeeIds = Slots::getInstance()->getPermission()->getStaffEmployeeIds();
        if ($employeeIds !== null) {
            $query->andWhere($employeeIds === [] ? '0=1' : ['r.[[employeeId]]' => $employeeIds]);
        }

        $search = trim($search);
        if ($search !== '') {
            $query->andWhere([
                'or',
                ['like', 'r.[[userName]]', $search],
                ['like', 'r.[[userEmail]]', $search],
                ['like', 'r.[[userPhone]]', $search],
            ]);
        }

        return $query;
    }

    /**
     * Groups bookings into one row per customer.
     *
     * The counts use SUM(CASE …) rather than a boolean sum or FILTER, both of
     * which are dialect-specific, and every camelCase column is bracket-quoted
     * so Postgres does not fold it to lowercase.
     */
    private function summaryQuery(Query $base): Query
    {
        $query = clone $base;

        return $query
            ->select([
                'emailKey' => 'LOWER(r.[[userEmail]])',
                'email' => 'MIN(r.[[userEmail]])',
                'totalBookings' => 'COUNT(*)',
                'cancelledBookings' => new \yii\db\Expression(
                    'SUM(CASE WHEN r.[[status]] = :cancelled THEN 1 ELSE 0 END)',
                    [':cancelled' => ReservationRecord::STATUS_CANCELLED],
                ),
                'noShowBookings' => new \yii\db\Expression(
                    'SUM(CASE WHEN r.[[status]] = :noShow THEN 1 ELSE 0 END)',
                    [':noShow' => ReservationRecord::STATUS_NO_SHOW],
                ),
                'upcomingBookings' => new \yii\db\Expression(
                    'SUM(CASE WHEN r.[[bookingDate]] >= :today AND r.[[status]] <> :cancelledToo THEN 1 ELSE 0 END)',
                    [':today' => (new \DateTime('today'))->format('Y-m-d'), ':cancelledToo' => ReservationRecord::STATUS_CANCELLED],
                ),
                'firstBooking' => 'MIN(r.[[bookingDate]])',
                'lastBooking' => 'MAX(r.[[bookingDate]])',
                'userId' => 'MAX(r.[[userId]])',
                // Sorting by name has to sort by something in the GROUP BY, and
                // the display name is resolved separately below.
                'name' => 'MIN(r.[[userName]])',
            ])
            ->groupBy(['LOWER(r.[[userEmail]])']);
    }

    /**
     * Fills in the name and phone from each customer's most recent booking.
     *
     * An aggregate cannot answer "the name they used last" — MIN() gives the
     * alphabetically first, which is how a customer ends up displayed under a
     * name they used once, years ago.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function attachLatestDetails(array $rows): array
    {
        if (!$rows) {
            return [];
        }

        $keys = array_column($rows, 'emailKey');

        $latest = (new Query())
            ->select(['[[userEmail]]', '[[userName]]', '[[userPhone]]', '[[userId]]', '[[bookingDate]]', '[[dateCreated]]'])
            ->from(ReservationRecord::tableName())
            ->where(['IN', new \yii\db\Expression('LOWER([[userEmail]])'), $keys])
            ->orderBy(['[[bookingDate]]' => SORT_ASC, '[[dateCreated]]' => SORT_ASC])
            ->all();

        // Ordered ascending, so the last write per key wins — the newest booking.
        $byKey = [];
        foreach ($latest as $row) {
            $byKey[mb_strtolower((string)$row['userEmail'])] = $row;
        }

        foreach ($rows as $i => $row) {
            $match = $byKey[$row['emailKey']] ?? null;
            $rows[$i]['name'] = $match['userName'] ?? $row['name'];
            $rows[$i]['phone'] = $match['userPhone'] ?? null;
            $rows[$i]['email'] = $match['userEmail'] ?? $row['email'];
            $rows[$i]['userId'] = $match['userId'] ?? $row['userId'];
            $rows[$i]['totalBookings'] = (int)$row['totalBookings'];
            $rows[$i]['cancelledBookings'] = (int)$row['cancelledBookings'];
            $rows[$i]['noShowBookings'] = (int)$row['noShowBookings'];
            $rows[$i]['upcomingBookings'] = (int)$row['upcomingBookings'];
        }

        return $rows;
    }

    /**
     * Adds what each customer has actually paid, net of refunds.
     *
     * Only settled payments count — a pending intent is not money received.
     * Amounts are minor units, as stored.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function attachSpend(array $rows): array
    {
        if (!$rows) {
            return [];
        }

        $keys = array_column($rows, 'emailKey');

        $spend = (new Query())
            ->select([
                'emailKey' => 'LOWER(r.[[userEmail]])',
                'paid' => 'SUM(p.[[amount]] - p.[[refundedAmount]])',
                'currency' => 'MIN(p.[[currency]])',
            ])
            ->from(['p' => PaymentRecord::tableName()])
            ->innerJoin(['r' => ReservationRecord::tableName()], 'r.[[id]] = p.[[reservationId]]')
            ->where(['p.[[status]]' => [PaymentRecord::STATUS_PAID, PaymentRecord::STATUS_PARTIALLY_REFUNDED]])
            ->andWhere(['IN', new \yii\db\Expression('LOWER(r.[[userEmail]])'), $keys])
            ->groupBy(['LOWER(r.[[userEmail]])'])
            ->all();

        $byKey = [];
        foreach ($spend as $row) {
            $byKey[$row['emailKey']] = $row;
        }

        foreach ($rows as $i => $row) {
            $match = $byKey[$row['emailKey']] ?? null;
            $rows[$i]['paidMinorUnits'] = (int)($match['paid'] ?? 0);
            $rows[$i]['currency'] = $match['currency']
                ?? Slots::getInstance()->getSettings()->defaultCurrency;
        }

        return $rows;
    }

    /**
     * Case-insensitive email match, for a table alias or the real table name.
     */
    private function emailMatches(string $email, string $table = 'r'): array
    {
        return ['=', new \yii\db\Expression('LOWER(' . $table . '.[[userEmail]])'), mb_strtolower(trim($email))];
    }
}
