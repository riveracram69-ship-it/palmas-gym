<?php
/**
 * config/paymongo.php
 * Official PayMongo Payment Gateway Integration Engine for Palma's Elite Gym
 * Supports: GCash, Maya, Credit/Debit Cards, GrabPay
 */

require_once __DIR__ . '/env.php';

class PayMongoGateway {
    private const API_BASE = 'https://api.paymongo.com/v1';

    /**
     * Get the Secret Key
     */
    private static function getSecretKey(): string {
        return defined('PAYMONGO_SECRET_KEY') ? trim((string)PAYMONGO_SECRET_KEY) : '';
    }

    /**
     * Get the Webhook Secret
     */
    private static function getWebhookSecret(): string {
        return defined('PAYMONGO_WEBHOOK_SECRET') ? trim((string)PAYMONGO_WEBHOOK_SECRET) : '';
    }

    /**
     * Check if live PayMongo gateway is configured
     */
    public static function isConfigured(): bool {
        $secret = self::getSecretKey();
        return !empty($secret) && (str_starts_with($secret, 'sk_live_') || str_starts_with($secret, 'sk_test_'));
    }

    /**
     * Create a PayMongo Checkout Session
     * 
     * @param array $params [
     *    'amount' => float (in PHP pesos),
     *    'currency' => 'PHP',
     *    'description' => string,
     *    'reference_code' => string,
     *    'payment_method' => 'GCash'|'Maya'|'Card'|'GrabPay',
     *    'member' => ['name' => ..., 'email' => ..., 'phone' => ...],
     *    'success_url' => string,
     *    'cancel_url' => string,
     *    'metadata' => array
     * ]
     * @return array ['success' => bool, 'checkout_url' => string, 'session_id' => string, 'message' => string]
     */
    public static function createCheckoutSession(array $params): array {
        $secretKey = self::getSecretKey();
        if (empty($secretKey)) {
            return [
                'success' => false,
                'message' => 'PayMongo Secret Key is not configured on the server.'
            ];
        }

        // Convert PHP amount to Centavos (integer)
        $amountCentavos = (int)round($params['amount'] * 100);
        if ($amountCentavos <= 0) {
            return ['success' => false, 'message' => 'Invalid transaction amount.'];
        }

        // PayMongo requires a minimum of 2000 centavos (₱20.00) for E-Wallets and 10000 centavos (₱100.00) for Cards.
        // In test mode, if testing with a ₱1 promo pass, floor to 2000 centavos so PayMongo API doesn't reject with 400.
        if (self::isTestMode() && $amountCentavos < 2000) {
            $amountCentavos = 2000;
        }

        // Map application payment method to PayMongo payment method types
        $methodType = strtolower($params['payment_method'] ?? 'paymongo');
        $allowedTypes = ['gcash', 'paymaya', 'card', 'grab_pay'];
        if ($methodType === 'gcash') {
            $allowedTypes = ['gcash'];
        } elseif (str_contains($methodType, 'maya')) {
            $allowedTypes = ['paymaya'];
        } elseif (str_contains($methodType, 'card') || str_contains($methodType, 'credit')) {
            $allowedTypes = ['card'];
        } elseif (str_contains($methodType, 'grab')) {
            $allowedTypes = ['grab_pay'];
        }

        $refCode = $params['reference_code'] ?? ($params['reference_number'] ?? ('PAY-' . strtoupper(bin2hex(random_bytes(4)))));
        $isLocalOrTest = is_paymongo_test_mode() || in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1']);

        $payload = [
            'data' => [
                'attributes' => [
                    'billing' => [
                        'name'  => $params['member']['name'] ?? 'Gym Member',
                        'email' => !empty($params['member']['email']) ? $params['member']['email'] : 'member@palmasgym.com',
                        'phone' => !empty($params['member']['phone']) ? $params['member']['phone'] : '09170000000',
                    ],
                    'send_email_receipt'   => false,
                    'show_description'     => true,
                    'show_line_items'      => true,
                    'description'          => $params['description'] ?? "Palma's Elite Gym Membership",
                    'line_items' => [
                        [
                            'currency'    => 'PHP',
                            'amount'      => $amountCentavos,
                            'name'        => $params['plan_name'] ?? 'Gym Membership',
                            'quantity'    => 1,
                            'description' => "Membership Access Pass - Ref: " . $refCode
                        ]
                    ],
                    'payment_method_types' => $allowedTypes,
                    'reference_number'     => $refCode,
                    'success_url'          => $params['success_url'],
                    'cancel_url'           => $params['cancel_url'],
                    'metadata'             => array_merge($params['metadata'] ?? [], [
                        'reference_code' => $refCode,
                        'gym_system'     => 'PalmasEliteGym'
                    ])
                ]
            ]
        ];

        $ch = curl_init(self::API_BASE . '/checkout_sessions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_USERPWD        => $secretKey . ':',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => !$isLocalOrTest,
            CURLOPT_SSL_VERIFYHOST => $isLocalOrTest ? 0 : 2
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("PayMongo cURL Error: " . $curlErr);
            return [
                'success' => false,
                'message' => 'Unable to connect to payment provider. Please check your internet connection.'
            ];
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && isset($decoded['data']['attributes']['checkout_url'])) {
            return [
                'success'      => true,
                'session_id'   => $decoded['data']['id'],
                'checkout_url' => $decoded['data']['attributes']['checkout_url'],
                'data'         => $decoded['data']
            ];
        }

        $errorMessage = $decoded['errors'][0]['detail'] ?? 'Failed to initialize PayMongo checkout session.';
        error_log("PayMongo API Error ({$httpCode}): " . json_encode($decoded));

        return [
            'success' => false,
            'message' => $errorMessage
        ];
    }

