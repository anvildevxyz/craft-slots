<?php

/**
 * Slots plugin for Craft CMS 5.x
 *
 * A comprehensive booking system for Craft CMS
 *
 * @link      https://anvildev.xyz
 * @copyright Copyright (c) 2025
 */

namespace anvildev\slots;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Fields;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\View;
use yii\base\Event;

/**
 * Slots plugin for Craft CMS - a focused booking and appointment system.
 *
 * Provides element-based services, employees, locations, schedules, and reservations
 * with subtractive availability, queue-based notifications,
 * and native Stripe payments.
 *
 * @method static Slots|null getInstance()
 *
 * @property-read \anvildev\slots\services\AvailabilityService $availability
 * @property-read \anvildev\slots\services\BookingService $booking
 * @property-read \anvildev\slots\services\BookingSecurityService $bookingSecurity
 * @property-read \anvildev\slots\services\BookingNotificationService $bookingNotification
 * @property-read \anvildev\slots\services\BookingValidationService $bookingValidation
 * @property-read \anvildev\slots\services\BlackoutDateService $blackoutDate
 * @property-read \anvildev\slots\services\SoftLockService $softLock
 * @property-read \anvildev\slots\services\ReminderService $reminder
 * @property-read \anvildev\slots\services\EmailRenderService $emailRender
 * @property-read \anvildev\slots\services\ServiceLocationService $serviceLocation
 * @property-read \anvildev\slots\services\CaptchaService $captcha
 * @property-read \anvildev\slots\services\ScheduleAssignmentService $scheduleAssignment
 * @property-read \anvildev\slots\services\PermissionService $permission
 * @property-read \anvildev\slots\services\CustomerService $customers
 * @property-read \anvildev\slots\services\AuditService $audit
 * @property-read \anvildev\slots\services\MaintenanceService $maintenance
 * @property-read \anvildev\slots\services\TimezoneService $timezone
 * @property-read \anvildev\slots\services\TimeWindowService $timeWindow
 * @property-read \anvildev\slots\services\SlotGeneratorService $slotGenerator
 * @property-read \anvildev\slots\services\ScheduleResolverService $scheduleResolver
 * @property-read \anvildev\slots\services\CapacityService $capacity
 * @property-read \anvildev\slots\services\ReportsService $reports
 * @property-read \anvildev\slots\services\DashboardService $dashboard
 * @property-read \anvildev\slots\services\RefundPolicyService $refundPolicy
 * @property-read \anvildev\slots\services\MutexFactory $mutex
 * @property-read \anvildev\slots\services\PaymentGatewayService $paymentGateways
 * @property-read \anvildev\slots\services\PaymentService $payments
 */
class Slots extends Plugin
{
    public string $schemaVersion = '1.2.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();

        Craft::setAlias('@slots', $this->getBasePath());

        $this->controllerNamespace = Craft::$app instanceof \craft\console\Application
            ? 'anvildev\\slots\\console\\controllers'
            : 'anvildev\\slots\\controllers';

