<?php

namespace anvildev\slots\helpers;

class RefundTierHelper
{
    public static function normalize(mixed $param): ?array
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
}
