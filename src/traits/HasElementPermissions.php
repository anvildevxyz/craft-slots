<?php

namespace anvildev\slots\traits;

use craft\elements\User;

/**
 * Parameterized permission checks for elements sharing the admin-or-permission pattern.
 *
 * The using class must implement `permissionKey()` returning the Craft permission handle
 * (e.g. 'slots-manageServices'). Override individual methods for custom logic.
 */
trait HasElementPermissions
{
    abstract protected function permissionKey(): string;

    public function canView(User $user): bool
    {
        return $user->admin || $user->can($this->permissionKey());
    }

    public function canSave(User $user): bool
    {
        return $user->admin || $user->can($this->permissionKey());
    }

    public function canDelete(User $user): bool
    {
        return $user->admin || $user->can($this->permissionKey());
    }
}
