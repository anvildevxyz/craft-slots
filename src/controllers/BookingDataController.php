<?php

namespace anvildev\slots\controllers;

use anvildev\slots\controllers\traits\BookingHelpersTrait;
use anvildev\slots\controllers\traits\JsonResponseTrait;
use anvildev\slots\elements\Employee;
use anvildev\slots\elements\Location;
use anvildev\slots\elements\Service;
use anvildev\slots\helpers\ElementQueryHelper;
use anvildev\slots\helpers\SiteHelper;
use anvildev\slots\Slots;
use Craft;
use craft\web\Controller;
use craft\web\Response;

/**
 * AJAX endpoints for booking-related reference data (services, employees, payment settings).
 */
class BookingDataController extends Controller
{
    use JsonResponseTrait;
    use BookingHelpersTrait;

    protected array|bool|int $allowAnonymous = [
        'get-services',
        'get-employees',
        'get-payment-settings',
    ];

    public $enableCsrfValidation = true;

    public function init(): void
    {
        parent::init();
        $this->closeSession();
    }

    public function actionGetServices(): Response
    {
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('slots_data_throttle', 60)) {
            return $this->jsonError(Craft::t('slots', 'booking.rateLimitIP'), statusCode: 429);
        }

        $site = SiteHelper::getSiteForRequest(Craft::$app->getRequest());

        /** @var Service[] $services */
        $services = ElementQueryHelper::forSite(
            Service::find()->enabled()->unique(),
            $site->id
        )->all();

        if (empty($services)) {
            return $this->jsonSuccess('', ['services' => []]);
        }

        $serviceIds = array_map(fn($s) => $s->id, $services);

        // Batch query: service → location IDs
        $serviceLocationMap = Slots::getInstance()->serviceLocation->getLocationIdMapForServices($serviceIds);

        return $this->jsonSuccess('', [
            'services' => array_map(fn(Service $service) => [
                'id' => $service->id,
                'title' => $service->title,
                'description' => $service->description ?? '',
                'duration' => $service->duration,
                'price' => $service->price,
                'bufferBefore' => $service->bufferBefore,
                'bufferAfter' => $service->bufferAfter,
                'locationIds' => $serviceLocationMap[$service->id] ?? [],
            ], $services),
        ]);
    }

    public function actionGetEmployees(): Response
    {
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('slots_data_throttle', 60)) {
            return $this->jsonError(Craft::t('slots', 'booking.rateLimitIP'), statusCode: 429);
        }

        $locationId = Craft::$app->request->getParam('locationId');
        $serviceId = Craft::$app->request->getParam('serviceId');

        // Check if service has its own availability schedule (employee-less booking)
        $serviceHasSchedule = false;
        if ($serviceId) {
            $service = ElementQueryHelper::forAllSites(
                Service::find()->id((int)$serviceId)->status(null)
            )->one();
            $serviceHasSchedule = $service?->hasAvailabilitySchedule() ?? false;
        }

        $query = Employee::find()->siteId('*')->enabled(true);
        if ($locationId) {
            $query->locationId((int)$locationId);
        }
        if ($serviceId) {
            $query->serviceId((int)$serviceId);
        }
        $employees = $query->all();

        // Extract unique locations from matching employees + direct service-location assignments
        $locationIds = array_unique(array_filter(array_map(fn($e) => $e->locationId, $employees)));

        // Merge in direct service-location assignments (for employee-less services)
        if ($serviceId) {
            $directLocationIds = Slots::getInstance()->serviceLocation
                ->getLocationIdMapForServices([(int) $serviceId])[(int) $serviceId] ?? [];
            $locationIds = array_unique(array_merge($locationIds, $directLocationIds));
        }

        $locations = [];
        if (!empty($locationIds)) {
            foreach (Location::find()->siteId('*')->id($locationIds)->all() as $location) {
                $locations[] = [
                    'id' => $location->id,
                    'name' => $location->title,
                    'address' => implode(', ', array_filter([
                        $location->addressLine1, $location->addressLine2,
                        $location->locality, $location->administrativeArea,
                        $location->postalCode, $location->countryCode,
                    ])),
                    'timezone' => $location->timezone,
                ];
            }
        }

        // Check if any employee has schedules (single batch query)
        $hasSchedules = $serviceHasSchedule;
        if (!$hasSchedules && !empty($employees)) {
            $hasSchedules = !empty(
                (new \craft\db\Query())
                    ->select(['employeeId'])
                    ->distinct()
                    ->from('{{%slots_employee_schedule_assignments}}')
                    ->where(['employeeId' => array_map(fn($e) => $e->id, $employees)])
                    ->column()
            );
        }

        return $this->jsonSuccess('', [
            'employees' => array_map(fn($e) => ['id' => $e->id, 'name' => $e->title], $employees),
            'employeeRequired' => count($employees) === 1 && !$serviceHasSchedule,
            'hasSchedules' => $hasSchedules,
            'serviceHasSchedule' => $serviceHasSchedule,
            'locations' => $locations,
        ]);
    }

    public function actionGetPaymentSettings(): Response
    {
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('slots_data_throttle', 60)) {
            return $this->jsonError(Craft::t('slots', 'booking.rateLimitIP'), statusCode: 429);
        }

        $settings = Slots::getInstance()->getSettings();
        $currency = Slots::getInstance()->reports->getCurrency();

        return $this->jsonSuccess('', [
            'paymentEnabled' => $settings->isDirectPayment(),
            'currency' => $currency,
            'currencySymbol' => $currency,
        ]);
    }
}
