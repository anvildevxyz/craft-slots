<?php

namespace anvildev\slots\tests\Unit\Elements;

use anvildev\slots\tests\Support\TestCase;

/**
 * Staff scoping has to live in the element query, not in the controllers.
 *
 * The bookings index is a native Craft element index, and Craft builds that
 * query inside its own controller — so a filter applied by our controller
 * simply never runs for it. Before this moved, switching the template would
 * have shown every staff member every booking in the system.
 *
 * Verified in a browser against a real limited user: they saw 21 of 24
 * bookings, 17 of 20 customers, and an explicit request for another employee's
 * bookings returned nothing.
 */
class ReservationQueryScopeTest extends TestCase
{
    private function querySource(): string
    {
        return file_get_contents(dirname(__DIR__, 3) . '/src/elements/db/ReservationQuery.php');
    }

    public function testTheQueryAppliesStaffScope(): void
    {
        $this->assertStringContainsString(
            'getStaffEmployeeIds()',
            $this->querySource(),
            'ReservationQuery must scope staff itself — Craft builds the index query, not our controller',
        );
    }

    /**
     * Applying it after beforePrepare() has returned would mean it never
     * reaches the query at all.
     */
    public function testTheScopeIsAppliedWhilePreparingTheQuery(): void
    {
        $source = $this->querySource();

        $prepare = strpos($source, 'protected function beforePrepare()');
        $call = strpos($source, '$this->_applyStaffScope(');

        $this->assertNotFalse($prepare);
        $this->assertNotFalse($call, 'beforePrepare() should apply the staff scope');
        $this->assertGreaterThan($prepare, $call);
    }

    /**
     * An empty managed-employee list means "nothing", stated outright. Left to
     * an empty IN() it is driver-dependent, and the failure mode is showing
     * everything rather than nothing.
     */
    public function testAnEmptyManagedListMatchesNothing(): void
    {
        $this->assertStringContainsString("'0=1'", $this->querySource());
    }

    /**
     * Console and queue runs have no user session, and asking for one there is
     * a fatal rather than an empty result.
     */
    public function testTheScopeIsSkippedOutsideWebRequests(): void
    {
        $this->assertStringContainsString(
            'craft\web\Application',
            $this->querySource(),
            'The scope must not try to read a user session in console or queue runs',
        );
    }

    /**
     * The controller must not be the only thing enforcing this. It may still
     * scope explicitly — that is harmless — but the index does not go through it.
     */
    public function testTheIndexActionDoesNotRelyOnControllerScoping(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 3) . '/src/controllers/cp/BookingsController.php');

        $start = strpos($controller, 'public function actionIndex(): Response');
        $this->assertNotFalse($start);

        $end = strpos($controller, "\n    public function ", $start + 1);
        $body = substr($controller, $start, $end !== false ? $end - $start : null);

        $this->assertStringNotContainsString(
            'scopeReservationQuery',
            $body,
            'The index renders an element index; scoping it here would give false confidence',
        );
    }
}
