<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\queue\jobs\SendBookingEmailJob;
use anvildev\slots\services\BookingNotificationService;
use anvildev\slots\services\EmailRenderService;
use anvildev\slots\tests\Support\TestCase;
use ReflectionMethod;

/**
 * Tests for quantity-changed email notification feature.
 */
class BookingNotificationQuantityTest extends TestCase
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

    public function testQuantityChangedEmailMethodExists(): void
    {
        $service = new BookingNotificationService();
        $this->assertTrue(method_exists($service, 'queueQuantityChangedEmail'));
    }

    public function testQuantityChangedEmailMethodSignature(): void
    {
        $method = new ReflectionMethod(BookingNotificationService::class, 'queueQuantityChangedEmail');

        $this->assertTrue($method->isPublic());
        $this->assertSame('void', (string) $method->getReturnType());

        $params = $method->getParameters();
        $this->assertCount(5, $params);
        $this->assertSame('reservationId', $params[0]->getName());
        $this->assertSame('previousQuantity', $params[1]->getName());
        $this->assertSame('newQuantity', $params[2]->getName());
        $this->assertSame('refundAmount', $params[3]->getName());
        $this->assertSame('priority', $params[4]->getName());

        // refundAmount and priority should have defaults
        $this->assertTrue($params[3]->isDefaultValueAvailable());
        $this->assertSame(0.0, $params[3]->getDefaultValue());
        $this->assertTrue($params[4]->isDefaultValueAvailable());
        $this->assertSame(1024, $params[4]->getDefaultValue());
    }

    public function testSendBookingEmailJobHasQuantityProperties(): void
    {
        $job = new SendBookingEmailJob([
            'reservationId' => 1,
            'emailType' => 'quantity_changed',
            'previousQuantity' => 3,
            'newQuantity' => 5,
            'refundAmount' => 10.50,
        ]);

        $this->assertSame(1, $job->reservationId);
        $this->assertSame('quantity_changed', $job->emailType);
        $this->assertSame(3, $job->previousQuantity);
        $this->assertSame(5, $job->newQuantity);
        $this->assertSame(10.50, $job->refundAmount);
    }

    public function testSendBookingEmailJobQuantityPropertiesDefaultValues(): void
    {
        $job = new SendBookingEmailJob([
            'reservationId' => 1,
            'emailType' => 'confirmation',
        ]);

        $this->assertNull($job->previousQuantity);
        $this->assertNull($job->newQuantity);
        $this->assertSame(0.0, $job->refundAmount);
    }

    public function testEmailRenderServiceHasQuantityChangedMethod(): void
    {
        $this->assertTrue(method_exists(EmailRenderService::class, 'renderQuantityChangedEmail'));
    }

    public function testEmailRenderQuantityChangedMethodSignature(): void
    {
        $method = new ReflectionMethod(EmailRenderService::class, 'renderQuantityChangedEmail');

        $this->assertTrue($method->isPublic());
        $this->assertSame('string', (string) $method->getReturnType());

        $params = $method->getParameters();
        $this->assertCount(5, $params);
        $this->assertSame('reservation', $params[0]->getName());
        $this->assertSame('settings', $params[1]->getName());
        $this->assertSame('previousQuantity', $params[2]->getName());
        $this->assertSame('newQuantity', $params[3]->getName());
        $this->assertSame('refundAmount', $params[4]->getName());

        $this->assertTrue($params[4]->isDefaultValueAvailable());
        $this->assertSame(0.0, $params[4]->getDefaultValue());
    }

    /**
     * @dataProvider translationFileProvider
     */
    public function testTranslationFilesContainQuantityChangedKeys(string $locale): void
    {
        $file = dirname(__DIR__, 2) . "/../src/translations/{$locale}/slots.php";
        $this->assertFileExists($file);

        $translations = require $file;

        $this->assertArrayHasKey('emails.subject.quantityChanged', $translations);
        $this->assertArrayHasKey('emails.quantityChanged.preheader', $translations);
        $this->assertNotEmpty($translations['emails.subject.quantityChanged']);
        $this->assertNotEmpty($translations['emails.quantityChanged.preheader']);
    }

    /**
     * @dataProvider translationFileProvider
     */
    public function testTranslationBodyContainsPlaceholders(string $locale): void
    {
        $file = dirname(__DIR__, 2) . "/../src/translations/{$locale}/slots.php";
        $translations = require $file;

        // The preheader is the string that actually renders with the quantities
        // interpolated; `emails.quantityChanged.body` was a legacy key no
        // template ever referenced and was pruned in P6.
        $preheader = $translations['emails.quantityChanged.preheader'];

        $this->assertStringContainsString('{previousQuantity}', $preheader);
        $this->assertStringContainsString('{newQuantity}', $preheader);
    }

    public static function translationFileProvider(): array
    {
        return [
            'English' => ['en'],
            'German' => ['de'],
            'Spanish' => ['es'],
            'French' => ['fr'],
            'Italian' => ['it'],
            'Japanese' => ['ja'],
            'Dutch' => ['nl'],
            'Portuguese' => ['pt'],
        ];
    }
}
