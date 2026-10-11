<?php
/**
 * Payment Gateway Webhooks (Razorpay Webhook Listener)
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
$cryptomusSign = $_SERVER['HTTP_SIGN'] ?? '';
$cryptomusKey = get_setting('cryptomus_api_key', '');
$razorpaySecret = get_setting('razorpay_key_secret', defined('RAZORPAY_KEY_SECRET') ? RAZORPAY_KEY_SECRET : '');

$db = Database::getConnection();

// 1. Verify Razorpay webhook signature
if (!empty($signature) && !empty($payload)) {
    $expectedSignature = hash_hmac('sha256', $payload, $razorpaySecret);
    if (!hash_equals($expectedSignature, $signature)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }

    $event = json_decode($payload, true);
    if (($event['event'] ?? '') === 'payment.captured') {
        $payment = $event['payload']['payment']['entity'] ?? null;
        if ($payment) {
            $amount = ((float)$payment['amount']) / 100; // Razorpay paise to rupees
            $txnId = (string)$payment['id'];
            $email = (string)($payment['email'] ?? '');

            $stmt = $db->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if ($user) {
                // Prevent duplicate credits
                $check = $db->prepare("SELECT id FROM transactions WHERE gateway_txn_id = :txnid LIMIT 1");
                $check->execute(['txnid' => $txnId]);
                if (!$check->fetch()) {
                    $db->beginTransaction();
                    wallet_credit($db, (int)$user['id'], $amount, 'Razorpay', $txnId, 'Razorpay webhook auto credit');
                    $db->commit();
                }
            }
        }
    }
}

// 2. Verify Cryptomus payment callback
if (!empty($cryptomusSign) && !empty($payload) && !empty($cryptomusKey)) {
    $data = json_decode($payload, true);
    if (is_array($data) && isset($data['sign'])) {
        $receivedSign = $data['sign'];
        unset($data['sign']);
        $expectedSign = md5(base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE)) . $cryptomusKey);

        if (hash_equals($expectedSign, $receivedSign) && ($data['status'] ?? '') === 'paid') {
            $orderId = $data['order_id'] ?? '';
            $amount = (float)($data['merchant_amount'] ?? $data['amount'] ?? 0);
            
            // Find user from order_id or pending transaction
            $findTxn = $db->prepare("SELECT user_id FROM transactions WHERE gateway_txn_id = :txnid LIMIT 1");
            $findTxn->execute(['txnid' => $orderId]);
            $txnRow = $findTxn->fetch();

            if ($txnRow && $amount > 0) {
                $db->beginTransaction();
                wallet_credit($db, (int)$txnRow['user_id'], $amount, 'Cryptomus USDT', $orderId, 'Cryptomus crypto webhook auto credit');
                $db->commit();
            }
        }
    }
}

// 3. PhonePe Business QR Automated Verification Trigger (Webhook / Remote Poller)
$action = $_GET['action'] ?? '';
if ($action === 'verify_phonepe_qr') {
    $cronKeyConfig = get_setting('cron_key', 'smm_secure_cron_token');
    $key = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
    if (!hash_equals($cronKeyConfig, (string)$key)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid key']);
        exit;
    }

    require_once __DIR__ . '/../includes/gmail_verifier.php';
    $gw = get_payment_method('phonepe_business_qr');
    if (!$gw || $gw['status'] !== 'active') {
        echo json_encode(['status' => 'inactive', 'message' => 'PhonePe Business QR gateway is inactive']);
        exit;
    }

    $verifier = new GmailPaymentVerifier();
    $stmt = $db->query("
        SELECT id FROM transactions 
        WHERE type = 'deposit' AND status = 'pending' AND (gateway = 'PhonePe Business QR' OR gateway = 'phonepe_business_qr')
        ORDER BY id ASC LIMIT 20
    ");
    $pending = $stmt->fetchAll();
    $results = [];
    foreach ($pending as $p) {
        $results[$p['id']] = $verifier->verifyDeposit($db, (int)$p['id']);
    }

    echo json_encode(['status' => 'ok', 'processed' => count($results), 'results' => $results]);
    exit;
}

echo json_encode(['status' => 'ok']);
