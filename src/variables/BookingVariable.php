<?php

namespace anvildev\slots\variables;

use anvildev\slots\contracts\ReservationQueryInterface;
use anvildev\slots\elements\BlackoutDate;
use anvildev\slots\elements\db\BlackoutDateQuery;
use anvildev\slots\elements\db\EmployeeQuery;
use anvildev\slots\elements\db\LocationQuery;
use anvildev\slots\elements\db\ScheduleQuery;
use anvildev\slots\elements\db\ServiceQuery;
use anvildev\slots\elements\Employee;
use anvildev\slots\elements\Location;
use anvildev\slots\elements\Schedule;
use anvildev\slots\elements\Service;
use anvildev\slots\factories\ReservationFactory;
use anvildev\slots\helpers\ElementQueryHelper;
use anvildev\slots\models\Settings;
use anvildev\slots\Slots;
use Craft;
use craft\helpers\Template;
use Twig\Markup;

class BookingVariable
{
    public function getForm(array $options = []): Markup
    {
        $viewMode = $options['viewMode'] ?? Slots::getInstance()->getSettings()->defaultViewMode ?? 'wizard';
        $title = $options['title'] ?? '';
        $text = $options['text'] ?? '';
        $entry = $options['entry'] ?? null;

        $entryId = null;
        if ($entry) {
            $entryId = is_object($entry) && isset($entry->id) ? $entry->id : (is_numeric($entry) ? (int)$entry : null);
        }

        // 'legacy' was the Alpine wizard, which is gone. It used to fall through to
        // a booking-form template that included a component living in the site's
        // own templates — so asking for it, or for any view mode that does not
        // exist, threw a template-not-found rather than falling back.
        $template = 'slots/frontend/' . $viewMode;
        if (!Craft::$app->view->doesTemplateExist($template)) {
            $template = 'slots/frontend/wizard';
        }

        return Template::raw(Craft::$app->view->renderTemplate($template, compact('title', 'text', 'entryId', 'options')));
    }

    public function getWizard(array $options = []): Markup
    {
        $settings = Slots::getInstance()->getSettings();

        return Template::raw(Craft::$app->view->renderTemplate('slots/frontend/wizard', [
            'options' => $options,
            'honeypotFieldName' => $settings->enableHoneypot ? $settings->honeypotFieldName : null,
            'captchaEnabled' => $settings->enableCaptcha ?? false,
            'captchaProvider' => $settings->captchaProvider ?? null,
            'captchaSiteKey' => $settings->enableCaptcha ? $this->getCaptchaSiteKey($settings) : null,
            'captchaAction' => $settings->recaptchaAction ?? 'booking',
        ]));
    }

    protected function getCaptchaSiteKey($settings): ?string
    {
        return match ($settings->captchaProvider) {
            'recaptcha' => $settings->recaptchaSiteKey ?? null,
            'hcaptcha' => $settings->hcaptchaSiteKey ?? null,
            'turnstile' => $settings->turnstileSiteKey ?? null,
            default => null,
        };
    }

    public function services(): ServiceQuery
    {
        return ElementQueryHelper::forCurrentSite(Service::find());
    }

    public function employees(): EmployeeQuery
    {
        return Employee::find()->siteId('*');
    }

    public function locations(): LocationQuery
    {
        return Location::find()->siteId('*');
    }

    public function reservations(): ReservationQueryInterface
    {
        return ReservationFactory::find();
    }

    public function myBookings(): ReservationQueryInterface
    {
        return ReservationFactory::find()->forCurrentUser();
    }

    public function myUpcomingBookings(int $limit = 10): array
    {
        return ReservationFactory::find()
            ->forCurrentUser()
            ->status(['confirmed', 'pending'])
            ->andWhere(['>=', 'slots_reservations.bookingDate', date('Y-m-d')])
            ->orderBy('slots_reservations.bookingDate ASC, slots_reservations.startTime ASC')
            ->limit($limit)
            ->all();
    }

