<?php
namespace App\Controllers;

use App\Models\CustomerModel;
use App\Services\Mailer;
use App\Services\Seo;
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

    public function forgotForm(Request $request, Response $response, array $args): Response
    {
        return $this->render($request, $response, 'public/forgot-password.twig');
    }

    public function forgotSubmit(Request $request, Response $response, array $args): Response
    {
        $lang  = $request->getAttribute('lang');
        $body  = (array) $request->getParsedBody();
        $email = trim($body['email'] ?? '');

        $customer = $email !== '' ? CustomerModel::findByEmail($email) : null;
        if ($customer) {
            $token = bin2hex(random_bytes(32));
            CustomerModel::setResetToken((int) $customer['id'], $token, date('Y-m-d H:i:s', time() + 3600));

            $resetUrl = Seo::canonicalUrl($lang, '/reset-password') . '?token=' . $token;
            $html     = '<p>' . htmlspecialchars($customer['email']) . '</p>'
                      . '<p><a href="' . htmlspecialchars($resetUrl) . '">' . htmlspecialchars($resetUrl) . '</a></p>';
            Mailer::send($customer['email'], 'Password reset', $html);
        }

        return $this->render($request, $response, 'public/forgot-password.twig', [
            'success' => true,
        ]);
    }

    public function resetForm(Request $request, Response $response, array $args): Response
    {
        $token    = (string) ($request->getQueryParams()['token'] ?? '');
        $customer = $token !== '' ? CustomerModel::findByValidResetToken($token) : null;

        if (!$customer) {
            return $this->render($request, $response, 'public/reset-password.twig', [
                'error' => 'account.error_reset_token',
            ]);
        }

        return $this->render($request, $response, 'public/reset-password.twig', [
            'token' => $token,
        ]);
    }

    public function resetSubmit(Request $request, Response $response, array $args): Response
    {
        $lang            = $request->getAttribute('lang');
        $body            = (array) $request->getParsedBody();
        $token           = trim($body['token'] ?? '');
        $password        = $body['password'] ?? '';
        $passwordConfirm = $body['password_confirm'] ?? '';

        $customer = $token !== '' ? CustomerModel::findByValidResetToken($token) : null;
        if (!$customer) {
            return $this->render($request, $response, 'public/reset-password.twig', [
                'error' => 'account.error_reset_token',
            ]);
        }

        if (strlen($password) < 8 || $password !== $passwordConfirm) {
            return $this->render($request, $response, 'public/reset-password.twig', [
                'error' => 'account.error_password',
                'token' => $token,
            ]);
        }

        CustomerModel::updatePasswordAndClearToken((int) $customer['id'], password_hash($password, PASSWORD_BCRYPT));
        $this->flash('success', 'account.reset_success');
        return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
    }
}
