<?php
namespace App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

abstract class AdminBaseController
{
    public function __construct(protected Twig $twig) {}

    protected function renderAdmin(Request $request, Response $response, string $template, array $data = []): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $flash = $this->getFlash();
        $i18n  = $request->getAttribute('admin_i18n');
        $env   = $this->twig->getEnvironment();
        if ($i18n && !$env->hasExtension(\App\Twig\I18nExtension::class)) {
            $env->addExtension(new \App\Twig\I18nExtension($i18n));
        }
        $userId      = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $unreadCount = $userId ? \App\Models\NotificationModel::unreadCount($userId) : 0;
        return $this->twig->render($response, $template, array_merge([
            'flash'                      => $flash,
            'session_role'               => $_SESSION['admin_user']['role']  ?? '',
            'session_email'              => $_SESSION['admin_user']['email'] ?? '',
            'admin_lang'                 => $request->getAttribute('admin_lang', 'cs'),
            'unread_notifications_count' => $unreadCount,
        ], $data));
    }

    protected function flash(string $type, string $message): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }

    protected function getFlash(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $flash;
    }

    protected function redirect(Response $response, string $url, int $status = 302): Response
    {
        return $response->withHeader('Location', $url)->withStatus($status);
    }

    protected function requireRole(Response $response, string $role): ?Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (($_SESSION['admin_user']['role'] ?? '') !== $role) {
            $this->flash('error', 'common.flash.forbidden');
            return $this->redirect($response, '/admin');
        }
        return null;
    }
}
