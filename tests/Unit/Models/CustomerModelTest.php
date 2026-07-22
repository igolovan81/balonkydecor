<?php
namespace Tests\Unit\Models;

use App\Models\CustomerModel;
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

    public function test_updateProfile_updates_name_and_phone(): void
    {
        CustomerModel::updateProfile(self::$customerId, 'Test Name', '+420111222333');

        $customer = CustomerModel::findById(self::$customerId);
        $this->assertSame('Test Name', $customer['name']);
        $this->assertSame('+420111222333', $customer['phone']);
    }

    public function test_updateEmail_updates_email(): void
    {
        $newEmail = 'updated-' . uniqid() . '@example.com';
        CustomerModel::updateEmail(self::$customerId, $newEmail);

        $customer = CustomerModel::findById(self::$customerId);
        $this->assertSame($newEmail, $customer['email']);
    }
}
