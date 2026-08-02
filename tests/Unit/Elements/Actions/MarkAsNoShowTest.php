<?php

namespace anvildev\slots\tests\Unit\Elements\Actions;

use anvildev\slots\elements\actions\MarkAsNoShow;
use anvildev\slots\tests\Support\TestCase;

class MarkAsNoShowTest extends TestCase
{
    public function testDisplayName(): void
    {
        $this->requiresCraft();
        $action = new MarkAsNoShow();
        $this->assertNotEmpty($action::displayName());
    }

    public function testConfirmationMessage(): void
    {
        $this->requiresCraft();
        $action = new MarkAsNoShow();
        $this->assertNotEmpty($action->getConfirmationMessage());
    }
}
