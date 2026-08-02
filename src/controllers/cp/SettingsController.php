<?php

namespace anvildev\slots\controllers\cp;

use anvildev\slots\controllers\traits\JsonResponseTrait;
use anvildev\slots\models\Settings;
use anvildev\slots\Slots;
use Craft;
use craft\web\Controller;
use craft\web\Response;
use Money\Currencies\ISOCurrencies;

class SettingsController extends Controller
{
    use JsonResponseTrait;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('slots-manageSettings');
        return true;
    }

    public function actionBooking(): Response
    {
        return $this->renderSettingsTemplate('slots/settings/booking', 'booking', 'selectedSettingsSubnavItem');
    }

    public function actionSecurity(): Response
    {
        return $this->renderSettingsTemplate('slots/settings/security', 'security', 'selectedSettingsSubnavItem');
    }

    public function actionNotifications(): Response
    {
        return $this->renderSettingsTemplate('slots/settings/notifications', 'notifications');
    }

    public function actionPayments(): Response
    {
        $currencyOptions = [
            ['label' => Craft::t('slots', 'settings.booking.autoDetectCurrency'), 'value' => 'auto'],
        ];
        foreach (new ISOCurrencies() as $currency) {
            $currencyOptions[] = ['label' => $currency->getCode(), 'value' => $currency->getCode()];
        }

        return $this->renderTemplate('slots/settings/payments', [
            'selectedSubnavItem' => 'payments',
            'settings' => Settings::loadSettings(),
            'currencyOptions' => $currencyOptions,
            'webhookUrl' => \craft\helpers\UrlHelper::siteUrl('slots/api/v1/payment/webhook/stripe'),
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $postedSettings = Craft::$app->request->getBodyParam('settings', []);
        $tab = Craft::$app->request->getBodyParam('tab', '');
        $settings = Settings::loadSettings();

        // Only allow attributes for the submitted tab
        $safeAttributes = $settings->safeAttributesForTab($tab);
        $filteredSettings = array_intersect_key($postedSettings, array_flip($safeAttributes));
        $settings->setAttributes($filteredSettings);

        if ($settings->validate() && $settings->save()) {
            Slots::getInstance()->getAudit()->logSettingsChange(
                Craft::$app->getUser()->getIdentity()->email ?? 'unknown',
                array_keys($filteredSettings)
            );
            Craft::$app->session->setNotice(Craft::t('slots', 'settings.saved'));
        } else {
            Craft::$app->session->setError($settings->hasErrors()
                ? Craft::t('slots', 'settings.validationErrors', ['errors' => implode(', ', $settings->getFirstErrors())])
                : Craft::t('slots', 'settings.notSaved')
            );
        }

        return $this->redirectToPostedUrl();
    }

    private function renderSettingsTemplate(string $template, string $subnavItem, string $subnavKey = 'selectedSubnavItem'): Response
    {
        return $this->renderTemplate($template, [
            $subnavKey => $subnavItem,
            'settings' => Settings::loadSettings(),
        ]);
    }
}
