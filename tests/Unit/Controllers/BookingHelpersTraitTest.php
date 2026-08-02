<?php

namespace anvildev\slots\tests\Unit\Controllers;

use anvildev\slots\controllers\traits\BookingHelpersTrait;
use anvildev\slots\tests\Support\TestCase;

class BookingHelpersTraitTest extends TestCase
{
    private object $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = new class {
            use BookingHelpersTrait;

            public function testValidateDate(string $date): bool
            {
                return $this->validateDate($date);
            }

            public function testNormalizeQuantity($quantity): int
            {
                return $this->normalizeQuantity($quantity);
            }

            public function testNormalizeId($id): ?int
            {
                return $this->normalizeId($id);
            }
        };
    }

    /**
     * @dataProvider validateDateProvider
     */
    public function testValidateDate(string $date, bool $expected): void
    {
        $this->assertSame($expected, $this->helper->testValidateDate($date));
    }

    public static function validateDateProvider(): array
    {
        return [
            'valid date' => ['2025-01-15', true],
            'valid leap year' => ['2024-02-29', true],
            'valid year boundary' => ['2025-12-31', true],
            'invalid format slashes' => ['01/15/2025', false],
            'invalid format text' => ['January 15, 2025', false],
            'empty string' => ['', false],
            'overflow day feb 30' => ['2025-02-30', false],
            'overflow day feb 31' => ['2025-02-31', false],
            'overflow month 13' => ['2025-13-01', false],
            'non-leap year feb 29' => ['2025-02-29', false],
            'overflow day apr 31' => ['2025-04-31', false],
        ];
    }

    public function testValidateDateRejectsOverflowDates(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/controllers/traits/BookingHelpersTrait.php');
        $this->assertStringContainsString('checkdate', $source,
            'validateDate must use checkdate() to reject overflow dates');
    }

    /**
     * @dataProvider normalizeQuantityProvider
     */
    public function testNormalizeQuantity($input, int $expected): void
    {
        $this->assertSame($expected, $this->helper->testNormalizeQuantity($input));
    }

    public static function normalizeQuantityProvider(): array
    {
        return [
            'positive int' => [3, 3],
            'string number' => ['5', 5],
            'null defaults to 1' => [null, 1],
            'zero becomes 1' => [0, 1],
            'negative becomes 1' => [-5, 1],
            'one stays 1' => [1, 1],
            'large number' => [100, 100],
        ];
    }

    /**
     * @dataProvider normalizeIdProvider
     */
    public function testNormalizeId($input, ?int $expected): void
    {
        $this->assertSame($expected, $this->helper->testNormalizeId($input));
    }

    public static function normalizeIdProvider(): array
    {
        return [
            'positive int' => [42, 42],
            'string number' => ['7', 7],
            'null returns null' => [null, null],
            'empty string returns null' => ['', null],
            'zero returns 0' => [0, 0],
            'string zero returns 0' => ['0', 0],
        ];
    }
}
