<?php
namespace App\Controllers\Admin;

use App\Models\AdminUserModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UserController extends AdminBaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        if ($redirect = $this->requireRole($response, 'admin')) return $redirect;
        $users = AdminUserModel::all();
        return $this->renderAdmin($request, $response, 'admin/users/index.twig', compact('users'));
    }

    public function createForm(Request $request, Response $response, array $args): Response
    {
        if ($redirect = $this->requireRole($response, 'admin')) return $redirect;
        return $this->renderAdmin($request, $response, 'admin/users/form.twig', ['user' => null]);
    }

    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        if ($redirect = $this->requireRole($response, 'admin')) return $redirect;
        $body  = (array) $request->getParsedBody();
        $email = trim($body['email'] ?? '');
        $pass  = $body['password'] ?? '';
        $role  = in_array($body['role'] ?? '', ['admin', 'editor']) ? $body['role'] : 'admin';
        if (!$email || strlen($pass) < 8) {
            $this->flash('error', 'users.flash.validation_required');
            return $this->redirect($response, '/admin/users/new');
        }
        AdminUserModel::create($email, password_hash($pass, PASSWORD_BCRYPT), $role);
        $this->flash('success', 'users.flash.created');
        return $this->redirect($response, '/admin/users');
    }

    public function changePassword(Request $request, Response $response, array $args): Response
    {
        if ($redirect = $this->requireRole($response, 'admin')) return $redirect;
        $body = (array) $request->getParsedBody();
        $pass = $body['password'] ?? '';
        if (strlen($pass) < 8) {
            $this->flash('error', 'users.flash.password_too_short');
            return $this->redirect($response, '/admin/users');
        }
        AdminUserModel::updatePassword((int) $args['id'], password_hash($pass, PASSWORD_BCRYPT));
        $this->flash('success', 'users.flash.password_changed');
        return $this->redirect($response, '/admin/users');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if ($redirect = $this->requireRole($response, 'admin')) return $redirect;
        if (session_status() === PHP_SESSION_NONE) session_start();
        $currentId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        if ((int) $args['id'] === $currentId) {
            $this->flash('error', 'users.flash.cannot_delete_self');
            return $this->redirect($response, '/admin/users');
        }
        AdminUserModel::delete((int) $args['id']);
        $this->flash('success', 'users.flash.deleted');
        return $this->redirect($response, '/admin/users');
    }
}
