<?php

namespace anvildev\slots\tests\Unit\Elements\Exporters;

use anvildev\slots\elements\exporters\ServiceCatalogCsvExporter;
use anvildev\slots\tests\Support\TestCase;

class ServiceCatalogCsvExporterTest extends TestCase
{
    public function testDisplayNameIsNotEmpty(): void
    {
        $this->requiresCraft();
        $this->assertNotEmpty(ServiceCatalogCsvExporter::displayName());
    }

    public function testIsNotFormattable(): void
    {
        $this->assertFalse(ServiceCatalogCsvExporter::isFormattable());
    }

    public function testFilenameIncludesDate(): void
    {
        $exporter = new ServiceCatalogCsvExporter();
        $filename = $exporter->getFilename();
        $this->assertStringContainsString('service-catalog-', $filename);
        $this->assertStringEndsWith('.csv', $filename);
    }
}
