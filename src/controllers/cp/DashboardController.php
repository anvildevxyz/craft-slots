<?php

namespace anvildev\slots\controllers\cp;

use anvildev\slots\Slots;
use craft\web\Controller;

class DashboardController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('slots-accessPlugin');
        return true;
    }

    public function actionIndex(): mixed
    {
        $staffEmployeeIds = Slots::getInstance()->getPermission()->getStaffEmployeeIds();
        $data = Slots::getInstance()->getDashboard()->getDashboardData($staffEmployeeIds);
        return $this->renderTemplate('slots/dashboard/index', $data);
    }
}