        $this->registerServices();
        $this->registerCpRoutes();
        $this->registerSiteRoutes();
        $this->registerApiRoutes();
        $this->registerPaymentGateways();
        $this->registerQuantityChangeListeners();
        $this->registerMaintenanceListeners();
        $this->registerTemplateRoots();
        $this->registerElementTypes();
        $this->registerPermissions();
        $this->registerTemplateVariable();
        $this->registerFieldTypes();
        $this->registerWidgetTypes();
    }

    public static function displayName(): string
    {
        return Craft::t('slots', 'plugin.name');
    }

    public static function description(): string
    {
        return Craft::t('slots', 'plugin.description');
    }

    public static function getInstance(): ?self
    {
        return parent::getInstance();
    }

    private function registerTemplateRoots(): void
    {
        $templateRoot = $this->getBasePath() . DIRECTORY_SEPARATOR . 'templates';
        $handler = static fn(RegisterTemplateRootsEvent $event) => $event->roots['slots'] = $templateRoot;

        Event::on(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS, $handler);
        Event::on(View::class, View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS, $handler);
    }

    private function registerServices(): void
    {
        $this->setComponents([
            'availability' => \anvildev\slots\services\AvailabilityService::class,
            'timeWindow' => \anvildev\slots\services\TimeWindowService::class,
            'slotGenerator' => \anvildev\slots\services\SlotGeneratorService::class,
            'scheduleResolver' => \anvildev\slots\services\ScheduleResolverService::class,
            'capacity' => \anvildev\slots\services\CapacityService::class,
            'booking' => \anvildev\slots\services\BookingService::class,
            'bookingSecurity' => \anvildev\slots\services\BookingSecurityService::class,
            'bookingNotification' => \anvildev\slots\services\BookingNotificationService::class,
            'bookingValidation' => \anvildev\slots\services\BookingValidationService::class,
            'blackoutDate' => \anvildev\slots\services\BlackoutDateService::class,
            'softLock' => \anvildev\slots\services\SoftLockService::class,
            'reminder' => \anvildev\slots\services\ReminderService::class,
            'emailRender' => \anvildev\slots\services\EmailRenderService::class,
            'maintenance' => \anvildev\slots\services\MaintenanceService::class,
            'timezone' => \anvildev\slots\services\TimezoneService::class,
            'serviceLocation' => \anvildev\slots\services\ServiceLocationService::class,
            'captcha' => \anvildev\slots\services\CaptchaService::class,
            'scheduleAssignment' => \anvildev\slots\services\ScheduleAssignmentService::class,
            'audit' => \anvildev\slots\services\AuditService::class,
            'permission' => \anvildev\slots\services\PermissionService::class,
            'customers' => \anvildev\slots\services\CustomerService::class,
            'reports' => \anvildev\slots\services\ReportsService::class,
            'dashboard' => \anvildev\slots\services\DashboardService::class,
            'refundPolicy' => \anvildev\slots\services\RefundPolicyService::class,
            'mutex' => \anvildev\slots\services\MutexFactory::class,
            'paymentGateways' => \anvildev\slots\services\PaymentGatewayService::class,
            'payments' => \anvildev\slots\services\PaymentService::class,
        ]);
    }

    /**
     * Register the built-in payment gateway adapters. Available in both editions
     * — direct payments are Lite's anchor feature. Third parties add more via the
     * same EVENT_REGISTER_PAYMENT_GATEWAYS event.
     */
    private function registerPaymentGateways(): void
    {
        Event::on(
            \anvildev\slots\services\PaymentGatewayService::class,
            \anvildev\slots\services\PaymentGatewayService::EVENT_REGISTER_PAYMENT_GATEWAYS,
            function(\anvildev\slots\events\RegisterPaymentGatewaysEvent $event) {
                $event->gateways[] = new \anvildev\slots\gateways\StripeGateway();
            },
        );
    }

    public function getReminder(): \anvildev\slots\services\ReminderService
    {
        return $this->get('reminder');
    }

    public function getAvailability(): \anvildev\slots\services\AvailabilityService
    {
        return $this->get('availability');
    }

    public function getBooking(): \anvildev\slots\services\BookingService
    {
        return $this->get('booking');
    }

    public function getBlackoutDate(): \anvildev\slots\services\BlackoutDateService
    {
        return $this->get('blackoutDate');
    }

    public function getSoftLock(): \anvildev\slots\services\SoftLockService
    {
        return $this->get('softLock');
    }

    public function getEmailRender(): \anvildev\slots\services\EmailRenderService
    {
        return $this->get('emailRender');
    }

    public function getTimeWindow(): \anvildev\slots\services\TimeWindowService
    {
        return $this->get('timeWindow');
    }

    public function getSlotGenerator(): \anvildev\slots\services\SlotGeneratorService
    {
        return $this->get('slotGenerator');
    }

    public function getScheduleResolver(): \anvildev\slots\services\ScheduleResolverService
    {
        return $this->get('scheduleResolver');
    }

    public function getCapacity(): \anvildev\slots\services\CapacityService
    {
        return $this->get('capacity');
    }

    public function getBookingSecurity(): \anvildev\slots\services\BookingSecurityService
    {
        return $this->get('bookingSecurity');
    }

    public function getBookingNotification(): \anvildev\slots\services\BookingNotificationService
    {
        return $this->get('bookingNotification');
    }

    public function getBookingValidation(): \anvildev\slots\services\BookingValidationService
    {
        return $this->get('bookingValidation');
    }

    public function getTimezone(): \anvildev\slots\services\TimezoneService
    {
        return $this->get('timezone');
    }

    public function getPaymentGateways(): \anvildev\slots\services\PaymentGatewayService
    {
        return $this->get('paymentGateways');
    }

    public function getPayments(): \anvildev\slots\services\PaymentService
    {
        return $this->get('payments');
    }

    /**
     * Register quantity-change integrations.
     */
    private function registerQuantityChangeListeners(): void
    {
        Event::on(
            \anvildev\slots\services\BookingService::class,
            \anvildev\slots\services\BookingService::EVENT_AFTER_QUANTITY_CHANGE,
            function(\anvildev\slots\events\AfterQuantityChangeEvent $event) {
                $reservationId = $event->reservation->getId();

                // Customer quantity-changed email — a core notification.
                try {
                    $this->bookingNotification->queueQuantityChangedEmail(
                        $reservationId,
                        $event->previousQuantity,
                        $event->newQuantity,
                    );
                } catch (\Throwable $e) {
                    Craft::error("Failed to queue quantity change email for reservation #{$reservationId}: " . $e->getMessage(), __METHOD__);
                }
            }
        );
    }

    private function registerElementTypes(): void
    {
        Event::on(
            \craft\services\Elements::class,
            \craft\services\Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function(\craft\events\RegisterComponentTypesEvent $event) {
                $event->types[] = \anvildev\slots\elements\Reservation::class;
                $event->types[] = \anvildev\slots\elements\Service::class;
                $event->types[] = \anvildev\slots\elements\Employee::class;
                $event->types[] = \anvildev\slots\elements\Location::class;
                $event->types[] = \anvildev\slots\elements\Schedule::class;
                $event->types[] = \anvildev\slots\elements\BlackoutDate::class;
            }
        );
    }

    private function registerFieldTypes(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function(\craft\events\RegisterComponentTypesEvent $event) {
                $event->types[] = \anvildev\slots\fields\SlotsServices::class;
            }
        );
    }

    private function registerWidgetTypes(): void
    {
        Event::on(
            \craft\services\Dashboard::class,
            \craft\services\Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function(\craft\events\RegisterComponentTypesEvent $event) {
                $event->types[] = \anvildev\slots\widgets\SlotsWidget::class;
            }
        );
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            \craft\web\UrlManager::class,
            \craft\web\UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(\craft\events\RegisterUrlRulesEvent $event) {
                $event->rules = array_merge($event->rules, [
                    // Default redirect to dashboard
                    'slots' => 'slots/cp/dashboard/index',

                    // Dashboard
                    'slots/dashboard' => 'slots/cp/dashboard/index',

                    // Calendar Views
                    'slots/calendar-view/month' => 'slots/cp/calendar-view/month',
                    'slots/calendar-view/week' => 'slots/cp/calendar-view/week',
                    'slots/calendar-view/day' => 'slots/cp/calendar-view/day',
                    'slots/calendar-view/reschedule' => 'slots/cp/calendar-view/reschedule',

                    // Reports
                    'slots/reports' => 'slots/cp/reports/index',
                    'slots/reports/revenue' => 'slots/cp/reports/revenue',

                    // Phase 1.3 - Core element management
                    'slots/services' => 'slots/cp/services/index',
                    'slots/services/new' => 'slots/cp/services/edit',
                    'slots/services/<id:\d+>' => 'slots/cp/services/edit',

                    'slots/employees' => 'slots/cp/employees/index',
                    'slots/employees/new' => 'slots/cp/employees/edit',
                    'slots/employees/<id:\d+>' => 'slots/cp/employees/edit',

                    'slots/schedules' => 'slots/cp/schedules/index',
                    'slots/schedules/new' => 'slots/cp/schedules/edit',
                    'slots/schedules/<id:\d+>' => 'slots/cp/schedules/edit',

                    'slots/locations' => 'slots/cp/locations/index',
                    'slots/locations/new' => 'slots/cp/locations/edit',
                    'slots/locations/<id:\d+>' => 'slots/cp/locations/edit',

                    'slots/blackout-dates' => 'slots/cp/blackout-dates/index',
                    'slots/blackout-dates/new' => 'slots/cp/blackout-dates/new',
                    'slots/blackout-dates/<id:\d+>' => 'slots/cp/blackout-dates/edit',

                    // Customers
                    'slots/customers' => 'slots/cp/customers/index',
                    'slots/customers/<email:.+>' => 'slots/cp/customers/detail',

                    // Bookings
                    'slots/bookings' => 'slots/cp/bookings/index',
                    'slots/bookings/new' => 'slots/cp/bookings/edit',
                    'slots/bookings/<id:\d+>' => 'slots/cp/bookings/edit',
                    'slots/bookings/<id:\d+>/view' => 'slots/cp/bookings/view',
                    'slots/bookings/export' => 'slots/cp/bookings/export',

                    // Settings - with sidebar navigation
                    'slots/settings' => 'slots/cp/settings/booking',
                    'slots/settings/booking' => 'slots/cp/settings/booking',
                    'slots/settings/security' => 'slots/cp/settings/security',
                    'slots/settings/notifications' => 'slots/cp/settings/notifications',
                    'slots/settings/payments' => 'slots/cp/settings/payments',



                ]);
            }
        );
    }

    private function registerSiteRoutes(): void
    {
        Event::on(
            \craft\web\UrlManager::class,
            \craft\web\UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(\craft\events\RegisterUrlRulesEvent $event) {
                $event->rules = array_merge($event->rules, [
                    'booking/manage/<token:[^\/]+>' => 'slots/booking-management/manage-booking',
                    'booking/cancel/<token:[^\/]+>' => 'slots/booking-management/cancel-booking-by-token',
                    'booking/ics/<token:[^\/]+>' => 'slots/booking-management/download-ics',
                    'account/bookings' => 'slots/booking-management/my-bookings',
                    // Customer account portal routes
                    'slots/account' => 'slots/account/index',
                    'slots/account/bookings' => 'slots/account/bookings',
                    'slots/account/upcoming' => 'slots/account/upcoming',
                    'slots/account/past' => 'slots/account/past',
                    'slots/account/<id:\d+>' => 'slots/account/view',
                ]);
            }
        );
    }

    /**
     * Versioned headless API (/slots/api/v1/…).
     *
     * These are thin aliases onto the existing frontend controller actions: the
     * headless wizard core targets only the versioned paths, while the original
     * action routes keep working. Route
     * tokens (e.g. serviceId) merge into request params, so the backing actions
     * read them unchanged. Management-mode routes are added when that flow is
     * wired into the core.
     */
    private function registerApiRoutes(): void
    {
        Event::on(
            \craft\web\UrlManager::class,
            \craft\web\UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(\craft\events\RegisterUrlRulesEvent $event) {
                $event->rules = array_merge($event->rules, [
                    // Booking data
                    'slots/api/v1/services' => 'slots/booking-data/get-services',
                    'slots/api/v1/services/employees' => 'slots/booking-data/get-employees',
                    'slots/api/v1/payment-settings' => 'slots/booking-data/get-payment-settings',
                    // Availability
                    'slots/api/v1/availability/slots' => 'slots/slot/get-available-slots',
                    'slots/api/v1/availability/calendar' => 'slots/slot/get-availability-calendar',
                    // Locks
                    'slots/api/v1/locks/slot' => 'slots/slot/create-lock',
                    'slots/api/v1/locks/extend' => 'slots/slot/extend-lock',
                    'slots/api/v1/locks/release' => 'slots/slot/release-lock',
                    // Booking
                    'slots/api/v1/bookings' => 'slots/booking/create-booking',
                    // Direct payments
                    'slots/api/v1/payment/create' => 'slots/payment/create',
                    'slots/api/v1/payment/confirm' => 'slots/payment/confirm',
                    'slots/api/v1/payment/webhook/<gateway:[\w-]+>' => 'slots/payment/webhook',
                    // Account
                    'slots/api/v1/me' => 'slots/account/current-user',
                    // Booking management (?manage= token flow)
                    'slots/api/v1/manage' => 'slots/booking-management/manage-booking',
                    'slots/api/v1/manage/reduce' => 'slots/booking-management/reduce-quantity',
                    'slots/api/v1/manage/increase' => 'slots/booking-management/increase-quantity',
                    // Cancel needs its own POST route: a POST to the bare
                    // `manage` rule above does not resolve (Craft site URL rules
                    // route GET only for that pattern), so the headless wizard
                    // cancels here, passing the token in the body.
                    'slots/api/v1/manage/cancel' => 'slots/booking-management/cancel-booking-by-token',
                ]);
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('slots', 'permissions.heading'),
                    'permissions' => [
                        'slots-accessPlugin' => [
                            'label' => Craft::t('slots', 'permissions.accessPlugin'),
                            'nested' => [
                                'slots-viewBookings' => [
                                    'label' => Craft::t('slots', 'permissions.viewBookings'),
                                    'nested' => [
                                        'slots-manageBookings' => [
                                            'label' => Craft::t('slots', 'permissions.manageBookings'),
                                        ],
                                        'slots-manageRefunds' => [
                                            'label' => Craft::t('slots', 'permissions.manageRefunds'),
                                        ],
                                    ],
                                ],
                                'slots-viewCalendar' => [
                                    'label' => Craft::t('slots', 'permissions.viewCalendar'),
                                ],
                                'slots-viewReports' => [
                                    'label' => Craft::t('slots', 'permissions.viewReports'),
                                ],
                                'slots-manageServices' => [
                                    'label' => Craft::t('slots', 'permissions.manageServices'),
                                ],
                                'slots-manageEmployees' => [
                                    'label' => Craft::t('slots', 'permissions.manageEmployees'),
                                ],
                                'slots-manageLocations' => [
                                    'label' => Craft::t('slots', 'permissions.manageLocations'),
                                ],
                                'slots-manageBlackoutDates' => [
                                    'label' => Craft::t('slots', 'permissions.manageBlackoutDates'),
                                ],
                                'slots-manageSettings' => [
                                    'label' => Craft::t('slots', 'permissions.manageSettings'),
                                ],
                            ],
                        ],
                    ],
                ];
            }
        );
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['icon'] = '@slots/nav-icon.svg';
        $item['url'] = 'slots';

        /** @var \craft\elements\User|null $user */
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            $item['subnav'] = [];
            return $item;
        }

        $isAdmin = $user->admin;
        $can = static fn(string ...$perms): bool => $isAdmin || array_any($perms, fn($perm) => $user->can($perm));

        // [key, translationKey, url, ...permissions]
        $navDefs = [
            ['calendar', 'nav.calendar', 'slots/calendar-view/month', 'slots-viewCalendar', 'slots-viewBookings'],
            ['bookings', 'nav.bookings', 'slots/bookings', 'slots-viewBookings'],
            ['customers', 'nav.customers', 'slots/customers', 'slots-viewBookings'],
            ['services', 'nav.services', 'slots/services', 'slots-manageServices'],
            ['employees', 'nav.employees', 'slots/employees', 'slots-manageEmployees'],
            ['schedules', 'nav.schedules', 'slots/schedules', 'slots-manageEmployees'],
            ['locations', 'nav.locations', 'slots/locations', 'slots-manageLocations'],
            ['blackout-dates', 'nav.blackoutDates', 'slots/blackout-dates', 'slots-manageBlackoutDates'],
            ['reports', 'nav.reports', 'slots/reports', 'slots-viewReports'],
            ['settings', 'nav.settings', 'slots/settings', 'slots-manageSettings'],
        ];

        $subnav = [];
        foreach ($navDefs as $def) {
            $key = $def[0];
            if ($can(...array_slice($def, 3))) {
                $subnav[$key] = ['label' => Craft::t('slots', $def[1]), 'url' => $def[2]];
            }
        }

        $item['subnav'] = $subnav;
        return $item;
    }

    protected function createSettingsModel(): ?\craft\base\Model
    {
        return new \anvildev\slots\models\Settings();
    }

    public function getSettings(): \anvildev\slots\models\Settings
    {
        return \anvildev\slots\models\Settings::loadSettings();
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(
            \craft\helpers\UrlHelper::cpUrl('slots/settings')
        );
    }

    private function registerTemplateVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('slots', \anvildev\slots\variables\BookingVariable::class);
            }
        );
    }

    public function getServiceLocation(): \anvildev\slots\services\ServiceLocationService
    {
        return $this->get('serviceLocation');
    }

    public function getCaptcha(): \anvildev\slots\services\CaptchaService
    {
        return $this->get('captcha');
    }

    public function getScheduleAssignment(): \anvildev\slots\services\ScheduleAssignmentService
    {
        return $this->get('scheduleAssignment');
    }

    public function getAudit(): \anvildev\slots\services\AuditService
    {
        return $this->get('audit');
    }

    public function getPermission(): \anvildev\slots\services\PermissionService
    {
        return $this->get('permission');
    }

    public function getCustomers(): \anvildev\slots\services\CustomerService
    {
        return $this->get('customers');
    }

    public function getMaintenance(): \anvildev\slots\services\MaintenanceService
    {
        return $this->get('maintenance');
    }

    public function getReports(): \anvildev\slots\services\ReportsService
    {
        return $this->get('reports');
    }

    public function getDashboard(): \anvildev\slots\services\DashboardService
    {
        return $this->get('dashboard');
    }

    public function getRefundPolicy(): \anvildev\slots\services\RefundPolicyService
    {
        return $this->get('refundPolicy');
    }

    public function getMutex(): \anvildev\slots\services\MutexFactory
    {
        return $this->get('mutex');
    }

    /**
     * Report-cache invalidation on booking changes, plus the maintenance
     * garbage-collection hook.
     *
     * Easy to mistake for dead wiring: nothing calls these directly, but the
     * report cache and garbage collection both depend on them firing.
     */
    private function registerMaintenanceListeners(): void
    {
        Event::on(
            \anvildev\slots\services\BookingService::class,
            \anvildev\slots\services\BookingService::EVENT_AFTER_BOOKING_SAVE,
            function(\anvildev\slots\events\AfterBookingSaveEvent $event) {
                if ($event->success) {
                    $this->getReports()->invalidateReportCaches();
                }
            }
        );

        Event::on(
            \anvildev\slots\services\BookingService::class,
            \anvildev\slots\services\BookingService::EVENT_AFTER_BOOKING_CANCEL,
            function(\anvildev\slots\events\AfterBookingCancelEvent $event) {
                if ($event->success) {
                    $this->getReports()->invalidateReportCaches();
                }
            }
        );

        Event::on(
            \craft\services\Gc::class,
            \craft\services\Gc::EVENT_RUN,
            function() {
                $results = $this->getMaintenance()->runAll();
                $total = array_sum(array_filter($results, 'is_int'));
                if ($total > 0) {
                    Craft::info("Maintenance cleanup completed: " . json_encode($results), __METHOD__);
                }
            }
        );
    }
}
