<?php

namespace anvildev\slots\exceptions;

use Craft;

class BookingNotFoundException extends BookingException
{
    public function getName(): string
    {
        return Craft::t('slots', 'exceptions.bookingNotFound');
    }
}
