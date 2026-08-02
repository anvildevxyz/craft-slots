<?php

namespace anvildev\slots\console\controllers;

use anvildev\slots\queue\jobs\SendRemindersJob;
use anvildev\slots\Slots;
use Craft;
use craft\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

class RemindersController extends Controller
{
    public function actionSend(): int
    {
        $this->stdout("Checking for pending reminders...\n");

        try {
            $sentCount = Slots::getInstance()->getReminder()->sendReminders();
            $this->stdout(
                $sentCount > 0 ? "✓ Sent {$sentCount} reminder(s)\n" : "✓ No reminders to send\n",
                $sentCount > 0 ? Console::FG_GREEN : Console::FG_YELLOW,
            );
            return ExitCode::OK;
        } catch (\Throwable $e) {
            $this->stderr("✗ Failed to send reminders: {$e->getMessage()}\n", Console::FG_RED);
            Craft::error("Failed to send reminders: " . $e->getMessage(), __METHOD__);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    public function actionQueue(): int
    {
        $this->stdout("Queuing reminder job...\n");

        try {
            Craft::$app->queue->push(new SendRemindersJob());
            $this->stdout("✓ Reminder job queued successfully\n", Console::FG_GREEN);
            $this->stdout("Run 'php craft queue/run' to process the queue\n", Console::FG_YELLOW);
            return ExitCode::OK;
        } catch (\Throwable $e) {
            $this->stderr("✗ Failed to queue reminder job: {$e->getMessage()}\n", Console::FG_RED);
            Craft::error("Failed to queue reminder job: " . $e->getMessage(), __METHOD__);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }
}
