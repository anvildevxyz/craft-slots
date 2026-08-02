<?php

namespace anvildev\slots\tests\Unit\Widgets;

use anvildev\slots\tests\Support\TestCase;
use anvildev\slots\widgets\SlotsWidget;

class SlotsWidgetTest extends TestCase
{
    public function testDisplayName(): void
    {
        $this->requiresCraft();
        $name = SlotsWidget::displayName();
        $this->assertNotEmpty($name);
    }

    public function testIconPath(): void
    {
        $this->requiresCraft();
        $icon = SlotsWidget::icon();
        // icon() should return a path or null
        $this->assertTrue($icon === null || is_string($icon));
    }

    public function testDefaultLookaheadDays(): void
    {
        $widget = new SlotsWidget();
        $this->assertEquals(1, $widget->lookaheadDays);
    }

    public function testSettingsValidation(): void
    {
        $widget = new SlotsWidget();
        $widget->lookaheadDays = 3;
        $rules = $widget->rules();
        $this->assertNotEmpty($rules);
    }

    public function testValidLookaheadValues(): void
    {
        $this->requiresCraft();
        $widget = new SlotsWidget();

        $widget->lookaheadDays = 1;
        $this->assertTrue($widget->validate());

        $widget->lookaheadDays = 3;
        $this->assertTrue($widget->validate());

        $widget->lookaheadDays = 7;
        $this->assertTrue($widget->validate());
    }

    public function testInvalidLookaheadValue(): void
    {
        $this->requiresCraft();
        $widget = new SlotsWidget();
        $widget->lookaheadDays = 5;
        $this->assertFalse($widget->validate());
    }
}
