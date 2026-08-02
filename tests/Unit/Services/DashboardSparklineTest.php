<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\tests\Support\TestCase;

class DashboardSparklineTest extends TestCase
{
    public function testSparklineUsesGroupByInsteadOfLoop(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/services/DashboardService.php');
        $pos = strpos($source, 'function getSparklineData');
        $this->assertNotFalse($pos);
        $methodBody = substr($source, $pos, 1500);

        // Should NOT have a for loop doing individual queries per day
        $this->assertStringNotContainsString(
            'for ($i =',
            $methodBody,
            'getSparklineData must not loop with individual queries per day'
        );

        // Should use GROUP BY for batching
        $this->assertStringContainsString(
            'groupBy',
            $methodBody,
            'getSparklineData must use GROUP BY to batch-query all days at once'
        );
    }
}
