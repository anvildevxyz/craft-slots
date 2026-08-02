<?php

namespace anvildev\slots\exceptions;

use Craft;

class BookingConflictException extends BookingException
{
    public function getName(): string
    {
        return Craft::t('slots', 'exceptions.bookingConflict');
    }
}
