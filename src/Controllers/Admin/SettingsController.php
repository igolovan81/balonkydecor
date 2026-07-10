<?php
namespace App\Controllers\Admin;

use App\Models\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SettingsController extends AdminBaseController
{
    private const KEYS = [
        'site_name', 'contact_email', 'contact_phone',
        'shipping_address', 'shipping_map_url',
        'facebook_url', 'instagram_url', 'whatsapp_phone',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from',
        'gopay_go_id', 'gopay_client_id', 'gopay_client_secret', 'gopay_test_mode',
    ];

    public function index(Request $request, Response $response, array $args): Response
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->query("SELECT `key`, `value` FROM settings");
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['key']] = $row['value'];
        }
        return $this->renderAdmin($request, $response, 'admin/settings/index.twig', compact('settings'));
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        $pdo  = Database::getConnection();
        $body = (array) $request->getParsedBody();
        $stmt = $pdo->prepare(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );
        foreach (self::KEYS as $key) {
            $stmt->execute([$key, $body[$key] ?? '']);
        }
        $this->flash('success', 'settings.flash.updated');
        return $this->redirect($response, '/admin/settings');
    }
}
