<?php

namespace anvildev\slots\tests\Unit\Controllers;

use anvildev\slots\controllers\BookingManagementController;
use anvildev\slots\tests\Support\TestCase;

/**
 * BookingManagementController Test
 *
 * Source-level checks for the booking management controller.
 */
class BookingManagementControllerTest extends TestCase
{
    private string $controllerSource;
    private string $bookedSource;

    protected function setUp(): void
    {
        parent::setUp();
        $srcDir = dirname(__DIR__, 3) . '/src';
        $this->controllerSource = file_get_contents($srcDir . '/controllers/BookingManagementController.php');
        $this->bookedSource = file_get_contents($srcDir . '/Slots.php');
    }

    public function testDownloadIcsIsAllowedAnonymous(): void
    {
        $this->assertStringContainsString(
            "'download-ics'",
            $this->controllerSource,
            'download-ics should be listed in $allowAnonymous'
        );
    }

    public function testDownloadIcsActionMethodExists(): void
    {
        $this->assertStringContainsString(
            'function actionDownloadIcs(string $token)',
            $this->controllerSource,
            'actionDownloadIcs method should exist in BookingManagementController'
        );
    }

    public function testIcsRouteExistsInBookedPhp(): void
    {
        $this->assertStringContainsString(
            "booking/ics/<token:[^\/]+>",
            $this->bookedSource,
            'ICS route should be registered in Slots.php site routes'
        );
    }

    public function testIcsRouteMapsToCorrectAction(): void
    {
        $this->assertStringContainsString(
            "'booking/ics/<token:[^\\/]+>' => 'slots/booking-management/download-ics'",
            $this->bookedSource,
            'ICS route should map to booking-management/download-ics action'
        );
    }

    public function testManageBookingJsonResponseDoesNotExposeConfirmationToken(): void
    {
        // Extract the actionManageBooking method body and verify it does not
        // include 'token' => ... getConfirmationToken() in the asJson array
        $this->assertStringNotContainsString(
            "'token' => \$reservation->getConfirmationToken()",
            $this->controllerSource,
            'actionManageBooking JSON response must not expose confirmationToken'
        );
    }

    public function testCancelBookingByTokenSanitizesReason(): void
    {
        $this->assertStringContainsString(
            'strip_tags',
            $this->controllerSource,
            'Cancellation reason must be sanitized with strip_tags'
        );
    }

    // ── Confirmation token verification tests ──

    public function testCancelBookingRequiresTokenBodyParam(): void
    {
        $this->assertStringContainsString(
            "getRequiredBodyParam('token')",
            $this->controllerSource,
            'actionCancelBooking must require a token body param'
        );
    }

    public function testCancelBookingValidatesTokenWithHashEquals(): void
    {
        // Extract the actionCancelBooking method to verify hash_equals is used
        preg_match('/function actionCancelBooking\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertNotEmpty($matches, 'actionCancelBooking method should exist');
        $this->assertStringContainsString(
            'hash_equals($reservation->getConfirmationToken(), $token)',
            $matches[0],
            'actionCancelBooking must use hash_equals to compare confirmation token (timing-safe)'
        );
    }

    public function testCancelBookingRejectsWrongTokenWithForbidden(): void
    {
        preg_match('/function actionCancelBooking\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertStringContainsString(
            'ForbiddenHttpException',
            $matches[0],
            'actionCancelBooking must throw ForbiddenHttpException on wrong token'
        );
    }

    public function testCancelBookingLogsAuthFailureOnWrongToken(): void
    {
        preg_match('/function actionCancelBooking\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertStringContainsString(
            "logAuthFailure('invalid_cancel_token'",
            $matches[0],
            'actionCancelBooking must log auth failure on invalid token'
        );
    }

    public function testReduceQuantityValidatesTokenWithHashEquals(): void
    {
        preg_match('/function actionReduceQuantity\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertNotEmpty($matches, 'actionReduceQuantity method should exist');
        $this->assertStringContainsString(
            'hash_equals($reservation->getConfirmationToken(), $token)',
            $matches[0],
            'actionReduceQuantity must use hash_equals for token validation'
        );
    }

    public function testIncreaseQuantityValidatesTokenWithHashEquals(): void
    {
        preg_match('/function actionIncreaseQuantity\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertNotEmpty($matches, 'actionIncreaseQuantity method should exist');
        $this->assertStringContainsString(
            'hash_equals($reservation->getConfirmationToken(), $token)',
            $matches[0],
            'actionIncreaseQuantity must use hash_equals for token validation'
        );
    }

    public function testManageBookingRequiresTokenForLookup(): void
    {
        // The manage-booking action uses findByToken which performs an exact DB match
        preg_match('/function actionManageBooking\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertNotEmpty($matches, 'actionManageBooking method should exist');
        $this->assertStringContainsString(
            'ReservationFactory::findByToken($token)',
            $matches[0],
            'actionManageBooking must use findByToken for secure lookup'
        );
    }

    public function testManageBookingReturns404ForMissingToken(): void
    {
        preg_match('/function actionManageBooking\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertStringContainsString(
            'NotFoundHttpException',
            $matches[0],
            'actionManageBooking must throw NotFoundHttpException when token not found'
        );
    }

    public function testCancelBookingByTokenUsesTokenForLookup(): void
    {
        preg_match('/function actionCancelBookingByToken\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertNotEmpty($matches, 'actionCancelBookingByToken method should exist');
        $this->assertStringContainsString(
            'ReservationFactory::findByToken($token)',
            $matches[0],
            'actionCancelBookingByToken must look up reservation by token'
        );
    }

    public function testManageBookingJsonResponseDoesNotExposeToken(): void
    {
        // Ensure the JSON response from manage-booking doesn't leak the confirmation token
        preg_match('/function actionManageBooking\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertStringNotContainsString(
            'confirmationToken',
            $matches[0],
            'actionManageBooking JSON response must not expose confirmationToken field'
        );
    }

    public function testRescheduleIsProtectedByManagementToken(): void
    {
        // Reschedule is only accessible through actionManageBooking which requires a valid token
        // Verify handleRescheduleAction is private (not directly callable as an action)
        $reflection = new \ReflectionMethod(BookingManagementController::class, 'handleRescheduleAction');
        $this->assertTrue(
            $reflection->isPrivate(),
            'handleRescheduleAction must be private (only accessible through token-protected actionManageBooking)'
        );
    }

    public function testHandleCancelActionIsPrivate(): void
    {
        $reflection = new \ReflectionMethod(BookingManagementController::class, 'handleCancelAction');
        $this->assertTrue(
            $reflection->isPrivate(),
            'handleCancelAction must be private (only accessible through token-protected actionManageBooking)'
        );
    }
}
