<?php
namespace App\Services;

use App\Models\NotificationModel;

class Notifier
{
    public static function notify(
        string $entityType,
        int $entityId,
        string $entityLabel,
        string $action,
        int $actorId,
        string $actorLabel
    ): void {
        NotificationModel::create($entityType, $entityId, $entityLabel, $action, $actorId, $actorLabel);
    }
}
