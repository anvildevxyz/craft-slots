<?php

namespace anvildev\slots\traits;

/**
 * Shared enabled/disabled status pattern for elements.
 *
 * Expects the using class to have an `$enabled` property (provided by Craft's Element base class).
 */
trait HasEnabledStatus
{
    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function statuses(): array
    {
        return ['enabled' => 'green', 'disabled' => null];
    }

    public function getStatus(): ?string
    {
        return $this->enabled ? 'enabled' : 'disabled';
    }
}
