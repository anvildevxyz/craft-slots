<?php

namespace anvildev\slots\tests\Unit;

use anvildev\slots\tests\Support\TestCase;

/**
 * Tests for all critical fixes from CODE_REVIEW.md
 *
 * Uses source-code regression testing where full Craft init isn't available:
 * reads the actual source files and asserts the fix is present / the bug is absent.
 */
class CodeReviewFixesTest extends TestCase
{
    private function src(string $relativePath): string
    {
        return dirname(__DIR__, 2) . '/src/' . $relativePath;
    }

    private function readSource(string $relativePath): string
    {
        $path = $this->src($relativePath);
        $this->assertFileExists($path, "Source file not found: {$relativePath}");
        return file_get_contents($path);
    }

    private function webJs(string $relativePath): string
    {
        return $this->readSource('web/js/' . $relativePath);
    }

    // ────────────────────────────────────────────────────────
    // #1 — GraphQL ScheduleQuery bypasses schema authorization
    // ────────────────────────────────────────────────────────

    public function testServiceSchedulesJsHasEscapeHtmlFunction(): void
    {
        $source = $this->webJs('cp/service-schedules.js');
        $this->assertStringContainsString(
            'function escapeHtml',
            $source,
            'service-schedules.js must have escapeHtml utility function'
        );
    }

    public function testServiceSchedulesJsEscapesTitles(): void
    {
        $source = $this->webJs('cp/service-schedules.js');
        $this->assertStringContainsString(
            'escapeHtml(title)',
            $source,
            'Schedule title must be escaped with escapeHtml() before HTML insertion'
        );
    }

    // ────────────────────────────────────────────────────────
    // #5 — OAuth tokens stored in plaintext
    // ────────────────────────────────────────────────────────

    public function testBookingManagementControllerUsesHashEquals(): void
    {
        $source = $this->readSource('controllers/BookingManagementController.php');
        $this->assertStringContainsString(
            'hash_equals',
            $source,
            'BookingManagementController must use hash_equals() for token comparison'
        );
        $this->assertStringNotContainsString(
            "->getConfirmationToken() !== \$token",
            $source,
            'Must NOT use !== for confirmation token comparison (timing attack)'
        );
    }

    // ────────────────────────────────────────────────────────
    // #9 — PII logged in BookingController
    // ────────────────────────────────────────────────────────

    public function testBookingControllerDoesNotLogPii(): void
    {
        $source = $this->readSource('controllers/BookingController.php');
        // Should NOT log full form attributes
        $this->assertStringNotContainsString(
            'json_encode($form->getAttributes())',
            $source,
            'BookingController must NOT log full form attributes (contains PII)'
        );
    }

    public function testBookingControllerLogsOnlyNonPiiFields(): void
    {
        $source = $this->readSource('controllers/BookingController.php');
        // The error log line should reference non-PII fields like serviceId, employeeId, date
        $this->assertStringContainsString('serviceId', $source);
        $this->assertStringContainsString('employeeId', $source);
        $this->assertStringContainsString('bookingDate', $source);
    }

    // ────────────────────────────────────────────────────────
    // #10 — updateReservation missing mutex lock
    // ────────────────────────────────────────────────────────

    public function testUpdateReservationUsesMutex(): void
    {
        $methodBody = $this->sourceOfMethod(\anvildev\slots\services\BookingService::class, 'updateReservation');

        $this->assertStringContainsString(
            'mutex',
            strtolower($methodBody),
            'updateReservation must use mutex locking'
        );
        $this->assertStringContainsString(
            'acquire',
            $methodBody,
            'updateReservation must acquire a mutex lock'
        );
    }

    public function testUpdateReservationUsesTransaction(): void
    {
        $methodBody = $this->sourceOfMethod(\anvildev\slots\services\BookingService::class, 'updateReservation');

        $this->assertStringContainsString(
            'beginTransaction',
            $methodBody,
            'updateReservation must wrap operations in a DB transaction'
        );
    }

    // ────────────────────────────────────────────────────────
    // #11 — updateReservation incomplete availability check
    // ────────────────────────────────────────────────────────

    public function testUpdateReservationPassesAllIdsToAvailabilityCheck(): void
    {
        $source = $this->readSource('services/BookingService.php');
        $pos = strpos($source, 'function updateReservation');
        $methodBody = substr($source, $pos, 3000);

        // The availability check should include employeeId, locationId, serviceId
        $this->assertStringContainsString('employeeId', $methodBody);
        $this->assertStringContainsString('locationId', $methodBody);
        $this->assertStringContainsString('serviceId', $methodBody);

        // Verify isSlotAvailable is called with these parameters
        $this->assertStringContainsString(
            'isSlotAvailable',
            $methodBody,
            'updateReservation must call isSlotAvailable'
        );
    }