    /**
     * Verify Paymongo Webhook Signature
     * 
     * Header format: t=1492774577,te=52571869e734621a0e417324d60c06396e35721d7235b6eb522b6460e078fa5b,li=...
     * 
     * @param string $rawPayload
     * @param string $signatureHeader
     * @return bool
     */
    public static function verifyWebhookSignature(string $rawPayload, string $signatureHeader): bool {
        $webhookSecret = self::getWebhookSecret();
        if (empty($webhookSecret)) {
            error_log("PayMongo Webhook Error: PAYMONGO_WEBHOOK_SECRET is not configured.");
            return false;
        }

        if (empty($signatureHeader)) {
            error_log("PayMongo Webhook Error: Paymongo-Signature header is missing.");
            return false;
        }

        $parts = explode(',', $signatureHeader);
        $parsed = [];
        foreach ($parts as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2) {
                $parsed[$kv[0]] = $kv[1];
            }
        }

        $timestamp = $parsed['t'] ?? '';
        if (empty($timestamp)) {
            error_log("PayMongo Webhook Error: Missing timestamp in signature header.");
            return false;
        }

        // Guard against replay attacks (10 minutes tolerance)
        if (abs(time() - (int)$timestamp) > 600) {
            error_log("PayMongo Webhook Error: Webhook timestamp expired/out of tolerance.");
            return false;
        }

        $toSign = $timestamp . '.' . $rawPayload;
        $computedSignature = hash_hmac('sha256', $toSign, $webhookSecret);

        // Test signature (te) or Live signature (li)
        $expectedTest = $parsed['te'] ?? '';
        $expectedLive = $parsed['li'] ?? '';

        $match = false;
        if (!empty($expectedLive) && hash_equals($computedSignature, $expectedLive)) {
            $match = true;
        } elseif (!empty($expectedTest) && hash_equals($computedSignature, $expectedTest)) {
            $match = true;
        }

        if (!$match) {
            error_log("PayMongo Webhook Error: Invalid signature hash comparison.");
        }

