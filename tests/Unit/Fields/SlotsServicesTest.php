<?php

namespace anvildev\slots\tests\Unit\Fields;

use anvildev\slots\elements\Service;
use anvildev\slots\fields\SlotsServices;
use anvildev\slots\tests\Support\TestCase;

class SlotsServicesTest extends TestCase
{
    private SlotsServices $field;

    /**
     * @beforeClass
     */
    public static function loadFieldClass(): void
    {
        if (!class_exists('Craft', false)) {
            eval('class Craft extends \yii\BaseYii {}');
        }

        // Suppress PHP 8.4 deprecation from Craft's BaseRelationField during class loading
        $previousLevel = error_reporting(E_ALL & ~E_DEPRECATED);
        class_exists(SlotsServices::class);
        error_reporting($previousLevel);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $ref = new \ReflectionClass(SlotsServices::class);
        $this->field = $ref->newInstanceWithoutConstructor();
    }

    public function testDisplayName(): void
    {
        $this->assertIsString(SlotsServices::displayName());
        $this->assertNotEmpty(SlotsServices::displayName());
    }

    public function testElementType(): void
    {
        $this->assertSame(Service::class, SlotsServices::elementType());
    }

    public function testDefaultSelectionLabel(): void
    {
        $this->assertIsString(SlotsServices::defaultSelectionLabel());
        $this->assertNotEmpty(SlotsServices::defaultSelectionLabel());
    }
}
