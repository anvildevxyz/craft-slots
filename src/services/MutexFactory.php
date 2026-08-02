<?php

namespace anvildev\slots\services;

use anvildev\slots\Slots;
use Craft;
use craft\base\Component;
use yii\mutex\Mutex;

/**
 * Creates the appropriate mutex driver for booking locks.
 *
 * FileMutex (Craft's default) doesn't work in multi-server deployments.
 * This factory provides configurable mutex drivers with auto-detection:
 * - `auto` (default): tries Redis, then falls back to Craft's default
 * - `file`: uses Craft's built-in FileMutex
 * - `db`: uses MySQL GET_LOCK via MysqlMutex
 * - `redis`: uses yii2-redis Mutex (requires yiisoft/yii2-redis)
 */
class MutexFactory extends Component
{
    private ?Mutex $mutex = null;

    /**
     * Returns the configured mutex instance.
     *
     * The instance is cached for the lifetime of the request to prevent
     * creating duplicate mutexes for the same key within a single request.
     */
    public function get(): Mutex
    {
        if ($this->mutex !== null) {
            return $this->mutex;
        }

        $settings = Slots::getInstance()->getSettings();
        $driver = $settings->mutexDriver ?? 'auto';

        $this->mutex = match ($driver) {
            'db' => $this->createDbMutex(),
            'redis' => $this->createRedisMutex(),
            'file' => Craft::$app->getMutex(),
            'auto' => $this->autoDetect(),
            default => $this->unknownDriverFallback($driver),
        };

        return $this->mutex;
    }

    /**
     * Returns the list of supported driver identifiers.
     */
    public function getSupportedDrivers(): array
    {
        return ['auto', 'file', 'db', 'redis'];
    }

    private function unknownDriverFallback(string $driver): Mutex
    {
        Craft::warning("Unknown mutex driver '{$driver}', falling back to Craft's default", __METHOD__);

        return Craft::$app->getMutex();
    }

    /**
     * Auto-detect the best available mutex driver.
     * Prefers Redis when available, otherwise falls back to Craft's default.
     */
    private function autoDetect(): Mutex
    {
        if ($this->isRedisAvailable()) {
            return $this->createRedisMutex();
        }

        return Craft::$app->getMutex();
    }

    /**
     * Create a MySQL GET_LOCK-based mutex.
     */
    private function createDbMutex(): Mutex
    {
        try {
            $driverName = Craft::$app->getDb()->getDriverName();

            $mutexClass = match ($driverName) {
                'mysql' => \yii\mutex\MysqlMutex::class,
                'pgsql' => \yii\mutex\PgsqlMutex::class,
                default => \yii\mutex\FileMutex::class,
            };

            if ($mutexClass === \yii\mutex\FileMutex::class) {
                Craft::warning("Unsupported DB driver '{$driverName}' for DB mutex, falling back to Craft's configured mutex", __METHOD__);
                return Craft::$app->getMutex();
            }

            return new $mutexClass([
                'db' => Craft::$app->getDb(),
            ]);
        } catch (\Throwable $e) {
            Craft::warning("Failed to create DB mutex, falling back to file: " . $e->getMessage(), __METHOD__);

            return Craft::$app->getMutex();
        }
    }

    /**
     * Create a Redis-based mutex.
     */
    private function createRedisMutex(): Mutex
    {
        if (!$this->isRedisAvailable()) {
            Craft::warning('Redis mutex requested but yii2-redis not available, falling back to file', __METHOD__);

            return Craft::$app->getMutex();
        }

        try {
            return new \yii\redis\Mutex([
                'redis' => Craft::$app->get('redis'),
            ]);
        } catch (\Throwable $e) {
            Craft::warning("Failed to create Redis mutex, falling back to file: " . $e->getMessage(), __METHOD__);

            return Craft::$app->getMutex();
        }
    }

    /**
     * Check if Redis mutex support is available (class + component).
     */
    private function isRedisAvailable(): bool
    {
        return class_exists(\yii\redis\Mutex::class)
            && Craft::$app->has('redis');
    }
}
