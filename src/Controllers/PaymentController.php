<?php
namespace App\Controllers;

use App\Models\OrderModel;
use App\Services\GoPay;
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
            OrderModel::updateStatus($orderNumber, 'paid');
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
                        OrderModel::updateStatus($order['order_number'], 'paid', $paymentId);
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
                    if ($order) {
                        OrderModel::updateStatus($order['order_number'], 'paid', $paymentId);
                    }
                }
            }
        }

        return $response->withStatus(200);
    }
}