    public function myPastBookings(int $limit = 10): array
    {
        return ReservationFactory::find()
            ->forCurrentUser()
            ->andWhere(['<', 'slots_reservations.bookingDate', date('Y-m-d')])
            ->orderBy('slots_reservations.bookingDate DESC, slots_reservations.startTime DESC')
            ->limit($limit)
            ->all();
    }

    public function myBookingCount(): int
    {
        return ReservationFactory::find()->forCurrentUser()->count();
    }

    public function getAvailableSlots(string|array $dateOrParams): array
    {
        $availabilityService = Slots::getInstance()->getAvailability();

        if (is_array($dateOrParams)) {
            return $availabilityService->getAvailableSlots(
                $dateOrParams['date'] ?? '',
                $dateOrParams['employeeId'] ?? null,
                $dateOrParams['locationId'] ?? null,
                $dateOrParams['serviceId'] ?? null,
                $dateOrParams['requestedQuantity'] ?? 1,
                $dateOrParams['userTimezone'] ?? null
            );
        }

        return $availabilityService->getAvailableSlots($dateOrParams);
    }

    public function getNextAvailableDate(): ?string
    {
        return Slots::getInstance()->getAvailability()->getNextAvailableDate();
    }

    public function getAvailabilityCalendar(string $startDate, string $endDate): array
    {
        return Slots::getInstance()->getAvailability()->getAvailabilitySummary($startDate, $endDate);
    }

    public function getUpcomingReservations(int $limit = 10): array
    {
        return Slots::getInstance()->getBooking()->getUpcomingReservations($limit);
    }

    public function getSettings(): Settings
    {
        return Slots::getInstance()->getSettings();
    }

    public function isSlotAvailable(
        string $date,
        string $startTime,
        string $endTime,
        ?int $employeeId = null,
        ?int $locationId = null,
        ?int $serviceId = null,
        int $requestedQuantity = 1,
    ): bool {
        return Slots::getInstance()->getAvailability()->isSlotAvailable(
            $date, $startTime, $endTime, $employeeId, $locationId, $serviceId, $requestedQuantity
        );
    }

    public function getEmployeeSchedules(int $employeeId): array
    {
        return Slots::getInstance()->getScheduleAssignment()->getSchedulesForEmployee($employeeId);
    }

    public function getServiceEmployees(int $serviceId): array
    {
        return Employee::find()->siteId('*')->serviceId($serviceId)->all();
    }

    public function getLocationEmployees(int $locationId): array
    {
        return Employee::find()->siteId('*')->locationId($locationId)->all();
    }

    public function isEmployeeAvailable(int $employeeId, string $date): bool
    {
        return Slots::getInstance()->getScheduleAssignment()->getActiveScheduleForDate($employeeId, $date) !== null;
    }

    public function isServiceBookable(Service|int $service): bool
    {
        if (is_int($service)) {
            $service = Service::find()->id($service)->one();
        }

        if (!$service || !$service->enabled) {
            return false;
        }

        return Employee::find()->siteId('*')->serviceId($service->id)->count() > 0
            || $service->hasAvailabilitySchedule();
    }

    public function getStats(): array
    {
        return Slots::getInstance()->getBooking()->getBookingStats();
    }

    public function __get($name)
    {
        return match ($name) {
            default => null,
        };
    }

    public function schedules(): ScheduleQuery
    {
        return Schedule::find()->siteId('*');
    }

    public function blackoutDates(): BlackoutDateQuery
    {
        return BlackoutDate::find()->siteId('*');
    }

    public function getCurrency(): string
    {
        $settings = Slots::getInstance()->getSettings();

        if (!empty($settings->defaultCurrency) && $settings->defaultCurrency !== 'auto') {
            return $settings->defaultCurrency;
        }

        if (Craft::$app->plugins->isPluginEnabled('commerce')) {
            try {
                $paymentCurrency = \craft\commerce\Plugin::getInstance()->getPaymentCurrencies()->getPrimaryPaymentCurrency();
                if ($paymentCurrency) {
                    return $paymentCurrency->iso;
                }
            } catch (\Exception) {
            }
        }

        return 'USD';
    }
}
