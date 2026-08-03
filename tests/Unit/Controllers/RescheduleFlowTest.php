<?php

namespace anvildev\slots\tests\Unit\Controllers;

use anvildev\slots\tests\Support\TestCase;

/**
 * Source-level checks on the reschedule flow.
 *
 * The handler needs a booted Craft to exercise, so these assert the ordering
 * and wiring that make it safe — each corresponds to a way the flow was, or
 * could quietly become, wrong.
 */
class RescheduleFlowTest extends TestCase
{
    private function controllerSource(): string
    {
        return file_get_contents(dirname(__DIR__, 3) . '/src/controllers/BookingManagementController.php');
    }

    private function handlerBody(): string
    {
        $source = $this->controllerSource();
        $start = strpos($source, 'private function handleRescheduleAction');
        $this->assertNotFalse($start, 'handleRescheduleAction() should exist');

        $end = strpos($source, "\n    private function ", $start + 1);

        return $end !== false ? substr($source, $start, $end - $start) : substr($source, $start);
    }

    /**
     * The guard this whole change exists for. Without it a customer inside the
     * no-cancellation window can still move the booking anywhere.
     */
    public function testHandlerEnforcesTheCancellationPolicy(): void
    {
        $this->assertStringContainsString(
            'canBeRescheduled()',
            $this->handlerBody(),
            'handleRescheduleAction() must refuse bookings that are past the cancellation policy',
        );
    }

    /**
     * The policy check has to happen before the booking is written, not after.
     */
    public function testThePolicyCheckPrecedesTheUpdate(): void
    {
        $body = $this->handlerBody();

        $guard = strpos($body, 'canBeRescheduled()');
        $update = strpos($body, 'updateReservation(');

        $this->assertNotFalse($guard);
        $this->assertNotFalse($update);
        $this->assertLessThan($update, $guard, 'The policy must be checked before the reservation is moved');
    }

    /**
     * Availability is re-checked inside updateReservation()'s slot mutex, but
     * the handler still rejects an unavailable slot up front so the customer
     * gets the right message rather than a generic conflict.
     */
    public function testHandlerChecksSlotAvailability(): void
    {
        $this->assertStringContainsString('isSlotAvailable(', $this->handlerBody());
    }

    /**
     * The previous slot has to be read before the update overwrites it, or the
     * email reports the new time as both the old and the new one.
     */
    public function testPreviousSlotIsCapturedBeforeTheUpdate(): void
    {
        $body = $this->handlerBody();

        $capture = strpos($body, '$previousDate = $reservation->getBookingDate()');
        $update = strpos($body, 'updateReservation(');

        $this->assertNotFalse($capture, 'The previous date should be captured for the notification');
        $this->assertLessThan($update, $capture, 'The previous slot must be read before the reservation moves');
    }

    /**
     * The booking has already moved by the time the email is queued, so a queue
     * failure must not be reported to the customer as a failed reschedule.
     */
    public function testNotificationFailureDoesNotFailTheReschedule(): void
    {
        $body = $this->handlerBody();

        $queueCall = strpos($body, 'queueRescheduledNotification(');
        $this->assertNotFalse($queueCall, 'A reschedule should notify the customer');

        $tail = substr($body, $queueCall);
        $this->assertStringContainsString(
            'catch (\Throwable',
            $tail,
            'Queuing the notification must not be able to fail the reschedule',
        );
    }

    public function testRescheduleIsReachableFromTheTokenAction(): void
    {
        $this->assertStringContainsString(
            "\$action === 'reschedule'",
            $this->controllerSource(),
            'The token-authenticated manage action should dispatch a reschedule',
        );
    }

    /**
     * The customer-facing page has to know whether to offer the control at all.
     */
    public function testTemplateAndJsonBothReceiveTheFlag(): void
    {
        $source = $this->controllerSource();

        $this->assertSame(
            2,
            substr_count($source, "'canReschedule' => \$reservation->canBeRescheduled()"),
            'canReschedule should be passed to both the JSON payload and the template',
        );
    }

    public function testManageTemplateOffersRescheduleOnlyWhenPermitted(): void
    {
        $template = file_get_contents(dirname(__DIR__, 3) . '/src/templates/manage-booking.twig');

        $this->assertStringContainsString(
            'not isPast and not isCancelled and canReschedule',
            $template,
            'The reschedule panel must be gated on canReschedule',
        );
    }

    /**
     * Craft reserves `token` as a query parameter and 400s the request before
     * the controller runs, which is why the flow posts `manageToken`.
     */
    public function testTemplatePostsManageTokenRatherThanToken(): void
    {
        // The behaviour moved out of the template into its own script so the
        // page survives a strict CSP; the constraint it encodes did not move.
        $script = file_get_contents(dirname(__DIR__, 3) . '/src/web/js/frontend/manage-page.js');

        // Only the reschedule calls are subject to this: the quantity endpoints
        // take the reservation id plus a `token` body param and always have.
        $reschedule = substr($script, (int) strpos($script, '── Reschedule'));

        $this->assertStringContainsString("params.append('manageToken', config.token)", $reschedule);
        $this->assertStringNotContainsString("params.append('token', config.token)", $reschedule);
    }

    /**
     * The same page is reached straight from an email link, so it has to work
     * under a strict Content-Security-Policy. That holds only while the template
     * ships no executable inline script: a `<script>` with no type (or a JS one)
     * needs 'unsafe-inline', whereas the JSON config block is inert.
     */
    public function testManageTemplateShipsNoExecutableInlineScript(): void
    {
        $template = file_get_contents(dirname(__DIR__, 3) . '/src/templates/manage-booking.twig');

        preg_match_all('/<script\b([^>]*)>/i', $template, $matches);

        foreach ($matches[1] as $attributes) {
            $this->assertMatchesRegularExpression(
                '/type\s*=\s*"application\/json"/i',
                $attributes,
                'Inline <script> on the manage page must be an inert JSON data block, not executable JavaScript',
            );
        }

        $this->assertStringContainsString('SlotsManagePageAsset', $template, 'The behaviour must be delivered as an asset bundle');
    }
}
