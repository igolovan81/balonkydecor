<?php
namespace App\Controllers\Admin;

use App\Models\CustomerModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CustomerController extends AdminBaseController
{
    public function restore(Request $request, Response $response, array $args): Response
    {
        $id       = (int) $args['id'];
        $customer = CustomerModel::findById($id);

        if ($customer && $customer['deleted_at'] !== null) {
            CustomerModel::restore($id);
            $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
            \App\Services\Notifier::notify(
                'customer', $id, $customer['email'], 'restored', $userId, $_SESSION['admin_user']['email'] ?? ''
            );
            $this->flash('success', 'notifications.flash.customer_restored');
        } else {
            $this->flash('error', 'notifications.flash.customer_restore_not_found');
        }

        return $this->redirect($response, '/admin/notifications');
    }
}
