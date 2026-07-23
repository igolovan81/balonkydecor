<?php
namespace Tests\Unit\Models;

use App\Models\CustomerModel;
use App\Models\Database;
use PHPUnit\Framework\TestCase;

class CustomerModelTest extends TestCase
{
    private static string $email;
    private static string $hash;
    private static int $customerId;

    public static function setUpBeforeClass(): void
    {
        self::$email      = 'customer-test-' . uniqid() . '@example.com';
        self::$hash       = password_hash('testpassword', PASSWORD_BCRYPT);
        self::$customerId = CustomerModel::create(self::$email, self::$hash);
    }

    public function test_create_returns_positive_id(): void
    {
        $this->assertGreaterThan(0, self::$customerId);
    }

    public function test_findByEmail_returns_created_customer(): void
    {
        $customer = CustomerModel::findByEmail(self::$email);
        $this->assertNotNull($customer);
        $this->assertSame(self::$email, $customer['email']);
        $this->assertSame(self::$hash, $customer['password_hash']);
    }

    public function test_findByEmail_returns_null_for_unknown_email(): void
    {
        $this->assertNull(CustomerModel::findByEmail('nobody-' . uniqid() . '@example.com'));
    }

    public function test_findById_returns_created_customer(): void
    {
        $customer = CustomerModel::findById(self::$customerId);
        $this->assertNotNull($customer);
        $this->assertSame(self::$email, $customer['email']);
    }

    public function test_findById_returns_null_for_unknown_id(): void
    {
        $this->assertNull(CustomerModel::findById(999999999));
    }

    public function test_create_defaults_notification_lang_to_cs(): void
    {
        $email = 'lang-default-' . uniqid() . '@example.com';
        $id    = CustomerModel::create($email, self::$hash);

        $customer = CustomerModel::findById($id);
        $this->assertSame('cs', $customer['notification_lang']);
    }

    public function test_create_accepts_explicit_notification_lang(): void
    {
        $email = 'lang-explicit-' . uniqid() . '@example.com';
        $id    = CustomerModel::create($email, self::$hash, 'ru');

        $customer = CustomerModel::findById($id);
        $this->assertSame('ru', $customer['notification_lang']);
    }

    public function test_setResetToken_then_findByValidResetToken_finds_it(): void
    {
        $token = 'token-' . uniqid();
        CustomerModel::setResetToken(self::$customerId, $token, date('Y-m-d H:i:s', time() + 3600));

        $found = CustomerModel::findByValidResetToken($token);
        $this->assertNotNull($found);
        $this->assertSame(self::$customerId, (int) $found['id']);
    }

    public function test_findByValidResetToken_returns_null_when_expired(): void
    {
        $token = 'expired-token-' . uniqid();
        CustomerModel::setResetToken(self::$customerId, $token, date('Y-m-d H:i:s', time() - 3600));

        $this->assertNull(CustomerModel::findByValidResetToken($token));
    }

    public function test_updatePasswordAndClearToken_updates_hash_and_clears_token(): void
    {
        $token = 'clear-token-' . uniqid();
        CustomerModel::setResetToken(self::$customerId, $token, date('Y-m-d H:i:s', time() + 3600));

        $newHash = password_hash('newpassword', PASSWORD_BCRYPT);
        CustomerModel::updatePasswordAndClearToken(self::$customerId, $newHash);

        $customer = CustomerModel::findById(self::$customerId);
        $this->assertSame($newHash, $customer['password_hash']);
        $this->assertNull(CustomerModel::findByValidResetToken($token));
    }

    public function test_updateProfile_updates_name_phone_and_notification_lang(): void
    {
        CustomerModel::updateProfile(self::$customerId, 'Test Name', '+420111222333', 'sk');

        $customer = CustomerModel::findById(self::$customerId);
        $this->assertSame('Test Name', $customer['name']);
        $this->assertSame('+420111222333', $customer['phone']);
        $this->assertSame('sk', $customer['notification_lang']);
    }

