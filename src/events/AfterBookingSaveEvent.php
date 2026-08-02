<?php

namespace anvildev\slots\events;

class AfterBookingSaveEvent extends BookingEvent
{
    public bool $success = true;
    public array $errors = [];
}