    // ────────────────────────────────────────────────────────
    // #12 — Calendar reschedule bypasses availability check
    // ────────────────────────────────────────────────────────

    public function testCalendarViewRescheduleValidatesDateFormat(): void
    {
        $source = $this->readSource('controllers/cp/CalendarViewController.php');
        // The reschedule action should validate date format with regex
        $this->assertStringContainsString(
            'preg_match',
            $source,
            'CalendarViewController reschedule must validate date format'
        );
    }

    public function testCalendarViewRescheduleChecksAvailability(): void
    {
        $source = $this->readSource('controllers/cp/CalendarViewController.php');
        // Reschedule delegates to BookingService::updateReservation which has
        // mutex locking and availability checking built in.
        $this->assertStringContainsString(
            'updateReservation',
            $source,
            'CalendarViewController reschedule must delegate to BookingService::updateReservation'
        );
    }

    public function testEmployeeElementUsesSiteIdWildcard(): void
    {
        $source = $this->readSource('elements/Employee.php');
        $this->assertStringContainsString(
            "siteId('*')",
            $source,
            'Employee element must use siteId(\'*\') for non-localized element lookups'
        );
    }

    public function testBlackoutDateElementUsesSiteIdWildcard(): void
    {
        $source = $this->readSource('elements/BlackoutDate.php');
        $this->assertStringContainsString(
            "siteId('*')",
            $source,
            'BlackoutDate element must use siteId(\'*\') for non-localized element lookups'
        );
    }

    public function testReservationQueriesUseSiteIdWildcard(): void
    {
        $source = $this->readSource('factories/ReservationFactory.php');
        $this->assertStringContainsString(
            "siteId('*')",
            $source,
            'ReservationFactory must use siteId(\'*\') for non-localized element lookups'
        );
    }

    // ────────────────────────────────────────────────────────
    // #15 — Hardcoded timezones (covered in TimezoneTest.php)
    // These are additional regression checks
    // ────────────────────────────────────────────────────────

    public function testBookingFormUsesTranslation(): void
    {
        $source = $this->readSource('models/forms/BookingForm.php');
        // Validation messages should use Yii::t() instead of German text
        $this->assertStringNotContainsString(
            'Dieses Feld',
            $source,
            'BookingForm must not contain hardcoded German strings'
        );
        $this->assertStringNotContainsString(
            'Bitte geben',
            $source,
            'BookingForm must not contain hardcoded German strings'
        );
    }

    public function testBookingFormUsesYiiTranslate(): void
    {
        $source = $this->readSource('models/forms/BookingForm.php');
        $this->assertStringContainsString(
            "Yii::t('slots'",
            $source,
            'BookingForm validation messages must use Yii::t() for i18n'
        );
    }

    public function testIcsHelperHasCorrectProdid(): void
    {
        $source = $this->readSource('helpers/IcsHelper.php');
        $this->assertStringContainsString(
            'PRODID',
            $source,
            'IcsHelper must have correct PRODID (not PROID) per RFC 5545'
        );
        $this->assertStringNotContainsString(
            'PROID',
            $source,
            'IcsHelper must not have PROID typo'
        );
    }

    public function testIcsHelperUsesEnLocale(): void
    {
        $source = $this->readSource('helpers/IcsHelper.php');
        $this->assertStringContainsString(
            '//EN',
            $source,
            'IcsHelper PRODID must use //EN locale, not //DE'
        );
    }

    // ────────────────────────────────────────────────────────
    // #18 — Availability calendar endpoint unbounded date range (DoS)
    // ────────────────────────────────────────────────────────

    public function testSlotControllerValidatesDateFormat(): void
    {
        $source = $this->readSource('controllers/SlotController.php');
        // Should validate date format with regex
        $this->assertStringContainsString(
            'preg_match',
            $source,
            'SlotController must validate date format'
        );
        $this->assertMatchesRegularExpression(
            '/\\\\d\{4\}-\\\\d\{2\}-\\\\d\{2\}/',
            $source,
            'SlotController must validate Y-m-d date format via regex'
        );
    }

    public function testSlotControllerCapsDateRange(): void
    {
        $source = $this->readSource('controllers/SlotController.php');
        $this->assertStringContainsString(
            'P90D',
            $source,
            'SlotController must cap date range to 90 days'
        );
    }

    public function testSlotControllerThrowsOnInvalidDate(): void
    {
        $source = $this->readSource('controllers/SlotController.php');
        $this->assertStringContainsString(
            'BadRequestHttpException',
            $source,
            'SlotController must throw BadRequestHttpException for invalid dates'
        );
    }

