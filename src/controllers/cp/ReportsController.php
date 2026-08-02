<?php

namespace anvildev\slots\controllers\cp;

use anvildev\slots\helpers\CsvHelper;
use anvildev\slots\Slots;
use Craft;
use craft\web\Controller;
use craft\web\Response;

class ReportsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('slots-viewReports');
        return true;
    }

    public function actionIndex(): mixed
    {
        return $this->renderTemplate('slots/reports/index');
    }

    public function actionRevenue(): mixed
    {
        $request = Craft::$app->request;
        $startDate = self::normalizeDateParam($request->getParam('startDate'), date('Y-m-01'));
        $endDate = self::normalizeDateParam($request->getParam('endDate'), date('Y-m-t'));

        $reports = Slots::getInstance()->getReports();
        $data = $reports->getRevenueData($startDate, $endDate, true);

        if ($request->getParam('format') === 'csv') {
            return $this->sendCsvResponse(
                ['Date', 'Customer', 'Service / Event', 'Time', 'Revenue', 'Status'],
                array_map(fn($r) => [
                    $r->bookingDate,
                    CsvHelper::sanitizeValue($r->userEmail ?? ''),
                    CsvHelper::sanitizeValue($r->getService()?->title ?? ''),
                    ($r->startTime ?? '') . ' - ' . ($r->endTime ?? ''),
                    number_format($r->getTotalPrice(), 2),
                    $r->status,
                ], $data['reservations']),
                'revenue-report', $startDate, $endDate,
            );
        }

        return $this->renderTemplate('slots/reports/revenue', [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'totalRevenue' => $data['total'],
            'previousTotal' => $data['previousTotal'],
            'changePercent' => $data['changePercent'],
            'reservations' => $data['reservations'],
            'currency' => $reports->getCurrency(),
        ]);
    }

    private function sendCsvResponse(array $headers, array $rows, string $name, ?string $startDate = null, ?string $endDate = null): Response
    {
        $output = fopen('php://temp', 'r+');
        fwrite($output, "\xEF\xBB\xBF");
        if ($startDate && $endDate) {
            fputcsv($output, ['Date Range', $startDate . ' to ' . $endDate]);
            fputcsv($output, []);
        }
        fputcsv($output, $headers);
        foreach ($rows as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $this->response->sendContentAsFile($csv, $name . '-' . date('Y-m-d') . '.csv', [
            'mimeType' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Normalize a date parameter from Craft's dateField (which sends {date, locale, timezone})
     * into a Y-m-d string.
     */
    private static function normalizeDateParam(mixed $value, ?string $default = null): ?string
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_array($value)) {
            $value = $value['date'] ?? null;
            if ($value === null || $value === '') {
                return $default;
            }
        }

        $parsed = date_create((string) $value);
        return $parsed ? $parsed->format('Y-m-d') : $default;
    }
}
