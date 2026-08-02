<?php

namespace anvildev\slots\tests\Unit\Factories;

use anvildev\slots\contracts\ReservationInterface;
use anvildev\slots\contracts\ReservationQueryInterface;
use anvildev\slots\factories\ReservationFactory;
use anvildev\slots\elements\Reservation;
use anvildev\slots\tests\Support\TestCase;

/**
 * ReservationFactory Test
 *
 * Tests the factory that determines which Reservation implementation to use
 * based on whether Commerce is enabled.
 *
 * Note: Tests requiring full Craft CMS are skipped in unit test mode.
 */
class ReservationFactoryTest extends TestCase
{
    // Class Structure

    public function testFactoryClassExists(): void
    {
        $this->assertTrue(class_exists(ReservationFactory::class));
    }

    public function testHasCreateMethod(): void
    {
        $this->assertTrue(method_exists(ReservationFactory::class, 'create'));
    }

    public function testHasFindMethod(): void
    {
        $this->assertTrue(method_exists(ReservationFactory::class, 'find'));
    }

    public function testHasFindByIdMethod(): void
    {
        $this->assertTrue(method_exists(ReservationFactory::class, 'findById'));
    }

    public function testHasFindByTokenMethod(): void
    {
        $this->assertTrue(method_exists(ReservationFactory::class, 'findByToken'));
    }

    // =========================================================================
    // Factory Returns Interface (Requires Craft)
    // =========================================================================

    public function testCreateReturnsReservationInterface(): void
    {
        $this->requiresCraft();
        $reservation = ReservationFactory::create();
        $this->assertInstanceOf(ReservationInterface::class, $reservation);
    }

    public function testFindReturnsReservationQueryInterface(): void
    {
        $this->requiresCraft();
        $query = ReservationFactory::find();
        $this->assertInstanceOf(ReservationQueryInterface::class, $query);
    }

    // =========================================================================
    // Create Sets Attributes (Requires Craft)
    // =========================================================================

    public function testCreateSetsAttributes(): void
    {
        $this->requiresCraft();
        $reservation = ReservationFactory::create([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
        ]);

        $this->assertEquals('John Doe', $reservation->getUserName());
        $this->assertEquals('john@example.com', $reservation->getUserEmail());
        $this->assertEquals('2025-06-15', $reservation->getBookingDate());
        $this->assertEquals('14:00', $reservation->getStartTime());
        $this->assertEquals('15:00', $reservation->getEndTime());
    }

    public function testCreateWithEmptyAttributes(): void
    {
        $this->requiresCraft();
        $reservation = ReservationFactory::create([]);
        $this->assertInstanceOf(ReservationInterface::class, $reservation);
    }

    // =========================================================================
    // Mode Detection (Requires Craft)
    // =========================================================================

    public function testIsElementModeDefaultsToTrueWithoutPlugin(): void
    {
        $this->requiresCraft();
        // Without plugin initialized, should default to Element mode
        $this->assertTrue(ReservationFactory::isElementMode());
    }

    // =========================================================================
    // Interface Compliance (Static checks)
    // =========================================================================

    public function testReservationImplementsInterface(): void
    {
        $this->assertTrue(
            is_a(Reservation::class, ReservationInterface::class, true),
            'Reservation should implement ReservationInterface'
        );
    }

    // =========================================================================
    // Find Methods (Require Craft)
    // =========================================================================

    public function testFindByIdReturnsNullForNonExistent(): void
    {
        $this->requiresCraft();
        $result = ReservationFactory::findById(999999);
        $this->assertNull($result);
    }

    public function testFindByTokenReturnsNullForEmptyToken(): void
    {
        $result = ReservationFactory::findByToken('');
        $this->assertNull($result);
    }

    public function testFindReturnsSiteAgnosticQuery(): void
    {
        $this->requiresCraft();
        $query = ReservationFactory::find();
        $this->assertInstanceOf(ReservationQueryInterface::class, $query);
        // Verify the query is site-agnostic (siteId = '*')
        $this->assertEquals('*', $query->siteId);
    }

    public function testFindByTokenReturnsNullForNonExistent(): void
    {
        $this->requiresCraft();
        $result = ReservationFactory::findByToken('non-existent-token');
        $this->assertNull($result);
    }

    // =========================================================================
    // Attribute Allowlist
    // =========================================================================

    public function testCreateUsesAttributeAllowlist(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/factories/ReservationFactory.php');
        $this->assertStringContainsString('ALLOWED_CREATE_ATTRIBUTES', $source,
            'ReservationFactory::create must use an attribute allowlist');
    }

    // =========================================================================
    // Factory Usage Patterns (Require Craft)
    // =========================================================================

    public function testFactoryCreateAndQueryPatternWorks(): void
    {
        $this->requiresCraft();
        $reservation = ReservationFactory::create([
            'userName' => 'Pattern Test',
            'userEmail' => 'pattern@test.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
        ]);

        $query = ReservationFactory::find()
            ->status('confirmed')
            ->limit(10);

        $this->assertInstanceOf(ReservationInterface::class, $reservation);
        $this->assertInstanceOf(ReservationQueryInterface::class, $query);
    }
}
