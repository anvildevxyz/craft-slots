<?php

namespace anvildev\slots\controllers\cp;

use anvildev\slots\controllers\traits\JsonResponseTrait;
use anvildev\slots\elements\Service;
use anvildev\slots\helpers\ElementQueryHelper;
use anvildev\slots\helpers\FormFieldHelper;
use anvildev\slots\helpers\RefundTierHelper;
use anvildev\slots\helpers\SiteHelper;
use anvildev\slots\Slots;
use Craft;
use craft\enums\PropagationMethod;
use craft\web\Controller;
use craft\web\Response;
use yii\web\NotFoundHttpException;

class ServicesController extends Controller
{
    use JsonResponseTrait;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('slots-manageServices');
        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('slots/services/_index', [
            'title' => Craft::t('slots', 'titles.services'),
        ]);
    }

    public function actionEdit(?int $id = null, ?Service $service = null): Response
    {
        $currentSite = SiteHelper::getSiteForRequest(Craft::$app->getRequest());

        if ($service === null) {
            if ($id !== null) {
                $service = ElementQueryHelper::forSite(
                    Service::find()->id($id)->status(null),
                    $currentSite->id
                )->one();

                if (!$service) {
                    $service = ElementQueryHelper::forAllSites(
                        Service::find()->id($id)->status(null)
                    )->one() ?? throw new NotFoundHttpException(Craft::t('slots', 'errors.serviceNotFound'));

                    $existingSite = Craft::$app->getSites()->getSiteById($service->siteId);
                    if ($existingSite) {
                        Craft::$app->getSession()->setNotice(Craft::t('slots', 'multiSite.redirectNotice', [
                            'site' => $currentSite->name,
                            'existingSite' => $existingSite->name,
                        ]));
                        return $this->redirect("slots/services/{$id}?site={$existingSite->handle}");
                    }
                }
            } else {
                $service = new Service();
                $service->siteId = $currentSite->id;
            }
        }

        $isNew = !$service->id;
        $assignedLocations = $isNew ? [] : array_map(
            fn($location) => $location->id,
            Slots::getInstance()->serviceLocation->getLocationsForService($service->id)
        );

        return $this->renderTemplate('slots/services/_edit', [
            'service' => $service,
            'isNew' => $isNew,
            'title' => $isNew ? Craft::t('slots', 'titles.newService') : $service->title,
            'assignedLocations' => $assignedLocations,
            'currentSite' => $currentSite,
        ]);
    }

    public function actionSave(): ?\yii\web\Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $id = $request->getBodyParam('id');
        $currentSite = SiteHelper::getSiteFromPost($request);

        $service = $id
            ? (ElementQueryHelper::forSite(Service::find()->id($id)->status(null), $currentSite->id)->one()
                ?? throw new NotFoundHttpException('Service not found'))
            : (function() use ($currentSite) {
                $s = new Service();
                $s->siteId = $currentSite->id;
                return $s;
            })();

        $service->title = $request->getBodyParam('title');
        $service->description = $request->getBodyParam('description') ?: null;
        $service->enabled = (bool)$request->getBodyParam('enabled', true);
        $service->duration = $request->getBodyParam('duration') ?: null;
        $service->bufferBefore = $request->getBodyParam('bufferBefore') ?: null;
        $service->bufferAfter = $request->getBodyParam('bufferAfter') ?: null;
        $service->price = $request->getBodyParam('price') ?: null;
        $service->minTimeBeforeBooking = $request->getBodyParam('minTimeBeforeBooking') ?: null;
        $service->propagationMethod = PropagationMethod::tryFrom($request->getBodyParam('propagationMethod', 'none')) ?? PropagationMethod::None;
        $service->timeSlotLength = $request->getBodyParam('timeSlotLength') ?: null;
        $service->customerLimitEnabled = (bool)$request->getBodyParam('customerLimitEnabled', false);
        $service->customerLimitCount = $request->getBodyParam('customerLimitCount') ?: null;
        $service->customerLimitPeriod = $request->getBodyParam('customerLimitPeriod') ?: null;
        $service->customerLimitPeriodType = $request->getBodyParam('customerLimitPeriodType') ?: null;

        $service->allowCancellation = (bool)$request->getBodyParam('allowCancellation', false);
        $service->allowRefund = (bool)$request->getBodyParam('allowRefund', false);

        $cancellationPolicyHours = $request->getBodyParam('cancellationPolicyHours');
        $service->cancellationPolicyHours = ($cancellationPolicyHours !== '' && $cancellationPolicyHours !== null) ? (int)$cancellationPolicyHours : null;

        $refundTiersParam = $request->getBodyParam('refundTiers');
        $service->refundTiers = $this->normalizeRefundTiers($refundTiersParam);


        $taxCategoryId = $request->getBodyParam('taxCategoryId');
        $service->taxCategoryId = ($taxCategoryId !== '' && $taxCategoryId !== null) ? (int)$taxCategoryId : null;

        $service->availabilitySchedule = FormFieldHelper::formatWorkingHoursFromRequest(
            $request->getBodyParam('availabilitySchedule', [])
        );

        if (!Craft::$app->getElements()->saveElement($service)) {
            Craft::$app->getSession()->setError(Craft::t('slots', 'messages.serviceNotSaved'));
            Craft::$app->getUrlManager()->setRouteParams(['service' => $service]);
            return null;
        }

        // Save service locations
        $selectedLocations = $request->getBodyParam('locations', []);
        $selectedLocations = is_array($selectedLocations) ? array_map('intval', array_filter($selectedLocations)) : [];
        Slots::getInstance()->serviceLocation->setLocationsForService($service->id, $selectedLocations);

        // Save schedule assignments
        $schedules = $request->getBodyParam('schedules', []);
        Slots::getInstance()->getScheduleAssignment()->setSchedulesForService(
            $service->id,
            is_array($schedules) ? array_map('intval', array_filter($schedules)) : []
        );

        Craft::$app->getSession()->setNotice(Craft::t('slots', 'messages.serviceSaved'));
        return $this->redirectToPostedUrl($service);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $service = ElementQueryHelper::forAllSites(
            Service::find()->id(Craft::$app->getRequest()->getRequiredBodyParam('id'))->status(null)
        )->one() ?? throw new NotFoundHttpException('Service not found');

        if (!Craft::$app->getElements()->deleteElement($service)) {
            return $this->jsonError(Craft::t('slots', 'messages.serviceDeleteFailed'));
        }

        return $this->jsonSuccess(Craft::t('slots', 'messages.serviceDeleted'));
    }

    private function normalizeRefundTiers(mixed $param): ?array
    {
        return RefundTierHelper::normalize($param);
    }
}
