<?php

namespace anvildev\slots\events;

class BeforeBookingCancelEvent extends BookingEvent
{
    public ?string $reason = null;
    public ?string $cancelledBy = null;
    public bool $sendNotification = true;
    public ?string $errorMessage = null;
}