        return $match;
    }

    /**
     * Retrieve Checkout Session from PayMongo API (for direct verification / polling)
     */
    public static function getCheckoutSession(string $sessionId): ?array {
        $secretKey = self::getSecretKey();
        if (empty($secretKey) || empty($sessionId)) {
            return null;
        }

        $isLocalOrTest = is_paymongo_test_mode() || in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1']);

        $ch = curl_init(self::API_BASE . '/checkout_sessions/' . urlencode($sessionId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $secretKey . ':',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => !$isLocalOrTest,
            CURLOPT_SSL_VERIFYHOST => $isLocalOrTest ? 0 : 2
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $decoded = json_decode($response, true);
            return $decoded['data'] ?? null;
        }

        return null;
    }

    /**
     * Check if currently operating in Test/Sandbox mode
     */
    public static function isTestMode(): bool {
        $secret = self::getSecretKey();
        return (defined('PAYMONGO_MODE') && PAYMONGO_MODE === 'test') || 
               (defined('PAYMENT_MODE') && PAYMENT_MODE === 'test') ||
               str_starts_with($secret, 'sk_test_');
    }

    /**
     * Retrieve individual Payment details from PayMongo API
     */
    public static function getPayment(string $paymentId): ?array {
        $secretKey = self::getSecretKey();
        if (empty($secretKey) || empty($paymentId)) {
            return null;
        }

        $ch = curl_init(self::API_BASE . '/payments/' . urlencode($paymentId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $secretKey . ':',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $decoded = json_decode($response, true);
            return $decoded['data'] ?? null;
        }

        return null;
    }

    /**
     * Self-healing schema migration: ensure PayMongo columns and tables exist.
     */
    public static function ensureSchema(?PDO $pdo = null): void {
        static $executed = false;
        if ($executed) return;
        if (!$pdo) {
            global $pdo;
        }
        if (!$pdo) return;

        try {
            // Check payment_transactions columns
            $stmt = $pdo->query("SHOW COLUMNS FROM `payment_transactions`");
            $cols = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

            if (!empty($cols)) {
                if (!in_array('paymongo_checkout_id', $cols)) {
                    $pdo->exec("ALTER TABLE `payment_transactions` ADD COLUMN `paymongo_checkout_id` VARCHAR(100) NULL AFTER `gateway_transaction_id`");
                }
                if (!in_array('paymongo_payment_id', $cols)) {
                    $pdo->exec("ALTER TABLE `payment_transactions` ADD COLUMN `paymongo_payment_id` VARCHAR(100) NULL AFTER `paymongo_checkout_id`");
                }
                if (!in_array('is_test', $cols)) {
                    $pdo->exec("ALTER TABLE `payment_transactions` ADD COLUMN `is_test` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`");
                }
                if (!in_array('failure_reason', $cols)) {
                    $pdo->exec("ALTER TABLE `payment_transactions` ADD COLUMN `failure_reason` VARCHAR(255) NULL AFTER `gateway_response`");
                }
            }

            // Check payments columns
            $p_stmt = $pdo->query("SHOW COLUMNS FROM `payments`");
            $pCols = $p_stmt ? $p_stmt->fetchAll(PDO::FETCH_COLUMN) : [];

            if (!empty($pCols)) {
                if (!in_array('paymongo_checkout_id', $pCols)) {
                    $pdo->exec("ALTER TABLE `payments` ADD COLUMN `paymongo_checkout_id` VARCHAR(100) NULL AFTER `reference_number`");
                }
                if (!in_array('paymongo_payment_id', $pCols)) {
                    $pdo->exec("ALTER TABLE `payments` ADD COLUMN `paymongo_payment_id` VARCHAR(100) NULL AFTER `paymongo_checkout_id`");
                }
                if (!in_array('is_test', $pCols)) {
                    $pdo->exec("ALTER TABLE `payments` ADD COLUMN `is_test` TINYINT(1) NOT NULL DEFAULT 0 AFTER `notes`");
                }
            }

            $executed = true;
        } catch (Throwable $e) {
            error_log("ensureSchema notice: " . $e->getMessage());
        }
    }
}
