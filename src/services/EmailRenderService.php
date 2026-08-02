<?php

namespace anvildev\slots\services;

use anvildev\slots\contracts\ReservationInterface;
use anvildev\slots\helpers\DateHelper;
use anvildev\slots\models\Settings;
use anvildev\slots\traits\RendersInLanguage;
use Craft;
use craft\base\Component;
use craft\helpers\UrlHelper;
use craft\models\Site;
use craft\web\View;

/**
 * Renders email templates for booking notifications with multi-site and multi-language support.
 *
 * Template resolution order:
 * 1. templates/slots/emails/[site-handle]/[template].twig (site-specific public)
 * 2. templates/_slots/emails/[site-handle]/[template].twig (site-specific private)
 * 3. templates/_slots/emails/[template].twig (private, underscore prefix)
 * 4. templates/slots/emails/[template].twig (public fallback)
 */
class EmailRenderService extends Component
{
    use RendersInLanguage;

    private function renderEmailTemplate(string $template, array $variables, ?Site $site = null): string
    {
        $view = Craft::$app->view;
        $mode = $view->getTemplateMode();

        if ($site) {
            $siteTemplate = dirname($template) . '/' . $site->handle . '/' . basename($template);
            foreach ([$siteTemplate, '_' . $siteTemplate] as $candidate) {
                if ($view->doesTemplateExist($candidate, $mode)) {
                    return $view->renderTemplate($candidate, $variables);
                }
            }
        }

        $privateTemplate = '_' . $template;
        return $view->renderTemplate(
            $view->doesTemplateExist($privateTemplate, $mode) ? $privateTemplate : $template,
            $variables,
        );
    }

    private function getReservationSite(ReservationInterface $reservation): Site
    {
        $siteId = $reservation->getSiteId();
        return ($siteId ? Craft::$app->getSites()->getSiteById($siteId) : null)
            ?? Craft::$app->getSites()->getPrimarySite();
    }

    private function getCommonVariables(ReservationInterface $reservation, Settings $settings): array
    {
        $service = $reservation->getService();
        $employee = $reservation->getEmployee();
        $location = $reservation->getLocation();
        $quantity = $reservation->quantity ?? 1;

        $employeeName = '';
        if ($employee) {
            $user = $employee->getUser();
            $employeeName = $user ? $user->getName() : ($employee->title ?? '');
        }

        return [
            'reservation' => $reservation,
            'service' => $service,
            'employee' => $employee,
            'location' => $location,
            'settings' => $settings,
            'siteName' => Craft::$app->getSystemName(),
            'ownerName' => $settings->getEffectiveName() ?? '',
            'ownerEmail' => $settings->getEffectiveEmail() ?? '',
            'bookingId' => $reservation->id,
            'userName' => $reservation->userName,
            'userEmail' => $reservation->userEmail,
            'userPhone' => $reservation->userPhone,
            'bookingDate' => $reservation->bookingDate,
            'startTime' => $reservation->startTime,
            'endTime' => $reservation->endTime,
            'formattedBookingDate' => $reservation->bookingDate
                ? DateHelper::formatDateLocale($reservation->bookingDate)
                : '',
            'formattedStartTime' => $reservation->startTime
                ? DateHelper::formatTimeLocale(DateHelper::parseTime($reservation->startTime))
                : '',
            'formattedEndTime' => $reservation->endTime
                ? DateHelper::formatTimeLocale(DateHelper::parseTime($reservation->endTime))
                : '',
            'duration' => $reservation->getDurationMinutes(),
            'durationMinutes' => $reservation->getDurationMinutes(),
            'durationUnit' => Craft::t('slots', 'labels.minutes'),
            'durationDisplay' => $reservation->getDurationMinutes() . ' ' . Craft::t('slots', 'labels.minutes'),
            'quantity' => $quantity,
            'quantityDisplay' => $quantity > 1,
            'status' => $reservation->getStatusLabel(),
            'notes' => $reservation->notes,
            'confirmationToken' => $reservation->confirmationToken,
            'dateCreated' => $reservation->dateCreated ? Craft::$app->getFormatter()->asDatetime($reservation->dateCreated, 'short') : '',
            'serviceName' => $service?->title ?? '',
            'employeeName' => $employeeName,
            'locationName' => $location?->title ?? '',
            'variationName' => null,
            'sourceName' => $service?->title,
            'formattedDateTime' => $reservation->getFormattedDateTime(),
            'managementUrl' => $reservation->getManagementUrl(),
            'cancelUrl' => $reservation->getCancelUrl(),
            'icsUrl' => $reservation->getIcsUrl(),
            'bookingPageUrl' => $settings->bookingPageUrl ?? '',
        ];
    }

