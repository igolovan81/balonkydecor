<?php
namespace App\Controllers;

use App\Models\Database;
use App\Models\OrderModel;
use App\Services\GoPay;
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
                $this->notifyOrderPaid($orderNumber);
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
                            $this->notifyOrderPaid($order['order_number']);
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
                        $this->notifyOrderPaid($order['order_number']);
                    }
                }
            }
        }

        return $response->withStatus(200);
    }

    private function notifyOrderPaid(string $orderNumber): void
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

        $rows = '';
        foreach ($order['items'] as $item) {
            $subtype = $item['subtype_name_snapshot']
                ? ' — ' . htmlspecialchars($item['subtype_name_snapshot'])
                : '';
            $rows .= '<tr>'
                . '<td>' . htmlspecialchars($item['product_name_snapshot']) . $subtype . '</td>'
                . '<td>' . (int) $item['quantity'] . '</td>'
                . '<td>' . htmlspecialchars((string) $item['unit_price']) . ' Kč</td>'
                . '</tr>';
        }

        $html = '<p><strong>Order:</strong> ' . htmlspecialchars($order['order_number']) . '</p>'
              . '<p><strong>Customer:</strong> ' . htmlspecialchars($order['customer_name']) . '</p>'
              . '<p><strong>Email:</strong> ' . htmlspecialchars($order['customer_email']) . '</p>'
              . '<p><strong>Phone:</strong> ' . htmlspecialchars($order['customer_phone']) . '</p>'
              . ($order['pickup_date'] ? '<p><strong>Pickup date:</strong> ' . htmlspecialchars($order['pickup_date']) . '</p>' : '')
              . ($order['notes'] ? '<p><strong>Notes:</strong> ' . nl2br(htmlspecialchars($order['notes'])) . '</p>' : '')
              . '<table border="1" cellpadding="6" cellspacing="0"><thead><tr><th>Item</th><th>Qty</th><th>Unit price</th></tr></thead><tbody>'
              . $rows
              . '</tbody></table>'
              . '<p><strong>Total:</strong> ' . htmlspecialchars((string) $order['total_amount']) . ' Kč</p>';

        Mailer::send($contactEmail, "Paid order {$order['order_number']}", $html);
    }
}
