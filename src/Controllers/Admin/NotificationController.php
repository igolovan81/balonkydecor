<?php
namespace App\Controllers\Admin;

use App\Models\NotificationModel;
use App\Services\I18n;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class NotificationController extends AdminBaseController
{
    private const ENTITY_URL_SEGMENT = [
        'category' => 'categories',
        'product'  => 'products',
        'service'  => 'services',
    ];

    public function index(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $page   = max(1, (int) ($request->getQueryParams()['page'] ?? 1));
        $data   = NotificationModel::forUser($userId, $page, 20);
        $i18n   = $request->getAttribute('admin_i18n');

        $notifications = array_map(fn(array $row) => $this->formatNotification($row, $i18n), $data['items']);

        return $this->renderAdmin($request, $response, 'admin/notifications/index.twig', [
            'notifications' => $notifications,
            'page'          => $page,
            'pages'         => $data['pages'],
        ]);
    }

    public function unreadCount(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $response->getBody()->write(json_encode(['count' => NotificationModel::unreadCount($userId)]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function open(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $i18n   = $request->getAttribute('admin_i18n');
        $rows   = NotificationModel::recentAndMarkRead($userId, 20);

        $items = array_map(fn(array $row) => $this->formatNotification($row, $i18n), $rows);

        $response->getBody()->write(json_encode(['items' => $items]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function formatNotification(array $row, ?I18n $i18n): array
    {
        $key     = "notifications.msg.{$row['entity_type']}_{$row['action']}";
        $message = $i18n ? $i18n->t($key, ['actor' => $row['actor_label'], 'label' => $row['entity_label']]) : $key;

        $url = null;
        if ($row['action'] !== 'deleted' && isset(self::ENTITY_URL_SEGMENT[$row['entity_type']])) {
            $url = '/admin/' . self::ENTITY_URL_SEGMENT[$row['entity_type']] . '/' . $row['entity_id'] . '/edit';
        }

        return [
            'id'         => (int) $row['id'],
            'message'    => $message,
            'url'        => $url,
            'created_at' => $row['created_at'],
        ];
    }
}
