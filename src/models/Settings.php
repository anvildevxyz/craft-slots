<?php

namespace anvildev\slots\models;

use anvildev\slots\records\SettingsRecord;
use anvildev\slots\validators\RefundTiersValidator;
use Craft;
use craft\base\Model;
use craft\helpers\App;

class Settings extends Model
{
    private static ?self $cached = null;

    private static ?float $cachedAt = null;

    private const CACHE_TTL = 60;

    public ?int $id = null;

    // General
    public ?string $defaultCurrency = null;
    public int $softLockDurationMinutes = 5;
    /**
     * Hard ceiling, in minutes, on a soft lock's total lifetime. A lock may be
     * renewed via {@see \anvildev\slots\services\SoftLockService::extendLock()}
     * as the customer completes checkout, but never held past its creation time
     * plus this value — so a client cannot sit on a slot indefinitely and starve
     * real bookings.
     */
    public int $softLockMaxLifetimeMinutes = 30;
    public int $minimumAdvanceBookingHours = 0;
    public int $maximumAdvanceBookingDays = 90;
    public int $cancellationPolicyHours = 24;
    public bool $enableRateLimiting = true;
    public int $rateLimitPerEmail = 5;
    public int $rateLimitPerIp = 10;
    public bool $enableAutoRefund = false;
    public array|string|null $defaultRefundTiers = null;

    // Security
    public bool $enableCaptcha = false;
    public ?string $captchaProvider = null;
    public ?string $recaptchaSiteKey = null;
    public ?string $recaptchaSecretKey = null;
    public ?string $hcaptchaSiteKey = null;
    public ?string $hcaptchaSecretKey = null;
    public ?string $turnstileSiteKey = null;
    public ?string $turnstileSecretKey = null;
    public float $recaptchaScoreThreshold = 0.5;
    public string $recaptchaAction = 'booking';
    public bool $enableHoneypot = true;
    public string $honeypotFieldName = 'website';
    public bool $enableIpBlocking = false;
    public ?string $blockedIps = null;
    public bool $enableTimeBasedLimits = true;
    public int $minimumSubmissionTime = 3;
    public bool $enableAuditLog = false;

    // Calendar

    // Virtual meetings

    // Notifications
    public bool $ownerNotificationEnabled = true;
    public ?string $ownerNotificationSubject = null;
    public ?string $ownerNotificationLanguage = null;
    public ?string $ownerEmail = null;
    public ?string $ownerName = null;
    public ?string $bookingConfirmationSubject = null;
    public ?string $reminderEmailSubject = null;
    public ?string $cancellationEmailSubject = null;
    public bool $emailRemindersEnabled = true;
    public int $emailReminderHoursBefore = 24;
    public bool $sendCancellationEmail = true;

    // Payments
    public const PAYMENT_MODE_NONE = 'none';
    public const PAYMENT_MODE_DIRECT = 'direct';

    /**
     * Active payment path: `none` (free bookings) or `direct` (native Stripe
     * gateway). Null means "not set" and resolves to `none`.
     */
    public ?string $paymentMode = null;

    // Stripe (direct payment gateway). Store secrets as $ENV_VAR references.
    public ?string $stripePublishableKey = null;
    public ?string $stripeSecretKey = null;
    public ?string $stripeWebhookSecret = null;

    // Minutes a direct-payment booking may stay `pending` before it's garbage-
    // collected (its reservation cancelled, releasing capacity). Abandoned Stripe
    // Elements checkouts are freed on this cadence. See MaintenanceService.
    public int $pendingPaymentTtlMinutes = 30;


    public string $mutexDriver = 'auto';

    // Frontend
    public ?int $defaultTimeSlotLength = null;
    public ?string $bookingPageUrl = null;

    public function getEffectiveEmail(): ?string
    {
        if ($this->ownerEmail) {
            return $this->ownerEmail;
        }
        $email = Craft::$app->getProjectConfig()->get('email.fromEmail');
        return $email ? App::parseEnv($email) : (Craft::$app->getMailer()->fromEmail ?? null);
    }

    public function getEffectiveName(): ?string
    {
        if ($this->ownerName) {
            return $this->ownerName;
        }
        $name = Craft::$app->getProjectConfig()->get('email.fromName');
        return $name ? App::parseEnv($name) : (Craft::$app->getMailer()->fromName ?? null);
    }

    public function getEffectiveOwnerNotificationSubject(): string
    {
        return $this->ownerNotificationSubject ?: Craft::t('slots', 'emails.subject.ownerNotification');
    }

