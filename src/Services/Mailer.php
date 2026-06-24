<?php
namespace App\Services;

use App\Models\Database;

class Mailer
{
    public static function send(
        string $to,
        string $subject,
        string $body,
        string $replyTo = ''
    ): bool {
        $pdo   = Database::getConnection();
        $stmt  = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('smtp_from','site_name')");
        $cfg   = [];
        foreach ($stmt->fetchAll() as $row) {
            $cfg[$row['key']] = $row['value'];
        }

        $from     = $cfg['smtp_from'] ?? '';
        $siteName = $cfg['site_name'] ?? 'BalonkyDecor';

        if (empty($from)) {
            $log   = __DIR__ . '/../../tmp/mail.log';
            $entry = date('[Y-m-d H:i:s]') . " TO:{$to} SUBJECT:{$subject}\n{$body}\n\n";
            file_put_contents($log, $entry, FILE_APPEND);
            return true;
        }

        $headers  = "From: {$siteName} <{$from}>\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        if ($replyTo) {
            $headers .= "Reply-To: {$replyTo}\r\n";
        }

        return mail($to, $subject, $body, $headers);
    }
}
