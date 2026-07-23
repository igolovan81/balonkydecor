<?php
namespace App\Controllers;

use App\Models\CustomerModel;
use App\Models\OrderModel;
use App\Services\Cart;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CheckoutController extends BaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');

        if (Cart::isEmpty()) {
            return $response->withHeader('Location', "/{$lang}/cart")->withStatus(302);
        }

        $values = [];
        if (!empty($_SESSION['customer'])) {
            $customer = CustomerModel::findById((int) $_SESSION['customer']['id']);
            if ($customer) {
                $values = [
                    'customer_name'  => $customer['name']  ?? '',
                    'customer_email' => $customer['email'] ?? '',
                    'customer_phone' => $customer['phone'] ?? '',
                ];
            }
        }

        return $this->render($request, $response, 'public/checkout/index.twig', [
            'items'  => Cart::items(),
            'total'  => Cart::total(),
            'error'  => false,
            'values' => $values,
        ]);
    }

    public function submit(Request $request, Response $response, array $args): Response
    {
        $lang  = $request->getAttribute('lang');
        $body  = (array) $request->getParsedBody();
        $name  = trim($body['customer_name']  ?? '');
        $email = trim($body['customer_email'] ?? '');
        $phone = trim($body['customer_phone'] ?? '');
        $date  = trim($body['pickup_date']    ?? '');
        $notes = trim($body['notes']          ?? '');

        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$phone) {
            return $this->render($request, $response, 'public/checkout/index.twig', [
                'items'  => Cart::items(),
                'total'  => Cart::total(),
                'error'  => true,
                'values' => $body,
            ]);
        }

        if (Cart::isEmpty()) {
            return $response->withHeader('Location', "/{$lang}/cart")->withStatus(302);
        }

        $orderNumber = OrderModel::create(
            [
                'customer_name'  => $name,
                'customer_email' => $email,
                'customer_phone' => $phone,
                'pickup_date'    => $date,
                'notes'          => $notes,
            ],
            Cart::items(),
            Cart::total(),
            $_SESSION['customer']['id'] ?? null
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['pending_order'] = $orderNumber;
        Cart::clear();

        return $response->withHeader('Location', "/{$lang}/checkout/confirm")->withStatus(302);
    }

    public function confirm(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $orderNumber = $_SESSION['pending_order'] ?? null;
        if (!$orderNumber) {
            return $response->withHeader('Location', "/{$lang}/")->withStatus(302);
        }
        $order = OrderModel::findByNumber($orderNumber);
        if (!$order) {
            return $response->withHeader('Location', "/{$lang}/")->withStatus(302);
        }
        return $this->render($request, $response, 'public/checkout/confirm.twig', [
            'order' => $order,
        ]);
    }
}
