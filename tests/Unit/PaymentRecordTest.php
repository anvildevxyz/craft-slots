<?php

namespace anvildev\slots\tests\Unit;

use anvildev\slots\records\PaymentRecord;
use anvildev\slots\tests\Support\TestCase;

class PaymentRecordTest extends TestCase
{
    public function testTableName(): void
    {
        $this->assertSame('{{%slots_payments}}', PaymentRecord::tableName());
    }

    public function testStatusConstants(): void
    {
        $this->assertSame('pending', PaymentRecord::STATUS_PENDING);
        $this->assertSame('paid', PaymentRecord::STATUS_PAID);
        $this->assertSame('refunded', PaymentRecord::STATUS_REFUNDED);
        $this->assertSame('partiallyRefunded', PaymentRecord::STATUS_PARTIALLY_REFUNDED);
    }
}
