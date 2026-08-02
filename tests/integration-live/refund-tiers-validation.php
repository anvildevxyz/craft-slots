<?php

/**
 * Proves RefundTiersValidator is actually wired into Service and Settings.
 *
 * Unit tests can only assert the rule is declared: instantiating either model,
 * and the Craft::t() call in the failure message, both need a booted Craft app.
 * This runs against a live install so the rule is exercised for real, and it
 * re-validates every existing service to prove the new rule does not
 * retroactively reject data that is already saved.
 *
 * Usage (from the project root):
 *   ddev exec php plugins/slots/tests/integration-live/refund-tiers-validation.php
 *
 * Exits non-zero if any expectation fails.
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\slots\elements\Service;
use anvildev\slots\models\Settings;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, $detail !== '' ? " — {$detail}" : '');
}

function service(mixed $tiers): Service
{
    $s = new Service();
    $s->title = 'refund-tier probe';
    $s->duration = 60;
    $s->refundTiers = $tiers;
    $s->validate();
    return $s;
}

echo "Service::refundTiers\n";

$s = service([['hoursBeforeStart' => 24, 'refundPercentage' => 150]]);
check('percentage above 100 is rejected', $s->getErrors('refundTiers') !== [], json_encode($s->getErrors('refundTiers')));

$s = service([['hoursBeforeStart' => -5, 'refundPercentage' => 50]]);
check('negative hoursBeforeStart is rejected', $s->getErrors('refundTiers') !== []);

$s = service([['hoursBeforeStart' => 48, 'refundPercentage' => 100], ['hoursBeforeStart' => 24, 'refundPercentage' => 50]]);
check('a valid two-tier policy still saves', $s->getErrors('refundTiers') === [], json_encode($s->getErrors('refundTiers')));

$s = service(json_encode([['hoursBeforeStart' => 24, 'refundPercentage' => 50]]));
check('valid tiers as a JSON string still save', $s->getErrors('refundTiers') === [], json_encode($s->getErrors('refundTiers')));

$s = service(null);
check('no tiers at all still saves', $s->getErrors('refundTiers') === []);

echo "\nSettings::defaultRefundTiers\n";

$set = new Settings();
$set->defaultRefundTiers = [['hoursBeforeStart' => 24, 'refundPercentage' => 500]];
$set->validate();
check('percentage above 100 is rejected', $set->getErrors('defaultRefundTiers') !== []);

$set = new Settings();
$set->defaultRefundTiers = [['hoursBeforeStart' => 24, 'refundPercentage' => 50]];
$set->validate();
check('a valid tier still saves', $set->getErrors('defaultRefundTiers') === [], json_encode($set->getErrors('defaultRefundTiers')));

echo "\nExisting data is not retroactively rejected\n";

$checked = 0;
$broken = [];
foreach (Service::find()->siteId('*')->all() as $existing) {
    $checked++;
    $existing->validate(['refundTiers']);
    if ($existing->getErrors('refundTiers') !== []) {
        $broken[] = $existing->id . ':' . json_encode($existing->refundTiers);
    }
}
check(sprintf('%d existing service(s) still validate', $checked), $broken === [], implode(', ', $broken));

printf("\n%s\n", $failures === 0 ? 'All checks passed.' : "{$failures} check(s) FAILED.");
exit($failures === 0 ? 0 : 1);
