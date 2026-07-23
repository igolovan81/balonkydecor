<?php
namespace App\Controllers;

use App\Models\Database;
use App\Models\PageModel;
use App\Services\I18n;
use App\Services\Mailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ContactController extends BaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');
        return $this->render($request, $response, 'public/contact.twig', [
            'success' => false,
            'error'   => false,
            'page'    => PageModel::find('contact', $lang),
        ]);
    }

    public function send(Request $request, Response $response, array $args): Response
    {
        $lang    = $request->getAttribute('lang');
        $body    = (array) $request->getParsedBody();
        $name    = trim($body['name']    ?? '');
        $email   = trim($body['email']   ?? '');
        $message = trim($body['message'] ?? '');
        $page    = PageModel::find('contact', $lang);

        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$message) {
            return $this->render($request, $response, 'public/contact.twig', [
                'success' => false,
                'error'   => true,
                'values'  => ['name' => $name, 'email' => $email, 'message' => $message],
                'page'    => $page,
            ]);
        }

        $pdo     = Database::getConnection();
        $setting = $pdo->query("SELECT value FROM settings WHERE `key`='contact_email'")->fetchColumn();
        $adminTo = $setting ?: 'admin@balonkydecor.cz';

        $i18n = new I18n('cs', __DIR__ . '/../../lang');
        $html = $this->fetchEmail($request, 'emails/contact-notification.twig', [
            't' => [
                'name'    => $i18n->t('contact.name'),
                'email'   => $i18n->t('contact.email'),
                'message' => $i18n->t('contact.message'),
            ],
            'name'    => $name,
            'email'   => $email,
            'message' => $message,
        ]);
        $subject = $i18n->t('email.contact.subject', ['name' => $name]);

        Mailer::send($adminTo, $subject, $html, $email);

        return $this->render($request, $response, 'public/contact.twig', [
            'success' => true,
            'error'   => false,
            'page'    => $page,
        ]);
    }
}
