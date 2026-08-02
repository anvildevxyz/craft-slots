<?php

namespace anvildev\slots\events;

use anvildev\slots\contracts\ReservationInterface;
use craft\events\CancelableEvent;

abstract class BookingEvent extends CancelableEvent
{
    public ReservationInterface $reservation;
    public bool $isNew = true;
}
