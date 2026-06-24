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
        return $this->twig->render($response, $template, array_merge(['flash' => $flash], $data));
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
}
