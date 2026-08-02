<?php

namespace anvildev\slots\exceptions;

use Craft;
use yii\base\Exception;

class BookingException extends Exception
{
    public function getName(): string
    {
        return Craft::t('slots', 'exceptions.bookingException');
    }
}