    /**
     * Render a reservation email with site-aware language and template mode.
     */
    /**
     * @param array|callable(): array $extraVars Static array or callable that returns extra vars (called inside language context)
     */
    private function renderReservationEmail(
        string $templatePath,
        ReservationInterface $reservation,
        Settings $settings,
        array|callable $extraVars = [],
    ): string {
        $site = $this->getReservationSite($reservation);
        $language = $this->getReservationLanguage($reservation);

        $oldMode = Craft::$app->view->getTemplateMode();
        Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_SITE);

        try {
            return $this->renderWithLanguage(
                function() use ($templatePath, $reservation, $settings, $extraVars, $site) {
                    $resolved = is_callable($extraVars) ? $extraVars() : $extraVars;
                    $variables = array_merge($this->getCommonVariables($reservation, $settings), $resolved);
                    return $this->renderEmailTemplate($templatePath, $variables, $site);
                },
                $language
            );
        } finally {
            Craft::$app->view->setTemplateMode($oldMode);
        }
    }

    /**
     * Wraps template mode switch + language context for non-reservation emails.
     */
    private function renderInSiteMode(callable $render, string $language): string
    {
        $oldMode = Craft::$app->view->getTemplateMode();
        Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_SITE);
        try {
            return $this->renderWithLanguage($render, $language);
        } finally {
            Craft::$app->view->setTemplateMode($oldMode);
        }
    }

    public function renderConfirmationEmail(ReservationInterface $reservation, Settings $settings): string
    {
        return $this->renderReservationEmail('slots/emails/confirmation', $reservation, $settings);
    }

    public function renderStatusChangeEmail(ReservationInterface $reservation, string $oldStatus, Settings $settings): string
    {
        return $this->renderReservationEmail('slots/emails/status-change', $reservation, $settings, [
            'oldStatus' => $oldStatus,
            'newStatus' => $reservation->getStatusLabel(),
        ]);
    }

    public function renderCancellationEmail(ReservationInterface $reservation, Settings $settings): string
    {
        $cancelledAt = new \DateTime();

        return $this->renderReservationEmail('slots/emails/cancellation', $reservation, $settings, fn() => [
            'cancelledAt' => $cancelledAt,
            'formattedCancelledAt' => Craft::$app->getFormatter()->asDatetime($cancelledAt, 'medium'),
            'cancellationReason' => $reservation->cancellationReason ?? '',
        ]);
    }

    public function renderReminderEmail(ReservationInterface $reservation, Settings $settings, int $hoursBefore = 24): string
    {
        return $this->renderReservationEmail('slots/emails/reminder', $reservation, $settings, [
            'hoursBefore' => $hoursBefore,
        ]);
    }

    /**
     * Uses the owner's preferred language, falling back to the primary site's language.
     */
    public function renderOwnerNotificationEmail(ReservationInterface $reservation, Settings $settings): string
    {
        return $this->renderInSiteMode(
            function() use ($reservation, $settings) {
                $variables = $this->getCommonVariables($reservation, $settings);
                $variables['cpEditUrl'] = UrlHelper::cpUrl('slots/bookings/' . $reservation->id);
                return $this->renderEmailTemplate('slots/emails/owner-notification', $variables);
            },
            $settings->getOwnerNotificationLanguageCode(),
        );
    }

    /**
     * The previous date and time are passed in rather than read back off the
     * reservation, which by this point already holds the new slot.
     */
    public function renderRescheduledEmail(
        ReservationInterface $reservation,
        Settings $settings,
        string $previousDate,
        string $previousStartTime,
    ): string {
        return $this->renderReservationEmail('slots/emails/rescheduled', $reservation, $settings, [
            'previousDate' => $previousDate,
            'previousStartTime' => $previousStartTime,
        ]);
    }

    public function renderQuantityChangedEmail(
        ReservationInterface $reservation,
        Settings $settings,
        int $previousQuantity,
        int $newQuantity,
        float $refundAmount = 0.0,
    ): string {
        return $this->renderReservationEmail('slots/emails/quantity-changed', $reservation, $settings, [
            'previousQuantity' => $previousQuantity,
            'newQuantity' => $newQuantity,
            'refundAmount' => $refundAmount,
        ]);
    }
}
