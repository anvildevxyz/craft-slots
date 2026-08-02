<?php

namespace anvildev\slots\console\controllers;

use anvildev\slots\models\Settings;
use anvildev\slots\Slots;
use Craft;
use craft\console\Controller;
use craft\helpers\App;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Health check diagnostics for the Slots plugin
 */
class DoctorController extends Controller
{
    public bool $ping = false;

    private int $passed = 0;
    private int $warnings = 0;
    private int $errors = 0;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'ping'];
    }

    public function actionIndex(): int
    {
        $this->stdout("\nSlots Health Check\n", Console::BOLD);
        $this->stdout("═══════════════════════════════════\n\n");

        $this->checkDatabase();
        $this->checkSettings();
        $this->checkEmail();
        $this->checkData();

        $settings = Settings::loadSettings();
        $this->checkCaptcha($settings);
        $this->checkPayments($settings);
        $this->checkQueue();

        $this->stdout("═══════════════════════════════════\n");
        $this->stdout("Result: ");
        $this->stdout("{$this->passed} passed", Console::FG_GREEN);
        if ($this->warnings > 0) {
            $this->stdout(", {$this->warnings} warning" . ($this->warnings !== 1 ? 's' : ''), Console::FG_YELLOW);
        }
        if ($this->errors > 0) {
            $this->stdout(", {$this->errors} error" . ($this->errors !== 1 ? 's' : ''), Console::FG_RED);
        }
        $this->stdout("\n\n");

        return $this->errors > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function checkDatabase(): void
    {
        $this->heading('Database');

        $tables = [
            'slots_settings', 'slots_reservations', 'slots_blackout_dates',
            'slots_blackout_dates_employees', 'slots_blackout_dates_locations',
            'slots_services', 'slots_service_locations',
            'slots_employees', 'slots_locations', 'slots_schedules',
            'slots_employee_schedule_assignments', 'slots_service_schedule_assignments',
            'slots_soft_locks', 'slots_payments',
        ];

        $db = Craft::$app->getDb();
        $missing = array_filter($tables, fn($t) => !$db->tableExists("{{%{$t}}}"));
        $total = count($tables);

        empty($missing)
            ? $this->pass("All {$total} tables present")
            : $this->fail(($total - count($missing)) . "/{$total} tables present — missing: " . implode(', ', $missing));
    }

    private function checkSettings(): void
    {
        $this->heading('Settings');

        $settings = Settings::loadSettings();

        if ($settings->id === null) {
            $this->fail('Plugin settings not found in database — save settings in the CP');
            return;
        }

        $this->pass('Plugin settings configured');

        $email = $settings->getEffectiveEmail();
        !empty($email) ? $this->pass("Owner email: {$email}") : $this->fail('No owner email configured');

        $name = $settings->getEffectiveName();
        !empty($name) ? $this->pass("Owner name: {$name}") : $this->warn('No owner name configured');
    }

    private function checkEmail(): void
    {
        $this->heading('Email');

        try {
            Craft::$app->getMailer() ? $this->pass('Craft mailer configured') : $this->fail('Craft mailer not available');
        } catch (\Throwable $e) {
            $this->fail('Craft mailer error: ' . $e->getMessage());
        }
    }

    private function checkData(): void
    {
        $this->heading('Data');

        $hints = [
            'Services' => 'no services configured — bookings won\'t work',
            'Employees' => 'no employees configured',
            'Locations' => 'no locations configured',
            'Schedules' => 'no working hours defined',
        ];

        $classes = [
            'Services' => \anvildev\slots\elements\Service::class,
            'Employees' => \anvildev\slots\elements\Employee::class,
            'Locations' => \anvildev\slots\elements\Location::class,
            'Schedules' => \anvildev\slots\elements\Schedule::class,
        ];

        foreach ($classes as $label => $class) {
            $count = $class::find()->siteId('*')->count();
            $count > 0 ? $this->pass("{$label}: {$count}") : $this->warn("{$label}: 0 — {$hints[$label]}");
        }
    }

    private function checkCaptcha(Settings $settings): void
    {
        if (!$settings->enableCaptcha) {
            return;
        }

        $this->heading('CAPTCHA', true);

        if (empty($settings->captchaProvider)) {
            $this->fail('No CAPTCHA provider selected');
            return;
        }

        $this->pass("Provider: {$settings->captchaProvider}");

        $keyPairs = [
            'recaptcha' => ['recaptchaSiteKey', 'recaptchaSecretKey'],
            'hcaptcha' => ['hcaptchaSiteKey', 'hcaptchaSecretKey'],
            'turnstile' => ['turnstileSiteKey', 'turnstileSecretKey'],
        ];

        if (!isset($keyPairs[$settings->captchaProvider])) {
            $this->fail("Unknown provider: {$settings->captchaProvider}");
            return;
        }

        [$siteKeyProp, $secretKeyProp] = $keyPairs[$settings->captchaProvider];

        if (!empty($settings->$siteKeyProp) && !empty($settings->$secretKeyProp)) {
            $this->pass('Site key and secret key configured');
        } else {
            $missing = array_filter([
                empty($settings->$siteKeyProp) ? 'site key' : null,
                empty($settings->$secretKeyProp) ? 'secret key' : null,
            ]);
            $this->fail('Missing: ' . implode(', ', $missing));
        }
    }


    /**
     * Direct-payment configuration checks: Stripe keys present + well-formed, the
     * webhook secret set (else payments never confirm), the gateway registered,
     * the currency resolvable, and test/live keys matched to the environment.
     * No-op unless payment mode is `direct`.
     */
    private function checkPayments(Settings $settings): void
    {
        if ($settings->getPaymentMode() !== Settings::PAYMENT_MODE_DIRECT) {
            return;
        }

        $this->heading('Direct Payments', true);

        $secret = (string) App::parseEnv($settings->stripeSecretKey);
        $publishable = (string) App::parseEnv($settings->stripePublishableKey);
        $webhookSecret = (string) App::parseEnv($settings->stripeWebhookSecret);

        // Secret key
        if ($secret === '') {
            $this->fail('Stripe secret key is not set');
        } elseif (!str_starts_with($secret, 'sk_')) {
            $this->fail('Stripe secret key is malformed (expected sk_…)');
        } else {
            $this->pass('Stripe secret key is set');
        }

        // Publishable key — guard against a secret key pasted into the public field.
        if ($publishable === '') {
            $this->fail('Stripe publishable key is not set');
        } elseif (str_starts_with($publishable, 'sk_')) {
            $this->fail('A SECRET key is configured as the publishable key — replace it with pk_…');
        } elseif (!str_starts_with($publishable, 'pk_')) {
            $this->warn('Stripe publishable key is malformed (expected pk_…)');
        } else {
            $this->pass('Stripe publishable key is set');
        }

        // Webhook secret — without it verifyWebhook() drops everything and payments never confirm.
        if ($webhookSecret === '') {
            $this->fail('Stripe webhook secret is not set — webhooks cannot be verified, so payments will never confirm');
        } elseif (!str_starts_with($webhookSecret, 'whsec_')) {
            $this->warn('Stripe webhook secret is malformed (expected whsec_…)');
        } else {
            $this->pass('Stripe webhook secret is set');
        }

        // Test/live keys vs environment.
        $devMode = Craft::$app->getConfig()->getGeneral()->devMode;
        if (str_starts_with($secret, 'sk_live_') && $devMode) {
            $this->warn('LIVE Stripe keys with devMode ON — real charges from a dev environment');
        }
        if (str_starts_with($secret, 'sk_test_') && !$devMode) {
            $this->warn('TEST Stripe keys with devMode OFF — a production site would take no real payments');
        }

        // Gateway registered + currency resolvable.
        Slots::getInstance()->getPaymentGateways()->getGateway('stripe')
            ? $this->pass('Stripe gateway is registered')
            : $this->fail('Stripe gateway is not registered');

        $currency = Slots::getInstance()->reports->getCurrency();
        $currency !== ''
            ? $this->pass("Payment currency resolves to {$currency}")
            : $this->warn('Payment currency could not be resolved');
    }

    private function checkQueue(): void
    {
        $this->heading('Queue');

        try {
            $queue = Craft::$app->getQueue();
            if ($queue instanceof \craft\queue\QueueInterface) {
                $info = $queue->getJobInfo();
                $waiting = count($info);
                $this->pass("Queue available — {$waiting} waiting job" . ($waiting !== 1 ? 's' : ''));
            } else {
                $this->pass('Queue available');
            }
        } catch (\Throwable $e) {
            $this->fail('Queue error: ' . $e->getMessage());
        }
    }

    private function heading(string $label, bool $enabled = false): void
    {
        $this->stdout($label . ($enabled ? ' [enabled]' : '') . "\n", Console::BOLD);
    }

    private function pass(string $message): void
    {
        $this->stdout("  ✓ {$message}\n", Console::FG_GREEN);
        $this->passed++;
    }

    private function warn(string $message): void
    {
        $this->stdout("  ! {$message}\n", Console::FG_YELLOW);
        $this->warnings++;
    }

    private function fail(string $message): void
    {
        $this->stdout("  ✗ {$message}\n", Console::FG_RED);
        $this->errors++;
    }
}