    public function getOwnerNotificationLanguageCode(): string
    {
        return $this->ownerNotificationLanguage ?: Craft::$app->getSites()->getPrimarySite()->language;
    }

    public function getEffectiveBookingConfirmationSubject(): string
    {
        return $this->bookingConfirmationSubject ?: Craft::t('slots', 'settings.attributeLabels.bookingConfirmation');
    }

    public function getEffectiveReminderEmailSubject(): string
    {
        return $this->reminderEmailSubject ?: Craft::t('slots', 'settings.attributeLabels.appointmentReminder');
    }

    public function getEffectiveCancellationEmailSubject(): string
    {
        return $this->cancellationEmailSubject ?: Craft::t('slots', 'settings.attributeLabels.bookingCancelled');
    }

    public function setAttributes($values, $safeOnly = true): void
    {
        if (isset($values['defaultRefundTiers'])) {
            $values['defaultRefundTiers'] = $this->normalizeRefundTiers($values['defaultRefundTiers']);
        }

        parent::setAttributes($values, $safeOnly);
    }

    private function normalizeRefundTiers(mixed $param): ?array
    {
        if (empty($param)) {
            return null;
        }

        if (is_string($param)) {
            $param = json_decode($param, true);
        }

        if (!is_array($param)) {
            return null;
        }

        $tiers = [];
        foreach (array_values($param) as $row) {
            if (!is_array($row) || !isset($row['hoursBeforeStart'], $row['refundPercentage'])) {
                continue;
            }

            $tiers[] = [
                'hoursBeforeStart' => (int) $row['hoursBeforeStart'],
                'refundPercentage' => (int) $row['refundPercentage'],
            ];
        }

        return empty($tiers) ? null : $tiers;
    }

