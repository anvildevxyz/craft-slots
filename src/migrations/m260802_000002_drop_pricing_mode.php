<?php

namespace anvildev\slots\migrations;

use craft\db\Migration;

/**
 * Drops `pricingMode` from services.
 *
 * It offered a choice between a flat price and a price per unit, where the unit
 * was a day — which only meant anything alongside multi-day stays. Those went
 * with the strip-down and nothing was left to multiply by, so `getTotalPrice()`
 * has always ignored the setting: choosing "per day" changed the field's label
 * and nothing else.
 */
class m260802_000002_drop_pricing_mode extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->columnExists('{{%slots_services}}', 'pricingMode')) {
            $this->dropColumn('{{%slots_services}}', 'pricingMode');
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m260802_000002_drop_pricing_mode cannot be reverted: the column carried no behaviour to restore.\n";
        return false;
    }
}
