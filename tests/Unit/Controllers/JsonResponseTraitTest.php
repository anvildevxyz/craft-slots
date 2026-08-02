<?php

namespace anvildev\slots\tests\Unit\Controllers;

use anvildev\slots\controllers\traits\JsonResponseTrait;
use anvildev\slots\tests\Support\TestCase;
use craft\web\Response;
use Yii;

class JsonResponseTraitTest extends TestCase
{
    private object $controller;
    private ?array $capturedResponse;
    private int $lastStatusCode;
    private $originalApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalApp = Yii::$app;
        $this->capturedResponse = null;
        $this->lastStatusCode = 200;
        $test = $this;
        $mockResponse = \Mockery::mock(Response::class);

        // Mock Yii::$app->getResponse() for setStatusCode calls in jsonError
        $mockWebResponse = \Mockery::mock();
        $mockWebResponse->shouldReceive('setStatusCode')->andReturnUsing(function (int $code) use ($test) {
            $test->lastStatusCode = $code;
        });
        $mockApp = \Mockery::mock();
        $mockApp->shouldReceive('getResponse')->andReturn($mockWebResponse);
        Yii::$app = $mockApp;

        $this->controller = new class($test, $mockResponse) {
            use JsonResponseTrait;

            private JsonResponseTraitTest $test;
            private Response $mockResponse;

            public function __construct(JsonResponseTraitTest $test, Response $mockResponse)
            {
                $this->test = $test;
                $this->mockResponse = $mockResponse;
            }

            public function asJson($data): Response
            {
                $this->test->setCapturedResponse($data);
                return $this->mockResponse;
            }

            public function callJsonError(string $message, ?string $errorType = null, array $errors = []): Response
            {
                return $this->jsonError($message, $errorType, $errors);
            }

            public function callJsonSuccess(string $message = '', array $data = []): Response
            {
                return $this->jsonSuccess($message, $data);
            }
        };
    }

    protected function tearDown(): void
    {
        Yii::$app = $this->originalApp;
        parent::tearDown();
    }

    public function setCapturedResponse(array $response): void
    {
        $this->capturedResponse = $response;
    }

    public function testJsonErrorBasic(): void
    {
        $this->controller->callJsonError('Something went wrong');

        $this->assertSame(false, $this->capturedResponse['success']);
        $this->assertSame('Something went wrong', $this->capturedResponse['message']);
        $this->assertArrayNotHasKey('error', $this->capturedResponse);
        $this->assertArrayNotHasKey('errors', $this->capturedResponse);
        $this->assertSame(400, $this->lastStatusCode);
    }

    public function testJsonErrorWithErrorType(): void
    {
        $this->controller->callJsonError('Rate limited', 'rate_limit');

        $this->assertSame(false, $this->capturedResponse['success']);
        $this->assertSame('Rate limited', $this->capturedResponse['message']);
        $this->assertSame('rate_limit', $this->capturedResponse['error']);
        $this->assertArrayNotHasKey('errors', $this->capturedResponse);
    }

    public function testJsonErrorWithErrorsArray(): void
    {
        $errors = ['email' => ['Invalid email']];
        $this->controller->callJsonError('Validation failed', 'validation', $errors);

        $this->assertSame(false, $this->capturedResponse['success']);
        $this->assertSame('validation', $this->capturedResponse['error']);
        $this->assertSame($errors, $this->capturedResponse['errors']);
    }

    public function testJsonSuccessBasic(): void
    {
        $this->controller->callJsonSuccess('Booking created');

        $this->assertSame(true, $this->capturedResponse['success']);
        $this->assertSame('Booking created', $this->capturedResponse['message']);
    }

    public function testJsonSuccessWithExtraData(): void
    {
        $this->controller->callJsonSuccess('Done', ['reservationId' => 42]);

        $this->assertSame(true, $this->capturedResponse['success']);
        $this->assertSame('Done', $this->capturedResponse['message']);
        $this->assertSame(42, $this->capturedResponse['reservationId']);
    }

    public function testJsonSuccessEmptyMessage(): void
    {
        $this->controller->callJsonSuccess();

        $this->assertSame(true, $this->capturedResponse['success']);
        $this->assertSame('', $this->capturedResponse['message']);
    }
}
