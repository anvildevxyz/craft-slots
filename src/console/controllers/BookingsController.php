<?php

namespace anvildev\slots\console\controllers;

use anvildev\slots\factories\ReservationFactory;
use anvildev\slots\records\ReservationRecord;
use anvildev\slots\Slots;
use craft\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

class BookingsController extends Controller
{
    public ?string $date = null;
    public ?string $status = null;
    public int $limit = 20;
    public string $reason = '';
    public string $format = 'csv';
    public ?string $from = null;
    public ?string $to = null;
    public int $gracePeriod = 30;
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return match ($actionID) {
            'list' => [...parent::options($actionID), 'date', 'status', 'limit'],
            'cancel' => [...parent::options($actionID), 'reason'],
            'export' => [...parent::options($actionID), 'format', 'from', 'to', 'status'],
            'mark-no-shows' => [...parent::options($actionID), 'gracePeriod', 'dryRun'],
            default => parent::options($actionID),
        };
    }

    public function actionValidate(): int
    {
        $this->stdout("\nBooking Data Integrity Check\n", Console::BOLD);
        $this->stdout("═══════════════════════════════════\n\n");

        $errors = 0;
        $warnings = 0;
        $checked = 0;

        $reservations = ReservationFactory::find()->siteId('*')->all();
        $total = count($reservations);

        if ($total === 0) {
            $this->stdout("No bookings found.\n\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        foreach ($reservations as $reservation) {
            $checked++;
            $id = $reservation->getId();
            $issues = [];

            // Check required fields
            if (empty($reservation->getUserEmail())) {
                $issues[] = ['error', 'Missing customer email'];
            }
            if (empty($reservation->getBookingDate())) {
                $issues[] = ['error', 'Missing booking date'];
            }
            if (empty($reservation->getStartTime()) || empty($reservation->getEndTime())) {
                $issues[] = ['error', 'Missing start/end time'];
            }

            // Check referential integrity
            $serviceId = $reservation->getServiceId();
            if ($serviceId && !$reservation->getService()) {
                $issues[] = ['error', "References service #{$serviceId} which no longer exists"];
            }
            if (!$serviceId) {
                $issues[] = ['warn', 'Not linked to any service or event'];
            }

            if ($reservation->getEmployeeId() && !$reservation->getEmployee()) {
                $issues[] = ['warn', "References employee #{$reservation->getEmployeeId()} which no longer exists"];
            }
            if ($reservation->getLocationId() && !$reservation->getLocation()) {
                $issues[] = ['warn', "References location #{$reservation->getLocationId()} which no longer exists"];
            }

            // Check time logic
            if ($reservation->getStartTime() && $reservation->getEndTime() && $reservation->getStartTime() >= $reservation->getEndTime()) {
                $issues[] = ['warn', "Start time ({$reservation->getStartTime()}) >= end time ({$reservation->getEndTime()})"];
            }

            // Check status validity
            $validStatuses = [ReservationRecord::STATUS_PENDING, ReservationRecord::STATUS_CONFIRMED, ReservationRecord::STATUS_CANCELLED, ReservationRecord::STATUS_NO_SHOW];
            if (!in_array($reservation->getStatus(), $validStatuses, true)) {
                $issues[] = ['error', "Invalid status: {$reservation->getStatus()}"];
            }

            if (!empty($issues)) {
                $this->stdout("  Booking #{$id} ({$reservation->getUserEmail()} — {$reservation->getBookingDate()}):\n");
                foreach ($issues as [$level, $message]) {
                    if ($level === 'error') {
                        $this->stdout("    ✗ {$message}\n", Console::FG_RED);
                        $errors++;
                    } else {
                        $this->stdout("    ! {$message}\n", Console::FG_YELLOW);
                        $warnings++;
                    }
                }
            }
        }

        $this->stdout("═══════════════════════════════════\n");
        $this->stdout("Checked {$checked} booking(s): ");
        if ($errors === 0 && $warnings === 0) {
            $this->stdout("all clean ✓\n", Console::FG_GREEN);
        } else {
            if ($errors > 0) {
                $this->stdout("{$errors} error" . ($errors !== 1 ? 's' : ''), Console::FG_RED);
            }
            if ($errors > 0 && $warnings > 0) {
                $this->stdout(', ');
            }
            if ($warnings > 0) {
                $this->stdout("{$warnings} warning" . ($warnings !== 1 ? 's' : ''), Console::FG_YELLOW);
            }
            $this->stdout("\n");
        }
        $this->stdout("\n");

        return $errors > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    public function actionList(): int
    {
        $query = ReservationFactory::find()
            ->siteId('*')
            ->orderBy(['slots_reservations.bookingDate' => SORT_DESC, 'slots_reservations.startTime' => SORT_DESC]);

        if ($this->date !== null) {
            $query->bookingDate($this->date);
        }

        if ($this->status !== null) {
            $validStatuses = [ReservationRecord::STATUS_PENDING, ReservationRecord::STATUS_CONFIRMED, ReservationRecord::STATUS_CANCELLED, ReservationRecord::STATUS_NO_SHOW];
            if (!in_array($this->status, $validStatuses, true)) {
                $this->stderr("Invalid status '{$this->status}'. Valid: " . implode(', ', $validStatuses) . "\n", Console::FG_RED);
                return ExitCode::USAGE;
            }
            $query->status($this->status);
        }

        $reservations = $query->limit($this->limit)->all();

        if (empty($reservations)) {
            $this->stdout("No bookings found.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("\nBookings", Console::BOLD);
        if ($this->date) {
            $this->stdout(" — {$this->date}");
        }
        if ($this->status) {
            $this->stdout(" [{$this->status}]");
        }
        $this->stdout("\n═══════════════════════════════════\n\n");

        foreach ($reservations as $reservation) {
            $this->printReservationRow($reservation);
        }

        $total = ReservationFactory::find()->siteId('*')->count();
        $this->stdout("\nShowing " . count($reservations) . " of {$total} total bookings\n\n");

        return ExitCode::OK;
    }

    public function actionInfo(int $id): int
    {
        $reservation = ReservationFactory::find()->siteId('*')->id($id)->one();

        if (!$reservation) {
            $this->stderr("Booking #{$id} not found.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\nBooking #{$id}\n", Console::BOLD);
        $this->stdout("═══════════════════════════════════\n\n");

        $statusColor = match ($reservation->getStatus()) {
            'confirmed' => Console::FG_GREEN,
            'cancelled' => Console::FG_RED,
            'pending' => Console::FG_YELLOW,
            'no_show' => Console::FG_RED,
            default => Console::FG_GREY,
        };

        $this->stdout("Status:       ");
        $this->stdout($reservation->getStatusLabel() . "\n", $statusColor);
        $this->stdout("Date:         {$reservation->getBookingDate()}\n");
        $this->stdout("Time:         {$reservation->getStartTime()} – {$reservation->getEndTime()}\n");
        $this->stdout("Duration:     {$reservation->getDurationMinutes()} min\n");
        $this->stdout("Quantity:     {$reservation->getQuantity()}\n");
        $this->stdout("Token:        {$reservation->getConfirmationToken()}\n");

        $this->stdout("\nCustomer\n", Console::BOLD);
        $this->stdout("  Name:       {$reservation->getUserName()}\n");
        $this->stdout("  Email:      {$reservation->getUserEmail()}\n");
        if ($reservation->getUserPhone()) {
            $this->stdout("  Phone:      {$reservation->getUserPhone()}\n");
        }
        if ($reservation->getUserId()) {
            $this->stdout("  User ID:    {$reservation->getUserId()}\n");
        }

        if ($service = $reservation->getService()) {
            $this->stdout("\nService\n", Console::BOLD);
            $this->stdout("  Name:       {$service->title} (#{$service->id})\n");
            if ($service->duration) {
                $this->stdout("  Duration:   {$service->duration} min\n");
            }
            if ($service->price > 0) {
                $this->stdout("  Price:      {$service->price}\n");
            }
        }

        if ($employee = $reservation->getEmployee()) {
            $this->stdout("\nEmployee\n", Console::BOLD);
            $this->stdout("  Name:       {$employee->title} (#{$employee->id})\n");
        }

        if ($location = $reservation->getLocation()) {
            $this->stdout("\nLocation\n", Console::BOLD);
            $this->stdout("  Name:       {$location->title} (#{$location->id})\n");
            if ($location->timezone) {
                $this->stdout("  Timezone:   {$location->timezone}\n");
            }
        }

        if ($reservation->getNotes()) {
            $this->stdout("\nNotes\n", Console::BOLD);
            $this->stdout("  {$reservation->getNotes()}\n");
        }

        $this->stdout("\nNotifications\n", Console::BOLD);
        $this->stdout('  Email confirmation: ' . ($reservation->getNotificationSent() ? '✓ Sent' : '✗ Not sent') . "\n");
        $this->stdout('  Email reminder 24h: ' . ($reservation->getEmailReminder24hSent() ? '✓ Sent' : '— Pending') . "\n");

        $this->stdout("\nTimestamps\n", Console::BOLD);
        if ($created = $reservation->getDateCreated()) {
            $this->stdout("  Created:    {$created->format('Y-m-d H:i:s')}\n");
        }
        if ($updated = $reservation->getDateUpdated()) {
            $this->stdout("  Updated:    {$updated->format('Y-m-d H:i:s')}\n");
        }

        $this->stdout("\n");
        return ExitCode::OK;
    }

    public function actionCancel(int $id): int
    {
        $reservation = ReservationFactory::find()->siteId('*')->id($id)->one();

        if (!$reservation) {
            $this->stderr("Booking #{$id} not found.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($reservation->getStatus() === ReservationRecord::STATUS_CANCELLED) {
            $this->stderr("Booking #{$id} is already cancelled.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("Booking: #{$id} — {$reservation->getUserName()} ({$reservation->getUserEmail()})\n");
        $this->stdout("Date:    {$reservation->getBookingDate()} {$reservation->getStartTime()}–{$reservation->getEndTime()}\n");
        if ($service = $reservation->getService()) {
            $this->stdout("Service: {$service->title}\n");
        }

        if (!$this->confirm('Cancel this booking?')) {
            $this->stdout("Aborted.\n");
            return ExitCode::OK;
        }

        try {
            if (Slots::getInstance()->getBooking()->cancelReservation($id, $this->reason)) {
                $this->stdout("Booking #{$id} cancelled successfully.\n", Console::FG_GREEN);
                return ExitCode::OK;
            }

            $this->stderr("Failed to cancel booking #{$id}. It may not be cancellable.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        } catch (\Throwable $e) {
            $this->stderr("Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    public function actionExport(): int
    {
        if (!in_array($this->format, ['csv', 'json'], true)) {
            $this->stderr("Invalid format '{$this->format}'. Use 'csv' or 'json'.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $query = ReservationFactory::find()
            ->siteId('*')
            ->orderBy(['slots_reservations.bookingDate' => SORT_ASC, 'slots_reservations.startTime' => SORT_ASC]);

        if ($this->from !== null) {
            $query->andWhere(['>=', 'slots_reservations.bookingDate', $this->from]);
        }
        if ($this->to !== null) {
            $query->andWhere(['<=', 'slots_reservations.bookingDate', $this->to]);
        }
        if ($this->status !== null) {
            $query->status($this->status);
        }

        $reservations = $query->all();

        if (empty($reservations)) {
            $this->stderr("No bookings found matching the criteria.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $rows = array_map(fn($r) => [
            'id' => $r->getId(),
            'status' => $r->getStatus(),
            'bookingDate' => $r->getBookingDate(),
            'startTime' => $r->getStartTime(),
            'endTime' => $r->getEndTime(),
            'duration' => $r->getDurationMinutes(),
            'quantity' => $r->getQuantity(),
            'customerName' => $r->getUserName(),
            'customerEmail' => $r->getUserEmail(),
            'customerPhone' => $r->getUserPhone(),
            'service' => $r->getService()?->title,
            'serviceId' => $r->getServiceId(),
            'employee' => $r->getEmployee()?->title,
            'employeeId' => $r->getEmployeeId(),
            'location' => $r->getLocation()?->title,
            'locationId' => $r->getLocationId(),
            'notes' => $r->getNotes(),
            'confirmationToken' => $r->getConfirmationToken(),
            'createdAt' => $r->getDateCreated()?->format('Y-m-d H:i:s'),
        ], $reservations);

        if ($this->format === 'json') {
            $this->stdout(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        } else {
            $this->stdout(implode(',', array_keys($rows[0])) . "\n");
            foreach ($rows as $row) {
                $this->stdout(implode(',', array_map(fn($v) => match (true) {
                    $v === null => '',
                    str_contains((string)$v, ',') || str_contains((string)$v, '"') || str_contains((string)$v, "\n") => '"' . str_replace('"', '""', (string)$v) . '"',
                    default => (string)$v,
                }, $row)) . "\n");
            }
        }

        $this->stderr("Exported " . count($rows) . " booking(s) as {$this->format}\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Auto-mark confirmed bookings as no-show when their appointment end time
     * plus a grace period has passed without being checked in.
     *
     * Usage: php craft slots/bookings/mark-no-shows --grace-period=30 --dry-run
     */
    public function actionMarkNoShows(): int
    {
        $this->stdout("\nNo-Show Auto-Detection\n", Console::BOLD);
        $this->stdout("═══════════════════════════════════\n\n");
        $this->stdout("Grace period: {$this->gracePeriod} minutes\n");

        $cutoff = new \DateTime();
        $cutoff->modify("-{$this->gracePeriod} minutes");

        // Broad date filter — precise end-time comparison happens in the loop below.
        // ReservationFactory::find() already sets siteId('*'), no need to repeat.
        $reservations = ReservationFactory::find()
            ->reservationStatus(ReservationRecord::STATUS_CONFIRMED)
            ->bookingDate(['<=', $cutoff->format('Y-m-d')])
            ->all();

        $marked = 0;
        $skipped = 0;

        foreach ($reservations as $reservation) {
            // Build full end datetime with the reservation's timezone
            $dateStr = $reservation->getBookingDate() . ' ' . $reservation->getEndTime();
            $tz = new \DateTimeZone($reservation->getUserTimezone() ?: date_default_timezone_get());
            $endDateTime = \DateTime::createFromFormat('Y-m-d H:i:s', $dateStr, $tz)
                ?: \DateTime::createFromFormat('Y-m-d H:i', $dateStr, $tz);

            if (!$endDateTime || $endDateTime > $cutoff) {
                $skipped++;
                continue;
            }

            if ($this->dryRun) {
                $this->stdout("  [DRY RUN] Would mark #{$reservation->getId()} ({$reservation->getUserName()}) as no-show\n");
                $marked++;
                continue;
            }

            if ($reservation->markAsNoShow()) {
                $this->stdout("  Marked #{$reservation->getId()} ({$reservation->getUserName()}) as no-show\n", Console::FG_GREEN);
                $marked++;
            } else {
                $this->stderr("  Failed to mark #{$reservation->getId()}\n", Console::FG_RED);
            }
        }

        $this->stdout("\n{$marked} booking(s) marked as no-show, {$skipped} skipped.\n\n");
        return ExitCode::OK;
    }

    private function printReservationRow(\anvildev\slots\contracts\ReservationInterface $reservation): void
    {
        [$statusIcon, $statusColor] = match ($reservation->getStatus()) {
            'confirmed' => ['●', Console::FG_GREEN],
            'cancelled' => ['○', Console::FG_RED],
            'pending' => ['◐', Console::FG_YELLOW],
            'no_show' => ['✗', Console::FG_RED],
            default => ['?', Console::FG_GREY],
        };

        $this->stdout("  {$statusIcon} ", $statusColor);
        $this->stdout(str_pad("#{$reservation->getId()}", 8));
        $this->stdout("{$reservation->getBookingDate()} ");
        $this->stdout("{$reservation->getStartTime()}–{$reservation->getEndTime()}  ");
        $this->stdout(str_pad($reservation->getUserName(), 25));

        if ($service = $reservation->getService()) {
            $this->stdout("  [{$service->title}]");
        }

        $this->stdout("\n");
    }
}
