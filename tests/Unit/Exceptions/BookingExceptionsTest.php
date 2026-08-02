<?php

namespace anvildev\slots\tests\Unit\Exceptions;

use anvildev\slots\exceptions\BookingConflictException;
use anvildev\slots\exceptions\BookingException;
use anvildev\slots\exceptions\BookingNotFoundException;
use anvildev\slots\exceptions\BookingRateLimitException;
use anvildev\slots\exceptions\BookingValidationException;
use anvildev\slots\tests\Support\TestCase;

/**
 * Booking Exceptions Test
 *
 * Tests exception hierarchy, custom properties, and getName() translations.
 */
class BookingExceptionsTest extends TestCase
{
    /**
     * @beforeClass
     */
    public static function defineCraftStub(): void
    {
        if (!class_exists('Craft', false)) {
            eval('class Craft extends \yii\BaseYii {}');
        }
    }

    // =========================================================================
    // Exception hierarchy
    // =========================================================================

    public function testBookingExceptionExtendsYiiException(): void
    {
        $e = new BookingException('test');
        $this->assertInstanceOf(\yii\base\Exception::class, $e);
    }

    public function testBookingValidationExceptionExtendsBookingException(): void
    {
        $e = new BookingValidationException('test');
        $this->assertInstanceOf(BookingException::class, $e);
    }

    public function testBookingNotFoundExceptionExtendsBookingException(): void
    {
        $e = new BookingNotFoundException('test');
        $this->assertInstanceOf(BookingException::class, $e);
    }

    public function testBookingRateLimitExceptionExtendsBookingException(): void
    {
        $e = new BookingRateLimitException('test');
        $this->assertInstanceOf(BookingException::class, $e);
    }

    public function testBookingConflictExceptionExtendsBookingException(): void
    {
        $e = new BookingConflictException('test');
        $this->assertInstanceOf(BookingException::class, $e);
    }

    // =========================================================================
    // BookingException - getMessage and getName
    // =========================================================================

    public function testBookingExceptionStoresMessage(): void
    {
        $e = new BookingException('Something went wrong');
        $this->assertEquals('Something went wrong', $e->getMessage());
    }

    public function testBookingExceptionGetNameReturnsTranslatedString(): void
    {
        $e = new BookingException('test');
        // getName() uses Craft::t() which returns the key when no translation exists
        $this->assertIsString($e->getName());
    }

    // =========================================================================
    // BookingValidationException - validation errors
    // =========================================================================

    public function testValidationExceptionStoresErrors(): void
    {
        $errors = ['email' => 'Invalid email', 'date' => 'Required'];
        $e = new BookingValidationException('Validation failed', $errors);

        $this->assertEquals($errors, $e->getValidationErrors());
    }

    public function testValidationExceptionDefaultsToEmptyErrors(): void
    {
        $e = new BookingValidationException('Validation failed');
        $this->assertEquals([], $e->getValidationErrors());
    }

    public function testValidationExceptionPreservesMessage(): void
    {
        $e = new BookingValidationException('Custom message', ['field' => 'error']);
        $this->assertEquals('Custom message', $e->getMessage());
    }

    public function testValidationExceptionPreservesCode(): void
    {
        $e = new BookingValidationException('fail', [], 422);
        $this->assertEquals(422, $e->getCode());
    }

    public function testValidationExceptionPreservesPrevious(): void
    {
        $prev = new \RuntimeException('original');
        $e = new BookingValidationException('fail', [], 0, $prev);
        $this->assertSame($prev, $e->getPrevious());
    }

    // =========================================================================
    // getName() returns strings for all exceptions
    // =========================================================================

    public function testAllExceptionsHaveGetName(): void
    {
        $exceptions = [
            new BookingException('test'),
            new BookingValidationException('test'),
            new BookingNotFoundException('test'),
            new BookingRateLimitException('test'),
            new BookingConflictException('test'),
        ];

        foreach ($exceptions as $e) {
            $this->assertIsString($e->getName());
            $this->assertNotEmpty($e->getName());
        }
    }
}
