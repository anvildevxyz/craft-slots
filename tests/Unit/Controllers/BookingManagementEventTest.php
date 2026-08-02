<?php

namespace anvildev\slots\tests\Unit\Controllers;

use anvildev\slots\controllers\BookingManagementController;
use anvildev\slots\tests\Support\TestCase;

/**
 * BookingManagementController Event Booking Test
 *
 * Tests for event booking management features: JSON response support
 * in actionManageBooking, isEventBooking flag, and quantity management
 * anonymous access.
 */
class BookingManagementEventTest extends TestCase
{
    private string $controllerSource;

    protected function setUp(): void
    {
        parent::setUp();
        $srcDir = dirname(__DIR__, 3) . '/src';
        $this->controllerSource = file_get_contents($srcDir . '/controllers/BookingManagementController.php');
    }

    public function testManageBookingActionExists(): void
    {
        $this->assertTrue(
            method_exists(BookingManagementController::class, 'actionManageBooking'),
            'actionManageBooking method should exist'
        );
    }

    public function testAllowAnonymousIncludesIncreaseQuantity(): void
    {
        $this->assertStringContainsString(
            "'increase-quantity'",
            $this->controllerSource,
            'increase-quantity should be listed in $allowAnonymous'
        );
    }

    public function testAllowAnonymousIncludesReduceQuantity(): void
    {
        $this->assertStringContainsString(
            "'reduce-quantity'",
            $this->controllerSource,
            'reduce-quantity should be listed in $allowAnonymous'
        );
    }

    public function testAllowAnonymousIncludesManageBooking(): void
    {
        $this->assertStringContainsString(
            "'manage-booking'",
            $this->controllerSource,
            'manage-booking should be listed in $allowAnonymous'
        );
    }

    public function testIncreaseQuantityActionExists(): void
    {
        $this->assertTrue(
            method_exists(BookingManagementController::class, 'actionIncreaseQuantity'),
            'actionIncreaseQuantity method should exist'
        );
    }

    public function testReduceQuantityActionExists(): void
    {
        $this->assertTrue(
            method_exists(BookingManagementController::class, 'actionReduceQuantity'),
            'actionReduceQuantity method should exist'
        );
    }

    public function testManageBookingReturnsQuantity(): void
    {
        $this->assertStringContainsString(
            "'quantity' => \$reservation->getQuantity()",
            $this->controllerSource,
            'actionManageBooking JSON response should include quantity'
        );
    }

    public function testManageBookingReturnsCanCancel(): void
    {
        $this->assertStringContainsString(
            "'canCancel' => \$reservation->canBeCancelled()",
            $this->controllerSource,
            'actionManageBooking JSON response should include canCancel flag'
        );
    }

    public function testManageBookingAcceptsTokenFromQueryParam(): void
    {
        $this->assertStringContainsString(
            "getQueryParam('token')",
            $this->controllerSource,
            'actionManageBooking should accept token from query parameter'
        );
    }

    public function testManageBookingSupportsJsonResponse(): void
    {
        $this->assertStringContainsString(
            'getAcceptsJson()',
            $this->controllerSource,
            'actionManageBooking should check for JSON Accept header'
        );
    }

    public function testManageBookingTokenParameterIsNullable(): void
    {
        $reflection = new \ReflectionMethod(BookingManagementController::class, 'actionManageBooking');
        $params = $reflection->getParameters();
        $this->assertCount(1, $params);
        $this->assertTrue($params[0]->allowsNull(), 'token parameter should be nullable');
        $this->assertTrue($params[0]->isDefaultValueAvailable(), 'token parameter should have a default value');
        $this->assertNull($params[0]->getDefaultValue(), 'token parameter default should be null');
    }
}
