<?php

namespace anvildev\slots\elements\exporters;

use anvildev\slots\helpers\CsvHelper;
use Craft;
use craft\base\ElementExporter;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;

class ReservationCsvExporter extends ElementExporter
{
    public static function displayName(): string
    {
        return Craft::t('slots', 'export.reservations');
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
            'ID', 'Name', 'Email', 'Phone',
            'Service', 'Employee', 'Location',
            'Date', 'Start Time', 'End Time', 'Duration (min)',
            'Status', 'Quantity', 'Price',
            'Notes', 'Created',
        ]);

        assert($query instanceof \anvildev\slots\elements\db\ReservationQuery);
        foreach (Db::each($query->withRelations()) as $r) {
            /** @var \anvildev\slots\elements\Reservation $r */
            fputcsv($handle, [
                (string) $r->id,
                CsvHelper::sanitizeValue($r->userName ?? ''),
                CsvHelper::sanitizeValue($r->userEmail ?? ''),
                CsvHelper::sanitizeValue($r->userPhone ?? ''),
                CsvHelper::sanitizeValue($r->getService()?->title ?? ''),
                CsvHelper::sanitizeValue($r->getEmployee()?->title ?? ''),
                CsvHelper::sanitizeValue($r->getLocation()?->title ?? ''),
                (string) ($r->bookingDate ?? ''),
                (string) ($r->startTime ?? ''),
                (string) ($r->endTime ?? ''),
                (string) $r->getDurationMinutes(),
                (string) $r->getStatusLabel(),
                (string) $r->quantity,
                number_format($r->getTotalPrice(), 2, '.', ''),
                CsvHelper::sanitizeValue($r->notes ?? ''),
                $r->dateCreated ? $r->dateCreated->format('Y-m-d H:i:s') : '',
            ]);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    public function getFilename(): string
    {
        return 'bookings-' . date('Y-m-d') . '.csv';
    }
}
