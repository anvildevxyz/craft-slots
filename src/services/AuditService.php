<?php

namespace anvildev\slots\services;

use anvildev\slots\Slots;
use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;

/**
 * Writes security-relevant events to a dedicated JSON-lines log file.
 *
 * Each call appends one self-contained JSON object to @storage/logs/slots-audit.log.
 * File rotation is left to the sysadmin (logrotate, etc.).
 */
class AuditService extends Component
{
    public const ACTION_RESERVATION_CANCELLED = 'reservation_cancelled';

    public const ACTION_QUANTITY_CHANGED = 'quantity_changed';

    public const ACTION_STATUS_CHANGED = 'status_changed';

    public const ACTION_SETTINGS_CHANGED = 'settings_changed';

    public function log(string $category, string $action, array $context = []): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $context[$key] = mb_substr($value, 0, 1000);
            } elseif (is_array($value) || is_object($value)) {
                $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $context[$key] = mb_substr($encoded !== false ? $encoded : '[unserializable]', 0, 1000);
            }
        }

        $entry = [
            ...$context,
            'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
            'category' => $category,
            'action' => $action,
            'ip' => $this->getClientIp(),
            'userId' => $this->getCurrentUserId(),
            'userName' => $this->getCurrentUserName(),
        ];

        try {
            FileHelper::writeToFile(
                Craft::$app->getPath()->getLogPath() . DIRECTORY_SEPARATOR . 'slots-audit.log',
                json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                ['append' => true, 'lock' => true],
            );
        } catch (\Throwable $e) {
            Craft::warning("Failed to write audit log: {$e->getMessage()}", __METHOD__);
        }
    }

    public function logCancellation(int $reservationId, string $cancelledBy, string $reason, string $source): void
    {
        $this->log('cancellation', 'reservation_cancelled', compact('reservationId', 'cancelledBy', 'reason', 'source'));
    }

    public function logQuantityChange(int $reservationId, string $changedBy, string $description, string $direction): void
    {
        $this->log('quantity_change', 'quantity_changed', compact('reservationId', 'changedBy', 'description', 'direction'));
    }

    public function logStatusChange(int $reservationId, string $oldStatus, string $newStatus): void
    {
        $this->log('status', 'status_changed', compact('reservationId', 'oldStatus', 'newStatus'));
    }

    public function logAuthFailure(string $type, array $context = []): void
    {
        $this->log('auth', $type, $context);
    }

    public function logRateLimit(string $type, array $context = []): void
    {
        $this->log('security', $type, $context);
    }

    public function logSettingsChange(string $changedBy, array $changedFields): void
    {
        $this->log('settings', 'settings_changed', compact('changedBy', 'changedFields'));
    }

    private function isEnabled(): bool
    {
        try {
            return Slots::getInstance()->getSettings()->enableAuditLog;
        } catch (\Throwable) {
            return false;
        }
    }

    private function getClientIp(): ?string
    {
        try {
            return Craft::$app->getRequest()->getIsConsoleRequest() ? null : Craft::$app->getRequest()->getUserIP();
        } catch (\Throwable) {
            return null;
        }
    }

    private function getCurrentUserId(): ?int
    {
        try {
            return Craft::$app->getUser()->getId();
        } catch (\Throwable) {
            return null;
        }
    }

    private function getCurrentUserName(): ?string
    {
        try {
            return Craft::$app->getUser()->getIdentity()?->email;
        } catch (\Throwable) {
            return null;
        }
    }
}
