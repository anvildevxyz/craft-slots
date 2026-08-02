<?php

namespace anvildev\slots\tests\Unit\Controllers;

use anvildev\slots\controllers\traits\HandlesExceptionsTrait;
use anvildev\slots\exceptions\BookingConflictException;
use anvildev\slots\exceptions\BookingException;
use anvildev\slots\exceptions\BookingNotFoundException;
use anvildev\slots\exceptions\BookingRateLimitException;
use anvildev\slots\exceptions\BookingValidationException;
use anvildev\slots\tests\Support\TestCase;
use ReflectionClass;

class HandlesExceptionsTraitTest extends TestCase
{
    private string $traitSource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->traitSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/controllers/traits/HandlesExceptionsTrait.php'
        );
    }

    /**
     * @dataProvider exceptionTypeProvider
     */
    public function testExceptionTypeIsHandled(string $exceptionClass): void
    {
        $shortName = (new ReflectionClass($exceptionClass))->getShortName();
        $this->assertStringContainsString(
            $shortName,
            $this->traitSource,
            "HandlesExceptionsTrait should handle {$shortName}"
        );
    }

    public static function exceptionTypeProvider(): array
    {
        return [
            'RateLimit' => [BookingRateLimitException::class],
            'Conflict' => [BookingConflictException::class],
            'Validation' => [BookingValidationException::class],
            'NotFound' => [BookingNotFoundException::class],
            'BookingException' => [BookingException::class],
        ];
    }

    /**
     * @dataProvider errorTypeStringProvider
     */
    public function testErrorTypeStringExists(string $errorType): void
    {
        $this->assertStringContainsString(
            "'{$errorType}'",
            $this->traitSource,
            "Error type '{$errorType}' should be defined in the trait"
        );
    }

    public static function errorTypeStringProvider(): array
    {
        return [
            'rate_limit' => ['rate_limit'],
            'conflict' => ['conflict'],
            'validation' => ['validation'],
            'not_found' => ['not_found'],
            'general' => ['general'],
        ];
    }

    public function testSubclassesAreMatchedBeforeParent(): void
    {
        $rateLimitPos = strpos($this->traitSource, 'BookingRateLimitException');
        $conflictPos = strpos($this->traitSource, 'BookingConflictException');
        $validationPos = strpos($this->traitSource, 'BookingValidationException');
        $notFoundPos = strpos($this->traitSource, 'BookingNotFoundException');
        $parentPos = strpos($this->traitSource, '$e instanceof BookingException');

        // Subclasses must appear before the parent catch-all
        $this->assertLessThan($parentPos, $rateLimitPos, 'RateLimit should be matched before BookingException');
        $this->assertLessThan($parentPos, $conflictPos, 'Conflict should be matched before BookingException');
        $this->assertLessThan($parentPos, $validationPos, 'Validation should be matched before BookingException');
        $this->assertLessThan($parentPos, $notFoundPos, 'NotFound should be matched before BookingException');
    }

    public function testDefaultCaseExistsAfterBookingException(): void
    {
        $parentPos = strpos($this->traitSource, '$e instanceof BookingException');
        $defaultPos = strpos($this->traitSource, 'default =>');

        $this->assertNotFalse($defaultPos, 'A default case should exist');
        $this->assertGreaterThan($parentPos, $defaultPos, 'default should come after BookingException');
    }

    public function testHandleExceptionMethodSignature(): void
    {
        $ref = new ReflectionClass(new class {
            use HandlesExceptionsTrait;

            // Stubs for required methods
            protected function jsonError(string $msg, ?string $type = null, array $errors = []): mixed
            {
                return null;
            }

            protected function redirectToPostedUrl($model = null): mixed
            {
                return null;
            }
        });

        $this->assertTrue($ref->hasMethod('handleException'));
        $method = $ref->getMethod('handleException');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame('e', $params[0]->getName());
        $this->assertSame('model', $params[1]->getName());
        $this->assertTrue($params[1]->isOptional());
    }
}
