<?php
namespace App\Controllers;

use App\Models\Database;
use App\Services\Mailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ContactController extends BaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $pdo  = Database::getConnection();
        $rows = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('contact_email','contact_phone','contact_address')")
                    ->fetchAll();
        $info = array_column($rows, 'value', 'key');

        return $this->render($request, $response, 'public/contact.twig', [
            'success'       => false,
            'error'         => false,
            'contact_email' => $info['contact_email'] ?? '',
            'contact_phone' => $info['contact_phone'] ?? '',
            'contact_address' => $info['contact_address'] ?? '',
        ]);
    }

    public function send(Request $request, Response $response, array $args): Response
    {
        $lang    = $request->getAttribute('lang');
        $body    = (array) $request->getParsedBody();
        $name    = trim($body['name']    ?? '');
        $email   = trim($body['email']   ?? '');
        $message = trim($body['message'] ?? '');

        $pdo     = Database::getConnection();
        $rows    = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('contact_email','contact_phone','contact_address')")
                       ->fetchAll();
        $info    = array_column($rows, 'value', 'key');
        $adminTo = ($info['contact_email'] ?? '') ?: 'admin@balonkydecor.cz';

        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$message) {
            return $this->render($request, $response, 'public/contact.twig', [
                'success'         => false,
                'error'           => true,
                'values'          => ['name' => $name, 'email' => $email, 'message' => $message],
                'contact_email'   => $info['contact_email'] ?? '',
                'contact_phone'   => $info['contact_phone'] ?? '',
                'contact_address' => $info['contact_address'] ?? '',
            ]);
        }

        $html = "<p><strong>Name:</strong> " . htmlspecialchars($name) . "</p>"
              . "<p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>"
              . "<p><strong>Message:</strong></p>"
              . "<p>" . nl2br(htmlspecialchars($message)) . "</p>";

        Mailer::send($adminTo, "Contact form: {$name}", $html, $email);

        return $this->render($request, $response, 'public/contact.twig', [
            'success' => true,
            'error'   => false,
        ]);
    }
}
