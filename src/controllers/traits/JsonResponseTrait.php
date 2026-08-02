<?php

namespace anvildev\slots\controllers\traits;

use craft\web\Response;

trait JsonResponseTrait
{
    protected function jsonError(string $message, ?string $errorType = null, array $errors = [], int $statusCode = 400): Response
    {
        $response = ['success' => false, 'message' => $message];
        if ($errorType) {
            $response['error'] = $errorType;
        }
        if (!empty($errors)) {
            $response['errors'] = $errors;
        }
        \Yii::$app->getResponse()->setStatusCode($statusCode);
        return $this->asJson($response);
    }

    protected function jsonSuccess(string $message = '', array $data = []): Response
    {
        return $this->asJson(array_merge(['success' => true, 'message' => $message], $data));
    }
}
