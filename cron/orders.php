<?php
/**
 * Cron Job: Order Status Synchronizer
 * SMM Panel - PHP 8.3+
 * Usage: php cron/orders.php
 */

declare(strict_types=1);

// CLI or cron invocation check
if (php_sapi_name() !== 'cli' && empty($_GET['cron_key'])) {
    http_response_code(403);
    die("CLI execution only or valid cron_key parameter required.\n");
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting Order Status Sync...\n";

$db = Database::getConnection();

// Select pending or in_progress orders with external provider_order_id
$stmt = $db->query("
    SELECT o.id, o.provider_order_id, o.provider_id, o.user_id, o.charge, p.api_url, p.api_key
    FROM orders o
    JOIN providers p ON o.provider_id = p.id
    WHERE o.status IN ('pending', 'processing', 'in_progress')
      AND o.provider_order_id IS NOT NULL
    LIMIT 100
");
$orders = $stmt->fetchAll();

foreach ($orders as $order) {
    echo "Syncing order #{$order['id']} (Provider ID: {$order['provider_order_id']})...\n";
    $response = call_provider_api($order['api_url'], [
        'key' => $order['api_key'],
        'action' => 'status',
        'order' => $order['provider_order_id']
    ]);

    if (!empty($response['status'])) {
        $pStatus = strtolower((string)$response['status']);
        $startCount = (int)($response['start_count'] ?? 0);
        $remains = (int)($response['remains'] ?? 0);

        $statusMap = [
            'completed' => 'completed',
            'in progress' => 'in_progress',
            'processing' => 'processing',
            'pending' => 'pending',
            'partial' => 'partial',
            'canceled' => 'cancelled',
            'cancelled' => 'cancelled'
        ];

        $targetStatus = $statusMap[$pStatus] ?? 'processing';

        if ($targetStatus === 'cancelled') {
            // Auto refund
            $db->beginTransaction();
            $db->prepare("UPDATE orders SET status = 'cancelled', remains = :rem WHERE id = :id")->execute(['rem' => $remains, 'id' => $order['id']]);
            $db->prepare("UPDATE users SET balance = balance + :amt WHERE id = :uid")->execute(['amt' => $order['charge'], 'uid' => $order['user_id']]);
            $db->prepare("INSERT INTO transactions (user_id, order_id, type, amount, gateway, status, note) VALUES (:uid, :oid, 'refund', :amt, 'system', 'completed', 'Provider cancelled order refund')")->execute([
                'uid' => $order['user_id'],
                'oid' => $order['id'],
                'amt' => $order['charge']
            ]);
            $db->commit();
            echo " -> Order cancelled and refunded.\n";
        } else {
            $db->prepare("UPDATE orders SET status = :st, start_count = :sc, remains = :rem WHERE id = :id")->execute([
                'st' => $targetStatus,
                'sc' => $startCount,
                'rem' => $remains,
                'id' => $order['id']
            ]);
            echo " -> Updated status to {$targetStatus}.\n";
        }
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Order sync completed.\n";

// Execute scheduled drip-feed runs
if (file_exists(__DIR__ . '/dripfeed.php')) {
    include __DIR__ . '/dripfeed.php';
}

