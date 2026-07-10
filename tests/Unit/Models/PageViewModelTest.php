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

    public function test_classify_device_detects_ipad_as_tablet_ios(): void
    {
        $ua = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15';
        $this->assertSame('tablet-ios', PageViewModel::classifyDevice($ua));
    }

    public function test_classify_device_detects_iphone_as_mobile_ios(): void
    {
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15';
        $this->assertSame('mobile-ios', PageViewModel::classifyDevice($ua));
    }

    public function test_classify_device_detects_android_phone_as_mobile_android(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 Mobile Safari/537.36';
        $this->assertSame('mobile-android', PageViewModel::classifyDevice($ua));
    }

    public function test_classify_device_detects_android_tablet_as_tablet_android(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 13; SM-X200) AppleWebKit/537.36 Safari/537.36';
        $this->assertSame('tablet-android', PageViewModel::classifyDevice($ua));
    }

    public function test_classify_device_detects_desktop(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0 Safari/537.36';
        $this->assertSame('desktop', PageViewModel::classifyDevice($ua));
    }

    public function test_classify_device_returns_other_for_empty_user_agent(): void
    {
        $this->assertSame('other', PageViewModel::classifyDevice(null));
        $this->assertSame('other', PageViewModel::classifyDevice(''));
    }

    public function test_classify_browser_detects_edge(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0 Safari/537.36 Edg/128.0';
        $this->assertSame('edge', PageViewModel::classifyBrowser($ua));
    }

    public function test_classify_browser_detects_opera(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0 Safari/537.36 OPR/114.0';
        $this->assertSame('opera', PageViewModel::classifyBrowser($ua));
    }

    public function test_classify_browser_detects_samsung_internet(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 13; SM-S911B) AppleWebKit/537.36 SamsungBrowser/24.0 Chrome/115.0 Mobile Safari/537.36';
        $this->assertSame('samsung', PageViewModel::classifyBrowser($ua));
    }

    public function test_classify_browser_detects_firefox(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0';
        $this->assertSame('firefox', PageViewModel::classifyBrowser($ua));
    }

    public function test_classify_browser_detects_chrome(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0 Safari/537.36';
        $this->assertSame('chrome', PageViewModel::classifyBrowser($ua));
    }

    public function test_classify_browser_detects_safari(): void
    {
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15';
        $this->assertSame('safari', PageViewModel::classifyBrowser($ua));
    }

    public function test_classify_browser_detects_ie(): void
    {
        $ua = 'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Trident/5.0)';
        $this->assertSame('ie', PageViewModel::classifyBrowser($ua));
    }

    public function test_classify_browser_returns_other_for_empty_user_agent(): void
    {
        $this->assertSame('other', PageViewModel::classifyBrowser(null));
        $this->assertSame('other', PageViewModel::classifyBrowser(''));
    }

    public function test_record_persists_device_type_and_browser(): void
    {
        $path = '/cs/device-test-' . uniqid();
        PageViewModel::record($path, 'cs', null, '1.2.3.0', 'TestAgent/1.0', 'mobile-android', 'chrome');

        $stmt = Database::getConnection()->prepare('SELECT device_type, browser FROM page_views WHERE path = ?');
        $stmt->execute([$path]);
        $row = $stmt->fetch();

        $this->assertSame('mobile-android', $row['device_type']);
        $this->assertSame('chrome', $row['browser']);
    }

    public function test_device_breakdown_groups_and_orders_by_views(): void
    {
        $pdo   = Database::getConnection();
        $pathA = '/cs/breakdown-test-' . uniqid();
        $pathB = '/cs/breakdown-test-' . uniqid();
        foreach (range(1, 3) as $i) {
            $pdo->prepare("INSERT INTO page_views (path, lang, device_type, created_at) VALUES (?, 'cs', 'desktop', NOW())")->execute([$pathA]);
        }
        $pdo->prepare("INSERT INTO page_views (path, lang, device_type, created_at) VALUES (?, 'cs', 'mobile-android', NOW())")->execute([$pathB]);

        $from = date('Y-m-d H:i:s', strtotime('-1 minute'));
        $to   = date('Y-m-d H:i:s', strtotime('+1 minute'));

        $breakdown = PageViewModel::deviceBreakdown($from, $to);
        $types     = array_column($breakdown, 'device_type');

        $this->assertContains('desktop', $types);
        $this->assertContains('mobile-android', $types);
        $this->assertLessThanOrEqual(array_search('mobile-android', $types), array_search('desktop', $types));
    }
}
