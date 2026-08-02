<?php

namespace anvildev\slots\tests\Unit\Helpers;

use anvildev\slots\helpers\FormFieldHelper;
use anvildev\slots\tests\Support\TestCase;

/**
 * FormFieldHelper Test
 *
 * Tests pure form field parsing logic.
 * extractDateValue() requires Craft::$app and needs integration tests.
 */
class FormFieldHelperTest extends TestCase
{
    // =========================================================================
    // extractTimeValue()
    // =========================================================================

    public function testExtractTimeValueReturnsStringDirectly(): void
    {
        $this->assertEquals('09:00', FormFieldHelper::extractTimeValue('09:00'));
    }

    public function testExtractTimeValueReturnsTimeFromArray(): void
    {
        $input = ['time' => '14:30', 'locale' => 'de', 'timezone' => 'Europe/Zurich'];
        $this->assertEquals('14:30', FormFieldHelper::extractTimeValue($input));
    }

    public function testExtractTimeValueReturnsDefaultWhenArrayTimeEmpty(): void
    {
        $input = ['time' => '', 'locale' => 'de'];
        $this->assertEquals('09:00', FormFieldHelper::extractTimeValue($input, '09:00'));
    }

    public function testExtractTimeValueReturnsDefaultWhenArrayMissingTimeKey(): void
    {
        $input = ['locale' => 'de'];
        $this->assertEquals('17:00', FormFieldHelper::extractTimeValue($input, '17:00'));
    }

    public function testExtractTimeValueFormatsDateTimeObject(): void
    {
        $dt = new \DateTime('2025-06-15 10:45:00');
        $this->assertEquals('10:45', FormFieldHelper::extractTimeValue($dt));
    }

    public function testExtractTimeValueReturnsDefaultWhenNull(): void
    {
        $this->assertNull(FormFieldHelper::extractTimeValue(null));
    }

    public function testExtractTimeValueReturnsDefaultWhenEmpty(): void
    {
        $this->assertEquals('08:00', FormFieldHelper::extractTimeValue('', '08:00'));
    }

    public function testExtractTimeValueReturnsNullDefaultByDefault(): void
    {
        $this->assertNull(FormFieldHelper::extractTimeValue(null, null));
    }

    // =========================================================================
    // extractCapacityValue()
    // =========================================================================

    public function testExtractCapacityValueReturnsIntForPositive(): void
    {
        $this->assertEquals(10, FormFieldHelper::extractCapacityValue('10'));
    }

    public function testExtractCapacityValueReturnsIntForNumeric(): void
    {
        $this->assertEquals(5, FormFieldHelper::extractCapacityValue(5));
    }

    public function testExtractCapacityValueReturnsNullForNull(): void
    {
        $this->assertNull(FormFieldHelper::extractCapacityValue(null));
    }

    public function testExtractCapacityValueReturnsNullForEmptyString(): void
    {
        $this->assertNull(FormFieldHelper::extractCapacityValue(''));
    }

    public function testExtractCapacityValueReturnsNullForZero(): void
    {
        $this->assertNull(FormFieldHelper::extractCapacityValue(0));
    }

    public function testExtractCapacityValueReturnsNullForNegative(): void
    {
        $this->assertNull(FormFieldHelper::extractCapacityValue(-5));
    }

    // =========================================================================
    // formatWorkingHoursFromRequest()
    // =========================================================================

    public function testFormatWorkingHoursReturnsSevenDays(): void
    {
        $result = FormFieldHelper::formatWorkingHoursFromRequest([]);
        $this->assertCount(7, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayHasKey(7, $result);
    }

    public function testFormatWorkingHoursUsesDefaults(): void
    {
        $result = FormFieldHelper::formatWorkingHoursFromRequest([]);

        $this->assertFalse($result[1]['enabled']);
        $this->assertEquals('09:00', $result[1]['start']);
        $this->assertEquals('17:00', $result[1]['end']);
        $this->assertNull($result[1]['breakStart']);
        $this->assertNull($result[1]['breakEnd']);
    }

    public function testFormatWorkingHoursExtractsEnabledDay(): void
    {
        $input = [
            1 => [
                'enabled' => '1',
                'start' => '08:00',
                'end' => '16:00',
                'breakStart' => '12:00',
                'breakEnd' => '13:00',
            ],
        ];

        $result = FormFieldHelper::formatWorkingHoursFromRequest($input);

        $this->assertTrue($result[1]['enabled']);
        $this->assertEquals('08:00', $result[1]['start']);
        $this->assertEquals('16:00', $result[1]['end']);
        $this->assertEquals('12:00', $result[1]['breakStart']);
        $this->assertEquals('13:00', $result[1]['breakEnd']);
    }

    public function testFormatWorkingHoursExcludesCapacityByDefault(): void
    {
        $result = FormFieldHelper::formatWorkingHoursFromRequest([]);
        $this->assertArrayNotHasKey('capacity', $result[1]);
    }

    public function testFormatWorkingHoursIncludesCapacityWhenRequested(): void
    {
        $input = [
            1 => ['enabled' => '1', 'start' => '09:00', 'end' => '17:00', 'capacity' => '10'],
        ];

        $result = FormFieldHelper::formatWorkingHoursFromRequest($input, true);

        $this->assertArrayHasKey('capacity', $result[1]);
        $this->assertEquals(10, $result[1]['capacity']);
    }

    public function testFormatWorkingHoursCapacityNullWhenEmpty(): void
    {
        $input = [
            1 => ['enabled' => '1', 'start' => '09:00', 'end' => '17:00', 'capacity' => ''],
        ];

        $result = FormFieldHelper::formatWorkingHoursFromRequest($input, true);
        $this->assertNull($result[1]['capacity']);
    }

    public function testFormatWorkingHoursHandlesArrayTimeInputs(): void
    {
        $input = [
            1 => [
                'enabled' => '1',
                'start' => ['time' => '10:00', 'locale' => 'de'],
                'end' => ['time' => '18:00', 'locale' => 'de'],
                'breakStart' => null,
                'breakEnd' => null,
            ],
        ];

        $result = FormFieldHelper::formatWorkingHoursFromRequest($input);

        $this->assertEquals('10:00', $result[1]['start']);
        $this->assertEquals('18:00', $result[1]['end']);
    }
}
