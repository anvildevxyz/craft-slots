<?php

namespace anvildev\slots\console\controllers;

use anvildev\slots\models\Settings;
use anvildev\slots\records\PaymentRecord;
use anvildev\slots\Slots;
use craft\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Direct-payment maintenance commands.
 */
class PaymentsController extends Controller
{
    /** Report what would change without writing anything. */
    public bool $dryRun = false;

    /** Only reconcile payments created within the last N days (0 = all non-finalized). */
    public int $since = 7;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['dryRun', 'since']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['d' => 'dryRun']);
    }

    /**
     * Reconcile local payment records against the gateway — a safety net for
     * missed/dropped webhooks. Re-queries each non-finalized record; a payment the
     * gateway reports as paid is finalized through the SAME idempotent path as the
     * webhook (`handleVerifiedPayment`), so it never double-confirms. Safe to
     * re-run. Direct mode only.
     */
    public function actionReconcile(): int
    {
        $settings = Slots::getInstance()->getSettings();
        if ($settings->getPaymentMode() !== Settings::PAYMENT_MODE_DIRECT) {
            $this->stdout("Payment mode is not 'direct' — nothing to reconcile.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $query = PaymentRecord::find()->where(['not in', 'status', [
            PaymentRecord::STATUS_PAID,
            PaymentRecord::STATUS_REFUNDED,
            PaymentRecord::STATUS_PARTIALLY_REFUNDED,
            PaymentRecord::STATUS_FAILED,
        ]]);
        if ($this->since > 0) {
            $cutoff = (new \DateTime("-{$this->since} days"))->format('Y-m-d H:i:s');
            $query->andWhere(['>=', 'dateCreated', $cutoff]);
        }

        /** @var PaymentRecord[] $records */
        $records = $query->all();
        if (!$records) {
            $this->stdout("No non-finalized payments to reconcile.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $gateways = Slots::getInstance()->getPaymentGateways();
        $payments = Slots::getInstance()->getPayments();
        $reconciled = 0;
        $failed = 0;

        foreach ($records as $record) {
            $gateway = $gateways->getGateway((string) $record->gateway);
            if (!$gateway) {
                $this->stdout("  ? payment #{$record->id}: gateway '{$record->gateway}' unavailable — skipped\n", Console::FG_YELLOW);
                continue;
            }

            try {
                $result = $gateway->confirmPayment((string) $record->externalId);
            } catch (\Throwable $e) {
                $failed++;
                $this->stdout("  ✗ payment #{$record->id}: {$e->getMessage()}\n", Console::FG_RED);
                continue;
            }

            if ($result->paid) {
                if ($this->dryRun) {
                    $this->stdout("  ~ payment #{$record->id}: WOULD confirm (gateway reports paid)\n", Console::FG_CYAN);
                } else {
                    $payments->handleVerifiedPayment($record);
                    $this->stdout("  ✓ payment #{$record->id}: confirmed from gateway\n", Console::FG_GREEN);
                }
                $reconciled++;
            } else {
                $this->stdout("  · payment #{$record->id}: gateway status '{$result->status}' — no change\n");
            }
        }

        $verb = $this->dryRun ? 'would be reconciled' : 'reconciled';
        $this->stdout("\n{$reconciled} {$verb}, {$failed} failed, " . count($records) . " checked.\n", Console::FG_GREEN);

        return $failed > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
