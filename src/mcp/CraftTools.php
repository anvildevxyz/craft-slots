<?php

namespace anvildev\slots\mcp;

use anvildev\slots\elements\Reservation;
use anvildev\slots\elements\Service;
use anvildev\slots\services\AvailabilityService;
use anvildev\slots\services\BookingService;
use anvildev\slots\Slots;
use craft\mcp\attributes\RequiresPermission;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/**
 * Read tools for Slots on Craft's MCP server, plus cancel, which calls the
 * booking service the control panel uses. Customer names, emails, phones,
 * notes, and confirmation tokens are not returned. Creating a booking and
 * refunds are not exposed.
 */
class CraftTools
{
    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'slots_list_services',
        description: 'List Slots services (id, title, enabled) across all sites.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('slots-accessPlugin')]
    public function listServices(int $limit = 50): array
    {
        $services = Service::find()
            ->siteId('*')
            ->status(null)
            ->unique()
            ->limit($this->clamp($limit))
            ->all();

        return [
            'count' => count($services),
            'services' => array_map(static fn(Service $service): array => [
                'id' => $service->id,
                'title' => $service->title,
                'enabled' => $service->enabled,
            ], $services),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'slots_check_availability',
        description: 'Available time slots for one date. date is Y-m-d.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('slots-viewCalendar')]
    public function checkAvailability(string $date, ?int $serviceId = null, ?int $employeeId = null, ?int $locationId = null, int $quantity = 1): array
    {
        return [
            'date' => $date,
            'slots' => $this->availability()->getAvailableSlots($date, $employeeId, $locationId, $serviceId, $quantity),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'slots_list_reservations',
        description: 'Upcoming reservations. No customer names, emails, phones, notes, or tokens.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('slots-viewBookings')]
    public function listReservations(int $limit = 20): array
    {
        $rows = [];
        foreach ($this->booking()->getUpcomingReservations($this->clamp($limit)) as $reservation) {
            if ($reservation instanceof Reservation) {
                $rows[] = $this->reservation($reservation);
            }
        }

        return ['count' => count($rows), 'reservations' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'slots_get_reservation',
        description: 'One reservation by id. No customer names, emails, phones, notes, or tokens.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('slots-viewBookings')]
    public function getReservation(int $id): array
    {
        $reservation = $this->booking()->getReservationById($id);
        if (!$reservation instanceof Reservation) {
            return ['error' => "Reservation #{$id} not found."];
        }

        return ['reservation' => $this->reservation($reservation)];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'slots_booking_stats',
        description: 'Booking counts: total, confirmed, pending, today, and this month.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('slots-viewBookings')]
    public function bookingStats(): array
    {
        return ['stats' => $this->booking()->getBookingStats()];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'slots_cancel_reservation',
        description: 'Cancel one reservation through the normal booking service, including its notifications.',
        annotations: new ToolAnnotations(destructiveHint: true),
    )]
    #[RequiresPermission('slots-manageBookings')]
    public function cancelReservation(int $id, ?string $reason = null): array
    {
        if (!$this->booking()->getReservationById($id) instanceof Reservation) {
            return ['error' => "Reservation #{$id} not found."];
        }

        try {
            $cancelled = $this->booking()->cancelReservation($id, $reason ?? '');
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        return ['cancelled' => $cancelled, 'id' => $id];
    }

    /**
     * @return array<string, mixed>
     */
    private function reservation(Reservation $reservation): array
    {
        return [
            'id' => $reservation->id,
            'status' => $reservation->status,
            'bookingDate' => $reservation->bookingDate,
            'startTime' => $reservation->startTime,
            'endTime' => $reservation->endTime,
            'serviceId' => $reservation->serviceId,
            'employeeId' => $reservation->employeeId,
            'locationId' => $reservation->locationId,
            'quantity' => $reservation->quantity,
        ];
    }

    private function booking(): BookingService
    {
        /** @var BookingService $service */
        $service = Slots::getInstance()->get('booking');

        return $service;
    }

    private function availability(): AvailabilityService
    {
        /** @var AvailabilityService $service */
        $service = Slots::getInstance()->get('availability');

        return $service;
    }

    private function clamp(int $limit): int
    {
        return max(1, min(100, $limit));
    }
}
