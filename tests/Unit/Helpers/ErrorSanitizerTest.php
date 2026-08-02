<?php

namespace anvildev\slots\tests\Unit\Helpers;

use anvildev\slots\helpers\ErrorSanitizer;
use anvildev\slots\tests\Support\TestCase;

class ErrorSanitizerTest extends TestCase
{
    public function testPassesThroughSafeMessages(): void
    {
        $this->assertSame('Something went wrong', ErrorSanitizer::sanitize('Something went wrong'));
    }

    public function testSanitizesSqlState(): void
    {
        $this->assertSame('An internal error occurred.', ErrorSanitizer::sanitize('SQLSTATE[23000]: Integrity constraint violation'));
    }

    public function testSanitizesPhpPaths(): void
    {
        $this->assertSame('An internal error occurred.', ErrorSanitizer::sanitize('Error in /var/www/vendor/yiisoft/yii2/db/Command.php on line 42'));
    }

    public function testSanitizesCraftTableReferences(): void
    {
        $this->assertSame('An internal error occurred.', ErrorSanitizer::sanitize('Error with {{%slots_reservations}} table'));
    }

    public function testSanitizesSqlKeywords(): void
    {
        $this->assertSame('An internal error occurred.', ErrorSanitizer::sanitize('SELECT * FROM users WHERE id = 1'));
        $this->assertSame('An internal error occurred.', ErrorSanitizer::sanitize('INSERT INTO slots_reservations'));
    }

    public function testSanitizesBacktickTableRef(): void
    {
        $this->assertSame('An internal error occurred.', ErrorSanitizer::sanitize('Error: `craft_booked`.`reservations` not found'));
    }
}
