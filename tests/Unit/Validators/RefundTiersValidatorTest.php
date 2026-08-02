<?php

namespace anvildev\slots\tests\Unit\Validators;

use anvildev\slots\tests\Support\TestCase;
use anvildev\slots\validators\RefundTiersValidator;
use ReflectionMethod;

class RefundTiersValidatorTest extends TestCase
{
    private RefundTiersValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new RefundTiersValidator();
    }

    public function testNullIsValid(): void
    {
        $this->assertTrue($this->validator->isValid(null));
    }

    public function testEmptyArrayIsValid(): void
    {
        $this->assertTrue($this->validator->isValid([]));
    }

    public function testValidSingleTier(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => 100],
        ];
        $this->assertTrue($this->validator->isValid($tiers));
    }

    public function testValidMultipleTiers(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => 100],
            ['hoursBeforeStart' => 24, 'refundPercentage' => 50],
            ['hoursBeforeStart' => 0, 'refundPercentage' => 0],
        ];
        $this->assertTrue($this->validator->isValid($tiers));
    }

    public function testStringIsInvalid(): void
    {
        $this->assertFalse($this->validator->isValid('not an array'));
    }

    public function testIntegerIsInvalid(): void
    {
        $this->assertFalse($this->validator->isValid(42));
    }

    public function testTierWithoutHoursBeforeStartIsInvalid(): void
    {
        $tiers = [
            ['refundPercentage' => 100],
        ];
        $this->assertFalse($this->validator->isValid($tiers));
    }

    public function testTierWithoutRefundPercentageIsInvalid(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48],
        ];
        $this->assertFalse($this->validator->isValid($tiers));
    }

    public function testNegativeHoursIsInvalid(): void
    {
        $tiers = [
            ['hoursBeforeStart' => -1, 'refundPercentage' => 100],
        ];
        $this->assertFalse($this->validator->isValid($tiers));
    }

    public function testNegativePercentageIsInvalid(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => -10],
        ];
        $this->assertFalse($this->validator->isValid($tiers));
    }

    public function testPercentageOver100IsInvalid(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => 101],
        ];
        $this->assertFalse($this->validator->isValid($tiers));
    }

    public function testNonNumericHoursIsInvalid(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 'abc', 'refundPercentage' => 100],
        ];
        $this->assertFalse($this->validator->isValid($tiers));
    }

    public function testNonNumericPercentageIsInvalid(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => 'full'],
        ];
        $this->assertFalse($this->validator->isValid($tiers));
    }

    public function testNonArrayTierIsInvalid(): void
    {
        $tiers = ['not a tier'];
        $this->assertFalse($this->validator->isValid($tiers));
    }

    public function testZeroHoursIsValid(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 0, 'refundPercentage' => 0],
        ];
        $this->assertTrue($this->validator->isValid($tiers));
    }

    public function testBoundaryPercentage100IsValid(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => 100],
        ];
        $this->assertTrue($this->validator->isValid($tiers));
    }

    public function testBoundaryPercentage0IsValid(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => 0],
        ];
        $this->assertTrue($this->validator->isValid($tiers));
    }

    public function testFloatHoursAreValid(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 2.5, 'refundPercentage' => 50],
        ];
        $this->assertTrue($this->validator->isValid($tiers));
    }

    public function testMixedValidAndInvalidTiers(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => 100],
            ['hoursBeforeStart' => -1, 'refundPercentage' => 50],
        ];
        $this->assertFalse($this->validator->isValid($tiers));
    }

    // =========================================================================
    // Wiring — the validator is only useful if the models actually apply it.
    // Asserted structurally because instantiating Settings/Service (and the
    // Craft::t() call in the failure message) needs a booted Craft app.
    // =========================================================================

    private static function methodSource(string $class, string $method): string
    {
        $rm = new ReflectionMethod($class, $method);
        $lines = file($rm->getFileName());
        return implode('', array_slice($lines, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));
    }

    public function testItIsAYiiValidatorSoItCanBeUsedInRules(): void
    {
        $this->assertInstanceOf(\yii\validators\Validator::class, $this->validator);
    }

    public function testServiceAppliesTheValidatorToRefundTiers(): void
    {
        $src = self::methodSource(\anvildev\slots\elements\Service::class, 'defineRules');
        $this->assertStringContainsString("[['refundTiers'], RefundTiersValidator::class]", $src);
    }

    public function testSettingsAppliesTheValidatorToDefaultRefundTiers(): void
    {
        $src = self::methodSource(\anvildev\slots\models\Settings::class, 'rules');
        $this->assertStringContainsString("[['defaultRefundTiers'], RefundTiersValidator::class]", $src);
    }

    public function testTheFailureMessageKeyExistsInTheEnglishCatalog(): void
    {
        $catalog = require dirname(__DIR__, 3) . '/src/translations/en/slots.php';
        $this->assertArrayHasKey('validation.refundTiersInvalid', $catalog);
    }

    // A percentage above 100 survives RefundTierHelper::normalize() — it casts to
    // int without clamping — so this is the case the wiring actually catches.
    public function testPercentageAboveOneHundredIsInvalid(): void
    {
        $this->assertFalse($this->validator->isValid([
            ['hoursBeforeStart' => 24, 'refundPercentage' => 150],
        ]));
    }
}
