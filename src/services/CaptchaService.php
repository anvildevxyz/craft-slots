<?php

namespace anvildev\slots\services;

use anvildev\slots\models\Settings;
use anvildev\slots\Slots;
use Craft;
use craft\base\Component;

/**
 * Verifies CAPTCHA tokens server-side to protect booking forms from automated abuse.
 *
 * Supports multiple providers: Google reCAPTCHA v3 (score-based), hCaptcha, and
 * Cloudflare Turnstile. The active provider is selected via plugin settings.
 */
class CaptchaService extends Component
{
    private const PROVIDER_CONFIG = [
        'recaptcha' => ['key' => 'recaptchaSecretKey', 'url' => 'https://www.google.com/recaptcha/api/siteverify', 'label' => 'reCAPTCHA', 'provider' => 'recaptcha'],
        'hcaptcha' => ['key' => 'hcaptchaSecretKey', 'url' => 'https://hcaptcha.com/siteverify', 'label' => 'hCaptcha', 'provider' => 'hcaptcha'],
        'turnstile' => ['key' => 'turnstileSecretKey', 'url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify', 'label' => 'Turnstile', 'provider' => 'turnstile'],
    ];

    public function verify(string $token, ?string $ipAddress = null): bool
    {
        $settings = Slots::getInstance()->getSettings();
        if (!$settings->enableCaptcha) {
            return true;
        }

        if (empty($token)) {
            return false;
        }

        $provider = $settings->captchaProvider;
        if (empty($provider)) {
            Craft::warning('CAPTCHA is enabled but no provider is configured', __METHOD__);
            return false;
        }

        return match ($provider) {
            'recaptcha' => $this->verifyRecaptcha($token, $ipAddress, $settings),
            'hcaptcha' => $this->verifyHcaptcha($token, $ipAddress, $settings),
            'turnstile' => $this->verifyTurnstile($token, $ipAddress, $settings),
            default => (function() use ($provider) {
                Craft::warning("Unknown CAPTCHA provider: {$provider}", __METHOD__);
                return false;
            }
            )(),
        };
    }

    protected function verifyRecaptcha(string $token, ?string $ipAddress, Settings $settings): bool
    {
        return $this->verifyWithProvider($token, $ipAddress, $settings, self::PROVIDER_CONFIG['recaptcha']);
    }

    protected function verifyHcaptcha(string $token, ?string $ipAddress, Settings $settings): bool
    {
        return $this->verifyWithProvider($token, $ipAddress, $settings, self::PROVIDER_CONFIG['hcaptcha']);
    }

    protected function verifyTurnstile(string $token, ?string $ipAddress, Settings $settings): bool
    {
        return $this->verifyWithProvider($token, $ipAddress, $settings, self::PROVIDER_CONFIG['turnstile']);
    }

    protected function verifyWithProvider(string $token, ?string $ipAddress, Settings $settings, array $config): bool
    {
        $secretKey = $settings->{$config['key']};
        if (empty($secretKey)) {
            Craft::warning("{$config['label']} secret key is not configured", __METHOD__);
            return false;
        }

        $data = ['secret' => $secretKey, 'response' => $token];
        if ($ipAddress) {
            $data['remoteip'] = $ipAddress;
        }

        $response = $this->sendVerificationRequest($config['url'], $data);
        if (!$response || !isset($response['success'])) {
            return false;
        }

        // reCAPTCHA v3 requires score check and action validation; others just need success=true
        if (($config['provider'] ?? null) === 'recaptcha') {
            $threshold = $settings->recaptchaScoreThreshold;
            $action = $settings->recaptchaAction;
            $score = $response['score'] ?? 0;
            $success = $response['success'] === true && $score >= $threshold;

            // Validate the action matches the expected action for reCAPTCHA v3
            if ($success && isset($response['action']) && $response['action'] !== $action) {
                Craft::warning("reCAPTCHA action mismatch. Expected '{$action}', got '{$response['action']}'", __METHOD__);
                $success = false;
            }

            if (!$success) {
                Craft::warning("reCAPTCHA verification failed. Score: {$score}", __METHOD__);
            }
            return $success;
        }

        return $response['success'] === true;
    }

    protected function sendVerificationRequest(string $url, array $data): ?array
    {
        try {
            $client = Craft::createGuzzleClient(['timeout' => 10, 'connect_timeout' => 5]);
            $body = $client->post($url, ['form_params' => $data])->getBody()->getContents();
            $result = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Craft::error('Failed to parse CAPTCHA verification response: ' . json_last_error_msg(), __METHOD__);
                return null;
            }
            return $result;
        } catch (\Exception $e) {
            Craft::error('CAPTCHA verification request failed: ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }
}
