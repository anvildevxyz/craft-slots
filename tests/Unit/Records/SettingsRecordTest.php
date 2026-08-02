<?php

namespace anvildev\slots\tests\Unit\Records;

use anvildev\slots\records\SettingsRecord;
use anvildev\slots\tests\Support\TestCase;

class SettingsRecordTest extends TestCase
{
    public function testEncryptedFieldsCount(): void
    {
        $this->assertCount(3, SettingsRecord::ENCRYPTED_FIELDS);
    }
}