    public function rules(): array
    {
        return [
            [['defaultCurrency'], 'string', 'max' => 4],
            [['defaultCurrency'], 'match', 'pattern' => '/^(auto|[A-Z]{3})$/', 'message' => Craft::t('slots', 'settings.attributeLabels.currencyValidation'), 'skipOnEmpty' => true],
            [['softLockDurationMinutes', 'softLockMaxLifetimeMinutes', 'rateLimitPerEmail', 'rateLimitPerIp'], 'integer', 'min' => 1],
            [['minimumAdvanceBookingHours', 'maximumAdvanceBookingDays', 'cancellationPolicyHours'], 'integer', 'min' => 0],
            [['softLockDurationMinutes'], 'default', 'value' => 5],
            [['softLockMaxLifetimeMinutes'], 'default', 'value' => 30],
            [['minimumAdvanceBookingHours'], 'default', 'value' => 0],
            [['maximumAdvanceBookingDays'], 'default', 'value' => 90],
            [['rateLimitPerEmail'], 'default', 'value' => 5],
            [['rateLimitPerIp'], 'default', 'value' => 10],
            [['enableRateLimiting'], 'boolean'],
            [['enableCaptcha', 'enableHoneypot', 'enableIpBlocking', 'enableTimeBasedLimits', 'enableAuditLog'], 'boolean'],
            [['captchaProvider'], 'string'],
            [['captchaProvider'], 'required', 'when' => fn(self $model) => $model->enableCaptcha, 'message' => Craft::t('slots', 'A CAPTCHA provider is required when CAPTCHA is enabled.')],
            [['captchaProvider'], 'validateCaptchaKeys'],
            [['recaptchaSiteKey', 'recaptchaSecretKey', 'hcaptchaSiteKey', 'hcaptchaSecretKey', 'turnstileSiteKey', 'turnstileSecretKey'], 'string'],
            [['recaptchaScoreThreshold'], 'number', 'min' => 0, 'max' => 1],
            [['recaptchaAction'], 'string', 'max' => 100],
            [['honeypotFieldName'], 'string'],
            [['blockedIps'], 'string'],
            [['minimumSubmissionTime'], 'integer', 'min' => 0],
            [['ownerNotificationEnabled', 'emailRemindersEnabled', 'sendCancellationEmail'], 'boolean'],
            [['emailReminderHoursBefore'], 'integer', 'min' => 0],
            [['ownerEmail'], 'email', 'skipOnEmpty' => true],
            [['ownerName', 'ownerNotificationSubject', 'ownerNotificationLanguage', 'bookingConfirmationSubject', 'reminderEmailSubject', 'cancellationEmailSubject'], 'string', 'skipOnEmpty' => true],
            [['paymentMode'], 'in', 'range' => [self::PAYMENT_MODE_NONE, self::PAYMENT_MODE_DIRECT], 'skipOnEmpty' => true],
            [['stripePublishableKey', 'stripeSecretKey', 'stripeWebhookSecret'], 'string'],
            [['pendingPaymentTtlMinutes'], 'integer', 'min' => 5, 'max' => 1440],
            [['enableAutoRefund'], 'boolean'],
            [['defaultRefundTiers'], 'safe'],
            [['defaultRefundTiers'], RefundTiersValidator::class],
            [['defaultTimeSlotLength'], 'integer', 'min' => 5],
            [['bookingPageUrl'], 'url', 'skipOnEmpty' => true],
            [['mutexDriver'], 'in', 'range' => ['auto', 'file', 'db', 'redis']],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'defaultCurrency' => Craft::t('slots', 'settings.attributeLabels.defaultCurrency'),
            'softLockDurationMinutes' => Craft::t('slots', 'settings.attributeLabels.softLockDuration'),
            'enableRateLimiting' => Craft::t('slots', 'settings.attributeLabels.enableRateLimiting'),
            'rateLimitPerEmail' => Craft::t('slots', 'settings.attributeLabels.rateLimitEmail'),
            'rateLimitPerIp' => Craft::t('slots', 'settings.attributeLabels.rateLimitIp'),
            'ownerNotificationEnabled' => Craft::t('slots', 'settings.attributeLabels.enableOwnerNotifications'),
            'ownerNotificationSubject' => Craft::t('slots', 'settings.attributeLabels.ownerNotificationSubject'),
            'ownerNotificationLanguage' => Craft::t('slots', 'settings.attributeLabels.ownerNotificationLanguage'),
            'ownerEmail' => Craft::t('slots', 'settings.attributeLabels.ownerEmail'),
            'ownerName' => Craft::t('slots', 'settings.attributeLabels.ownerName'),
            'bookingConfirmationSubject' => Craft::t('slots', 'settings.attributeLabels.confirmationSubject'),
            'emailRemindersEnabled' => Craft::t('slots', 'settings.attributeLabels.enableReminders'),
            'emailReminderHoursBefore' => Craft::t('slots', 'settings.attributeLabels.reminderHoursBefore'),
            'defaultTimeSlotLength' => Craft::t('slots', 'settings.attributeLabels.defaultTimeSlotLength'),
            'enableCaptcha' => Craft::t('slots', 'settings.attributeLabels.enableCaptcha'),
            'captchaProvider' => Craft::t('slots', 'settings.attributeLabels.captchaProvider'),
            'recaptchaSiteKey' => Craft::t('slots', 'settings.attributeLabels.recaptchaSiteKey'),
            'recaptchaSecretKey' => Craft::t('slots', 'settings.attributeLabels.recaptchaSecretKey'),
            'hcaptchaSiteKey' => Craft::t('slots', 'settings.attributeLabels.hcaptchaSiteKey'),
            'hcaptchaSecretKey' => Craft::t('slots', 'settings.attributeLabels.hcaptchaSecretKey'),
            'turnstileSiteKey' => Craft::t('slots', 'settings.attributeLabels.turnstileSiteKey'),
            'turnstileSecretKey' => Craft::t('slots', 'settings.attributeLabels.turnstileSecretKey'),
            'enableHoneypot' => Craft::t('slots', 'settings.attributeLabels.enableHoneypot'),
            'honeypotFieldName' => Craft::t('slots', 'settings.attributeLabels.honeypotFieldName'),
            'enableIpBlocking' => Craft::t('slots', 'settings.attributeLabels.enableIpBlocking'),
            'blockedIps' => Craft::t('slots', 'settings.attributeLabels.blockedIps'),
            'enableTimeBasedLimits' => Craft::t('slots', 'settings.attributeLabels.enableTimeLimits'),
            'minimumSubmissionTime' => Craft::t('slots', 'settings.attributeLabels.minSubmissionTime'),
            'enableAuditLog' => Craft::t('slots', 'settings.attributeLabels.enableAuditLog'),
            'mutexDriver' => Craft::t('slots', 'settings.attributeLabels.mutexDriver'),
        ];
    }

