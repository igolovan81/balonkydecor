<?php
namespace Tests\Unit\Models;

use App\Models\AdminUserModel;
use PHPUnit\Framework\TestCase;

class AdminUserModelTest extends TestCase
{
    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        $hash = password_hash('testpassword', PASSWORD_BCRYPT);
        self::$userId = AdminUserModel::create('langtest@example.com', $hash, 'editor');
    }

    public static function tearDownAfterClass(): void
    {
        AdminUserModel::delete(self::$userId);
    }

    public function test_getLang_defaults_to_cs(): void
    {
        $lang = AdminUserModel::getLang(self::$userId);
        $this->assertSame('cs', $lang);
    }

    public function test_setLang_persists_to_db(): void
    {
        AdminUserModel::setLang(self::$userId, 'en');
        $this->assertSame('en', AdminUserModel::getLang(self::$userId));
    }

    public function test_getLang_returns_cs_for_unknown_id(): void
    {
        $this->assertSame('cs', AdminUserModel::getLang(999999));
    }
}
