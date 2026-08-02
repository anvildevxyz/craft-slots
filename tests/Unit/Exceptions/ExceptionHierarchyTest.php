<?php

namespace anvildev\slots\tests\Unit\Exceptions;

use anvildev\slots\exceptions\BookingConflictException;
use anvildev\slots\exceptions\BookingException;
use anvildev\slots\exceptions\BookingNotFoundException;
use anvildev\slots\exceptions\BookingRateLimitException;
use anvildev\slots\exceptions\BookingValidationException;
use anvildev\slots\tests\Support\TestCase;
use ReflectionClass;

class ExceptionHierarchyTest extends TestCase
{
    // =========================================================================
    // Hierarchy
    // =========================================================================

    public function testBookingExceptionExtendsYiiException(): void
    {
        $ref = new ReflectionClass(BookingException::class);
        $this->assertTrue($ref->isSubclassOf(\yii\base\Exception::class));
    }

    /**
     * @dataProvider subclassProvider
     */
    public function testSubclassExtendsBookingException(string $className): void
    {
        $ref = new ReflectionClass($className);
        $this->assertTrue(
            $ref->isSubclassOf(BookingException::class),
            "{$className} should extend BookingException"
        );
    }

    public static function subclassProvider(): array
    {
        return [
            'Conflict' => [BookingConflictException::class],
            'NotFound' => [BookingNotFoundException::class],
            'RateLimit' => [BookingRateLimitException::class],
            'Validation' => [BookingValidationException::class],
        ];
    }

    /**
     * @dataProvider subclassProvider
     */
    public function testSubclassIsAlsoYiiException(string $className): void
    {
        $ref = new ReflectionClass($className);
        $this->assertTrue($ref->isSubclassOf(\yii\base\Exception::class));
    }

    /**
     * @dataProvider subclassProvider
     */
    public function testSubclassIsAlsoThrowable(string $className): void
    {
        $ref = new ReflectionClass($className);
        $this->assertTrue($ref->implementsInterface(\Throwable::class));
    }

    // =========================================================================
    // Instantiation
    // =========================================================================

    /**
     * @dataProvider allExceptionProvider
     */
    public function testExceptionCanBeConstructedWithMessage(string $className): void
    {
        $e = new $className('test message');
        $this->assertSame('test message', $e->getMessage());
        $this->assertSame(0, $e->getCode());
        $this->assertNull($e->getPrevious());
    }

    /**
     * @dataProvider allExceptionProvider
     */
    public function testExceptionCanBeThrown(string $className): void
    {
        $this->expectException($className);
        throw new $className('thrown');
    }

    /**
     * @dataProvider allExceptionProvider
     */
    public function testExceptionCanBeCaughtAsBookingException(string $className): void
    {
        try {
            throw new $className('caught');
        } catch (BookingException $e) {
            $this->assertInstanceOf($className, $e);
            return;
        }
        $this->fail("{$className} should be catchable as BookingException");
    }

    public static function allExceptionProvider(): array
    {
        return [
            'BookingException' => [BookingException::class],
            'Conflict' => [BookingConflictException::class],
            'NotFound' => [BookingNotFoundException::class],
            'RateLimit' => [BookingRateLimitException::class],
            'Validation' => [BookingValidationException::class],
        ];
    }

    // =========================================================================
    // BookingValidationException specifics
    // =========================================================================

    public function testValidationExceptionStoresErrors(): void
    {
        $errors = ['email' => ['Invalid email'], 'name' => ['Required']];
        $e = new BookingValidationException('Validation failed', $errors);

        $this->assertSame($errors, $e->getValidationErrors());
        $this->assertSame('Validation failed', $e->getMessage());
    }

    public function testValidationExceptionDefaultsToEmptyErrors(): void
    {
        $e = new BookingValidationException('fail');
        $this->assertSame([], $e->getValidationErrors());
    }

    public function testValidationExceptionPreservesPrevious(): void
    {
        $previous = new \RuntimeException('root cause');
        $e = new BookingValidationException('wrapped', [], 42, $previous);

        $this->assertSame(42, $e->getCode());
        $this->assertSame($previous, $e->getPrevious());
    }

    public function testValidationExceptionConstructorSignature(): void
    {
        $ref = new ReflectionClass(BookingValidationException::class);
        $constructor = $ref->getConstructor();
        $this->assertNotNull($constructor);

        $params = $constructor->getParameters();
        $this->assertCount(4, $params);
        $this->assertSame('message', $params[0]->getName());
        $this->assertSame('validationErrors', $params[1]->getName());
        $this->assertSame('code', $params[2]->getName());
        $this->assertSame('previous', $params[3]->getName());

        $this->assertTrue($params[0]->isOptional());
        $this->assertTrue($params[1]->isOptional());
        $this->assertTrue($params[2]->isOptional());
        $this->assertTrue($params[3]->isOptional());
    }

    // =========================================================================
    // getName() exists on all
    // =========================================================================

    /**
     * @dataProvider allExceptionProvider
     */
    public function testGetNameMethodExists(string $className): void
    {
        $ref = new ReflectionClass($className);
        $this->assertTrue($ref->hasMethod('getName'));

        $method = $ref->getMethod('getName');
        $this->assertTrue($method->isPublic());
        $this->assertSame($className, $method->getDeclaringClass()->getName());

        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('string', $returnType->getName());
    }

    // =========================================================================
    // instanceof discrimination
    // =========================================================================

    public function testExceptionsAreDistinguishable(): void
    {
        $conflict = new BookingConflictException();
        $notFound = new BookingNotFoundException();
        $rateLimit = new BookingRateLimitException();
        $validation = new BookingValidationException();

        $this->assertInstanceOf(BookingException::class, $conflict);
        $this->assertNotInstanceOf(BookingNotFoundException::class, $conflict);

        $this->assertInstanceOf(BookingException::class, $notFound);
        $this->assertNotInstanceOf(BookingConflictException::class, $notFound);

        $this->assertInstanceOf(BookingException::class, $rateLimit);
        $this->assertNotInstanceOf(BookingValidationException::class, $rateLimit);

        $this->assertInstanceOf(BookingException::class, $validation);
        $this->assertNotInstanceOf(BookingRateLimitException::class, $validation);
    }

    public function testMatchTrueOrderMatters(): void
    {
        $e = new BookingValidationException('test', ['field' => ['error']]);

        // Subclass match should hit before parent — same order as HandlesExceptionsTrait
        $matched = match (true) {
            $e instanceof BookingRateLimitException => 'rate_limit',
            $e instanceof BookingConflictException => 'conflict',
            $e instanceof BookingValidationException => 'validation',
            $e instanceof BookingNotFoundException => 'not_found',
            $e instanceof BookingException => 'general',
            default => 'unknown',
        };

        $this->assertSame('validation', $matched);
    }

    public function testParentCatchAllMatchesUnknownSubclass(): void
    {
        // BookingException itself should fall through to 'general'
        $e = new BookingException('generic');

        $matched = match (true) {
            $e instanceof BookingRateLimitException => 'rate_limit',
            $e instanceof BookingConflictException => 'conflict',
            $e instanceof BookingValidationException => 'validation',
            $e instanceof BookingNotFoundException => 'not_found',
            $e instanceof BookingException => 'general',
            default => 'unknown',
        };

        $this->assertSame('general', $matched);
    }
}
