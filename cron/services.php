<?php
/**
 * Cron Job: Provider Services & Balance Synchronizer
 * SMM Panel - PHP 8.3+
 * Usage: php cron/services.php
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli' && empty($_GET['cron_key'])) {
    http_response_code(403);
    die("CLI execution only or valid cron_key parameter required.\n");
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting Provider Balance Sync...\n";

$db = Database::getConnection();
$providers = $db->query("SELECT * FROM providers WHERE status = 'active'")->fetchAll();

foreach ($providers as $p) {
    echo "Checking balance for provider #{$p['id']} ({$p['name']})...\n";
    $response = call_provider_api($p['api_url'], [
        'key' => $p['api_key'],
        'action' => 'balance'
    ]);

    if (isset($response['balance'])) {
        $bal = (float)$response['balance'];
        $curr = !empty($response['currency']) ? strtoupper((string)$response['currency']) : 'USD';
        $db->prepare("UPDATE providers SET balance = :b, currency = :c, updated_at = CURRENT_TIMESTAMP WHERE id = :id")->execute(['b' => $bal, 'c' => $curr, 'id' => $p['id']]);
        echo " -> Provider balance updated to {$curr} {$bal}\n";
    } else {
        echo " -> Failed to fetch balance: " . ($response['error'] ?? 'Unknown error') . "\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Provider sync completed.\n";
