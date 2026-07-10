<?php
namespace App\Models;

class PageViewModel
{
    public static function anonymizeIp(string $ip): string
    {
        if (strpos($ip, ':') !== false) {
            $parts = explode(':', $ip);
            if (count($parts) > 1) {
                $parts[count($parts) - 1] = '0';
                return implode(':', $parts);
            }
            return $ip;
        }

        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            $parts[3] = '0';
            return implode('.', $parts);
        }

        return $ip;
    }

    public static function record(string $path, string $lang, ?string $referrer, ?string $ipAnon, ?string $userAgent, string $deviceType = 'other', string $browser = 'other'): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO page_views (path, lang, referrer, ip_anon, user_agent, device_type, browser) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$path, $lang, $referrer, $ipAnon, $userAgent, $deviceType, $browser]);
    }

    public static function classifyDevice(?string $userAgent): string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return 'other';
        }
        if (stripos($userAgent, 'iPad') !== false) {
            return 'tablet-ios';
        }
        if (stripos($userAgent, 'iPhone') !== false || stripos($userAgent, 'iPod') !== false) {
            return 'mobile-ios';
        }
        if (stripos($userAgent, 'Android') !== false) {
            return stripos($userAgent, 'Mobile') !== false ? 'mobile-android' : 'tablet-android';
        }
        return 'desktop';
    }

    public static function classifyBrowser(?string $userAgent): string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return 'other';
        }
        if (stripos($userAgent, 'Edg/') !== false || stripos($userAgent, 'Edge/') !== false) {
            return 'edge';
        }
        if (stripos($userAgent, 'OPR/') !== false || stripos($userAgent, 'Opera') !== false) {
            return 'opera';
        }
        if (stripos($userAgent, 'SamsungBrowser') !== false) {
            return 'samsung';
        }
        if (stripos($userAgent, 'Firefox') !== false) {
            return 'firefox';
        }
        if (stripos($userAgent, 'Chrome') !== false || stripos($userAgent, 'CriOS') !== false) {
            return 'chrome';
        }
        if (stripos($userAgent, 'Safari') !== false) {
            return 'safari';
        }
        if (stripos($userAgent, 'MSIE') !== false || stripos($userAgent, 'Trident') !== false) {
            return 'ie';
        }
        return 'other';
    }

    public static function summary(string $from, string $to): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS total_views,
                    COUNT(DISTINCT CONCAT(ip_anon, "|", DATE(created_at))) AS unique_visitors
             FROM page_views WHERE created_at BETWEEN :from AND :to'
        );
        $stmt->execute(['from' => $from, 'to' => $to]);
        $row = $stmt->fetch();

        return [
            'total_views'     => (int) $row['total_views'],
            'unique_visitors' => (int) $row['unique_visitors'],
        ];
    }

    public static function topPages(string $from, string $to, int $page, int $perPage): array
    {
        $pdo = Database::getConnection();

        $totalStmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT path) FROM page_views WHERE created_at BETWEEN :from AND :to'
        );
        $totalStmt->execute(['from' => $from, 'to' => $to]);
        $total = (int) $totalStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt   = $pdo->prepare(
            'SELECT path, COUNT(*) AS views
             FROM page_views
             WHERE created_at BETWEEN :from AND :to
             GROUP BY path
             ORDER BY views DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':from', $from);
        $stmt->bindValue(':to', $to);
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows'  => $stmt->fetchAll(),
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public static function deviceBreakdown(string $from, string $to): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT device_type, COUNT(*) AS views
             FROM page_views
             WHERE created_at BETWEEN :from AND :to
             GROUP BY device_type
             ORDER BY views DESC'
        );
        $stmt->execute(['from' => $from, 'to' => $to]);
        return $stmt->fetchAll();
    }

    public static function pruneOlderThan(int $days): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM page_views WHERE created_at < (NOW() - INTERVAL :days DAY)');
        $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }
}
