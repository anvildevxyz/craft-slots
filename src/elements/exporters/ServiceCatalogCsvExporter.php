<?php

namespace anvildev\slots\elements\exporters;

use anvildev\slots\helpers\CsvHelper;
use Craft;
use craft\base\ElementExporter;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;

class ServiceCatalogCsvExporter extends ElementExporter
{
    public static function displayName(): string
    {
        return Craft::t('slots', 'export.serviceCatalog');
    }

    public static function isFormattable(): bool
    {
        return false;
    }

    public function export(ElementQueryInterface $query): mixed
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [
            'ID', 'Title', 'Description',
            'Duration (min)', 'Buffer Before (min)', 'Buffer After (min)',
            'Time Slot (min)', 'Price',
            'Min Advance (min)',
            'Cancellation Allowed', 'Cancellation Hours',
            'Status', 'Created',
        ]);

        foreach (Db::each($query) as $service) {
            /** @var \anvildev\slots\elements\Service $service */
            fputcsv($handle, [
                (string) $service->id,
                CsvHelper::sanitizeValue($service->title ?? ''),
                CsvHelper::sanitizeValue($service->description ?? ''),
                (string) ($service->duration ?? ''),
                (string) ($service->bufferBefore ?? 0),
                (string) ($service->bufferAfter ?? 0),
                (string) ($service->timeSlotLength ?? ''),
                $service->price !== null ? number_format($service->price, 2, '.', '') : '',
                (string) ($service->minTimeBeforeBooking ?? ''),
                $service->allowCancellation ? 'Yes' : 'No',
                (string) ($service->cancellationPolicyHours ?? ''),
                $service->enabled ? 'Enabled' : 'Disabled',
                $service->dateCreated ? $service->dateCreated->format('Y-m-d H:i:s') : '',
            ]);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    public function getFilename(): string
    {
        return 'service-catalog-' . date('Y-m-d') . '.csv';
    }
}
