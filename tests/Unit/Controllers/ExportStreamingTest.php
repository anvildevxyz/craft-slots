<?php

namespace anvildev\slots\tests\Unit\Controllers;

use anvildev\slots\tests\Support\TestCase;

class ExportStreamingTest extends TestCase
{
    public function testExportUsesEachInsteadOfAll(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/controllers/cp/BookingsController.php');
        $pos = strpos($source, 'function actionExport');
        $this->assertNotFalse($pos);
        $methodBody = substr($source, $pos, 3000);

        $this->assertStringNotContainsString(
            '$query->all()',
            $methodBody,
            'Export must not load all results into memory with ->all()'
        );

        $this->assertStringContainsString(
            'each(',
            $methodBody,
            'Export must use ->each() for batched streaming'
        );
    }
}