    public function safeAttributesForTab(string $tab): array
    {
        $map = [
            'booking' => [
                'softLockDurationMinutes', 'defaultTimeSlotLength',
                'minimumAdvanceBookingHours', 'maximumAdvanceBookingDays', 'cancellationPolicyHours',
                'bookingPageUrl', 'mutexDriver',
            ],
            'security' => [
                'enableCaptcha', 'captchaProvider', 'recaptchaSiteKey', 'recaptchaSecretKey',
                'recaptchaScoreThreshold', 'recaptchaAction',
                'hcaptchaSiteKey', 'hcaptchaSecretKey', 'turnstileSiteKey', 'turnstileSecretKey',
                'enableRateLimiting', 'rateLimitPerEmail', 'rateLimitPerIp',
                'enableHoneypot', 'honeypotFieldName',
                'enableIpBlocking', 'blockedIps', 'enableTimeBasedLimits', 'minimumSubmissionTime',
                'enableAuditLog',
            ],
            'notifications' => [
                'ownerNotificationEnabled', 'ownerEmail', 'ownerName', 'ownerNotificationSubject',
                'ownerNotificationLanguage', 'bookingConfirmationSubject', 'emailRemindersEnabled',
                'reminderEmailSubject', 'emailReminderHoursBefore', 'sendCancellationEmail',
                'cancellationEmailSubject',
            ],
            'payments' => [
                'paymentMode', 'stripePublishableKey', 'stripeSecretKey', 'stripeWebhookSecret',
                'pendingPaymentTtlMinutes', 'defaultCurrency',
            ],
        ];

        if (!isset($map[$tab])) {
            \Craft::warning("Unknown settings tab '{$tab}', rejecting.", __METHOD__);
            return [];
        }

        return $map[$tab];
    }

    public function validateCaptchaKeys(string $attribute): void
    {
        if (!$this->enableCaptcha || empty($this->captchaProvider)) {
            return;
        }

        $keyMap = [
            'recaptcha' => ['recaptchaSiteKey', 'recaptchaSecretKey'],
            'hcaptcha' => ['hcaptchaSiteKey', 'hcaptchaSecretKey'],
            'turnstile' => ['turnstileSiteKey', 'turnstileSecretKey'],
        ];

        $keys = $keyMap[$this->captchaProvider] ?? null;
        if (!$keys) {
            return;
        }

        [$siteKey, $secretKey] = $keys;
        if (empty($this->$siteKey) || empty($this->$secretKey)) {
            $this->addError($attribute, Craft::t('slots', 'Both site key and secret key are required for {provider}.', [
                'provider' => $this->captchaProvider,
            ]));
        }
    }

    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }

        $record = SettingsRecord::find()->one() ?? new SettingsRecord();

        foreach ($this->getAttributes() as $attribute => $value) {
            if (property_exists($record, $attribute) || $record->hasAttribute($attribute)) {
                if ($attribute === 'defaultRefundTiers' && is_array($value)) {
                    $record->$attribute = json_encode($value);
                } elseif (in_array($attribute, ['ownerEmail', 'ownerName']) && $value === '') {
                    $record->$attribute = null;
                } else {
                    $record->$attribute = $value;
                }
            }
        }

        if ($record->save()) {
            $this->id = $record->id;
            self::clearCache();
            return true;
        }

        $this->addErrors($record->getErrors());
        return false;
    }

    public static function loadSettings(): self
    {
        if (self::$cached !== null && self::$cachedAt !== null
            && (microtime(true) - self::$cachedAt) < self::CACHE_TTL) {
            return self::$cached;
        }

        $model = new self();

        try {
            if (!Craft::$app->getDb()->tableExists(SettingsRecord::tableName())) {
                return $model;
            }
        } catch (\Throwable) {
            return $model;
        }

        $record = SettingsRecord::find()->one();
        if ($record) {
            foreach ($model->getAttributes() as $attribute => $value) {
                if ($record->hasAttribute($attribute) || property_exists($record, $attribute)) {
                    $model->$attribute = $record->$attribute;
                }
            }
            $model->id = $record->id;

            $rawTiers = $record->defaultRefundTiers ?? null;
            if (is_string($rawTiers)) {
                $model->defaultRefundTiers = json_decode($rawTiers, true);
            }
        }

        self::$cached = $model;
        self::$cachedAt = microtime(true);

        return $model;
    }

    public static function clearCache(): void
    {
        self::$cached = null;
        self::$cachedAt = null;
    }

    /** Resolve the active payment mode, defaulting to `none`. */
    public function getPaymentMode(): string
    {
        $valid = [self::PAYMENT_MODE_NONE, self::PAYMENT_MODE_DIRECT];
        return in_array($this->paymentMode, $valid, true) ? $this->paymentMode : self::PAYMENT_MODE_NONE;
    }

    /** Whether the native direct payment path is active. */
    public function isDirectPayment(): bool
    {
        return $this->getPaymentMode() === self::PAYMENT_MODE_DIRECT;
    }
}