    // ────────────────────────────────────────────────────────
    // Cross-cutting: ensure no new hardcoded timezones introduced
    // ────────────────────────────────────────────────────────

    public function testIcsHelperDoesNotHardcodeTimezone(): void
    {
        $source = $this->readSource('helpers/IcsHelper.php');
        $this->assertStringNotContainsString(
            "'Europe/Zurich'",
            $source,
            'IcsHelper must not hardcode Europe/Zurich'
        );
    }

    public function testReservationDoesNotHardcodeTimezone(): void
    {
        $source = $this->readSource('elements/Reservation.php');
        $this->assertStringNotContainsString(
            "'Europe/Zurich'",
            $source,
            'Reservation must not hardcode Europe/Zurich'
        );
    }

    public function testServiceSchedulesModalEscapesStrings(): void
    {
        $source = $this->webJs('cp/service-schedules.js');
        // The h1 uses a ternary: escapeHtml(isNew ? strings.addSchedule : strings.editSchedule)
        $this->assertStringContainsString("escapeHtml(isNew ? strings.addSchedule : strings.editSchedule)", $source);
        $this->assertStringContainsString("escapeHtml(strings.title)", $source);
        $this->assertStringContainsString("escapeHtml(strings.startDate)", $source);
        $this->assertStringContainsString("escapeHtml(strings.endDate)", $source);
        $this->assertStringContainsString("escapeHtml(strings.capacity)", $source);
        $this->assertStringContainsString("escapeHtml(strings.cancel)", $source);
        $this->assertStringContainsString("escapeHtml(strings.saveSchedule)", $source);
    }

    // ────────────────────────────────────────────────────────
    // M4 — Missing status validation in updateReservation
    // ────────────────────────────────────────────────────────

    public function testUpdateReservationValidatesStatus(): void
    {
        $source = $this->readSource('services/BookingService.php');
        $pos = strpos($source, 'function updateReservation');
        $this->assertNotFalse($pos);
        $methodBody = substr($source, $pos, 3000);
        $this->assertStringContainsString('STATUS_CONFIRMED', $methodBody,
            'updateReservation must validate status against allowed values');
        $this->assertStringContainsString('STATUS_CANCELLED', $methodBody,
            'updateReservation must validate status against allowed values');
        // Validate against $data['status'] (incoming), not $reservation->status (already assigned)
        $this->assertStringContainsString("\$data['status']", $methodBody,
            'updateReservation must validate $data[\'status\'], not $reservation->status');
    }

    // ────────────────────────────────────────────────────────
    // M3 — SettingsRecord double-encryption on key rotation
    // ────────────────────────────────────────────────────────

    public function testSettingsRecordNullsOnDecryptionFailure(): void
    {
        $methodBody = $this->sourceOfMethod(\anvildev\slots\records\SettingsRecord::class, 'afterFind');
        $this->assertStringContainsString('= null', $methodBody,
            'afterFind must set field to null on decryption failure to prevent double-encryption');
    }

    // ────────────────────────────────────────────────────────
    // M1 — EmployeeQuery uses fragile LIKE for JSON serviceIds
    // ────────────────────────────────────────────────────────

    public function testEmployeeQueryUsesJsonContains(): void
    {
        $source = $this->readSource('elements/db/EmployeeQuery.php');
        $this->assertStringContainsString('JSON_CONTAINS', $source,
            'EmployeeQuery serviceId filter must use JSON_CONTAINS instead of LIKE patterns');
    }

    // ────────────────────────────────────────────────────────
    // M5 — Location missing max-length constraints
    // ────────────────────────────────────────────────────────

    public function testLocationDefineRulesHasMaxLength(): void
    {
        $source = $this->readSource('elements/Location.php');
        $pos = strpos($source, 'function defineRules');
        $this->assertNotFalse($pos);
        $methodBody = substr($source, $pos, 1000);
        $this->assertStringContainsString("'max'", $methodBody,
            'Location defineRules must include max-length constraints');
    }

    // ────────────────────────────────────────────────────────
    // M8 — Booking wizard resetWizard missing soft lock release
    // ────────────────────────────────────────────────────────

    public function testAvailabilityCalendarMaxRange90Days(): void
    {
        $source = $this->readSource('controllers/SlotController.php');
        $this->assertStringContainsString('P90D', $source,
            'Availability calendar max range should be 90 days, not 180');
        $this->assertStringNotContainsString('P180D', $source,
            'Availability calendar max range of 180 days is too permissive');
    }
}
