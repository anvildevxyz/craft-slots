<?php

namespace anvildev\slots\events;

use yii\base\Event;

class AfterAvailabilityCheckEvent extends Event
{
    public string $date;
    public ?int $serviceId = null;
    public ?int $employeeId = null;
    public ?int $locationId = null;
    public array $slots = [];
    public array $availableSlots = [];
    public int $slotCount = 0;
    public float $calculationTime = 0.0;
    public float $duration = 0.0;
    public bool $fromCache = false;

    public function init(): void
    {
        parent::init();
        $this->availableSlots = $this->slots;
        $this->duration = $this->calculationTime;
    }
}
