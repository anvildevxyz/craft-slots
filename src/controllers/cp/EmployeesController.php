<?php

namespace anvildev\slots\controllers\cp;

use anvildev\slots\controllers\traits\JsonResponseTrait;
use anvildev\slots\elements\Employee;
use anvildev\slots\elements\Location;
use anvildev\slots\elements\Service;
use anvildev\slots\helpers\FormFieldHelper;
use Craft;
use craft\web\Controller;
use craft\web\Response;
use yii\web\NotFoundHttpException;

class EmployeesController extends Controller
{
    use JsonResponseTrait;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('slots-manageEmployees');
        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('slots/employees/_index', [
            'title' => Craft::t('slots', 'titles.employees'),
        ]);
    }

    public function actionEdit(?int $id = null): Response
    {
        if ($id) {
            $employee = Employee::find()->siteId('*')->id($id)->status(null)->one()
                ?? throw new NotFoundHttpException(Craft::t('slots', 'errors.employeeNotFound'));
        } else {
            $employee = new Employee();
            $employee->siteId = Craft::$app->request->getParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id;
        }

        $assignedUserIds = array_values(array_filter(array_map('intval',
            Employee::find()->siteId('*')->status(null)
                ->andWhere(['not', ['slots_employees.userId' => null]])
                ->select(['slots_employees.userId'])->column()
        )));


        return $this->renderTemplate('slots/employees/edit', [
            'employee' => $employee,
            'locations' => Location::find()->siteId('*')->enabled()->all(),
            'services' => \anvildev\slots\helpers\ElementQueryHelper::forCurrentSite(Service::find()->enabled())->all(),
            'assignedUserIds' => $assignedUserIds,
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->request;
        $id = $request->getBodyParam('elementId') ?? $request->getBodyParam('id');

        $employee = $id
            ? (Employee::find()->siteId('*')->id($id)->status(null)->one() ?? throw new NotFoundHttpException(Craft::t('slots', 'errors.employeeNotFound')))
            : new Employee();

        $employee->title = $request->getBodyParam('title');
        $employee->enabled = (bool)$request->getBodyParam('enabled', true);

        // Handle userId (array from element selector or scalar)
        $userId = $request->getBodyParam('userId');
        $userId = is_array($userId) ? ($userId[0] ?? null) : $userId;
        $employee->userId = ($userId === '' || $userId === null) ? null : (int)$userId;

        $locationId = $request->getBodyParam('locationId');
        $locationId = is_array($locationId) ? ($locationId[0] ?? null) : $locationId;
        $employee->locationId = ($locationId === '' || $locationId === null) ? null : (int)$locationId;

        $email = $request->getBodyParam('email');
        $employee->email = ($email === '' || $email === null) ? null : trim($email);

        $services = $request->getBodyParam('services', []);
        $employee->serviceIds = is_array($services) ? array_map('intval', $services) : [];

        $workingHours = $request->getBodyParam('workingHours', []);
        if (is_array($workingHours)) {
            $employee->workingHours = FormFieldHelper::formatWorkingHoursFromRequest($workingHours);
        }

        if (!Craft::$app->elements->saveElement($employee)) {
            Craft::$app->session->setError(Craft::t('slots', 'messages.employeeNotSaved'));
            Craft::$app->urlManager->setRouteParams(['employee' => $employee]);
            return $this->redirectToPostedUrl();
        }

        // Schedule assignments
        $schedules = $request->getBodyParam('schedules', []);
        \anvildev\slots\Slots::getInstance()->getScheduleAssignment()->setSchedulesForEmployee(
            $employee->id,
            is_array($schedules) ? array_map('intval', array_filter($schedules)) : []
        );

        // Managed employee assignments

        Craft::$app->session->setNotice(Craft::t('slots', 'messages.employeeSaved'));
        return $this->redirect('slots/employees');
    }
}
