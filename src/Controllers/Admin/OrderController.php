<?php
namespace App\Controllers\Admin;

use App\Models\CustomerModel;
use App\Models\OrderModel;
use App\Services\I18n;
use App\Services\Mailer;
use App\Services\Seo;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class OrderController extends AdminBaseController
{
    private const STATUSES = ['pending', 'paid', 'ready', 'completed', 'cancelled'];

    public function index(Request $request, Response $response, array $args): Response
    {
        $params = $request->getQueryParams();
        $page   = max(1, (int) ($params['page'] ?? 1));
        $status = $params['status'] ?? '';

        $data = OrderModel::adminList($page, 20, $status);
        return $this->renderAdmin($request, $response, 'admin/orders/index.twig', [
            'orders'   => $data['orders'],
            'pages'    => $data['pages'],
            'page'     => $page,
            'status'   => $status,
            'statuses' => self::STATUSES,
        ]);
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        $order = OrderModel::findByNumber($args['number']);
        if (!$order) return $response->withStatus(404);
        return $this->renderAdmin($request, $response, 'admin/orders/detail.twig', [
            'order'    => $order,
            'statuses' => self::STATUSES,
        ]);
    }

    public function updateStatus(Request $request, Response $response, array $args): Response
    {
        $body   = (array) $request->getParsedBody();
        $status = $body['status'] ?? '';
        if (in_array($status, self::STATUSES, true)) {
            $order = OrderModel::findByNumber($args['number']);
            if ($order && $order['status'] !== $status) {
                OrderModel::updateStatus($args['number'], $status);
                $this->notifyStatusChanged($request, $order, $status);
            }
            $this->flash('success', 'orders.flash.status_changed');
        }
        return $this->redirect($response, '/admin/orders/' . $args['number']);
    }

    private function notifyStatusChanged(Request $request, array $order, string $newStatus): void
    {
        $notificationLang = 'cs';
        if (!empty($order['customer_id'])) {
            $customer = CustomerModel::findById((int) $order['customer_id']);
            if ($customer && !empty($customer['notification_lang'])) {
                $notificationLang = $customer['notification_lang'];
            }
        }

        $i18n = new I18n($notificationLang, __DIR__ . '/../../../lang');
        $html = $this->fetchEmail($request, 'emails/order-status-changed.twig', [
            't' => [
                'intro'  => $i18n->t('email.order_status_changed.intro'),
                'order'  => $i18n->t('order.title'),
                'status' => $i18n->t('email.order_status_changed.status'),
            ],
            'order'        => $order,
            'status_label' => $i18n->t('order.status.' . $newStatus),
            'order_url'    => Seo::canonicalUrl($notificationLang, '/order/' . $order['order_number']),
        ]);
        $subject = $i18n->t('email.order_status_changed.subject', ['number' => $order['order_number']]);

        Mailer::send($order['customer_email'], $subject, $html);
    }
}
