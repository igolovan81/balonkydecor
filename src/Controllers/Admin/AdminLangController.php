<?php
namespace App\Controllers\Admin;

use App\Models\AdminUserModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminLangController extends AdminBaseController
{
    private const SUPPORTED = ['cs', 'sk', 'en', 'uk', 'ru'];

    public function setLang(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $lang   = $request->getQueryParams()['l'] ?? '';
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);

        if (in_array($lang, self::SUPPORTED, true) && $userId > 0) {
            AdminUserModel::setLang($userId, $lang);
            $_SESSION['admin_lang'] = $lang;
        }

        $referer = $request->getServerParams()['HTTP_REFERER'] ?? '';
        $host    = $request->getServerParams()['HTTP_HOST'] ?? '';
        if (!$referer || ($host && !str_contains($referer, $host))) {
            $referer = '/admin';
        }
        return $this->redirect($response, $referer);
    }
}
