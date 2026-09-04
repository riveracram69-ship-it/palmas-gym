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

        // Map application payment method to PayMongo payment method types
        $methodType = strtolower($params['payment_method'] ?? 'gcash');
        $allowedTypes = ['gcash'];
        if (str_contains($methodType, 'maya')) {
            $allowedTypes = ['paymaya'];
        } elseif (str_contains($methodType, 'card') || str_contains($methodType, 'credit')) {
            $allowedTypes = ['card'];
        } elseif (str_contains($methodType, 'grab')) {
            $allowedTypes = ['grab_pay'];
        } elseif (str_contains($methodType, 'all')) {
            $allowedTypes = ['gcash', 'paymaya', 'card', 'grab_pay'];
        }

        $payload = [
            'data' => [
                'attributes' => [
                    'billing' => [
                        'name'  => $params['member']['name'] ?? 'Gym Member',
                        'email' => !empty($params['member']['email']) ? $params['member']['email'] : 'member@palmasgym.com',
                        'phone' => !empty($params['member']['phone']) ? $params['member']['phone'] : '09170000000',
                    ],
                    'send_email_receipt'   => true,
                    'show_description'     => true,
                    'show_line_items'      => true,
                    'description'          => $params['description'] ?? "Palma's Elite Gym Membership",
                    'line_items' => [
                        [
                            'currency'    => 'PHP',
                            'amount'      => $amountCentavos,
                            'name'        => $params['plan_name'] ?? 'Gym Membership',
                            'quantity'    => 1,
                            'description' => "Membership Access Pass - Ref: " . ($params['reference_code'] ?? '')
                        ]
                    ],
                    'payment_method_types' => $allowedTypes,
                    'reference_number'     => $params['reference_code'],
                    'success_url'          => $params['success_url'],
                    'cancel_url'           => $params['cancel_url'],
                    'metadata'             => array_merge($params['metadata'] ?? [], [
                        'reference_code' => $params['reference_code'],
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
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("PayMongo cURL Error: " . $curlErr);
            return [
                'success' => false,
                'message' => 'Unable to connect to PayMongo payment gateway.'
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

        $ch = curl_init(self::API_BASE . '/checkout_sessions/' . urlencode($sessionId));
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
}
