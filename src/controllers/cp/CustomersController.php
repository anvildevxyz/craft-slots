<?php

namespace anvildev\slots\controllers\cp;

use anvildev\slots\services\CustomerService;
use anvildev\slots\Slots;
use Craft;
use craft\web\Controller;
use craft\web\Response;
use yii\web\NotFoundHttpException;

/**
 * The customer list and one customer's booking history.
 *
 * Gated on viewing bookings rather than a permission of its own: this shows the
 * same records the bookings index does, grouped differently, so a separate
 * permission would only create a way to grant one and not the other.
 */
class CustomersController extends Controller
{
    private const PER_PAGE = 50;

    public function init(): void
    {
        parent::init();
        $this->requirePermission('slots-viewBookings');
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();

        $search = trim((string)$request->getParam('search', ''));
        $sort = (string)$request->getParam('sort', 'lastBooking');
        $dir = $request->getParam('dir') === 'asc' ? 'asc' : 'desc';
        $page = max(1, (int)$request->getParam('page', 1));

        $result = $this->customers()->search(
            $search,
            $sort,
            $dir,
            self::PER_PAGE,
            ($page - 1) * self::PER_PAGE,
        );

        return $this->renderTemplate('slots/customers/_index', [
            'customers' => $result['customers'],
            'total' => $result['total'],
            'search' => $search,
            'sort' => in_array($sort, CustomerService::SORTABLE, true) ? $sort : 'lastBooking',
            'dir' => $dir,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'totalPages' => (int)ceil($result['total'] / self::PER_PAGE),
        ]);
    }

    /**
     * Yii has already percent-decoded the route parameter by the time it lands
     * here, so it must not be decoded again: `urldecode()` reads `+` as a space,
     * and `first+tag@example.com` would silently become a different address that
     * matches nobody. Plus-addressing is exactly what people book with.
     */
    public function actionDetail(?string $email = null): Response
    {
        $email = trim($email ?? (string)Craft::$app->getRequest()->getParam('email', ''));

        if ($email === '') {
            throw new NotFoundHttpException(Craft::t('slots', 'customers.notFound'));
        }

        $customer = $this->customers()->get($email);
        if (!$customer) {
            throw new NotFoundHttpException(Craft::t('slots', 'customers.notFound'));
        }

        return $this->renderTemplate('slots/customers/detail', [
            'customer' => $customer,
            'bookings' => $this->customers()->bookingsFor($email),
            'linkedUser' => $this->customers()->linkedUser($customer['userId'] ?? null),
        ]);
    }

    private function customers(): CustomerService
    {
        return Slots::getInstance()->getCustomers();
    }
}
