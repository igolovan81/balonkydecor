<?php
namespace App\Controllers\Admin;

use App\Models\AdminUserModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UserController extends AdminBaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $users = AdminUserModel::all();
        return $this->renderAdmin($request, $response, 'admin/users/index.twig', compact('users'));
    }

    public function createForm(Request $request, Response $response, array $args): Response
    {
        return $this->renderAdmin($request, $response, 'admin/users/form.twig', ['user' => null]);
    }

    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        $body  = (array) $request->getParsedBody();
        $email = trim($body['email'] ?? '');
        $pass  = $body['password'] ?? '';
        $role  = in_array($body['role'] ?? '', ['admin', 'editor']) ? $body['role'] : 'admin';
        if (!$email || strlen($pass) < 8) {
            $this->flash('error', 'Vyplňte e-mail a heslo (min. 8 znaků).');
            return $this->redirect($response, '/admin/users/new');
        }
        AdminUserModel::create($email, password_hash($pass, PASSWORD_BCRYPT), $role);
        $this->flash('success', 'Uživatel vytvořen.');
        return $this->redirect($response, '/admin/users');
    }

    public function changePassword(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $pass = $body['password'] ?? '';
        if (strlen($pass) < 8) {
            $this->flash('error', 'Heslo musí mít alespoň 8 znaků.');
            return $this->redirect($response, '/admin/users');
        }
        AdminUserModel::updatePassword((int) $args['id'], password_hash($pass, PASSWORD_BCRYPT));
        $this->flash('success', 'Heslo změněno.');
        return $this->redirect($response, '/admin/users');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $currentId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        if ((int) $args['id'] === $currentId) {
            $this->flash('error', 'Nemůžete smazat vlastní účet.');
            return $this->redirect($response, '/admin/users');
        }
        AdminUserModel::delete((int) $args['id']);
        $this->flash('success', 'Uživatel smazán.');
        return $this->redirect($response, '/admin/users');
    }
}
