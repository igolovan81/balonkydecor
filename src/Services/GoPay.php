<?php
namespace App\Services;

use App\Models\Database;

class GoPay
{
    private string $baseUrl;

    public function __construct(
        private string $goId,
        private string $clientId,
        private string $clientSecret,
        bool $testMode = true
    ) {
        $this->baseUrl = $testMode
            ? 'https://gw.sandbox.gopay.com/api'
            : 'https://gate.gopay.cz/api';
    }

    public static function fromSettings(): ?self
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->query(
            "SELECT `key`, `value` FROM settings
             WHERE `key` IN ('gopay_go_id','gopay_client_id','gopay_client_secret','gopay_test_mode')"
        );
        $cfg = [];
        foreach ($stmt->fetchAll() as $row) {
            $cfg[$row['key']] = $row['value'];
        }
        if (empty($cfg['gopay_go_id'])) {
            return null;
        }
        return new self(
            $cfg['gopay_go_id'],
            $cfg['gopay_client_id'],
            $cfg['gopay_client_secret'],
            (bool) ($cfg['gopay_test_mode'] ?? true)
        );
    }

    private function getToken(): string
    {
        $ch = curl_init("{$this->baseUrl}/oauth2/token");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => "{$this->clientId}:{$this->clientSecret}",
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials&scope=payment-all',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        ]);
        $body      = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            AppLogger::instance()->error('GoPay getToken: cURL request failed', ['error' => $curlError]);
            return '';
        }

        $data = json_decode((string) $body, true);
        if (empty($data['access_token'])) {
            AppLogger::instance()->error('GoPay getToken: no access_token in response', ['response' => $body]);
            return '';
        }
        return $data['access_token'];
    }

    public function createPayment(array $order, string $returnUrl, string $notifyUrl): array
    {
        $token   = $this->getToken();
        $amountH = (int) round((float) $order['total_amount'] * 100);

        $payload = [
            'payer'             => ['allowed_payment_instruments' => ['PAYMENT_CARD', 'BANK_ACCOUNT']],
            'amount'            => $amountH,
            'currency'          => 'CZK',
            'order_number'      => $order['order_number'],
            'order_description' => 'BalonkyDecor ' . $order['order_number'],
            'callback'          => ['return_url' => $returnUrl, 'notification_url' => $notifyUrl],
            'lang'              => 'CS',
        ];

        $ch = curl_init("{$this->baseUrl}/payments/payment");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        $body      = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            AppLogger::instance()->error('GoPay createPayment: cURL request failed', [
                'order_number' => $order['order_number'] ?? '',
                'error'        => $curlError,
            ]);
            return ['payment_id' => '', 'gw_url' => ''];
        }

        $data = json_decode((string) $body, true);
        if (empty($data['gw_url'])) {
            AppLogger::instance()->error('GoPay createPayment: no gw_url in response', [
                'order_number' => $order['order_number'] ?? '',
                'response'     => $body,
            ]);
        }

        return [
            'payment_id' => (string) ($data['id'] ?? ''),
            'gw_url'     => (string) ($data['gw_url'] ?? ''),
        ];
    }

    public function getStatus(string $paymentId): array
    {
        $token = $this->getToken();
        $ch    = curl_init("{$this->baseUrl}/payments/payment/{$paymentId}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                'Accept: application/json',
            ],
        ]);
        $body      = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            AppLogger::instance()->error('GoPay getStatus: cURL request failed', [
                'payment_id' => $paymentId,
                'error'      => $curlError,
            ]);
            return [];
        }

        return json_decode((string) $body, true) ?? [];
    }
}
