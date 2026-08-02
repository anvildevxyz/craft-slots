<?php

namespace anvildev\slots\tests\Unit\Exceptions;

use anvildev\slots\exceptions\BookingConflictException;
use anvildev\slots\exceptions\BookingException;
use anvildev\slots\exceptions\BookingNotFoundException;
use anvildev\slots\exceptions\BookingRateLimitException;
use anvildev\slots\exceptions\BookingValidationException;
use anvildev\slots\tests\Support\TestCase;

/**
 * Booking Exception Test
 *
 * Tests exception classes for the booking system
 */
class BookingExceptionTest extends TestCase
{
    public function testBookingExceptionIsThrowable(): void
    {
        $exception = new BookingException('Test message');

        $this->assertInstanceOf(\Throwable::class, $exception);
        $this->assertInstanceOf(\Exception::class, $exception);
    }

    public function testBookingExceptionHasMessage(): void
    {
        $exception = new BookingException('Test error message');

        $this->assertEquals('Test error message', $exception->getMessage());
    }

    public function testBookingExceptionHasCode(): void
    {
        $exception = new BookingException('Test', 404);

        $this->assertEquals(404, $exception->getCode());
    }

    public function testBookingConflictExceptionExtendsBookingException(): void
    {
        $exception = new BookingConflictException('Conflict');

        $this->assertInstanceOf(BookingException::class, $exception);
    }

    public function testBookingNotFoundExceptionExtendsBookingException(): void
    {
        $exception = new BookingNotFoundException('Not found');

        $this->assertInstanceOf(BookingException::class, $exception);
    }

    public function testBookingRateLimitExceptionExtendsBookingException(): void
    {
        $exception = new BookingRateLimitException('Rate limit');

        $this->assertInstanceOf(BookingException::class, $exception);
    }

    public function testBookingValidationExceptionExtendsBookingException(): void
    {
        $exception = new BookingValidationException('Validation failed');

        $this->assertInstanceOf(BookingException::class, $exception);
    }

    public function testBookingValidationExceptionStoresValidationErrors(): void
    {
        $errors = [
            'field1' => ['Error 1', 'Error 2'],
            'field2' => ['Error 3'],
        ];

        $exception = new BookingValidationException('Validation failed', $errors);

        $this->assertEquals($errors, $exception->getValidationErrors());
    }

    public function testBookingValidationExceptionEmptyErrors(): void
    {
        $exception = new BookingValidationException('Validation failed');

        $this->assertIsArray($exception->getValidationErrors());
        $this->assertEmpty($exception->getValidationErrors());
    }

    public function testBookingValidationExceptionWithCode(): void
    {
        $errors = ['field' => ['error']];
        $exception = new BookingValidationException('Validation failed', $errors, 422);

        $this->assertEquals(422, $exception->getCode());
        $this->assertEquals($errors, $exception->getValidationErrors());
    }

    public function testBookingValidationExceptionWithPrevious(): void
    {
        $previous = new \Exception('Previous exception');
        $exception = new BookingValidationException('Validation failed', [], 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testExceptionCanBeCaught(): void
    {
        try {
            throw new BookingConflictException('Test conflict');
        } catch (BookingException $e) {
            $this->assertInstanceOf(BookingConflictException::class, $e);
            $this->assertEquals('Test conflict', $e->getMessage());
        }
    }

    public function testExceptionCanBeCaughtByBaseType(): void
    {
        try {
            throw new BookingValidationException('Test validation');
        } catch (\Exception $e) {
            $this->assertInstanceOf(BookingValidationException::class, $e);
        }
    }

    public function testMultipleValidationErrors(): void
    {
        $errors = [
            'email' => ['Email is required', 'Email format is invalid'],
            'phone' => ['Phone is required'],
            'date' => ['Date must be in the future'],
        ];

        $exception = new BookingValidationException('Multiple validation errors', $errors);

        $validationErrors = $exception->getValidationErrors();
        $this->assertCount(3, $validationErrors);
        $this->assertArrayHasKey('email', $validationErrors);
        $this->assertArrayHasKey('phone', $validationErrors);
        $this->assertArrayHasKey('date', $validationErrors);
        $this->assertCount(2, $validationErrors['email']);
    }

    public function testExceptionMessagePreserved(): void
    {
        $message = 'Custom error message with special characters: üöä!@#$%';
        $exception = new BookingException($message);

        $this->assertEquals($message, $exception->getMessage());
    }
}