    public function test_updateEmail_updates_email(): void
    {
        $newEmail = 'updated-' . uniqid() . '@example.com';
        CustomerModel::updateEmail(self::$customerId, $newEmail);

        $customer = CustomerModel::findById(self::$customerId);
        $this->assertSame($newEmail, $customer['email']);
    }

    public function test_delete_soft_deletes_customer(): void
    {
        $email = 'delete-test-' . uniqid() . '@example.com';
        $id    = CustomerModel::create($email, self::$hash);

        CustomerModel::delete($id);

        $customer = CustomerModel::findById($id);
        $this->assertNotNull($customer);
        $this->assertNotNull($customer['deleted_at']);
    }

    public function test_restore_clears_deleted_at(): void
    {
        $email = 'restore-test-' . uniqid() . '@example.com';
        $id    = CustomerModel::create($email, self::$hash);
        CustomerModel::delete($id);

        CustomerModel::restore($id);

        $customer = CustomerModel::findById($id);
        $this->assertNull($customer['deleted_at']);
    }

    public function test_dashboardStats_reflects_new_customer(): void
    {
        $before = CustomerModel::dashboardStats();

        CustomerModel::create('dash-stats-' . uniqid() . '@example.com', self::$hash);

        $after = CustomerModel::dashboardStats();

        $this->assertSame($before['total'] + 1, $after['total']);
        $this->assertSame($before['new_this_week'] + 1, $after['new_this_week']);
        $this->assertSame($before['new_this_month'] + 1, $after['new_this_month']);
    }

    public function test_dashboardStats_excludes_soft_deleted_customers(): void
    {
        $id = CustomerModel::create('dash-stats-deleted-' . uniqid() . '@example.com', self::$hash);

        $before = CustomerModel::dashboardStats();
        CustomerModel::delete($id);
        $after = CustomerModel::dashboardStats();

        $this->assertSame($before['total'] - 1, $after['total']);
    }

    public function test_signupsByDay_includes_todays_signup_and_zero_fills_range(): void
    {
        $today       = date('Y-m-d');
        $before      = CustomerModel::signupsByDay(7);
        $beforeToday = end($before)['count'];

        CustomerModel::create('signup-day-' . uniqid() . '@example.com', self::$hash);

        $after = CustomerModel::signupsByDay(7);

        $this->assertCount(7, $after);
        $this->assertSame($today, end($after)['date']);
        $this->assertSame($beforeToday + 1, end($after)['count']);
    }

    public function test_signupsByDay_excludes_soft_deleted_customers(): void
    {
        $before      = CustomerModel::signupsByDay(7);
        $beforeToday = end($before)['count'];

        $id = CustomerModel::create('signup-deleted-' . uniqid() . '@example.com', self::$hash);
        CustomerModel::delete($id);

        $after = CustomerModel::signupsByDay(7);
        $this->assertSame($beforeToday, end($after)['count']);
    }

    public function test_recent_orders_by_created_at_descending(): void
    {
        $pdo = Database::getConnection();

        $oldEmail = 'recent-old-' . uniqid() . '@example.com';
        $oldId    = CustomerModel::create($oldEmail, self::$hash);
        $pdo->prepare('UPDATE customers SET created_at = NOW() - INTERVAL 1 DAY WHERE id = ?')->execute([$oldId]);

        $newEmail = 'recent-new-' . uniqid() . '@example.com';
        CustomerModel::create($newEmail, self::$hash);

        $rows   = CustomerModel::recent(1000000);
        $emails = array_column($rows, 'email');
        $oldPos = array_search($oldEmail, $emails);
        $newPos = array_search($newEmail, $emails);

        $this->assertNotFalse($oldPos);
        $this->assertNotFalse($newPos);
        $this->assertLessThan($oldPos, $newPos);
    }

    public function test_recent_excludes_soft_deleted_customers(): void
    {
        $email = 'recent-deleted-' . uniqid() . '@example.com';
        $id    = CustomerModel::create($email, self::$hash);
        CustomerModel::delete($id);

        $emails = array_column(CustomerModel::recent(1000000), 'email');
        $this->assertNotContains($email, $emails);
    }
}
