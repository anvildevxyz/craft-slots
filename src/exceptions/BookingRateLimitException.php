<?php

namespace anvildev\slots\exceptions;

use Craft;

class BookingRateLimitException extends BookingException
{
    public function getName(): string
    {
        return Craft::t('slots', 'exceptions.bookingRateLimit');
    }
}
