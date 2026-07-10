<?php
namespace Tests\Unit\Models;

use App\Models\PageViewModel;
use App\Models\Database;
use PHPUnit\Framework\TestCase;

class PageViewModelTest extends TestCase
{
    public function test_anonymize_ip_zeroes_last_ipv4_octet(): void
    {
        $this->assertSame('89.24.130.0', PageViewModel::anonymizeIp('89.24.130.57'));
    }

    public function test_anonymize_ip_zeroes_last_ipv6_hextet(): void
    {
        $this->assertSame(
            '2001:db8:85a3:0:0:8a2e:370:0',
            PageViewModel::anonymizeIp('2001:db8:85a3:0:0:8a2e:370:7334')
        );
    }

    public function test_record_persists_row(): void
    {
        $path = '/cs/audit-test-' . uniqid();
        PageViewModel::record($path, 'cs', 'https://example.com', '1.2.3.0', 'TestAgent/1.0');

        $stmt = Database::getConnection()->prepare('SELECT * FROM page_views WHERE path = ?');
        $stmt->execute([$path]);
        $row = $stmt->fetch();

        $this->assertSame('cs', $row['lang']);
        $this->assertSame('https://example.com', $row['referrer']);
        $this->assertSame('1.2.3.0', $row['ip_anon']);
        $this->assertSame('TestAgent/1.0', $row['user_agent']);
    }

    public function test_summary_counts_views_and_unique_visitors_in_range(): void
    {
        $pdo  = Database::getConnection();
        $path = '/cs/summary-test-' . uniqid();
        $pdo->prepare("INSERT INTO page_views (path, lang, ip_anon, created_at) VALUES (?, 'cs', '10.0.0.0', NOW())")->execute([$path]);
        $pdo->prepare("INSERT INTO page_views (path, lang, ip_anon, created_at) VALUES (?, 'cs', '10.0.0.0', NOW())")->execute([$path]);
        $pdo->prepare("INSERT INTO page_views (path, lang, ip_anon, created_at) VALUES (?, 'cs', '10.0.0.1', NOW())")->execute([$path]);

        $from = date('Y-m-d H:i:s', strtotime('-1 minute'));
        $to   = date('Y-m-d H:i:s', strtotime('+1 minute'));

        $summary = PageViewModel::summary($from, $to);

        $this->assertGreaterThanOrEqual(3, $summary['total_views']);
        $this->assertGreaterThanOrEqual(2, $summary['unique_visitors']);
    }

    public function test_top_pages_orders_by_view_count_descending(): void
    {
        $pdo     = Database::getConnection();
        $popular = '/cs/top-test-popular-' . uniqid();
        $quiet   = '/cs/top-test-quiet-' . uniqid();
        foreach (range(1, 3) as $i) {
            $pdo->prepare("INSERT INTO page_views (path, lang, created_at) VALUES (?, 'cs', NOW())")->execute([$popular]);
        }
        $pdo->prepare("INSERT INTO page_views (path, lang, created_at) VALUES (?, 'cs', NOW())")->execute([$quiet]);

        $from = date('Y-m-d H:i:s', strtotime('-1 minute'));
        $to   = date('Y-m-d H:i:s', strtotime('+1 minute'));

        $data  = PageViewModel::topPages($from, $to, 1, 100);
        $paths = array_column($data['rows'], 'path');

        $this->assertLessThan(array_search($quiet, $paths), array_search($popular, $paths));
    }

    public function test_top_pages_paginates(): void
    {
        $data = PageViewModel::topPages('2000-01-01', '2100-01-01', 1, 1);
        $this->assertCount(1, $data['rows']);
        $this->assertGreaterThanOrEqual(1, $data['pages']);
    }

    public function test_prune_older_than_deletes_old_rows_but_keeps_recent(): void
    {
        $pdo    = Database::getConnection();
        $old    = '/cs/prune-test-old-' . uniqid();
        $recent = '/cs/prune-test-recent-' . uniqid();
        $pdo->prepare("INSERT INTO page_views (path, lang, created_at) VALUES (?, 'cs', NOW() - INTERVAL 100 DAY)")->execute([$old]);
        $pdo->prepare("INSERT INTO page_views (path, lang, created_at) VALUES (?, 'cs', NOW())")->execute([$recent]);

        PageViewModel::pruneOlderThan(90);

        $oldStmt = $pdo->prepare('SELECT COUNT(*) FROM page_views WHERE path = ?');
        $oldStmt->execute([$old]);
        $this->assertSame(0, (int) $oldStmt->fetchColumn());

        $recentStmt = $pdo->prepare('SELECT COUNT(*) FROM page_views WHERE path = ?');
        $recentStmt->execute([$recent]);
        $this->assertSame(1, (int) $recentStmt->fetchColumn());
    }
}
