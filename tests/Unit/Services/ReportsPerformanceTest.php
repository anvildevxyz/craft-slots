<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\tests\Support\TestCase;

class ReportsPerformanceTest extends TestCase
{
    private function readSource(): string
    {
        return file_get_contents(dirname(__DIR__, 3) . '/src/services/ReportsService.php');
    }

    public function testCancellationDataDoesNotRunFourQueries(): void
    {
        $source = $this->readSource();
        $pos = strpos($source, 'function computeCancellationData');
        // Extract only the method body by finding the next "function " or end of class
        $nextMethod = strpos($source, "\n    public function ", $pos + 1);
        if ($nextMethod === false) {
            $nextMethod = strpos($source, "\n    private function ", $pos + 1);
        }
        $methodBody = substr($source, $pos, $nextMethod ? $nextMethod - $pos : 3000);

        $count = substr_count($methodBody, 'buildReservationQuery');
        $this->assertLessThanOrEqual(
            2,
            $count,
            "computeCancellationData calls buildReservationQuery {$count} times, should be <= 2"
        );
    }

    public function testGroupedDataBatchLoadsElements(): void
    {
        $source = $this->readSource();
        $pos = strpos($source, 'function computeGroupedData');
        $methodBody = substr($source, $pos, 2000);

        $this->assertStringNotContainsString(
            '$elementLoader($id)',
            $methodBody,
            'computeGroupedData must batch-load elements, not load one at a time'
        );
    }
}
