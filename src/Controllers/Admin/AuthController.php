<?php
namespace App\Controllers\Admin;

use App\Models\AdminUserModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController extends AdminBaseController
{
    public function loginForm(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (!empty($_SESSION['admin_user'])) {
            return $this->redirect($response, '/admin');
        }
        return $this->renderAdmin($request, $response, 'admin/login.twig');
    }

    public function loginSubmit(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $body     = (array) $request->getParsedBody();
        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        $user = AdminUserModel::findByEmail($email);
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['admin_user'] = ['id' => $user['id'], 'email' => $user['email'], 'role' => $user['role']];
            $_SESSION['admin_lang'] = AdminUserModel::getLang((int) $user['id']);
            return $this->redirect($response, '/admin');
        }

        return $this->renderAdmin($request, $response, 'admin/login.twig', ['error' => 'Nesprávný e-mail nebo heslo.']);
    }

    public function logout(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        session_destroy();
        return $this->redirect($response, '/admin/login');
    }

    public function setupForm(Request $request, Response $response, array $args): Response
    {
        if (AdminUserModel::count() > 0) {
            return $this->redirect($response, '/admin/login');
        }
        return $this->renderAdmin($request, $response, 'admin/setup.twig');
    }

    public function setupSubmit(Request $request, Response $response, array $args): Response
    {
        if (AdminUserModel::count() > 0) {
            return $this->redirect($response, '/admin/login');
        }
        $body  = (array) $request->getParsedBody();
        $email = trim($body['email'] ?? '');
        $pass  = $body['password'] ?? '';

        if (!$email || strlen($pass) < 8) {
            return $this->renderAdmin($request, $response, 'admin/setup.twig', ['error' => 'Vyplňte e-mail a heslo (min. 8 znaků).']);
        }

        $hash = password_hash($pass, PASSWORD_BCRYPT);
        AdminUserModel::create($email, $hash, 'admin');

        if (session_status() === PHP_SESSION_NONE) session_start();
        $user = AdminUserModel::findByEmail($email);
        $_SESSION['admin_user'] = ['id' => $user['id'], 'email' => $user['email'], 'role' => $user['role']];
        $_SESSION['admin_lang'] = 'cs';
        return $this->redirect($response, '/admin');
    }
}
