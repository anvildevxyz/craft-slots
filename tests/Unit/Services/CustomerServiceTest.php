<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\services\CustomerService;
use anvildev\slots\tests\Support\TestCase;

/**
 * The customer index is an aggregation over bookings — there is no customer
 * table — so most of what can go wrong is in the SQL and in how a customer is
 * identified. Executing it needs a database, so these assert the properties
 * that decide whether the numbers come out right.
 */
class CustomerServiceTest extends TestCase
{
    private function source(): string
    {
        return file_get_contents(dirname(__DIR__, 3) . '/src/services/CustomerService.php');
    }

    private function controllerSource(): string
    {
        return file_get_contents(dirname(__DIR__, 3) . '/src/controllers/cp/CustomersController.php');
    }

    /**
     * Source with comments removed. A docblock that names a hazard must not be
     * read as the hazard — this test file was failing on its own explanation of
     * why urldecode() is wrong here.
     */
    private function codeOnly(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /**
     * Every SQL-looking string literal in the file.
     *
     * @return list<string>
     */
    private function sqlLiterals(string $source): array
    {
        $literals = [];
        foreach (token_get_all($source) as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $value = trim($token[1], "'\"");
            if (preg_match('/\b(SELECT|SUM|COUNT|MIN|MAX|LOWER|CASE WHEN)\b|\[\[/', $value)) {
                $literals[] = $value;
            }
        }

        return $literals;
    }

    public function testSortableColumnsAreDeclared(): void
    {
        $this->assertSame(
            ['name', 'email', 'totalBookings', 'lastBooking', 'firstBooking'],
            CustomerService::SORTABLE,
        );
    }

    /**
     * A sort column reaches ORDER BY, so anything outside the allowlist has to
     * be rejected rather than interpolated.
     */
    public function testUnknownSortFallsBackRatherThanReachingTheQuery(): void
    {
        $this->assertStringContainsString(
            "if (!in_array(\$sort, self::SORTABLE, true)) {",
            $this->source(),
            'search() must reject a sort column it does not recognise',
        );
    }

    /**
     * Email is the customer identity. Someone booking as `Ann@` and later
     * `ann@` is one person, and comparing case-sensitively splits their
     * history — and their spend — in two.
     */
    public function testEmailIsMatchedCaseInsensitively(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('LOWER(r.[[userEmail]])', $source);
        $this->assertStringContainsString('mb_strtolower', $source);
    }

    /**
     * Postgres folds unquoted identifiers to lowercase, so every camelCase
     * column in raw SQL has to be bracket-quoted for Yii to quote it properly.
     */
    public function testCamelCaseColumnsAreBracketQuotedForPostgres(): void
    {
        $columns = ['userEmail', 'userName', 'userPhone', 'employeeId', 'bookingDate', 'refundedAmount', 'reservationId', 'dateCreated', 'userId'];
        $unquoted = [];

        foreach ($this->sqlLiterals($this->source()) as $sql) {
            foreach ($columns as $column) {
                if (preg_match('/(?<!\[\[)\b' . $column . '\b(?!\]\])/', $sql)) {
                    $unquoted[] = "{$column} in \"{$sql}\"";
                }
            }
        }

        $this->assertSame([], $unquoted, 'These columns appear unquoted in SQL and will break on Postgres');
    }

    /**
     * `SUM(status = 'x')` is MySQL-only and `FILTER` is Postgres-only.
     */
    public function testConditionalCountsUsePortableSql(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('SUM(CASE WHEN', $source);
        $this->assertStringNotContainsString('FILTER (WHERE', $source);
    }

    /**
     * Staff are scoped to the employees they manage on the bookings index, and
     * the customer list shows the same records grouped differently — leaving it
     * unscoped would leak every customer to a limited user.
     */
    public function testTheListIsScopedToWhatTheUserMaySee(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('getStaffEmployeeIds()', $source);
        $this->assertStringContainsString('scopeReservationQuery', $source);
    }

    /**
     * Only settled money counts. A pending intent is not revenue, and counting
     * it would overstate what a customer has paid.
     */
    public function testSpendCountsOnlySettledPaymentsNetOfRefunds(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('STATUS_PAID', $source);
        $this->assertStringContainsString('STATUS_PARTIALLY_REFUNDED', $source);
        $this->assertStringNotContainsString('STATUS_PENDING', $source);
        $this->assertStringContainsString('p.[[amount]] - p.[[refundedAmount]]', $source);
    }

    /**
     * The displayed name comes from the newest booking. An aggregate cannot say
     * "the name they used last" — MIN() gives the alphabetically first, which
     * is how a customer ends up filed under a name they used once.
     */
    public function testDisplayNameComesFromTheMostRecentBooking(): void
    {
        $this->assertStringContainsString('attachLatestDetails', $this->source());
    }

    /**
     * Yii percent-decodes a route parameter before the action sees it. Decoding
     * again turns `+` into a space, so `first+tag@example.com` — ordinary
     * plus-addressing — silently becomes an address that matches nobody. This
     * shipped, and the detail page 404'd for every customer with a `+`.
     */
    public function testTheDetailActionDoesNotDecodeTheEmailTwice(): void
    {
        $this->assertStringNotContainsString(
            'urldecode(',
            $this->codeOnly($this->controllerSource()),
            'The route parameter is already decoded; decoding again breaks plus-addressed emails',
        );
    }

    public function testTheControllerRequiresPermissionToViewBookings(): void
    {
        $this->assertStringContainsString(
            "requirePermission('slots-viewBookings')",
            $this->controllerSource(),
        );
    }

    public function testRoutesAndNavAreRegistered(): void
    {
        $plugin = file_get_contents(dirname(__DIR__, 3) . '/src/Slots.php');

        $this->assertStringContainsString("'slots/customers' => 'slots/cp/customers/index'", $plugin);
        $this->assertStringContainsString("'slots/customers/<email:.+>' => 'slots/cp/customers/detail'", $plugin);
        $this->assertStringContainsString("'nav.customers'", $plugin);
    }
}
