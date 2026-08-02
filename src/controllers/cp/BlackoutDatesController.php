<?php

namespace anvildev\slots\controllers\cp;

use anvildev\slots\elements\BlackoutDate;
use Craft;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use craft\web\Response;
use yii\web\NotFoundHttpException;

class BlackoutDatesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('slots-manageBlackoutDates');
        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('slots/blackout-dates/_index', [
            'title' => Craft::t('slots', 'titles.blackoutDates'),
        ]);
    }

    public function actionNew(): Response
    {
        return $this->renderTemplate('slots/blackout-dates/edit', [
            'blackoutDate' => null,
            'title' => Craft::t('slots', 'titles.newBlackoutDate'),
        ]);
    }

    public function actionEdit(int $id): Response
    {
        $blackoutDate = BlackoutDate::find()->siteId('*')->id($id)->one()
            ?? throw new NotFoundHttpException(Craft::t('slots', 'errors.blackoutDateNotFound'));

        return $this->renderTemplate('slots/blackout-dates/edit', [
            'blackoutDate' => $blackoutDate,
            'title' => Craft::t('slots', 'titles.editBlackoutDate'),
        ]);
    }

    public function actionSave(): \yii\web\Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->request;
        $id = $request->getBodyParam('id') ?: $request->getBodyParam('elementId');

        $blackoutDate = $id
            ? (BlackoutDate::find()->siteId('*')->id($id)->one() ?? throw new NotFoundHttpException(Craft::t('slots', 'errors.blackoutDateNotFound')))
            : new BlackoutDate();

        $blackoutDate->title = $request->getRequiredBodyParam('title');

        $startDateTime = DateTimeHelper::toDateTime($request->getRequiredBodyParam('startDate'));
        $blackoutDate->startDate = $startDateTime ? $startDateTime->format('Y-m-d') : '';

        $endDateTime = DateTimeHelper::toDateTime($request->getRequiredBodyParam('endDate'));
        $blackoutDate->endDate = $endDateTime ? $endDateTime->format('Y-m-d') : '';

        $locationIds = $request->getBodyParam('locationIds');
        $blackoutDate->locationIds = is_array($locationIds) ? $locationIds : [];

        $employeeIds = $request->getBodyParam('employeeIds');
        $blackoutDate->employeeIds = is_array($employeeIds) ? $employeeIds : [];

        $blackoutDate->isActive = (bool) $request->getBodyParam('isActive', true);

        if (!Craft::$app->elements->saveElement($blackoutDate)) {
            Craft::$app->getSession()->setError(Craft::t('slots', 'messages.blackoutDateNotSaved'));
            return $this->renderTemplate('slots/blackout-dates/edit', [
                'blackoutDate' => $blackoutDate,
                'title' => Craft::t('slots', $id ? 'titles.editBlackoutDate' : 'titles.newBlackoutDate'),
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('slots', 'messages.blackoutDateSaved'));
        return $this->redirect('slots/blackout-dates');
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $blackoutDate = BlackoutDate::find()->siteId('*')->id(Craft::$app->request->getRequiredBodyParam('id'))->one()
            ?? throw new NotFoundHttpException(Craft::t('slots', 'errors.blackoutDateNotFound'));

        if (Craft::$app->elements->deleteElement($blackoutDate)) {
            Craft::$app->getSession()->setNotice(Craft::t('slots', 'messages.blackoutDateDeleted'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('slots', 'messages.blackoutDateNotDeleted'));
        }

        return $this->redirectToPostedUrl();
    }
}
