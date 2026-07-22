<?php
namespace App\Controllers;

use App\Models\CustomerModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AccountController extends BaseController
{
    public function registerForm(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $lang = $request->getAttribute('lang');
        if (!empty($_SESSION['customer'])) {
            return $response->withHeader('Location', "/{$lang}/account")->withStatus(302);
        }
        return $this->render($request, $response, 'public/register.twig');
    }

    public function registerSubmit(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $lang            = $request->getAttribute('lang');
        $body            = (array) $request->getParsedBody();
        $email           = trim($body['email'] ?? '');
        $password        = $body['password'] ?? '';
        $passwordConfirm = $body['password_confirm'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || $password !== $passwordConfirm) {
            return $this->render($request, $response, 'public/register.twig', [
                'error' => 'account.error_password',
                'email' => $email,
            ]);
        }

        if (CustomerModel::findByEmail($email)) {
            return $this->render($request, $response, 'public/register.twig', [
                'error' => 'account.error_email_taken',
                'email' => $email,
            ]);
        }

        $customerId = CustomerModel::create($email, password_hash($password, PASSWORD_BCRYPT));
        $_SESSION['customer'] = ['id' => $customerId, 'email' => $email];

        return $response->withHeader('Location', "/{$lang}/account")->withStatus(302);
    }

    public function loginForm(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $lang = $request->getAttribute('lang');
        if (!empty($_SESSION['customer'])) {
            return $response->withHeader('Location', "/{$lang}/account")->withStatus(302);
        }
        return $this->render($request, $response, 'public/login.twig');
    }

    public function loginSubmit(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $lang     = $request->getAttribute('lang');
        $body     = (array) $request->getParsedBody();
        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        $customer = CustomerModel::findByEmail($email);
        if (!$customer || !password_verify($password, $customer['password_hash'])) {
            return $this->render($request, $response, 'public/login.twig', [
                'error' => 'account.error_login',
                'email' => $email,
            ]);
        }

        $_SESSION['customer'] = ['id' => (int) $customer['id'], 'email' => $customer['email']];
        return $response->withHeader('Location', "/{$lang}/account")->withStatus(302);
    }

    public function logout(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $lang = $request->getAttribute('lang');
        unset($_SESSION['customer']);
        return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
    }

    public function index(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $lang = $request->getAttribute('lang');
        if (empty($_SESSION['customer'])) {
            return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
        }

        $customer = CustomerModel::findById((int) $_SESSION['customer']['id']);
        if (!$customer) {
            unset($_SESSION['customer']);
            return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
        }

        return $this->render($request, $response, 'public/account.twig', [
            'account' => $customer,
        ]);
    }
}
