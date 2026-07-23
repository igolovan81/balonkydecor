<?php
namespace App\Controllers;

use App\Models\CustomerModel;
use App\Models\OrderModel;
use App\Services\I18n;
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
        $customer = $this->requireLogin($request);
        if (!$customer) {
            $lang = $request->getAttribute('lang');
            return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
        }

        return $this->render($request, $response, 'public/account/customer-info.twig', [
            'account' => $customer,
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $customer = $this->requireLogin($request);
        $lang     = $request->getAttribute('lang');
        if (!$customer) {
            return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
        }

        $body            = (array) $request->getParsedBody();
        $name            = trim($body['name'] ?? '');
        $phone           = trim($body['phone'] ?? '');
        $email           = trim($body['email'] ?? '');
        $currentPassword = $body['current_password'] ?? '';

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->render($request, $response, 'public/account/customer-info.twig', [
                'account' => array_merge($customer, ['name' => $name, 'phone' => $phone, 'email' => $email]),
                'error'   => 'account.error_invalid',
            ]);
        }

        if ($email !== $customer['email']) {
            if ($currentPassword === '' || !password_verify($currentPassword, $customer['password_hash'])) {
                return $this->render($request, $response, 'public/account/customer-info.twig', [
                    'account' => array_merge($customer, ['name' => $name, 'phone' => $phone, 'email' => $email]),
                    'error'   => 'account.error_current_password',
                ]);
            }

            $existing = CustomerModel::findByEmail($email);
            if ($existing && (int) $existing['id'] !== (int) $customer['id']) {
                return $this->render($request, $response, 'public/account/customer-info.twig', [
                    'account' => array_merge($customer, ['name' => $name, 'phone' => $phone, 'email' => $email]),
                    'error'   => 'account.error_email_taken',
                ]);
            }

            CustomerModel::updateEmail((int) $customer['id'], $email);
            $_SESSION['customer']['email'] = $email;
        }

        CustomerModel::updateProfile((int) $customer['id'], $name, $phone);
        $this->flash('success', 'account.update_success');
        return $response->withHeader('Location', "/{$lang}/account")->withStatus(302);
    }

    public function ordersList(Request $request, Response $response, array $args): Response
    {
        $customer = $this->requireLogin($request);
        if (!$customer) {
            $lang = $request->getAttribute('lang');
            return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
        }

        return $this->render($request, $response, 'public/account/orders.twig', [
            'orders' => OrderModel::forCustomer((int) $customer['id']),
        ]);
    }

    public function forgotForm(Request $request, Response $response, array $args): Response
    {
        return $this->render($request, $response, 'public/forgot-password.twig');
    }

    public function forgotSubmit(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');
        /** @var I18n $i18n */
        $i18n  = $request->getAttribute('i18n');
        $body  = (array) $request->getParsedBody();
        $email = trim($body['email'] ?? '');

        $customer = $email !== '' ? CustomerModel::findByEmail($email) : null;
        if ($customer) {
            $token = bin2hex(random_bytes(32));
            CustomerModel::setResetToken((int) $customer['id'], $token, date('Y-m-d H:i:s', time() + 3600));

            $resetUrl = Seo::canonicalUrl($lang, '/reset-password') . '?token=' . $token;
            $html     = $this->fetchEmail($request, 'emails/password-reset.twig', [
                't'         => ['intro' => $i18n->t('email.password_reset.intro')],
                'reset_url' => $resetUrl,
            ]);
            Mailer::send($customer['email'], $i18n->t('email.password_reset.subject'), $html);
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

    public function passwordForm(Request $request, Response $response, array $args): Response
    {
        $customer = $this->requireLogin($request);
        if (!$customer) {
            $lang = $request->getAttribute('lang');
            return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
        }

        return $this->render($request, $response, 'public/account/password.twig');
    }

    public function passwordSubmit(Request $request, Response $response, array $args): Response
    {
        $customer = $this->requireLogin($request);
        $lang     = $request->getAttribute('lang');
        if (!$customer) {
            return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
        }

        $body            = (array) $request->getParsedBody();
        $currentPassword = $body['current_password'] ?? '';
        $password        = $body['password'] ?? '';
        $passwordConfirm = $body['password_confirm'] ?? '';

        if ($currentPassword === '' || !password_verify($currentPassword, $customer['password_hash'])) {
            return $this->render($request, $response, 'public/account/password.twig', [
                'error' => 'account.error_current_password',
            ]);
        }

        if (strlen($password) < 8 || $password !== $passwordConfirm) {
            return $this->render($request, $response, 'public/account/password.twig', [
                'error' => 'account.error_password',
            ]);
        }

        CustomerModel::updatePasswordAndClearToken((int) $customer['id'], password_hash($password, PASSWORD_BCRYPT));
        $this->flash('success', 'account.password_success');
        return $response->withHeader('Location', "/{$lang}/account")->withStatus(302);
    }

    private function requireLogin(Request $request): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['customer'])) {
            return null;
        }
        $customer = CustomerModel::findById((int) $_SESSION['customer']['id']);
        if (!$customer) {
            unset($_SESSION['customer']);
            return null;
        }
        return $customer;
    }
}
