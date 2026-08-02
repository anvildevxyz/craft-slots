<?php

namespace anvildev\slots\events;

use craft\events\CancelableEvent;

class BeforeAvailabilityCheckEvent extends CancelableEvent
{
    public string $date;
    public ?int $serviceId = null;
    public ?int $employeeId = null;
    public ?int $locationId = null;
    public int $quantity = 1;
    public array $criteria = [];
    public ?string $errorMessage = null;
}
