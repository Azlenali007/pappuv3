<?php
/**
 * Cron Job: PhonePe Business QR & Gmail Payment Reconciler
 * SMM Panel - Production Automated Verification
 *
 * Usage via CLI:
 *   php cron/verify_payments.php
 *
 * Usage via Web / cURL:
 *   curl https://your-domain.com/cron/verify_payments.php?cron_key=YOUR_CRON_KEY
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/gmail_verifier.php';

// CLI or valid security token check
$cronKeyConfig = get_setting('cron_key', 'smm_secure_cron_token');
$providedKey = $_GET['cron_key'] ?? '';

if (php_sapi_name() !== 'cli' && (empty($providedKey) || !hash_equals($cronKeyConfig, $providedKey))) {
    http_response_code(403);
    die(json_encode(['error' => 'CLI execution only or valid cron_key required.']));
}

$db = Database::getConnection();

// Check if PhonePe Business QR gateway is active & configured
$gw = get_payment_method('phonepe_business_qr');
if (!$gw || $gw['status'] !== 'active') {
    echo "[" . date('Y-m-d H:i:s') . "] PhonePe Business QR gateway is disabled. Skipping check.\n";
    exit(0);
}

$gwConfig = get_gateway_config($gw);
if (empty($gwConfig['gmail_email']) && empty($gwConfig['gmail_client_id'])) {
    echo "[" . date('Y-m-d H:i:s') . "] Gmail verification credentials not configured. Skipping.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Starting PhonePe Business QR Gmail Verification Scan...\n";

// Query pending deposits submitted in last 48 hours
$stmt = $db->query("
    SELECT t.*, u.username, u.email
    FROM transactions t
    JOIN users u ON t.user_id = u.id
    WHERE t.type = 'deposit'
      AND t.status = 'pending'
      AND (t.gateway = 'PhonePe Business QR' OR t.gateway = 'phonepe_business_qr')
      AND t.created_at >= NOW() - INTERVAL 48 HOUR
    ORDER BY t.id ASC
    LIMIT 25
");
$pendingList = $stmt->fetchAll();

if (empty($pendingList)) {
    echo "[" . date('Y-m-d H:i:s') . "] No pending PhonePe Business QR deposits in queue.\n";
    exit(0);
}

echo "Found " . count($pendingList) . " pending deposit(s) awaiting verification.\n";

$verifier = new GmailPaymentVerifier($gwConfig);
$processed = 0;
$credited = 0;

foreach ($pendingList as $txn) {
    $depositId = (int)$txn['id'];
    $utr = (string)$txn['gateway_txn_id'];
    $amount = (float)$txn['amount'];

    echo "Checking Deposit #{$depositId} (User: {$txn['username']}, UTR: '{$utr}', Amount: ₹{$amount})... ";

    try {
        $result = $verifier->verifyDeposit($db, $depositId, $utr, $amount);
        $processed++;

        if (!empty($result['success']) && !empty($result['credited'])) {
            $credited++;
            echo "VERIFIED & CREDITED! ₹{$result['amount']}\n";
        } elseif (($result['status'] ?? '') === 'duplicate_rejected') {
            echo "REJECTED (Duplicate UTR detected)\n";
        } elseif (($result['status'] ?? '') === 'amount_mismatch') {
            echo "FLAGGED (Amount mismatch)\n";
        } elseif (($result['status'] ?? '') === 'connection_error') {
            echo "CONNECTION ERROR: {$result['error']}\n";
            break; // Stop loop if inbox connection fails
        } else {
            echo "PENDING (No matching email found yet)\n";
        }
    } catch (\Throwable $e) {
        echo "EXCEPTION: " . $e->getMessage() . "\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Scan complete. Processed: {$processed}, Successfully Credited: {$credited}.\n";
