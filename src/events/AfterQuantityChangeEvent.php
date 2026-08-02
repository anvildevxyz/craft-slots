<?php

namespace anvildev\slots\events;

class AfterQuantityChangeEvent extends BookingEvent
{
    public int $previousQuantity;
    public int $reduceBy = 0;
    public int $increaseBy = 0;
    public int $newQuantity;
    public ?string $reason = null;
    public float $originalTotalPrice = 0.0;
}
