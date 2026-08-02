<?php

namespace anvildev\slots\validators;

use Craft;
use yii\validators\Validator;

/**
 * Validates refund tier configurations.
 *
 * Each tier must be an array with:
 * - hoursBeforeStart: numeric >= 0 (hours before booking start time)
 * - refundPercentage: numeric 0-100 (percentage to refund)
 *
 * The normalizers that feed these attributes (see {@see \anvildev\slots\helpers\RefundTierHelper})
 * cast values to int but do not clamp them, so a percentage of 150 or -5 reaches
 * the model intact. Downstream refund maths clamps to the captured amount, so
 * such a tier cannot over-refund — it simply behaves as something other than what
 * was entered. This validator surfaces that at save time instead.
 */
class RefundTiersValidator extends Validator
{
    /**
     * Validate that tiers are correctly structured.
     *
     * Accepts null or empty array (no tiers defined).
     * Each tier must have hoursBeforeStart >= 0 and refundPercentage 0-100.
     */
    public function isValid(mixed $tiers): bool
    {
        if ($tiers === null || $tiers === []) {
            return true;
        }

        if (!is_array($tiers)) {
            return false;
        }

        foreach ($tiers as $tier) {
            if (!is_array($tier)) {
                return false;
            }

            if (!isset($tier['hoursBeforeStart'], $tier['refundPercentage'])) {
                return false;
            }

            if (!is_numeric($tier['hoursBeforeStart']) || $tier['hoursBeforeStart'] < 0) {
                return false;
            }

            if (!is_numeric($tier['refundPercentage']) || $tier['refundPercentage'] < 0 || $tier['refundPercentage'] > 100) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param \yii\base\Model $model
     * @param string $attribute
     */
    public function validateAttribute($model, $attribute): void
    {
        if (!$this->isValid($this->decode($model->$attribute))) {
            $this->addError($model, $attribute, Craft::t('slots', 'validation.refundTiersInvalid'));
        }
    }

    /**
     * Both tier attributes are declared `array|string|null` — the value is still a
     * JSON string on the way in from a form post and only becomes an array once
     * normalized. Decode first so a well-formed JSON payload is judged on its
     * contents rather than rejected for being a string.
     */
    private function decode(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $value;
    }
}
