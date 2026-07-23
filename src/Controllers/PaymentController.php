<?php
namespace App\Controllers;

use App\Models\Database;
use App\Models\OrderModel;
use App\Services\GoPay;
use App\Services\I18n;
use App\Services\Mailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PaymentController extends BaseController
{
    public function initiate(Request $request, Response $response, array $args): Response
    {
        $lang        = $request->getAttribute('lang');
        $body        = (array) $request->getParsedBody();
        $orderNumber = trim($body['order_number'] ?? '');

        if (!$orderNumber) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $orderNumber = $_SESSION['pending_order'] ?? '';
        }

        $order = $orderNumber ? OrderModel::findByNumber($orderNumber) : null;
        if (!$order) {
            return $response->withHeader('Location', "/{$lang}/")->withStatus(302);
        }

        $gopay = GoPay::fromSettings();
        if (!$gopay) {
            if ($order['status'] !== 'paid') {
                OrderModel::updateStatus($orderNumber, 'paid');
                $this->notifyOrderPaid($request, $orderNumber);
            }
            return $response
                ->withHeader('Location', "/{$lang}/order/{$orderNumber}")
                ->withStatus(302);
        }

        $uri       = $request->getUri();
        $base      = $uri->getScheme() . '://' . $uri->getHost();
        $returnUrl = "{$base}/{$lang}/payment/return";
        $notifyUrl = "{$base}/payment/notify";

        $payment = $gopay->createPayment($order, $returnUrl, $notifyUrl);
        if (empty($payment['gw_url'])) {
            return $response
                ->withHeader('Location', "/{$lang}/order/{$orderNumber}")
                ->withStatus(302);
        }

        OrderModel::updateStatus($orderNumber, 'pending', $payment['payment_id']);
        return $response->withHeader('Location', $payment['gw_url'])->withStatus(302);
    }

    public function paymentReturn(Request $request, Response $response, array $args): Response
    {
        $lang      = $request->getAttribute('lang');
        $params    = $request->getQueryParams();
        $paymentId = $params['id'] ?? '';

        if ($paymentId) {
            $gopay = GoPay::fromSettings();
            if ($gopay) {
                $status = $gopay->getStatus($paymentId);
                if (($status['state'] ?? '') === 'PAID') {
                    $order = OrderModel::findByGopayId($paymentId);
                    if ($order) {
                        if ($order['status'] !== 'paid') {
                            OrderModel::updateStatus($order['order_number'], 'paid', $paymentId);
                            $this->notifyOrderPaid($request, $order['order_number']);
                        }
                        return $response
                            ->withHeader('Location', "/{$lang}/order/{$order['order_number']}")
                            ->withStatus(302);
                    }
                }
            }
        }

        return $response->withHeader('Location', "/{$lang}/")->withStatus(302);
    }

    public function notify(Request $request, Response $response, array $args): Response
    {
        $body      = (string) $request->getBody();
        $data      = json_decode($body, true) ?? [];
        $paymentId = (string) ($data['id'] ?? '');

        if ($paymentId) {
            $gopay = GoPay::fromSettings();
            if ($gopay) {
                $status = $gopay->getStatus($paymentId);
                if (($status['state'] ?? '') === 'PAID') {
                    $order = OrderModel::findByGopayId($paymentId);
                    if ($order && $order['status'] !== 'paid') {
                        OrderModel::updateStatus($order['order_number'], 'paid', $paymentId);
                        $this->notifyOrderPaid($request, $order['order_number']);
                    }
                }
            }
        }

        return $response->withStatus(200);
    }

    private function notifyOrderPaid(Request $request, string $orderNumber): void
    {
        $order = OrderModel::findByNumber($orderNumber);
        if (!$order) {
            return;
        }

        $pdo          = Database::getConnection();
        $contactEmail = $pdo->query("SELECT value FROM settings WHERE `key`='contact_email'")->fetchColumn();
        if (!$contactEmail) {
            return;
        }

        $i18n = new I18n('cs', __DIR__ . '/../../lang');
        $html = $this->fetchEmail($request, 'emails/order-paid.twig', [
            't' => [
                'order'       => $i18n->t('order.title'),
                'customer'    => $i18n->t('email.order_paid.customer'),
                'email'       => $i18n->t('account.email'),
                'phone'       => $i18n->t('checkout.phone'),
                'pickup_date' => $i18n->t('checkout.pickup_date'),
                'notes'       => $i18n->t('checkout.notes'),
                'item'        => $i18n->t('order.product'),
                'qty'         => $i18n->t('order.qty'),
                'unit_price'  => $i18n->t('order.unit_price'),
                'total'       => $i18n->t('order.total'),
            ],
            'order' => $order,
        ]);
        $subject = $i18n->t('email.order_paid.subject', ['number' => $order['order_number']]);

        Mailer::send($contactEmail, $subject, $html, $order['customer_email']);
    }
}
