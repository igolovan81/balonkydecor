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
        return $this->render($request, $response, 'public/contact.twig', [
            'success' => false,
            'error'   => false,
        ]);
    }

    public function send(Request $request, Response $response, array $args): Response
    {
        $lang    = $request->getAttribute('lang');
        $body    = (array) $request->getParsedBody();
        $name    = trim($body['name']    ?? '');
        $email   = trim($body['email']   ?? '');
        $message = trim($body['message'] ?? '');

        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$message) {
            return $this->render($request, $response, 'public/contact.twig', [
                'success' => false,
                'error'   => true,
                'values'  => ['name' => $name, 'email' => $email, 'message' => $message],
            ]);
        }

        $pdo     = Database::getConnection();
        $setting = $pdo->query("SELECT value FROM settings WHERE `key`='contact_email'")->fetchColumn();
        $adminTo = $setting ?: 'admin@balonkydecor.cz';

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
